<?php

namespace DSA\Site_Graph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Target-local, credential-free audit ledger for SiteGraph staging seeds. */
final class Staging_Seed_Ledger_Service {
	private const OPTION = 'dsa_sitegraph_staging_seed_ledgers_v1';
	private const SCHEMA = 'kiwe.staging-seed-ledger.v1';
	private const MAX_RECORDS = 12;

	public function record_preflight( array $manifest, array $preflight ): array {
		$id = strtolower( wp_generate_password( 24, false, false ) );
		$record = [
			'schema'       => self::SCHEMA,
			'id'           => $id,
			'state'        => 'preflight-only',
			'createdAt'    => gmdate( 'c' ),
			'createdBy'    => get_current_user_id(),
			'source'       => [
				'origin'       => esc_url_raw( (string) ( $manifest['source']['origin'] ?? '' ) ),
				'originHash'   => sanitize_text_field( (string) ( $manifest['source']['originHash'] ?? '' ) ),
				'packageId'    => sanitize_text_field( (string) ( $manifest['packageId'] ?? '' ) ),
				'revisionHash' => sanitize_text_field( (string) ( $manifest['revisionHash'] ?? '' ) ),
			],
			'destination'  => [
				'origin'     => esc_url_raw( home_url( '/' ) ),
				'originHash' => hash( 'sha256', strtolower( untrailingslashit( home_url( '/' ) ) ) ),
			],
			'resourceCounts' => $preflight['resourceCounts'] ?? [],
			'blockers'       => $preflight['blockers'] ?? [],
			'warnings'       => $preflight['warnings'] ?? [],
			'rollbackContract' => [
				'requiredBeforeImport' => true,
				'baselineSnapshot'     => 'kiwe.test-site-snapshot.v1',
				'importedEntityLedger' => 'required',
				'mediaFiles'           => 'delete-only-when-created-by-this-transfer-and-unreferenced',
				'ordersCustomersUsers' => 'never-touched',
			],
			'credentialsStored' => false,
		];

		$records = get_option( self::OPTION, [] );
		$records = is_array( $records ) ? $records : [];
		array_unshift( $records, $record );
		update_option( self::OPTION, array_slice( $records, 0, self::MAX_RECORDS ), false );

		return $record;
	}

	public function records(): array {
		$records = get_option( self::OPTION, [] );
		return is_array( $records ) ? array_values( array_filter( $records, 'is_array' ) ) : [];
	}
}
