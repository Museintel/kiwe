<?php

namespace DSA\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Asset_Manifest_Service {
	private bool $enabled;
	private bool $registered = false;

	public function __construct( bool $enabled ) {
		$this->enabled = $enabled;
	}

	public function register(): void {
		if ( ! $this->enabled || $this->registered ) {
			return;
		}

		$this->registered = true;
		add_action( 'wp_print_footer_scripts', [ $this, 'capture_frontend' ], PHP_INT_MAX );
		add_action( 'admin_print_footer_scripts', [ $this, 'capture_admin' ], PHP_INT_MAX );
	}

	public function capture_frontend(): void {
		$this->capture( 'frontend' );
	}

	public function capture_admin(): void {
		$this->capture( 'admin' );
	}

	private function capture( string $surface ): void {
		$context = [
			'surface' => $surface,
			'route'   => $this->route_context(),
			'scripts' => $this->collect( 'script' ),
			'styles'  => $this->collect( 'style' ),
		];

		if ( function_exists( 'kiwe_mu_debug_log' ) ) {
			\kiwe_mu_debug_log( 'Asset ownership manifest', $context );
			return;
		}

		error_log( '[Kiwe asset manifest] ' . wp_json_encode( $context ) );
	}

	private function collect( string $kind ): array {
		$dependencies = 'style' === $kind ? wp_styles() : wp_scripts();

		if ( ! is_object( $dependencies ) || ! isset( $dependencies->registered ) || ! is_array( $dependencies->registered ) ) {
			return [
				'count'      => 0,
				'registered' => 0,
				'items'      => [],
			];
		}

		$handles = array_values(
			array_unique(
				array_merge(
					is_array( $dependencies->queue ?? null ) ? $dependencies->queue : [],
					is_array( $dependencies->done ?? null ) ? $dependencies->done : []
				)
			)
		);

		$items = [];
		foreach ( $handles as $handle ) {
			$handle = sanitize_key( (string) $handle );
			if ( '' === $handle || ! isset( $dependencies->registered[ $handle ] ) ) {
				continue;
			}

			$items[] = $this->asset_record( $kind, $handle, $dependencies->registered[ $handle ], $dependencies );
		}

		return [
			'count'                => count( $items ),
			'registered'           => count( $dependencies->registered ),
			'registered_not_active' => max( 0, count( $dependencies->registered ) - count( $items ) ),
			'items'                => $items,
		];
	}

	private function asset_record( string $kind, string $handle, $asset, $dependencies ): array {
		$src = isset( $asset->src ) ? (string) $asset->src : '';
		$url = $this->normalize_url( $src );
		$local = $this->local_file_for_url( $url );
		$extra = isset( $asset->extra ) && is_array( $asset->extra ) ? $asset->extra : [];

		return [
			'handle'        => $handle,
			'kind'          => $kind,
			'owner'         => $this->owner_for( $handle, $url ),
			'src_type'      => $this->src_type( $url ),
			'deps'          => array_values( array_map( 'sanitize_key', isset( $asset->deps ) && is_array( $asset->deps ) ? $asset->deps : [] ) ),
			'version'       => isset( $asset->ver ) ? sanitize_text_field( (string) $asset->ver ) : '',
			'placement'     => $this->placement( $kind, $handle, $extra, $dependencies ),
			'inline_bytes'  => $this->inline_bytes( $extra ),
			'local_bytes'   => $local && is_file( $local ) ? (int) filesize( $local ) : null,
			'local_path_id' => $local ? substr( md5( wp_normalize_path( $local ) ), 0, 12 ) : '',
		];
	}

	private function normalize_url( string $src ): string {
		if ( '' === $src ) {
			return '';
		}

		if ( str_starts_with( $src, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $src;
		}

		if ( preg_match( '#^https?://#i', $src ) ) {
			return $src;
		}

		return site_url( $src );
	}

	private function local_file_for_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$url_path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $url_path ) {
			return '';
		}

