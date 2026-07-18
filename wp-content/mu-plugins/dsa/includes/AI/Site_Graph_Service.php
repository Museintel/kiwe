<?php

namespace DSA\AI;

use DSA\Design\Seam_Token_Service;
use DSA\Modules\Module_Registry;
use DSA\Settings;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Graph_Service {
	private const DEFAULT_SAMPLE_LIMIT = 8;
	private const MAX_SAMPLE_LIMIT = 24;

	public function __construct( private Settings $settings, private Module_Registry $modules ) {}

	public function graph( array $args = [] ): array {
		$sample_limit = isset( $args['sampleLimit'] ) ? absint( $args['sampleLimit'] ) : self::DEFAULT_SAMPLE_LIMIT;
		$sample_limit = max( 0, min( self::MAX_SAMPLE_LIMIT, $sample_limit ) );

		$settings = $this->settings->all();
		$dock     = isset( $settings['dock'] ) && is_array( $settings['dock'] ) ? $settings['dock'] : [];
		$tokens   = Seam_Token_Service::universal_tokens();

		return [
			'schema'        => 'kiwe.site-graph.v1',
			'generatedAt'   => gmdate( 'c' ),
			'site'          => $this->site_summary(),
			'wordpress'     => $this->wordpress_summary( $sample_limit ),
			'woocommerce'   => $this->woocommerce_summary( $sample_limit ),
			'bricks'        => $this->bricks_summary(),
			'customContent' => $this->custom_content_summary( $sample_limit ),
			'kiwe'          => [
				'version'       => defined( 'DSA_VERSION' ) ? DSA_VERSION : '',
				'modules'       => $this->modules->manifest_contract( $dock ),
				'dock'          => $this->safe_setting_fragment( $dock, [ 'presentation', 'layout', 'focus_module', 'shape', 'orientation', 'items' ] ),
				'search'        => $this->safe_setting_fragment( isset( $settings['search'] ) && is_array( $settings['search'] ) ? $settings['search'] : [], [ 'context_aware', 'alphabet_enabled', 'product_add_enabled', 'bricks_bridge_enabled', 'families', 'custom_taxonomies' ] ),
				'bricksBridge'  => $this->safe_setting_fragment( isset( $settings['bricks'] ) && is_array( $settings['bricks'] ) ? $settings['bricks'] : [], [ 'dynamic_tags_enabled', 'dsa_icon_launcher_enabled', 'linked_products_controls_enabled', 'prefer_bricks_native_cart', 'quantity_stepper_enabled', 'stock_badge_enabled', 'verified_version' ] ),
				'tokenSummary'  => [
					'source'        => 'kiwe.universal',
					'count'         => count( $tokens ),
					'counts'        => Seam_Token_Service::counts( $tokens ),
					'bricksAdditive' => true,
				],
			],
			'bindingTargets' => $this->binding_targets(),
			'guardrails'     => [
				'readOnly'             => true,
				'noSecrets'            => true,
				'noVisitorState'       => true,
				'noMutationAuthority'  => true,
				'bricksOwnsPageData'   => true,
				'kiweOwnsAppShell'     => true,
				'woocommerceOwnsMoney' => true,
			],
		];
	}

	public function summary(): array {
		return [
			'id'          => 'site_graph',
			'label'       => __( 'Kiwe Site Graph', 'dsa' ),
			'available'   => true,
			'status'      => 'readonly',
			'description' => __( 'Admin-only context graph for AI design, Bricks binding, WordPress content inventory, WooCommerce terms, and Kiwe AppShell capabilities.', 'dsa' ),
			'schema'      => 'kiwe.site-graph.v1',
		];
	}

	private function site_summary(): array {
		return [
			'name'          => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
			'description'   => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
			'homeUrl'       => esc_url_raw( home_url( '/' ) ),
			'language'      => sanitize_text_field( (string) get_bloginfo( 'language' ) ),
			'timezone'      => sanitize_text_field( (string) wp_timezone_string() ),
			'wpVersion'     => sanitize_text_field( (string) get_bloginfo( 'version' ) ),
			'siteIcon'      => esc_url_raw( get_site_icon_url( 192 ) ?: '' ),
			'logo'          => esc_url_raw( Site_Identity_Service::logo_url() ),
			'logoInverse'   => esc_url_raw( Site_Identity_Service::logo_url( 'inverse' ) ),
			'permalinkMode' => get_option( 'permalink_structure' ) ? 'pretty' : 'plain',
			'multisite'     => is_multisite(),
		];
	}

	private function wordpress_summary( int $sample_limit ): array {
		$post_types = [];
		$objects    = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( is_array( $objects ) ? $objects : [] as $name => $object ) {
			if ( ! is_object( $object ) ) {
				continue;
			}

			$post_types[] = [
				'name'       => sanitize_key( (string) $name ),
				'label'      => sanitize_text_field( (string) ( $object->label ?? $name ) ),
				'restBase'   => sanitize_key( (string) ( $object->rest_base ?? $name ) ),
				'hasArchive' => ! empty( $object->has_archive ),
				'counts'     => $this->post_counts( (string) $name ),
				'samples'    => $sample_limit ? $this->post_samples( (string) $name, $sample_limit ) : [],
			];
		}

		return [
			'postTypes'  => $post_types,
			'taxonomies' => $this->taxonomy_summary( $sample_limit ),
			'pages'      => $sample_limit ? $this->post_samples( 'page', $sample_limit ) : [],
			'menus'      => $this->menus_summary(),
		];
	}

	private function woocommerce_summary( int $sample_limit ): array {
		$active = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
		$pages  = [];

		foreach (
			[
				'shop'     => 'woocommerce_shop_page_id',
				'cart'     => 'woocommerce_cart_page_id',
				'checkout' => 'woocommerce_checkout_page_id',
				'account'  => 'woocommerce_myaccount_page_id',
			] as $key => $option
		) {
			$page_id       = absint( get_option( $option, 0 ) );
			$pages[ $key ] = [
				'id'    => $page_id,
				'title' => $page_id ? sanitize_text_field( get_the_title( $page_id ) ) : '',
				'url'   => $page_id ? esc_url_raw( get_permalink( $page_id ) ) : '',
			];
		}

		return [
			'active'            => $active,
			'pages'             => $pages,
			'productCounts'     => $this->post_counts( 'product' ),
			'productCategories' => taxonomy_exists( 'product_cat' ) && $sample_limit ? $this->terms_for_taxonomy( 'product_cat', $sample_limit * 2 ) : [],
			'productTags'       => taxonomy_exists( 'product_tag' ) && $sample_limit ? $this->terms_for_taxonomy( 'product_tag', $sample_limit ) : [],
			'store'             => [
				'address1'          => sanitize_text_field( (string) get_option( 'woocommerce_store_address', '' ) ),
				'address2'          => sanitize_text_field( (string) get_option( 'woocommerce_store_address_2', '' ) ),
				'city'              => sanitize_text_field( (string) get_option( 'woocommerce_store_city', '' ) ),
				'postcode'          => sanitize_text_field( (string) get_option( 'woocommerce_store_postcode', '' ) ),
				'defaultCountryRaw' => sanitize_text_field( (string) get_option( 'woocommerce_default_country', '' ) ),
			],
		];
	}

	private function bricks_summary(): array {
		$active        = defined( 'BRICKS_VERSION' ) || class_exists( '\Bricks\Helpers' ) || class_exists( '\Bricks\Setup' );
		$bricks_config = $this->settings->get( 'bricks', [] );
		$bricks_config = is_array( $bricks_config ) ? $bricks_config : [];
		$conversion    = ( new Bricks_Html_Css_Converter_Service() )->available();

		return [
			'active'          => $active,
			'version'         => defined( 'BRICKS_VERSION' ) ? sanitize_text_field( (string) BRICKS_VERSION ) : '',
			'kiweVerifiedFor' => sanitize_text_field( (string) ( $bricks_config['verified_version'] ?? '' ) ),
			'abilities'       => [
				'wpAbilitiesApiPresent' => function_exists( 'wp_register_ability' ),
				'bricksAbilityManager'  => class_exists( '\Bricks\Abilities\Manager' ),
				'mcpLikelyAvailable'    => class_exists( '\Bricks\Abilities\Manager' ) && function_exists( 'wp_register_ability' ),
			],
			'queryLoopTypes'  => $this->bricks_query_loop_types(),
			'dynamicTags'     => $this->bricks_dynamic_tags(),
			'kiweDynamicTags' => $this->kiwe_dynamic_tags(),
			'conversion'      => [
				'htmlCssToBricksAvailable' => ! empty( $conversion['native'] ) || ! empty( $conversion['fallback'] ),
				'bricksNativeAvailable'    => ! empty( $conversion['native'] ),
				'kiweFallbackAvailable'     => ! empty( $conversion['fallback'] ),
				'preferredConverter'        => (string) ( $conversion['preferred'] ?? 'kiwe-fallback' ),
				'preferredWorkflow'         => 'Use Kiwe staging operation bricks.page.from-html or bricks.template.from-html; Kiwe uses Bricks native conversion when present and otherwise preserves Seam HTML/CSS through its fallback converter.',
			],
		];
	}

	private function binding_targets(): array {
		return [
			'bricksDynamicData' => [
				'authority' => 'bricks',
				'useFor'    => [ 'post title', 'post URL', 'featured image', 'excerpt', 'date', 'author', 'terms', 'Woo product fields', 'Kiwe dynamic tags' ],
				'rule'      => 'Use site graph tags or Bricks list-dynamic-data-tags output; do not guess unavailable tags.',
			],
			'bricksQueryLoops'  => [
				'authority' => 'bricks',
				'useFor'    => [ 'post rails', 'product rails', 'category/term rails', 'bestsellers', 'archives', 'filtered listings' ],
				'rule'      => 'Use real taxonomy term IDs from the site graph. Bricks taxonomy filters use taxonomy::term_id values.',
			],
			'kiweAppShell'      => [
				'authority' => 'kiwe',
				'useFor'    => [ 'dock launchers', 'Search screen', 'Menu context', 'Saved', 'Cart', 'Checkout', 'Profile', 'AI', 'notifications', 'Links' ],
				'rule'      => 'Page/header controls should use canonical Kiwe launchers such as data-dsa-open-module. Do not recreate AppShell runtime.',
			],
			'seamContext'       => [
				'authority' => 'kiwe-seam',
				'useFor'    => [ 'semantic sections', 'rails', 'cards', 'article/story/content meaning', 'menu context candidates' ],
				'rule'      => 'Seam attributes describe meaning without starter visuals. Site CSS and Bricks styles own appearance.',
			],
		];
	}

	private function post_counts( string $post_type ): array {
		if ( ! post_type_exists( $post_type ) ) {
			return [];
		}

		$counts = wp_count_posts( $post_type );
		$out    = [];

		foreach ( get_object_vars( $counts ) as $status => $count ) {
			$out[ sanitize_key( (string) $status ) ] = max( 0, (int) $count );
		}

		return $out;
	}

	private function post_samples( string $post_type, int $limit ): array {
		if ( ! post_type_exists( $post_type ) ) {
			return [];
		}

		$ids = get_posts(
			[
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, min( self::MAX_SAMPLE_LIMIT, $limit ) ),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$samples = [];

		foreach ( is_array( $ids ) ? $ids : [] as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}

			$samples[] = [
				'id'    => absint( $id ),
				'title' => sanitize_text_field( get_the_title( $id ) ),
				'slug'  => sanitize_title( $post->post_name ),
				'url'   => esc_url_raw( get_permalink( $id ) ),
			];
		}

		return $samples;
	}

	private function taxonomy_summary( int $sample_limit ): array {
		$taxonomies = [];
		$objects    = get_taxonomies( [ 'public' => true ], 'objects' );

		foreach ( is_array( $objects ) ? $objects : [] as $name => $object ) {
			if ( ! is_object( $object ) ) {
				continue;
			}

			$taxonomies[] = [
				'name'       => sanitize_key( (string) $name ),
				'label'      => sanitize_text_field( (string) ( $object->label ?? $name ) ),
				'objectType' => array_values( array_map( 'sanitize_key', (array) ( $object->object_type ?? [] ) ) ),
				'terms'      => $sample_limit ? $this->terms_for_taxonomy( (string) $name, $sample_limit ) : [],
			];
		}

		return $taxonomies;
	}

	private function custom_content_summary( int $sample_limit ): array {
		return [
			'postTypes'    => $this->custom_post_types( $sample_limit ),
			'taxonomies'   => $this->custom_taxonomies( $sample_limit ),
			'customFields' => $this->custom_field_summary( $sample_limit ),
			'guardrails'   => [
				'valuesRedacted'      => true,
				'secretKeysExcluded'  => true,
				'bricksMetaSeparated' => true,
				'useFor'              => 'AI dynamic binding and Bricks query-loop planning without exposing private field values.',
			],
		];
	}

	private function custom_post_types( int $sample_limit ): array {
		$out     = [];
		$objects = get_post_types( [ '_builtin' => false ], 'objects' );
		foreach ( is_array( $objects ) ? $objects : [] as $name => $object ) {
			if ( ! is_object( $object ) ) {
				continue;
			}
			$out[] = [
				'name'       => sanitize_key( (string) $name ),
				'label'      => sanitize_text_field( (string) ( $object->label ?? $name ) ),
				'public'     => ! empty( $object->public ),
				'showUi'     => ! empty( $object->show_ui ),
				'showInRest' => ! empty( $object->show_in_rest ),
				'restBase'   => sanitize_key( (string) ( $object->rest_base ?? $name ) ),
				'taxonomies' => array_values( array_map( 'sanitize_key', get_object_taxonomies( (string) $name ) ) ),
				'counts'     => $this->post_counts( (string) $name ),
				'samples'    => $sample_limit ? $this->post_samples( (string) $name, min( $sample_limit, 8 ) ) : [],
			];
		}

		return $out;
	}

	private function custom_taxonomies( int $sample_limit ): array {
		$out     = [];
		$objects = get_taxonomies( [ '_builtin' => false ], 'objects' );
		foreach ( is_array( $objects ) ? $objects : [] as $name => $object ) {
			if ( ! is_object( $object ) ) {
				continue;
			}
			$out[] = [
				'name'       => sanitize_key( (string) $name ),
				'label'      => sanitize_text_field( (string) ( $object->label ?? $name ) ),
				'public'     => ! empty( $object->public ),
				'showUi'     => ! empty( $object->show_ui ),
				'showInRest' => ! empty( $object->show_in_rest ),
				'objectType' => array_values( array_map( 'sanitize_key', (array) ( $object->object_type ?? [] ) ) ),
				'terms'      => $sample_limit ? $this->terms_for_taxonomy( (string) $name, min( $sample_limit, 16 ) ) : [],
			];
		}

		return $out;
	}

	private function custom_field_summary( int $sample_limit ): array {
		$post_types = get_post_types( [], 'names' );
		$registered = [];
		foreach ( is_array( $post_types ) ? $post_types : [] as $post_type ) {
			$keys = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', (string) $post_type ) : [];
			foreach ( is_array( $keys ) ? $keys : [] as $key => $schema ) {
				if ( $this->is_secretish_key( (string) $key ) ) {
					continue;
				}
				$registered[] = [
					'postType'   => sanitize_key( (string) $post_type ),
					'key'        => sanitize_text_field( (string) $key ),
					'type'       => sanitize_key( (string) ( $schema['type'] ?? '' ) ),
					'single'     => ! empty( $schema['single'] ),
					'showInRest' => ! empty( $schema['show_in_rest'] ),
					'protected'  => str_starts_with( (string) $key, '_' ),
				];
			}
		}

		return [
			'registered' => array_slice( $registered, 0, 160 ),
			'observed'   => $this->observed_custom_fields( $sample_limit ),
		];
	}

	private function observed_custom_fields( int $sample_limit ): array {
		$out = [];
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		foreach ( is_array( $post_types ) ? $post_types : [] as $post_type ) {
			$ids = get_posts(
				[
					'post_type'              => (string) $post_type,
					'post_status'            => [ 'publish', 'draft', 'private' ],
					'posts_per_page'         => max( 1, min( 8, $sample_limit ) ),
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				]
			);
			foreach ( is_array( $ids ) ? $ids : [] as $id ) {
				$meta = get_post_meta( absint( $id ) );
				foreach ( is_array( $meta ) ? $meta : [] as $key => $values ) {
					$key = (string) $key;
					if ( $this->is_secretish_key( $key ) ) {
						continue;
					}
					$bucket = sanitize_key( (string) $post_type ) . '|' . $key;
					if ( ! isset( $out[ $bucket ] ) ) {
						$out[ $bucket ] = [
							'postType'   => sanitize_key( (string) $post_type ),
							'key'        => sanitize_text_field( $key ),
							'protected'  => str_starts_with( $key, '_' ),
							'bricksMeta' => str_starts_with( $key, '_bricks' ),
							'occurrences' => 0,
							'valueTypes' => [],
						];
					}
					$out[ $bucket ]['occurrences']++;
					foreach ( is_array( $values ) ? $values : [] as $value ) {
						$type = $this->meta_value_type( $value );
						$out[ $bucket ]['valueTypes'][ $type ] = true;
					}
				}
			}
		}

		$out = array_values(
			array_map(
				static function ( array $field ): array {
					$field['valueTypes'] = array_keys( $field['valueTypes'] );
					return $field;
				},
				$out
			)
		);
		usort( $out, static fn( array $a, array $b ): int => ( $b['occurrences'] ?? 0 ) <=> ( $a['occurrences'] ?? 0 ) );

		return array_slice( $out, 0, 220 );
	}

	private function meta_value_type( mixed $value ): string {
		if ( is_serialized( $value ) ) {
			return 'serialized';
		}
		$decoded = is_string( $value ) ? json_decode( $value, true ) : null;
		if ( is_array( $decoded ) ) {
			return 'json';
		}
		if ( is_numeric( $value ) ) {
			return 'number';
		}
		if ( is_string( $value ) && preg_match( '/^https?:\\/\\//i', $value ) ) {
			return 'url';
		}

		return is_scalar( $value ) ? 'scalar' : gettype( $value );
	}

	private function is_secretish_key( string $key ): bool {
		if ( '' === $key ) {
			return true;
		}
		if ( preg_match( '/password|secret|token|nonce|session|cookie|license|consumer|private|payment|stripe|paypal|key/i', $key ) ) {
			return true;
		}

		return false;
	}

	private function terms_for_taxonomy( string $taxonomy, int $limit ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => max( 1, min( self::MAX_SAMPLE_LIMIT * 2, $limit ) ),
				'orderby'    => 'count',
				'order'      => 'DESC',
			]
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		return array_values(
			array_map(
				static function ( $term ): array {
					return [
						'id'    => absint( $term->term_id ?? 0 ),
						'name'  => sanitize_text_field( (string) ( $term->name ?? '' ) ),
						'slug'  => sanitize_title( (string) ( $term->slug ?? '' ) ),
						'count' => max( 0, (int) ( $term->count ?? 0 ) ),
					];
				},
				$terms
			)
		);
	}

	private function menus_summary(): array {
		$locations = get_nav_menu_locations();
		$menus     = [];

		foreach ( wp_get_nav_menus() as $menu ) {
			$term_id = absint( $menu->term_id ?? 0 );
			if ( ! $term_id ) {
				continue;
			}

			$menus[] = [
				'id'        => $term_id,
				'name'      => sanitize_text_field( (string) ( $menu->name ?? '' ) ),
				'slug'      => sanitize_title( (string) ( $menu->slug ?? '' ) ),
				'locations' => array_values(
					array_keys(
						array_filter(
							is_array( $locations ) ? $locations : [],
							static fn( $value ): bool => absint( $value ) === $term_id
						)
					)
				),
			];
		}

		return $menus;
	}

	private function bricks_query_loop_types(): array {
		if ( ! class_exists( '\Bricks\Setup' ) || ! method_exists( '\Bricks\Setup', 'get_control_options' ) ) {
			return [];
		}

		try {
			$options = \Bricks\Setup::get_control_options();
		} catch ( \Throwable $error ) {
			return [
				[
					'error' => 'unavailable',
					'message' => sanitize_text_field( $error->getMessage() ),
				],
			];
		}

		$query_types = isset( $options['queryTypes'] ) && is_array( $options['queryTypes'] ) ? $options['queryTypes'] : [];
		$out         = [];

		foreach ( $query_types as $key => $value ) {
			$out[] = [
				'objectType' => sanitize_key( is_string( $key ) ? $key : ( is_string( $value ) ? $value : (string) ( $value['objectType'] ?? '' ) ) ),
				'label'      => sanitize_text_field( is_string( $value ) ? $value : (string) ( $value['label'] ?? $value['name'] ?? $key ) ),
			];
		}

		return array_values(
			array_filter(
				$out,
				static fn( array $item ): bool => '' !== $item['objectType']
			)
		);
	}

	private function bricks_dynamic_tags(): array {
		if ( ! class_exists( '\Bricks\Integrations\Dynamic_Data\Providers' ) || ! method_exists( '\Bricks\Integrations\Dynamic_Data\Providers', 'get_dynamic_tags_list' ) ) {
			return [];
		}

		try {
			$tags = \Bricks\Integrations\Dynamic_Data\Providers::get_dynamic_tags_list();
		} catch ( \Throwable $error ) {
			return [
				[
					'error' => 'unavailable',
					'message' => sanitize_text_field( $error->getMessage() ),
				],
			];
		}

		$out = [];
		foreach ( is_array( $tags ) ? $tags : [] as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $tag['name'] ?? $tag['tag'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			$out[] = [
				'name'  => $name,
				'label' => sanitize_text_field( (string) ( $tag['label'] ?? $name ) ),
				'group' => sanitize_text_field( (string) ( $tag['group'] ?? '' ) ),
			];

			if ( count( $out ) >= 160 ) {
				break;
			}
		}

		return $out;
	}

	private function kiwe_dynamic_tags(): array {
		return [
			'{kiwe_site_logo}',
			'{kiwe_site_logo_inverse}',
			'{kiwe_store_address_1}',
			'{kiwe_store_address_2}',
			'{kiwe_store_city}',
			'{kiwe_store_country}',
			'{kiwe_store_state}',
			'{kiwe_store_postcode}',
			'{kiwe_store_phone}',
			'{kiwe_store_email}',
			'{kiwe_selling_locations}',
			'{kiwe_shipping_locations}',
			'{woo_product_weight}',
		];
	}

	private function safe_setting_fragment( array $settings, array $allow ): array {
		$out = [];

		foreach ( $allow as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$out[ $key ] = $settings[ $key ];
			}
		}

		return $out;
	}
}
