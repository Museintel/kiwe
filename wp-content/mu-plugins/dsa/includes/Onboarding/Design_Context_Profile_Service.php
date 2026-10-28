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
	public const OPTION_SEO_REPORT = 'kiwe_overall_seo_report_v1';
	public const PAGE_VISIBILITY_META = '_kiwe_search_visibility';
	public const USER_META_TEAM_MEMBER = 'kiwe_public_team_member';
	public const USER_META_TEAM_TITLE = 'kiwe_team_title';
	public const USER_META_FOUNDER_TITLE = 'kiwe_founder_title';
	public const USER_META_LINKEDIN = 'kiwe_linkedin_url';
	public const USER_META_TEAM_ORDER = 'kiwe_team_order';
	public const USER_META_AVATAR_ID = 'kiwe_avatar_id';
	/** Native fallback shared with same-site Service archives when the source has no taxonomy. */
	public const SERVICE_CATEGORY_PLAN_META = '_kiwe_service_category_plan';

	/** Active integrations, never the site-type label, determine commerce availability. */
	/** A Core edit never rewrites dormant Store context or its existing intent. */
	private function retain_inactive_store( array $profile, array $stored ): array {
		if ( $this->commerce_available() ) return $profile;
		foreach ( [ 'commerce','regulatory' ] as $key ) { if ( array_key_exists( $key, $stored ) ) $profile[$key] = $stored[$key]; else unset( $profile[$key] ); }
		if ( array_key_exists( 'highlightBestsellers', (array) ( $stored['contentPlan'] ?? [] ) ) ) $profile['contentPlan']['highlightBestsellers'] = $stored['contentPlan']['highlightBestsellers'];
		return $profile;
	}

	public function commerce_available(): bool {
		return \DSA\Apps\Store_App_Service::enabled() && (bool) apply_filters( 'kiwe_design_context_commerce_available', true );
	}

	private function services_enabled( array $stored ): bool {
		$services = (array) ( $stored['services'] ?? [] );
		// Existing configured services keep working; new sites start disabled.
		return array_key_exists( 'enabled', $services ) ? ! empty( $services['enabled'] ) : ( ! empty( $services['sourcePostType'] ) || ! empty( $services['items'] ) );
	}

	public function current(): array {
		$stored = get_option( self::OPTION_PROFILE, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$inferred = $this->inferred( $stored );
		$profile = array_replace_recursive( $inferred, $stored );
		$profile['business']['activities'] = \DSA\Bricks\Design_Context_Binding_Service::activities( $profile );
		// Native owners win, including edits made outside Kiwe.
		foreach ( [ 'siteName', 'tagline', 'logoId', 'logoInverseId', 'siteIconId' ] as $key ) $profile['identity'][ $key ] = $inferred['identity'][ $key ];
		foreach ( ['squareLogoId','roundLogoId'] as $asset ) $profile['identity'][$asset] = self::public_brand_image($profile['identity'][$asset] ?? 0);
		foreach ( [ 'phone', 'email' ] as $key ) $profile['contact'][ $key ] = $inferred['contact'][ $key ];
		$profile['localization'] = $inferred['localization'];
		$profile['seo']['allowIndexing'] = $inferred['seo']['allowIndexing'];
		$profile['commerce']['enabled'] = $this->commerce_available() && ! empty( $profile['commerce']['enabled'] );
		if ( function_exists( 'WC' ) ) {
			$profile['contact']['address'] = $inferred['contact']['address'];
			foreach ( [ 'currency', 'currencyPosition', 'priceDecimals', 'weightUnit', 'dimensionUnit', 'taxEnabled', 'pricesIncludeTax', 'sellingLocationMode', 'sellingCountries', 'excludedSellingCountries', 'shippingLocationMode', 'shippingCountries' ] as $key ) $profile['commerce'][ $key ] = $inferred['commerce'][ $key ];
		}
		$profile['services']['enabled'] = $this->services_enabled( $stored );
		$profile['contentPlan']['existingPages'] = $inferred['contentPlan']['existingPages'];
		$profile['contentPlan']['plannedPages'] = is_array( $stored['contentPlan']['plannedPages'] ?? null ) ? array_values( $stored['contentPlan']['plannedPages'] ) : [];
		$profile['contentPlan']['testimonialSources'] = Context_Selection_Service::testimonial_sources( (array) ( $stored['contentPlan']['testimonialSources'] ?? [] ) );
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

	/** Private identity card projection. Native WordPress options remain the authority. */
	public function identity_projection(): array {
		$profile = $this->current();
		$identity = (array) ( $profile['identity'] ?? [] );
		$contact = (array) ( $profile['contact'] ?? [] );
		$state = [
			'siteName'       => wp_strip_all_tags( (string) ( $identity['siteName'] ?? '' ) ),
			'tagline'        => wp_strip_all_tags( (string) ( $identity['tagline'] ?? '' ) ),
			'industry'       => sanitize_text_field( (string) ( $identity['industry'] ?? '' ) ),
			'industrySector' => sanitize_key( (string) ( $identity['industrySector'] ?? '' ) ),
			'siteType'       => sanitize_key( (string) ( $identity['siteType'] ?? 'business' ) ),
			'logoId'         => absint( $identity['logoId'] ?? 0 ),
			'logoInverseId'  => absint( $identity['logoInverseId'] ?? 0 ),
			'siteIconId'     => absint( $identity['siteIconId'] ?? 0 ),
			'phone'          => Site_Identity_Service::store_phone(),
			'email'          => Site_Identity_Service::store_email(),
		];
		$state['logoUrl'] = $state['logoId'] ? esc_url_raw( (string) wp_get_attachment_url( $state['logoId'] ) ) : '';
		$state['logoInverseUrl'] = $state['logoInverseId'] ? esc_url_raw( (string) wp_get_attachment_url( $state['logoInverseId'] ) ) : '';
		$state['siteIconUrl'] = $state['siteIconId'] ? esc_url_raw( (string) wp_get_attachment_url( $state['siteIconId'] ) ) : '';
		foreach ( ['squareLogo','roundLogo'] as $asset ) {
			$state[$asset.'Id'] = self::public_brand_image($identity[$asset.'Id'] ?? 0);
			$state[$asset.'Url'] = $state[$asset.'Id'] ? esc_url_raw((string) wp_get_attachment_url($state[$asset.'Id'])) : '';
		}
		$state['activities'] = \DSA\Bricks\Design_Context_Binding_Service::activities($profile);
		$state['expected'] = hash( 'sha256', wp_json_encode( [ $state['siteName'], $state['tagline'], $state['industry'], $state['industrySector'], $state['siteType'], $state['logoId'], $state['logoInverseId'], $state['siteIconId'], $state['phone'], $state['email'], $state['squareLogoId'], $state['roundLogoId'], $state['activities'] ] ) );
		return $state;
	}

	public function is_complete(): bool {
		return ! empty( $this->status()['completed'] );
	}

	public static function saved_seo_strength(): ?int {
		$report = Search_Report_Snapshot::read()['report'];
		if ( is_array( $report ) && isset( $report['score'] ) ) {
			return max( 0, min( 100, absint( $report['score'] ) ) );
		}
		$status = get_option( self::OPTION_STATUS, [] );
		if ( ! is_array( $status ) || empty( $status['completed'] ) || ! isset( $status['scores']['seoStrength'] ) ) return null;
		return max( 0, min( 100, absint( $status['scores']['seoStrength'] ) ) );
	}

	/** Section inputs are scoped before the existing sanitizer and native writers run. */
	public function save_section( string $section, array $raw, int $user_id, string $invitation_id = '', string $changed_field = '' ) {
		$groups = [ 'identity'=>[ 'identity','business' ], 'story'=>[ 'audience','about','seo' ], 'contact'=>[ 'contact','localization' ], 'brand'=>[ 'brand' ], 'website-plan'=>[ 'contentPlan' ], 'services'=>[ 'services' ], 'store'=>[ 'commerce','regulatory' ] ];
		if ( ! isset( $groups[ $section ] ) || ( 'store' === $section && ! $this->commerce_available() ) ) return new \WP_Error( 'kiwe_section', 'This section is unavailable.' );
		if ( ! user_can( $user_id, 'kiwe_manage_context' ) ) return new \WP_Error( 'kiwe_section_forbidden', 'Website management access required.' );
		$latest = $this->current();
		$merged = $latest;
		foreach ( $groups[ $section ] as $group ) $merged[ $group ] = is_array( $raw[ $group ] ?? null ) ? $raw[ $group ] : [];
		if ( 'identity' === $section ) $merged['identity']['description'] = $latest['identity']['description'];
		if ( 'story' === $section ) $merged['identity']['description'] = $raw['identity']['description'] ?? '';
		if ( 'website-plan' === $section ) {
			$merged['contentPlan']['existingPages'] = $latest['contentPlan']['existingPages'];
			$merged['contentPlan']['plannedPages'] = $latest['contentPlan']['plannedPages'];
		}
		// Binding arbitrary post types is a developer decision, never an input shortcut.
		if ( 'services' === $section && ! user_can( $user_id, 'manage_options' ) ) $merged['services']['sourcePostType'] = $latest['services']['sourcePostType'];
		// Individual contact tiles can complete a fresh profile incrementally. The
		// overall onboarding status still records every missing required field.
		$validation = null;
		if ( 'contact' === $section && '' !== $changed_field ) {
			$validation = 'contact.phone' === $changed_field ? [ 'phone' ] : ( 'contact.email' === $changed_field ? [ 'email' ] : [] );
		}
		return $this->save( $merged, $user_id, $invitation_id, $section, $validation );
	}

	/**
	 * Apply only the identity values a client changed.
	 *
	 * This is deliberately narrower than the onboarding form: it preserves every
	 * unrelated native value, accepts an explicit image removal, and leaves the
	 * overall Design Context status incomplete until required setup is restored.
	 */
	public function save_identity_changes( array $changes, string $expected, int $user_id ) {
		if ( ! user_can( $user_id, 'kiwe_manage_context' ) ) return new \WP_Error( 'kiwe_identity_forbidden', __( 'Website management access required.', 'dsa' ) );
		$current = $this->identity_projection();
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $current['expected'], $expected ) ) return new \WP_Error( 'kiwe_identity_stale', __( 'Business identity changed in another screen. Reload and review it before saving.', 'dsa' ), [ 'status'=>409 ] );
		$allowed = [ 'siteName','tagline','industry','industrySector','siteType','logoId','logoInverseId','siteIconId','squareLogoId','roundLogoId','activities' ];
		if ( ! $changes || count( $changes ) > count( $allowed ) || array_diff( array_keys( $changes ), $allowed ) ) return new \WP_Error( 'kiwe_identity_changes', __( 'Choose one or more supported identity details to save.', 'dsa' ), [ 'status'=>400 ] );

		$latest = $this->current();
		$text_limits = [ 'siteName'=>200, 'tagline'=>160, 'industry'=>200 ];
		$sectors = [ '', 'food_beverage','retail','manufacturing','healthcare','education','hospitality','professional_services','technology','media','nonprofit','real_estate','other' ];
		$types = [ 'business','ecommerce','publication','portfolio','nonprofit','community','education','service','other' ];
		foreach ( $changes as $key=>$value ) {
			if ( 'activities' === $key ) {
				$keys = array_keys(\DSA\Bricks\Design_Context_Binding_Service::activities([]));
				if ( ! is_array($value) || count($value)!==count($keys) || array_diff(array_keys($value),$keys) || count(array_filter($value,'is_bool'))!==count($keys) ) return new \WP_Error('kiwe_identity_activities',__('Choose the supported business activities.','dsa'),['status'=>400]);
				$latest['business']['activities'] = array_replace(array_fill_keys($keys,false),$value);
				continue;
			} elseif ( isset( $text_limits[ $key ] ) ) {
				if ( ! is_string( $value ) ) return new \WP_Error( 'kiwe_identity_value', __( 'Use text for this identity field.', 'dsa' ), [ 'status'=>400 ] );
				$value = sanitize_text_field( $value );
				$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
				if ( $length > $text_limits[ $key ] || ( 'siteName' === $key && '' === $value ) ) return new \WP_Error( 'kiwe_identity_value', 'siteName' === $key ? __( 'Website name is required.', 'dsa' ) : __( 'This identity value is too long.', 'dsa' ), [ 'status'=>400 ] );
			} elseif ( 'industrySector' === $key || 'siteType' === $key ) {
				if ( ! is_string( $value ) ) return new \WP_Error( 'kiwe_identity_value', __( 'Choose an available identity option.', 'dsa' ), [ 'status'=>400 ] );
				$value = sanitize_key( $value );
				if ( ! in_array( $value, 'industrySector' === $key ? $sectors : $types, true ) ) return new \WP_Error( 'kiwe_identity_value', __( 'Choose an available identity option.', 'dsa' ), [ 'status'=>400 ] );
			} else {
				if ( ! is_int( $value ) || $value < 0 ) return new \WP_Error( 'kiwe_identity_image', __( 'Choose an image from this website or remove the current image.', 'dsa' ), [ 'status'=>400 ] );
				if ( $value && ! wp_attachment_is_image( $value ) ) return new \WP_Error( 'kiwe_identity_image', __( 'Choose an image from this website.', 'dsa' ), [ 'status'=>400 ] );
				if ( $value && in_array($key,['squareLogoId','roundLogoId'],true) && ! self::public_brand_image($value) ) return new \WP_Error('kiwe_identity_image',__('Choose a public business image.','dsa'),['status'=>400]);
			}
			$latest['identity'][ $key ] = $value;
		}
		$validation = array_key_exists( 'siteName', $changes ) ? [ 'siteName' ] : [];
		$result = $this->save( $latest, $user_id, '', 'identity', $validation );
		if ( is_wp_error( $result ) ) return $result;
		$after = $this->identity_projection();
		foreach ( $changes as $key=>$value ) if ( 'activities' === $key ? $after[$key] != $value : $after[$key] !== $value ) return new \WP_Error( 'kiwe_identity_unconfirmed', __( 'Identity changed during the save. Reload and review the current details.', 'dsa' ), [ 'status'=>409 ] );
		return $after;
	}

	public function save( array $raw, int $user_id, string $invitation_id = '', string $section = '', ?array $validation_fields = null ) {
		$stored = (array) get_option( self::OPTION_PROFILE, [] );
		// Removed editors cannot erase legacy records on an unrelated save.
		$raw['about']['team'] = $stored['about']['team'] ?? [ 'enabled'=>false, 'members'=>[] ];
		$raw['resources'] = $stored['resources'] ?? [ 'items'=>[] ];
		// Page inventory is inferred from WP; legacy plans are retained, never edited here.
		$raw['contentPlan']['plannedPages'] = $stored['contentPlan']['plannedPages'] ?? [];
		if ( ! $this->commerce_available() ) {
			$raw['commerce'] = $stored['commerce'] ?? [];
			$raw['commerce']['enabled'] = false;
			$raw['regulatory'] = $stored['regulatory'] ?? [];
		}
		if ( empty( $raw['services']['enabled'] ) ) $raw['services'] = array_replace( (array) ( $stored['services'] ?? [] ), [ 'enabled'=>false ] );
		// Site privacy stays with WordPress; client choices apply to individual records.
		$raw['seo'] = is_array( $raw['seo'] ?? null ) ? $raw['seo'] : [];
		$raw['seo']['allowIndexing'] = '0' !== (string) get_option( 'blog_public', '1' );
		$profile = $this->sanitize( $raw );
		$errors  = $this->required_errors( $profile );
		$section_fields = [ 'identity'=>[ 'siteName','logoId','siteIconId' ], 'contact'=>[ 'phone','email','address' ] ];
		$blocking_errors = $section ? array_intersect_key( $errors, array_fill_keys( $section_fields[ $section ] ?? [], true ) ) : $errors;
		if ( in_array( $section, [ 'identity','contact' ], true ) && null !== $validation_fields ) $blocking_errors = array_intersect_key( $blocking_errors, array_fill_keys( $validation_fields, true ) );
		if ( $blocking_errors ) {
			return new \WP_Error( 'kiwe_onboarding_incomplete', implode( ' ', $blocking_errors ), [ 'fields' => array_keys( $blocking_errors ) ] );
		}

		if ( ! $section || 'identity' === $section ) {
		update_option( 'blogname', $profile['identity']['siteName'] );
		update_option( 'blogdescription', $profile['identity']['tagline'] );
		update_option( Site_Identity_Service::OPTION_LOGO, $profile['identity']['logoId'], false );
		update_option( Site_Identity_Service::OPTION_LOGO_INVERSE, $profile['identity']['logoInverseId'], false );
		set_theme_mod( 'custom_logo', $profile['identity']['logoId'] );
		update_option( 'site_icon', $profile['identity']['siteIconId'], false );
		}
		if ( ! $section || 'contact' === $section ) {
		update_option( Site_Identity_Service::OPTION_STORE_PHONE, $profile['contact']['phone'], false );
		update_option( Site_Identity_Service::OPTION_STORE_EMAIL, $profile['contact']['email'], false );
		if ( in_array( $profile['localization']['timezone'], timezone_identifiers_list(), true ) ) {
			update_option( 'timezone_string', $profile['localization']['timezone'] );
			update_option( 'gmt_offset', 0 );
		}
		}

		if ( ! $section || in_array( $section, [ 'contact','website-plan' ], true ) ) $this->apply_kiwe_public_context( $profile );
		if ( ! $section || 'story' === $section ) {
			$profile = $this->apply_people_context( $profile, $user_id );
		}
		if ( ! $section || 'services' === $section ) $profile = $this->apply_services_context( $profile, $user_id );
		if ( ( ! $section || in_array( $section, [ 'store','contact' ], true ) ) && $profile['commerce']['enabled'] && function_exists( 'WC' ) ) {
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
		// Legacy unlinked entries remain recoverable but are never projected publicly.
		$stored_profile['about']['team'] = $stored['about']['team'] ?? [ 'enabled'=>false, 'members'=>[] ];
		// Setup choices belong to their narrow journey writer, not stale section forms.
		$stored_profile['journey'] = is_array( $stored['journey'] ?? null ) ? $stored['journey'] : [];
		$stored_profile = $this->retain_inactive_store( $stored_profile, $stored );
		update_option( self::OPTION_PROFILE, $stored_profile, false );
		update_option(
			self::OPTION_STATUS,
			[
				'completed'    => ! $errors,
				'completedAt'  => $errors ? '' : gmdate( 'c' ),
				'completedBy'  => $user_id,
				'invitationId' => sanitize_key( $invitation_id ),
				'scores'       => $profile['scores'],
			],
			false
		);
		// Business edits never synchronously scan the site's full posts/media catalog.
		do_action( 'kiwe_seo_score_dirty' );

		return $profile;
	}

	public function public_context( bool $administrator = false ): array {
		$profile = $this->current();
		$scores = $this->scores( $profile );
		$overall_seo = self::saved_seo_strength();
		if ( null !== $overall_seo ) $scores['seoStrength'] = $overall_seo;
		$address = $profile['contact']['address'];
		$about   = $profile['about'];
		unset( $about['team']['enabledByOwner'] ); // Private applicability is not a public person field.

		$founder_image_id = absint( $about['founder']['imageId'] ?? 0 );
		$about['founder']['image'] = $founder_image_id ? esc_url_raw( (string) wp_get_attachment_url( $founder_image_id ) ) : '';
		foreach ( $about['team']['members'] as &$member ) {
			$image_id = absint( $member['imageId'] ?? 0 );
			$member['image'] = $image_id ? esc_url_raw( (string) wp_get_attachment_url( $image_id ) ) : esc_url_raw( (string) ( $member['image'] ?? '' ) );
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
		$services = ! empty( $profile['services']['enabled'] ) ? $profile['services'] : [ 'enabled'=>false, 'sourcePostType'=>'', 'useForNavigation'=>false, 'items'=>[] ];
		if ( ! $administrator ) {
			$services['items'] = array_values( array_filter( (array) ( $services['items'] ?? [] ), static fn( $item ): bool => ! empty( $item['recordId'] ) && 'publish' === get_post_status( absint( $item['recordId'] ) ) ) );
			foreach ( $services['items'] as &$service_item ) $service_item['categoryPaths'] = '';
			unset( $service_item );
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
				'squareLogo'  => ! empty($profile['identity']['squareLogoId']) ? (string) wp_get_attachment_url($profile['identity']['squareLogoId']) : '',
				'roundLogo'   => ! empty($profile['identity']['roundLogoId']) ? (string) wp_get_attachment_url($profile['identity']['roundLogoId']) : '',
			],
			'business' => [ 'activities'=>\DSA\Bricks\Design_Context_Binding_Service::activities($profile) ],
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
			'testimonials' => [ 'source'=>'public-community-feedback', 'sources'=>Context_Selection_Service::testimonial_sources(), 'items'=>Context_Selection_Service::testimonials( 20 ), 'limit'=>20, 'queryType'=>'kiwe_testimonials', 'note'=>'Live approved public feedback selected by pinned, team-heart or optional popular sources. Preserve source context and genuine product ratings. Never invent quotes, identities or verified-purchase claims.' ],
			'resources'   => [
				'items' => [],
				'source' => 'wordpress-media-library',
				'authority' => 'explicit-design-selections-and-inherited-public-images',
				'query' => [ 'resource'=>'media', 'mimeType'=>'', 'contextOnly'=>true, 'page'=>1 ],
				'note' => 'Use the SiteGraph contextOnly media query and pagination. Native content, gallery, identity and public team images are inherited; other files require explicit design selection. Automatic inclusion alone never completes the Media setup step.',
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
			'scores' => $scores,
			'privacy' => [
				'publicBusinessContextOnly' => true,
				'operationalAddressIncluded' => $administrator,
				'adminIdentityExcluded' => true,
			],
		];
	}

	public function scores( ?array $profile = null ): array {
		$p = $profile ?: $this->current();
		$seo_checks = $this->seo_evidence_checks( $p );
		$design_checks = [
			! empty( $p['identity']['siteName'] ), ! empty( $p['identity']['description'] ),
			! empty( $p['identity']['industry'] ), ! empty( $p['audience']['primary'] ),
			! empty( $p['contact']['phone'] ), ! empty( $p['contact']['email'] ),
			! empty( $p['brand']['tone'] ), ! empty( $p['brand']['colors'] ),
			! empty( $p['contentPlan']['existingPages'] ) || ! empty( $p['contentPlan']['plannedPages'] ),
			! empty( $p['brand']['notes'] ),
			! empty( $p['about']['story'] ), ! empty( $p['about']['usp'] ), ! empty( $p['about']['values'] ),
			! empty( $p['identity']['logoId'] ),
		];
		$percent = static fn( array $checks ): int => (int) round( 100 * count( array_filter( $checks ) ) / max( 1, count( $checks ) ) );
		return [ 'seoStrength' => $percent( $seo_checks ), 'designContextStrength' => $percent( $design_checks ) ];
	}

	/** Compatibility entry: request background work and return only the last completed snapshot. */
	public function overall_seo_report( ?array $profile = null, bool $persist = true ): array {
		if ( $persist && \DSA\Apps\SEO_App_Service::enabled() ) do_action( 'kiwe_seo_score_dirty' );
		return Search_Report_Snapshot::read()['report'] ?? [];
	}

	/** Onboarding evidence without current(), page inventories, service lists or product aggregation. */
	public function seo_evidence_component() {
		global $wpdb;
		$p = get_option( self::OPTION_PROFILE, [] );
		$p = is_array( $p ) ? $p : [];
		foreach ( [ 'identity','contact','contentPlan','seo','about' ] as $key ) if ( ! is_array( $p[ $key ] ?? null ) ) $p[ $key ] = [];
		$p['identity']['siteName'] = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$p['identity']['tagline'] = wp_strip_all_tags( (string) get_bloginfo( 'description' ) );
		$p['identity']['logoId'] = Site_Identity_Service::attachment_id();
		$p['identity']['siteIconId'] = (int) get_option( 'site_icon', 0 );
		$p['contact']['email'] = Site_Identity_Service::store_email();
		$p['contact']['phone'] = Site_Identity_Service::store_phone();
		$page = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status IN ('publish','draft','private') LIMIT 1" );
		if ( $wpdb->last_error ) return new \WP_Error( 'score_evidence', 'Could not read page evidence.' );
		$p['contentPlan']['existingPages'] = (bool) $page;
		$checks = $this->seo_evidence_checks( $p );
		$ready = count( array_filter( $checks ) ); $total = count( $checks );
		return [ 'label'=>'Onboarding evidence','score'=>(int) round( 100 * $ready / max( 1, $total ) ),'ready'=>$ready,'total'=>$total,'weight'=>20,'active'=>true ];
	}

	private function seo_evidence_checks( array $p ): array {
		return [
			! empty( $p['identity']['siteName'] ), ! empty( $p['identity']['tagline'] ),
			! empty( $p['seo']['homepageDescription'] ), ! empty( $p['identity']['logoId'] ),
			! empty( $p['identity']['siteIconId'] ), ! empty( $p['contact']['email'] ),
			! empty( $p['contact']['phone'] ), ! empty( $p['contentPlan']['existingPages'] ) || ! empty( $p['contentPlan']['plannedPages'] ),
			! empty( $p['seo']['legalName'] ), ! empty( $p['seo']['primaryGoal'] ),
			! empty( $p['seo']['searchIntent'] ), ! empty( $p['seo']['proofPoints'] ),
			! empty( $p['about']['story'] ), ! empty( $p['about']['mission'] ) || ! empty( $p['about']['vision'] ),
		];
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
		update_option( self::OPTION_PROFILE, $this->retain_inactive_store( $this->compact_service_references( $this->compact_people_references( $profile ) ), (array) get_option( self::OPTION_PROFILE, [] ) ), false );
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
				'visibility' => SEO_Context_Service::page_noindex( (int) $page->ID ) ? 'secondary' : 'primary',
			];
		}

		$product_plan = $this->product_plan();
		return [
			'identity' => [
				'siteName' => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'tagline' => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
				'description' => '', 'industry' => '', 'industrySector' => '', 'siteType' => $this->commerce_available() ? 'ecommerce' : 'business',
				'logoId' => Site_Identity_Service::attachment_id(), 'logoInverseId' => Site_Identity_Service::attachment_id( Site_Identity_Service::OPTION_LOGO_INVERSE ), 'siteIconId' => (int) get_option( 'site_icon', 0 ),
				'squareLogoId'=>0, 'roundLogoId'=>0,
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
			'services' => $this->services_enabled( $stored ) ? $this->inferred_services( sanitize_key( (string) ( $stored['services']['sourcePostType'] ?? '' ) ) ) : [ 'enabled'=>false, 'sourcePostType'=>'', 'useForNavigation'=>false, 'items'=>[] ],
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
		$count = $this->commerce_available() && post_type_exists( 'product' ) ? (int) ( wp_count_posts( 'product' )->publish ?? 0 ) : 0;
		$min = ''; $max = '';
		global $wpdb;
		$table = $wpdb->prefix . 'wc_product_meta_lookup';
		if ( $count && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$row = $wpdb->get_row( "SELECT MIN(min_price) AS min_price, MAX(max_price) AS max_price FROM {$table} WHERE min_price IS NOT NULL", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$min = isset( $row['min_price'] ) ? (string) $row['min_price'] : '';
			$max = isset( $row['max_price'] ) ? (string) $row['max_price'] : '';
		}
		return [
			'enabled' => $this->commerce_available(), 'expectedProductCount' => $count,
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
				$service_meta[ $meta_key ] = 'boolean' === $definition['type'] ? ! empty( $value ) : ( '' === (string) $value ? '' : ( 'integer' === $definition['type'] ? (int) $value : ( 'number' === $definition['type'] ? (float) $value : substr( sanitize_textarea_field( (string) $value ), 0, 5000 ) ) ) );
			}
			$image_id = absint( $item['imageId'] ?? 0 );
			$service_items[] = [
				'stableId'=>substr( $stable_id, 0, 80 ), 'recordId'=>$record_id, 'title'=>$title,
				'summary'=>substr( sanitize_textarea_field( (string) ( $item['summary'] ?? '' ) ), 0, 2000 ),
				'description'=>substr( wp_kses_post( (string) ( $item['description'] ?? '' ) ), 0, 20000 ),
				'imageId'=>$image_id && wp_attachment_is_image( $image_id ) ? $image_id : 0,
				'parentId'=>absint( $item['parentId'] ?? 0 ), 'menuOrder'=>(int) ( $item['menuOrder'] ?? $index ),
				'status'=>in_array( $item['status'] ?? '', [ 'publish','pending' ], true ) ? $item['status'] : 'draft',
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
				'squareLogoId' => self::public_brand_image($identity['squareLogoId'] ?? 0), 'roundLogoId' => self::public_brand_image($identity['roundLogoId'] ?? 0),
			],
			'business' => [ 'activities'=>\DSA\Bricks\Design_Context_Binding_Service::activities($raw) ],
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
				'testimonialSources' => Context_Selection_Service::testimonial_sources( (array) ( $content['testimonialSources'] ?? [] ) ),
			],
			'services' => [
				'enabled'=>! empty( $services['enabled'] ),
				'sourcePostType'=>$service_source,
				'useForNavigation'=>! empty( $services['useForNavigation'] ),
				'items'=>$service_items,
			],
			'resources' => [ 'items' => $resource_items ],
			'commerce' => [
				'enabled' => $this->commerce_available() && ! empty( $commerce['enabled'] ), 'expectedProductCount' => min( 1000000, absint( $commerce['expectedProductCount'] ?? 0 ) ),
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
				'shippingLocationMode' => in_array( $commerce['shippingLocationMode'] ?? '', [ '', 'all', 'specific', 'disabled' ], true ) ? ( $commerce['shippingLocationMode'] ?? '' ) : '',
				'shippingCountries' => $country_codes( $commerce['shippingCountries'] ?? [] ),
				'shippingModel' => in_array( $commerce['shippingModel'] ?? '', [ 'free', 'flat', 'calculated', 'pickup', 'mixed', '' ], true ) ? ( $commerce['shippingModel'] ?? '' ) : '',
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

	private static function public_brand_image( $value ): int {
		$id = absint($value);
		return $id && wp_attachment_is_image($id) && Context_Selection_Service::public_media(get_post($id)) ? $id : 0;
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

	/** Bounded native catalog for the client app; exports retain their own limits. */
	public function service_catalog( array $query = [] ): array {
		$stored = (array) get_option( self::OPTION_PROFILE, [] );
		// A service source is always explicit. An unrelated plugin's CPT must never
		// silently replace a client's plan-only inventory.
		$source = sanitize_key( (string) ( $stored['services']['sourcePostType'] ?? '' ) );
		$result = $this->inferred_services( $source, $query );
		$result['enabled'] = $this->services_enabled( $stored );
		$result['useForNavigation'] = ! empty( $stored['services']['useForNavigation'] );
		return $result;
	}

	/** Reuse the existing native writer without saving unrelated context/options. */
	public function save_service_record( string $source, array $item, int $actor_id ): array {
		$result = $this->apply_services_context( [ 'services'=>[ 'enabled'=>true, 'sourcePostType'=>$source, 'items'=>[ $item ] ] ], $actor_id, false );
		$saved = $result['services']['items'][0];
		// The native post remains the record owner. Only this Kiwe-owned context
		// flag changes when a client first saves a connected service.
		if ( ! empty( $saved['recordId'] ) ) {
			$stored = get_option( self::OPTION_PROFILE, [] );
			if ( is_array( $stored ) && $source === sanitize_key( (string) ( $stored['services']['sourcePostType'] ?? '' ) ) && empty( $stored['services']['enabled'] ) ) {
				$stored['services']['enabled'] = true;
				update_option( self::OPTION_PROFILE, $stored, false );
			}
		}
		return $saved;
	}

	private function inferred_services( string $source, array $query = [] ): array {
		$sources = $this->service_post_types();
		if ( ! isset( $sources[ $source ] ) ) return [ 'sourcePostType'=>'', 'useForNavigation'=>false, 'items'=>[] ];
		$taxonomies = $this->service_taxonomies( $source );
		$meta_fields = $this->service_meta_fields( $source );
		$items = [];
		$args = [ 'post_type'=>$source, 'post_status'=>[ 'publish','draft','pending' ], 'posts_per_page'=>100, 'orderby'=>[ 'menu_order'=>'ASC', 'title'=>'ASC', 'ID'=>'ASC' ], 'suppress_filters'=>false ];
		if ( $query ) {
			$status = in_array( $query['status'] ?? '', [ 'publish','draft','pending' ], true ) ? $query['status'] : 'all';
			$sort = in_array( $query['sort'] ?? '', [ 'modified_desc','modified_asc','date_desc','date_asc','title_asc','title_desc' ], true ) ? $query['sort'] : 'modified_desc';
			$args['posts_per_page'] = 21;
			$args['offset'] = ( max( 1, min( 10000, (int) ( $query['page'] ?? 1 ) ) ) - 1 ) * 20;
			$args['s'] = sanitize_text_field( substr( (string) ( $query['search'] ?? '' ), 0, 200 ) );
			$args['post_status'] = 'all' === $status ? [ 'publish','draft','pending' ] : [ $status ];
			$args['orderby'] = str_starts_with( $sort, 'title_' ) ? 'title' : ( str_starts_with( $sort, 'date_' ) ? 'date' : 'modified' );
			$args['order'] = str_ends_with( $sort, '_asc' ) ? 'ASC' : 'DESC';
			if ( ! empty( $query['id'] ) ) { $args['post__in'] = [ absint( $query['id'] ) ]; $args['offset'] = 0; $args['posts_per_page'] = 1; }
		}
		foreach ( empty( $query['disabled'] ) ? get_posts( $args ) : [] as $post ) {
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
				'status'=>in_array( $post->post_status, [ 'publish','pending' ], true ) ? $post->post_status : 'draft', 'categoryPaths'=>sanitize_textarea_field( (string) get_post_meta( $post->ID, self::SERVICE_CATEGORY_PLAN_META, true ) ), 'taxonomyPaths'=>$paths, 'meta'=>$meta,
			];
		}
		return [ 'sourcePostType'=>$source, 'useForNavigation'=>false, 'items'=>$items, 'taxonomies'=>$taxonomies, 'customFields'=>$meta_fields, 'hierarchical'=>$sources[ $source ]['hierarchical'] ];
	}

	private function apply_services_context( array $profile, int $actor_id, bool $refresh = true ): array {
		if ( empty( $profile['services']['enabled'] ) ) return $profile;
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
			$status = 'publish' === ( $item['status'] ?? '' ) && ( $already_published || user_can( $actor_id, $object->cap->publish_posts ) ) ? 'publish' : ( 'pending' === ( $item['status'] ?? '' ) ? 'pending' : 'draft' );
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
		if ( ! $refresh ) return $profile;
		$navigation = ! empty( $profile['services']['useForNavigation'] );
		$failed = array_values( array_filter( $profile['services']['items'], static fn( $item ): bool => empty( $item['recordId'] ) ) );
		$profile['services'] = $this->inferred_services( $source );
		$profile['services']['enabled'] = true;
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
				// A failed path must not replace existing assignments with a subset.
				if ( ! $exists || is_wp_error( $exists ) ) return null;
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
		$members = [];
		$legacy = (array) ( $profile['about']['team']['members'] ?? [] );
		// Only explicitly selected users, using WordPress's query/meta caches.
		foreach ( get_users( [ 'meta_key'=>self::USER_META_TEAM_MEMBER, 'meta_value'=>'1', 'orderby'=>'display_name', 'order'=>'ASC' ] ) as $user ) {
			$user_id = (int) $user->ID;
			if ( ! User_Profile_Service::eligible( $user_id ) ) continue;
			$id = 'user-' . $user_id;
			foreach ( $legacy as $entry ) {
				if ( $user_id === absint( $entry['userId'] ?? 0 ) && ! empty( $entry['id'] ) ) { $id = sanitize_key( (string) $entry['id'] ); break; }
			}
			$members[] = $this->resolve_person( [ 'id'=>$id, 'userId'=>$user_id, 'order'=>(int) get_user_meta( $user_id, self::USER_META_TEAM_ORDER, true ) ] );
		}
		usort( $members, static fn( array $a, array $b ): int => ( $a['order'] <=> $b['order'] ) ?: strcasecmp( $a['name'], $b['name'] ) );
		$owner_enabled = $profile['about']['team']['enabledByOwner'] ?? null;
		$profile['about']['team'] = [ 'enabled'=>null === $owner_enabled ? ! empty( $members ) : true === $owner_enabled, 'members'=>false === $owner_enabled ? [] : $members ];
		if ( null !== $owner_enabled ) $profile['about']['team']['enabledByOwner'] = true === $owner_enabled;
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
		$person = array_replace( $person, User_Profile_Service::public_fields( $user_id ) );
		$person['image'] = $person['imageId'] ? esc_url_raw( (string) wp_get_attachment_url( $person['imageId'] ) ) : esc_url_raw( (string) get_avatar_url( $user_id, [ 'size'=>256 ] ) );
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
