<?php
namespace DSA\Access;

use DSA\Backup\Content_Archive_Service as Archives;
use DSA\Backup\Content_Archive_Job_Service as Jobs;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One route and download authority across native content collections. */
final class Content_Archive_App_Service {
	public const DOWNLOAD = 'kiwe_content_download';
	public static function endpoint(): string { return rest_url( 'dsa/v1/workspace/content-archives' ); }
	public static function config(): array {
		$imports = []; foreach ( \DSA\Backup\Unit_Catalog_Service::content_definitions() as $unit ) $imports[$unit['singleScope']] = Archives::can_import( $unit['singleScope'] );
		foreach ( [ 'category','product_category' ] as $scope ) $imports[$scope] = Archives::can_import( $scope );
		foreach ( array_keys( Archives::service_definition()['taxonomies'] ?? [] ) as $taxonomy ) { $scope = Archives::category_scope( $taxonomy, true ); $imports[$scope] = Archives::can_import( $scope ); }
		$collections = []; foreach ( \DSA\Backup\Unit_Catalog_Service::content_definitions() as $unit ) $collections[$unit['scope']] = Archives::can_export_collection( $unit['scope'] );
		return [ ...Media_Archive_App_Service::config(), 'archiveEndpoint'=>self::endpoint(), 'canImportArchives'=>$imports,'canExportArchiveCollections'=>$collections,'archiveTimezone'=>wp_timezone_string(),'resumableArchives'=>class_exists( '\ZipArchive' ),'archiveUploadBytes'=>min( Jobs::MAX_BYTES, wp_max_upload_size() ) ];
	}
	public function register(): void {
		if ( ! defined( 'KIWE_REACT_WORKSPACE' ) || true !== KIWE_REACT_WORKSPACE ) return;
		add_action( 'rest_api_init', [ $this,'routes' ] ); add_action( 'admin_post_' . self::DOWNLOAD, [ $this,'download' ] );
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/content-archives', [ 'methods'=>'GET','callback'=>[ $this,'capabilities' ],'permission_callback'=>[ $this,'permission' ] ] );
		foreach ( [ 'export','preview','import' ] as $action ) register_rest_route( 'dsa/v1', '/workspace/content-archives/' . $action, [ 'methods'=>'POST','callback'=>[ $this, 'import' === $action ? 'apply' : $action ],'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/content-archives/jobs', [ 'methods'=>'GET','callback'=>[ $this,'jobs' ],'permission_callback'=>[ $this,'permission' ] ] );
		foreach ( [ 'step','remove' ] as $action ) register_rest_route( 'dsa/v1', '/workspace/content-archives/jobs/(?P<id>[a-f0-9]{32})/' . $action, [ 'methods'=>'POST','callback'=>[ $this, 'step' === $action ? 'job_step' : 'job_remove' ],'permission_callback'=>[ $this,'permission' ] ] );
	}
	public function permission( \WP_REST_Request $request ): bool { return is_user_logged_in() && current_user_can( 'read' ) && in_array( $request->get_method(), [ 'GET','POST' ], true ) && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && Origin_Checker::is_same_site_request() && ( 'GET' === $request->get_method() || true === Origin_Checker::mutation_allowed( $request ) ); }
	private function fail( \Throwable $error, int $status = 400 ): \WP_REST_Response { return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_content_archive', $error->getMessage() ), $status ); }
	public function capabilities( \WP_REST_Request $request ): \WP_REST_Response {
		$config = self::config();
		return Task_Workspace_UI::response( [ 'schema'=>'kiwe.content-archive-workspace.v1',...$config,'canImport'=>$config['canImportArchives'],'drive'=>[ 'connected'=>false,'reason'=>'Connect this website to Google Drive when the Drive backup unit is available.' ],'limits'=>[ 'maxBytes'=>Archives::MAX_BYTES,'maxRecords'=>Archives::MAX_RECORDS,'sameSite'=>true ] ] );
	}
	public function export( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! is_string( $body['scope'] ?? null ) || ! is_int( $body['id'] ?? null ) || ! is_string( $body['passphrase'] ?? null ) ) return $this->fail( new \RuntimeException( 'Choose the content and a recovery passphrase.' ) );
		if ( array_key_exists( 'selection', $body ) && ! is_array( $body['selection'] ) ) return $this->fail( new \RuntimeException( 'Choose a valid backup date range.' ) );
		if ( array_key_exists( 'resumable', $body ) && ! is_bool( $body['resumable'] ) ) return $this->fail( new \RuntimeException( 'Choose a valid backup mode.' ) );
		if ( true === ( $body['resumable'] ?? false ) && class_exists( '\ZipArchive' ) ) {
			if ( ! is_string( $body['requestKey'] ?? null ) ) return $this->fail( new \RuntimeException( 'A backup request reference is required.' ) );
			try { return Task_Workspace_UI::response( [ 'job'=>self::job( ( new Jobs() )->start_export( $body['scope'], $body['id'], $body['passphrase'], $body['selection'] ?? [], $body['requestKey'] ) ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error ); }
		}
		try { $result = ( new Archives() )->export( $body['scope'], $body['id'], $body['passphrase'], $body['selection'] ?? [] ); $result['downloadUrl'] = add_query_arg( [ 'action'=>self::DOWNLOAD,'id'=>$result['id'],'_wpnonce'=>wp_create_nonce( self::DOWNLOAD . '_' . $result['id'] ) ], admin_url( 'admin-post.php' ) ); return Task_Workspace_UI::response( [ 'archive'=>$result ] ); }
		catch ( \Throwable $error ) { return $this->fail( $error ); }
	}
	public function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$file = $request->get_file_params()['file'] ?? null; $phrase = $request->get_param( 'passphrase' ); $scope = $request->get_param( 'scope' );
		$media = $request->get_param( 'mediaRecovery' ) ?? ''; if ( ! is_string( $media ) || ( $media && ! preg_match( '/^[a-f0-9]{32}$/D', $media ) ) ) return $this->fail( new \RuntimeException( 'Choose a completed media recovery.' ) );
		if ( ! is_array( $file ) || ! is_string( $phrase ) || ! is_string( $scope ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) ) || (int) ( $file['size'] ?? 0 ) > min( Jobs::MAX_BYTES, wp_max_upload_size() ) ) return $this->fail( new \RuntimeException( 'Choose a Kiwe content file within this website’s upload limit.' ) );
		$stream = fopen( $file['tmp_name'], 'rb' ); $signature = $stream ? fread( $stream, 4 ) : ''; if ( $stream ) fclose( $stream );
		if ( "PK\x03\x04" === $signature || str_ends_with( (string) ( $file['name'] ?? '' ), '.kiwe-set' ) ) {
			$key = $request->get_param( 'requestKey' ); if ( ! is_string( $key ) ) return $this->fail( new \RuntimeException( 'An import request reference is required.' ) );
			try { return Task_Workspace_UI::response( [ 'job'=>self::job( ( new Jobs() )->start_import( $file['tmp_name'], $phrase, $scope, $key, $media ) ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error ); }
		}
		if ( (int) $file['size'] > Archives::MAX_BYTES ) return $this->fail( new \RuntimeException( 'Single archives may contain up to 10 MB. Larger collections use a Kiwe backup set.' ) );
		$bytes = file_get_contents( $file['tmp_name'] ); if ( ! is_string( $bytes ) ) return $this->fail( new \RuntimeException( 'The uploaded archive could not be read.' ) );
		try { return Task_Workspace_UI::response( [ 'review'=>( new Archives() )->preview( $bytes, $phrase, $scope, [], $media ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error ); }
	}
	public function apply( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! is_string( $body['token'] ?? null ) || ! is_string( $body['passphrase'] ?? null ) || true !== ( $body['confirm'] ?? null ) ) return $this->fail( new \RuntimeException( 'Review and confirm this draft import.' ) );
		try { return Task_Workspace_UI::response( [ 'imported'=>( new Archives() )->apply( $body['token'], $body['passphrase'] ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error, 409 ); }
	}
	private static function job( array $job ): array {
		if ( 'export' === $job['mode'] && 'ready' === $job['status'] ) $job['downloadUrl'] = add_query_arg( [ 'action'=>self::DOWNLOAD,'id'=>$job['id'],'kind'=>'set','_wpnonce'=>wp_create_nonce( self::DOWNLOAD . '_set_' . $job['id'] ) ], admin_url( 'admin-post.php' ) );
		return $job;
	}
	public function jobs( \WP_REST_Request $request ): \WP_REST_Response { try { return Task_Workspace_UI::response( [ 'jobs'=>array_map( [ self::class,'job' ], ( new Jobs() )->inventory() ),'available'=>class_exists( '\ZipArchive' ),'maxUploadBytes'=>min( Jobs::MAX_BYTES, wp_max_upload_size() ),'maxPrimary'=>Jobs::MAX_PRIMARY ] ); } catch ( \Throwable $error ) { return $this->fail( $error ); } }
	public function job_step( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! is_string( $body['passphrase'] ?? null ) || ! is_int( $body['revision'] ?? null ) || $body['revision'] < 1 || ( array_key_exists( 'confirm', $body ) && ! is_bool( $body['confirm'] ) ) ) return $this->fail( new \RuntimeException( 'Choose a saved job, its progress and recovery passphrase.' ) );
		try { return Task_Workspace_UI::response( [ 'job'=>self::job( ( new Jobs() )->step( (string) $request->get_param( 'id' ), $body['passphrase'], $body['revision'], true === ( $body['confirm'] ?? false ) ) ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error, 409 ); }
	}
	public function job_remove( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params(); if ( ! is_array( $body ) || true !== ( $body['confirm'] ?? null ) ) return $this->fail( new \RuntimeException( 'Confirm removal of this private backup job. Saved drafts are kept.' ) );
		try { return Task_Workspace_UI::response( ( new Jobs() )->remove( (string) $request->get_param( 'id' ) ) ); } catch ( \Throwable $error ) { return $this->fail( $error, 409 ); }
	}
	public function download(): void {
		if ( ! is_user_logged_in() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'This download is unavailable.', '', [ 'response'=>403 ] );
		$id = is_string( $_GET['id'] ?? null ) ? $_GET['id'] : ''; $set = 'set' === ( $_GET['kind'] ?? '' ); check_admin_referer( self::DOWNLOAD . ( $set ? '_set_' : '_' ) . $id );
		try { $path = $set ? ( new Jobs() )->download_path( $id ) : ( new Archives() )->download_path( $id ); } catch ( \Throwable $error ) { wp_die( esc_html( $error->getMessage() ), '', [ 'response'=>403 ] ); }
		nocache_headers(); header( 'Cache-Control: private, no-store' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: application/octet-stream' ); header( 'Content-Disposition: attachment; filename="kiwe-content-' . $id . ( $set ? '.kiwe-set' : '.kiwe' ) . '"' ); header( 'Content-Length: ' . filesize( $path ) );
		$stream = fopen( $path, 'rb' ); if ( ! $stream ) exit; while ( ! feof( $stream ) ) { echo fread( $stream, 65536 ); flush(); } fclose( $stream ); exit;
	}
}
