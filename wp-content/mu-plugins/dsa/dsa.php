<?php
/**
 * Plugin Name: Kiwe
 * Description: Kiwe Surface, Key.kiwe auth, and appsite layer for WordPress.
 * Version: 8.0.0-rc.53
 * Requires PHP: 8.2
 * Author: Kiwelauch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( PHP_VERSION_ID < 80200 ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Kiwe requires PHP 8.2 or newer. Update PHP in hosting settings, then reload the MU plugin.', 'dsa' ) . '</p></div>';
		}
	);
	return;
}

define( 'DSA_VERSION', '8.0.0-rc.53' );
define( 'DSA_FILE', __FILE__ );
define( 'DSA_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSA_URL', plugin_dir_url( __FILE__ ) );
define( 'DSA_OPTION_SETTINGS', 'dsa_settings' );
define( 'DSA_OPTION_MANIFEST', 'dsa_shell_manifest' );

require_once DSA_DIR . 'includes/Autoloader.php';
require_once DSA_DIR . 'includes/Runtime/Package_Manifest.php';

\DSA\Autoloader::register();

if ( ! defined( 'KIWE_MU_LOADER_VERSION' ) || KIWE_MU_LOADER_VERSION !== DSA_VERSION ) {
	kiwe_mu_debug_log( 'Loader/package version mismatch', [ 'loader' => defined( 'KIWE_MU_LOADER_VERSION' ) ? KIWE_MU_LOADER_VERSION : '', 'package' => DSA_VERSION ] );
	kiwe_mu_admin_notice( 'Kiwe loader and package versions do not match. Upload both wp-content/mu-plugins/dsa.php and the complete dsa folder from the same release.' );
	return;
}

// Authentication is a critical lane. Load the existing Key.kiwe core before
// the wider Kiwe graph and package audit so an already-open sign-in sheet does
// not lose its REST routes during a slow boot or a partially copied manual MU
// deployment. Plugin::register_services() reuses this same core via
// require_once; no second auth service or route set is created.
$kiwe_boot_settings = get_option( DSA_OPTION_SETTINGS, [] );
if (
	is_array( $kiwe_boot_settings )
	&& ! empty( $kiwe_boot_settings['phonekey']['enabled'] )
	&& is_readable( DSA_DIR . 'includes/PhoneKey/phonekey-core.php' )
) {
	( new \DSA\PhoneKey\PhoneKey_Core_Loader() )->register();
}

$dsa_package_proof = \DSA\Runtime\Package_Manifest::verify();
if ( empty( $dsa_package_proof['valid'] ) ) {
	kiwe_mu_debug_log(
		'Package manifest verification failed for required runtime files; Kiwe is disabled for this request',
		[
			'blocking_missing' => $dsa_package_proof['blocking_missing'] ?? [],
			'missing'          => $dsa_package_proof['missing'] ?? [],
			'changed'          => $dsa_package_proof['changed'] ?? [],
		]
	);
	$kiwe_missing_sample = array_slice( (array) ( $dsa_package_proof['blocking_missing'] ?? [] ), 0, 5 );
	kiwe_mu_admin_notice( 'Kiwe found missing, stale, or invalid required runtime files and disabled itself without stopping WordPress. Upload both wp-content/mu-plugins/dsa.php and the complete dsa folder from the same release. First issue(s): ' . implode( ', ', $kiwe_missing_sample ) );
	return;
}

if ( empty( $dsa_package_proof['complete'] ) ) {
	kiwe_mu_debug_log(
		'Package manifest drift detected; Kiwe is continuing because required runtime files are present',
		[
			'missing_count' => count( (array) ( $dsa_package_proof['missing'] ?? [] ) ),
			'changed_count' => count( (array) ( $dsa_package_proof['changed'] ?? [] ) ),
			'missing'       => array_slice( (array) ( $dsa_package_proof['missing'] ?? [] ), 0, 10 ),
			'changed'       => array_slice( (array) ( $dsa_package_proof['changed'] ?? [] ), 0, 10 ),
		]
	);
}

// Image upload/sub-size generation is a memory-sensitive WordPress core path.
// The direct Kiwe Incident Guard MU loader still provides bounded upload and
// security checks, while the large application graph is intentionally skipped.
if ( \DSA\Secure\Incident_Response_Service::is_lean_media_request() ) {
	return;
}

\DSA\Plugin::instance()->boot();
