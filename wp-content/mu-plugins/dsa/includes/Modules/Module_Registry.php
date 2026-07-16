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
		$modules = $this->public_modules();
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
			if ( ! in_array( $mode, [ 'dock', 'action' ], true ) || empty( $enabled[ $id ] ) || ! $this->module_visible( $module, $context ) ) {
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
		$modules = $this->public_modules();
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

			if ( $admin_only && ! $can_manage ) {
				continue;
			}

			$out[] = [
				'id'             => sanitize_key( $id ),
				'label'          => sanitize_text_field( $module['label'] ?? $id ),
				'enabled'        => ! empty( $enabled[ $id ] ),
				'mode'           => sanitize_key( $module['mode'] ?? 'dock' ),
				'panel'          => sanitize_key( $module['panel'] ?? $id ),
				'binder'         => sanitize_key( $module['binder'] ?? '' ),
				'dismiss'        => sanitize_key( $module['dismiss'] ?? 'outside_safe' ),
				'visibility'     => sanitize_key( $module['visibility'] ?? 'all' ),
				'protected_flow' => sanitize_key( $module['protected_flow'] ?? '' ),
			];
		}

		return [
			'version' => 1,
			'items'   => $out,
		];
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
