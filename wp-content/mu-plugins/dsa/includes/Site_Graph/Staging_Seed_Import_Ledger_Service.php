<?php

namespace DSA\Site_Graph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Crash-resilient, credential-free mutation ledger for one staging import. */
final class Staging_Seed_Import_Ledger_Service {
	private const OPTION = 'dsa_sitegraph_staging_seed_import_ledgers_v1';
	private const SCHEMA = 'kiwe.staging-seed-import-ledger.v1';
	private const MAX_RECORDS = 6;

	public function begin( string $package_record_id, array $package ): array {
		foreach ( $this->records() as $record ) {
			if ( in_array( (string) ( $record['state'] ?? '' ), [ 'running', 'complete', 'failed' ], true ) && empty( $record['closedAt'] ) ) {
				throw new \RuntimeException( 'An earlier staging import still owns the rollback baseline. Roll it back or accept it before starting another import.' );
			}
		}
		$record = [
			'schema'       => self::SCHEMA,
			'id'           => strtolower( wp_generate_password( 24, false, false ) ),
			'state'        => 'running',
			'createdAt'    => gmdate( 'c' ),
			'createdBy'    => get_current_user_id(),
			'packageRecordId' => sanitize_key( $package_record_id ),
			'packageId'    => sanitize_text_field( (string) ( $package['manifest']['packageId'] ?? '' ) ),
			'revisionHash' => sanitize_text_field( (string) ( $package['manifest']['revisionHash'] ?? '' ) ),
			'sourceOrigin' => esc_url_raw( (string) ( $package['manifest']['source']['origin'] ?? '' ) ),
			'created'      => [ 'posts' => [], 'media' => [], 'terms' => [], 'termrefs' => [], 'menus' => [], 'attributes' => [] ],
			'updated'      => [ 'posts' => [] ],
			'deleted'      => [ 'posts' => [] ],
			'reused'       => [ 'media' => [], 'terms' => [], 'menus' => [] ],
			'warnings'     => [],
			'error'        => '',
			'credentialsStored' => false,
		];
		$records = $this->records();
		array_unshift( $records, $record );
		$this->save( array_slice( $records, 0, self::MAX_RECORDS ) );
		return $record;
	}

	public function append( string $id, string $bucket, string $type, int $object_id ): void {
		$this->mutate(
			$id,
			static function ( array $record ) use ( $bucket, $type, $object_id ): array {
				$bucket = in_array( $bucket, [ 'created', 'updated', 'reused', 'deleted' ], true ) ? $bucket : 'created';
				$type   = sanitize_key( $type );
				if ( $object_id > 0 && isset( $record[ $bucket ][ $type ] ) && is_array( $record[ $bucket ][ $type ] ) ) {
					$record[ $bucket ][ $type ][] = $object_id;
					$record[ $bucket ][ $type ] = array_values( array_unique( array_map( 'absint', $record[ $bucket ][ $type ] ) ) );
				}
				return $record;
			}
		);
	}

	public function warning( string $id, string $warning ): void {
		$this->mutate(
			$id,
			static function ( array $record ) use ( $warning ): array {
				$record['warnings'][] = sanitize_text_field( $warning );
				$record['warnings'] = array_values( array_unique( array_filter( $record['warnings'] ) ) );
				return $record;
			}
		);
	}

	public function append_reference( string $id, string $bucket, string $type, array $reference ): void {
		$this->mutate(
			$id,
			static function ( array $record ) use ( $bucket, $type, $reference ): array {
				$bucket = in_array( $bucket, [ 'created', 'updated', 'reused', 'deleted' ], true ) ? $bucket : 'created';
				$type = sanitize_key( $type );
				if ( isset( $record[ $bucket ][ $type ] ) && is_array( $record[ $bucket ][ $type ] ) ) $record[ $bucket ][ $type ][] = $reference;
				return $record;
			}
		);
	}

	public function complete( string $id ): array {
		return $this->state( $id, 'complete', '' );
	}

	public function fail( string $id, string $message ): array {
		return $this->state( $id, 'failed', $message );
	}

	public function close( string $id, string $state ): array {
		$state = in_array( $state, [ 'rolled-back', 'accepted' ], true ) ? $state : 'accepted';
		return $this->mutate(
			$id,
			static function ( array $record ) use ( $state ): array {
				$record['state'] = $state;
				$record['closedAt'] = gmdate( 'c' );
				return $record;
			}
		);
	}

	public function find( string $id ): array {
		$id = sanitize_key( $id );
		foreach ( $this->records() as $record ) {
			if ( hash_equals( (string) ( $record['id'] ?? '' ), $id ) ) return $record;
		}
		throw new \RuntimeException( 'The staging import ledger was not found.' );
	}

	public function records(): array {
		$records = get_option( self::OPTION, [] );
		return is_array( $records ) ? array_values( array_filter( $records, 'is_array' ) ) : [];
	}

	private function state( string $id, string $state, string $error ): array {
		return $this->mutate(
			$id,
			static function ( array $record ) use ( $state, $error ): array {
				$record['state'] = $state;
				$record[ $state . 'At' ] = gmdate( 'c' );
				$record['error'] = sanitize_text_field( $error );
				return $record;
			}
		);
	}

	private function mutate( string $id, callable $callback ): array {
		$id = sanitize_key( $id );
		$records = $this->records();
		foreach ( $records as $index => $record ) {
			if ( ! hash_equals( (string) ( $record['id'] ?? '' ), $id ) ) continue;
			$records[ $index ] = $callback( $record );
			$this->save( $records );
			return $records[ $index ];
		}
		throw new \RuntimeException( 'The staging import ledger was not found.' );
	}

	private function save( array $records ): void {
		update_option( self::OPTION, $records, false );
	}
}