		$pairs = [
			DSA_URL         => DSA_DIR,
			content_url( '/' ) => WP_CONTENT_DIR . '/',
			includes_url( '/' ) => ABSPATH . WPINC . '/',
			site_url( '/' ) => ABSPATH,
		];

		foreach ( $pairs as $base_url => $base_dir ) {
			$base_path = (string) wp_parse_url( (string) $base_url, PHP_URL_PATH );
			if ( '' === $base_path || ! str_starts_with( $url_path, $base_path ) ) {
				continue;
			}

			$relative = ltrim( substr( $url_path, strlen( $base_path ) ), '/\\' );
			$path = wp_normalize_path( trailingslashit( (string) $base_dir ) . $relative );

			if ( str_starts_with( $path, wp_normalize_path( trailingslashit( (string) $base_dir ) ) ) ) {
				return $path;
			}
		}

		return '';
	}

	private function owner_for( string $handle, string $url ): string {
		$haystack = strtolower( $handle . ' ' . $url );

		if ( str_contains( $haystack, 'dsa' ) || str_contains( $haystack, 'kiwe' ) || str_contains( $haystack, '/mu-plugins/dsa/' ) ) {
			return 'kiwe';
		}

		if ( str_contains( $haystack, 'bricks' ) || str_contains( $haystack, '/themes/bricks/' ) ) {
			return 'bricks';
		}

		if ( str_contains( $haystack, 'woocommerce' ) || str_contains( $haystack, 'wc-' ) || str_contains( $haystack, '/plugins/woocommerce/' ) ) {
			return 'woocommerce';
		}

		if ( str_contains( $haystack, '/wp-includes/' ) || str_starts_with( $handle, 'wp-' ) ) {
			return 'wordpress';
		}

		if ( str_contains( $haystack, '/themes/' ) ) {
			return 'theme';
		}

		if ( str_contains( $haystack, '/plugins/' ) || str_contains( $haystack, '/mu-plugins/' ) ) {
			return 'plugin';
		}

		if ( preg_match( '#^https?://#i', $url ) && ! str_starts_with( $url, site_url( '/' ) ) ) {
			return 'external';
		}

		return 'unknown';
	}

	private function src_type( string $url ): string {
		if ( '' === $url ) {
			return 'inline';
		}

		if ( preg_match( '#^https?://#i', $url ) && ! str_starts_with( $url, site_url( '/' ) ) ) {
			return 'external';
		}

		return 'local';
	}

	private function placement( string $kind, string $handle, array $extra, $dependencies ): string {
		if ( 'style' === $kind ) {
			return 'head';
		}

		$group = isset( $extra['group'] ) ? (int) $extra['group'] : 0;
		if ( 1 === $group ) {
			return 'footer';
		}

		if ( isset( $dependencies->groups ) && is_array( $dependencies->groups ) && ! empty( $dependencies->groups[ $handle ] ) ) {
			return 'footer';
		}

		return 'head';
	}

	private function inline_bytes( array $extra ): array {
		$bytes = [];

		foreach ( [ 'data', 'before', 'after' ] as $key ) {
			if ( ! isset( $extra[ $key ] ) ) {
				continue;
			}

			if ( is_array( $extra[ $key ] ) ) {
				$bytes[ $key ] = array_sum( array_map( 'strlen', array_map( 'strval', $extra[ $key ] ) ) );
				continue;
			}

			$bytes[ $key ] = strlen( (string) $extra[ $key ] );
		}

		return $bytes;
	}

	private function route_context(): array {
		$path = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		}

		return [
			'path'         => sanitize_text_field( $path ),
			'is_admin'     => is_admin(),
			'is_rest'      => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'is_ajax'      => wp_doing_ajax(),
			'is_front'     => function_exists( 'is_front_page' ) && is_front_page(),
			'is_singular'  => function_exists( 'is_singular' ) && is_singular(),
			'post_type'    => function_exists( 'get_post_type' ) ? sanitize_key( (string) get_post_type() ) : '',
			'queried_hash' => get_queried_object_id() ? substr( md5( (string) get_queried_object_id() ), 0, 12 ) : '',
		];
	}
}
