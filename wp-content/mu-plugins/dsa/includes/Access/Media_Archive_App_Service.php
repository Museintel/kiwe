<?php
namespace DSA\Access;

use DSA\Backup\Media_Archive_Service as Media;
use DSA\Backup\Media_Archive_Job_Service as Jobs;
use DSA\Backup\Archive_Error;

if ( ! defined( 'ABSPATH' ) ) exit;

/** The existing workspace request guard with native upload and per-job authority. */
final class Media_Archive_App_Service {
	public const DOWNLOAD = 'kiwe_media_download';
	public static function config(): array { return [ 'mediaArchiveEndpoint'=>Media::can_use() ? rest_url( 'dsa/v1/workspace/media-archives' ) : '','mediaArchiveUploadBytes'=>min( Jobs::MAX_BYTES, wp_max_upload_size() ),'bricksFontArchivesAvailable'=>\DSA\Backup\Bricks_Font_Archive_Service::allowed() && class_exists('\ZipArchive'),'mediaArchivesAvailable'=>Media::can_use() && class_exists( '\ZipArchive' ) ]; }
	public function register(): void {
		if ( ! defined( 'KIWE_REACT_WORKSPACE' ) || true !== KIWE_REACT_WORKSPACE ) return;
		add_action( 'rest_api_init', [ $this,'routes' ] ); add_action( 'admin_post_' . self::DOWNLOAD, [ $this,'download' ] );
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/media-archives', [ 'methods'=>'GET','callback'=>[ $this,'capabilities' ],'permission_callback'=>[ $this,'permission' ] ] );
		foreach ( [ 'export','preview' ] as $action ) register_rest_route( 'dsa/v1', '/workspace/media-archives/' . $action, [ 'methods'=>'POST','callback'=>[ $this,$action ],'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/media-archives/jobs', [ 'methods'=>'GET','callback'=>[ $this,'jobs' ],'permission_callback'=>[ $this,'permission' ] ] );
		foreach ( [ 'step','remove' ] as $action ) register_rest_route( 'dsa/v1', '/workspace/media-archives/jobs/(?P<id>[a-f0-9]{32})/' . $action, [ 'methods'=>'POST','callback'=>[ $this,'job_'.$action ],'permission_callback'=>[ $this,'permission' ] ] );
	}
	public function permission( \WP_REST_Request $request ): bool { return Media::can_use() && ( new Content_Archive_App_Service() )->permission( $request ); }
	private function fail( \Throwable $error, int $status = 400 ): \WP_REST_Response { return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_media_archive', $error instanceof Archive_Error ? $error->getMessage() : 'The media request could not be completed. Check the selection, recovery passphrase and saved progress.' ), $status ); }
	public function capabilities( \WP_REST_Request $request ): \WP_REST_Response { return Task_Workspace_UI::response( [ 'schema'=>'kiwe.media-archive-workspace.v1',...self::config(),'maxRawBytes'=>Media::MAX_RAW_BYTES,'maxAttachments'=>Media::MAX_ATTACHMENTS,'sameSite'=>true ] ); }
	private static function job( array $job ): array {
		if ( 'export' === $job['mode'] && 'ready' === $job['status'] ) $job['downloadUrl'] = add_query_arg( [ 'action'=>self::DOWNLOAD,'id'=>$job['id'],'_wpnonce'=>wp_create_nonce( self::DOWNLOAD.'_'.$job['id'] ) ], admin_url( 'admin-post.php' ) ); return $job;
	}
	public function export( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! is_int( $body['id'] ?? null ) || $body['id'] < 0 || ! is_string( $body['passphrase'] ?? null ) || ! is_string( $body['requestKey'] ?? null ) || ! is_array( $body['selection'] ?? [] ) || ! in_array( $body['scope'] ?? '', [ 'media','attachment','bricks_fonts' ], true ) || ( 'attachment' === $body['scope'] ? $body['id'] < 1 : $body['id'] !== 0 ) ) return $this->fail( new Archive_Error( 'Choose the media, date range and recovery passphrase.' ) );
		try { return Task_Workspace_UI::response( [ 'job'=>self::job( ( 'bricks_fonts' === $body['scope'] ? ( new Jobs() )->start_fonts( $body['passphrase'],$body['requestKey'] ) : ( new Jobs() )->start_export( $body['id'], $body['passphrase'], $body['selection'] ?? [], $body['requestKey'] ) ) ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error ); }
	}
	public function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$file = $request->get_file_params()['file'] ?? null; $phrase = $request->get_param( 'passphrase' ); $key = $request->get_param( 'requestKey' );
		if ( ! is_array( $file ) || ! is_string( $phrase ) || ! is_string( $key ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) ) || (int) ( $file['size'] ?? 0 ) > min( Jobs::MAX_BYTES, wp_max_upload_size() ) ) return $this->fail( new Archive_Error( 'Choose a Kiwe media file within this website’s upload limit.' ) );
		try { return Task_Workspace_UI::response( [ 'job'=>self::job( ( new Jobs() )->start_import( $file['tmp_name'], $phrase, $key ) ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error ); }
	}
	public function jobs( \WP_REST_Request $request ): \WP_REST_Response { try { return Task_Workspace_UI::response( [ 'jobs'=>array_map( [ self::class,'job' ], ( new Jobs() )->inventory() ),'available'=>Media::can_use() && class_exists( '\ZipArchive' ),'maxUploadBytes'=>min( Jobs::MAX_BYTES, wp_max_upload_size() ),'maxPrimary'=>Media::MAX_ATTACHMENTS ] ); } catch ( \Throwable $error ) { return $this->fail( $error ); } }
	public function job_step( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params(); if ( ! is_array( $body ) || ! is_string( $body['passphrase'] ?? null ) || ! is_int( $body['revision'] ?? null ) || $body['revision'] < 1 || ( isset( $body['confirm'] ) && ! is_bool( $body['confirm'] ) ) ) return $this->fail( new Archive_Error( 'Choose saved progress and its recovery passphrase.' ) );
		try { return Task_Workspace_UI::response( [ 'job'=>self::job( ( new Jobs() )->step( (string) $request->get_param( 'id' ), $body['passphrase'], $body['revision'], true === ( $body['confirm'] ?? false ) ) ) ] ); } catch ( \Throwable $error ) { return $this->fail( $error, 409 ); }
	}
	public function job_remove( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params(); if ( ! is_array( $body ) || true !== ( $body['confirm'] ?? false ) ) return $this->fail( new Archive_Error( 'Confirm removal of this private media job. Completed media copies are kept.' ) );
		try { return Task_Workspace_UI::response( ( new Jobs() )->remove( (string) $request->get_param( 'id' ) ) ); } catch ( \Throwable $error ) { return $this->fail( $error, 409 ); }
	}
	public function download(): void {
		if ( ! is_user_logged_in() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'This media download is unavailable.', '', [ 'response'=>403 ] );
		$id = is_string( $_GET['id'] ?? null ) ? $_GET['id'] : ''; check_admin_referer( self::DOWNLOAD.'_'.$id );
		try { $path = ( new Jobs() )->download_path( $id ); } catch ( \Throwable $error ) { wp_die( 'This media download is expired, changed or unavailable.', '', [ 'response'=>403 ] ); }
		nocache_headers(); header( 'Cache-Control: private, no-store' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: application/octet-stream' ); header( 'Content-Disposition: attachment; filename="kiwe-media-'.$id.'.kiwe-media"' ); header( 'Content-Length: '.filesize( $path ) );
		$stream = fopen( $path, 'rb' ); if ( ! $stream ) exit; while ( ! feof( $stream ) ) { echo fread( $stream, 65536 ); flush(); } fclose( $stream ); exit;
	}
}
