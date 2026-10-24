<?php

namespace DSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Environment {
	/** Presentation request only: never authenticates an account or changes feature settings. */
	public static function is_auth_surface_request(): bool {
		return isset( $_GET['kiwe-auth'] ) && is_string( $_GET['kiwe-auth'] ) && '1' === $_GET['kiwe-auth']
			&& in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', [ 'GET', 'HEAD' ], true );
	}

	/** An explicit account entry needs the Profile sheet even in clean-conversion mode. */
	public static function is_profile_surface_request(): bool {
		return isset( $_GET['kiwe-screen'] ) && is_string( $_GET['kiwe-screen'] ) && 'profile' === $_GET['kiwe-screen']
			&& in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', [ 'GET', 'HEAD' ], true );
	}

	public static function is_private_surface_request(): bool {
		return self::is_auth_surface_request() || self::is_profile_surface_request();
	}

	public static function allows_surface_registration( array $settings ): bool {
		$diagnostics = is_array( $settings['diagnostics'] ?? null ) ? $settings['diagnostics'] : [];
		if ( empty( $diagnostics['raw_convert_test_mode'] ) || ! empty( $diagnostics['accessibility_preview_mode'] ) ) return true;
		// Clean conversion pages stay bare; an explicit login still needs its existing screen and assets.
		return ! empty( $settings['enabled'] ) && ! empty( $settings['phonekey']['enabled'] ) && self::is_private_surface_request();
	}

	public static function should_render_frontend(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || self::is_non_document_request() ) {
			return false;
		}

		if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
			return false;
		}

		if ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() ) {
			return false;
		}

		return (bool) apply_filters( 'dsa_should_render_frontend', true );
	}

	private static function is_non_document_request(): bool {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return true;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return true;
		}

		foreach ( [ 'is_feed', 'is_robots', 'is_trackback', 'is_embed', 'is_preview', 'is_favicon' ] as $conditional ) {
			if ( function_exists( $conditional ) && $conditional() ) {
				return true;
			}
		}

		return function_exists( 'is_customize_preview' ) && is_customize_preview();
	}
}
