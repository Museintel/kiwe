<?php

namespace DSA\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Module_Registry {
	/** @var array<string,array> */
	private array $modules = [];

	public function register_defaults(): void {
		$this->register(
			[
				'id'      => 'menu',
				'label'   => __( 'Menu', 'dsa' ),
				'icon'    => $this->lucide_icon( 'grip' ),
				'order'   => 10,
				'panel'   => 'menu',
				'binder'  => '',
				'dismiss' => 'outside_safe',
			]
		);

		$this->register(
			[
				'id'      => 'search',
				'label'   => __( 'Search', 'dsa' ),
				'icon'    => $this->lucide_icon( 'search' ),
				'order'   => 15,
				'panel'   => 'search',
				'binder'  => 'search',
				'dismiss' => 'outside_safe',
			]
		);

		$this->register(
			[
				'id'         => 'profile',
				'label'      => __( 'Profile', 'dsa' ),
				'icon'       => $this->lucide_icon( 'user-round' ),
				'order'      => 20,
				'panel'      => 'profile',
				'binder'     => 'profile',
				'dismiss'    => 'outside_safe',
				'visibility' => 'phonekey_scope',
			]
		);

		$this->register(
			[
				'id'      => 'links',
				'label'   => __( 'Links', 'dsa' ),
				'icon'    => $this->lucide_icon( 'share-2' ),
				'order'   => 30,
				'panel'   => 'links',
				'binder'  => 'links',
				'dismiss' => 'outside_safe',
			]
		);

		$this->register(
			[
				'id'      => 'cart',
				'label'   => __( 'Cart', 'dsa' ),
				'icon'    => $this->lucide_icon( 'shopping-bag' ),
				'order'   => 40,
				'panel'   => 'cart',
				'binder'  => '',
				'dismiss' => 'outside_safe',
			]
		);

		$this->register(
			[
				'id'      => 'saved',
				'label'   => __( 'Saved', 'dsa' ),
				'icon'    => $this->lucide_icon( 'bookmark' ),
				'order'   => 35,
				'panel'   => 'saved',
				'binder'  => 'saved',
				'dismiss' => 'outside_safe',
			]
		);

		$this->register(
			[
				'id'      => 'theme',
				'label'   => __( 'Light / dark mode', 'dsa' ),
				'icon'    => $this->lucide_icon( 'sun-moon' ),
				'order'   => 50,
				'mode'    => 'action',
				'panel'   => '',
				'binder'  => '',
				'dismiss' => 'none',
			]
		);

		$this->register(
			[
				'id'          => 'ai',
				'label'       => __( 'AI Assistant', 'dsa' ),
				'icon'        => $this->lucide_icon( 'sparkles' ),
				'order'       => 90,
				'panel'       => 'ai',
				'binder'      => '',
				'dismiss'     => 'outside_safe',
				'visibility'  => 'all',
				'admin_only'  => false,
				'requires_ai' => false,
			]
		);

		$this->register(
			[
				'id'             => 'secure',
				'label'          => __( 'Secure', 'dsa' ),
				'icon'           => $this->lucide_icon( 'shield-check' ),
				'order'          => 60,
				'mode'           => 'internal',
				'panel'          => 'secure',
				'binder'         => '',
				'dismiss'        => 'outside_safe',
				'visibility'     => 'admins',
				'admin_only'     => true,
				'requires_secure' => true,
				'protected_flow' => 'security_actions',
			]
		);
	}

	private function lucide_icon( string $name ): string {
		$name = sanitize_key( $name );
		if ( 'house' === $name ) {
			$name = 'home';
		}
		if ( 'home' === $name ) {
			return '<svg class="dsa-lucide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m3 11 9-8 9 8"></path><path d="M5 10v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V10"></path><path d="M9 21v-6a3 3 0 0 1 6 0v6"></path></svg>';
		}
		if ( 'external-link' === $name ) {
			return '<svg class="dsa-lucide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 3h6v6"></path><path d="M10 14 21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path></svg>';
		}
		$href = esc_url( DSA_URL . 'assets/icons/lucide/sprite.svg' ) . '#' . $name;
		return '<svg class="dsa-lucide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="' . esc_attr( $href ) . '"></use></svg>';
	}

