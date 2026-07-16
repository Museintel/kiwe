<?php

namespace DSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Environment {
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
