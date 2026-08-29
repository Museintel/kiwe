<?php

namespace DSA\Site_Graph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates a remote seed manifest without importing or mutating site data. */
final class Staging_Seed_Preflight_Service {
	public function evaluate( array $manifest ): array {
		$blockers = [];
		$warnings = [];
		$source   = strtolower( untrailingslashit( esc_url_raw( (string) ( $manifest['source']['origin'] ?? '' ) ) ) );
		$target   = strtolower( untrailingslashit( esc_url_raw( home_url( '/' ) ) ) );
		$boundary = is_array( $manifest['preservationBoundary'] ?? null ) ? $manifest['preservationBoundary'] : [];

		if ( Staging_Seed_Export_Service::SCHEMA !== ( $manifest['schema'] ?? '' ) ) {
			$blockers[] = 'unsupported_manifest_schema';
		}
		if ( '' === $source || ! str_starts_with( $source, 'https://' ) ) {
			$blockers[] = 'source_must_use_https';
		}
		if ( '' !== $source && hash_equals( $source, $target ) ) {
			$blockers[] = 'source_and_destination_are_same_site';
		}
		foreach ( [ 'usersExcluded', 'customersExcluded', 'ordersExcluded', 'credentialsExcluded', 'paymentDataExcluded', 'sessionsExcluded', 'webhooksExcluded', 'downloadFilesExcluded' ] as $required ) {
			if ( empty( $boundary[ $required ] ) ) {
				$blockers[] = 'unsafe_boundary_' . sanitize_key( $required );
			}
		}
		if ( empty( $manifest['authority']['readOnly'] ) || ! empty( $manifest['authority']['mutationAuthority'] ) ) {
			$blockers[] = 'source_export_not_read_only';
		}
		if ( ! class_exists( 'WooCommerce' ) && absint( $manifest['resources']['products']['count'] ?? 0 ) > 0 ) {
			$blockers[] = 'woocommerce_missing_on_destination';
		}
		if ( function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() ) {
			$warnings[] = 'destination_reports_production_environment';
		}
		if ( false === get_option( 'blog_public', false ) || '0' !== (string) get_option( 'blog_public', '1' ) ) {
			$warnings[] = 'destination_search_indexing_is_not_disabled';
		}

		$counts = [];
		foreach ( (array) ( $manifest['resources'] ?? [] ) as $name => $resource ) {
			$counts[ sanitize_key( (string) $name ) ] = absint( is_array( $resource ) ? ( $resource['count'] ?? 0 ) : 0 );
		}

		return [
			'schema'         => 'kiwe.staging-seed-preflight.v1',
			'generatedAt'    => gmdate( 'c' ),
			'status'         => [] === $blockers ? 'ready-for-human-reviewed-import' : 'blocked',
			'sourceOrigin'   => $source,
			'destinationOrigin' => $target,
			'packageId'      => sanitize_text_field( (string) ( $manifest['packageId'] ?? '' ) ),
			'revisionHash'   => sanitize_text_field( (string) ( $manifest['revisionHash'] ?? '' ) ),
			'resourceCounts' => $counts,
			'blockers'       => array_values( array_unique( $blockers ) ),
			'warnings'       => array_values( array_unique( $warnings ) ),
			'nextGates'      => [
				'captureTargetBaseline',
				'disableOutboundMessagesWebhooksAndPayments',
				'pullAndVerifyEveryResourceChunk',
				'presentCreateUpdateSkipDiff',
				'requireExplicitImportConfirmation',
			],
			'contentMutated'    => false,
			'credentialsStored' => false,
		];
	}
}
