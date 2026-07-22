<?php

namespace DSA\Public_Endpoint;

use DSA\Element_Registry;
use DSA\Environment;
use DSA\Modules\Module_Registry;
use DSA\PhoneKey\PhoneKey_Bridge;
use DSA\Theme\Theme_Package_Service;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Surface_Renderer {
	public function __construct(
		private Settings $settings,
		private Module_Registry $modules,
		private Element_Registry $registry,
		private PhoneKey_Bridge $phonekey
	) {}

	public function register(): void {
		add_action( 'wp_footer', [ $this, 'render' ], 5 );
	}

	public function render(): void {
		if ( ! $this->settings->get( 'enabled', true ) || ! Environment::should_render_frontend() ) {
			return;
		}

		$all_settings = $this->settings->all();
		$dock         = isset( $all_settings['dock'] ) && is_array( $all_settings['dock'] ) ? $all_settings['dock'] : [];
		$commerce     = isset( $all_settings['commerce'] ) && is_array( $all_settings['commerce'] ) ? $all_settings['commerce'] : [];

		if ( empty( $commerce['cart_surface_enabled'] ) ) {
			$dock['enabled_items']['cart'] = false;
		}
		$modules = $this->dock_items( $dock );
		$focus_item = sanitize_key( (string) ( $dock['focus_item'] ?? 'ai' ) );
		$module_ids = array_map( static fn( array $module ): string => sanitize_key( (string) ( $module['id'] ?? '' ) ), $modules );
		if ( ! in_array( $focus_item, $module_ids, true ) ) {
			$focus_item = in_array( 'ai', $module_ids, true ) ? 'ai' : ( $module_ids[0] ?? '' );
		}
		$main_module_count = count( array_filter( $modules, static fn( array $module ): bool => $focus_item !== (string) ( $module['id'] ?? '' ) ) );
		$ai_module_count = count( $modules ) - $main_module_count;
		$visual  = $this->settings->get( 'visual_effects', [] );
		$style   = $this->settings->get( 'style', [] );
		$raw_visual_profile = sanitize_key( (string) ( $style['visual_profile'] ?? 'legacy' ) );
		$visual_profile = in_array( $raw_visual_profile, [ 'prototype', 'kiwe2027', 'kiwe-2027' ], true ) ? 'kiwe2027' : 'legacy';
		$active_theme = ( new Theme_Package_Service() )->active( $all_settings );
		$active_theme_id = sanitize_html_class( (string) ( $active_theme['id'] ?? $visual_profile ) );
		$theme_mode = in_array( $style['mode'] ?? 'classic', [ 'classic', 'sheet' ], true ) ? (string) $style['mode'] : 'classic';
		$sheet_position = in_array( $style['sheet_position'] ?? 'bottom', [ 'bottom', 'right', 'left' ], true ) ? (string) $style['sheet_position'] : 'bottom';
		$sheet_animation = in_array( $style['sheet_animation'] ?? 'slide', [ 'slide', 'soft', 'snap' ], true ) ? (string) $style['sheet_animation'] : 'slide';
		$sheet_backdrop = in_array( $style['sheet_backdrop'] ?? 'blur', [ 'blur', 'fade', 'none' ], true ) ? (string) $style['sheet_backdrop'] : 'blur';
		$sheet_spacing = in_array( $style['sheet_spacing'] ?? 'edge', [ 'edge', 'inset' ], true ) ? (string) $style['sheet_spacing'] : 'edge';
		$sheet_origin = in_array( $style['sheet_origin'] ?? 'bottom', [ 'bottom', 'above_dock' ], true ) ? (string) $style['sheet_origin'] : 'bottom';
		$sheet_duration = max( 120, min( 900, (int) ( $style['sheet_duration_ms'] ?? 320 ) ) );
		$sheet_max_height = max( 45, min( 96, (int) ( $style['sheet_max_height'] ?? 82 ) ) );
		$sheet_width_percent = max( 50, min( 90, (int) ( $style['sheet_width_percent'] ?? 78 ) ) );
		$dock_presentation = in_array( $dock['presentation'] ?? 'dock', [ 'dock', 'navbar' ], true ) ? (string) $dock['presentation'] : 'dock';
		$dock_shape = sanitize_key( (string) ( $dock['shape'] ?? 'pill' ) );
		$dock_shape = 'rounded' === $dock_shape ? 'pill' : $dock_shape;
		$dock_shape = in_array( $dock_shape, [ 'pill', 'box', 'square' ], true ) ? $dock_shape : 'pill';
		$dock_split_enabled = ! empty( $dock['split_style'] ) && 'dock' === $dock_presentation;
		$desktop_orientation = in_array( $dock['desktop_orientation'] ?? 'auto', [ 'auto', 'horizontal', 'vertical' ], true ) ? (string) $dock['desktop_orientation'] : 'auto';
		$tablet_orientation = in_array( $dock['tablet_orientation'] ?? 'auto', [ 'auto', 'horizontal', 'vertical' ], true ) ? (string) $dock['tablet_orientation'] : 'auto';
		$mobile_orientation = in_array( $dock['mobile_orientation'] ?? 'auto', [ 'auto', 'horizontal', 'vertical' ], true ) ? (string) $dock['mobile_orientation'] : 'auto';
		$desktop_class_orientation = 'auto' === $desktop_orientation ? 'vertical' : $desktop_orientation;
		$tablet_class_orientation = 'auto' === $tablet_orientation ? 'horizontal' : $tablet_orientation;
		$mobile_class_orientation = 'auto' === $mobile_orientation ? 'horizontal' : $mobile_orientation;
		$initial_orientation = 'horizontal' === $desktop_orientation ? 'horizontal' : 'vertical';
		$initial_position = 'horizontal' === $initial_orientation ? (string) ( $dock['desktop_horizontal_vertical_position'] ?? 'bottom' ) : (string) ( $dock['desktop_vertical_position'] ?? 'center' );
		$initial_alignment = 'horizontal' === $initial_orientation ? (string) ( $dock['desktop_horizontal_position'] ?? 'right' ) : $initial_position;
		$initial_edge = 'horizontal' === $initial_orientation ? (string) ( $dock['desktop_horizontal_edge'] ?? 'bottom' ) : (string) ( $dock['desktop_vertical_edge'] ?? 'right' );
		$context_rail_enabled = ! empty( $dock['context_rail_enabled'] ) && 'legacy' === $visual_profile && 'classic' === $theme_mode;
		$registry_json = wp_json_encode(
			$this->registry->to_array(),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		$surface_classes = [
			'dsa-surface',
			'dsa-visual-' . sanitize_html_class( $visual_profile ),
			'dsa-installed-theme-' . sanitize_html_class( $active_theme_id ),
			'dsa-theme-' . sanitize_html_class( $theme_mode ),
			'dsa-sheet-position-' . sanitize_html_class( $sheet_position ),
			'dsa-sheet-animation-' . sanitize_html_class( $sheet_animation ),
			'dsa-sheet-backdrop-' . sanitize_html_class( $sheet_backdrop ),
			'dsa-sheet-spacing-' . sanitize_html_class( $sheet_spacing ),
			'dsa-sheet-origin-' . sanitize_html_class( $sheet_origin ),
			'dsa-loader-' . sanitize_html_class( $visual['loader_type'] ?? 'orb-chase' ),
			'dsa-dock-presentation-' . sanitize_html_class( $dock_presentation ),
			'dsa-dock-shape-' . sanitize_html_class( $dock_shape ),
			'dsa-dock-material-' . sanitize_html_class( $dock['material'] ?? 'glass' ),
			'dsa-dock-desktop-' . sanitize_html_class( $desktop_class_orientation ),
			'dsa-dock-tablet-' . sanitize_html_class( $tablet_class_orientation ),
			'dsa-dock-mobile-' . sanitize_html_class( $mobile_class_orientation ),
			'dsa-dock-desktop-vertical-position-' . sanitize_html_class( $dock['desktop_vertical_position'] ?? 'center' ),
			'dsa-dock-desktop-horizontal-position-' . sanitize_html_class( $dock['desktop_horizontal_position'] ?? 'right' ),
			'dsa-dock-tablet-vertical-position-' . sanitize_html_class( $dock['tablet_vertical_position'] ?? 'center' ),
			'dsa-dock-tablet-horizontal-position-' . sanitize_html_class( $dock['tablet_horizontal_position'] ?? 'center' ),
			'dsa-dock-tablet-horizontal-vertical-position-' . sanitize_html_class( $dock['tablet_horizontal_vertical_position'] ?? 'bottom' ),
			'dsa-dock-mobile-vertical-position-' . sanitize_html_class( $dock['mobile_vertical_position'] ?? 'bottom' ),
			'dsa-dock-mobile-horizontal-position-' . sanitize_html_class( $dock['mobile_horizontal_position'] ?? 'right' ),
			'dsa-dock-mobile-horizontal-vertical-position-' . sanitize_html_class( $dock['mobile_horizontal_vertical_position'] ?? 'bottom' ),
			'dsa-dock-desktop-vertical-edge-' . sanitize_html_class( $dock['desktop_vertical_edge'] ?? 'right' ),
			'dsa-dock-desktop-horizontal-edge-' . sanitize_html_class( $dock['desktop_horizontal_edge'] ?? 'bottom' ),
			'dsa-dock-tablet-vertical-edge-' . sanitize_html_class( $dock['tablet_vertical_edge'] ?? 'right' ),
			'dsa-dock-tablet-horizontal-edge-' . sanitize_html_class( $dock['tablet_horizontal_edge'] ?? 'bottom' ),
			'dsa-dock-mobile-vertical-edge-' . sanitize_html_class( $dock['mobile_vertical_edge'] ?? 'right' ),
			'dsa-dock-mobile-horizontal-edge-' . sanitize_html_class( $dock['mobile_horizontal_edge'] ?? 'bottom' ),
		];
		if ( 'kiwe2027' === $visual_profile ) {
			$surface_classes[] = 'dsa-visual-prototype';
		}
		if ( $context_rail_enabled ) {
			$surface_classes[] = 'dsa-context-rail-enabled';
		}
		if ( $dock_split_enabled ) {
			$surface_classes[] = 'dsa-dock-split';
		}
		if ( 'classic' === $theme_mode ) {
			$surface_classes[] = 'dsa-blur-' . sanitize_html_class( $visual['blur_type'] ?? 'gaussian' );
			$surface_classes[] = 'dsa-glass-' . sanitize_html_class( $visual['glass_intensity'] ?? 'medium' );
			$surface_classes[] = 'dsa-screen-material-' . sanitize_html_class( $visual['screen_material'] ?? 'glass' );
			$surface_classes[] = 'dsa-screen-motion-' . sanitize_html_class( $visual['screen_animation'] ?? 'bottom' );
		}
		?>
		<!-- DSA Surface <?php echo esc_html( DSA_VERSION ); ?> -->
		<script id="dsa-element-registry" type="application/json"><?php echo $registry_json ? $registry_json : '{}'; ?></script>
		<div class="dsa-document-scrim" data-dsa-scrim hidden></div>
		<div id="dsa-surface" class="<?php echo esc_attr( implode( ' ', $surface_classes ) ); ?>" data-dsa-surface data-nosnippet data-dsa-ui-contract="2" data-dsa-visual-profile="<?php echo esc_attr( $visual_profile ); ?>" data-dsa-installed-theme="<?php echo esc_attr( $active_theme_id ); ?>" data-dsa-theme="<?php echo esc_attr( $theme_mode ); ?>" data-dsa-dock-presentation="<?php echo esc_attr( $dock_presentation ); ?>" data-dsa-dock-focus-id="<?php echo esc_attr( $focus_item ); ?>" data-dsa-dock-profile="desktop" data-dsa-dock-orientation="<?php echo esc_attr( $initial_orientation ); ?>" data-dsa-dock-position="<?php echo esc_attr( $initial_position ); ?>" data-dsa-dock-alignment="<?php echo esc_attr( $initial_alignment ); ?>" data-dsa-dock-edge="<?php echo esc_attr( $initial_edge ); ?>" data-dsa-sheet-position="<?php echo esc_attr( $sheet_position ); ?>" data-dsa-sheet-backdrop="<?php echo esc_attr( $sheet_backdrop ); ?>" data-dsa-sheet-spacing="<?php echo esc_attr( $sheet_spacing ); ?>" data-dsa-sheet-origin="<?php echo esc_attr( $sheet_origin ); ?>" data-dsa-layout="wide" data-dsa-density="comfortable" data-dsa-dock-item-count="<?php echo esc_attr( (string) $main_module_count ); ?>" data-dsa-dock-ai-count="<?php echo esc_attr( (string) $ai_module_count ); ?>" style="--dsa-dock-item-count:<?php echo esc_attr( (string) $main_module_count ); ?>;--dsa-dock-ai-count:<?php echo esc_attr( (string) $ai_module_count ); ?>;--dsa-sheet-duration:<?php echo esc_attr( (string) $sheet_duration ); ?>ms;--dsa-sheet-max-height:<?php echo esc_attr( (string) $sheet_max_height ); ?>dvh;--dsa-sheet-width-percent:<?php echo esc_attr( (string) $sheet_width_percent ); ?>;">
			<div class="dsa-dock-context" data-dsa-dock-context hidden><div class="dsa-dock-context__content" data-dsa-dock-context-content></div></div>
			<div class="dsa-dock-cluster" data-dsa-dock-cluster>
			<nav class="dsa-dock dsa-phonekey-dock" data-dsa-dock role="toolbar" aria-label="<?php echo esc_attr__( 'Surface tools', 'dsa' ); ?>">
				<?php foreach ( $modules as $index => $module ) : ?>
					<?php
					$button_classes = [ 'dsa-dock__button' ];
					$module_id = sanitize_key( (string) ( $module['id'] ?? '' ) );
					$is_focus = $focus_item === $module_id;
					if ( $is_focus ) {
						$button_classes[] = 'dsa-dock-focus';
						$button_classes[] = 'dsa-dock-primary';
						$button_classes[] = 'dsa-ai-launcher';
					}
					if ( $dock_split_enabled ) {
						if ( 0 === (int) $index ) {
							$button_classes[] = 'is-split-segment-start';
						}
						if ( count( $modules ) - 1 === (int) $index ) {
							$button_classes[] = 'is-split-segment-end';
						}
						if ( isset( $modules[ $index + 1 ] ) && $focus_item === sanitize_key( (string) ( $modules[ $index + 1 ]['id'] ?? '' ) ) ) {
							$button_classes[] = 'is-split-before-focus';
							$button_classes[] = 'is-split-before-ai';
						}
						if ( isset( $modules[ $index - 1 ] ) && $focus_item === sanitize_key( (string) ( $modules[ $index - 1 ]['id'] ?? '' ) ) ) {
							$button_classes[] = 'is-split-after-focus';
							$button_classes[] = 'is-split-after-ai';
						}
					}
					?>
					<?php if ( 'link' === sanitize_key( (string) ( $module['mode'] ?? '' ) ) && ! empty( $module['url'] ) ) : ?>
					<a
						class="<?php echo esc_attr( implode( ' ', $button_classes ) ); ?>"
						href="<?php echo esc_url( $module['url'] ); ?>"
						data-dsa-dock-item
						data-dsa-module="<?php echo esc_attr( $module_id ); ?>"
						data-dsa-module-mode="link"
						data-dsa-dock-link
						<?php echo $is_focus ? 'data-dsa-dock-focus data-dsa-dock-primary' : ''; ?>
						data-dsa-full-navigation
						aria-label="<?php echo esc_attr( $module['label'] ); ?>"
					>
						<span class="dsa-dock__icon dsa-icon-<?php echo esc_attr( $module_id ); ?>" aria-hidden="true"><?php echo wp_kses( $module['icon'], $this->svg_allowlist() ); ?></span>
						<span class="dsa-dock__badge" data-dsa-badge="<?php echo esc_attr( $module_id ); ?>" <?php echo empty( $module['badge'] ) ? 'hidden' : ''; ?>><?php echo esc_html( (string) ( $module['badge'] ?? '' ) ); ?></span>
					</a>
					<?php else : ?>
					<button
						class="<?php echo esc_attr( implode( ' ', $button_classes ) ); ?>"
						type="button"
						data-dsa-dock-item
						data-dsa-module="<?php echo esc_attr( $module_id ); ?>"
						data-dsa-module-mode="<?php echo esc_attr( $module['mode'] ?? 'dock' ); ?>"
						data-dsa-module-panel="<?php echo esc_attr( $module['panel'] ?? $module_id ); ?>"
						<?php echo $is_focus ? 'data-dsa-dock-focus data-dsa-dock-primary' : ''; ?>
						aria-pressed="false"
						aria-label="<?php echo esc_attr( $module['label'] ); ?>"
					>
						<span class="dsa-dock__icon dsa-icon-<?php echo esc_attr( $module_id ); ?>" aria-hidden="true"><?php echo wp_kses( $module['icon'], $this->svg_allowlist() ); ?></span>
						<span class="dsa-dock__badge" data-dsa-badge="<?php echo esc_attr( $module_id ); ?>" <?php echo empty( $module['badge'] ) ? 'hidden' : ''; ?>><?php echo esc_html( (string) ( $module['badge'] ?? '' ) ); ?></span>
					</button>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>
			</div>
			<aside class="dsa-ai-popout" data-dsa-ai-popout hidden aria-live="polite"></aside>
			<div class="dsa-overlay-root" data-dsa-overlay-root hidden></div>
			<div class="dsa-loader" data-dsa-loader hidden aria-live="polite" aria-label="<?php echo esc_attr__( 'Surface loading experience', 'dsa' ); ?>">
				<div class="dsa-loader__arena" data-dsa-loader-arena>
					<span class="dsa-loader__orb" data-dsa-loader-orb></span>
					<span class="dsa-loader__target"></span>
					<span class="dsa-loader__trail dsa-loader__trail--one"></span>
					<span class="dsa-loader__trail dsa-loader__trail--two"></span>
				</div>
				<div class="dsa-loader__message" data-dsa-loader-message hidden>
					<div class="dsa-loader__title" data-dsa-loader-title></div>
					<div class="dsa-loader__copy" data-dsa-loader-copy></div>
				</div>
				<div class="dsa-loader__label" data-dsa-loader-label><?php esc_html_e( 'Loading', 'dsa' ); ?></div>
			</div>
			<div class="dsa-live-region screen-reader-text" aria-live="polite" aria-atomic="true"></div>
		</div>
		<?php
	}

	private function dock_items( array $dock ): array {
		return $this->modules->dock_modules(
			$dock,
			[
				// Visibility is hydrated after cache-safe boot; keep the static shell neutral.
				'phonekey_visible' => true,
				'secure_available' => $this->securetrack_available(),
				'badges'           => [
					'profile' => 0,
					'cart'    => 0,
					'ai'      => 0,
				],
				'labels'           => [
					'menu' => $dock['menu_label'] ?? __( 'Menu', 'dsa' ),
				],
			]
		);
	}

	private function center_ai_module( array $modules ): array {
		$ai = null;
		$items = [];

		foreach ( $modules as $module ) {
			if ( 'ai' === (string) ( $module['id'] ?? '' ) ) {
				$ai = $module;
				continue;
			}

			$items[] = $module;
		}

		if ( null === $ai ) {
			return $items;
		}

		array_splice( $items, (int) ceil( count( $items ) / 2 ), 0, [ $ai ] );

		return $items;
	}

	private function securetrack_available(): bool {
		return defined( 'STP_VER' ) || function_exists( 'stp_cfg' );
	}

	private function svg_allowlist(): array {
		return [
			'svg' => [
				'class' => true,
				'viewbox' => true,
				'aria-hidden' => true,
				'focusable' => true,
			],
			'use' => [
				'href' => true,
				'xlink:href' => true,
			],
			'path' => [
				'd' => true,
			],
			'circle' => [
				'cx' => true,
				'cy' => true,
				'r' => true,
				'fill' => true,
			],
			'line' => [
				'x1' => true,
				'x2' => true,
				'y1' => true,
				'y2' => true,
			],
			'polyline' => [
				'points' => true,
			],
			'span' => [
				'class' => true,
				'aria-hidden' => true,
			],
			'i' => [
				'class' => true,
			],
		];
	}
}
