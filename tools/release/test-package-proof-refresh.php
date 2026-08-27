<?php

// Uses the real local manifest with an in-memory WordPress option store.
// No WordPress database or package files are mutated by this test.
define( 'ABSPATH', __DIR__ );
define( 'DSA_DIR', dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/' );
$manifest = json_decode( file_get_contents( DSA_DIR . 'package-manifest.json' ), true );
define( 'DSA_VERSION', $manifest['version'] );
$options = [];
$writes = 0;
function get_option( $name, $default = false ) {
	return $GLOBALS['options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['options'][ $name ] = $value;
	++$GLOBALS['writes'];
	return true;
}
require DSA_DIR . 'includes/Runtime/Package_Manifest.php';
function check( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
	echo "PASS {$label}\n";
}
$normalize = new ReflectionMethod( \DSA\Runtime\Package_Manifest::class, 'canonical_body' );
check( "one\ntwo\n" === $normalize->invoke( null, "one\r\ntwo\r", 'PHP' ), 'text fingerprints use the build-time LF normalization' );
check( "one\r\ntwo\r" === $normalize->invoke( null, "one\r\ntwo\r", 'png' ), 'binary fingerprints stay byte-exact' );
check( "changed\n" !== $normalize->invoke( null, "original\r\n", 'php' ), 'normalization never hides changed text' );
$good = \DSA\Runtime\Package_Manifest::verify();
check( $good['complete'], 'canonical package matches its manifest' );
$stale = $good;
$stale['complete'] = false;
$stale['changed'] = [ 'includes/Admin/Admin.php' ];
$options['dsa_package_manifest_proof'] = $stale;
$writes = 0;
$cached = \DSA\Runtime\Package_Manifest::verify();
check( $cached === $stale && 0 === $writes, 'ordinary requests keep the inexpensive cached proof' );
$fresh = \DSA\Runtime\Package_Manifest::verify( true );
check( $fresh['complete'] && [] === $fresh['changed'] && 1 === $writes, 'explicit verification replaces stale upload-time drift' );
$admin = file_get_contents( DSA_DIR . 'includes/Admin/Admin.php' );
check( str_contains( $admin, 'Package_Manifest::verify( true )' ), 'Developer reload requests a real disk verification' );
check( str_contains( $admin, 'Files requiring attention' ), 'Developer exposes exact mismatch paths' );
