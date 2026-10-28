<?php

namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reduce proven repetition in the native large inline block without changing native settings. */
final class Inline_Style_Service {
	private const HANDLE = 'bricks-frontend-inline';
	private const MIN_BYTES = 524288;
	private const MAX_BYTES = 4194304;
	private ?array $last = null;

	public function register(): void {
		if ( ! defined( 'BRICKS_VERSION' ) || version_compare( BRICKS_VERSION, '2.3.4', '<' ) || version_compare( BRICKS_VERSION, '2.5', '>=' ) ) { return; }
		add_filter( 'print_styles_array', [ $this, 'reduce' ], PHP_INT_MAX );
	}

	public function reduce( $handles ) {
		if ( ! is_array( $handles ) || ! in_array( self::HANDLE, $handles, true ) || is_admin()
			|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() )
			|| ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() )
			|| ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) { return $handles; }
		if ( ! class_exists( '\Bricks\Database', false ) || 'file' === \Bricks\Database::get_setting( 'cssLoading' ) ) { return $handles; }
		$styles = $GLOBALS['wp_styles'] ?? null;
		if ( ! $styles instanceof \WP_Styles || ! isset( $styles->registered[ self::HANDLE ] ) || false !== $styles->registered[ self::HANDLE ]->src ) { return $handles; }
		$after = $styles->get_data( self::HANDLE, 'after' );
		if ( ! is_array( $after ) || ! array_is_list( $after ) || count( $after ) > 32 || $this->last === $after ) { return $handles; }
		$total = 0;
		foreach ( $after as $css ) {
			if ( ! is_string( $css ) ) { return $handles; }
			$total += strlen( $css );
			if ( $total > self::MAX_BYTES ) { return $handles; }
		}
		// Predeclared security policies may own exact inline hashes. Never weaken or rewrite them.
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Content-Security-Policy:' ) || 0 === stripos( $header, 'Content-Security-Policy-Report-Only:' ) ) { return $handles; }
		}
		if ( ! apply_filters( 'kiwe/bricks/reduce_inline_css', true ) ) { return $handles; }
		$next = $after;
		foreach ( $after as $index => $css ) {
			$bytes = strlen( $css );
			if ( $bytes < self::MIN_BYTES || $bytes > self::MAX_BYTES || ! $this->has_repeat( $css ) ) { continue; }
			$reduced = \DSA\Performance\CSS_Reduction_Cache::reduce( $css );
			if ( $bytes - strlen( $reduced ) >= 8192 ) { $next[ $index ] = $reduced; }
		}
		if ( $next !== $after ) {
			// Keep the handle, dependency order and every other metadata field in its native owner.
			if ( ! $styles->add_data( self::HANDLE, 'after', $next ) ) { return $handles; }
		}
		$this->last = $next;
		return $handles;
	}

	/** A cheap repeat probe can decline work; it never authorizes a CSS removal. */
	private function has_repeat( string $css ): bool {
		$size = strlen( $css );
		for ( $sample = 0; $sample < 8; $sample++ ) {
			$at = (int) ( ( $size - 96 ) * ( $sample + 0.5 ) / 8 );
			$probe = substr( $css, $at, 96 );
			$first = strpos( $css, $probe );
			if ( false !== $first && ( $first < $at || false !== strpos( $css, $probe, $at + 96 ) ) ) { return true; }
		}
		return false;
	}
}
