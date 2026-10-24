<?php
namespace DSA\Dock;
use DSA\Theme\Theme_Package_Service;
if ( ! defined( 'ABSPATH' ) ) exit;

/** One native normalizer shared by owner forms and the client Dock workspace. */
final class Dock_Settings_Service {
	public function __construct( private array $labels ) {}
	private function dock_module_labels(): array { return $this->labels; }

	public function sanitize_dock_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$enabled = (array) ( $current['enabled_items'] ?? [] );
		$custom_items = isset( $input['custom_items'] ) ? $this->sanitize_dock_custom_items( $input['custom_items'] ) : $this->dock_custom_items_for_admin( $current );
		$allowed_labels = array_merge( $this->dock_module_labels(), $this->dock_custom_item_labels( $custom_items ) );
		$order = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $input['item_order'] ?? ( $current['item_order'] ?? [] ) ) ) ) ) );

		if ( isset( $input['enabled_items'] ) && is_array( $input['enabled_items'] ) ) {
			foreach ( array_keys( $allowed_labels ) as $id ) {
				$enabled[ $id ] = ! empty( $input['enabled_items'][ $id ] );
			}
		}

		foreach ( $custom_items as $item ) {
			if ( ! isset( $enabled[ $item['id'] ] ) ) {
				$enabled[ $item['id'] ] = ! empty( $item['enabled'] );
			}
		}

		$enabled = array_replace(
			array_fill_keys( array_keys( $allowed_labels ), true ),
			array_intersect_key( $enabled, $allowed_labels )
		);

		$order = array_values( array_filter( $order, static fn( string $id ): bool => isset( $allowed_labels[ $id ] ) ) );
		foreach ( array_keys( $allowed_labels ) as $id ) {
			if ( ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}

		$focus_item = sanitize_key( (string) ( $input['focus_item'] ?? ( $current['focus_item'] ?? 'ai' ) ) );
		if ( '' !== $focus_item && ! isset( $allowed_labels[ $focus_item ] ) ) {
			$focus_item = isset( $allowed_labels['ai'] ) ? 'ai' : ( array_key_first( $allowed_labels ) ?: '' );
		}

		$heading = sanitize_key( $input['menu_heading_tag'] ?? ( $current['menu_heading_tag'] ?? 'span' ) );

		if ( ! in_array( $heading, [ 'span', 'p', 'h1', 'h2', 'h3', 'h4' ], true ) ) {
			$heading = 'span';
		}

		$shape = sanitize_key( (string) ( $input['shape'] ?? ( $current['shape'] ?? 'pill' ) ) );
		$shape = 'rounded' === $shape ? 'pill' : $shape;
		$shape = in_array( $shape, [ 'pill', 'box', 'square' ], true ) ? $shape : 'pill';

		return [
			'style'               => 'phonekey',
			'visible'             => array_key_exists( 'visible', $input ) ? ! empty( $input['visible'] ) : ( $current['visible'] ?? true ),
			'presentation'        => in_array( $input['presentation'] ?? ( $current['presentation'] ?? 'dock' ), [ 'dock', 'navbar' ], true ) ? sanitize_key( $input['presentation'] ?? $current['presentation'] ) : 'dock',
			'shape'               => $shape,
			'material'            => in_array( $input['material'] ?? ( $current['material'] ?? 'glass' ), [ 'glass', 'solid' ], true ) ? sanitize_key( $input['material'] ?? $current['material'] ) : 'glass',
			'fill_axis'           => 'navbar' === ( $input['presentation'] ?? ( $current['presentation'] ?? 'dock' ) ),
			'context_rail_enabled' => array_key_exists( 'context_rail_enabled', $input ) ? ! empty( $input['context_rail_enabled'] ) : ! empty( $current['context_rail_enabled'] ),
			'split_style'         => array_key_exists( 'split_style', $input ) ? ! empty( $input['split_style'] ) : ! empty( $current['split_style'] ),
			'focus_item'          => $focus_item,
			'desktop_orientation' => in_array( $input['desktop_orientation'] ?? ( $current['desktop_orientation'] ?? 'auto' ), [ 'auto', 'vertical', 'horizontal' ], true ) ? sanitize_key( $input['desktop_orientation'] ?? $current['desktop_orientation'] ) : 'auto',
			'tablet_orientation'  => in_array( $input['tablet_orientation'] ?? ( $current['tablet_orientation'] ?? 'auto' ), [ 'auto', 'vertical', 'horizontal' ], true ) ? sanitize_key( $input['tablet_orientation'] ?? $current['tablet_orientation'] ) : 'auto',
			'mobile_orientation'  => in_array( $input['mobile_orientation'] ?? ( $current['mobile_orientation'] ?? 'auto' ), [ 'auto', 'vertical', 'horizontal' ], true ) ? sanitize_key( $input['mobile_orientation'] ?? $current['mobile_orientation'] ) : 'auto',
			'desktop_vertical_edge' => in_array( $input['desktop_vertical_edge'] ?? ( $current['desktop_vertical_edge'] ?? 'right' ), [ 'left', 'right' ], true ) ? sanitize_key( $input['desktop_vertical_edge'] ?? $current['desktop_vertical_edge'] ) : 'right',
			'desktop_horizontal_edge' => in_array( $input['desktop_horizontal_edge'] ?? ( $current['desktop_horizontal_edge'] ?? 'bottom' ), [ 'top', 'bottom' ], true ) ? sanitize_key( $input['desktop_horizontal_edge'] ?? $current['desktop_horizontal_edge'] ) : 'bottom',
			'tablet_vertical_edge' => in_array( $input['tablet_vertical_edge'] ?? ( $current['tablet_vertical_edge'] ?? 'right' ), [ 'left', 'right' ], true ) ? sanitize_key( $input['tablet_vertical_edge'] ?? $current['tablet_vertical_edge'] ) : 'right',
			'tablet_horizontal_edge' => in_array( $input['tablet_horizontal_edge'] ?? ( $current['tablet_horizontal_edge'] ?? 'bottom' ), [ 'top', 'bottom' ], true ) ? sanitize_key( $input['tablet_horizontal_edge'] ?? $current['tablet_horizontal_edge'] ) : 'bottom',
			'mobile_vertical_edge' => in_array( $input['mobile_vertical_edge'] ?? ( $current['mobile_vertical_edge'] ?? 'right' ), [ 'left', 'right' ], true ) ? sanitize_key( $input['mobile_vertical_edge'] ?? $current['mobile_vertical_edge'] ) : 'right',
			'mobile_horizontal_edge' => in_array( $input['mobile_horizontal_edge'] ?? ( $current['mobile_horizontal_edge'] ?? 'bottom' ), [ 'top', 'bottom' ], true ) ? sanitize_key( $input['mobile_horizontal_edge'] ?? $current['mobile_horizontal_edge'] ) : 'bottom',
			'desktop_vertical_position'   => in_array( $input['desktop_vertical_position'] ?? ( $current['desktop_vertical_position'] ?? 'center' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['desktop_vertical_position'] ?? $current['desktop_vertical_position'] ) : 'center',
			'desktop_horizontal_position' => in_array( $input['desktop_horizontal_position'] ?? ( $current['desktop_horizontal_position'] ?? 'right' ), [ 'left', 'center', 'right' ], true ) ? sanitize_key( $input['desktop_horizontal_position'] ?? $current['desktop_horizontal_position'] ) : 'right',
			'desktop_horizontal_vertical_position' => in_array( $input['desktop_horizontal_vertical_position'] ?? ( $current['desktop_horizontal_vertical_position'] ?? 'bottom' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['desktop_horizontal_vertical_position'] ?? $current['desktop_horizontal_vertical_position'] ) : 'bottom',
			'tablet_vertical_position'    => in_array( $input['tablet_vertical_position'] ?? ( $current['tablet_vertical_position'] ?? 'center' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['tablet_vertical_position'] ?? $current['tablet_vertical_position'] ) : 'center',
			'tablet_horizontal_position'  => in_array( $input['tablet_horizontal_position'] ?? ( $current['tablet_horizontal_position'] ?? 'center' ), [ 'left', 'center', 'right' ], true ) ? sanitize_key( $input['tablet_horizontal_position'] ?? $current['tablet_horizontal_position'] ) : 'center',
			'tablet_horizontal_vertical_position' => in_array( $input['tablet_horizontal_vertical_position'] ?? ( $current['tablet_horizontal_vertical_position'] ?? 'bottom' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['tablet_horizontal_vertical_position'] ?? $current['tablet_horizontal_vertical_position'] ) : 'bottom',
			'mobile_vertical_position'    => in_array( $input['mobile_vertical_position'] ?? ( $current['mobile_vertical_position'] ?? 'bottom' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['mobile_vertical_position'] ?? $current['mobile_vertical_position'] ) : 'bottom',
			'mobile_horizontal_position'  => in_array( $input['mobile_horizontal_position'] ?? ( $current['mobile_horizontal_position'] ?? 'right' ), [ 'left', 'center', 'right' ], true ) ? sanitize_key( $input['mobile_horizontal_position'] ?? $current['mobile_horizontal_position'] ) : 'right',
			'mobile_horizontal_vertical_position' => in_array( $input['mobile_horizontal_vertical_position'] ?? ( $current['mobile_horizontal_vertical_position'] ?? 'bottom' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['mobile_horizontal_vertical_position'] ?? $current['mobile_horizontal_vertical_position'] ) : 'bottom',
			'mobile_breakpoint'   => 640,
			'tablet_breakpoint'   => 1024,
			'enabled_items'       => $enabled,
			'item_order'          => $order,
			'custom_items'        => $custom_items,
			'menu_label'          => sanitize_text_field( $input['menu_label'] ?? ( $current['menu_label'] ?? 'Menu' ) ),
			'menu_url'            => esc_url_raw( $input['menu_url'] ?? ( $current['menu_url'] ?? '' ) ),
			'menu_nav_id'         => absint( $input['menu_nav_id'] ?? ( $current['menu_nav_id'] ?? 0 ) ),
			'menu_nav_ids'        => isset( $input['menu_nav_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $input['menu_nav_ids'] ) ) ) : (array) ( $current['menu_nav_ids'] ?? [] ),
			// Per-menu page guides belong to Navigation; legacy Dock saves preserve them.
			'menu_group_contents' => (array) ( $current['menu_group_contents'] ?? [] ),
			'menu_heading_tag'    => $heading,
			'menu_scale'          => in_array( $input['menu_scale'] ?? ( $current['menu_scale'] ?? 'compact' ), [ 'compact', 'balanced', 'expressive' ], true ) ? sanitize_key( $input['menu_scale'] ?? ( $current['menu_scale'] ?? 'compact' ) ) : 'compact',
			'menu_items'          => isset( $input['menu_items'] ) ? $this->sanitize_menu_items( $input['menu_items'] ) : (array) ( $current['menu_items'] ?? [] ),
			'menu_context_enabled' => array_key_exists( 'menu_context_enabled', $input ) ? ! empty( $input['menu_context_enabled'] ) : ! empty( $current['menu_context_enabled'] ),
			'menu_context_title' => sanitize_text_field( $input['menu_context_title'] ?? ( $current['menu_context_title'] ?? 'Table of contents' ) ),
			'menu_context_locations' => isset( $input['menu_context_locations'] ) ? $this->sanitize_menu_locations( $input['menu_context_locations'] ) : (array) ( $current['menu_context_locations'] ?? [] ),
			'menu_context_page_ids' => isset( $input['menu_context_page_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $input['menu_context_page_ids'] ) ) ) : (array) ( $current['menu_context_page_ids'] ?? [] ),
			'menu_context_heading_levels' => isset( $input['menu_context_heading_levels'] ) ? $this->sanitize_heading_levels( $input['menu_context_heading_levels'] ) : (array) ( $current['menu_context_heading_levels'] ?? [ 'h1', 'h2', 'h3' ] ),
			'admin_dashboard_link_enabled' => array_key_exists( 'admin_dashboard_link_enabled', $input ) ? ! empty( $input['admin_dashboard_link_enabled'] ) : ! empty( $current['admin_dashboard_link_enabled'] ),
			'phonekey_visibility' => in_array( $input['phonekey_visibility'] ?? ( $current['phonekey_visibility'] ?? 'all' ), [ 'all', 'visitors', 'users', 'customers', 'admins' ], true ) ? sanitize_key( $input['phonekey_visibility'] ?? $current['phonekey_visibility'] ) : 'all',
			'hide_frontend_admin_bar' => array_key_exists( 'hide_frontend_admin_bar', $input ) ? ! empty( $input['hide_frontend_admin_bar'] ) : ! empty( $current['hide_frontend_admin_bar'] ),
		];
	}

	public function sanitize_menu_settings( $input, array $current ): array {
		$input = is_array( $input ) ? wp_unslash( $input ) : [];
		$heading = sanitize_key( $input['menu_heading_tag'] ?? ( $current['menu_heading_tag'] ?? 'span' ) );
		if ( ! in_array( $heading, [ 'span', 'p', 'h1', 'h2', 'h3', 'h4' ], true ) ) {
			$heading = 'span';
		}
		$nav_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['menu_nav_ids'] ?? [] ) ) ) ) );
		$nav_ids = array_values( array_filter( $nav_ids, static fn( int $id ): bool => is_nav_menu( $id ) ) );
		$limits = [];
		foreach ( (array) ( $current['menu_group_limits'] ?? [] ) as $id=>$limit ) if ( absint( $id ) && is_nav_menu( absint( $id ) ) && is_numeric( $limit ) ) $limits[ absint( $id ) ] = max( 1, min( 200, (int) $limit ) );

		return [
			'menu_label'          => sanitize_text_field( $input['menu_label'] ?? 'Menu' ),
			'menu_heading_tag'    => $heading,
			'menu_scale'          => in_array( $input['menu_scale'] ?? ( $current['menu_scale'] ?? 'compact' ), [ 'compact', 'balanced', 'expressive' ], true ) ? sanitize_key( $input['menu_scale'] ?? ( $current['menu_scale'] ?? 'compact' ) ) : 'compact',
			'menu_nav_ids'        => $nav_ids,
			'menu_group_limits'   => $limits,
			'menu_nav_id'         => absint( $nav_ids[0] ?? 0 ),
			'menu_items'          => $this->sanitize_menu_items( $input['menu_items'] ?? [] ),
			'admin_dashboard_link_enabled' => ! empty( $input['admin_dashboard_link_enabled'] ),
			'menu_context_enabled' => ! empty( $input['menu_context_enabled'] ),
			'menu_context_title'   => sanitize_text_field( $input['menu_context_title'] ?? 'Table of contents' ),
			'menu_context_locations' => $this->sanitize_menu_locations( $input['menu_context_locations'] ?? [] ),
			'menu_context_page_ids' => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['menu_context_page_ids'] ?? [] ) ) ) ) ),
			'menu_context_heading_levels' => $this->sanitize_heading_levels( $input['menu_context_heading_levels'] ?? [ 'h1', 'h2', 'h3' ] ),
		];
	}

	public function sanitize_menu_locations( $locations ): array {
		$locations = is_array( $locations ) ? $locations : [];
		return [
			'everywhere'     => ! empty( $locations['everywhere'] ),
			'single_post'    => ! empty( $locations['single_post'] ),
			'single_product' => ! empty( $locations['single_product'] ),
			'front_page'     => ! empty( $locations['front_page'] ),
			'selected_pages' => ! empty( $locations['selected_pages'] ),
		];

	}

	public function sanitize_heading_levels( $levels ): array {
		$levels = array_values( array_intersect( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], array_map( 'sanitize_key', (array) $levels ) ) );
		return $levels ?: [ 'h1', 'h2', 'h3' ];
	}

	public function dock_custom_item_labels( array $items ): array {
		$labels = [];

		foreach ( $items as $item ) {
			$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}

			$labels[ $id ] = sanitize_text_field( (string) ( $item['label'] ?? $id ) );
		}

		return $labels;
	}

	public function dock_custom_items_for_admin( array $dock ): array {
		$items = isset( $dock['custom_items'] ) && is_array( $dock['custom_items'] ) ? $dock['custom_items'] : [];
		$out   = [];

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$url   = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			if ( '' === $label || '' === $url ) {
				continue;
			}

			$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id || 0 !== strpos( $id, 'link-' ) ) {
				$id = 'link-' . sanitize_title( $label );
			}
			if ( '' === $id || 'link-' === $id ) {
				$id = 'link-custom-' . ( (int) $index + 1 );
			}

			$out[] = [
				'id'      => $id,
				'label'   => $label,
				'url'     => $url,
				'icon'    => sanitize_key( (string) ( $item['icon'] ?? 'home' ) ) ?: 'home',
				'enabled' => ! empty( $item['enabled'] ),
			];
		}

		return array_slice( $out, 0, 12 );
	}

	public function sanitize_dock_custom_items( $items ): array {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$out  = [];
		$used = [];

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$url   = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			$icon  = sanitize_key( (string) ( $item['icon'] ?? 'home' ) ) ?: 'home';
			$id    = sanitize_key( (string) ( $item['id'] ?? '' ) );

			if ( '' === $label || '' === $url ) {
				continue;
			}

			if ( '' === $id || 0 !== strpos( $id, 'link-' ) ) {
				$id = 'link-' . sanitize_title( $label );
			}
			if ( '' === $id || 'link-' === $id ) {
				$id = 'link-custom-' . ( (int) $index + 1 );
			}

			$base = $id;
			$suffix = 2;
			while ( isset( $used[ $id ] ) ) {
				$id = $base . '-' . $suffix;
				$suffix++;
			}
			$used[ $id ] = true;

			$out[] = [
				'id'      => $id,
				'label'   => $label,
				'url'     => $url,
				'icon'    => $icon,
				'enabled' => true,
			];
		}

		return array_slice( $out, 0, 12 );
	}

	public function sanitize_menu_items( $items ): array {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$output = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title = sanitize_text_field( $item['title'] ?? '' );
			$url   = esc_url_raw( $item['url'] ?? '' );
			$type  = sanitize_text_field( $item['type'] ?? '' );
			$image = esc_url_raw( $item['image'] ?? '' );
			$object_id = absint( $item['object_id'] ?? 0 );
			$object_type = sanitize_key( $item['object_type'] ?? '' );

			if ( '' === $title || '' === $url ) {
				continue;
			}

			$output[] = [
				'title' => $title,
				'url'   => $url,
				'type'  => $type,
				'image' => $image,
				'object_id'   => $object_id,
				'object_type' => $object_type,
			];
		}

		return array_slice( $output, 0, 20 );
	}

	public function sanitize_style_settings( $input, array $current ): array {
		$defaults = ( new \DSA\Settings() )->defaults()['style'];
		if ( ! is_array( $input ) ) {
			return wp_parse_args( $current, $defaults );
		}

		$input = wp_unslash( $input );
		$visual_profile = sanitize_key( (string) ( $input['visual_profile'] ?? ( $current['visual_profile'] ?? 'legacy' ) ) );
		$mode = sanitize_key( (string) ( $input['mode'] ?? ( $current['mode'] ?? 'classic' ) ) );
		$sheet_position = sanitize_key( (string) ( $input['sheet_position'] ?? ( $current['sheet_position'] ?? 'bottom' ) ) );
		$sheet_animation = sanitize_key( (string) ( $input['sheet_animation'] ?? ( $current['sheet_animation'] ?? 'slide' ) ) );
		$sheet_backdrop = sanitize_key( (string) ( $input['sheet_backdrop'] ?? ( $current['sheet_backdrop'] ?? 'blur' ) ) );
		$sheet_spacing = sanitize_key( (string) ( $input['sheet_spacing'] ?? ( $current['sheet_spacing'] ?? 'edge' ) ) );
		$sheet_origin = sanitize_key( (string) ( $input['sheet_origin'] ?? ( $current['sheet_origin'] ?? 'bottom' ) ) );
		$screen_heading_tag = sanitize_key( (string) ( $input['screen_heading_tag'] ?? ( $current['screen_heading_tag'] ?? 'h2' ) ) );
		$active_theme_id = ( new Theme_Package_Service() )->sanitize_id( (string) ( $input['active_theme_id'] ?? ( $current['active_theme_id'] ?? ( in_array( $visual_profile, [ 'prototype', 'kiwe2027', 'kiwe-2027' ], true ) ? 'kiwe2027' : 'legacy' ) ) ) );
		if ( '' === $active_theme_id ) {
			$active_theme_id = in_array( $visual_profile, [ 'prototype', 'kiwe2027', 'kiwe-2027' ], true ) ? 'kiwe2027' : 'legacy';
		}
		return [
			'visual_profile'    => in_array( $visual_profile, [ 'prototype', 'kiwe2027', 'kiwe-2027' ], true ) ? 'kiwe2027' : 'legacy',
			'mode'              => in_array( $mode, [ 'classic', 'sheet' ], true ) ? $mode : 'classic',
			'sheet_position'    => in_array( $sheet_position, [ 'bottom', 'right', 'left' ], true ) ? $sheet_position : 'bottom',
			'sheet_animation'   => in_array( $sheet_animation, [ 'slide', 'soft', 'snap' ], true ) ? $sheet_animation : 'slide',
			'sheet_backdrop'    => in_array( $sheet_backdrop, [ 'blur', 'fade', 'none' ], true ) ? $sheet_backdrop : 'blur',
			'sheet_duration_ms' => max( 120, min( 900, absint( $input['sheet_duration_ms'] ?? ( $current['sheet_duration_ms'] ?? 320 ) ) ) ),
			'sheet_max_height'  => max( 45, min( 96, absint( $input['sheet_max_height'] ?? ( $current['sheet_max_height'] ?? 82 ) ) ) ),
			'sheet_spacing'     => in_array( $sheet_spacing, [ 'edge', 'inset' ], true ) ? $sheet_spacing : 'edge',
			'sheet_origin'      => in_array( $sheet_origin, [ 'bottom', 'above_dock' ], true ) ? $sheet_origin : 'bottom',
			'sheet_width_percent' => max( 50, min( 90, absint( $input['sheet_width_percent'] ?? ( $current['sheet_width_percent'] ?? 78 ) ) ) ),
			'sheet_side_max_width' => max( 280, min( 1200, absint( $input['sheet_side_max_width'] ?? ( $current['sheet_side_max_width'] ?? 460 ) ) ) ),
			'screen_heading_tag' => in_array( $screen_heading_tag, [ 'h1', 'h2', 'h3', 'h4', 'p', 'span' ], true ) ? $screen_heading_tag : 'h2',
			'active_theme_id'   => $active_theme_id,
		];
	}

	public function sanitize_dsa_theme( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$active = sanitize_hex_color( $input['active_color'] ?? ( $current['active_color'] ?? '' ) );
		$hover = sanitize_hex_color( $input['hover_color'] ?? ( $current['hover_color'] ?? '' ) );
		$hero = trim( (string) ( $input['hero_text_color'] ?? ( $current['hero_text_color'] ?? '' ) ) );

		if ( ! preg_match( '/^(#[0-9a-f]{3,6}|rgba?\([^)]+\))$/i', $hero ) ) {
			$hero = 'rgba(20,24,34,0.18)';
		}

		return [
			'active_color'          => $active ?: '#8f8f98',
			'hover_color'           => $hover ?: '#24c6a1',
			'hero_text_color'       => $hero,
			'confetti_color_source' => in_array( $input['confetti_color_source'] ?? ( $current['confetti_color_source'] ?? '' ), [ 'hero', 'active', 'hover' ], true ) ? sanitize_key( $input['confetti_color_source'] ?? $current['confetti_color_source'] ) : 'hero',
		];
	}
}
