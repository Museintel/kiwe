<?php
namespace DSA\Access;

use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Client-facing recovery entrance; the configuration snapshot is one bounded unit. */
final class Backup_Workspace_Service {
	public const PAGE = 'kiwe-backups';
	private const DOWNLOAD = 'kiwe_backup_download';
	public static function url(): string { return admin_url( 'admin.php?page=' . self::PAGE ); }
	public static function allowed(): bool {
		if ( ! is_user_logged_in() ) return false;
		if ( Site_Owner_Service::is_owner() ) return true;
		return Workspace_Admin_Service::uses_workspace( wp_get_current_user() ) && 'administrator' === WordPress_Role_Access_Service::role_for( wp_get_current_user() ) && current_user_can( 'kiwe_manage_context' ) && current_user_can( 'edit_users' );
	}
	public function register(): void {
		if ( ! defined( 'KIWE_REACT_WORKSPACE' ) || true !== KIWE_REACT_WORKSPACE ) return;
		( new Portable_Capture_App_Service() )->register();
		add_action( 'admin_menu', [ $this, 'menu' ], 36 );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'init', [ \DSA\Backup\Backup_Request_Service::class, 'register_type' ] );
		add_action( 'admin_post_' . self::DOWNLOAD, [ $this, 'download' ] );
		if ( is_admin() ) add_action( 'load-toplevel_page_' . self::PAGE, [ $this, 'document' ], 1 );
	}
	public function menu(): void { if ( self::allowed() ) add_menu_page( 'Backups', 'Backups', 'read', self::PAGE, [ $this, 'document' ], 'dashicons-database-export', 36 ); }
	public function document(): void {
		if ( ! self::allowed() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) return;
		Task_Workspace_UI::app_document( 'backups', 'Backups', [ 'endpoint'=>rest_url( 'dsa/v1/workspace/backups' ),...Content_Archive_App_Service::config(),...Bricks_Design_Package_App_Service::config(),...Portable_Capture_App_Service::config() ] );
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/backups', [ [ 'methods'=>'GET', 'callback'=>[ $this,'listing' ], 'permission_callback'=>[ $this,'permission' ] ], [ 'methods'=>'POST', 'callback'=>[ $this,'create' ], 'permission_callback'=>[ $this,'permission' ] ] ] );
		foreach ( [ 'preview','restore','remove' ] as $action ) register_rest_route( 'dsa/v1', '/workspace/backups/' . $action, [ 'methods'=>'POST', 'callback'=>[ $this,$action ], 'permission_callback'=>[ $this,'permission' ] ] );
		foreach ( [ 'request','resolve' ] as $action ) register_rest_route( 'dsa/v1', '/workspace/backups/' . $action, [ 'methods'=>'POST', 'callback'=>[ $this,$action ], 'permission_callback'=>[ $this,'permission' ] ] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		return self::allowed() && in_array( $request->get_method(), [ 'GET','POST' ], true ) && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && Origin_Checker::is_same_site_request() && ( 'GET' === $request->get_method() || true === Origin_Checker::mutation_allowed( $request ) );
	}
	private function fail( \Throwable $error, int $status = 400 ): \WP_REST_Response { return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_backups', $error->getMessage() ), $status ); }
	private function snapshot(): array {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$hint = (bool) preg_match( '/(?:^|\.)hostingersite\.com$/D', $host );
		$copies = ( new Snapshots() )->inventory();
		foreach ( $copies as &$copy ) $copy['downloadUrl'] = self::download_url( $copy['id'] );
		unset( $copy );
		$tickets = \DSA\Backup\Backup_Request_Service::inventory();
		return [
			'schema'=>'kiwe.backups-workspace.v1',
			'identity'=>\DSA\Site\Site_Identity_Service::workspace_brand(),
			'copies'=>$copies,
			'tickets'=>$tickets,
			'groups'=>Snapshots::option_groups(),
			'groupDescriptions'=>\DSA\Backup\Organization_Snapshot_Service::descriptions()+\DSA\Backup\Account_Profile_Snapshot_Service::descriptions()+\DSA\Backup\Kiwe_Configuration_Snapshot_Service::descriptions()+\DSA\Backup\Product_Attribute_Snapshot_Service::descriptions(),
			'coverage'=>\DSA\Backup\Unit_Catalog_Service::snapshot(),
			'provider'=>[ 'name'=>$hint ? 'Hostinger' : 'Your hosting provider', 'detected'=>$hint, 'status'=>'unavailable', 'lastBackup'=>null, 'nextBackup'=>null, 'canCreate'=>false, 'dashboardUrl'=>$hint ? 'https://hpanel.hostinger.com/websites' : '' ],
			'limits'=>[ 'kind'=>'Website settings', 'maxBytes'=>Snapshots::MAX_FILE, 'passphraseMinimum'=>16 ],
		];
	}
	public function listing( \WP_REST_Request $request ): \WP_REST_Response { try { return Task_Workspace_UI::response( $this->snapshot() ); } catch ( \Throwable $error ) { return $this->fail( $error, 503 ); } }
	public function request( \WP_REST_Request $request ): \WP_REST_Response { try { $body = $request->get_json_params(); if ( ! is_array( $body ) ) throw new \RuntimeException( 'Choose a backup request reason.' ); $result = \DSA\Backup\Backup_Request_Service::create( $body ); return Task_Workspace_UI::response( [ 'requested'=>$result, ...$this->snapshot() ] ); } catch ( \Throwable $error ) { return $this->fail( $error, 409 ); } }
	public function resolve( \WP_REST_Request $request ): \WP_REST_Response { try { $body = $request->get_json_params(); if ( ! is_array( $body ) ) throw new \RuntimeException( 'Review the backup request.' ); $result = \DSA\Backup\Backup_Request_Service::resolve( $body ); return Task_Workspace_UI::response( [ 'resolved'=>$result, ...$this->snapshot() ] ); } catch ( \Throwable $error ) { return $this->fail( $error, 409 ); } }
	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! is_string( $body['passphrase'] ?? null ) ) return $this->fail( new \RuntimeException( 'Choose a recovery passphrase.' ) );
		$groups = $body['groups'] ?? null;
		if ( ! is_array( $groups ) || array_filter( $groups, static fn( $name ) => ! is_string( $name ) ) ) return $this->fail( new \RuntimeException( 'Choose one or more available settings groups.' ) );
		try { $copy = ( new Snapshots() )->create( $body['passphrase'], is_string( $body['label'] ?? null ) ? $body['label'] : '', $groups ); return Task_Workspace_UI::response( [ 'created'=>$copy, ...$this->snapshot() ] ); }
		catch ( \Throwable $error ) { return $this->fail( $error ); }
	}
	public function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( is_array( $body ) ) {
			if ( ! is_string( $body['id'] ?? null ) || ! is_string( $body['passphrase'] ?? null ) ) return $this->fail( new \RuntimeException( 'Choose a saved copy and enter its recovery passphrase.' ) );
			foreach ( [ 'fontRecovery','mediaRecovery','templateRecovery' ] as $key ) if ( isset( $body[$key] ) && ! is_string( $body[$key] ) ) return $this->fail( new \RuntimeException( 'Choose a valid completed recovery.' ) );
			try { $service = new Snapshots(); $bytes = file_get_contents( $service->download_path( $body['id'] ) ); if ( ! is_string( $bytes ) ) throw new \RuntimeException( 'The saved copy could not be read.' ); return Task_Workspace_UI::response( [ 'review'=>$service->preview_upload( $bytes, $body['passphrase'], $body['fontRecovery'] ?? '', $body['mediaRecovery'] ?? '', $body['templateRecovery'] ?? '' ) ] ); }
			catch ( \Throwable $error ) { return $this->fail( $error ); }
		}
		$file = $request->get_file_params()['file'] ?? null; $passphrase = $request->get_param( 'passphrase' );
		if ( ! is_array( $file ) || ! is_string( $passphrase ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) ) || (int) ( $file['size'] ?? 0 ) > Snapshots::MAX_FILE ) return $this->fail( new \RuntimeException( 'Choose a website settings snapshot up to 16 MB. Standard settings copies remain limited to 2 MB.' ) );
		$bytes = file_get_contents( $file['tmp_name'] );
		if ( ! is_string( $bytes ) ) return $this->fail( new \RuntimeException( 'The uploaded snapshot could not be read.' ) );
		foreach ( [ 'fontRecovery','mediaRecovery','templateRecovery' ] as $key ) if ( null !== $request->get_param( $key ) && ! is_string( $request->get_param( $key ) ) ) return $this->fail( new \RuntimeException( 'Choose a valid completed recovery.' ) );
		try { return Task_Workspace_UI::response( [ 'review'=>( new Snapshots() )->preview_upload( $bytes, $passphrase, $request->get_param( 'fontRecovery' ) ?? '', $request->get_param( 'mediaRecovery' ) ?? '', $request->get_param( 'templateRecovery' ) ?? '' ) ] ); }
		catch ( \Throwable $error ) { return $this->fail( $error ); }
	}
	public function restore( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! is_string( $body['token'] ?? null ) || ! is_string( $body['passphrase'] ?? null ) || true !== ( $body['confirm'] ?? null ) ) return $this->fail( new \RuntimeException( 'Review and confirm this settings restore.' ) );
		try { $result = ( new Snapshots() )->apply( $body['token'], $body['passphrase'] ); return Task_Workspace_UI::response( [ 'restored'=>$result, ...$this->snapshot() ] ); }
		catch ( \Throwable $error ) { return $this->fail( $error, 409 ); }
	}
	public function remove( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! is_string( $body['id'] ?? null ) || true !== ( $body['confirm'] ?? null ) ) return $this->fail( new \RuntimeException( 'Confirm which private copy to remove.' ) );
		try { ( new Snapshots() )->remove( $body['id'] ); return Task_Workspace_UI::response( $this->snapshot() ); }
		catch ( \Throwable $error ) { return $this->fail( $error ); }
	}
	public function download(): void {
		if ( ! self::allowed() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'This backup is unavailable.', '', [ 'response'=>403 ] );
		$id = is_string( $_GET['id'] ?? null ) ? $_GET['id'] : '';
		check_admin_referer( self::DOWNLOAD . '_' . $id );
		try { $path = ( new Snapshots() )->download_path( $id ); }
		catch ( \Throwable $error ) { wp_die( esc_html( $error->getMessage() ), '', [ 'response'=>404 ] ); }
		nocache_headers(); header( 'Cache-Control: private, no-store' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: application/octet-stream' ); header( 'Content-Disposition: attachment; filename="kiwe-website-settings-' . $id . '.kiwe"' ); header( 'Content-Length: ' . filesize( $path ) );
		$stream = fopen( $path, 'rb' ); if ( ! $stream ) exit;
		while ( ! feof( $stream ) ) { echo fread( $stream, 65536 ); flush(); }
		fclose( $stream ); exit;
	}
	// JSON consumers need a plain URL; wp_nonce_url() escapes '&' for HTML.
	public static function download_url( string $id ): string { return add_query_arg( [ 'action'=>self::DOWNLOAD, 'id'=>$id, '_wpnonce'=>wp_create_nonce( self::DOWNLOAD . '_' . $id ) ], admin_url( 'admin-post.php' ) ); }
}
