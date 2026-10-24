<?php
/** Native isolated settings proof. Include only from an authorized CLI lab wrapper. */
if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || true !== KIWE_WORKSPACE_TEST_SITE || ! defined( 'KIWE_WORKSPACE_EXPECTED_VERSION' ) ) exit( 2 );

use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
use DSA\Access\Backup_Workspace_Service as Workspace;
use DSA\Access\Site_Owner_Service as Owner;

$proof = [ 'schema'=>'kiwe-native-backups.v1', 'version'=>DSA_VERSION, 'php'=>PHP_VERSION, 'wordpress'=>$GLOBALS['wp_version'], 'checks'=>[], 'cleanup'=>false ];
$assert = static function ( bool $ok, string $label ) use ( &$proof ) { $proof['checks'][] = [ 'label'=>$label, 'passed'=>$ok ]; if ( ! $ok ) throw new RuntimeException( $label ); };
$actor = get_current_user_id(); $nativeOptions = $wpdb->options; $nativeCache = $GLOBALS['wp_object_cache'];
$table = $wpdb->prefix . 'kiwe_backup_qa_' . bin2hex( random_bytes( 6 ) );
$created = false; $users = []; $filters = []; $copies = []; $reviews = []; $prefix = 'kiwebackupqa_' . bin2hex( random_bytes( 6 ) );
$passphrase = 'synthetic-recovery-phrase-123';
$filter = static function ( string $hook, callable $callback, int $args = 1 ) use ( &$filters ) { add_filter( $hook, $callback, PHP_INT_MAX, $args ); $filters[] = [ $hook,$callback ]; };
$dispatch = static function ( string $method, string $path = '', ?array $body = null, bool $nonce = true, bool $mutation = true ) {
	$request = new WP_REST_Request( $method, '/dsa/v1/workspace/backups' . $path );
	if ( $nonce ) $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	if ( $mutation ) $request->set_header( 'X-Kiwe-Mutation', '1' );
	if ( null !== $body ) { $request->set_header( 'Content-Type','application/json' ); $request->set_body( wp_json_encode( $body ) ); }
	return rest_do_request( $request );
};
$rememberReview = static function ( array $review ) use ( &$reviews ) { $reviews[ $review['token'] ] = get_transient( 'kiwe_backup_review_' . $review['token'] ); };

