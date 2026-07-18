<?php

namespace DSA\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Theme_Package_Service {
	private const OPTION  = 'dsa_installed_themes';
	private const MAX     = 24;
	private const MAX_CSS = 81920;

	public function all(): array {
		return array_merge( $this->builtins(), $this->records() );
	}

	public function custom_records(): array {
		return $this->records();
	}

	public function find( string $id ): array {
		$id = $this->sanitize_id( $id );
		foreach ( $this->all() as $record ) {
			if ( $id === (string) ( $record['id'] ?? '' ) ) {
				return $record;
			}
		}

		return [];
	}

	public function install( array $payload, array $context = [] ): array {
		$theme = isset( $payload['theme'] ) && is_array( $payload['theme'] ) ? $payload['theme'] : $payload;
		$id    = $this->sanitize_id( (string) ( $theme['id'] ?? $payload['id'] ?? '' ) );
		if ( '' === $id ) {
			return $this->failure( 'missing_id', __( 'Theme package is missing a theme id.', 'dsa' ) );
		}
		if ( in_array( $id, [ 'legacy', 'kiwe2027' ], true ) ) {
			return $this->failure( 'reserved_id', __( 'Built-in theme ids cannot be overwritten.', 'dsa' ) );
		}

		$name = sanitize_text_field( (string) ( $theme['name'] ?? $payload['name'] ?? $id ) );
		$css  = $this->extract_css( $payload );
		$css_error = $this->validate_css( $css );
		if ( '' !== $css_error ) {
			return $this->failure( 'invalid_css', $css_error );
		}

		$settings = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : [];
		$record   = [
			'id'          => $id,
			'schema'      => 'kiwe.installed-theme.v1',
			'name'        => '' !== $name ? $name : $id,
			'version'     => sanitize_text_field( (string) ( $theme['version'] ?? $payload['version'] ?? '1.0.0' ) ),
			'description' => sanitize_text_field( (string) ( $theme['description'] ?? $payload['description'] ?? '' ) ),
			'author'      => sanitize_text_field( (string) ( $theme['author'] ?? $payload['author'] ?? '' ) ),
			'profile'     => sanitize_key( (string) ( $theme['profile'] ?? 'marketplace' ) ),
			'manifest'    => $this->sanitize_manifest( $theme ),
			'settings'    => $this->sanitize_setting_subset( $settings ),
			'css'         => $css,
			'createdAt'   => sanitize_text_field( (string) ( $context['createdAt'] ?? gmdate( 'c' ) ) ),
			'createdBy'   => isset( $context['userId'] ) ? absint( $context['userId'] ) : 0,
			'updatedAt'   => gmdate( 'c' ),
		];

		$records = array_values( array_filter( $this->records(), static fn( array $candidate ): bool => (string) ( $candidate['id'] ?? '' ) !== $id ) );
		array_unshift( $records, $record );
		update_option( self::OPTION, array_slice( $records, 0, self::MAX ), false );

		return [
			'ok'     => true,
			'record' => $this->public_record( $record ),
		];
	}

	public function delete( string $id ): bool {
		$id = $this->sanitize_id( $id );
		if ( in_array( $id, [ 'legacy', 'kiwe2027' ], true ) ) {
			return false;
		}
		$records = $this->records();
		$next    = array_values( array_filter( $records, static fn( array $record ): bool => (string) ( $record['id'] ?? '' ) !== $id ) );
		if ( count( $next ) === count( $records ) ) {
			return false;
		}
		update_option( self::OPTION, $next, false );

		return true;
	}

