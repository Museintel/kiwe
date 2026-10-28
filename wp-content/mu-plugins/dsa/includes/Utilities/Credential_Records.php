<?php

namespace DSA\Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Conditional transitions of the two existing, bounded native credential options. */
final class Credential_Records {
	private const OPTIONS = [ 'dsa_ai_access_keys', 'dsa_sitegraph_task_capsules_v1' ];
	private const ATTEMPTS = 4;
	private const MAX_BYTES = 1048576;

	/** A callback returns value, and records only when a write must be confirmed. */
	public static function change( string $option, callable $transition ): array {
		for ( $attempt = 0; $attempt < self::ATTEMPTS; ++$attempt ) {
			$read = self::read( $option );
			if ( ! $read['ok'] ) return $read;
			$next = $transition( $read['records'] );
			if ( ! is_array( $next ) || ! array_key_exists( 'value', $next ) ) return self::failure();
			if ( ! array_key_exists( 'records', $next ) ) return [ 'ok' => true, 'value' => $next['value'] ];
			if ( ! is_array( $next['records'] ) ) return self::failure();
			$written = self::write( $option, $read, $next['records'] );
			if ( false === $written ) return self::failure();
			if ( 1 === $written ) return [ 'ok' => true, 'value' => $next['value'] ];
			// Reread and revalidate; never merge a stale authorization decision.
		}
		return [ 'ok' => false, 'code' => 'credential_state_busy' ];
	}

	private static function read( string $option ): array {
		global $wpdb;
		if ( ! in_array( $option, self::OPTIONS, true ) || ! isset( $wpdb->options ) ) return self::failure();
		// A cached option cannot arbitrate revocation or a finite request budget.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $option ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) return self::failure();
		if ( null === $row ) return [ 'ok' => true, 'raw' => null, 'autoload' => 'no', 'records' => [] ];
		$raw = $row['option_value'] ?? null;
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BYTES ) return self::failure();
		$records = @unserialize( $raw, [ 'allowed_classes' => false ] );
		if ( ! is_array( $records ) ) return self::failure();
		return [ 'ok' => true, 'raw' => $raw, 'autoload' => (string) ( $row['autoload'] ?? 'yes' ), 'records' => $records ];
	}

	/** @return int|false One committed/current, zero conflict, false storage failure. */
	private static function write( string $option, array $before, array $records ) {
		global $wpdb;
		$next = serialize( $records );
		if ( strlen( $next ) > self::MAX_BYTES ) return false;
		if ( $next === $before['raw'] ) {
			// Same-second key telemetry is a no-op. Confirm it is still current;
			// SQL changed-row counts cannot distinguish that from a lost race.
			$current = self::read( $option );
			return ! $current['ok'] ? false : ( $current['raw'] === $before['raw'] ? 1 : 0 );
		}
		if ( null === $before['raw'] ) {
			$sql = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", $option, $next );
		} else {
			$sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", $next, $option, $before['raw'] );
		}
		$result = $wpdb->query( $sql );
		if ( 1 === $result ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( ! in_array( $before['autoload'], [ 'no', 'off', 'auto-off' ], true ) ) wp_cache_delete( 'alloptions', 'options' );
			return 1;
		}
		return 0 === $result ? 0 : false;
	}

	private static function failure(): array {
		return [ 'ok' => false, 'code' => 'credential_state_unavailable' ];
	}
}
