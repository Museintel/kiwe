<?php

namespace DSA\WP7;

use DSA\Onboarding\Design_Context_Enhancement_Service;
use DSA\Commerce\Product_Context_Service;
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
		$callback(
			'kiwe/product',
			[
				'label'              => __( 'Kiwe product context', 'dsa' ),
				'get_value_callback' => [ $this, 'product_value' ],
				'uses_context'       => [ 'postId', 'postType' ],
			]
		);
		$callback(
			'kiwe/team',
			[
				'label'              => __( 'Kiwe public team member', 'dsa' ),
				'get_value_callback' => [ $this, 'team_value' ],
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
						'phone_url',
						'email_url',
						'business_description',
						'whatsapp',
						'whatsapp_url',
						'directions_url',
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
						'business_story', 'business_mission', 'business_vision', 'business_values', 'business_usp',
						'founder_name', 'founder_title', 'founder_bio', 'founder_image', 'founder_linkedin_url', 'team_enabled',
						'fssai_license', 'gst_number', 'manufacturing_address',
						'show_fssai_on_products', 'show_gst_on_products', 'show_manufacturing_address',
						'show_blog_rail', 'highlight_bestsellers',
					],
					'mutations'  => false,
				],
				[
					'name' => 'kiwe/product',
					'attributes' => [ 'nutrition_image' ],
					'mutations' => false,
				],
				[
					'name' => 'kiwe/team',
					'attributes' => [ 'member_id', 'name', 'title', 'bio', 'image', 'linkedin_url' ],
					'selectorArgument' => 'memberId',
					'mutations' => false,
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
			case 'phone_url':
			case 'store_phone_url':
				$phone = preg_replace( '/[^0-9+]/', '', Site_Identity_Service::store_phone() );
				return $phone ? esc_url_raw( 'tel:' . $phone ) : '';
			case 'email_url':
			case 'store_email_url':
				$email = sanitize_email( Site_Identity_Service::store_email() );
				return $email ? esc_url_raw( 'mailto:' . $email ) : '';
			case 'business_description':
			case 'whatsapp':
			case 'whatsapp_url':
			case 'directions_url':
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
			case 'business_story':
			case 'business_mission':
			case 'business_vision':
			case 'business_values':
			case 'business_usp':
			case 'founder_name':
			case 'founder_title':
			case 'founder_bio':
			case 'founder_image':
			case 'founder_linkedin_url':
			case 'team_enabled':
			case 'fssai_license':
			case 'gst_number':
			case 'manufacturing_address':
			case 'show_fssai_on_products':
			case 'show_gst_on_products':
			case 'show_manufacturing_address':
			case 'show_blog_rail':
			case 'highlight_bestsellers':
				return $this->design_context_value( $key );
			default:
				return '';
		}
	}

	public function product_value( array $source_args = [], $block_instance = null, string $attribute_name = '' ): string {
		$key = sanitize_key( $source_args['key'] ?? $source_args['field'] ?? $attribute_name );
		$context = is_object( $block_instance ) && is_array( $block_instance->context ?? null ) ? $block_instance->context : [];
		$product_id = absint( $context['postId'] ?? get_the_ID() );
		if ( 'nutrition_image' !== $key || 'product' !== get_post_type( $product_id ) ) return '';
		$image = Product_Context_Service::nutrition_image( $product_id );
		return esc_url_raw( (string) ( $image['url'] ?? '' ) );
	}

	public function team_value( array $source_args = [], $block_instance = null, string $attribute_name = '' ): string {
		unset( $block_instance );
		$key = sanitize_key( $source_args['key'] ?? $source_args['field'] ?? $attribute_name );
		$member_id = sanitize_key( (string) ( $source_args['memberId'] ?? $source_args['member_id'] ?? '' ) );
		$user_id = absint( $source_args['userId'] ?? $source_args['user_id'] ?? 0 );
		$profile = ( new Design_Context_Enhancement_Service() )->resolved_profile();
		$members = is_array( $profile['about']['team']['members'] ?? null ) ? $profile['about']['team']['members'] : [];
		$member = [];
		foreach ( $members as $candidate ) {
			if ( $member_id && $member_id === sanitize_key( (string) ( $candidate['id'] ?? '' ) ) ) { $member = $candidate; break; }
			if ( $user_id && $user_id === absint( $candidate['userId'] ?? 0 ) ) { $member = $candidate; break; }
		}
		if ( ! $member && ! $member_id && ! $user_id && $members ) $member = (array) reset( $members );
		if ( ! $member ) return '';
		if ( 'member_id' === $key ) return sanitize_key( (string) ( $member['id'] ?? '' ) );
		if ( 'name' === $key ) return sanitize_text_field( (string) ( $member['name'] ?? '' ) );
		if ( 'title' === $key ) return sanitize_text_field( (string) ( $member['title'] ?? '' ) );
		if ( 'bio' === $key ) return sanitize_textarea_field( (string) ( $member['bio'] ?? '' ) );
		if ( 'linkedin_url' === $key ) return esc_url_raw( (string) ( $member['linkedin'] ?? '' ) );
		if ( 'image' === $key ) {
			$image_id = absint( $member['imageId'] ?? 0 );
			return $image_id ? esc_url_raw( (string) wp_get_attachment_url( $image_id ) ) : esc_url_raw( (string) ( $member['image'] ?? '' ) );
		}
		return '';
	}

	private function design_context_value( string $key ): string {
		$profile = ( new Design_Context_Enhancement_Service() )->resolved_profile();
		if ( 'business_description' === $key ) return sanitize_textarea_field( (string) ( $profile['identity']['description'] ?? '' ) );
		$about_map = [ 'business_story'=>'story', 'business_mission'=>'mission', 'business_vision'=>'vision', 'business_values'=>'values', 'business_usp'=>'usp' ];
		if ( isset( $about_map[ $key ] ) ) return sanitize_textarea_field( (string) ( $profile['about'][ $about_map[ $key ] ] ?? '' ) );
		$founder_map = [ 'founder_name'=>'name', 'founder_title'=>'title', 'founder_bio'=>'bio' ];
		if ( isset( $founder_map[ $key ] ) ) return sanitize_textarea_field( (string) ( $profile['about']['founder'][ $founder_map[ $key ] ] ?? '' ) );
		if ( 'founder_image' === $key ) {
			$image_id = absint( $profile['about']['founder']['imageId'] ?? 0 );
			return $image_id ? esc_url_raw( (string) wp_get_attachment_url( $image_id ) ) : '';
		}
		if ( 'founder_linkedin_url' === $key ) return esc_url_raw( (string) ( $profile['about']['founder']['linkedin'] ?? '' ) );
		if ( 'team_enabled' === $key ) return ! empty( $profile['about']['team']['enabled'] ) ? '1' : '';
		$regulatory_map = [ 'fssai_license'=>'fssaiLicense', 'gst_number'=>'gstNumber', 'manufacturing_address'=>'manufacturingAddress' ];
		if ( isset( $regulatory_map[ $key ] ) ) return sanitize_textarea_field( (string) ( $profile['regulatory'][ $regulatory_map[ $key ] ] ?? '' ) );
		$flag_map = [
			'show_fssai_on_products'=>[ 'regulatory','showFssaiOnProducts' ], 'show_gst_on_products'=>[ 'regulatory','showGstOnProducts' ],
			'show_manufacturing_address'=>[ 'regulatory','showManufacturingAddress' ], 'show_blog_rail'=>[ 'contentPlan','showBlogRailOnHome' ],
			'highlight_bestsellers'=>[ 'contentPlan','highlightBestsellers' ],
		];
		if ( isset( $flag_map[ $key ] ) ) return ! empty( $profile[ $flag_map[ $key ][0] ][ $flag_map[ $key ][1] ] ) ? '1' : '';
		if ( 'whatsapp' === $key ) return sanitize_text_field( (string) ( $profile['contact']['whatsapp'] ?? '' ) );
		if ( 'whatsapp_url' === $key ) {
			$number = preg_replace( '/\D+/', '', (string) ( $profile['contact']['whatsapp'] ?? '' ) );
			return $number ? esc_url_raw( 'https://wa.me/' . $number ) : '';
		}
		if ( 'directions_url' === $key ) {
			$address = is_array( $profile['contact']['address'] ?? null ) ? array_filter( $profile['contact']['address'] ) : [];
			return $address ? esc_url_raw( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( implode( ', ', $address ) ) ) : '';
		}
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