	public function register( array $module ): void {
		if ( empty( $module['id'] ) ) {
			return;
		}

		$defaults = [
			'type'           => 'overlay-panel',
			'icon'           => 'circle',
			'label'          => $module['id'],
			'public'         => true,
			'requires_ai'    => false,
			'min_capability' => 'edit_posts',
			'mode'           => 'dock',
			'panel'          => sanitize_key( $module['id'] ),
			'binder'         => '',
			'dismiss'        => 'outside_safe',
			'visibility'     => 'all',
			'order'          => 100,
			'admin_only'     => false,
			'requires_secure' => false,
			'protected_flow' => '',
			'settings'       => [],
		];

		$module = wp_parse_args( $module, $defaults );

		$this->modules[ sanitize_key( $module['id'] ) ] = $module;
	}

	public function get( string $id ): ?array {
		$id = sanitize_key( $id );

		return $this->modules[ $id ] ?? null;
	}

	public function all(): array {
		return $this->modules;
	}

	public function public_modules(): array {
		return array_filter(
			$this->modules,
			static function ( array $module ): bool {
				return ! empty( $module['public'] );
			}
		);
	}

	public function dock_modules( array $dock, array $context = [] ): array {
		$enabled = array_replace(
			[
				'menu'    => true,
				'search'  => true,
				'profile' => true,
				'links'   => true,
				'saved'   => true,
				'cart'    => true,
				'theme'   => true,
				'ai'      => true,
				'secure'  => true,
			],
			isset( $dock['enabled_items'] ) && is_array( $dock['enabled_items'] ) ? $dock['enabled_items'] : []
		);
		$badges = isset( $context['badges'] ) && is_array( $context['badges'] ) ? $context['badges'] : [];
		$labels = isset( $context['labels'] ) && is_array( $context['labels'] ) ? $context['labels'] : [];
		$modules = array_merge( $this->public_modules(), $this->custom_link_modules( $dock ) );
		$order = isset( $dock['item_order'] ) && is_array( $dock['item_order'] )
			? array_values( array_unique( array_map( 'sanitize_key', $dock['item_order'] ) ) )
			: [];
		$positions = array_flip( $order );

		uasort(
			$modules,
			static function ( array $a, array $b ) use ( $positions ): int {
				$a_id = sanitize_key( (string) ( $a['id'] ?? '' ) );
				$b_id = sanitize_key( (string) ( $b['id'] ?? '' ) );
				$a_position = $positions[ $a_id ] ?? PHP_INT_MAX;
				$b_position = $positions[ $b_id ] ?? PHP_INT_MAX;
				if ( $a_position !== $b_position ) return $a_position <=> $b_position;
				return (int) ( $a['order'] ?? 100 ) <=> (int) ( $b['order'] ?? 100 );
			}
		);

		$out = [];

		foreach ( $modules as $id => $module ) {
			$mode = sanitize_key( $module['mode'] ?? 'dock' );
			$default_enabled = 'link' === $mode ? ! empty( $module['enabled'] ) : true;
			if ( ! in_array( $mode, [ 'dock', 'action', 'link' ], true ) || empty( $enabled[ $id ] ?? $default_enabled ) || ! $this->module_visible( $module, $context ) ) {
				continue;
			}

			$out[] = [
				'id'             => $id,
				'label'          => sanitize_text_field( $labels[ $id ] ?? $module['label'] ),
				'icon'           => (string) $module['icon'],
				'badge'          => $badges[ $id ] ?? '',
				'mode'           => sanitize_key( $module['mode'] ?? 'dock' ),
				'panel'          => sanitize_key( $module['panel'] ?? $id ),
				'binder'         => sanitize_key( $module['binder'] ?? '' ),
				'dismiss'        => sanitize_key( $module['dismiss'] ?? 'outside_safe' ),
				'visibility'     => sanitize_key( $module['visibility'] ?? 'all' ),
				'protected_flow' => sanitize_key( $module['protected_flow'] ?? '' ),
				'url'            => esc_url_raw( (string) ( $module['url'] ?? '' ) ),
			];
		}

		return $out;
	}

	public function frontend_contract( array $dock, array $context = [] ): array {
		return [
			'version' => 1,
			'items'   => array_map(
				static function ( array $module ): array {
					unset( $module['icon'], $module['badge'] );
					return $module;
				},
				$this->dock_modules( $dock, $context )
			),
		];
	}

