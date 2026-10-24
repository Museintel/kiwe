<?php
namespace DSA\Access;

use DSA\Backup\Archive_Error;
use DSA\Backup\Portable_Capture_Job_Service as Jobs;
use DSA\Backup\Portable_Capture_Worker_Service as Worker;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Default-off capture review entrance. No HTTP capture work or restore authority. */
final class Portable_Capture_App_Service {
	public const DOWNLOAD = 'kiwe_capture_download';
	public static function enabled(): bool {
		return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && defined( 'KIWE_PORTABLE_CAPTURE_PREVIEW' ) && true === KIWE_PORTABLE_CAPTURE_PREVIEW;
	}
	public static function config(): array {
		$available = self::enabled() && Backup_Workspace_Service::allowed();
		return [ 'portableCaptureAvailable'=>$available,'portableCaptureEndpoint'=>$available ? rest_url( 'dsa/v1/workspace/site-captures' ) : '' ];
	}
	public function register(): void {
		if ( ! self::enabled() ) return;
		( new Worker() )->register();
		add_action( 'rest_api_init',[ $this,'routes' ] ); add_action( 'admin_post_'.self::DOWNLOAD,[ $this,'download' ] );
	}
	public function routes(): void {
		if ( ! self::enabled() ) return;
		register_rest_route( 'dsa/v1','/workspace/site-captures',[ [ 'methods'=>'GET','callback'=>[ $this,'listing' ],'permission_callback'=>[ $this,'permission' ] ],[ 'methods'=>'POST','callback'=>[ $this,'create' ],'permission_callback'=>[ $this,'permission' ] ] ] );
		register_rest_route( 'dsa/v1','/workspace/site-captures/(?P<id>[a-f0-9]{32})',[ 'methods'=>'GET','callback'=>[ $this,'status' ],'permission_callback'=>[ $this,'permission' ] ] );
		foreach ( [ 'queue','remove' ] as $action ) register_rest_route( 'dsa/v1','/workspace/site-captures/(?P<id>[a-f0-9]{32})/'.$action,[ 'methods'=>'POST','callback'=>[ $this,$action ],'permission_callback'=>[ $this,'permission' ] ] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		$method = $request->get_method(); $cleanup = 'GET' === $method || (bool)preg_match( '#^/dsa/v1/workspace/site-captures/[a-f0-9]{32}/remove/?$#D',$request->get_route() );
		return self::enabled() && is_user_logged_in() && current_user_can( 'read' ) && in_array( $method,[ 'GET','POST' ],true ) && ( $cleanup || Backup_Workspace_Service::allowed() ) && wp_verify_nonce( (string)$request->get_header( 'X-WP-Nonce' ),'wp_rest' ) && Origin_Checker::is_same_site_request() && ( 'GET' === $method || true === Origin_Checker::mutation_allowed( $request ) );
	}
	private function failure( int $status = 409 ): \WP_REST_Response {
		// Never return database, filesystem, passphrase or provider diagnostics.
		return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_capture_unavailable','Kiwe could not confirm this capture action. Refresh the saved attempts before trying again.' ),$status );
	}
	private static function job( array $job ): array {
		if ( true === ( $job['canReview'] ?? false ) ) foreach ( [ 'recovery','observation' ] as $member ) {
			$job['downloads'][$member] = add_query_arg( [ 'action'=>self::DOWNLOAD,'id'=>$job['id'],'component'=>$member,'_wpnonce'=>wp_create_nonce( self::DOWNLOAD.'_'.$job['id'].'_'.$member ) ],admin_url( 'admin-post.php' ) );
		}
		return $job;
	}
	public function listing( \WP_REST_Request $request ): \WP_REST_Response {
		try { return Task_Workspace_UI::response( [ 'schema'=>'kiwe.site-capture-workspace.v1','jobs'=>array_map( [ self::class,'job' ],( new Jobs() )->inventory() ),'canCreate'=>Backup_Workspace_Service::allowed(),'workerReady'=>Backup_Workspace_Service::allowed() && Worker::readiness()['ready'],'fullSiteReady'=>false,'scope'=>'capture-review' ] ); }
		catch ( \Throwable $error ) { return $this->failure( 503 ); }
	}
	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params(); if ( ! is_array( $body ) || $body !== [] ) return $this->failure( 400 );
		try { return Task_Workspace_UI::response( [ 'job'=>( new Jobs() )->create() ] ); } catch ( \Throwable $error ) { return $this->failure(); }
	}
	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		try { return Task_Workspace_UI::response( [ 'job'=>( new Jobs() )->status( (string)$request->get_param( 'id' ) ) ] ); } catch ( \Throwable $error ) { return $this->failure(); }
	}
	public function queue( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params(); if ( ! is_array( $body ) || count( $body ) !== 1 || ! is_string( $body['passphrase'] ?? null ) ) return $this->failure( 400 );
		try { return Task_Workspace_UI::response( [ 'job'=>( new Jobs() )->enqueue( (string)$request->get_param( 'id' ),$body['passphrase'] ) ] ); } catch ( \Throwable $error ) { return $this->failure(); }
	}
	public function remove( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params(); if ( ! is_array( $body ) || count( $body ) !== 1 || true !== ( $body['confirm'] ?? null ) ) return $this->failure( 400 );
		try { return Task_Workspace_UI::response( ( new Jobs() )->remove( (string)$request->get_param( 'id' ) ) ); } catch ( \Throwable $error ) { return $this->failure(); }
	}
	/** Trusted output adapter, sharing exactly the native download authorization. */
	public function send_download( callable $begin, callable $emit, ?callable $cancel = null ): array {
		if ( ! self::enabled() || ! Backup_Workspace_Service::allowed() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || isset( $_SERVER['HTTP_RANGE'] ) || ! Origin_Checker::is_same_site_request() ) throw new Archive_Error( 'This capture download is unavailable.' );
		$id = $_GET['id'] ?? null; $member = $_GET['component'] ?? null; $nonce = $_GET['_wpnonce'] ?? null;
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D',$id ) || ! is_string( $member ) || ! in_array( $member,[ 'recovery','observation' ],true ) || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce,self::DOWNLOAD.'_'.$id.'_'.$member ) ) throw new Archive_Error( 'This capture download is unavailable.' );
		return ( new Jobs() )->stream_review_capture( $id,$member,$begin,$emit,$cancel );
	}
	public function download(): void {
		$started = false; $previous_abort = ignore_user_abort( true );
		try {
			$this->send_download( static function( array $metadata ) use ( &$started ): void {
				if ( headers_sent() ) throw new Archive_Error( 'This capture download is unavailable.' );
				@ini_set( 'zlib.output_compression','0' ); if ( ini_get( 'zlib.output_compression' ) ) throw new Archive_Error( 'This capture download is unavailable.' );
				while ( ob_get_level() ) if ( ! @ob_end_clean() ) throw new Archive_Error( 'This capture download is unavailable.' );
				nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Content-Type-Options: nosniff' ); header( 'X-Robots-Tag: noindex, nofollow' ); header( 'Content-Type: application/octet-stream' ); header( 'Content-Disposition: attachment; filename="'.$metadata['filename'].'"' ); header( 'Content-Length: '.$metadata['bytes'] ); header( 'X-Kiwe-Capture-Scope: capture-review' ); $started = true;
			},static function( string $bytes ): bool { if ( connection_aborted() ) return false; echo $bytes; flush(); return ! connection_aborted(); },static function(): void { if ( connection_aborted() ) throw new Archive_Error( 'The capture download was cancelled.' ); } );
		} catch ( \Throwable $error ) {
			// A failed stream stays short of Content-Length. Never append HTML or an
			// error document to encrypted bytes; the same capture can be retried.
			if ( ! $started ) wp_die( 'This capture download is unavailable. Refresh the saved attempts and try again.','',[ 'response'=>403 ] );
		} finally { ignore_user_abort( (bool)$previous_abort ); }
		exit;
	}
}
