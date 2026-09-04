<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small, fail-safe controls that remain available even when the main Kiwe
 * package is disabled. The direct MU loader registers the guard independently
 * from the full application so upload and incident-response requests do not
 * need to construct every Kiwe service.
 */
final class Incident_Response_Service {
	private static bool $registered = false;

	public static function register_early(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;
		add_action( 'template_redirect', [ self::class, 'mark_spam_404_gone' ], 0 );
		add_filter( 'redirect_canonical', [ self::class, 'disable_spam_404_redirect' ], 10, 2 );
		add_filter( 'wp_robots', [ self::class, 'filter_robots' ], 99 );
		add_filter( 'rank_math/json_ld', [ self::class, 'filter_rank_math_schema' ], PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_structured_data_product', [ self::class, 'filter_woocommerce_product_schema' ], PHP_INT_MAX, 2 );
		add_filter( 'rest_endpoints', [ self::class, 'filter_rest_endpoints' ], PHP_INT_MAX );
		add_action( 'send_headers', [ self::class, 'send_security_headers' ], 99 );
		add_filter( 'big_image_size_threshold', [ self::class, 'big_image_threshold' ], 99, 4 );
		add_filter( 'wp_handle_upload_prefilter', [ self::class, 'validate_image_upload' ], 5 );
	}

	public static function is_lean_media_request(): bool {
		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
		if ( preg_match( '#/wp-admin/(?:async-upload|media-new)\.php$#i', $path ) ) {
			return true;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) );
		return in_array( $action, [ 'upload-attachment', 'media-create-image-subsizes', 'image-editor' ], true );
	}

	public static function mark_spam_404_gone(): void {
		if ( ! self::enabled( 'seo_spam_410_enabled' ) || ! self::is_spam_404_request() ) {
			return;
		}

		status_header( 410 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, nosnippet', true );
		header( 'X-Kiwe-Incident-Guard: spam-route-gone', true );
	}

	public static function disable_spam_404_redirect( $redirect_url, $requested_url ) {
		return self::enabled( 'seo_spam_410_enabled' ) && self::is_spam_404_request() ? false : $redirect_url;
	}

	public static function filter_robots( array $robots ): array {
		if ( self::enabled( 'seo_spam_410_enabled' ) && self::is_spam_404_request() ) {
			$robots['noindex']   = true;
			$robots['nofollow']  = true;
			$robots['nosnippet'] = true;
		}

		return $robots;
	}

	public static function filter_rank_math_schema( $data, $jsonld = null ) {
		if ( ! self::enabled( 'schema_integrity_guard_enabled' ) || self::is_real_product_request() || ! is_array( $data ) ) {
			return $data;
		}

		return self::remove_product_nodes( $data );
	}

	public static function filter_woocommerce_product_schema( $markup, $product = null ) {
		if ( self::enabled( 'schema_integrity_guard_enabled' ) && ! self::is_real_product_request() ) {
			return [];
		}

		return $markup;
	}

	public static function filter_rest_endpoints( array $endpoints ): array {
		if ( ! self::enabled( 'harden_public_rest_users' ) || is_user_logged_in() ) {
			return $endpoints;
		}

		foreach ( array_keys( $endpoints ) as $route ) {
			if ( preg_match( '#^/wp/v2/users(?:/|$)#', (string) $route ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}

	public static function send_security_headers(): void {
		if ( ! self::enabled( 'security_headers_enabled' ) || headers_sent() ) {
			return;
		}

		header_remove( 'X-Powered-By' );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'Referrer-Policy: strict-origin-when-cross-origin', true );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()', true );
		header( 'X-Frame-Options: SAMEORIGIN', true );
	}

	public static function big_image_threshold( $threshold, $imagesize = null, $file = null, $attachment_id = null ) {
		if ( ! self::enabled( 'image_resource_guard_enabled' ) ) {
			return $threshold;
		}

		$configured = max( 1600, min( 4096, absint( self::config()['image_big_size_threshold'] ?? 2560 ) ) );
		if ( false === $threshold || ! is_numeric( $threshold ) ) {
			return $configured;
		}

		return min( (int) $threshold, $configured );
	}

	public static function validate_image_upload( array $file ): array {
		if ( ! self::enabled( 'image_resource_guard_enabled' ) || ! empty( $file['error'] ) ) {
			return $file;
		}

		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$type = isset( $file['type'] ) ? sanitize_mime_type( (string) $file['type'] ) : '';
		if ( '' === $tmp || ! is_readable( $tmp ) || ! str_starts_with( $type, 'image/' ) ) {
			return $file;
		}

		$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $tmp ) : @getimagesize( $tmp );
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return $file;
		}

		$config = self::config();
		$width  = absint( $size[0] );
		$height = absint( $size[1] );
		$max_dimension = max( 4096, min( 20000, absint( $config['image_max_dimension'] ?? 12000 ) ) );
		$max_megapixels = max( 16, min( 120, absint( $config['image_max_megapixels'] ?? 40 ) ) );
		$pixels = $width * $height;

		if ( $width > $max_dimension || $height > $max_dimension || $pixels > ( $max_megapixels * 1000000 ) ) {
			$file['error'] = sprintf(
				/* translators: 1: width, 2: height, 3: maximum dimension, 4: maximum megapixels */
				__( 'Kiwe stopped this image before resource-intensive processing. The file is %1$d×%2$d px; resize it below %3$d px per side and %4$d megapixels, then upload again.', 'dsa' ),
				$width,
				$height,
				$max_dimension,
				$max_megapixels
			);
		}

		return $file;
	}

	public static function is_spam_404_path( string $path ): bool {
		$path = rawurldecode( (string) wp_parse_url( $path, PHP_URL_PATH ) );
		return (bool) preg_match( '#^/\d{4,9}/[\p{L}\p{N}][\p{L}\p{N}-]{8,}/?$#u', $path );
	}

	private static function is_spam_404_request(): bool {
		if ( is_admin() || ! is_404() || ! in_array( strtoupper( sanitize_text_field( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), [ 'GET', 'HEAD' ], true ) ) {
			return false;
		}

		return self::is_spam_404_path( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
	}

	private static function is_real_product_request(): bool {
		return function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_product' ) && (bool) wc_get_product( get_queried_object_id() );
	}

	private static function remove_product_nodes( array $value ): array {
		if ( self::has_product_type( $value['@type'] ?? null ) ) {
			return [];
		}

		$was_list = array_is_list( $value );
		foreach ( $value as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$filtered = self::remove_product_nodes( $item );
			if ( [] === $filtered && self::has_product_type( $item['@type'] ?? null ) ) {
				unset( $value[ $key ] );
				continue;
			}
			$value[ $key ] = $filtered;
		}

		return $was_list ? array_values( $value ) : $value;
	}

	private static function has_product_type( $type ): bool {
		$types = is_array( $type ) ? $type : [ $type ];
		foreach ( $types as $candidate ) {
			if ( in_array( strtolower( (string) $candidate ), [ 'product', 'productgroup' ], true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function enabled( string $key ): bool {
		return ! empty( self::config()[ $key ] );
	}

	private static function config(): array {
		$settings = get_option( defined( 'DSA_OPTION_SETTINGS' ) ? DSA_OPTION_SETTINGS : 'dsa_settings', [] );
		return is_array( $settings ) && isset( $settings['secure'] ) && is_array( $settings['secure'] ) ? $settings['secure'] : [];
	}
}