	public function export_payload( array $settings, string $id = '' ): array {
		$id     = $this->sanitize_id( $id );
		$active = '' !== $id ? $this->find( $id ) : [];
		if ( [] === $active ) {
			$active = $this->active( $settings );
		}
		$theme_settings = $this->theme_settings_from_site( $settings );

		if ( [] !== $active && ! in_array( (string) ( $active['id'] ?? '' ), [ 'legacy', 'kiwe2027' ], true ) ) {
			$theme_settings = isset( $active['settings'] ) && is_array( $active['settings'] ) ? $active['settings'] : $theme_settings;
		}

		return [
			'type'          => 'kiwe-theme-package',
			'schema'        => 'kiwe.theme-package.v1',
			'schemaVersion' => 1,
			'pluginVersion' => defined( 'DSA_VERSION' ) ? DSA_VERSION : '',
			'exportedAt'    => gmdate( 'c' ),
			'theme'         => isset( $active['manifest'] ) && is_array( $active['manifest'] ) ? $active['manifest'] : $this->manifest_from_record( $active ),
			'settings'      => $theme_settings,
			'css'           => (string) ( $active['css'] ?? '' ),
		];
	}

	public function safe_settings_overlay( array $record, array $current ): array {
		$id       = $this->sanitize_id( (string) ( $record['id'] ?? 'legacy' ) );
		$profile  = sanitize_key( (string) ( $record['profile'] ?? '' ) );
		$settings = isset( $record['settings'] ) && is_array( $record['settings'] ) ? $record['settings'] : [];
		$next     = $current;

		$style = isset( $current['style'] ) && is_array( $current['style'] ) ? $current['style'] : [];
		if ( isset( $settings['style'] ) && is_array( $settings['style'] ) ) {
			foreach ( [ 'mode', 'sheet_position', 'sheet_animation', 'sheet_backdrop', 'sheet_spacing', 'sheet_origin', 'screen_heading_tag' ] as $key ) {
				if ( isset( $settings['style'][ $key ] ) ) {
					$style[ $key ] = sanitize_key( (string) $settings['style'][ $key ] );
				}
			}
			foreach ( [ 'sheet_duration_ms', 'sheet_max_height', 'sheet_width_percent' ] as $key ) {
				if ( isset( $settings['style'][ $key ] ) ) {
					$style[ $key ] = absint( $settings['style'][ $key ] );
				}
			}
		}
		$style['active_theme_id'] = $id;
		if ( 'legacy' === $id || 'legacy' === $profile ) {
			$style['visual_profile'] = 'legacy';
		} elseif ( 'kiwe2027' === $id || in_array( $profile, [ 'kiwe-2027', 'kiwe2027', 'prototype' ], true ) ) {
			$style['visual_profile'] = 'kiwe2027';
		}
		$next['style'] = $style;

		if ( isset( $settings['dock'] ) && is_array( $settings['dock'] ) ) {
			$dock = isset( $current['dock'] ) && is_array( $current['dock'] ) ? $current['dock'] : [];
			foreach ( [ 'presentation', 'shape', 'material', 'focus_item', 'desktop_orientation', 'tablet_orientation', 'mobile_orientation', 'desktop_vertical_edge', 'desktop_horizontal_edge', 'tablet_vertical_edge', 'tablet_horizontal_edge', 'mobile_vertical_edge', 'mobile_horizontal_edge', 'desktop_vertical_position', 'desktop_horizontal_position', 'desktop_horizontal_vertical_position', 'tablet_vertical_position', 'tablet_horizontal_position', 'tablet_horizontal_vertical_position', 'mobile_vertical_position', 'mobile_horizontal_position', 'mobile_horizontal_vertical_position' ] as $key ) {
				if ( isset( $settings['dock'][ $key ] ) ) {
					$dock[ $key ] = sanitize_key( (string) $settings['dock'][ $key ] );
				}
			}
			foreach ( [ 'split_style', 'context_rail_enabled' ] as $key ) {
				if ( array_key_exists( $key, $settings['dock'] ) ) {
					$dock[ $key ] = ! empty( $settings['dock'][ $key ] );
				}
			}
			if ( isset( $settings['dock']['enabled_items'] ) && is_array( $settings['dock']['enabled_items'] ) ) {
				$dock['enabled_items'] = array_map( static fn( $value ): bool => ! empty( $value ), $settings['dock']['enabled_items'] );
			}
			if ( isset( $settings['dock']['item_order'] ) && is_array( $settings['dock']['item_order'] ) ) {
				$dock['item_order'] = array_values( array_map( 'sanitize_key', $settings['dock']['item_order'] ) );
			}
			if ( isset( $settings['dock']['custom_items'] ) && is_array( $settings['dock']['custom_items'] ) ) {
				$dock['custom_items'] = $this->sanitize_dock_custom_items( $settings['dock']['custom_items'] );
				if ( ! isset( $dock['enabled_items'] ) || ! is_array( $dock['enabled_items'] ) ) {
					$dock['enabled_items'] = [];
				}
				foreach ( $dock['custom_items'] as $item ) {
					$item_id = sanitize_key( (string) ( $item['id'] ?? '' ) );
					if ( '' !== $item_id && ! isset( $dock['enabled_items'][ $item_id ] ) ) {
						$dock['enabled_items'][ $item_id ] = ! empty( $item['enabled'] );
					}
				}
			}
			$next['dock'] = $dock;
		}

		if ( isset( $settings['dsa_theme'] ) && is_array( $settings['dsa_theme'] ) ) {
			$theme = isset( $current['dsa_theme'] ) && is_array( $current['dsa_theme'] ) ? $current['dsa_theme'] : [];
			foreach ( [ 'active_color', 'hover_color' ] as $key ) {
				if ( isset( $settings['dsa_theme'][ $key ] ) ) {
					$value = sanitize_hex_color( (string) $settings['dsa_theme'][ $key ] );
					if ( $value ) {
						$theme[ $key ] = $value;
					}
				}
			}
			if ( isset( $settings['dsa_theme']['hero_text_color'] ) && preg_match( '/^(#[0-9a-f]{3,6}|rgba?\([^)]+\))$/i', (string) $settings['dsa_theme']['hero_text_color'] ) ) {
				$theme['hero_text_color'] = (string) $settings['dsa_theme']['hero_text_color'];
			}
			$next['dsa_theme'] = $theme;
		}

		if ( isset( $settings['visual_effects'] ) && is_array( $settings['visual_effects'] ) ) {
			$visual = isset( $current['visual_effects'] ) && is_array( $current['visual_effects'] ) ? $current['visual_effects'] : [];
			foreach ( [ 'blur_strength', 'min_loader_ms', 'artificial_delay_ms' ] as $key ) {
				if ( isset( $settings['visual_effects'][ $key ] ) ) {
					$visual[ $key ] = absint( $settings['visual_effects'][ $key ] );
				}
			}
			foreach ( [ 'blur_type', 'glass_intensity', 'screen_material', 'screen_animation', 'loader_type' ] as $key ) {
				if ( isset( $settings['visual_effects'][ $key ] ) ) {
					$visual[ $key ] = sanitize_key( (string) $settings['visual_effects'][ $key ] );
				}
			}
			foreach ( [ 'show_on_overlay_open', 'show_on_navigation', 'show_on_page_in', 'show_on_page_out' ] as $key ) {
				if ( array_key_exists( $key, $settings['visual_effects'] ) ) {
					$visual[ $key ] = ! empty( $settings['visual_effects'][ $key ] );
				}
			}
			$next['visual_effects'] = $visual;
		}

		return $next;
	}