try {
	$package = DSA\Runtime\Package_Manifest::verify( true );
	$assert( DSA_VERSION === KIWE_WORKSPACE_EXPECTED_VERSION && ! empty( $package['valid'] ) && ! empty( $package['complete'] ), 'matching complete installed package' );
	$assert( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ), 'native encryption available' );
	$assert( ! wp_using_ext_object_cache() && str_ends_with( str_replace( '\\','/', ( new ReflectionClass( 'WP_Object_Cache' ) )->getFileName() ), '/wp-includes/class-wp-object-cache.php' ), 'core request-local cache supports isolated native options' );
	$filter( 'pre_wp_mail', static fn()=>false );
	$filter( 'pre_http_request', static fn()=>new WP_Error( 'kiwe_qa_network', 'Network blocked during backup acceptance' ), 3 );
	$off = static fn()=>[]; $filter( 'pre_option_' . Owner::OPTION, $off );
	foreach ( [ 'owner'=>'administrator','client'=>'administrator','author'=>'author','visitor'=>'subscriber' ] as $name=>$role ) {
		$id = wp_insert_user( [ 'user_login'=>$prefix . '_' . $name,'user_pass'=>wp_generate_password( 40,true,true ),'user_email'=>$prefix . '_' . $name . '@example.invalid','role'=>$role ] );
		if ( is_wp_error( $id ) ) throw new RuntimeException( 'Synthetic account creation failed' );
		$users[$name] = (int) $id;
	}
	remove_filter( 'pre_option_' . Owner::OPTION,$off,PHP_INT_MAX );
	$seed = [ 'siteurl'=>get_option( 'siteurl' ),'home'=>get_option( 'home' ),$wpdb->prefix . 'user_roles'=>wp_roles()->roles,'blogname'=>$prefix,'blogdescription'=>'Original synthetic choice','active_plugins'=>get_option( 'active_plugins' ),'template'=>get_option( 'template' ),'stylesheet'=>get_option( 'stylesheet' ),'start_of_week'=>1,'kiwe_store_phone'=>'Original synthetic phone' ];
	$assert( false !== $wpdb->query( "CREATE TEMPORARY TABLE {$table} LIKE {$nativeOptions}" ), 'temporary native options table created' ); $created = true;
	$wpdb->options = $table; $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	foreach ( $seed as $key=>$value ) add_option( $key,$value,'',false );
	$filter( 'pre_option_' . Owner::OPTION, static fn()=>[ 'enabled'=>true,'ownerId'=>$users['owner'],'rankMath'=>'inline' ] );
	wp_set_current_user( $users['owner'] );
	$assert( Workspace::allowed(), 'technical owner retains snapshot authority' );
	wp_set_current_user( $users['client'] );
	$assert( Workspace::allowed() && ! current_user_can( 'manage_options' ), 'client backup authority does not grant technical settings' );
	$assert( $dispatch( 'GET','',null,false )->get_status() === 403 && $dispatch( 'POST','',[ 'groups'=>['WordPress'],'passphrase'=>'synthetic-recovery-phrase-123' ],true,false )->get_status() === 403, 'native routes deny missing nonce and mutation proof' );
	$_SERVER['HTTP_ORIGIN'] = 'https://outside.invalid';
	$assert( $dispatch( 'GET' )->get_status() === 403, 'cross-site inventory denied' );
	unset( $_SERVER['HTTP_ORIGIN'] );
	foreach ( [ 'author','visitor' ] as $name ) { wp_set_current_user( $users[$name] ); $assert( $dispatch( 'GET' )->get_status() === 403, $name . ' cannot access backup inventory' ); }
	wp_set_current_user( $users['client'] );
	$passphrase = 'synthetic-recovery-phrase-123'; $service = new Snapshots();
	$result = $dispatch( 'POST','',[ 'groups'=>['WordPress'],'label'=>$prefix . ' Before','passphrase'=>$passphrase ] );
	$data = $result->get_data();
	if ( isset( $data['created']['id'] ) ) $copies[] = $data['created']['id'];
	$assert( $result->get_status() === 200 && isset( $data['created']['id'] ), 'native client create stores an encrypted selected snapshot' );
	$copy = $data['created']; $path = $service->download_path( $copy['id'] ); $bytes = file_get_contents( $path );
	parse_str( (string) wp_parse_url( Workspace::download_url( $copy['id'] ), PHP_URL_QUERY ),$downloadQuery );
	$assert( ( $downloadQuery['action'] ?? '' ) === 'kiwe_backup_download' && ( $downloadQuery['id'] ?? '' ) === $copy['id'] && (bool) wp_verify_nonce( $downloadQuery['_wpnonce'] ?? '', 'kiwe_backup_download_' . $copy['id'] ), 'JSON download URL preserves native nonce and query keys' );
	$assert( ! str_starts_with( $path, realpath( ABSPATH ) . '/' ) && ! str_contains( $bytes, 'Original synthetic choice' ), 'encrypted copy stored outside document root' );
	$header = json_decode( $bytes,true );
	$assert( $header['groups'] === ['WordPress'] && ! in_array( 'dsa_settings',array_merge( ...array_values( Snapshots::option_groups() ) ),true ), 'snapshot manifest records selected groups and excludes secrets' );
	$assert( $dispatch( 'POST','/preview',[ 'id'=>$copy['id'],'passphrase'=>'incorrect-recovery-phrase' ] )->get_status() === 400, 'wrong passphrase denied through native REST' );
	update_option( 'blogdescription','Changed synthetic choice',false );
	update_option( 'site_icon',0,false );
	$preview = $dispatch( 'POST','/preview',[ 'id'=>$copy['id'],'passphrase'=>$passphrase ] );
	$review = $preview->get_data()['review'] ?? []; if ( isset( $review['token'] ) ) $rememberReview( $review );
	$assert( $preview->get_status() === 200 && $review['changedCount'] === 2, 'review reports value and default-absence changes' );
	update_option( 'blogdescription','Newer synthetic choice',false );
	$assert( $dispatch( 'POST','/restore',[ 'token'=>$review['token'],'passphrase'=>$passphrase,'confirm'=>true ] )->get_status() === 409, 'stale native settings review refused' );
	update_option( 'blogdescription','Changed synthetic choice',false );
	$review = $service->preview_upload( $bytes,$passphrase ); $rememberReview( $review );
	$restored = $dispatch( 'POST','/restore',[ 'token'=>$review['token'],'passphrase'=>$passphrase,'confirm'=>true ] );
	$restoreData = $restored->get_data(); if ( isset( $restoreData['restored']['rollbackId'] ) ) $copies[] = $restoreData['restored']['rollbackId'];
	$assert( $restored->get_status() === 200 && get_option( 'blogdescription' ) === 'Original synthetic choice' && get_option( 'site_icon','missing' ) === 'missing' && get_option( 'kiwe_store_phone' ) === 'Original synthetic phone', 'native restore reads back selected values and leaves unrelated group intact' );
	$assert( ( $restoreData['identity']['siteTagline'] ?? '' ) === 'Original synthetic choice', 'restore projects refreshed native identity to the shared header' );
	$assert( isset( $restoreData['restored']['rollbackId'] ) && is_file( $service->download_path( $restoreData['restored']['rollbackId'] ) ), 'native before-restore copy retained' );
	$assert( $dispatch( 'POST','/restore',[ 'token'=>$review['token'],'passphrase'=>$passphrase,'confirm'=>true ] )->get_status() === 409, 'review token cannot be replayed' );
	$undo = $service->preview_upload( file_get_contents( $service->download_path( $restoreData['restored']['rollbackId'] ) ),$passphrase ); $rememberReview( $undo );
	$undone = $service->apply( $undo['token'],$passphrase ); $copies[] = $undone['rollbackId'];
	$assert( get_option( 'blogdescription' ) === 'Changed synthetic choice' && (string) get_option( 'site_icon' ) === '0', 'retained copy reverses native restore' );
	$inventory = $dispatch( 'GET' )->get_data();
	$assert( ( $inventory['provider']['status'] ?? '' ) === 'unavailable' && null === $inventory['provider']['lastBackup'] && false === $inventory['provider']['canCreate'], 'provider state stays explicitly unavailable' );
	$assert( $dispatch( 'POST','/remove',[ 'id'=>$copy['id'],'confirm'=>false ] )->get_status() === 400, 'copy removal needs confirmation' );
	$removed = $dispatch( 'POST','/remove',[ 'id'=>$copy['id'],'confirm'=>true ] );
	$assert( $removed->get_status() === 200 && ! is_file( $path ), 'native confirmed removal affects only selected server copy' );
} catch ( Throwable $error ) { $proof['error'] = [ 'message'=>$error->getMessage(),'line'=>$error->getLine() ]; }
finally {
	$clean = true;
	if ( $created ) {
		$service = new Snapshots();
		// A failed REST apply can retain a recovery file before returning its ID.
		// Retire only files whose decrypted native site name proves this fixture.
		$decrypt = ( new ReflectionClass( Snapshots::class ) )->getMethod( 'decrypt' );
		foreach ( $service->inventory() as $candidate ) {
			try {
				$settings = $decrypt->invoke( null,file_get_contents( $service->download_path( $candidate['id'] ) ),$passphrase );
				if ( ( $settings['blogname']['value'] ?? '' ) === $prefix ) $copies[] = $candidate['id'];
			} catch ( Throwable $ignored ) {}
		}
		foreach ( array_unique( $copies ) as $id ) { try { $service->remove( $id ); } catch ( Throwable $ignored ) {} }
		$private = dirname( realpath( ABSPATH ) ) . '/kiwe-private-backups';
		foreach ( $reviews as $token=>$review ) { delete_transient( 'kiwe_backup_review_' . $token ); $id=$review['id'] ?? ''; if ( preg_match( '/^[a-f0-9]{24}$/D',$id ) ) @unlink( $private . '/stage-' . $id . '.kiwe' ); }
		foreach ( $copies as $id ) if ( is_file( $private . '/website-' . $id . '.kiwe' ) ) $clean = false;
		$wpdb->options = $nativeOptions; $GLOBALS['wp_object_cache'] = $nativeCache;
		$clean = false !== $wpdb->query( "DROP TEMPORARY TABLE {$table}" ) && $clean;
	}
	foreach ( $filters as [ $hook,$callback ] ) if ( $hook === 'pre_option_' . Owner::OPTION ) remove_filter( $hook,$callback,PHP_INT_MAX );
	wp_set_current_user( $actor ); require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) { $user=get_userdata( $id ); if ( $user && str_starts_with( $user->user_login,$prefix ) ) wp_delete_user( $id ); if ( get_userdata( $id ) ) $clean=false; }
	foreach ( array_reverse( $filters ) as [ $hook,$callback ] ) remove_filter( $hook,$callback,PHP_INT_MAX );
	$proof['cleanup'] = $clean;
}
$proof['passed'] = ! isset( $proof['error'] ) && $proof['cleanup'];
return $proof;
