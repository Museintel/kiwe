<?php

namespace DSA\Site_Graph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pulls and hash-verifies a complete read-only staging package. */
final class Staging_Seed_Package_Service {
	private const OPTION = 'dsa_sitegraph_staging_seed_packages_v2';
	private const LEGACY_OPTION = 'dsa_sitegraph_staging_seed_packages_v1';
	private const SCHEMA = 'kiwe.staging-seed-package.v2';
	private const MAX_RECORDS = 6;
	private const MAX_TOTAL_ROWS = 50000;
	private const MAX_CALLS = 600;

	public function pull( string $source_url, string $username, string $application_password ): array {
		$connection = new Staging_Seed_Connection_Service();
		$manifest   = $connection->fetch_manifest( $source_url, $username, $application_password );
		$preflight  = ( new Staging_Seed_Preflight_Service() )->evaluate( $manifest );
		if ( ! empty( $preflight['blockers'] ) ) {
			throw new \RuntimeException( 'The source manifest failed preflight: ' . implode( ', ', (array) $preflight['blockers'] ) );
		}
		$total = 0;
		foreach ( (array) ( $manifest['resources'] ?? [] ) as $resource ) {
			$total += absint( is_array( $resource ) ? ( $resource['count'] ?? 0 ) : 0 );
		}
		if ( $total > self::MAX_TOTAL_ROWS ) {
			throw new \RuntimeException( 'The source contains more than the 50,000-record RC transfer budget.' );
		}

		$resources = [];
		$calls = 0;
		foreach ( [ 'site', 'designcontext', 'menus', 'content', 'products', 'terms', 'media' ] as $resource ) {
			$resources[ $this->resource_key( $resource ) ] = [];
			$page = 1;
			do {
				if ( ++$calls > self::MAX_CALLS ) {
					throw new \RuntimeException( 'The transfer exceeded the bounded request budget.' );
				}
				$payload = $connection->fetch_resource( $source_url, $username, $application_password, $resource, [ 'page' => $page, 'perPage' => 100 ] );
				if ( isset( $payload['error'] ) ) {
					throw new \RuntimeException( 'The source rejected resource ' . $resource . ': ' . sanitize_key( (string) $payload['error'] ) );
				}
				$data = $payload['data'] ?? [];
				$key  = $this->resource_key( $resource );
				if ( in_array( $resource, [ 'site', 'designcontext' ], true ) ) {
					$resources[ $key ] = is_array( $data ) ? $data : [];
				} elseif ( is_array( $data ) ) {
					$resources[ $key ] = array_merge( $resources[ $key ], array_values( $data ) );
				}
				$total_pages = max( 1, absint( $payload['pageInfo']['totalPages'] ?? 1 ) );
				++$page;
			} while ( $page <= $total_pages );
		}

		$final_manifest = $connection->fetch_manifest( $source_url, $username, $application_password );
		unset( $application_password );
		if ( ! hash_equals( (string) ( $manifest['revisionHash'] ?? '' ), (string) ( $final_manifest['revisionHash'] ?? '' ) ) ) {
			throw new \RuntimeException( 'The source changed while Kiwe was reading it. Run the pull again from a fresh revision.' );
		}
		if ( empty( $resources['site']['pageAuthority'] ) ) {
			$source_version = sanitize_text_field( (string) ( $manifest['source']['kiwe'] ?? 'unknown' ) );
			throw new \RuntimeException( 'The source returned an incomplete clean-reconciliation package (Kiwe ' . $source_version . '). Update the complete canonical Kiwe plugin on the source site, then pull once more.' );
		}

		$package = [
			'schema'       => self::SCHEMA,
			'createdAt'    => gmdate( 'c' ),
			'createdBy'    => get_current_user_id(),
			'destination'  => [ 'origin' => esc_url_raw( home_url( '/' ) ), 'originHash' => hash( 'sha256', strtolower( untrailingslashit( home_url( '/' ) ) ) ) ],
			'manifest'     => $manifest,
			'preflight'    => $preflight,
			'resources'    => $resources,
			'calls'        => $calls + 1,
			'credentialsStored' => false,
		];
		$package['hash'] = $this->package_hash( $package );
		$dry_run = ( new Staging_Seed_Dry_Run_Service() )->evaluate( $package );
		$package['dryRun'] = $dry_run;
		$package['hash'] = $this->package_hash( $package );

		$json = wp_json_encode( $package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			throw new \RuntimeException( 'The verified staging package could not be encoded.' );
		}
		if ( strlen( $json ) > 128 * MB_IN_BYTES ) {
			throw new \RuntimeException( 'The verified staging package exceeded the 128 MB RC package limit.' );
		}
		$id = strtolower( wp_generate_password( 24, false, false ) );
		$filename = 'staging-seed-' . sanitize_file_name( (string) ( $manifest['packageId'] ?? 'package' ) ) . '-' . $id . '.json';
		$path = trailingslashit( $this->private_directory() ) . $filename;
		if ( strlen( $json ) !== file_put_contents( $path, $json, LOCK_EX ) ) {
			@unlink( $path );
			throw new \RuntimeException( 'The verified staging package could not be written completely.' );
		}

		$record = [
			'schema'       => self::SCHEMA,
			'id'           => $id,
			'state'        => 'dry-run-ready',
			'createdAt'    => $package['createdAt'],
			'createdBy'    => $package['createdBy'],
			'sourceOrigin' => esc_url_raw( (string) ( $manifest['source']['origin'] ?? '' ) ),
			'sourceKiweVersion' => sanitize_text_field( (string) ( $manifest['source']['kiwe'] ?? '' ) ),
			'capabilities' => [
				'pageAuthority'       => 'v1',
				'cleanReconciliation' => true,
			],
			'packageId'    => sanitize_text_field( (string) ( $manifest['packageId'] ?? '' ) ),
			'revisionHash' => sanitize_text_field( (string) ( $manifest['revisionHash'] ?? '' ) ),
			'file'         => $filename,
			'bytes'        => strlen( $json ),
			'hash'         => $package['hash'],
			'calls'        => $package['calls'],
			'resourceCounts' => array_map( static fn( $data ): int => is_array( $data ) && array_is_list( $data ) ? count( $data ) : ( empty( $data ) ? 0 : 1 ), $resources ),
			'dryRun'       => $dry_run,
			'credentialsStored' => false,
		];
		$this->store_record( $record );
		return $record;
	}

