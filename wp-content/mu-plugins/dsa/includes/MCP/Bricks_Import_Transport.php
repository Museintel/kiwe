<?php
namespace DSA\MCP;

use DSA\Bricks\Compiler_Package_Reader;
use DSA\Utilities\Atomic_Rate_Limiter;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Binary upload and private native preview for the Bricks MCP operations. */
final class Bricks_Import_Transport {
	public const PREVIEW_QUERY = 'kiwe_bricks_draft_preview';
	private const PACKAGE = 'bricks/package';

	public function __construct(
		private Authorization_Service $auth,
		private Operation_Registry $registry,
		private HTTP_Controller $http
	) {}

	public static function preview_url(): string {
		return add_query_arg( self::PREVIEW_QUERY, '1', home_url( '/' ) );
	}

	public static function package_request(): bool {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return false;
		$rest_route = $_GET['rest_route'] ?? '';
		$url = Urls::endpoint( self::PACKAGE );
		return $rest_route === '/' . Urls::NS . '/' . self::PACKAGE || ( ! wp_parse_url( $url, PHP_URL_QUERY ) && self::request_path() === wp_parse_url( $url, PHP_URL_PATH ) );
	}

	private static function request_path(): string {
		return (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	}

	public function register(): void {
		add_action( 'rest_api_init', function (): void {
			register_rest_route( Urls::NS, '/' . self::PACKAGE, [
				'methods'=>'POST',
				'permission_callback'=>'__return_true',
				'callback'=>[ $this, 'upload' ],
			] );
		} );
		if ( isset( $_GET[self::PREVIEW_QUERY] ) ) add_action( 'parse_request', [ $this, 'preview' ], 1 );
	}

	private function authorize( string $operation, string $header, string $origin, int $limit ): array {
		if ( ! Urls::request_host_matches() ) throw new OAuth_Failure( 'invalid_target', 403 );
		if ( ! Atomic_Rate_Limiter::allow( 'kiwe-mcp-bricks|' . $operation . '|' . Origin_Checker::client_ip(), $limit, 60, false ) ) throw new OAuth_Failure( 'temporarily_unavailable', 429 );
		if ( strlen( $header ) > 200 || ! preg_match( '/^Bearer (kiwe_mcp_access_[A-Za-z0-9_.-]+)$/D', $header, $match ) ) throw new OAuth_Failure( 'invalid_token', 401 );
		$grant = Auth_Context::establish( $this->auth, $match[1] );
		if ( ! $this->http->origin_for_client( $grant['client'], $origin ) || ! in_array( 'mcp.read', $grant['scopes'], true ) || ! isset( $this->registry->retain( $grant['pins'], $grant['scopes'] )[$operation] ) ) {
			throw new OAuth_Failure( 'insufficient_scope', 403 );
		}
		Records::seen( $grant['id'] );
		return $grant;
	}

	public function upload( \WP_REST_Request $request ): \WP_REST_Response {
		$prior_user = get_current_user_id();
		Auth_Context::clear();
		try {
			$this->authorize( Bricks_Import_Provider::STAGE, (string) $request->get_header( 'Authorization' ), (string) $request->get_header( 'Origin' ), 10 );
			$intent = (string) $request->get_header( 'X-Kiwe-Intent' );
			$service = new Intent_Service( $this->registry );
			if ( $service->status( $intent )['operation'] !== Bricks_Import_Provider::STAGE ) throw new OAuth_Failure( 'access_denied', 403 );
			$files = $request->get_file_params();
			$file = $files['package'] ?? null;
			if ( count( $files ) !== 1 || ! is_array( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) || ( $file['size'] ?? 0 ) > min( Compiler_Package_Reader::MAX_BYTES, (int) wp_max_upload_size() ) ) {
				return HTTP_Controller::response( [ 'error'=>'invalid_upload', 'message'=>'Choose a Seam ZIP within the site upload limit.' ], 400 );
			}
			return HTTP_Controller::response( Bricks_Import_Provider::with_attachment( $file['tmp_name'], fn() => $service->apply( $intent ) ) );
		} catch ( OAuth_Failure $error ) {
			$response = HTTP_Controller::response( [ 'error'=>$error->reason ], $error->status );
			if ( $error->status === 401 ) $response->header( 'WWW-Authenticate', Urls::challenge( 'invalid_token' ) );
			return $response;
		} catch ( \RuntimeException ) {
			return HTTP_Controller::response( [ 'error'=>'package_review_required', 'message'=>'Use the prepared upload from this connection and its exact ZIP.' ], 409 );
		} catch ( \Throwable ) {
			return HTTP_Controller::response( [ 'error'=>'temporarily_unavailable' ], 503 );
		} finally {
			Auth_Context::clear();
			wp_set_current_user( $prior_user );
		}
	}

	/** Use Bricks' ordinary rendering stack on one permission-checked draft. */
	public function preview( \WP $request ): void {
		$prior_user = get_current_user_id();
		Auth_Context::clear();
		nocache_headers();
		header( 'Cache-Control: private, no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		try {
			if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || $_GET !== [ self::PREVIEW_QUERY=>'1' ] || self::request_path() !== wp_parse_url( self::preview_url(), PHP_URL_PATH ) ) throw new \RuntimeException();
			$grant = $this->authorize( Bricks_Import_Provider::PREVIEW, (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ), (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' ), 90 );
			$args = [
				'job'=>(string) ( $_SERVER['HTTP_X_KIWE_IMPORT'] ?? '' ),
				'archiveSha256'=>(string) ( $_SERVER['HTTP_X_KIWE_PACKAGE_SHA256'] ?? '' ),
				'beginIntent'=>(string) ( $_SERVER['HTTP_X_KIWE_BEGIN_INTENT'] ?? '' ),
				'file'=>(string) ( $_SERVER['HTTP_X_KIWE_DRAFT_FILE'] ?? '' ),
			];
			$result = $this->registry->read( Bricks_Import_Provider::PREVIEW, $args, $grant['scopes'], $grant['pins'] );
			$draft = json_decode( $result['resultJson'], true, 4, JSON_THROW_ON_ERROR );
			$id = (int) ( $draft['id'] ?? 0 );
			if ( $id < 1 ) throw new \RuntimeException();
		} catch ( \Throwable ) {
			Auth_Context::clear();
			wp_set_current_user( $prior_user );
			status_header( 404 );
			exit;
		}
		// The request has no cookie authority or write path. The native renderer
		// needs its current actor to load the one unpublished template.
		Auth_Context::clear();
		$request->query_vars = [ 'p'=>$id, 'post_type'=>BRICKS_DB_TEMPLATE_SLUG, 'post_status'=>'draft', 'preview'=>'true' ];
		add_filter( 'redirect_canonical', '__return_false' );
		add_filter( 'show_admin_bar', '__return_false' );
		add_filter( 'bricks/active_templates', static function ( array $templates ) use ( $id ): array {
			foreach ( [ 'header', 'footer', 'content' ] as $area ) {
				if ( (int) ( $templates[$area] ?? 0 ) !== $id ) $templates[$area] = 0;
			}
			$templates['popup'] = array_values( array_intersect( [ $id ], (array) ( $templates['popup'] ?? [] ) ) );
			return $templates;
		}, PHP_INT_MAX );
		add_action( 'template_redirect', static function () use ( $id ): void {
			nocache_headers();
			header( 'Cache-Control: private, no-store' );
			if ( ! is_singular( BRICKS_DB_TEMPLATE_SLUG ) || get_queried_object_id() !== $id ) {
				status_header( 404 );
				exit;
			}
			header( 'X-Kiwe-Bricks-Preview: ' . $id );
		}, 0 );
	}
}
