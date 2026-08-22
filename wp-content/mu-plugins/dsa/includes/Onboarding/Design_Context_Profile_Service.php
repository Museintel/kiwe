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
		foreach ( $profile['brand']['colors'] as &$color ) {
			if ( is_array( $color ) && 'support' === ( $color['role'] ?? '' ) ) {
				$color['role']  = 'neutral';
				$color['token'] = 'color-neutral';
			}
		}
		unset( $color );
		$profile['contact']['socialLinks'] = array_replace(
			$this->empty_social_links(),
			is_array( $stored['contact']['socialLinks'] ?? null ) ? $stored['contact']['socialLinks'] : $inferred['contact']['socialLinks']
		);
		return $profile;
	}

	public function status(): array {
		$status = get_option( self::OPTION_STATUS, [] );
		return is_array( $status ) ? $status : [];
	}

	public function is_complete(): bool {
		return ! empty( $this->status()['completed'] );
	}

	public static function saved_seo_strength(): ?int {
		$status = get_option( self::OPTION_STATUS, [] );
		if ( ! is_array( $status ) || empty( $status['completed'] ) || ! isset( $status['scores']['seoStrength'] ) ) return null;
		return max( 0, min( 100, absint( $status['scores']['seoStrength'] ) ) );
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
		update_option( Site_Identity_Service::OPTION_LOGO_INVERSE, $profile['identity']['logoInverseId'], false );
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
		$this->apply_kiwe_public_context( $profile );
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
				'logoInverse' => Site_Identity_Service::logo_url( 'inverse' ),
				'siteIcon'    => get_site_icon_url( 512 ) ?: '',
			],
			'contact' => [
				'phone'    => $profile['contact']['phone'],
				'email'    => $profile['contact']['email'],
				'whatsapp' => $profile['contact']['whatsapp'],
				'whatsappSameAsPhone' => ! empty( $profile['contact']['whatsappSameAsPhone'] ),
				'socialLinks' => array_filter( $profile['contact']['socialLinks'] ),
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
				'currency'              => $profile['commerce']['currency'],
				'currencyPosition'      => $profile['commerce']['currencyPosition'],
				'weightUnit'            => $profile['commerce']['weightUnit'],
				'dimensionUnit'         => $profile['commerce']['dimensionUnit'],
				'sellingLocations'      => [ 'mode' => $profile['commerce']['sellingLocationMode'], 'countries' => $profile['commerce']['sellingCountries'], 'excludedCountries' => $profile['commerce']['excludedSellingCountries'] ],
				'shippingLocations'     => [ 'mode' => $profile['commerce']['shippingLocationMode'], 'countries' => $profile['commerce']['shippingCountries'] ],
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
			! empty( $p['seo']['homepageDescription'] ), ! empty( $p['identity']['logoId'] ),
			! empty( $p['identity']['siteIconId'] ), ! empty( $p['contact']['email'] ),
			! empty( $p['contact']['phone'] ), ! empty( $p['contentPlan']['existingPages'] ) || ! empty( $p['contentPlan']['plannedPages'] ),
			! empty( $p['seo']['legalName'] ), ! empty( $p['seo']['primaryGoal'] ),
			! empty( $p['seo']['searchIntent'] ), ! empty( $p['seo']['proofPoints'] ),
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
				'logoId' => Site_Identity_Service::attachment_id(), 'logoInverseId' => Site_Identity_Service::attachment_id( Site_Identity_Service::OPTION_LOGO_INVERSE ), 'siteIconId' => (int) get_option( 'site_icon', 0 ),
			],
			'contact' => [
				'phone' => Site_Identity_Service::store_phone(), 'email' => Site_Identity_Service::store_email(), 'whatsapp' => '', 'whatsappSameAsPhone' => false,
				'socialLinks' => $this->stored_social_links(),
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
			'seo' => [ 'homepageDescription' => '', 'legalName' => '', 'foundedYear' => 0, 'primaryGoal' => '', 'searchIntent' => '', 'proofPoints' => '', 'allowIndexing' => '0' !== (string) get_option( 'blog_public', '1' ) ],
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
			'currencyPosition' => sanitize_key( (string) get_option( 'woocommerce_currency_pos', 'left' ) ),
			'priceDecimals' => min( 6, absint( get_option( 'woocommerce_price_num_decimals', 2 ) ) ),
			'weightUnit' => sanitize_key( (string) get_option( 'woocommerce_weight_unit', 'kg' ) ),
			'dimensionUnit' => sanitize_key( (string) get_option( 'woocommerce_dimension_unit', 'cm' ) ),
			'taxEnabled' => 'yes' === get_option( 'woocommerce_calc_taxes', 'no' ),
			'pricesIncludeTax' => 'yes' === get_option( 'woocommerce_prices_include_tax', 'no' ),
			'sellingLocationMode' => sanitize_key( (string) get_option( 'woocommerce_allowed_countries', 'all' ) ),
			'sellingCountries' => array_values( array_map( 'sanitize_text_field', (array) get_option( 'woocommerce_specific_allowed_countries', [] ) ) ),
			'excludedSellingCountries' => array_values( array_map( 'sanitize_text_field', (array) get_option( 'woocommerce_all_except_countries', [] ) ) ),
			'shippingLocationMode' => sanitize_key( (string) get_option( 'woocommerce_ship_to_countries', '' ) ),
			'shippingCountries' => array_values( array_map( 'sanitize_text_field', (array) get_option( 'woocommerce_specific_ship_to_countries', [] ) ) ),
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
		foreach ( array_slice( is_array( $brand['colors'] ?? null ) ? $brand['colors'] : [], 0, 5 ) as $color ) {
			if ( ! is_array( $color ) || ! sanitize_hex_color( (string) ( $color['hex'] ?? '' ) ) ) continue;
			$submitted_role = 'support' === ( $color['role'] ?? '' ) ? 'neutral' : ( $color['role'] ?? '' );
			$role = in_array( $submitted_role, [ 'brand', 'accent', 'hero', 'neutral', 'surface' ], true ) ? $submitted_role : 'neutral';
			$hex = strtolower( (string) sanitize_hex_color( (string) $color['hex'] ) );
			$colors[] = [ 'role' => $role, 'token' => 'color-' . $role, 'name' => $color_names[ $hex ] ?? 'custom', 'hex' => $hex, 'ownerSelected' => true ];
		}
		$social_links = [];
		foreach ( $this->empty_social_links() as $network => $empty ) {
			$social_links[ $network ] = esc_url_raw( (string) ( $contact['socialLinks'][ $network ] ?? '' ) );
		}
		$phone = sanitize_text_field( (string) ( $contact['phone'] ?? '' ) );
		$whatsapp_same = ! empty( $contact['whatsappSameAsPhone'] );
		$whatsapp = $whatsapp_same ? $phone : sanitize_text_field( (string) ( $contact['whatsapp'] ?? '' ) );
		$country_codes = static function ( $values ): array {
			return array_values( array_unique( array_filter( array_map( static fn( $code ): string => strtoupper( substr( sanitize_key( (string) $code ), 0, 2 ) ), is_array( $values ) ? $values : [] ) ) ) );
		};
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
				'logoId' => absint( $identity['logoId'] ?? 0 ), 'logoInverseId' => absint( $identity['logoInverseId'] ?? 0 ), 'siteIconId' => absint( $identity['siteIconId'] ?? 0 ),
			],
			'contact' => [
				'phone' => $phone, 'email' => sanitize_email( (string) ( $contact['email'] ?? '' ) ), 'whatsapp' => $whatsapp, 'whatsappSameAsPhone' => $whatsapp_same, 'socialLinks' => $social_links,
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
				'currencyPosition' => in_array( $commerce['currencyPosition'] ?? '', [ 'left', 'right', 'left_space', 'right_space' ], true ) ? $commerce['currencyPosition'] : 'left',
				'priceDecimals' => min( 6, absint( $commerce['priceDecimals'] ?? 2 ) ),
				'weightUnit' => in_array( $commerce['weightUnit'] ?? '', [ 'kg', 'g', 'lbs', 'oz' ], true ) ? $commerce['weightUnit'] : 'kg',
				'dimensionUnit' => in_array( $commerce['dimensionUnit'] ?? '', [ 'm', 'cm', 'mm', 'in', 'yd' ], true ) ? $commerce['dimensionUnit'] : 'cm',
				'taxEnabled' => ! empty( $commerce['taxEnabled'] ), 'pricesIncludeTax' => ! empty( $commerce['pricesIncludeTax'] ),
				'sellingLocationMode' => in_array( $commerce['sellingLocationMode'] ?? '', [ 'all', 'all_except', 'specific' ], true ) ? $commerce['sellingLocationMode'] : 'all',
				'sellingCountries' => $country_codes( $commerce['sellingCountries'] ?? [] ),
				'excludedSellingCountries' => $country_codes( $commerce['excludedSellingCountries'] ?? [] ),
				'shippingLocationMode' => in_array( $commerce['shippingLocationMode'] ?? '', [ '', 'all', 'specific', 'disabled' ], true ) ? $commerce['shippingLocationMode'] : '',
				'shippingCountries' => $country_codes( $commerce['shippingCountries'] ?? [] ),
				'shippingModel' => in_array( $commerce['shippingModel'] ?? '', [ 'free', 'flat', 'calculated', 'pickup', 'mixed', '' ], true ) ? $commerce['shippingModel'] : '',
				'typicalShippingCharge' => max( 0, (float) ( $commerce['typicalShippingCharge'] ?? 0 ) ),
			],
			'seo' => [
				'homepageDescription' => substr( sanitize_textarea_field( (string) ( $seo['homepageDescription'] ?? '' ) ), 0, 320 ),
				'legalName' => substr( sanitize_text_field( (string) ( $seo['legalName'] ?? '' ) ), 0, 200 ),
				'foundedYear' => ( static function ( int $year ): int { $current_year = (int) gmdate( 'Y' ); return $year >= 1000 && $year <= $current_year ? $year : 0; } )( absint( $seo['foundedYear'] ?? 0 ) ),
				'primaryGoal' => in_array( $seo['primaryGoal'] ?? '', [ '', 'buy', 'contact', 'book', 'visit', 'subscribe', 'donate', 'read' ], true ) ? $seo['primaryGoal'] : '',
				'searchIntent' => substr( sanitize_text_field( (string) ( $seo['searchIntent'] ?? '' ) ), 0, 240 ),
				'proofPoints' => substr( sanitize_textarea_field( (string) ( $seo['proofPoints'] ?? '' ) ), 0, 1600 ),
				'allowIndexing' => ! empty( $seo['allowIndexing'] ),
			],
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
		update_option( 'woocommerce_currency_pos', $profile['commerce']['currencyPosition'] );
		update_option( 'woocommerce_price_num_decimals', $profile['commerce']['priceDecimals'] );
		update_option( 'woocommerce_weight_unit', $profile['commerce']['weightUnit'] );
		update_option( 'woocommerce_dimension_unit', $profile['commerce']['dimensionUnit'] );
		update_option( 'woocommerce_calc_taxes', $profile['commerce']['taxEnabled'] ? 'yes' : 'no' );
		update_option( 'woocommerce_prices_include_tax', $profile['commerce']['pricesIncludeTax'] ? 'yes' : 'no' );
		update_option( 'woocommerce_allowed_countries', $profile['commerce']['sellingLocationMode'] );
		update_option( 'woocommerce_specific_allowed_countries', $profile['commerce']['sellingCountries'] );
		update_option( 'woocommerce_all_except_countries', $profile['commerce']['excludedSellingCountries'] );
		update_option( 'woocommerce_ship_to_countries', $profile['commerce']['shippingLocationMode'] );
		update_option( 'woocommerce_specific_ship_to_countries', $profile['commerce']['shippingCountries'] );
		update_option( 'kiwe_onboarding_has_bundles', $profile['commerce']['hasBundles'] ? 'yes' : 'no', false );
	}

	private function empty_social_links(): array {
		return [ 'facebook'=>'', 'instagram'=>'', 'x'=>'', 'youtube'=>'', 'pinterest'=>'', 'linkedin'=>'' ];
	}

	private function stored_social_links(): array {
		$settings = defined( 'DSA_OPTION_SETTINGS' ) ? get_option( DSA_OPTION_SETTINGS, [] ) : [];
		$links = is_array( $settings['link_hub']['social_links'] ?? null ) ? $settings['link_hub']['social_links'] : [];
		return array_replace( $this->empty_social_links(), array_intersect_key( $links, $this->empty_social_links() ) );
	}

	private function apply_kiwe_public_context( array $profile ): void {
		if ( ! defined( 'DSA_OPTION_SETTINGS' ) ) return;
		$settings = get_option( DSA_OPTION_SETTINGS, [] );
		$settings = is_array( $settings ) ? $settings : [];
		$link_hub = is_array( $settings['link_hub'] ?? null ) ? $settings['link_hub'] : [];
		$link_hub['social_links'] = $profile['contact']['socialLinks'];
		$settings['link_hub'] = $link_hub;
		update_option( DSA_OPTION_SETTINGS, $settings, false );
	}
}
