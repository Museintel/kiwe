<?php

namespace DSA\Performance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded, content-addressed removal metadata. Never stores CSS, HTML or visitor state. */
final class CSS_Reduction_Cache {
	private const PREFIX = '_kiwe_css_plan_v1_';
	private const MAX_ENTRY = 65536;
	private const MAX_SPANS = 4096;
	private const VERSION = 1;

	public static function reduce( string $css ): string {
		global $wpdb;
		$size = strlen( $css );
		if ( $size < 524288 || $size > 4194304 || ! $wpdb instanceof \wpdb ) { return $css; }
		try {
			$hash = hash( 'sha256', $css );
			$name = self::PREFIX . sprintf( '%02d', hexdec( substr( $hash, 0, 2 ) ) % 32 );
			$raw = get_option( $name, false );
			if ( false !== $raw && ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_ENTRY ) ) { return $css; }
			$entry = self::decode( $raw ); $now = time();
			if ( $entry && $entry['born'] <= $now && $entry['until'] > $now ) {
				if ( 'ready' === $entry['state'] && $entry['hash'] === $hash && $entry['bytes'] === $size ) {
					return self::apply( $css, $entry['spans'] ) ?? $css;
				}
				// One owner learns a slot at a time; avoid churn when different pages share a slot.
				if ( 'working' === $entry['state'] || $entry['born'] > $now - 60 ) { return $css; }
			}
			$claim = self::encode( [
				'v' => self::VERSION, 'state' => 'working', 'hash' => $hash, 'bytes' => $size,
				'born' => $now, 'until' => $now + 30, 'owner' => bin2hex( random_bytes( 16 ) ), 'spans' => [],
			] );
			if ( ! $claim || ! self::exchange( $name, $raw, $claim ) ) { return $css; }
			$plan = CSS_Rule_Reducer::removals( $css );
			$spans = [];
			if ( is_array( $plan ) && count( $plan ) <= self::MAX_SPANS && array_sum( $plan ) >= 8192 ) {
				foreach ( $plan as $at => $length ) { $spans[] = [ $at, $length ]; }
			}
			$ready = self::encode( [
				'v' => self::VERSION, 'state' => 'ready', 'hash' => $hash, 'bytes' => $size,
				'born' => $now, 'until' => $now + 21600, 'owner' => '', 'spans' => $spans,
			] );
			if ( ! $ready ) {
				$spans = [];
				$ready = self::encode( [ 'v' => self::VERSION, 'state' => 'ready', 'hash' => $hash, 'bytes' => $size,
					'born' => $now, 'until' => $now + 21600, 'owner' => '', 'spans' => [] ] );
			}
			// An expired worker can never overwrite a newer owner's result.
			if ( $ready ) { self::exchange( $name, $claim, $ready ); }
			return self::apply( $css, $spans ) ?? $css;
		} catch ( \Throwable $error ) {
			return $css;
		}
	}

	/** Validate the whole plan before slicing: a partial application is never returned. */
	private static function apply( string $css, $spans ): ?string {
		if ( ! is_array( $spans ) || ! array_is_list( $spans ) || count( $spans ) > self::MAX_SPANS ) { return null; }
		$end = 0; $size = strlen( $css ); $removed = 0;
		foreach ( $spans as $span ) {
			if ( ! is_array( $span ) || ! array_is_list( $span ) || count( $span ) !== 2
				|| ! is_int( $span[0] ) || ! is_int( $span[1] ) || $span[0] < $end || $span[1] < 1
				|| $span[0] > $size || $span[1] > $size - $span[0] ) { return null; }
			$end = $span[0] + $span[1]; $removed += $span[1];
		}
		if ( ! $spans || $removed < 8192 ) { return $css; }
		$parts = []; $end = 0;
		foreach ( $spans as [ $at, $length ] ) { $parts[] = substr( $css, $end, $at - $end ); $end = $at + $length; }
		$parts[] = substr( $css, $end );
		return implode( '', $parts );
	}

	private static function encode( array $entry ): ?string {
		$data = json_encode( $entry );
		if ( false === $data ) { return null; }
		$raw = json_encode( [ 'data' => $data, 'mac' => hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ) ] );
		return is_string( $raw ) && strlen( $raw ) <= self::MAX_ENTRY ? $raw : null;
	}

	private static function decode( $raw ): ?array {
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_ENTRY ) { return null; }
		$outer = json_decode( $raw, true, 4 );
		if ( ! is_array( $outer ) || ! is_string( $outer['data'] ?? null ) || ! is_string( $outer['mac'] ?? null )
			|| ! hash_equals( hash_hmac( 'sha256', $outer['data'], wp_salt( 'auth' ) ), $outer['mac'] ) ) { return null; }
		$entry = json_decode( $outer['data'], true, 8 );
		if ( ! is_array( $entry ) || self::VERSION !== ( $entry['v'] ?? null )
			|| ! in_array( $entry['state'] ?? null, [ 'ready', 'working' ], true )
			|| ! is_string( $entry['hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $entry['hash'] )
			|| ! is_int( $entry['bytes'] ?? null ) || $entry['bytes'] < 524288 || $entry['bytes'] > 4194304
			|| ! is_int( $entry['born'] ?? null ) || ! is_int( $entry['until'] ?? null )
			|| $entry['until'] <= $entry['born'] || $entry['until'] - $entry['born'] > 21600
			|| ! is_array( $entry['spans'] ?? null ) || count( $entry['spans'] ) > self::MAX_SPANS
			|| ! is_string( $entry['owner'] ?? null ) ) { return null; }
		return $entry;
	}

	/** Native options own reads/cache. Exact-value CAS supplies the nonblocking worker lease. */
	private static function exchange( string $name, $before, string $after ): bool {
		if ( false === $before ) { return add_option( $name, $after, '', false ); }
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
			$after, $name, $before
		) );
		if ( 1 === $changed ) { wp_cache_delete( $name, 'options' ); }
		return 1 === $changed;
	}
}
