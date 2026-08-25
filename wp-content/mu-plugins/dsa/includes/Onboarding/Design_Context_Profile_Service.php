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
	public const USER_META_TEAM_MEMBER = 'kiwe_public_team_member';
	public const USER_META_TEAM_TITLE = 'kiwe_team_title';
	public const USER_META_FOUNDER_TITLE = 'kiwe_founder_title';
	public const USER_META_LINKEDIN = 'kiwe_linkedin_url';
	public const USER_META_TEAM_ORDER = 'kiwe_team_order';
	public const USER_META_AVATAR_ID = 'kiwe_avatar_id';
	private const SERVICE_CATEGORY_PLAN_META = '_kiwe_service_category_plan';

	public function current(): array {
		$stored = get_option( self::OPTION_PROFILE, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$inferred = $this->inferred( $stored );
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
		$profile['about']['team']['members'] = is_array( $stored['about']['team']['members'] ?? null ) ? array_values( $stored['about']['team']['members'] ) : [];
		if ( empty( $inferred['services']['sourcePostType'] ) ) {
			$profile['services']['items'] = is_array( $stored['services']['items'] ?? null ) ? array_values( $stored['services']['items'] ) : [];
		} else {
			$pending_services = array_values( array_filter( is_array( $stored['services']['items'] ?? null ) ? $stored['services']['items'] : [], static fn( $item ): bool => is_array( $item ) && empty( $item['recordId'] ) ) );
			$profile['services']['items'] = array_merge( $inferred['services']['items'], $pending_services );
		}
		$profile['brand']['colors'] = is_array( $stored['brand']['colors'] ?? null ) ? array_values( $stored['brand']['colors'] ) : [];
		$profile['resources']['items'] = is_array( $stored['resources']['items'] ?? null ) ? array_values( $stored['resources']['items'] ) : [];
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
		return $this->resolve_people( $profile );
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
		$profile = $this->apply_people_context( $profile, $user_id );
		$profile = $this->apply_services_context( $profile, $user_id );
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
		$stored_profile = $this->compact_people_references( $profile );
		$stored_profile = $this->compact_service_references( $stored_profile );
		update_option( self::OPTION_PROFILE, $stored_profile, false );
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
		$about   = $profile['about'];
		$about = $this->resolve_people( [ 'about' => $about ] )['about'];
		$founder_image_id = absint( $about['founder']['imageId'] ?? 0 );
		$about['founder']['image'] = $founder_image_id ? esc_url_raw( (string) wp_get_attachment_url( $founder_image_id ) ) : '';
		foreach ( $about['team']['members'] as &$member ) {
			$image_id = absint( $member['imageId'] ?? 0 );
			$member['image'] = $image_id ? esc_url_raw( (string) wp_get_attachment_url( $image_id ) ) : '';
		}
		unset( $member );
		if ( ! $administrator ) {
			$address = [
				'city'    => $address['city'],
				'state'   => $address['state'],
				'country' => $address['country'],
			];
			if ( empty( $about['team']['enabled'] ) ) $about['team']['members'] = [];
		}
		$services = $profile['services'];
		if ( ! $administrator ) {
			$services['items'] = array_values( array_filter( (array) ( $services['items'] ?? [] ), static fn( $item ): bool => ! empty( $item['recordId'] ) && 'publish' === get_post_status( absint( $item['recordId'] ) ) ) );
			foreach ( $services['items'] as &$service_item ) $service_item['categoryPaths'] = '';
			unset( $service_item );
		}

		$resources = [];
		if ( $administrator ) {
			foreach ( (array) ( $profile['resources']['items'] ?? [] ) as $resource ) {
				$attachment_id = absint( $resource['attachmentId'] ?? 0 );
				if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) continue;
				$resources[] = [
					'attachmentId' => $attachment_id,
					'title'        => sanitize_text_field( (string) get_the_title( $attachment_id ) ),
					'url'          => esc_url_raw( (string) wp_get_attachment_url( $attachment_id ) ),
					'mimeType'     => sanitize_mime_type( (string) get_post_mime_type( $attachment_id ) ),
					'role'         => sanitize_key( (string) ( $resource['role'] ?? 'reference' ) ),
					'note'         => sanitize_textarea_field( (string) ( $resource['note'] ?? '' ) ),
				];
			}
		}

		return [
			'schema' => 'kiwe.seam-design-context.v1',
			'complete' => $this->is_complete(),
			'identity' => [
				'siteName'    => $profile['identity']['siteName'],
				'tagline'     => $profile['identity']['tagline'],
				'description' => $profile['identity']['description'],
				'industry'    => $profile['identity']['industry'],
				'industrySector' => $profile['identity']['industrySector'],
				'siteType'    => $profile['identity']['siteType'],
				'logo'        => Site_Identity_Service::logo_url(),
				'logoInverse' => Site_Identity_Service::logo_url( 'inverse' ),
				'siteIcon'    => get_site_icon_url( 512 ) ?: '',
			],
			'about'       => $about,
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
			'services'    => $services,
			'resources'   => [
				'items' => $resources,
				'count' => count( (array) ( $profile['resources']['items'] ?? [] ) ),
				'authority' => $administrator ? 'administrator-selected-media-library-resources' : 'withheld-from-public-context',
			],
			'regulatory'  => $profile['regulatory'],
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
			! empty( $p['about']['story'] ), ! empty( $p['about']['mission'] ) || ! empty( $p['about']['vision'] ),
		];
		$design_checks = [
			! empty( $p['identity']['siteName'] ), ! empty( $p['identity']['description'] ),
			! empty( $p['identity']['industry'] ), ! empty( $p['audience']['primary'] ),
			! empty( $p['contact']['phone'] ), ! empty( $p['contact']['email'] ),
			! empty( $p['brand']['tone'] ), ! empty( $p['brand']['colors'] ),
			! empty( $p['contentPlan']['existingPages'] ) || ! empty( $p['contentPlan']['plannedPages'] ),
			! empty( $p['brand']['notes'] ),
			! empty( $p['about']['story'] ), ! empty( $p['about']['usp'] ), ! empty( $p['about']['values'] ),
			! empty( $p['resources']['items'] ),
		];
		$percent = static fn( array $checks ): int => (int) round( 100 * count( array_filter( $checks ) ) / max( 1, count( $checks ) ) );
		return [ 'seoStrength' => $percent( $seo_checks ), 'designContextStrength' => $percent( $design_checks ) ];
	}

	/**
	 * Apply only owner-approved editorial refinements. This deliberately cannot
	 * address identity names, contacts, locations, legal/regulatory records,
	 * commerce facts, people names/titles, services, products, or media.
	 */
	public function apply_editorial_refinements( array $changes, int $user_id ): array {
		$profile = $this->current();
		$limits = [
			'identity.tagline'=>160, 'identity.description'=>5000,
			'audience.primary'=>500, 'audience.locations'=>500, 'audience.needs'=>2000,
			'about.story'=>5000, 'about.mission'=>2000, 'about.vision'=>2000, 'about.values'=>2000, 'about.usp'=>2000,
			'about.founder.bio'=>3000, 'brand.notes'=>3000,
			'seo.homepageDescription'=>320, 'seo.searchIntent'=>240, 'seo.proofPoints'=>1600,
		];
		$applied = [];
		foreach ( $changes as $path=>$value ) {
			if ( ! isset( $limits[ $path ] ) || ! is_scalar( $value ) ) continue;
			if ( 'about.founder.bio' === $path ) {
				$founder_user_id = absint( $profile['about']['founder']['userId'] ?? 0 );
				if ( $founder_user_id && ! current_user_can( 'edit_user', $founder_user_id ) ) continue;
			}
			$value = substr( sanitize_textarea_field( (string) $value ), 0, $limits[ $path ] );
			if ( '' === trim( $value ) ) continue;
			$segments = explode( '.', $path );
			$cursor =& $profile;
			foreach ( $segments as $index=>$segment ) {
				if ( $index === count( $segments ) - 1 ) {
					$cursor[ $segment ] = $value;
					break;
				}
				if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) $cursor[ $segment ] = [];
				$cursor =& $cursor[ $segment ];
			}
			unset( $cursor );
			$applied[ $path ] = $value;
		}
		if ( ! $applied ) return [];
		if ( isset( $applied['identity.tagline'] ) ) update_option( 'blogdescription', $applied['identity.tagline'] );
		if ( isset( $applied['about.founder.bio'] ) ) {
			$founder_user_id = absint( $profile['about']['founder']['userId'] ?? 0 );
			if ( $founder_user_id ) wp_update_user( [ 'ID'=>$founder_user_id, 'description'=>$applied['about.founder.bio'] ] );
		}
		$profile['scores'] = $this->scores( $profile );
		$profile['meta'] = array_replace( (array) ( $profile['meta'] ?? [] ), [
			'schema'=>'kiwe.seam-design-context.v1', 'updatedAt'=>gmdate( 'c' ), 'updatedBy'=>$user_id,
			'lastEditorialRefinementAt'=>gmdate( 'c' ), 'lastEditorialRefinementBy'=>$user_id,
		] );
		update_option( self::OPTION_PROFILE, $this->compact_service_references( $this->compact_people_references( $profile ) ), false );
		$status = $this->status();
		$status['scores'] = $profile['scores'];
		update_option( self::OPTION_STATUS, $status, false );
		return $applied;
	}

	private function inferred( ?array $stored = null ): array {
		$stored = is_array( $stored ) ? $stored : (array) get_option( self::OPTION_PROFILE, [] );
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
				'description' => '', 'industry' => '', 'industrySector' => '', 'siteType' => function_exists( 'WC' ) ? 'ecommerce' : 'business',
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
			'about' => [
				'story' => '', 'mission' => '', 'vision' => '', 'values' => '', 'usp' => '',
				'founder' => [ 'userId' => 0, 'name' => '', 'title' => '', 'bio' => '', 'imageId' => 0, 'linkedin' => '' ],
				'team' => [ 'enabled' => false, 'members' => [] ],
			],
			'brand' => [ 'tone' => '', 'colors' => [], 'notes' => '' ],
			'contentPlan' => [ 'existingPages' => $pages, 'plannedPages' => [], 'showBlogRailOnHome' => false, 'highlightBestsellers' => false ],
			'services' => $this->inferred_services( sanitize_key( (string) ( $stored['services']['sourcePostType'] ?? $this->default_service_post_type() ) ) ),
			'resources' => [ 'items' => [] ],
			'commerce' => $product_plan,
			'regulatory' => [
				'fssaiLicense' => '', 'showFssaiOnProducts' => false,
				'gstNumber' => '', 'showGstOnProducts' => false,
				'manufacturingAddress' => '', 'showManufacturingAddress' => false,
			],
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
		$about = is_array( $raw['about'] ?? null ) ? $raw['about'] : [];
		$founder = is_array( $about['founder'] ?? null ) ? $about['founder'] : [];
		$team = is_array( $about['team'] ?? null ) ? $about['team'] : [];
		$regulatory = is_array( $raw['regulatory'] ?? null ) ? $raw['regulatory'] : [];
		$localization = is_array( $raw['localization'] ?? null ) ? $raw['localization'] : [];
		$content = is_array( $raw['contentPlan'] ?? null ) ? $raw['contentPlan'] : [];
		$services = is_array( $raw['services'] ?? null ) ? $raw['services'] : [];
		$resources = is_array( $raw['resources'] ?? null ) ? $raw['resources'] : [];

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
		$team_members = [];
		foreach ( array_slice( is_array( $team['members'] ?? null ) ? $team['members'] : [], 0, 30 ) as $index => $member ) {
			if ( ! is_array( $member ) ) continue;
			$user_id = absint( $member['userId'] ?? 0 );
			if ( $user_id && ! get_user_by( 'id', $user_id ) ) $user_id = 0;
			$name = substr( sanitize_text_field( (string) ( $member['name'] ?? '' ) ), 0, 200 );
			if ( ! $user_id && '' === $name ) continue;
			$member_id = sanitize_key( (string) ( $member['id'] ?? '' ) );
			if ( '' === $member_id ) $member_id = 'member-' . substr( wp_generate_uuid4(), 0, 12 );
			$image_id = absint( $member['imageId'] ?? 0 );
			$team_members[] = [
				'id' => substr( $member_id, 0, 80 ),
				'userId' => $user_id,
				'name' => $name,
				'title' => substr( sanitize_text_field( (string) ( $member['title'] ?? '' ) ), 0, 200 ),
				'bio' => substr( sanitize_textarea_field( (string) ( $member['bio'] ?? '' ) ), 0, 3000 ),
				'imageId' => $image_id && wp_attachment_is_image( $image_id ) ? $image_id : 0,
				'linkedin' => esc_url_raw( (string) ( $member['linkedin'] ?? '' ) ),
				'order' => $index,
			];
		}
		$service_sources = $this->service_post_types();
		$service_source = sanitize_key( (string) ( $services['sourcePostType'] ?? '' ) );
		if ( ! isset( $service_sources[ $service_source ] ) ) $service_source = '';
		$stored_profile = (array) get_option( self::OPTION_PROFILE, [] );
		$previous_service_source = sanitize_key( (string) ( $stored_profile['services']['sourcePostType'] ?? '' ) );
		$service_taxonomies = $service_source ? $this->service_taxonomies( $service_source ) : [];
		$service_meta_fields = $service_source ? $this->service_meta_fields( $service_source ) : [];
		$service_items = [];
		foreach ( array_slice( is_array( $services['items'] ?? null ) ? $services['items'] : [], 0, 100 ) as $index => $item ) {
			if ( ! is_array( $item ) ) continue;
			$record_id = absint( $item['recordId'] ?? 0 );
			if ( $record_id && $previous_service_source && $previous_service_source !== $service_source ) continue;
			if ( $record_id && ( ! $service_source || $service_source !== get_post_type( $record_id ) ) ) $record_id = 0;
			$title = substr( sanitize_text_field( (string) ( $item['title'] ?? '' ) ), 0, 240 );
			if ( ! $record_id && '' === $title ) continue;
			$stable_id = sanitize_key( (string) ( $item['stableId'] ?? '' ) );
			if ( '' === $stable_id ) $stable_id = $record_id ? 'service-' . $record_id : 'service-' . substr( wp_generate_uuid4(), 0, 12 );
			$taxonomy_paths = [];
			foreach ( $service_taxonomies as $taxonomy => $definition ) {
				$taxonomy_paths[ $taxonomy ] = substr( sanitize_textarea_field( (string) ( $item['taxonomyPaths'][ $taxonomy ] ?? '' ) ), 0, 3000 );
			}
			$service_meta = [];
			foreach ( $service_meta_fields as $meta_key=>$definition ) {
				$value = $item['meta'][ $meta_key ] ?? '';
				$service_meta[ $meta_key ] = 'boolean' === $definition['type'] ? ! empty( $value ) : ( 'integer' === $definition['type'] ? (int) $value : ( 'number' === $definition['type'] ? (float) $value : substr( sanitize_textarea_field( (string) $value ), 0, 5000 ) ) );
			}
			$image_id = absint( $item['imageId'] ?? 0 );
			$service_items[] = [
				'stableId'=>substr( $stable_id, 0, 80 ), 'recordId'=>$record_id, 'title'=>$title,
				'summary'=>substr( sanitize_textarea_field( (string) ( $item['summary'] ?? '' ) ), 0, 2000 ),
				'description'=>substr( wp_kses_post( (string) ( $item['description'] ?? '' ) ), 0, 20000 ),
				'imageId'=>$image_id && wp_attachment_is_image( $image_id ) ? $image_id : 0,
				'parentId'=>absint( $item['parentId'] ?? 0 ), 'menuOrder'=>(int) ( $item['menuOrder'] ?? $index ),
				'status'=>'publish' === ( $item['status'] ?? '' ) ? 'publish' : 'draft',
				'categoryPaths'=>substr( sanitize_textarea_field( (string) ( $item['categoryPaths'] ?? '' ) ), 0, 3000 ),
				'taxonomyPaths'=>$taxonomy_paths, 'meta'=>$service_meta,
			];
		}
		$resource_items = [];
		$resource_roles = [ 'logo', 'hero', 'product', 'team', 'service', 'gallery', 'document', 'video', 'reference', 'other' ];
		$seen_attachments = [];
		foreach ( array_slice( is_array( $resources['items'] ?? null ) ? $resources['items'] : [], 0, 100 ) as $resource ) {
			if ( ! is_array( $resource ) ) continue;
			$attachment_id = absint( $resource['attachmentId'] ?? 0 );
			if ( ! $attachment_id || isset( $seen_attachments[ $attachment_id ] ) || 'attachment' !== get_post_type( $attachment_id ) ) continue;
			$seen_attachments[ $attachment_id ] = true;
			$role = sanitize_key( (string) ( $resource['role'] ?? 'reference' ) );
			$resource_items[] = [
				'attachmentId' => $attachment_id,
				'role' => in_array( $role, $resource_roles, true ) ? $role : 'reference',
				'note' => substr( sanitize_textarea_field( (string) ( $resource['note'] ?? '' ) ), 0, 1000 ),
			];
		}

		return [
			'identity' => [
				'siteName' => sanitize_text_field( (string) ( $identity['siteName'] ?? '' ) ), 'tagline' => sanitize_text_field( (string) ( $identity['tagline'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $identity['description'] ?? '' ) ), 'industry' => sanitize_text_field( (string) ( $identity['industry'] ?? '' ) ),
				'industrySector' => in_array( $identity['industrySector'] ?? '', [ '', 'food_beverage', 'retail', 'manufacturing', 'healthcare', 'education', 'hospitality', 'professional_services', 'technology', 'media', 'nonprofit', 'real_estate', 'other' ], true ) ? $identity['industrySector'] : '',
				'siteType' => in_array( $identity['siteType'] ?? '', [ 'business', 'ecommerce', 'publication', 'portfolio', 'nonprofit', 'community', 'education', 'service', 'other' ], true ) ? $identity['siteType'] : 'business',
				'logoId' => absint( $identity['logoId'] ?? 0 ), 'logoInverseId' => absint( $identity['logoInverseId'] ?? 0 ), 'siteIconId' => absint( $identity['siteIconId'] ?? 0 ),
			],
			'contact' => [
				'phone' => $phone, 'email' => sanitize_email( (string) ( $contact['email'] ?? '' ) ), 'whatsapp' => $whatsapp, 'whatsappSameAsPhone' => $whatsapp_same, 'socialLinks' => $social_links,
				'address' => [ 'line1' => sanitize_text_field( (string) ( $address['line1'] ?? '' ) ), 'line2' => sanitize_text_field( (string) ( $address['line2'] ?? '' ) ), 'city' => sanitize_text_field( (string) ( $address['city'] ?? '' ) ), 'state' => sanitize_text_field( (string) ( $address['state'] ?? '' ) ), 'postcode' => sanitize_text_field( (string) ( $address['postcode'] ?? '' ) ), 'country' => strtoupper( substr( sanitize_key( (string) ( $address['country'] ?? '' ) ), 0, 2 ) ) ],
			],
			'localization' => [ 'timezone' => sanitize_text_field( (string) ( $localization['timezone'] ?? $current['localization']['timezone'] ) ), 'language' => sanitize_text_field( (string) get_bloginfo( 'language' ) ) ],
			'audience' => [ 'primary' => sanitize_text_field( (string) ( $audience['primary'] ?? '' ) ), 'locations' => sanitize_text_field( (string) ( $audience['locations'] ?? '' ) ), 'needs' => sanitize_textarea_field( (string) ( $audience['needs'] ?? '' ) ) ],
			'about' => [
				'story' => substr( sanitize_textarea_field( (string) ( $about['story'] ?? '' ) ), 0, 5000 ),
				'mission' => substr( sanitize_textarea_field( (string) ( $about['mission'] ?? '' ) ), 0, 2000 ),
				'vision' => substr( sanitize_textarea_field( (string) ( $about['vision'] ?? '' ) ), 0, 2000 ),
				'values' => substr( sanitize_textarea_field( (string) ( $about['values'] ?? '' ) ), 0, 2000 ),
				'usp' => substr( sanitize_textarea_field( (string) ( $about['usp'] ?? '' ) ), 0, 2000 ),
				'founder' => [
					'userId' => ( static function ( int $id ): int { return $id && get_user_by( 'id', $id ) ? $id : 0; } )( absint( $founder['userId'] ?? 0 ) ),
					'name' => substr( sanitize_text_field( (string) ( $founder['name'] ?? '' ) ), 0, 200 ),
					'title' => substr( sanitize_text_field( (string) ( $founder['title'] ?? '' ) ), 0, 200 ),
					'bio' => substr( sanitize_textarea_field( (string) ( $founder['bio'] ?? '' ) ), 0, 3000 ),
					'imageId' => ( static function ( int $id ): int { return $id && wp_attachment_is_image( $id ) ? $id : 0; } )( absint( $founder['imageId'] ?? 0 ) ),
					'linkedin' => esc_url_raw( (string) ( $founder['linkedin'] ?? '' ) ),
				],
				'team' => [ 'enabled' => ! empty( $team['enabled'] ), 'members' => $team_members ],
			],
			'brand' => [ 'tone' => in_array( $brand['tone'] ?? '', [ 'pastel', 'vibrant', 'muted', 'natural', 'dark', 'light', 'luxury', 'playful', 'minimal', '' ], true ) ? $brand['tone'] : '', 'colors' => $colors, 'notes' => sanitize_textarea_field( (string) ( $brand['notes'] ?? '' ) ) ],
			'contentPlan' => [
				'existingPages' => $existing, 'plannedPages' => $planned,
				'showBlogRailOnHome' => ! empty( $content['showBlogRailOnHome'] ),
				'highlightBestsellers' => ! empty( $content['highlightBestsellers'] ),
			],
			'services' => [
				'sourcePostType'=>$service_source,
				'useForNavigation'=>! empty( $services['useForNavigation'] ),
				'items'=>$service_items,
			],
			'resources' => [ 'items' => $resource_items ],
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
			'regulatory' => [
				'fssaiLicense' => substr( preg_replace( '/[^A-Za-z0-9\/-]/', '', (string) ( $regulatory['fssaiLicense'] ?? '' ) ), 0, 30 ),
				'showFssaiOnProducts' => ! empty( $regulatory['showFssaiOnProducts'] ),
				'gstNumber' => substr( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $regulatory['gstNumber'] ?? '' ) ) ), 0, 20 ),
				'showGstOnProducts' => ! empty( $regulatory['showGstOnProducts'] ),
				'manufacturingAddress' => substr( sanitize_textarea_field( (string) ( $regulatory['manufacturingAddress'] ?? '' ) ), 0, 1500 ),
				'showManufacturingAddress' => ! empty( $regulatory['showManufacturingAddress'] ),
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

	/** @return array<string,array{label:string,hierarchical:bool}> */
	public function service_post_types(): array {
		$out = [];
		foreach ( get_post_types( [ 'show_ui'=>true ], 'objects' ) as $name=>$object ) {
			$name = sanitize_key( (string) $name );
			if ( in_array( $name, [ 'post','page','attachment','product','product_variation','nav_menu_item','wp_block','wp_template','wp_template_part','bricks_template' ], true ) ) continue;
			if ( empty( $object->public ) && empty( $object->publicly_queryable ) ) continue;
			$out[ $name ] = [ 'label'=>sanitize_text_field( (string) ( $object->labels->name ?? $object->label ?? $name ) ), 'hierarchical'=>! empty( $object->hierarchical ) ];
		}
		return $out;
	}

	private function default_service_post_type(): string {
		$sources = $this->service_post_types();
		foreach ( [ 'service','services' ] as $candidate ) if ( isset( $sources[ $candidate ] ) ) return $candidate;
		return '';
	}

	/** @return array<string,array{label:string,hierarchical:bool}> */
	public function service_taxonomies( string $post_type ): array {
		$out = [];
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $name=>$object ) {
			if ( 'post_format' === $name || empty( $object->show_ui ) ) continue;
			$out[ sanitize_key( (string) $name ) ] = [ 'label'=>sanitize_text_field( (string) ( $object->labels->name ?? $object->label ?? $name ) ), 'hierarchical'=>! empty( $object->hierarchical ) ];
		}
		return $out;
	}

	/** Registered REST-visible scalar meta is an explicit developer opt-in for owner editing. */
	public function service_meta_fields( string $post_type ): array {
		$out = [];
		$registered = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $post_type ) : [];
		foreach ( $registered as $key=>$args ) {
			$key = sanitize_key( (string) $key );
			$type = sanitize_key( (string) ( $args['type'] ?? 'string' ) );
			if ( '' === $key || str_starts_with( $key, '_' ) || empty( $args['show_in_rest'] ) || empty( $args['single'] ) || ! in_array( $type, [ 'string','number','integer','boolean' ], true ) ) continue;
			$out[ $key ] = [ 'label'=>sanitize_text_field( (string) ( $args['label'] ?? $args['description'] ?? ucwords( str_replace( [ '_','-' ], ' ', $key ) ) ) ), 'type'=>$type ];
		}
		return $out;
	}

	private function inferred_services( string $source ): array {
		$sources = $this->service_post_types();
		if ( ! isset( $sources[ $source ] ) ) return [ 'sourcePostType'=>'', 'useForNavigation'=>false, 'items'=>[] ];
		$taxonomies = $this->service_taxonomies( $source );
		$meta_fields = $this->service_meta_fields( $source );
		$items = [];
		foreach ( get_posts( [ 'post_type'=>$source, 'post_status'=>[ 'publish','draft','pending' ], 'posts_per_page'=>100, 'orderby'=>'menu_order title', 'order'=>'ASC', 'suppress_filters'=>false ] ) as $post ) {
			$paths = [];
			foreach ( $taxonomies as $taxonomy=>$definition ) {
				$assigned = wp_get_object_terms( $post->ID, $taxonomy );
				$paths[ $taxonomy ] = is_wp_error( $assigned ) ? '' : implode( "\n", array_map( fn( $term ): string => $this->term_path( $term, $taxonomy, ! empty( $definition['hierarchical'] ) ), $assigned ) );
			}
			$meta = [];
			foreach ( $meta_fields as $meta_key=>$definition ) {
				$value = get_post_meta( $post->ID, $meta_key, true );
				$meta[ $meta_key ] = 'boolean' === $definition['type'] ? ! empty( $value ) : ( is_scalar( $value ) ? $value : '' );
			}
			$items[] = [
				'stableId'=>'service-' . $post->ID, 'recordId'=>(int) $post->ID,
				'title'=>sanitize_text_field( (string) $post->post_title ), 'summary'=>sanitize_textarea_field( (string) $post->post_excerpt ),
				'description'=>wp_kses_post( (string) $post->post_content ), 'imageId'=>absint( get_post_thumbnail_id( $post->ID ) ),
				'parentId'=>(int) $post->post_parent, 'menuOrder'=>(int) $post->menu_order,
				'status'=>'publish' === $post->post_status ? 'publish' : 'draft', 'categoryPaths'=>sanitize_textarea_field( (string) get_post_meta( $post->ID, self::SERVICE_CATEGORY_PLAN_META, true ) ), 'taxonomyPaths'=>$paths, 'meta'=>$meta,
			];
		}
		return [ 'sourcePostType'=>$source, 'useForNavigation'=>false, 'items'=>$items, 'taxonomies'=>$taxonomies, 'customFields'=>$meta_fields, 'hierarchical'=>$sources[ $source ]['hierarchical'] ];
	}

	private function apply_services_context( array $profile, int $actor_id ): array {
		$source = sanitize_key( (string) ( $profile['services']['sourcePostType'] ?? '' ) );
		$sources = $this->service_post_types();
		if ( ! isset( $sources[ $source ] ) ) return $profile;
		$object = get_post_type_object( $source );
		if ( ! $object || ! user_can( $actor_id, $object->cap->edit_posts ) ) return $profile;
		$taxonomies = $this->service_taxonomies( $source );
		$meta_fields = $this->service_meta_fields( $source );
		foreach ( $profile['services']['items'] as &$item ) {
			$post_id = absint( $item['recordId'] ?? 0 );
			if ( $post_id && ( $source !== get_post_type( $post_id ) || ! user_can( $actor_id, 'edit_post', $post_id ) ) ) continue;
			if ( ! $post_id && ! user_can( $actor_id, $object->cap->create_posts ) ) continue;
			$already_published = $post_id && 'publish' === get_post_status( $post_id );
			$status = 'publish' === ( $item['status'] ?? '' ) && ( $already_published || user_can( $actor_id, $object->cap->publish_posts ) ) ? 'publish' : 'draft';
			$parent_id = absint( $item['parentId'] ?? 0 );
			if ( empty( $sources[ $source ]['hierarchical'] ) || $parent_id === $post_id || $source !== get_post_type( $parent_id ) || ( $post_id && in_array( $post_id, get_post_ancestors( $parent_id ), true ) ) ) $parent_id = 0;
			$payload = [ 'post_type'=>$source, 'post_title'=>$item['title'], 'post_excerpt'=>$item['summary'], 'post_content'=>$item['description'], 'post_status'=>$status, 'post_parent'=>$parent_id, 'menu_order'=>(int) $item['menuOrder'] ];
			if ( $post_id ) $payload['ID'] = $post_id; else $payload['post_author'] = $actor_id;
			$result = wp_insert_post( wp_slash( $payload ), true );
			if ( is_wp_error( $result ) || ! $result ) continue;
			$post_id = (int) $result; $item['recordId'] = $post_id; $item['stableId'] = 'service-' . $post_id;
			if ( ! empty( $item['imageId'] ) ) set_post_thumbnail( $post_id, absint( $item['imageId'] ) ); else delete_post_thumbnail( $post_id );
			foreach ( $meta_fields as $meta_key=>$definition ) {
				if ( ! user_can( $actor_id, 'edit_post_meta', $post_id, $meta_key ) ) continue;
				$value = $item['meta'][ $meta_key ] ?? '';
				if ( 'boolean' === $definition['type'] ) update_post_meta( $post_id, $meta_key, ! empty( $value ) ? '1' : '0' );
				elseif ( '' === (string) $value ) delete_post_meta( $post_id, $meta_key );
				else update_post_meta( $post_id, $meta_key, 'integer' === $definition['type'] ? (int) $value : ( 'number' === $definition['type'] ? (float) $value : sanitize_textarea_field( (string) $value ) ) );
			}
			$generic_paths = (string) ( $item['categoryPaths'] ?? '' );
			$generic_target = '';
			foreach ( $taxonomies as $taxonomy=>$definition ) { if ( ! empty( $definition['hierarchical'] ) ) { $generic_target = $taxonomy; break; } }
			if ( '' === $generic_target && $taxonomies ) $generic_target = (string) array_key_first( $taxonomies );
			if ( ! $taxonomies ) {
				if ( '' !== trim( $generic_paths ) ) update_post_meta( $post_id, self::SERVICE_CATEGORY_PLAN_META, sanitize_textarea_field( $generic_paths ) );
				else delete_post_meta( $post_id, self::SERVICE_CATEGORY_PLAN_META );
			}
			foreach ( $taxonomies as $taxonomy=>$definition ) {
				$paths = (string) ( $item['taxonomyPaths'][ $taxonomy ] ?? '' );
				if ( '' === trim( $paths ) && $generic_paths && $generic_target === $taxonomy ) { $paths = $generic_paths; $generic_paths = ''; }
				$term_ids = $this->ensure_term_paths( $taxonomy, $paths, ! empty( $definition['hierarchical'] ), $actor_id );
				if ( null !== $term_ids ) wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			}
			if ( $taxonomies && '' === trim( $generic_paths ) ) delete_post_meta( $post_id, self::SERVICE_CATEGORY_PLAN_META );
		}
		unset( $item );
		$navigation = ! empty( $profile['services']['useForNavigation'] );
		$failed = array_values( array_filter( $profile['services']['items'], static fn( $item ): bool => empty( $item['recordId'] ) ) );
		$profile['services'] = $this->inferred_services( $source );
		$profile['services']['useForNavigation'] = $navigation;
		$profile['services']['items'] = array_merge( $profile['services']['items'], $failed );
		return $profile;
	}

	private function ensure_term_paths( string $taxonomy, string $raw, bool $hierarchical, int $actor_id ): ?array {
		$object = get_taxonomy( $taxonomy );
		if ( ! $object || ! user_can( $actor_id, $object->cap->assign_terms ) ) return null;
		$may_create = user_can( $actor_id, $object->cap->manage_terms );
		$ids = [];
		foreach ( preg_split( '/[\r\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $path ) {
			$parts = $hierarchical ? preg_split( '/\s*>\s*/', trim( $path ), -1, PREG_SPLIT_NO_EMPTY ) : [ trim( $path ) ];
			$parent = 0;
			foreach ( $parts as $name ) {
				$name = substr( sanitize_text_field( $name ), 0, 200 ); if ( '' === $name ) continue;
				$exists = term_exists( $name, $taxonomy, $parent );
				if ( ! $exists && $may_create ) $exists = wp_insert_term( $name, $taxonomy, [ 'parent'=>$parent ] );
				if ( ! $exists || is_wp_error( $exists ) ) { $parent = 0; break; }
				$parent = absint( is_array( $exists ) ? $exists['term_id'] : $exists );
			}
			if ( $parent ) $ids[] = $parent;
		}
		return array_values( array_unique( $ids ) );
	}

	private function term_path( $term, string $taxonomy, bool $hierarchical ): string {
		if ( ! $hierarchical || empty( $term->parent ) ) return sanitize_text_field( (string) $term->name );
		$names = [];
		foreach ( array_reverse( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) ) as $ancestor_id ) { $ancestor = get_term( $ancestor_id, $taxonomy ); if ( $ancestor && ! is_wp_error( $ancestor ) ) $names[] = $ancestor->name; }
		$names[] = $term->name;
		return implode( ' > ', array_map( 'sanitize_text_field', $names ) );
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
		if ( ! empty( $profile['contentPlan']['highlightBestsellers'] ) ) {
			$commerce = is_array( $settings['commerce'] ?? null ) ? $settings['commerce'] : [];
			$commerce['bestseller_enabled'] = true;
			$settings['commerce'] = $commerce;
		}
		update_option( DSA_OPTION_SETTINGS, $settings, false );
	}

	/**
	 * Synchronize only public profile fields. Login, email, capabilities and the
	 * WordPress account role are deliberately outside this onboarding boundary.
	 */
	private function apply_people_context( array $profile, int $actor_id ): array {
		$stored = get_option( self::OPTION_PROFILE, [] );
		$old_ids = [];
		foreach ( (array) ( $stored['about']['team']['members'] ?? [] ) as $member ) {
			if ( ! empty( $member['userId'] ) ) $old_ids[] = absint( $member['userId'] );
		}
		$new_ids = [];
		foreach ( (array) ( $profile['about']['team']['members'] ?? [] ) as $index => $member ) {
			$user_id = absint( $member['userId'] ?? 0 );
			if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) continue;
			$new_ids[] = $user_id;
			if ( user_can( $actor_id, 'edit_user', $user_id ) ) {
				wp_update_user( [ 'ID'=>$user_id, 'display_name'=>$member['name'], 'description'=>$member['bio'] ] );
				update_user_meta( $user_id, self::USER_META_TEAM_TITLE, $member['title'] );
				update_user_meta( $user_id, self::USER_META_LINKEDIN, $member['linkedin'] );
				update_user_meta( $user_id, self::USER_META_AVATAR_ID, absint( $member['imageId'] ) );
				update_user_meta( $user_id, self::USER_META_TEAM_MEMBER, ! empty( $profile['about']['team']['enabled'] ) ? '1' : '0' );
				update_user_meta( $user_id, self::USER_META_TEAM_ORDER, (int) $index );
			}
		}
		foreach ( array_diff( array_unique( $old_ids ), array_unique( $new_ids ) ) as $removed_id ) {
			if ( user_can( $actor_id, 'edit_user', $removed_id ) ) {
				delete_user_meta( $removed_id, self::USER_META_TEAM_MEMBER );
				delete_user_meta( $removed_id, self::USER_META_TEAM_ORDER );
			}
		}

		$founder = &$profile['about']['founder'];
		$founder_id = absint( $founder['userId'] ?? 0 );
		if ( $founder_id && get_user_by( 'id', $founder_id ) && user_can( $actor_id, 'edit_user', $founder_id ) ) {
			wp_update_user( [ 'ID'=>$founder_id, 'display_name'=>$founder['name'], 'description'=>$founder['bio'] ] );
			update_user_meta( $founder_id, self::USER_META_FOUNDER_TITLE, $founder['title'] );
			update_user_meta( $founder_id, self::USER_META_LINKEDIN, $founder['linkedin'] );
			update_user_meta( $founder_id, self::USER_META_AVATAR_ID, absint( $founder['imageId'] ) );
		}
		return $this->resolve_people( $profile );
	}

	/** Resolve linked records from WordPress so the profile is a reference, not a duplicate authority. */
	private function resolve_people( array $profile ): array {
		if ( ! isset( $profile['about'] ) || ! is_array( $profile['about'] ) ) return $profile;
		if ( isset( $profile['about']['founder'] ) && is_array( $profile['about']['founder'] ) ) {
			$profile['about']['founder'] = $this->resolve_person( $profile['about']['founder'], true );
		}
		$team = is_array( $profile['about']['team'] ?? null ) ? $profile['about']['team'] : [ 'enabled'=>false, 'members'=>[] ];
		$team['enabled'] = ! empty( $team['enabled'] );
		$team['members'] = array_values( array_map( [ $this, 'resolve_person' ], is_array( $team['members'] ?? null ) ? $team['members'] : [] ) );
		$profile['about']['team'] = $team;
		return $profile;
	}

	private function resolve_person( array $person, bool $founder = false ): array {
		$user_id = absint( $person['userId'] ?? 0 );
		$user = $user_id ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user ) return $person + [ 'userId'=>0, 'linkedin'=>'', 'imageId'=>0 ];
		$person['userId'] = $user_id;
		$person['name'] = sanitize_text_field( (string) $user->display_name );
		$person['bio'] = sanitize_textarea_field( (string) $user->description );
		$title_key = $founder ? self::USER_META_FOUNDER_TITLE : self::USER_META_TEAM_TITLE;
		$person['title'] = sanitize_text_field( (string) get_user_meta( $user_id, $title_key, true ) );
		$person['linkedin'] = esc_url_raw( (string) get_user_meta( $user_id, self::USER_META_LINKEDIN, true ) );
		$person['imageId'] = absint( get_user_meta( $user_id, self::USER_META_AVATAR_ID, true ) );
		$person['linkedToWordPressUser'] = true;
		return $person;
	}

	/** Keep only references for linked users; their public fields live on the user record. */
	private function compact_people_references( array $profile ): array {
		$founder = (array) ( $profile['about']['founder'] ?? [] );
		if ( ! empty( $founder['userId'] ) ) {
			$profile['about']['founder'] = [ 'userId'=>absint( $founder['userId'] ) ];
		}
		foreach ( (array) ( $profile['about']['team']['members'] ?? [] ) as $index => $member ) {
			if ( empty( $member['userId'] ) ) continue;
			$profile['about']['team']['members'][ $index ] = [
				'id'=>sanitize_key( (string) ( $member['id'] ?? '' ) ),
				'userId'=>absint( $member['userId'] ),
				'order'=>(int) ( $member['order'] ?? $index ),
			];
		}
		return $profile;
	}

	/** Native service posts remain canonical; Design Context retains only unresolved plans. */
	private function compact_service_references( array $profile ): array {
		if ( empty( $profile['services']['sourcePostType'] ) ) return $profile;
		$profile['services']['items'] = array_values( array_filter( (array) ( $profile['services']['items'] ?? [] ), static fn( $item ): bool => is_array( $item ) && empty( $item['recordId'] ) ) );
		return $profile;
	}
}
