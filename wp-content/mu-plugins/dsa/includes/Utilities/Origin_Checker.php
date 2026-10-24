<?php

namespace DSA\Utilities;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Origin_Checker {
	private const MUTATION_HEADER = 'x-kiwe-mutation';

	public static function is_same_site_request(): bool {
		$fetch_site = sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) );

		if ( 'cross-site' === $fetch_site ) {
			return false;
		}

		// WordPress may live in a subdirectory or use a different configured admin
		// origin. Request Host is not configuration and must not expand this list.
		$allowed_origins = array_values( array_unique( array_filter( [
			self::url_origin( home_url( '/' ) ),
			self::url_origin( site_url( '/' ) ),
		] ) ) );

		foreach ( [ 'HTTP_ORIGIN', 'HTTP_REFERER' ] as $header ) {
			if ( ! isset( $_SERVER[ $header ] ) ) {
				continue;
			}

			$value = wp_unslash( $_SERVER[ $header ] );
			// An explicit opaque/malformed Origin is not an absent header. Compare
			// scheme, host and effective port without sanitizing invalid URLs into
			// acceptable ones. Origin is an authority; Referer may include a path.
			if ( ! is_string( $value ) || ( 'HTTP_ORIGIN' === $header && ! preg_match( '#\Ahttps?://(?:\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::[0-9]+)?\z#i', $value ) ) ) {
				return false;
			}
			$origin = self::url_origin( $value );
			if ( '' === $origin || ! in_array( $origin, $allowed_origins, true ) ) {
				return false;
			}
		}

		return true;
	}

	private static function url_origin( string $url ): string {
		if ( preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] ?? '' );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return '';
		}
		$port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
	}

	/**
	 * State changes need an explicit non-simple request header. Cross-origin
	 * forms cannot send it and cross-origin fetches must pass a CORS preflight.
	 */
	public static function mutation_allowed( WP_REST_Request $request ) {
		if ( ! self::is_same_site_request() ) {
			return new WP_Error( 'dsa_cross_site_mutation', __( 'Cross-site state changes are not allowed.', 'dsa' ), [ 'status' => 403 ] );
		}

		if ( '1' !== trim( (string) $request->get_header( self::MUTATION_HEADER ) ) ) {
			return new WP_Error( 'dsa_mutation_proof_missing', __( 'This state change is missing Kiwe request proof. Refresh and try again.', 'dsa' ), [ 'status' => 403 ] );
		}

		return true;
	}

	public static function client_ip(): string {
		if ( function_exists( 'pk_ip' ) ) {
			return (string) \pk_ip();
		}
		if ( function_exists( 'stp_get_ip' ) ) {
			return (string) \stp_get_ip();
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	}

	public static function transient_rate_limit( string $prefix, int $max_per_minute ): bool {
		return Atomic_Rate_Limiter::allow( sanitize_key( $prefix ) . '|' . self::client_ip(), $max_per_minute, MINUTE_IN_SECONDS );
	}
}
