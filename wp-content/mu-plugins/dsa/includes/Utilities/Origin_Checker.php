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

		$allowed_hosts = [];
		$site_host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( is_string( $site_host ) && '' !== $site_host ) {
			$allowed_hosts[] = strtolower( $site_host );
		}
		$request_host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		$request_host = strtolower( preg_replace( '/:\d+$/', '', $request_host ) );
		if ( '' !== $request_host ) {
			$allowed_hosts[] = $request_host;
		}
		$allowed_hosts = array_values( array_unique( array_filter( $allowed_hosts ) ) );

		foreach ( [ 'HTTP_ORIGIN', 'HTTP_REFERER' ] as $header ) {
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ?? '' ) );

			if ( '' === $value ) {
				continue;
			}

			$host = wp_parse_url( $value, PHP_URL_HOST );
			$host = is_string( $host ) ? strtolower( $host ) : '';

			if ( '' !== $host && [] !== $allowed_hosts && ! in_array( $host, $allowed_hosts, true ) ) {
				return false;
			}
		}

		return true;
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
