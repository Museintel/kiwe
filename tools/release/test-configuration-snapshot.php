<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kiwe-backup-qa-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . DIRECTORY_SEPARATOR . 'public_html', 0700, true );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR );
define( 'DAY_IN_SECONDS', 86400 );
$options = [ 'blogname'=>'Kiwe QA', 'blogdescription'=>'Before', 'kiwe_seam_design_context_v1'=>[ 'goal'=>'Grow' ], 'woocommerce_currency'=>'INR' ];
$option_autoload = [ 'blogname'=>true, 'blogdescription'=>true ];
$transients = [];
$site = 'https://qa.example.test/';
$checks = [];
$fail_next_write = false;
$theme_mods = [ 'custom_logo'=>11 ];
$theme = 'qa-theme';
$missing_image = false;
$change_during_prepare = 0;

function get_option( $name, $default = false ) { global $options, $change_during_prepare; if ( $name === 'blogdescription' && $change_during_prepare > 0 && --$change_during_prepare === 0 ) $options[$name] = 'Concurrent editor choice'; return array_key_exists( $name, $options ) ? $options[$name] : $default; }
function update_option( $name, $value, $autoload = null ) { global $options, $option_autoload, $fail_next_write; if ( $fail_next_write && $name === 'blogdescription' ) { $fail_next_write = false; return false; } $options[$name] = $value; if ( null !== $autoload ) $option_autoload[$name] = $autoload; return true; }
function delete_option( $name ) { global $options; unset( $options[$name] ); return true; }
function add_option( $name, $value, $deprecated = '', $autoload = null ) { global $options; if ( array_key_exists( $name, $options ) ) return false; $options[$name] = $value; return true; }
function get_transient( $name ) { global $transients; return $transients[$name] ?? false; }
function set_transient( $name, $value, $expiry ) { global $transients; $transients[$name] = $value; return true; }
function delete_transient( $name ) { global $transients; unset( $transients[$name] ); return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0700, true ); }
function home_url( $path = '' ) { global $site; return $site . ltrim( $path, '/' ); }
function get_current_blog_id() { return 1; }
function get_current_user_id() { return 7; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function WC() { return true; }
function get_stylesheet() { global $theme; return $theme; }
function get_theme_mod( $name, $default = false ) { global $theme_mods; return array_key_exists( $name, $theme_mods ) ? $theme_mods[$name] : $default; }
function set_theme_mod( $name, $value ) { global $theme_mods; $theme_mods[$name] = $value; }
function remove_theme_mod( $name ) { global $theme_mods; unset( $theme_mods[$name] ); }
function wp_attachment_is_image( $id ) { global $missing_image; return ! $missing_image && $id === 11; }
function get_post( $id ) { return null; }

require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Cipher.php';
require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Backup/Configuration_Snapshot_Service.php';
use DSA\Backup\Configuration_Snapshot_Service as Snapshots;

function expect( bool $condition, string $name ): void { global $checks; $checks[$name] = $condition; if ( ! $condition ) throw new RuntimeException( $name ); }
function rejects( callable $action, string $name ): void { $thrown = false; try { $action(); } catch ( RuntimeException $error ) { $thrown = true; } expect( $thrown, $name ); }
function clear_directory( string $directory ): void {
	foreach ( scandir( $directory ) ?: [] as $name ) {
		if ( $name === '.' || $name === '..' ) continue;
		$path = $directory . DIRECTORY_SEPARATOR . $name;
		if ( is_dir( $path ) ) clear_directory( $path ); else unlink( $path );
	}
	rmdir( $directory );
}

$passed = false; $error_message = '';
try {
	$service = new Snapshots(); $passphrase = 'a-long-private-recovery-passphrase';
	rejects( static fn() => $service->create( $passphrase, '', [] ), 'empty group selection denied' );
	rejects( static fn() => $service->create( $passphrase, '', [ 'Secrets' ] ), 'unknown group denied' );
	$wordpress = $service->create( $passphrase, 'WordPress only', [ 'WordPress' ] );
	$wordpress_body = json_decode( file_get_contents( $service->download_path( $wordpress['id'] ) ), true );
	expect( $wordpress_body['groups'] === [ 'WordPress' ] && $wordpress_body['count'] === count( Snapshots::option_groups()['WordPress'] ), 'selected settings groups' );
	$service->remove( $wordpress['id'] );
	$copy = $service->create( $passphrase, 'Before change' );
	$path = $service->download_path( $copy['id'] ); $bytes = file_get_contents( $path );
	expect( str_starts_with( $path, $root . DIRECTORY_SEPARATOR . 'kiwe-private-backups' ), 'private storage outside docroot' );
	expect( ! str_contains( $bytes, 'Kiwe QA' ) && ! str_contains( $bytes, 'Grow' ), 'content encrypted' );
	expect( count( $service->inventory() ) === 1, 'inventory' );
	rejects( static fn() => $service->preview_upload( $bytes, 'wrong-passphrase' ), 'wrong passphrase denied' );
	$damaged = json_decode( $bytes, true ); $damaged['data'] = base64_encode( 'tampered' );
	rejects( static fn() => $service->preview_upload( json_encode( $damaged ), $passphrase ), 'tamper denied' );
	$site = 'https://another.example.test/';
	rejects( static fn() => $service->preview_upload( $bytes, $passphrase ), 'other site denied' );
	$site = 'https://qa.example.test/';
	$theme = 'different-theme';
	rejects( static fn() => $service->preview_upload( $bytes, $passphrase ), 'changed theme denied' );
	$theme = 'qa-theme'; $missing_image = true;
	rejects( static fn() => $service->preview_upload( $bytes, $passphrase ), 'missing image denied' );
	$missing_image = false;
	update_option( 'blogdescription', 'After' );
	update_option( 'kiwe_store_phone', 'synthetic-added-choice' );
	set_theme_mod( 'custom_logo', 0 );
	$review = $service->preview_upload( $bytes, $passphrase );
	expect( $review['changed'] === [ 'blogdescription','theme.custom_logo','kiwe_store_phone' ], 'review changed setting' );
	update_option( 'blogdescription', 'Later' );
	rejects( static fn() => $service->apply( $review['token'], $passphrase ), 'stale review denied' );
	update_option( 'blogdescription', 'After' );
	$review = $service->preview_upload( $bytes, $passphrase );
	$result = $service->apply( $review['token'], $passphrase );
	expect( $result['restored'] === 3 && get_option( 'blogdescription' ) === 'Before', 'settings restored' );
	expect( true === $option_autoload['blogdescription'] && true === $option_autoload['blogname'], 'existing autoload policy preserved during successful restore' );
	expect( (string) get_theme_mod( 'custom_logo' ) === '11', 'native theme logo restored' );
	expect( get_option( 'kiwe_store_phone', 'absent' ) === 'absent', 'default absence restored' );
	expect( count( $service->inventory() ) === 2 && is_file( $service->download_path( $result['rollbackId'] ) ), 'rollback copy retained' );
	update_option( 'blogdescription', 'Third version' );
	$review = $service->preview_upload( $bytes, $passphrase );
	$fail_next_write = true;
	rejects( static fn() => $service->apply( $review['token'], $passphrase ), 'failed native write denied' );
	expect( get_option( 'blogdescription' ) === 'Third version' && count( $service->inventory() ) === 3, 'failed write rolled back with copy' );
	expect( true === $option_autoload['blogdescription'], 'existing autoload policy preserved during failure rollback' );
	$review = $service->preview_upload( $bytes, $passphrase );
	$change_during_prepare = 3;
	rejects( static fn() => $service->apply( $review['token'], $passphrase ), 'preparation race refused' );
	expect( get_option( 'blogdescription' ) === 'Concurrent editor choice' && get_option( 'kiwe_backup_restore_lock_v1', 'absent' ) === 'absent' && count( $service->inventory() ) === 3, 'preparation failure preserves newer settings and clears lock' );
	$service->remove( $copy['id'] );
	expect( count( $service->inventory() ) === 2, 'selected copy removed' );
	$validate = new ReflectionMethod( DSA\Backup\Configuration_Snapshot_Service::class, 'validate_references' );
	$brand = [ 'kiwe_seam_design_context_v1'=>[ 'exists'=>true,'value'=>[ 'identity'=>[ 'squareLogoId'=>11,'roundLogoId'=>11 ] ] ] ];
	$validate->invoke( null, $brand );
	expect( true, 'optional business logos restore with their existing native media dependency' );
	$missing_image = true;
	rejects( static fn() => $validate->invoke( null, $brand ), 'optional business logo dependency is missing' );
	$missing_image = false;
	$brand['kiwe_seam_design_context_v1']['value']['identity']['roundLogoId'] = '11';
	rejects( static fn() => $validate->invoke( null, $brand ), 'optional business logo rejects a forged reference type' );
	$brand['kiwe_seam_design_context_v1']['value']['identity'] = [ 'squareLogoId'=>0,'roundLogoId'=>0 ];
	$validate->invoke( null, $brand );
	expect( true, 'older profiles and explicit optional-logo removal require no new media dependency' );
	$passed = true;
} catch ( Throwable $error ) { $error_message = $error->getMessage(); }
finally { clear_directory( $root ); }

echo json_encode( [ 'passed'=>$passed, 'cleanup'=>! file_exists( $root ), 'checks'=>$checks, 'error'=>$error_message ] ) . PHP_EOL;
exit( $passed && ! file_exists( $root ) ? 0 : 1 );
