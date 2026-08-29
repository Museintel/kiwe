<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
function esc_url_raw( string $value ): string { return filter_var( $value, FILTER_SANITIZE_URL ) ?: ''; }
function home_url( string $path = '' ): string { return 'https://staging.example.test' . $path; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''; }
function absint( $value ): int { return abs( (int) $value ); }
function wp_get_environment_type(): string { return 'staging'; }
function get_option( string $name, $default = false ) { return 'blog_public' === $name ? '0' : $default; }

require_once __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Export_Service.php';
require_once __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Preflight_Service.php';
require_once __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Dry_Run_Service.php';

use DSA\Site_Graph\Staging_Seed_Export_Service;
use DSA\Site_Graph\Staging_Seed_Preflight_Service;
use DSA\Site_Graph\Staging_Seed_Dry_Run_Service;

$boundary = array_fill_keys(
	[ 'usersExcluded', 'customersExcluded', 'ordersExcluded', 'credentialsExcluded', 'paymentDataExcluded', 'sessionsExcluded', 'webhooksExcluded', 'downloadFilesExcluded' ],
	true
);
$manifest = [
	'schema' => Staging_Seed_Export_Service::SCHEMA,
	'capabilities' => [ 'pageAuthority' => 'v1', 'cleanReconciliation' => true ],
	'packageId' => 'fixture',
	'revisionHash' => str_repeat( 'a', 64 ),
	'source' => [ 'origin' => 'https://source.example.test' ],
	'resources' => [ 'products' => [ 'count' => 0 ], 'content' => [ 'count' => 4 ], 'media' => [ 'count' => 8 ] ],
	'preservationBoundary' => $boundary,
	'authority' => [ 'readOnly' => true, 'mutationAuthority' => false ],
];

$service = new Staging_Seed_Preflight_Service();
$safe = $service->evaluate( $manifest );
if ( 'ready-for-human-reviewed-import' !== $safe['status'] || [] !== $safe['blockers'] || $safe['contentMutated'] || $safe['credentialsStored'] ) {
	fwrite( STDERR, "Safe staging manifest did not pass the fail-closed preflight.\n" );
	exit( 1 );
}

$legacy = $manifest;
unset( $legacy['capabilities'] );
$legacy_result = $service->evaluate( $legacy );
if ( ! in_array( 'source_missing_clean_reconciliation_capability', $legacy_result['blockers'], true ) ) {
	fwrite( STDERR, "Legacy source did not fail closed before package pull.\n" );
	exit( 1 );
}

$unsafe = $manifest;
$unsafe['source']['origin'] = 'https://staging.example.test';
$unsafe['preservationBoundary']['ordersExcluded'] = false;
$unsafe['authority']['mutationAuthority'] = true;
$blocked = $service->evaluate( $unsafe );
foreach ( [ 'source_and_destination_are_same_site', 'unsafe_boundary_ordersexcluded', 'source_export_not_read_only' ] as $expected ) {
	if ( ! in_array( $expected, $blocked['blockers'], true ) ) {
		fwrite( STDERR, "Missing expected blocker: {$expected}\n" );
		exit( 1 );
	}
}

