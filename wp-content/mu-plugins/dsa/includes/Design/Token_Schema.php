<?php

namespace DSA\Design;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Token_Schema {
	public const VERSION = 2;

	public static function contract( array $settings, array $manifest = [] ): array {
		$theme  = isset( $settings['dsa_theme'] ) && is_array( $settings['dsa_theme'] ) ? $settings['dsa_theme'] : [];
		$visual = isset( $settings['visual_effects'] ) && is_array( $settings['visual_effects'] ) ? $settings['visual_effects'] : [];
		$footprint = isset( $manifest['footprint'] ) && is_array( $manifest['footprint'] ) ? $manifest['footprint'] : [];

		$active = self::hex_color( $theme['active_color'] ?? '', '#8f8f98' );
		$hover  = self::hex_color( $theme['hover_color'] ?? '', '#24c6a1' );
		$hero   = self::css_color( $theme['hero_text_color'] ?? '', 'rgba(20,24,34,0.18)' );
		$blur   = max( 0, min( 24, (int) ( $visual['blur_strength'] ?? 10 ) ) ) . 'px';
		$right  = max( 0, (int) ( $footprint['right'] ?? ( $settings['surface_width'] ?? 72 ) ) ) . 'px';
		$left   = max( 0, (int) ( $footprint['left'] ?? 0 ) ) . 'px';
		$top    = max( 0, (int) ( $footprint['top'] ?? 0 ) ) . 'px';
		$bottom = max( 0, (int) ( $footprint['bottom'] ?? 0 ) ) . 'px';

		$tokens = [
			self::token( 'color.active', '--dsa-active-color', 'color', 'var(--kiwe-color-brand)', $active, 'kiwe.universal.color-brand', __( 'Compatibility alias for the Kiwe brand token.', 'dsa' ) ),
			self::token( 'color.hover', '--dsa-hover-color', 'color', 'var(--kiwe-color-accent)', $hover, 'kiwe.universal.color-accent', __( 'Compatibility alias for the Kiwe accent token.', 'dsa' ) ),
			self::token( 'color.heroText', '--dsa-hero-text-color', 'color', 'var(--kiwe-color-hero)', $hero, 'kiwe.universal.color-hero', __( 'Compatibility alias for the Kiwe hero token.', 'dsa' ) ),
			self::token( 'effect.blurStrength', '--dsa-blur-strength', 'dimension', 'var(--kiwe-glass-blur)', $blur, 'kiwe.universal.glass-blur', __( 'Compatibility alias for Kiwe glass blur.', 'dsa' ) ),
			self::token( 'layout.surfaceTop', '--dsa-surface-top', 'dimension', $top, '0px', 'manifest.footprint.top', __( 'Reserved top viewport space.', 'dsa' ) ),
			self::token( 'layout.surfaceRight', '--dsa-surface-right', 'dimension', $right, '72px', 'manifest.footprint.right', __( 'Reserved right viewport space.', 'dsa' ) ),
			self::token( 'layout.surfaceBottom', '--dsa-surface-bottom', 'dimension', $bottom, '0px', 'manifest.footprint.bottom', __( 'Reserved bottom viewport space.', 'dsa' ) ),
			self::token( 'layout.surfaceLeft', '--dsa-surface-left', 'dimension', $left, '0px', 'manifest.footprint.left', __( 'Reserved left viewport space.', 'dsa' ) ),
			self::token( 'layout.contentWidth', '--dsa-content-vw', 'calculation', 'calc(100vw - var(--dsa-surface-left) - var(--dsa-surface-right))', 'calc(100vw - var(--dsa-surface-left) - var(--dsa-surface-right))', 'derived', __( 'Available Surface content width.', 'dsa' ) ),
			self::token( 'layout.contentHeight', '--dsa-content-vh', 'calculation', 'calc(100vh - var(--dsa-surface-top) - var(--dsa-surface-bottom))', 'calc(100vh - var(--dsa-surface-top) - var(--dsa-surface-bottom))', 'derived', __( 'Available Surface content height.', 'dsa' ) ),
			self::token( 'geometry.controlMin', '--dsa-geometry-control-min', 'dimension', 'var(--kiwe-control-size-sm)', '30px', 'kiwe.universal.control-size-sm', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.controlIdeal', '--dsa-geometry-control-ideal', 'dimension', 'var(--kiwe-control-size)', '48px', 'kiwe.universal.control-size', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.aiMin', '--dsa-geometry-ai-min', 'dimension', 'var(--kiwe-control-size-ai-sm)', '40px', 'kiwe.universal.control-size-ai-sm', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.aiIdeal', '--dsa-geometry-ai-ideal', 'dimension', 'var(--kiwe-control-size-ai)', '54px', 'kiwe.universal.control-size-ai', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.iconMin', '--dsa-geometry-icon-min', 'dimension', 'var(--kiwe-icon-size-sm)', '18px', 'kiwe.universal.icon-size-sm', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.iconIdeal', '--dsa-geometry-icon-ideal', 'dimension', 'var(--kiwe-icon-size)', '23px', 'kiwe.universal.icon-size', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.badgeMin', '--dsa-geometry-badge-min', 'dimension', 'var(--kiwe-badge-size-sm)', '16px', 'kiwe.universal.badge-size-sm', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.badgeIdeal', '--dsa-geometry-badge-ideal', 'dimension', 'var(--kiwe-badge-size)', '18px', 'kiwe.universal.badge-size', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.dockGapMin', '--dsa-geometry-dock-gap-min', 'dimension', 'var(--kiwe-dock-gap-min)', '1px', 'kiwe.universal.dock-gap-min', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.dockGapIdeal', '--dsa-geometry-dock-gap-ideal', 'dimension', 'var(--kiwe-dock-gap)', '2px', 'kiwe.universal.dock-gap', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.dockPaddingMin', '--dsa-geometry-dock-padding-min', 'dimension', 'var(--kiwe-dock-padding-min)', '6px', 'kiwe.universal.dock-padding-min', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.dockPaddingIdeal', '--dsa-geometry-dock-padding-ideal', 'dimension', 'var(--kiwe-dock-padding)', '8px', 'kiwe.universal.dock-padding', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.dockBorder', '--dsa-geometry-dock-border', 'dimension', 'var(--kiwe-dock-border)', '1px', 'kiwe.universal.dock-border', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.clusterGapMin', '--dsa-geometry-cluster-gap-min', 'dimension', 'var(--kiwe-dock-cluster-gap-min)', '6px', 'kiwe.universal.dock-cluster-gap-min', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.clusterGapIdeal', '--dsa-geometry-cluster-gap-ideal', 'dimension', 'var(--kiwe-dock-cluster-gap)', '14px', 'kiwe.universal.dock-cluster-gap', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.viewportGutter', '--dsa-geometry-viewport-gutter', 'dimension', 'var(--kiwe-viewport-gutter)', '12px', 'kiwe.universal.viewport-gutter', __( 'Resolved compatibility alias.', 'dsa' ) ),
			self::token( 'geometry.contentMax', '--dsa-screen-content-width', 'dimension', '980px', '980px', 'kiwe.surface.geometry', __( 'Maximum width shared by full-screen DSA panel content.', 'dsa' ) ),
			self::token( 'geometry.screenGutter', '--dsa-screen-gutter', 'dimension', 'clamp(18px, 5vw, 72px)', '18px', 'kiwe.surface.geometry', __( 'Fluid outer gutter for DSA screen content.', 'dsa' ) ),
		];

		return [
			'schemaVersion' => self::VERSION,
			'namespace'     => 'kiwe.surface',
			'mode'          => 'read-only',
			'writesStyles'  => false,
			'tokens'        => $tokens,
			'cssVariables'  => self::css_variables( $tokens ),
			'adapters'      => [
				'bricks' => [
					'mode'         => 'read-only',
					'writesStyles' => false,
				],
			],
		];
	}

	private static function token( string $name, string $css_var, string $type, string $value, string $fallback, string $source, string $description ): array {
		return [
			'name'        => $name,
			'cssVar'      => $css_var,
			'type'        => $type,
			'value'       => $value,
			'fallback'    => $fallback,
			'source'      => $source,
			'description' => $description,
			'writable'    => false,
		];
	}

	private static function css_variables( array $tokens ): array {
		$variables = [];

		foreach ( $tokens as $token ) {
			if ( ! empty( $token['cssVar'] ) && array_key_exists( 'value', $token ) ) {
				$variables[ $token['cssVar'] ] = $token['value'];
			}
		}

		return $variables;
	}

	private static function hex_color( $value, string $fallback ): string {
		$color = sanitize_hex_color( is_string( $value ) ? $value : '' );

		return $color ?: $fallback;
	}

	private static function css_color( $value, string $fallback ): string {
		$color = trim( (string) $value );

		if ( preg_match( '/^(#[0-9a-f]{3,6}|rgba?\([^)]+\))$/i', $color ) ) {
			return $color;
		}

		return $fallback;
	}
}