	public function active( array $settings ): array {
		$style = isset( $settings['style'] ) && is_array( $settings['style'] ) ? $settings['style'] : [];
		$id    = $this->sanitize_id( (string) ( $style['active_theme_id'] ?? '' ) );
		if ( '' !== $id ) {
			$record = $this->find( $id );
			if ( [] !== $record ) {
				return $record;
			}
		}
		$profile = sanitize_key( (string) ( $style['visual_profile'] ?? 'legacy' ) );

		return $this->find( in_array( $profile, [ 'prototype', 'kiwe2027', 'kiwe-2027' ], true ) ? 'kiwe2027' : 'legacy' );
	}

	public function active_css( array $settings ): string {
		$active = $this->active( $settings );

		return (string) ( $active['css'] ?? '' );
	}

	public function public_record( array $record ): array {
		return [
			'id'          => (string) ( $record['id'] ?? '' ),
			'name'        => (string) ( $record['name'] ?? '' ),
			'version'     => (string) ( $record['version'] ?? '' ),
			'description' => (string) ( $record['description'] ?? '' ),
			'author'      => (string) ( $record['author'] ?? '' ),
			'profile'     => (string) ( $record['profile'] ?? 'marketplace' ),
			'builtIn'     => ! empty( $record['builtIn'] ),
			'hasCss'      => '' !== (string) ( $record['css'] ?? '' ),
			'hasSettings' => ! empty( $record['settings'] ) && is_array( $record['settings'] ),
			'createdAt'   => (string) ( $record['createdAt'] ?? '' ),
			'updatedAt'   => (string) ( $record['updatedAt'] ?? '' ),
		];
	}