$export_source = file_get_contents( __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Export_Service.php' );
foreach ( [ 'wp_insert_post(', 'wp_update_post(', 'wp_delete_post(', 'wc_create_order(', 'update_user_meta(', 'update_post_meta(', 'download_url(' ] as $forbidden ) {
	if ( false !== strpos( (string) $export_source, $forbidden ) ) {
		fwrite( STDERR, "Read-only export service contains forbidden mutation call: {$forbidden}\n" );
		exit( 1 );
	}
}
foreach ( [ '$menu_data', "\$menu_query['data']", 'generatedAt, which is intentionally volatile' ] as $required ) {
	if ( false === strpos( (string) $export_source, $required ) ) {
		fwrite( STDERR, "Revision hashing is missing stable menu-data evidence: {$required}\n" );
		exit( 1 );
	}
}
if ( false !== strpos( (string) $export_source, "'menuHash'     => hash( 'sha256', \$this->json( \$this->data_query->query(" ) ) {
	fwrite( STDERR, "Revision hashing still includes the volatile SiteGraph menu envelope.\n" );
	exit( 1 );
}

$dry = ( new Staging_Seed_Dry_Run_Service() )->evaluate( [ 'manifest' => [ 'packageId' => 'fixture', 'revisionHash' => str_repeat( 'a', 64 ) ], 'resources' => [] ] );
if ( 'ready-for-baseline-and-import-confirmation' !== $dry['status'] || $dry['mutationsPerformed'] ) {
	fwrite( STDERR, "Empty deterministic dry run violated the no-mutation contract.\n" );
	exit( 1 );
}

foreach ( [
	__DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Dry_Run_Service.php',
	__DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Package_Service.php',
] as $read_only_file ) {
	$source = (string) file_get_contents( $read_only_file );
	foreach ( [ 'wp_insert_post(', 'wp_update_post(', 'wp_delete_post(', 'wc_create_order(', 'update_user_meta(', 'update_post_meta(', 'media_handle_sideload(' ] as $forbidden ) {
		if ( false !== strpos( $source, $forbidden ) ) {
			fwrite( STDERR, "Read/dry-run service contains forbidden content mutation call: {$forbidden}\n" );
			exit( 1 );
		}
	}
}

$import_source = (string) file_get_contents( __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Import_Service.php' );
foreach ( [ 'packages->read(', 'snapshots->capture(', 'ledgers->append(', "woocommerce_webhook_should_deliver", 'snapshots->restore(', 'wp_delete_attachment(', 'Customers, users, orders, coupons, credentials, messages, webhooks and' ] as $required ) {
	if ( false === strpos( $import_source, $required ) ) {
		fwrite( STDERR, "Controlled import is missing required safety evidence: {$required}\n" );
		exit( 1 );
	}
}
foreach ( [ 'reconcile_public_records(', "'pageAuthority'", "'woocommerce_shop_page_id'", "'woocommerce_cart_page_id'", "'woocommerce_checkout_page_id'", "'woocommerce_myaccount_page_id'" ] as $required ) {
	$haystack = "'pageAuthority'" === $required ? $export_source : $import_source;
	if ( false === strpos( $haystack, $required ) ) {
		fwrite( STDERR, "Clean staging reconciliation is missing authority evidence: {$required}\n" );
		exit( 1 );
	}
}
$package_source = (string) file_get_contents( __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Package_Service.php' );
foreach ( [ 'kiwe.staging-seed-package.v2', 'retire_legacy_packages(', "'sourceKiweVersion'", "'cleanReconciliation'" ] as $required ) {
	if ( false === strpos( $package_source, $required ) ) {
		fwrite( STDERR, "Package compatibility gate is missing evidence: {$required}\n" );
		exit( 1 );
	}
}
$connection_source = (string) file_get_contents( __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Connection_Service.php' );
foreach ( [ "'_kiweTransfer'", "'Cache-Control' => 'no-cache, no-store, max-age=0'" ] as $required ) {
	if ( false === strpos( $connection_source, $required ) ) {
		fwrite( STDERR, "Staging transfer cache bypass is missing evidence: {$required}\n" );
		exit( 1 );
	}
}
$admin_source = (string) file_get_contents( __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Admin/Admin.php' );
foreach ( [ 'Copy public site data to staging', 'Connect and calculate changes', 'Imported and ready for review', 'Accept import and finish', 'Advanced: import and rollback history', 'Open baseline controls' ] as $required ) {
	if ( false === strpos( $admin_source, $required ) ) {
		fwrite( STDERR, "Guided staging migration UI is missing evidence: {$required}\n" );
		exit( 1 );
	}
}
if ( 1 !== substr_count( $admin_source, 'name="sourceApplicationPassword"' ) ) {
	fwrite( STDERR, "The normal staging flow must request the temporary source credential exactly once.\n" );
	exit( 1 );
}
foreach ( [ 'wp_insert_user(', 'wp_update_user(', 'update_user_meta(', 'wc_create_order(', 'wp_mail(', 'WC_Webhook' ] as $forbidden ) {
	if ( false !== strpos( $import_source, $forbidden ) ) {
		fwrite( STDERR, "Controlled import crosses the identity/order/message boundary: {$forbidden}\n" );
		exit( 1 );
	}
}

$ledger_source = (string) file_get_contents( __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Import_Ledger_Service.php' );
if ( false === strpos( $ledger_source, "'credentialsStored' => false" ) || false === strpos( $ledger_source, "'termrefs'" ) ) {
	fwrite( STDERR, "Import ledger does not prove its credential-free rollback inventory.\n" );
	exit( 1 );
}

fwrite( STDOUT, "Staging Seed contract verified: read lanes remain mutation-free; import is baseline-gated, ledgered and excludes identities, orders, messages and credentials.\n" );
