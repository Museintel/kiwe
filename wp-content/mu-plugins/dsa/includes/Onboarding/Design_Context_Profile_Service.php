<?php

namespace DSA\Onboarding;

use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical owner-supplied context which complements, but never duplicates,
 * WordPress and WooCommerce authority. The service also applies values to the
 * native options which already own them.
 */
final class Design_Context_Profile_Service {
	public const OPTION_PROFILE = 'kiwe_seam_design_context_v1';
	public const OPTION_STATUS  = 'kiwe_onboarding_status_v1';
	public const PAGE_VISIBILITY_META = '_kiwe_search_visibility';

	public function current(): array {
		$stored = get_option( self::OPTION_PROFILE, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$inferred = $this->inferred();
		$profile = array_replace_recursive( $inferred, $stored );
		$visibility = [];
		foreach ( is_array( $stored['contentPlan']['existingPages'] ?? null ) ? $stored['contentPlan']['existingPages'] : [] as $page ) {
			if ( ! empty( $page['id'] ) ) $visibility[ (int) $page['id'] ] = 'secondary' === ( $page['visibility'] ?? '' ) ? 'secondary' : 'primary';
		}
		$profile['contentPlan']['existingPages'] = array_map(
			static function ( array $page ) use ( $visibility ): array {
				if ( isset( $visibility[ (int) $page['id'] ] ) ) $page['visibility'] = $visibility[ (int) $page['id'] ];
				return $page;
			},
			$inferred['contentPlan']['existingPages']
		);
		$profile['contentPlan']['plannedPages'] = is_array( $stored['contentPlan']['plannedPages'] ?? null ) ? array_values( $stored['contentPlan']['plannedPages'] ) : [];
		$profile['brand']['colors'] = is_array( $stored['brand']['colors'] ?? null ) ? array_values( $stored['brand']['colors'] ) : [];
		return $profile;
	}

	public function status(): array {
		$status = get_option( self::OPTION_STATUS, [] );
		return is_array( $status ) ? $status : [];
	}

	public function is_complete(): bool {
		return ! empty( $this->status()['completed'] );
	}

	public function save( array $raw, int $user_id, string $invitation_id = '' ) {
		$profile = $this->sanitize( $raw );
		$errors  = $this->required_errors( $profile );
		if ( $errors ) {
			return new \WP_Error( 'kiwe_onboarding_incomplete', implode( ' ', $errors ), [ 'fields' => array_keys( $errors ) ] );
		}

		update_option( 'blogname', $profile['identity']['siteName'] );
		update_option( 'blogdescription', $profile['identity']['tagline'] );
		update_option( Site_Identity_Service::OPTION_LOGO, $profile['identity']['logoId'], false );
		set_theme_mod( 'custom_logo', $profile['identity']['logoId'] );
		update_option( Site_Identity_Service::OPTION_STORE_PHONE, $profile['contact']['phone'], false );
		update_option( Site_Identity_Service::OPTION_STORE_EMAIL, $profile['contact']['email'], false );
		if ( $profile['identity']['siteIconId'] ) {
			update_option( 'site_icon', $profile['identity']['siteIconId'] );
		}
		if ( in_array( $profile['localization']['timezone'], timezone_identifiers_list(), true ) ) {
			update_option( 'timezone_string', $profile['localization']['timezone'] );
			update_option( 'gmt_offset', 0 );
		}

		$this->apply_page_visibility( $profile['contentPlan']['existingPages'] );
		if ( $profile['commerce']['enabled'] ) {
			$this->apply_woocommerce( $profile );
		}

		$profile['scores'] = $this->scores( $profile );
		$profile['meta'] = [
			'schema'       => 'kiwe.seam-design-context.v1',
			'updatedAt'    => gmdate( 'c' ),
			'updatedBy'    => $user_id,
			'authority'    => 'site-owner',
			'frameworkOptInRequired' => true,
		];
		update_option( self::OPTION_PROFILE, $profile, false );
		update_option(
			self::OPTION_STATUS,
			[
				'completed'    => true,
				'completedAt'  => gmdate( 'c' ),
				'completedBy'  => $user_id,
				'invitationId' => sanitize_key( $invitation_id ),
				'scores'       => $profile['scores'],
			],
			false
		);

		return $profile;
	}

	public function public_context( bool $administrator = false ): array {
		$profile = $this->current();
		$address = $profile['contact']['address'];
		if ( ! $administrator ) {
			$address = [
				'city'    => $address['city'],
				'state'   => $address['state'],
				'country' => $address['country'],
			];
		}

		return [
			'schema' => 'kiwe.seam-design-context.v1',
			'complete' => $this->is_complete(),
			'identity' => [
				'siteName'    => $profile['identity']['siteName'],
				'tagline'     => $profile['identity']['tagline'],
				'description' => $profile['identity']['description'],
				'industry'    => $profile['identity']['industry'],
				'siteType'    => $profile['identity']['siteType'],
				'logo'        => Site_Identity_Service::logo_url(),
				'siteIcon'    => get_site_icon_url( 512 ) ?: '',
			],
			'contact' => [
				'phone'    => $profile['contact']['phone'],
				'email'    => $profile['contact']['email'],
				'whatsapp' => $profile['contact']['whatsapp'],
				'address'  => $address,
			],
			'brand'       => $profile['brand'],
			'audience'    => $profile['audience'],
			'contentPlan' => $profile['contentPlan'],
			'commercePlan'=> [
				'enabled'              => $profile['commerce']['enabled'],
				'expectedProductCount' => $profile['commerce']['expectedProductCount'],
				'expectedPriceRange'   => $profile['commerce']['expectedPriceRange'],
				'hasBundles'           => $profile['commerce']['hasBundles'],
				'shippingModel'        => $profile['commerce']['shippingModel'],
				'typicalShippingCharge'=> $profile['commerce']['typicalShippingCharge'],
			],
			'seo'    => $profile['seo'],
			'scores' => $this->scores( $profile ),
			'privacy' => [
				'publicBusinessContextOnly' => true,
				'operationalAddressIncluded' => $administrator,
				'adminIdentityExcluded' => true,
			],
		];
	}

	public function scores( ?array $profile = null ): array {
		$p = $profile ?: $this->current();
		$seo_checks = [
			! empty( $p['identity']['siteName'] ), ! empty( $p['identity']['tagline'] ),
			! empty( $p['identity']['description'] ), ! empty( $p['identity']['logoId'] ),
			! empty( $p['identity']['siteIconId'] ), ! empty( $p['contact']['email'] ),
			! empty( $p['seo']['homepageDescription'] ), ! empty( $p['contentPlan']['existingPages'] ) || ! empty( $p['contentPlan']['plannedPages'] ),
		];
		$design_checks = [
			! empty( $p['identity']['siteName'] ), ! empty( $p['identity']['description'] ),
			! empty( $p['identity']['industry'] ), ! empty( $p['audience']['primary'] ),
			! empty( $p['contact']['phone'] ), ! empty( $p['contact']['email'] ),
			! empty( $p['brand']['tone'] ), ! empty( $p['brand']['colors'] ),
			! empty( $p['contentPlan']['existingPages'] ) || ! empty( $p['contentPlan']['plannedPages'] ),
			! empty( $p['brand']['notes'] ),
		];
		$percent = static fn( array $checks ): int => (int) round( 100 * count( array_filter( $checks ) ) / max( 1, count( $checks ) ) );
		return [ 'seoStrength' => $percent( $seo_checks ), 'designContextStrength' => $percent( $design_checks ) ];
	}

	private function inferred(): array {
		$country_state = sanitize_text_field( (string) get_option( 'woocommerce_default_country', '' ) );
		[ $country, $state ] = array_pad( explode( ':', $country_state, 2 ), 2, '' );
		$pages = [];
		foreach ( get_pages( [ 'post_status' => [ 'publish', 'draft', 'private' ], 'sort_column' => 'menu_order,post_title' ] ) as $page ) {
			$pages[] = [
				'id' => (int) $page->ID,
				'name' => sanitize_text_field( (string) $page->post_title ),
				'status' => sanitize_key( (string) $page->post_status ),
				'visibility' => 'secondary' === get_post_meta( $page->ID, self::PAGE_VISIBILITY_META, true ) ? 'secondary' : 'primary',
			];
		}

		$product_plan = $this->product_plan();
		return [
			'identity' => [
				'siteName' => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'tagline' => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
				'description' => '', 'industry' => '', 'siteType' => function_exists( 'WC' ) ? 'ecommerce' : 'business',
				'logoId' => Site_Identity_Service::attachment_id(), 'siteIconId' => (int) get_option( 'site_icon', 0 ),
			],
			'contact' => [
				'phone' => Site_Identity_Service::store_phone(), 'email' => Site_Identity_Service::store_email(), 'whatsapp' => '',
				'address' => [
					'line1' => sanitize_text_field( (string) get_option( 'woocommerce_store_address', '' ) ),
					'line2' => sanitize_text_field( (string) get_option( 'woocommerce_store_address_2', '' ) ),
					'city' => sanitize_text_field( (string) get_option( 'woocommerce_store_city', '' ) ),
					'state' => $state, 'postcode' => sanitize_text_field( (string) get_option( 'woocommerce_store_postcode', '' ) ), 'country' => $country,
				],
			],
			'localization' => [ 'timezone' => wp_timezone_string(), 'language' => sanitize_text_field( (string) get_bloginfo( 'language' ) ) ],
			'audience' => [ 'primary' => '', 'locations' => '', 'needs' => '' ],
			'brand' => [ 'tone' => '', 'colors' => [], 'notes' => '' ],
			'contentPlan' => [ 'existingPages' => $pages, 'plannedPages' => [] ],
			'commerce' => $product_plan,
			'seo' => [ 'homepageDescription' => '', 'allowIndexing' => '0' !== (string) get_option( 'blog_public', '1' ) ],
			'scores' => [ 'seoStrength' => 0, 'designContextStrength' => 0 ],
			'meta' => [ 'schema' => 'kiwe.seam-design-context.v1' ],
		];
	}

	private function product_plan(): array {
		$count = post_type_exists( 'product' ) ? (int) ( wp_count_posts( 'product' )->publish ?? 0 ) : 0;
		$min = ''; $max = '';
		global $wpdb;
		$table = $wpdb->prefix . 'wc_product_meta_lookup';
		if ( $count && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$row = $wpdb->get_row( "SELECT MIN(min_price) AS min_price, MAX(max_price) AS max_price FROM {$table} WHERE min_price IS NOT NULL", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$min = isset( $row['min_price'] ) ? (string) $row['min_price'] : '';
			$max = isset( $row['max_price'] ) ? (string) $row['max_price'] : '';
		}
		return [
			'enabled' => function_exists( 'WC' ), 'expectedProductCount' => $count,
			'expectedPriceRange' => [ 'min' => $min, 'max' => $max ],
			'hasBundles' => 'yes' === get_option( 'kiwe_onboarding_has_bundles', 'no' ),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'INR',
			'taxEnabled' => 'yes' === get_option( 'woocommerce_calc_taxes', 'no' ),
			'pricesIncludeTax' => 'yes' === get_option( 'woocommerce_prices_include_tax', 'no' ),
			'shippingModel' => '', 'typicalShippingCharge' => '',
		];
	}

	private function sanitize( array $raw ): array {
		$current = $this->inferred();
		$identity = is_array( $raw['identity'] ?? null ) ? $raw['identity'] : [];
		$contact = is_array( $raw['contact'] ?? null ) ? $raw['contact'] : [];
		$address = is_array( $contact['address'] ?? null ) ? $contact['address'] : [];
		$brand = is_array( $raw['brand'] ?? null ) ? $raw['brand'] : [];
		$commerce = is_array( $raw['commerce'] ?? null ) ? $raw['commerce'] : [];
		$seo = is_array( $raw['seo'] ?? null ) ? $raw['seo'] : [];
		$audience = is_array( $raw['audience'] ?? null ) ? $raw['audience'] : [];
		$localization = is_array( $raw['localization'] ?? null ) ? $raw['localization'] : [];
		$content = is_array( $raw['contentPlan'] ?? null ) ? $raw['contentPlan'] : [];

		$colors = [];
		$color_names = [ '#dc2626'=>'red','#f97360'=>'coral','#f97316'=>'orange','#f59e0b'=>'amber','#eab308'=>'yellow','#84cc16'=>'lime','#16a34a'=>'green','#059669'=>'emerald','#0d9488'=>'teal','#0891b2'=>'cyan','#0284c7'=>'sky','#2563eb'=>'blue','#4f46e5'=>'indigo','#7c3aed'=>'violet','#9333ea'=>'purple','#c026d3'=>'magenta','#db2777'=>'pink','#e11d48'=>'rose','#92400e'=>'brown','#c4a574'=>'sand','#6b7b3e'=>'olive','#1e3a5f'=>'navy','#64748b'=>'grey','#171717'=>'black' ];
		foreach ( array_slice( is_array( $brand['colors'] ?? null ) ? $brand['colors'] : [], 0, 3 ) as $color ) {
			if ( ! is_array( $color ) || ! sanitize_hex_color( (string) ( $color['hex'] ?? '' ) ) ) continue;
			$role = in_array( $color['role'] ?? '', [ 'brand', 'accent', 'support' ], true ) ? $color['role'] : 'support';
			$hex = strtolower( (string) sanitize_hex_color( (string) $color['hex'] ) );
			$colors[] = [ 'role' => $role, 'name' => $color_names[ $hex ] ?? 'custom', 'hex' => $hex ];
		}
		$existing = [];
		foreach ( is_array( $content['existingPages'] ?? null ) ? $content['existingPages'] : [] as $page ) {
			$id = absint( $page['id'] ?? 0 );
			if ( ! $id || 'page' !== get_post_type( $id ) ) continue;
			$existing[] = [ 'id' => $id, 'name' => sanitize_text_field( get_the_title( $id ) ), 'status' => sanitize_key( get_post_status( $id ) ?: '' ), 'visibility' => 'secondary' === ( $page['visibility'] ?? '' ) ? 'secondary' : 'primary' ];
		}
		$planned = [];
		foreach ( array_slice( is_array( $content['plannedPages'] ?? null ) ? $content['plannedPages'] : [], 0, 20 ) as $page ) {
			$name = sanitize_text_field( (string) ( $page['name'] ?? '' ) );
			if ( '' === $name ) continue;
			$planned[] = [ 'name' => $name, 'visibility' => 'secondary' === ( $page['visibility'] ?? '' ) ? 'secondary' : 'primary' ];
		}

		return [
			'identity' => [
				'siteName' => sanitize_text_field( (string) ( $identity['siteName'] ?? '' ) ), 'tagline' => sanitize_text_field( (string) ( $identity['tagline'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $identity['description'] ?? '' ) ), 'industry' => sanitize_text_field( (string) ( $identity['industry'] ?? '' ) ),
				'siteType' => in_array( $identity['siteType'] ?? '', [ 'business', 'ecommerce', 'publication', 'portfolio', 'nonprofit', 'community', 'education', 'service', 'other' ], true ) ? $identity['siteType'] : 'business',
				'logoId' => absint( $identity['logoId'] ?? 0 ), 'siteIconId' => absint( $identity['siteIconId'] ?? 0 ),
			],
			'contact' => [
				'phone' => sanitize_text_field( (string) ( $contact['phone'] ?? '' ) ), 'email' => sanitize_email( (string) ( $contact['email'] ?? '' ) ), 'whatsapp' => sanitize_text_field( (string) ( $contact['whatsapp'] ?? '' ) ),
				'address' => [ 'line1' => sanitize_text_field( (string) ( $address['line1'] ?? '' ) ), 'line2' => sanitize_text_field( (string) ( $address['line2'] ?? '' ) ), 'city' => sanitize_text_field( (string) ( $address['city'] ?? '' ) ), 'state' => sanitize_text_field( (string) ( $address['state'] ?? '' ) ), 'postcode' => sanitize_text_field( (string) ( $address['postcode'] ?? '' ) ), 'country' => strtoupper( substr( sanitize_key( (string) ( $address['country'] ?? '' ) ), 0, 2 ) ) ],
			],
			'localization' => [ 'timezone' => sanitize_text_field( (string) ( $localization['timezone'] ?? $current['localization']['timezone'] ) ), 'language' => sanitize_text_field( (string) get_bloginfo( 'language' ) ) ],
			'audience' => [ 'primary' => sanitize_text_field( (string) ( $audience['primary'] ?? '' ) ), 'locations' => sanitize_text_field( (string) ( $audience['locations'] ?? '' ) ), 'needs' => sanitize_textarea_field( (string) ( $audience['needs'] ?? '' ) ) ],
			'brand' => [ 'tone' => in_array( $brand['tone'] ?? '', [ 'pastel', 'vibrant', 'muted', 'natural', 'dark', 'light', 'luxury', 'playful', 'minimal', '' ], true ) ? $brand['tone'] : '', 'colors' => $colors, 'notes' => sanitize_textarea_field( (string) ( $brand['notes'] ?? '' ) ) ],
			'contentPlan' => [ 'existingPages' => $existing, 'plannedPages' => $planned ],
			'commerce' => [
				'enabled' => ! empty( $commerce['enabled'] ), 'expectedProductCount' => min( 1000000, absint( $commerce['expectedProductCount'] ?? 0 ) ),
				'expectedPriceRange' => [ 'min' => max( 0, (float) ( $commerce['expectedPriceRange']['min'] ?? 0 ) ), 'max' => max( 0, (float) ( $commerce['expectedPriceRange']['max'] ?? 0 ) ) ],
				'hasBundles' => ! empty( $commerce['hasBundles'] ), 'currency' => strtoupper( substr( sanitize_key( (string) ( $commerce['currency'] ?? 'INR' ) ), 0, 3 ) ),
				'taxEnabled' => ! empty( $commerce['taxEnabled'] ), 'pricesIncludeTax' => ! empty( $commerce['pricesIncludeTax'] ),
				'shippingModel' => in_array( $commerce['shippingModel'] ?? '', [ 'free', 'flat', 'calculated', 'pickup', 'mixed', '' ], true ) ? $commerce['shippingModel'] : '',
				'typicalShippingCharge' => max( 0, (float) ( $commerce['typicalShippingCharge'] ?? 0 ) ),
			],
			'seo' => [ 'homepageDescription' => substr( sanitize_textarea_field( (string) ( $seo['homepageDescription'] ?? '' ) ), 0, 320 ), 'allowIndexing' => ! empty( $seo['allowIndexing'] ) ],
			'scores' => [ 'seoStrength' => 0, 'designContextStrength' => 0 ], 'meta' => [ 'schema' => 'kiwe.seam-design-context.v1' ],
		];
	}

	private function required_errors( array $profile ): array {
		$errors = [];
		if ( '' === $profile['identity']['siteName'] ) $errors['siteName'] = __( 'Site name is required.', 'dsa' );
		if ( ! $profile['identity']['logoId'] ) $errors['logoId'] = __( 'A site logo is required.', 'dsa' );
		elseif ( ! wp_attachment_is_image( $profile['identity']['logoId'] ) ) $errors['logoId'] = __( 'The selected logo must be an image from this site.', 'dsa' );
		if ( ! $profile['identity']['siteIconId'] ) $errors['siteIconId'] = __( 'A square site icon is required.', 'dsa' );
		elseif ( ! wp_attachment_is_image( $profile['identity']['siteIconId'] ) ) $errors['siteIconId'] = __( 'The selected site icon must be an image from this site.', 'dsa' );
		if ( '' === $profile['contact']['phone'] ) $errors['phone'] = __( 'A public phone number is required.', 'dsa' );
		if ( ! is_email( $profile['contact']['email'] ) ) $errors['email'] = __( 'A valid public email is required.', 'dsa' );
		if ( $profile['commerce']['enabled'] && ( '' === $profile['contact']['address']['line1'] || '' === $profile['contact']['address']['city'] || '' === $profile['contact']['address']['country'] ) ) $errors['address'] = __( 'Store address, city and country are required for a store.', 'dsa' );
		return $errors;
	}

	private function apply_page_visibility( array $pages ): void {
		foreach ( $pages as $page ) {
			update_post_meta( (int) $page['id'], self::PAGE_VISIBILITY_META, 'secondary' === $page['visibility'] ? 'secondary' : 'primary' );
		}
	}

	private function apply_woocommerce( array $profile ): void {
		$a = $profile['contact']['address'];
		update_option( 'woocommerce_store_address', $a['line1'] ); update_option( 'woocommerce_store_address_2', $a['line2'] );
		update_option( 'woocommerce_store_city', $a['city'] ); update_option( 'woocommerce_store_postcode', $a['postcode'] );
		update_option( 'woocommerce_default_country', $a['country'] . ( $a['state'] ? ':' . $a['state'] : '' ) );
		update_option( 'woocommerce_currency', $profile['commerce']['currency'] );
		update_option( 'woocommerce_calc_taxes', $profile['commerce']['taxEnabled'] ? 'yes' : 'no' );
		update_option( 'woocommerce_prices_include_tax', $profile['commerce']['pricesIncludeTax'] ? 'yes' : 'no' );
		update_option( 'kiwe_onboarding_has_bundles', $profile['commerce']['hasBundles'] ? 'yes' : 'no', false );
	}
}