	public function sanitize_id( string $id ): string {
		$id = strtolower( trim( $id ) );
		$id = preg_replace( '/[^a-z0-9.-]+/', '-', $id );
		$id = trim( (string) $id, '.-' );

		return substr( $id, 0, 80 );
	}

	private function records(): array {
		$records = get_option( self::OPTION, [] );
		if ( ! is_array( $records ) ) {
			return [];
		}

		return array_values( array_filter( $records, static fn( $record ): bool => is_array( $record ) && ! empty( $record['id'] ) ) );
	}

	private function builtins(): array {
		return [
			[
				'id'          => 'legacy',
				'schema'      => 'kiwe.installed-theme.v1',
				'name'        => __( 'Legacy UI', 'dsa' ),
				'version'     => defined( 'DSA_VERSION' ) ? DSA_VERSION : '1.0.0',
				'description' => __( 'The preserved lightweight baseline.', 'dsa' ),
				'author'      => 'Kiwe',
				'profile'     => 'legacy',
				'builtIn'     => true,
				'settings'    => [ 'style' => [ 'visual_profile' => 'legacy', 'active_theme_id' => 'legacy' ] ],
				'css'         => '',
			],
			[
				'id'          => 'kiwe2027',
				'schema'      => 'kiwe.installed-theme.v1',
				'name'        => __( 'Kiwe 2027', 'dsa' ),
				'version'     => defined( 'DSA_VERSION' ) ? DSA_VERSION : '1.0.0',
				'description' => __( 'The built-in modern app UI track.', 'dsa' ),
				'author'      => 'Kiwe',
				'profile'     => 'kiwe-2027',
				'builtIn'     => true,
				'settings'    => [ 'style' => [ 'visual_profile' => 'kiwe2027', 'active_theme_id' => 'kiwe2027' ] ],
				'css'         => '',
			],
		];
	}

	private function extract_css( array $payload ): string {
		if ( isset( $payload['css'] ) && is_string( $payload['css'] ) ) {
			return trim( $payload['css'] );
		}
		if ( isset( $payload['cssInline'] ) && is_string( $payload['cssInline'] ) ) {
			return trim( $payload['cssInline'] );
		}
		if ( isset( $payload['files'] ) && is_array( $payload['files'] ) ) {
			foreach ( [ 'css/theme.css', 'theme.css', 'import/css/theme.css' ] as $key ) {
				if ( isset( $payload['files'][ $key ] ) && is_string( $payload['files'][ $key ] ) ) {
					return trim( $payload['files'][ $key ] );
				}
			}
		}

		return '';
	}

	private function validate_css( string $css ): string {
		if ( strlen( $css ) > self::MAX_CSS ) {
			return __( 'Theme CSS exceeds the Kiwe import budget.', 'dsa' );
		}
		if ( preg_match( '/<\/style|@import|javascript:|expression\s*\(|url\s*\(\s*[\'"]?\s*(https?:|\/\/)/i', $css ) ) {
			return __( 'Theme CSS contains remote, executable, or style-breaking content.', 'dsa' );
		}
		if ( preg_match( '/(?:data-dsa-dock|dsa-dock|data-dsa-screen|dsa-panel|dsa-sheet|screen-backdrop)[^{]{0,120}\{[^}]*\b(position\s*:\s*(fixed|absolute)|inset\s*:|z-index\s*:|width\s*:\s*100vw|height\s*:\s*100vh)/is', $css ) ) {
			return __( 'Theme CSS attempts to own protected AppShell geometry.', 'dsa' );
		}

		return '';
	}

