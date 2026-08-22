<?php

namespace DSA\WP7;

use DSA\Onboarding\Design_Context_Profile_Service;
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
						'public_phone',
						'public_email',
						'business_description',
						'whatsapp',
						'brand_tone',
						'brand_color',
						'accent_color',
						'hero_color',
						'neutral_color',
						'surface_color',
						'facebook_url',
						'instagram_url',
						'x_url',
						'youtube_url',
						'pinterest_url',
						'linkedin_url',
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
			case 'public_phone':
			case 'store_phone':
				return Site_Identity_Service::store_phone();
			case 'public_email':
			case 'store_email':
				return Site_Identity_Service::store_email();
			case 'business_description':
			case 'whatsapp':
			case 'brand_tone':
			case 'brand_color':
			case 'accent_color':
			case 'hero_color':
			case 'neutral_color':
			case 'surface_color':
			case 'facebook_url':
			case 'instagram_url':
			case 'x_url':
			case 'youtube_url':
			case 'pinterest_url':
			case 'linkedin_url':
				return $this->design_context_value( $key );
			default:
				return '';
		}
	}

	private function design_context_value( string $key ): string {
		$profile = ( new Design_Context_Profile_Service() )->current();
		if ( 'business_description' === $key ) return sanitize_textarea_field( (string) ( $profile['identity']['description'] ?? '' ) );
		if ( 'whatsapp' === $key ) return sanitize_text_field( (string) ( $profile['contact']['whatsapp'] ?? '' ) );
		if ( 'brand_tone' === $key ) return sanitize_text_field( (string) ( $profile['brand']['tone'] ?? '' ) );
		if ( preg_match( '/^(facebook|instagram|x|youtube|pinterest|linkedin)_url$/', $key, $match ) ) {
			return esc_url_raw( (string) ( $profile['contact']['socialLinks'][ $match[1] ] ?? '' ) );
		}
		$role_map = [ 'brand_color'=>'brand', 'accent_color'=>'accent', 'hero_color'=>'hero', 'neutral_color'=>'neutral', 'surface_color'=>'surface' ];
		$role = $role_map[ $key ] ?? 'brand';
		foreach ( is_array( $profile['brand']['colors'] ?? null ) ? $profile['brand']['colors'] : [] as $color ) {
			if ( $role === ( $color['role'] ?? '' ) ) return (string) sanitize_hex_color( (string) ( $color['hex'] ?? '' ) );
		}
		return '';
	}
}
