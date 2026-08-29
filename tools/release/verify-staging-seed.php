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

fwrite( STDOUT, "Staging Seed contract verified: safe manifest accepted, unsafe manifest blocked, pull/dry-run remain content-mutation-free.\n" );