	private function sanitize_manifest( array $theme ): array {
		return [
			'schema'      => 'kiwe.surface-theme.v1',
			'id'          => $this->sanitize_id( (string) ( $theme['id'] ?? '' ) ),
			'name'        => sanitize_text_field( (string) ( $theme['name'] ?? '' ) ),
			'version'     => sanitize_text_field( (string) ( $theme['version'] ?? '1.0.0' ) ),
			'profile'     => sanitize_key( (string) ( $theme['profile'] ?? 'marketplace' ) ),
			'description' => sanitize_text_field( (string) ( $theme['description'] ?? '' ) ),
			'author'      => sanitize_text_field( (string) ( $theme['author'] ?? '' ) ),
			'screens'     => array_values( array_map( 'sanitize_key', isset( $theme['screens'] ) && is_array( $theme['screens'] ) ? $theme['screens'] : [] ) ),
			'supports'    => array_values( array_map( 'sanitize_key', isset( $theme['supports'] ) && is_array( $theme['supports'] ) ? $theme['supports'] : [] ) ),
			'requires'    => isset( $theme['requires'] ) && is_array( $theme['requires'] ) ? $theme['requires'] : [ 'uiContract' => 'kiwe.surface-ui.v2', 'tokenContract' => 'kiwe.universal' ],
		];
	}

	private function manifest_from_record( array $record ): array {
		return [
			'schema'      => 'kiwe.surface-theme.v1',
			'id'          => (string) ( $record['id'] ?? 'legacy' ),
			'name'        => (string) ( $record['name'] ?? 'Kiwe Theme' ),
			'version'     => (string) ( $record['version'] ?? '1.0.0' ),
			'profile'     => (string) ( $record['profile'] ?? 'legacy' ),
			'description' => (string) ( $record['description'] ?? '' ),
			'author'      => (string) ( $record['author'] ?? 'Kiwe' ),
			'screens'     => [ 'profile', 'cart', 'checkout', 'search', 'menu', 'saved', 'links', 'notifications', 'ios-install', 'games', 'ai' ],
			'supports'    => [ 'light', 'dark', 'sheet', 'classic', 'dock', 'split-dock', 'full-dock', 'navigation-bar', 'horizontal', 'vertical' ],
			'requires'    => [ 'uiContract' => 'kiwe.surface-ui.v2', 'tokenContract' => 'kiwe.universal', 'minKiwe' => defined( 'DSA_VERSION' ) ? DSA_VERSION : '0.0.0' ],
		];
	}

	private function sanitize_setting_subset( array $settings ): array {
		$allowed = [ 'style', 'dock', 'dsa_theme', 'visual_effects' ];
		$out     = [];
		foreach ( $allowed as $key ) {
			if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
				$out[ $key ] = $settings[ $key ];
			}
		}

		return $out;
	}

	private function sanitize_dock_custom_items( array $items ): array {
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

			$base   = $id;
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
				'enabled' => ! empty( $item['enabled'] ),
			];
		}

		return array_slice( $out, 0, 12 );
	}

	private function theme_settings_from_site( array $settings ): array {
		return $this->sanitize_setting_subset(
			[
				'style'          => isset( $settings['style'] ) && is_array( $settings['style'] ) ? $settings['style'] : [],
				'dock'           => isset( $settings['dock'] ) && is_array( $settings['dock'] ) ? $settings['dock'] : [],
				'dsa_theme'      => isset( $settings['dsa_theme'] ) && is_array( $settings['dsa_theme'] ) ? $settings['dsa_theme'] : [],
				'visual_effects' => isset( $settings['visual_effects'] ) && is_array( $settings['visual_effects'] ) ? $settings['visual_effects'] : [],
			]
		);
	}

	private function failure( string $code, string $message ): array {
		return [
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
		];
	}
}
