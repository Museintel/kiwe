<?php
namespace DSA\Access;

use DSA\Backup\Bricks_Design_Package_Service as Jobs;
use DSA\Backup\Archive_Error;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Bricks_Design_Package_App_Service {
	public const DOWNLOAD = 'kiwe_design_download';
	public static function config(): array { return [ 'designPackageEndpoint'=>Backup_Workspace_Service::allowed() ? rest_url( 'dsa/v1/workspace/design-packages' ) : '','designPackagesAvailable'=>Jobs::allowed(),'designPackageUploadBytes'=>min( Jobs::MAX_BYTES,wp_max_upload_size() ) ]; }
	public function register(): void { if ( ! defined( 'KIWE_REACT_WORKSPACE' ) || true !== KIWE_REACT_WORKSPACE ) return; add_action( 'rest_api_init',[ $this,'routes' ] ); add_action( 'admin_post_'.self::DOWNLOAD,[ $this,'download' ] ); }
	public function routes(): void {
		register_rest_route( 'dsa/v1','/workspace/design-packages/jobs',[ 'methods'=>'GET','callback'=>[ $this,'jobs' ],'permission_callback'=>[ $this,'permission' ] ] );
		foreach ( [ 'export','preview' ] as $action ) register_rest_route( 'dsa/v1','/workspace/design-packages/'.$action,[ 'methods'=>'POST','callback'=>[ $this,$action ],'permission_callback'=>[ $this,'permission' ] ] );
		foreach ( [ 'step','review_settings','apply_settings','remove' ] as $action ) register_rest_route( 'dsa/v1','/workspace/design-packages/jobs/(?P<id>[a-f0-9]{32})/'.$action,[ 'methods'=>'POST','callback'=>[ $this,'job_'.$action ],'permission_callback'=>[ $this,'permission' ] ] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		$cleanup = str_ends_with( $request->get_route(),'/jobs' ) || str_ends_with( $request->get_route(),'/remove' );
		return ( $cleanup ? current_user_can( 'read' ) : Jobs::allowed() ) && ( new Content_Archive_App_Service() )->permission( $request );
	}
	private function fail( \Throwable $error, int $status = 400 ): \WP_REST_Response { return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_design_package',$error instanceof Archive_Error ? $error->getMessage() : 'The design request did not finish. Check the recovery passphrase and saved progress.' ),$status ); }
	private static function job( array $job ): array { if ( 'export' === $job['mode'] && 'ready' === $job['status'] ) $job['downloadUrl'] = add_query_arg( [ 'action'=>self::DOWNLOAD,'id'=>$job['id'],'_wpnonce'=>wp_create_nonce( self::DOWNLOAD.'_'.$job['id'] ) ],admin_url( 'admin-post.php' ) ); return $job; }
	public function jobs( \WP_REST_Request $request ): \WP_REST_Response { try { return Task_Workspace_UI::response( [ 'jobs'=>array_map( [ self::class,'job' ],( new Jobs() )->inventory() ),'maxUploadBytes'=>min( Jobs::MAX_BYTES,wp_max_upload_size() ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error ); } }
	public function export( \WP_REST_Request $request ): \WP_REST_Response { $body = $request->get_json_params(); if ( ! is_array( $body ) || ! is_string( $body['passphrase'] ?? null ) || ! is_string( $body['requestKey'] ?? null ) ) return $this->fail( new Archive_Error( 'Choose a recovery passphrase.' ) ); try { return Task_Workspace_UI::response( [ 'job'=>self::job( ( new Jobs() )->start_export( $body['passphrase'],$body['requestKey'] ) ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error ); } }
	public function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$file = $request->get_file_params()['file'] ?? null; $phrase = $request->get_param( 'passphrase' ); $key = $request->get_param( 'requestKey' );
		if ( ! is_array( $file ) || ! is_string( $phrase ) || ! is_string( $key ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) ) || (int) ( $file['size'] ?? 0 ) > min( Jobs::MAX_BYTES,wp_max_upload_size() ) ) return $this->fail( new Archive_Error( 'Choose a Kiwe design package within this website’s upload limit.' ) );
		try { return Task_Workspace_UI::response( [ 'job'=>self::job( ( new Jobs() )->start_import( $file['tmp_name'],$phrase,$key ) ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error ); }
	}
	private function action( \WP_REST_Request $request, string $action ): \WP_REST_Response {
		$body = $request->get_json_params(); if ( ! is_array( $body ) || ( isset( $body['confirm'] ) && ! is_bool( $body['confirm'] ) ) || ( in_array( $action,[ 'apply_settings','remove' ],true ) && true !== ( $body['confirm'] ?? false ) ) ) return $this->fail( new Archive_Error( 'Review and confirm this design action.' ) );
		if ( 'remove' !== $action && ( ! is_string( $body['passphrase'] ?? null ) || ! is_int( $body['revision'] ?? null ) || $body['revision'] < 1 ) ) return $this->fail( new Archive_Error( 'Choose saved progress and its recovery passphrase.' ) );
		try { $jobs = new Jobs(); $id = (string) $request->get_param( 'id' ); if ( 'remove' === $action ) return Task_Workspace_UI::response( $jobs->remove( $id ) ); $job = 'step' === $action ? $jobs->step( $id,$body['passphrase'],$body['revision'],true === ( $body['confirm'] ?? false ) ) : $jobs->$action( $id,$body['passphrase'],$body['revision'] ); return Task_Workspace_UI::response( [ 'job'=>self::job( $job ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error,409 ); }
	}
	public function job_step( \WP_REST_Request $request ): \WP_REST_Response { return $this->action( $request,'step' ); }
	public function job_review_settings( \WP_REST_Request $request ): \WP_REST_Response { return $this->action( $request,'review_settings' ); }
	public function job_apply_settings( \WP_REST_Request $request ): \WP_REST_Response { return $this->action( $request,'apply_settings' ); }
	public function job_remove( \WP_REST_Request $request ): \WP_REST_Response { return $this->action( $request,'remove' ); }
	public function download(): void {
		if ( ! is_user_logged_in() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'This design download is unavailable.','',[ 'response'=>403 ] ); $id = is_string( $_GET['id'] ?? null ) ? $_GET['id'] : ''; check_admin_referer( self::DOWNLOAD.'_'.$id );
		try { $path = ( new Jobs() )->download_path( $id ); } catch ( \Throwable $error ) { wp_die( 'This design download expired, changed or is unavailable.','',[ 'response'=>403 ] ); }
		nocache_headers(); header( 'Cache-Control: private, no-store' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: application/octet-stream' ); header( 'Content-Disposition: attachment; filename="kiwe-design-'.$id.'.kiwe-design"' ); header( 'Content-Length: '.filesize( $path ) ); $stream = fopen( $path,'rb' ); if ( ! $stream ) exit; while ( ! feof( $stream ) ) { echo fread( $stream,65536 ); flush(); } fclose( $stream ); exit;
	}
}