	public function records(): array {
		$this->retire_legacy_packages();
		$records = get_option( self::OPTION, [] );
		return is_array( $records ) ? array_values( array_filter( $records, 'is_array' ) ) : [];
	}

	public function read( string $id ): array {
		$id = sanitize_key( $id );
		foreach ( $this->records() as $record ) {
			if ( ! hash_equals( (string) ( $record['id'] ?? '' ), $id ) ) continue;
			$path = $this->package_path( (string) ( $record['file'] ?? '' ) );
			$json = is_readable( $path ) ? file_get_contents( $path ) : false;
			$package = false !== $json ? json_decode( $json, true ) : null;
			if ( ! is_array( $package ) || self::SCHEMA !== ( $package['schema'] ?? '' ) || ! hash_equals( (string) ( $record['hash'] ?? '' ), $this->package_hash( $package ) ) ) {
				throw new \RuntimeException( 'The staging package is missing or failed integrity verification.' );
			}
			if ( ! hash_equals( (string) ( $package['destination']['originHash'] ?? '' ), hash( 'sha256', strtolower( untrailingslashit( home_url( '/' ) ) ) ) ) ) {
				throw new \RuntimeException( 'The staging package belongs to another destination site.' );
			}
			return $package;
		}
		throw new \RuntimeException( 'The staging package was not found.' );
	}

	private function store_record( array $record ): void {
		$records = $this->records();
		array_unshift( $records, $record );
		$removed = array_slice( $records, self::MAX_RECORDS );
		foreach ( $removed as $old ) {
			$path = $this->package_path( (string) ( $old['file'] ?? '' ) );
			if ( is_file( $path ) ) @unlink( $path );
		}
		update_option( self::OPTION, array_slice( $records, 0, self::MAX_RECORDS ), false );
	}

	/** Retires pre-authority packages so an obsolete row can never be imported by mistake. */
	private function retire_legacy_packages(): void {
		$records = get_option( self::LEGACY_OPTION, [] );
		if ( ! is_array( $records ) || [] === $records ) {
			delete_option( self::LEGACY_OPTION );
			return;
		}
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) continue;
			$path = $this->package_path( (string) ( $record['file'] ?? '' ) );
			if ( is_file( $path ) ) @unlink( $path );
		}
		delete_option( self::LEGACY_OPTION );
	}

	private function resource_key( string $resource ): string {
		return 'designcontext' === $resource ? 'designContext' : sanitize_key( $resource );
	}

	private function private_directory(): string {
		$path = trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . '.kiwe-private';
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			throw new \RuntimeException( 'Kiwe could not create its private staging-package directory.' );
		}
		if ( ! is_writable( $path ) ) {
			throw new \RuntimeException( 'The private staging-package directory is not writable.' );
		}
		return $path;
	}

	private function package_path( string $filename ): string {
		$filename = sanitize_file_name( basename( $filename ) );
		return '' === $filename ? '' : trailingslashit( dirname( untrailingslashit( ABSPATH ) ) . '/.kiwe-private' ) . $filename;
	}

	private function package_hash( array $package ): string {
		unset( $package['hash'] );
		$json = wp_json_encode( $package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash_hmac( 'sha256', false !== $json ? $json : serialize( $package ), wp_salt( 'auth' ) );
	}
}