	public function manifest_contract( array $dock ): array {
		$enabled = array_replace(
			[
				'menu'    => true,
				'search'  => true,
				'profile' => true,
				'links'   => true,
				'saved'   => true,
				'cart'    => true,
				'theme'   => true,
				'ai'      => true,
				'secure'  => true,
			],
			isset( $dock['enabled_items'] ) && is_array( $dock['enabled_items'] ) ? $dock['enabled_items'] : []
		);
		$modules = array_merge( $this->public_modules(), $this->custom_link_modules( $dock ) );
		$order = isset( $dock['item_order'] ) && is_array( $dock['item_order'] )
			? array_values( array_unique( array_map( 'sanitize_key', $dock['item_order'] ) ) )
			: [];
		$positions = array_flip( $order );

		uasort(
			$modules,
			static function ( array $a, array $b ) use ( $positions ): int {
				$a_id = sanitize_key( (string) ( $a['id'] ?? '' ) );
				$b_id = sanitize_key( (string) ( $b['id'] ?? '' ) );
				$a_position = $positions[ $a_id ] ?? PHP_INT_MAX;
				$b_position = $positions[ $b_id ] ?? PHP_INT_MAX;
				if ( $a_position !== $b_position ) return $a_position <=> $b_position;
				return (int) ( $a['order'] ?? 100 ) <=> (int) ( $b['order'] ?? 100 );
			}
		);

		$out = [];
		$can_manage = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );

		foreach ( $modules as $id => $module ) {
			$admin_only = 'admins' === sanitize_key( $module['visibility'] ?? 'all' ) || ! empty( $module['admin_only'] );
			$mode       = sanitize_key( $module['mode'] ?? 'dock' );
			$default_enabled = 'link' === $mode ? ! empty( $module['enabled'] ) : true;

			if ( $admin_only && ! $can_manage ) {
				continue;
			}

			$out[] = [
				'id'             => sanitize_key( $id ),
				'label'          => sanitize_text_field( $module['label'] ?? $id ),
				'enabled'        => ! empty( $enabled[ $id ] ?? $default_enabled ),
				'mode'           => $mode,
				'panel'          => sanitize_key( $module['panel'] ?? $id ),
				'binder'         => sanitize_key( $module['binder'] ?? '' ),
				'dismiss'        => sanitize_key( $module['dismiss'] ?? 'outside_safe' ),
				'visibility'     => sanitize_key( $module['visibility'] ?? 'all' ),
				'protected_flow' => sanitize_key( $module['protected_flow'] ?? '' ),
				'url'            => esc_url_raw( (string) ( $module['url'] ?? '' ) ),
			];
		}

		return [
			'version' => 1,
			'items'   => $out,
		];
	}

	/**
	 * @return array<string,array>
	 */
	private function custom_link_modules( array $dock ): array {
		$items = isset( $dock['custom_items'] ) && is_array( $dock['custom_items'] ) ? $dock['custom_items'] : [];
		$out   = [];

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id    = sanitize_key( (string) ( $item['id'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$url   = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			$icon  = sanitize_key( (string) ( $item['icon'] ?? 'external-link' ) );

			if ( '' === $label || '' === $url ) {
				continue;
			}

			if ( '' === $id || 0 !== strpos( $id, 'link-' ) ) {
				$id = 'link-' . sanitize_title( $label );
			}

			if ( '' === $id || 'link-' === $id || isset( $out[ $id ] ) ) {
				$id = 'link-custom-' . ( (int) $index + 1 );
			}

			$out[ $id ] = [
				'id'         => $id,
				'label'      => $label,
				'icon'       => $this->lucide_icon( $icon ?: 'external-link' ),
				'order'      => 80 + (int) $index,
				'mode'       => 'link',
				'panel'      => '',
				'binder'     => '',
				'dismiss'    => 'none',
				'visibility' => 'all',
				'enabled'    => ! empty( $item['enabled'] ),
				'url'        => $url,
			];
		}

		return $out;
	}

	private function module_visible( array $module, array $context ): bool {
		$visibility = sanitize_key( $module['visibility'] ?? 'all' );

		if ( 'admins' === $visibility || ! empty( $module['admin_only'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			if ( ! empty( $module['requires_secure'] ) && empty( $context['secure_available'] ) ) {
				return false;
			}

			return true;
		}

		if ( 'phonekey_scope' === $visibility ) {
			return ! empty( $context['phonekey_visible'] );
		}

		return true;
	}
}
