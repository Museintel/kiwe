<?php

namespace DSA\WP7;

use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bindings_Service {
	public function register(): void {
		$callback = null;

		if ( function_exists( 'register_block_bindings_source' ) ) {
			$callback = 'register_block_bindings_source';
		} elseif ( function_exists( 'wp_register_block_bindings_source' ) ) {
			$callback = 'wp_register_block_bindings_source';
		}

		if ( ! $callback ) {
			return;
		}

		$callback(
			'kiwe/site',
			[
				'label'              => __( 'Kiwe site identity', 'dsa' ),
				'get_value_callback' => [ $this, 'site_value' ],
				'uses_context'       => [],
			]
		);
	}

	public function summary(): array {
		return [
			'id'          => 'bindings',
			'label'       => __( 'Block Bindings', 'dsa' ),
			'available'   => $this->available(),
			'status'      => $this->available() ? 'available' : 'fallback',
			'description' => __( 'Native bindings for site logo, title, user/account data, WooCommerce context, and trust labels.', 'dsa' ),
			'fallback'    => __( 'DSA continues using server-rendered PHP data and REST payloads.', 'dsa' ),
			'sources'     => [
				[
					'name'       => 'kiwe/site',
					'attributes' => [
						'title',
						'tagline',
						'home_url',
						'site_icon',
						'logo',
						'logo_inverse',
					],
					'mutations'  => false,
				],
			],
		];
	}

	public function available(): bool {
		return function_exists( 'register_block_bindings_source' )
			|| function_exists( 'wp_register_block_bindings_source' )
			|| class_exists( 'WP_Block_Bindings_Registry' );
	}

	public function site_value( array $source_args = [], $block_instance = null, string $attribute_name = '' ): string {
		$key = sanitize_key( $source_args['key'] ?? $source_args['field'] ?? $attribute_name );

		switch ( $key ) {
			case 'title':
			case 'site_title':
				return wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
			case 'tagline':
			case 'description':
				return wp_strip_all_tags( (string) get_bloginfo( 'description' ) );
			case 'home':
			case 'home_url':
				return esc_url_raw( home_url( '/' ) );
			case 'site_icon':
			case 'icon':
				return esc_url_raw( get_site_icon_url( 192 ) ?: '' );
			case 'logo':
			case 'site_logo':
				return Site_Identity_Service::logo_url();
			case 'logo_inverse':
			case 'site_logo_inverse':
			case 'logo_light':
			case 'site_logo_light':
			case 'logo_dark':
			case 'site_logo_dark':
				return Site_Identity_Service::logo_url( 'inverse' );
			default:
				return '';
		}
	}
}
