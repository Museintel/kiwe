<?php

namespace DSA\Site_Graph;

use DSA\AI\Site_Graph_Service;
use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Settings;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces one framework-neutral design evidence packet for live tools and
 * file-only browser AIs. It contains public content and builder capabilities,
 * never credentials, visitor state, orders, drafts, filesystem paths or writes.
 */
final class Design_Context_Service {
	private const PUBLIC_MAX_PRODUCTS = 100;
	private const PUBLIC_MAX_MEDIA    = 100;
	private const ADMIN_MAX_PRODUCTS  = 200;
	private const ADMIN_MAX_MEDIA     = 500;
	private const CONTENT_MAX         = 100;
	private const PUBLIC_MAX_CUSTOM_RECORDS = 100;
	private const ADMIN_MAX_CUSTOM_RECORDS  = 200;
	private const MAX_CUSTOM_TYPES          = 40;
	private const MAX_TAXONOMIES            = 60;
	private const MAX_TERMS                 = 300;
	private const MAX_FIELDS_PER_TYPE       = 80;

	public function __construct( private Site_Graph_Service $site_graph, private ?Data_Query_Service $data = null ) {
		$this->data = $this->data ?: new Data_Query_Service();
	}

	public function context( array $args = [], bool $administrator = false ): array {
		$product_limit = $this->bounded( $args['productLimit'] ?? 24, 0, $administrator ? self::ADMIN_MAX_PRODUCTS : self::PUBLIC_MAX_PRODUCTS );
		$media_limit   = $this->bounded( $args['mediaLimit'] ?? 48, 0, $administrator ? self::ADMIN_MAX_MEDIA : self::PUBLIC_MAX_MEDIA );
		$content_limit = $this->bounded( $args['contentLimit'] ?? 12, 0, self::CONTENT_MAX );
		$custom_limit  = $this->bounded( $args['customContentLimit'] ?? 32, 0, $administrator ? self::ADMIN_MAX_CUSTOM_RECORDS : self::PUBLIC_MAX_CUSTOM_RECORDS );
		$term_limit    = $this->bounded( $args['termLimit'] ?? 120, 0, self::MAX_TERMS );
		$media_search  = substr( trim( sanitize_text_field( (string) ( $args['mediaSearch'] ?? $args['search'] ?? '' ) ) ), 0, 200 );
		$resources     = isset( $args['resources'] ) && is_array( $args['resources'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $args['resources'] ) ) ) )
			: [ 'site', 'business', 'menus', 'products', 'commerce', 'media', 'pages', 'posts', 'customcontent', 'customfields', 'taxonomies', 'terms' ];
		$allows        = static fn( string $resource ): bool => in_array( $resource, $resources, true );

		// Design context is public-only even when an administrator downloads it.
		// Administrator mode raises export size budgets; it never unlocks drafts.
		$graph = $this->site_graph->graph( [ 'sampleLimit' => 0, 'publicOnly' => true ] );
		$site  = $allows( 'site' ) ? $this->data->query( [ 'resource' => 'site' ], false ) : [ 'data' => [] ];
		$menus = $allows( 'menus' ) ? $this->data->query( [ 'resource' => 'menus' ], false ) : [ 'data' => [] ];
		$product_args = $this->content_query_args( 'product', [ 'id', 'type', 'slug', 'status', 'title', 'url', 'excerpt', 'featuredImage', 'terms', 'product', 'meta' ], $administrator );
		$page_args    = $this->content_query_args( 'page', [ 'id', 'type', 'slug', 'title', 'url', 'excerpt', 'featuredImage', 'terms', 'meta' ], $administrator );
		$post_args    = $this->content_query_args( 'post', [ 'id', 'type', 'slug', 'title', 'url', 'excerpt', 'date', 'featuredImage', 'terms', 'meta' ], $administrator );
		$products = $allows( 'products' ) && $product_limit ? $this->collect(
			'products',
			$product_limit,
			$product_args,
			$administrator
		) : [];
		$media = $allows( 'media' ) && $media_limit ? $this->collect(
			'media',
			$media_limit,
			[
				'mimeType' => 'image',
				'search'   => $media_search,
			],
			false
		) : [];
		$pages = $allows( 'pages' ) && $content_limit ? $this->collect( 'pages', $content_limit, $page_args, $administrator ) : [];
		$posts = $allows( 'posts' ) && $content_limit ? $this->collect( 'posts', $content_limit, $post_args, $administrator ) : [];
		$product_categories = $allows( 'terms' ) && taxonomy_exists( 'product_cat' ) ? $this->data->query( [ 'resource' => 'terms', 'taxonomy' => 'product_cat', 'limit' => 100 ], false ) : [ 'data' => [] ];
		$product_tags       = $allows( 'terms' ) && taxonomy_exists( 'product_tag' ) ? $this->data->query( [ 'resource' => 'terms', 'taxonomy' => 'product_tag', 'limit' => 100 ], false ) : [ 'data' => [] ];
		$custom_content     = $allows( 'customcontent' ) && $custom_limit ? $this->custom_content_catalog( $custom_limit, $administrator ) : [];
		$taxonomies        = ( $allows( 'taxonomies' ) || $allows( 'terms' ) ) && $term_limit ? $this->taxonomy_catalog( $term_limit, $administrator ) : [];
		$business          = $allows( 'business' ) || $allows( 'site' ) ? $this->business_identity( $administrator ) : [];
		$commerce          = $allows( 'commerce' ) || $allows( 'products' ) ? $this->commerce_context( $graph, $products ) : [];
		$field_registry     = $allows( 'customfields' ) || $allows( 'customcontent' ) ? $this->field_registry( $administrator ) : [];
		$owner_context      = ( new Design_Context_Profile_Service() )->public_context( $administrator );

		$catalog = [
			'site'       => is_array( $site['data'] ?? null ) ? $site['data'] : [],
			'menus'      => is_array( $menus['data'] ?? null ) ? $menus['data'] : [],
			'products'   => $products,
			'media'      => $media,
			'pages'      => $pages,
			'posts'      => $posts,
			'productCategories' => is_array( $product_categories['data'] ?? null ) ? $product_categories['data'] : [],
			'productTags'       => is_array( $product_tags['data'] ?? null ) ? $product_tags['data'] : [],
			'customContent'     => $custom_content,
			'taxonomies'       => $taxonomies,
			'customFields'     => $field_registry,
		];

		return [
			'schema'      => 'kiwe.sitegraph-design-context.v1',
			'generatedAt' => gmdate( 'c' ),
			'authority'   => [
				'source'              => esc_url_raw( home_url( '/' ) ),
				'live'                => true,
				'publicDataOnly'      => true,
				'readOnly'            => true,
				'mayMutateWordPress'  => false,
				'mayPublish'          => false,
				'credentialsIncluded' => false,
				'privateContentIncluded' => false,
				'ordersIncluded'      => false,
				'visitorDataIncluded' => false,
			],
			'siteGraph'   => [
				'site'           => is_array( $graph['site'] ?? null ) ? $graph['site'] : [],
				'wordpress'      => [
					'postTypes'  => $this->without_samples( is_array( $graph['wordpress']['postTypes'] ?? null ) ? $graph['wordpress']['postTypes'] : [] ),
					'taxonomies' => $this->without_samples( is_array( $graph['wordpress']['taxonomies'] ?? null ) ? $graph['wordpress']['taxonomies'] : [] ),
				],
				'woocommerce'    => is_array( $graph['woocommerce'] ?? null ) ? $graph['woocommerce'] : [],
				'customContent'   => is_array( $graph['customContent'] ?? null ) ? $graph['customContent'] : [],
				'bricks'         => is_array( $graph['bricks'] ?? null ) ? $graph['bricks'] : [],
				'kiwe'           => [
					'version'      => sanitize_text_field( (string) ( $graph['kiwe']['version'] ?? '' ) ),
					'modules'      => is_array( $graph['kiwe']['modules'] ?? null ) ? $graph['kiwe']['modules'] : [],
					'bricksBridge' => is_array( $graph['kiwe']['bricksBridge'] ?? null ) ? $graph['kiwe']['bricksBridge'] : [],
				],
				'bindingTargets' => is_array( $graph['bindingTargets'] ?? null ) ? $graph['bindingTargets'] : [],
				'calibration'    => is_array( $graph['calibration'] ?? null ) ? $graph['calibration'] : [],
			],
			'business'    => $business,
			'seamDesignContext' => $owner_context,
			'commerce'    => $commerce,
			'catalog'     => $catalog,
			'catalogHash' => hash( 'sha256', (string) wp_json_encode( $catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'contextHash' => hash( 'sha256', (string) wp_json_encode( [ 'business'=>$business, 'seamDesignContext'=>$owner_context, 'commerce'=>$commerce, 'catalog'=>$catalog ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'counts'      => array_map( 'count', array_filter( $catalog, 'is_array' ) ),
			'query'       => [
				'mediaSearch'  => $media_search,
				'productLimit' => $product_limit,
				'mediaLimit'   => $media_limit,
				'contentLimit' => $content_limit,
				'customContentLimit' => $custom_limit,
				'termLimit'    => $term_limit,
				'resources'    => $resources,
			],
			'rawSourceContract' => [
				'staticMedia' => [ 'preserve public URL', 'add data-kiwe-media-id', 'add stable source selector', 'record attachment ownership in bindings' ],
				'dynamicPreview' => [ 'real records may render in preview', 'production regions remain dynamic', 'add data-kiwe-query-template or data-kiwe-dynamic-field markers', 'emit bricks-bindings/kiwe-bindings.json' ],
				'compiler' => [ 'SEAM Compiler consumes only declared selectors and target-proven tags/query types', 'unmatched or ambiguous targets require human review', 'no Seam Framework requirement' ],
			],
			'usage'       => [
				'command' => '/usesitegraph /for /designcontext',
				'fileOnlyCommand' => '/usesitegraph /for /designcontext /nonai',
				'entityScopes' => [ '/products', '/posts', '/pages', '/media', '/menus', '/customcontent', '/taxonomies', '/business', '/commerce', '/seamdesigncontext' ],
				'fieldScopes'  => [ '/titles', '/images', '/prices', '/links', '/excerpts', '/metadata', '/customfields', '/contact', '/brand', '/audience', '/contentplan', '/bundles', '/discounts', '/bestsellers' ],
				'rule' => 'Use this evidence to improve design choices and binding precision without redesigning an approved artifact or hardcoding production collections.',
			],
		];
	}

	private function content_query_args( string $post_type, array $fields, bool $administrator ): array {
		$contract = $this->custom_field_contract( $post_type, $administrator );
		$value_keys = array_values( array_map( static fn( array $field ): string => (string) $field['key'], array_filter( $contract, static fn( array $field ): bool => ! empty( $field['valueExposed'] ) ) ) );
		$args = [ 'fields' => $fields, 'status' => 'publish', 'publicMeta' => true ];
		if ( $administrator && $value_keys ) {
			$args['metaKeys'] = $value_keys;
		}

		return $args;
	}

	private function field_registry( bool $administrator ): array {
		$out = [];
		$objects = get_post_types( [ 'public' => true ], 'objects' );
		foreach ( array_slice( is_array( $objects ) ? $objects : [], 0, self::MAX_CUSTOM_TYPES, true ) as $name => $object ) {
			if ( ! is_object( $object ) ) {
				continue;
			}
			$fields = $this->custom_field_contract( sanitize_key( (string) $name ), $administrator );
			if ( $fields ) {
				$out[] = [
					'postType' => sanitize_key( (string) $name ),
					'label' => sanitize_text_field( (string) ( $object->label ?? $name ) ),
					'fields' => $fields,
				];
			}
		}

		return $out;
	}

	private function business_identity( bool $administrator ): array {
		$address = [];
		$owner_contact = ( new Design_Context_Profile_Service() )->public_context( false )['contact'] ?? [];
		if ( $administrator && ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) ) {
			$address = [
				'address1' => sanitize_text_field( (string) get_option( 'woocommerce_store_address', '' ) ),
				'address2' => sanitize_text_field( (string) get_option( 'woocommerce_store_address_2', '' ) ),
				'city'     => sanitize_text_field( (string) get_option( 'woocommerce_store_city', '' ) ),
				'postcode' => sanitize_text_field( (string) get_option( 'woocommerce_store_postcode', '' ) ),
				'countryState' => sanitize_text_field( (string) get_option( 'woocommerce_default_country', '' ) ),
			];
		}

		return [
			'name'        => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
			'tagline'     => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
			'homeUrl'     => esc_url_raw( home_url( '/' ) ),
			'logo'        => esc_url_raw( Site_Identity_Service::logo_url() ),
			'logoInverse' => esc_url_raw( Site_Identity_Service::logo_url( 'inverse' ) ),
			'siteIcon'    => esc_url_raw( get_site_icon_url( 512 ) ?: '' ),
			'publicContact' => [
				'phone' => Site_Identity_Service::store_phone(),
				'email' => Site_Identity_Service::store_email(),
				'whatsapp' => sanitize_text_field( (string) ( $owner_contact['whatsapp'] ?? '' ) ),
			],
			'socialProfiles' => array_filter( is_array( $owner_contact['socialLinks'] ?? null ) ? $owner_contact['socialLinks'] : [] ),
			'storeAddress' => $address,
			'dynamicTags' => [
				'logo' => '{kiwe_site_logo}',
				'logoInverse' => '{kiwe_site_logo_inverse}',
				'phone' => '{kiwe_store_phone}',
				'email' => '{kiwe_store_email}',
				'businessDescription' => '{kiwe_business_description}',
				'whatsapp' => '{kiwe_whatsapp}',
				'brandTone' => '{kiwe_brand_tone}',
				'brandColor' => '{kiwe_brand_color}',
				'accentColor' => '{kiwe_accent_color}',
				'heroColor' => '{kiwe_hero_color}',
				'neutralColor' => '{kiwe_neutral_color}',
				'surfaceColor' => '{kiwe_surface_color}',
				'socials' => [
					'facebook' => '{kiwe_facebook_url}', 'instagram' => '{kiwe_instagram_url}', 'x' => '{kiwe_x_url}',
					'youtube' => '{kiwe_youtube_url}', 'pinterest' => '{kiwe_pinterest_url}', 'linkedin' => '{kiwe_linkedin_url}',
				],
				'address' => [ '{kiwe_store_address_1}', '{kiwe_store_address_2}', '{kiwe_store_city}', '{kiwe_store_state}', '{kiwe_store_country}', '{kiwe_store_postcode}' ],
			],
			'privacy' => [
				'contactSource' => 'explicit Kiwe public store identity settings',
				'wordpressAdminEmailExcluded' => true,
				'operationalAddressRequiresAuthenticatedExport' => true,
			],
		];
	}

	private function commerce_context( array $graph, array $products ): array {
		$settings = ( new Settings() )->all();
		$config   = is_array( $settings['commerce'] ?? null ) ? $settings['commerce'] : [];
		$parent_slug = sanitize_title( (string) ( $config['bestseller_parent_slug'] ?? 'bestseller' ) );
		$bestsellers = [];
		if ( taxonomy_exists( 'product_cat' ) ) {
			foreach ( [ $parent_slug => 'all', $parent_slug . '-week' => 'week', $parent_slug . '-month' => 'month', $parent_slug . '-year' => 'year' ] as $slug => $period ) {
				$term = get_term_by( 'slug', $slug, 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$bestsellers[] = [
					'id' => absint( $term->term_id ),
					'name' => sanitize_text_field( (string) $term->name ),
					'slug' => sanitize_title( (string) $term->slug ),
					'period' => $period,
					'count' => max( 0, (int) $term->count ),
					'url' => esc_url_raw( is_wp_error( get_term_link( $term ) ) ? '' : get_term_link( $term ) ),
				];
			}
		}

		$offers = [];
		foreach ( $products as $node ) {
			$merchandising = is_array( $node['product']['kiweMerchandising'] ?? null ) ? $node['product']['kiweMerchandising'] : [];
			if ( ! empty( $merchandising['offerProduct'] ) ) {
				$offers[] = [
					'triggerProduct' => [ 'id' => absint( $node['id'] ?? 0 ), 'title' => sanitize_text_field( (string) ( $node['title'] ?? '' ) ), 'url' => esc_url_raw( (string) ( $node['url'] ?? '' ) ) ],
				] + $merchandising;
			}
		}

		return [
			'active' => ! empty( $graph['woocommerce']['active'] ),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? sanitize_text_field( (string) get_woocommerce_currency() ) : '',
			'currencySymbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? wp_strip_all_tags( (string) get_woocommerce_currency_symbol() ) : '',
			'productTypes' => function_exists( 'wc_get_product_types' ) ? array_map( 'sanitize_text_field', (array) wc_get_product_types() ) : [],
			'pricingAuthority' => 'WooCommerce',
			'marketCoverage' => [
				'selling' => $this->country_scope( 'selling' ),
				'shipping' => $this->country_scope( 'shipping' ),
			],
			'bundles' => [
				'nativeGroupedProductsUseChildren' => true,
				'pluginBundleItemsIncludedWhenPublicApiIsAvailable' => true,
			],
			'kiweOffers' => $offers,
			'bestsellers' => [
				'enabled' => ! empty( $config['bestseller_enabled'] ),
				'configuredParentSlug' => $parent_slug,
				'terms' => $bestsellers,
			],
			'dynamicAuthority' => [
				'price' => 'WooCommerce product dynamic data',
				'cart' => 'WooCommerce or data-dsa-open-module="cart" when Kiwe AppShell is selected',
				'wishlist' => 'data-dsa-save-product',
			],
		];
	}

	private function country_scope( string $type ): array {
		if ( 'shipping' === $type ) {
			$mode = sanitize_key( (string) get_option( 'woocommerce_ship_to_countries', 'ship_to_all' ) );
			$codes = 'specific' === $mode ? (array) get_option( 'woocommerce_specific_ship_to_countries', [] ) : [];
		} else {
			$mode = sanitize_key( (string) get_option( 'woocommerce_allowed_countries', 'all' ) );
			$codes = 'specific' === $mode
				? (array) get_option( 'woocommerce_specific_allowed_countries', [] )
				: ( 'all_except' === $mode ? (array) get_option( 'woocommerce_all_except_countries', [] ) : [] );
		}
		$countries = function_exists( 'WC' ) && WC() && WC()->countries ? (array) WC()->countries->get_countries() : [];
		$locations = [];
		foreach ( array_slice( array_values( array_unique( array_map( 'sanitize_text_field', $codes ) ) ), 0, 250 ) as $code ) {
			$locations[] = [ 'code' => $code, 'name' => sanitize_text_field( (string) ( $countries[ $code ] ?? $code ) ) ];
		}

		return [
			'mode' => $mode,
			'locations' => $locations,
			'dynamicTag' => 'shipping' === $type ? '{kiwe_shipping_locations}' : '{kiwe_selling_locations}',
		];
	}

	private function custom_content_catalog( int $total_limit, bool $administrator ): array {
		$out = [];
		$remaining = $total_limit;
		$objects = get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' );
		foreach ( array_slice( is_array( $objects ) ? $objects : [], 0, self::MAX_CUSTOM_TYPES, true ) as $name => $object ) {
			$post_type = sanitize_key( (string) $name );
			if ( '' === $post_type || 'product' === $post_type || $remaining <= 0 || ! is_object( $object ) ) {
				continue;
			}
			$fields = $this->custom_field_contract( $post_type, $administrator );
			$value_keys = array_values( array_map( static fn( array $field ): string => (string) $field['key'], array_filter( $fields, static fn( array $field ): bool => ! empty( $field['valueExposed'] ) ) ) );
			$take = min( 12, $remaining );
			$args = [
				'fields' => [ 'id', 'type', 'slug', 'title', 'url', 'excerpt', 'date', 'featuredImage', 'terms', 'meta' ],
				'status' => 'publish',
				'publicMeta' => true,
			];
			if ( $administrator && $value_keys ) {
				$args['metaKeys'] = $value_keys;
			}
			$records = $this->collect( $post_type, $take, $args, $administrator );
			$remaining -= count( $records );
			$out[] = [
				'name'       => $post_type,
				'label'      => sanitize_text_field( (string) ( $object->label ?? $post_type ) ),
				'hasArchive' => ! empty( $object->has_archive ),
				'archiveUrl' => ! empty( $object->has_archive ) ? esc_url_raw( (string) get_post_type_archive_link( $post_type ) ) : '',
				'restBase'   => sanitize_key( (string) ( $object->rest_base ?? $post_type ) ),
				'taxonomies' => array_values( array_map( 'sanitize_key', get_object_taxonomies( $post_type ) ) ),
				'customFields' => $fields,
				'records'    => $records,
			];
		}

		return $out;
	}

	private function custom_field_contract( string $post_type, bool $administrator ): array {
		$fields = [];
		$registered = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $post_type ) : [];
		foreach ( is_array( $registered ) ? $registered : [] as $key => $schema ) {
			$this->add_field_contract( $fields, (string) $key, is_array( $schema ) ? $schema : [], true, $administrator );
		}
		$ids = get_posts( [ 'post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => 8, 'fields' => 'ids', 'no_found_rows' => true, 'update_post_meta_cache' => true, 'update_post_term_cache' => false ] );
		foreach ( is_array( $ids ) ? $ids : [] as $id ) {
			foreach ( array_keys( (array) get_post_meta( absint( $id ) ) ) as $key ) {
				$this->add_field_contract( $fields, (string) $key, [], false, $administrator );
			}
		}

		return array_slice( array_values( $fields ), 0, self::MAX_FIELDS_PER_TYPE );
	}

	private function add_field_contract( array &$fields, string $key, array $schema, bool $registered, bool $administrator ): void {
		if ( '' === $key || $this->is_secretish_key( $key ) || str_starts_with( $key, '_bricks' ) || in_array( $key, [ '_edit_lock', '_edit_last' ], true ) ) {
			return;
		}
		$protected = str_starts_with( $key, '_' );
		$show_in_rest = ! empty( $schema['show_in_rest'] );
		$value_exposed = ( $show_in_rest && ! $protected ) || ( $administrator && ! $protected );
		$fields[ $key ] = [
			'key' => sanitize_text_field( $key ),
			'type' => sanitize_key( (string) ( $schema['type'] ?? 'observed' ) ),
			'single' => ! empty( $schema['single'] ),
			'registered' => $registered,
			'showInRest' => $show_in_rest,
			'protected' => $protected,
			'valueExposed' => $value_exposed,
		];
	}

	private function taxonomy_catalog( int $total_limit, bool $administrator ): array {
		$out = [];
		$remaining = $total_limit;
		$objects = get_taxonomies( [ 'public' => true ], 'objects' );
		foreach ( array_slice( is_array( $objects ) ? $objects : [], 0, self::MAX_TAXONOMIES, true ) as $name => $object ) {
			if ( $remaining <= 0 || ! is_object( $object ) ) {
				break;
			}
			$taxonomy = sanitize_key( (string) $name );
			$take = min( 30, $remaining );
			$result = $this->data->query( [ 'resource' => 'terms', 'taxonomy' => $taxonomy, 'limit' => $take ], false );
			$terms = is_array( $result['data'] ?? null ) ? $result['data'] : [];
			foreach ( $terms as &$term ) {
				if ( is_array( $term ) ) {
					$term['meta'] = $this->term_meta( absint( $term['id'] ?? 0 ), $taxonomy, $administrator );
					$thumbnail_id = absint( $term['meta']['thumbnail_id'] ?? 0 );
					if ( $thumbnail_id ) {
						$term['thumbnail'] = [ 'id' => $thumbnail_id, 'url' => esc_url_raw( (string) wp_get_attachment_url( $thumbnail_id ) ) ];
					}
				}
			}
			unset( $term );
			$remaining -= count( $terms );
			$out[] = [
				'name' => $taxonomy,
				'label' => sanitize_text_field( (string) ( $object->label ?? $taxonomy ) ),
				'builtin' => ! empty( $object->_builtin ),
				'hierarchical' => ! empty( $object->hierarchical ),
				'objectTypes' => array_values( array_map( 'sanitize_key', (array) ( $object->object_type ?? [] ) ) ),
				'restBase' => sanitize_key( (string) ( $object->rest_base ?? $taxonomy ) ),
				'terms' => $terms,
			];
		}

		return $out;
	}

	private function term_meta( int $term_id, string $taxonomy, bool $administrator ): array {
		if ( ! $term_id ) {
			return [];
		}
		$registered = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'term', $taxonomy ) : [];
		$allowed = [ 'thumbnail_id' ];
		foreach ( is_array( $registered ) ? $registered : [] as $key => $schema ) {
			if ( ! empty( $schema['show_in_rest'] ) && ! str_starts_with( (string) $key, '_' ) && ! $this->is_secretish_key( (string) $key ) ) {
				$allowed[] = (string) $key;
			}
		}
		if ( $administrator ) {
			foreach ( array_keys( (array) get_term_meta( $term_id ) ) as $key ) {
				if ( ! str_starts_with( (string) $key, '_' ) && ! $this->is_secretish_key( (string) $key ) ) {
					$allowed[] = (string) $key;
				}
			}
		}
		$out = [];
		foreach ( array_slice( array_values( array_unique( $allowed ) ), 0, 30 ) as $key ) {
			$value = get_term_meta( $term_id, $key, true );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$out[ sanitize_text_field( $key ) ] = is_string( $value ) ? substr( wp_strip_all_tags( $value ), 0, 1000 ) : $value;
			}
		}

		return $out;
	}

	private function is_secretish_key( string $key ): bool {
		return '' === $key || (bool) preg_match( '/password|secret|token|nonce|session|cookie|license|consumer|private|payment|stripe|paypal|credential|authorization|api[_-]?key|webhook/i', $key );
	}

	private function collect( string $resource, int $limit, array $args, bool $private ): array {
		$out       = [];
		$page      = 1;
		$remaining = $limit;
		$page_size = $private ? 200 : 100;
		while ( $remaining > 0 ) {
			$take = min( $page_size, $remaining );
			$result = $this->data->query( [ 'resource' => $resource, 'limit' => $take, 'page' => $page ] + $args, $private );
			$nodes  = is_array( $result['data'] ?? null ) ? $result['data'] : [];
			foreach ( $nodes as $node ) {
				if ( is_array( $node ) ) {
					$out[] = $node;
				}
			}
			$remaining -= count( $nodes );
			$total_pages = absint( $result['pageInfo']['totalPages'] ?? 1 );
			if ( [] === $nodes || $page >= $total_pages || count( $nodes ) < $take ) {
				break;
			}
			++$page;
		}

		return array_slice( $out, 0, $limit );
	}

	private function without_samples( array $records ): array {
		return array_map(
			static function ( $record ) {
				if ( is_array( $record ) ) {
					unset( $record['samples'] );
				}
				return $record;
			},
			$records
		);
	}

	private function bounded( $value, int $minimum, int $maximum ): int {
		return max( $minimum, min( $maximum, absint( $value ) ) );
	}
}
