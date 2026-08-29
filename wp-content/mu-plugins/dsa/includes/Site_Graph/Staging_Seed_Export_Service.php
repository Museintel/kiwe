<?php

namespace DSA\Site_Graph;

use DSA\Commerce\Product_Context_Service;
use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only, public-business-data export contract for staging seeding.
 *
 * This service never exports users, customers, orders, credentials, payment
 * data, sessions, message history, logs, webhooks, provider configuration or
 * downloadable-file URLs. Import/mutation belongs to a separate target-side
 * adapter and is never performed by SiteGraph.
 */
final class Staging_Seed_Export_Service {
	public const SCHEMA = 'kiwe.sitegraph-staging-seed.v1';
	private const MAX_PAGE_SIZE = 100;
	private ?array $transferable_media_ids = null;

	public function __construct( private ?Data_Query_Service $data_query = null ) {
		$this->data_query = $data_query ?: new Data_Query_Service();
	}

	public function manifest(): array {
		$post_types = $this->public_content_types();
		$taxonomies = $this->public_taxonomies();
		$resources  = [
			'site'          => [ 'count' => 1, 'paged' => false ],
			'designContext' => [ 'count' => 1, 'paged' => false ],
			'menus'         => [ 'count' => count( wp_get_nav_menus() ), 'paged' => false ],
			'content'       => [ 'count' => $this->post_count( $post_types ), 'paged' => true, 'postTypes' => $post_types ],
			'products'      => [ 'count' => post_type_exists( 'product' ) ? $this->post_count( [ 'product' ] ) : 0, 'paged' => true ],
			'terms'         => [ 'count' => $this->term_count( $taxonomies ), 'paged' => true, 'taxonomies' => $taxonomies ],
			'media'         => [ 'count' => $this->media_count(), 'paged' => true ],
		];

		$revision_material = [
			'origin'       => $this->origin(),
			'resources'    => $resources,
			'lastModified' => $this->latest_public_modified(),
			'designHash'   => hash( 'sha256', $this->json( ( new Design_Context_Profile_Service() )->public_context( true ) ) ),
			'kiweVersion'  => defined( 'DSA_VERSION' ) ? DSA_VERSION : '',
		];

		return [
			'schema'       => self::SCHEMA,
			'generatedAt'  => gmdate( 'c' ),
			'packageId'    => substr( hash( 'sha256', $this->json( $revision_material ) ), 0, 32 ),
			'revisionHash' => hash( 'sha256', $this->json( $revision_material ) ),
			'source'       => [
				'origin'     => $this->origin(),
				'originHash' => hash( 'sha256', $this->origin() ),
				'siteName'   => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'wordpress'  => (string) get_bloginfo( 'version' ),
				'woocommerce'=> defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'kiwe'       => defined( 'DSA_VERSION' ) ? DSA_VERSION : '',
			],
			'resources'    => $resources,
			'endpoint'     => [
				'resource' => rest_url( 'dsa/v1/site-graph/staging-seed/resource' ),
				'pageSize' => self::MAX_PAGE_SIZE,
			],
			'preservationBoundary' => [
				'publishedBusinessContentOnly' => true,
				'usersExcluded'                 => true,
				'customersExcluded'             => true,
				'ordersExcluded'                => true,
				'credentialsExcluded'           => true,
				'paymentDataExcluded'           => true,
				'sessionsExcluded'              => true,
				'messageHistoryExcluded'         => true,
				'webhooksExcluded'               => true,
				'downloadFilesExcluded'          => true,
				'filesystemPathsExcluded'        => true,
			],
			'excludedResources' => [
				'users', 'customers', 'orders', 'refunds', 'payment_tokens', 'sessions', 'carts',
				'wishlists', 'credentials', 'api_keys', 'smtp', 'phonekey', 'webhooks', 'logs',
				'analytics', 'action_scheduler', 'caches', 'licenses', 'download_file_urls',
			],
			'authority' => [
				'readOnly'              => true,
				'requiresAdministrator' => true,
				'mutationAuthority'     => false,
				'authentication'        => 'wordpress-application-password-or-cookie',
			],
	];
	}

	public function resource( string $resource, array $args = [] ): array {
		$resource = sanitize_key( $resource );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$limit    = max( 1, min( self::MAX_PAGE_SIZE, absint( $args['perPage'] ?? $args['limit'] ?? 50 ) ) );

		return match ( $resource ) {
			'site'          => $this->single( 'site', $this->site_record() ),
			'designcontext' => $this->single( 'designContext', ( new Design_Context_Profile_Service() )->public_context( true ) ),
			'menus'         => $this->data_query->query( [ 'resource' => 'menus' ], true ),
			'content'       => $this->content( $args, $page, $limit ),
			'products'      => $this->products( $page, $limit ),
			'terms'         => $this->terms( $args, $page, $limit ),
			'media'         => $this->media( $page, $limit ),
			default         => [ 'schema' => self::SCHEMA, 'error' => 'unsupported_resource', 'resource' => $resource ],
		};
	}

	private function site_record(): array {
		return [
			'name'        => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
			'description' => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
			'origin'      => $this->origin(),
			'language'    => sanitize_text_field( (string) get_bloginfo( 'language' ) ),
			'timezone'    => sanitize_text_field( (string) wp_timezone_string() ),
			'logoMediaId' => Site_Identity_Service::attachment_id(),
			'logoInverseMediaId' => Site_Identity_Service::attachment_id( Site_Identity_Service::OPTION_LOGO_INVERSE ),
			'siteIconMediaId' => absint( get_option( 'site_icon', 0 ) ),
			'publicContact' => [
				'phone' => Site_Identity_Service::store_phone(),
				'email' => Site_Identity_Service::store_email(),
			],
			'commerce' => [
				'enabled'          => class_exists( 'WooCommerce' ),
				'currency'         => function_exists( 'get_woocommerce_currency' ) ? sanitize_text_field( get_woocommerce_currency() ) : '',
				'currencyPosition' => sanitize_key( (string) get_option( 'woocommerce_currency_pos', '' ) ),
				'weightUnit'       => sanitize_key( (string) get_option( 'woocommerce_weight_unit', '' ) ),
				'dimensionUnit'    => sanitize_key( (string) get_option( 'woocommerce_dimension_unit', '' ) ),
			],
		];
	}

	private function content( array $args, int $page, int $limit ): array {
		$allowed   = $this->public_content_types();
		$post_type = sanitize_key( (string) ( $args['postType'] ?? '' ) );
		$types     = '' !== $post_type ? ( in_array( $post_type, $allowed, true ) ? [ $post_type ] : [] ) : $allowed;
		if ( [] === $types ) {
			return [ 'schema' => self::SCHEMA, 'resource' => 'content', 'error' => 'unsupported_post_type', 'data' => [] ];
		}

		$query = new \WP_Query(
			[
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		$data = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$data[] = $this->content_node( $post );
			}
		}
		wp_reset_postdata();

		return $this->paged( 'content', $data, $query->found_posts, $page, $limit, [ 'postTypes' => $types ] );
	}

	private function products( int $page, int $limit ): array {
		if ( ! post_type_exists( 'product' ) || ! function_exists( 'wc_get_product' ) ) {
			return $this->paged( 'products', [], 0, $page, $limit, [ 'woocommerceAvailable' => false ] );
		}
		$query = new \WP_Query(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		$data = [];
		foreach ( $query->posts as $post ) {
			$product = $post instanceof \WP_Post ? wc_get_product( $post->ID ) : false;
			if ( $product ) {
				$data[] = $this->product_node( $product, $post );
			}
		}
		wp_reset_postdata();

		return $this->paged( 'products', $data, $query->found_posts, $page, $limit, [ 'woocommerceAvailable' => true ] );
	}

	private function product_node( $product, \WP_Post $post ): array {
		$attributes = [];
		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! is_callable( [ $attribute, 'get_name' ] ) ) {
				continue;
			}
			$attributes[] = [
				'name'      => sanitize_text_field( (string) $attribute->get_name() ),
				'options'   => array_values( array_map( 'sanitize_text_field', (array) $attribute->get_options() ) ),
				'position'  => is_callable( [ $attribute, 'get_position' ] ) ? absint( $attribute->get_position() ) : 0,
				'visible'   => (bool) $attribute->get_visible(),
				'variation' => (bool) $attribute->get_variation(),
			];
		}
		$variations = [];
		$variation_ids = 'variable' === $product->get_type() ? (array) $product->get_children() : [];
		foreach ( array_slice( array_map( 'absint', $variation_ids ), 0, 250 ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || 'publish' !== get_post_status( $variation_id ) ) {
				continue;
			}
			$variations[] = [
				'sourceId'     => $variation_id,
				'sku'          => sanitize_text_field( (string) $variation->get_sku() ),
				'status'       => sanitize_key( (string) $variation->get_status() ),
				'price'        => (string) $variation->get_price(),
				'regularPrice' => (string) $variation->get_regular_price(),
				'salePrice'    => (string) $variation->get_sale_price(),
				'stockStatus'  => sanitize_key( (string) $variation->get_stock_status() ),
				'stockQuantity'=> is_null( $variation->get_stock_quantity() ) ? null : (float) $variation->get_stock_quantity(),
				'attributes'   => array_map( 'sanitize_text_field', (array) $variation->get_attributes() ),
				'imageMediaId' => absint( $variation->get_image_id() ),
				'weight'       => sanitize_text_field( (string) $variation->get_weight() ),
				'dimensions'   => array_map( 'sanitize_text_field', (array) $variation->get_dimensions( false ) ),
				'virtual'      => (bool) $variation->is_virtual(),
				'downloadable' => (bool) $variation->is_downloadable(),
			];
		}

		return [
			'sourceId'       => (int) $post->ID,
			'sourceKey'      => $this->source_key( 'product', (int) $post->ID ),
			'slug'           => sanitize_title( (string) $post->post_name ),
			'name'           => html_entity_decode( wp_strip_all_tags( (string) $post->post_title ), ENT_QUOTES ),
			'description'    => (string) $post->post_content,
			'shortDescription'=> (string) $post->post_excerpt,
			'type'           => sanitize_key( (string) $product->get_type() ),
			'sku'            => sanitize_text_field( (string) $product->get_sku() ),
			'price'          => (string) $product->get_price(),
			'regularPrice'   => (string) $product->get_regular_price(),
			'salePrice'      => (string) $product->get_sale_price(),
			'taxStatus'      => sanitize_key( (string) $product->get_tax_status() ),
			'taxClass'       => sanitize_key( (string) $product->get_tax_class() ),
			'stockStatus'    => sanitize_key( (string) $product->get_stock_status() ),
			'manageStock'    => (bool) $product->get_manage_stock(),
			'stockQuantity'  => is_null( $product->get_stock_quantity() ) ? null : (float) $product->get_stock_quantity(),
			'backorders'     => sanitize_key( (string) $product->get_backorders() ),
			'soldIndividually'=> (bool) $product->get_sold_individually(),
			'virtual'        => (bool) $product->is_virtual(),
			'downloadable'   => (bool) $product->is_downloadable(),
			'downloadFileCount' => count( (array) $product->get_downloads() ),
			'externalUrl'    => 'external' === $product->get_type() ? esc_url_raw( (string) $product->get_product_url() ) : '',
			'buttonText'     => is_callable( [ $product, 'get_button_text' ] ) ? sanitize_text_field( (string) $product->get_button_text() ) : '',
			'weight'         => sanitize_text_field( (string) $product->get_weight() ),
			'dimensions'     => array_map( 'sanitize_text_field', (array) $product->get_dimensions( false ) ),
			'imageMediaId'   => absint( $product->get_image_id() ),
			'galleryMediaIds'=> array_values( array_map( 'absint', (array) $product->get_gallery_image_ids() ) ),
			'attributes'     => $attributes,
			'defaultAttributes' => array_map( 'sanitize_text_field', (array) $product->get_default_attributes() ),
			'variations'     => $variations,
			'linkedSourceIds'=> [
				'upsells'    => array_values( array_map( 'absint', (array) $product->get_upsell_ids() ) ),
				'crossSells' => array_values( array_map( 'absint', (array) $product->get_cross_sell_ids() ) ),
				'grouped'    => 'grouped' === $product->get_type() ? array_values( array_map( 'absint', (array) $product->get_children() ) ) : [],
			],
			'terms'          => $this->post_terms( $post ),
			'publicMeta'     => $this->public_meta( $post->ID, 'product' ),
			'kiwe'           => [
				'nutritionImageMediaId' => absint( get_post_meta( $post->ID, Product_Context_Service::META_NUTRITION_IMAGE_ID, true ) ),
			],
			'modifiedGmt'    => get_post_modified_time( 'c', true, $post ),
		];
	}

	private function terms( array $args, int $page, int $limit ): array {
		$allowed  = $this->public_taxonomies();
		$taxonomy = sanitize_key( (string) ( $args['taxonomy'] ?? '' ) );
		$taxes    = '' !== $taxonomy ? ( in_array( $taxonomy, $allowed, true ) ? [ $taxonomy ] : [] ) : $allowed;
		if ( [] === $taxes ) {
			return [ 'schema' => self::SCHEMA, 'resource' => 'terms', 'error' => 'unsupported_taxonomy', 'data' => [] ];
		}
		$terms = get_terms( [ 'taxonomy' => $taxes, 'hide_empty' => false, 'number' => $limit, 'offset' => ( $page - 1 ) * $limit, 'orderby' => 'term_id', 'order' => 'ASC' ] );
		$data  = [];
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$data[] = [
					'sourceId' => absint( $term->term_id ?? 0 ),
					'sourceKey'=> $this->source_key( (string) ( $term->taxonomy ?? '' ), absint( $term->term_id ?? 0 ) ),
					'taxonomy' => sanitize_key( (string) ( $term->taxonomy ?? '' ) ),
					'name'     => sanitize_text_field( (string) ( $term->name ?? '' ) ),
					'slug'     => sanitize_title( (string) ( $term->slug ?? '' ) ),
					'description' => sanitize_textarea_field( (string) ( $term->description ?? '' ) ),
					'parentSourceId' => absint( $term->parent ?? 0 ),
				];
			}
		}
		return $this->paged( 'terms', $data, $this->term_count( $taxes ), $page, $limit, [ 'taxonomies' => $taxes ] );
	}

	private function media( int $page, int $limit ): array {
		$allowed_ids = $this->transferable_media_ids();
		if ( [] === $allowed_ids ) {
			return $this->paged( 'media', [], 0, $page, $limit );
		}
		$query = new \WP_Query( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => $allowed_ids, 'posts_per_page' => $limit, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC' ] );
		$data  = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$url = wp_get_attachment_url( $post->ID );
			if ( ! $url ) {
				continue;
			}
			$metadata = wp_get_attachment_metadata( $post->ID );
			$data[] = [
				'sourceId'  => (int) $post->ID,
				'sourceKey' => $this->source_key( 'attachment', (int) $post->ID ),
				'sourceUrl' => esc_url_raw( $url ),
				'mimeType'  => sanitize_mime_type( (string) $post->post_mime_type ),
				'filename'  => sanitize_file_name( wp_basename( (string) parse_url( $url, PHP_URL_PATH ) ) ),
				'title'     => sanitize_text_field( (string) $post->post_title ),
				'alt'       => sanitize_text_field( (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) ),
				'caption'   => sanitize_textarea_field( (string) $post->post_excerpt ),
				'description'=> wp_kses_post( (string) $post->post_content ),
				'parentSourceId' => absint( $post->post_parent ),
				'width'     => absint( is_array( $metadata ) ? ( $metadata['width'] ?? 0 ) : 0 ),
				'height'    => absint( is_array( $metadata ) ? ( $metadata['height'] ?? 0 ) : 0 ),
				'modifiedGmt' => get_post_modified_time( 'c', true, $post ),
			];
		}
		wp_reset_postdata();
		return $this->paged( 'media', $data, $query->found_posts, $page, $limit );
	}

	private function content_node( \WP_Post $post ): array {
		return [
			'sourceId'       => (int) $post->ID,
			'sourceKey'      => $this->source_key( (string) $post->post_type, (int) $post->ID ),
			'postType'       => sanitize_key( (string) $post->post_type ),
			'slug'           => sanitize_title( (string) $post->post_name ),
			'title'          => html_entity_decode( wp_strip_all_tags( (string) $post->post_title ), ENT_QUOTES ),
			'content'        => (string) $post->post_content,
			'excerpt'        => (string) $post->post_excerpt,
			'parentSourceId' => absint( $post->post_parent ),
			'menuOrder'      => (int) $post->menu_order,
			'featuredMediaId'=> absint( get_post_thumbnail_id( $post->ID ) ),
			'terms'          => $this->post_terms( $post ),
			'publicMeta'     => $this->public_meta( $post->ID, (string) $post->post_type ),
			'authorDisplay'  => sanitize_text_field( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ),
			'modifiedGmt'    => get_post_modified_time( 'c', true, $post ),
		];
	}

	private function post_terms( \WP_Post $post ): array {
		$out = [];
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy => $object ) {
			if ( empty( $object->public ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, (string) $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$out[] = [ 'sourceId' => absint( $term->term_id ?? 0 ), 'taxonomy' => sanitize_key( (string) $taxonomy ), 'slug' => sanitize_title( (string) ( $term->slug ?? '' ) ) ];
			}
		}
		return $out;
	}

	private function public_meta( int $post_id, string $post_type ): array {
		$registered = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $post_type ) : [];
		$out = [];
		foreach ( is_array( $registered ) ? $registered : [] as $key => $schema ) {
			if ( empty( $schema['show_in_rest'] ) || str_starts_with( (string) $key, '_' ) || preg_match( '/password|secret|token|nonce|session|cookie|license|consumer|private|payment|credential|authorization|api[_-]?key|webhook/i', (string) $key ) ) {
				continue;
			}
			$value = get_post_meta( $post_id, (string) $key, true );
			if ( is_scalar( $value ) || is_array( $value ) ) {
				$out[ sanitize_key( (string) $key ) ] = $this->safe_value( $value );
			}
		}
		return $out;
	}

	private function safe_value( $value, int $depth = 0 ) {
		if ( $depth > 3 ) {
			return null;
		}
		if ( is_scalar( $value ) || null === $value ) {
			return is_string( $value ) ? substr( wp_strip_all_tags( $value ), 0, 4000 ) : $value;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$out = [];
		foreach ( array_slice( $value, 0, 80, true ) as $key => $item ) {
			if ( is_string( $key ) && preg_match( '/password|secret|token|nonce|session|cookie|license|consumer|private|payment|credential|authorization|api[_-]?key|webhook/i', $key ) ) {
				continue;
			}
			$clean = $this->safe_value( $item, $depth + 1 );
			if ( null !== $clean ) {
				$out[ is_string( $key ) ? sanitize_key( $key ) : $key ] = $clean;
			}
		}
		return $out;
	}

	private function public_content_types(): array {
		$types = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $name => $object ) {
			$name = sanitize_key( (string) $name );
			if ( in_array( $name, [ 'attachment', 'product' ], true ) || empty( $object->show_ui ) ) {
				continue;
			}
			$types[] = $name;
		}
		sort( $types );
		return $types;
	}

	private function public_taxonomies(): array {
		$taxonomies = array_keys( get_taxonomies( [ 'public' => true ], 'objects' ) );
		$taxonomies = array_values( array_filter( array_map( 'sanitize_key', $taxonomies ), static fn( string $taxonomy ): bool => 'nav_menu' !== $taxonomy && 'link_category' !== $taxonomy ) );
		sort( $taxonomies );
		return $taxonomies;
	}

	private function post_count( array $post_types ): int {
		$total = 0;
		foreach ( $post_types as $post_type ) {
			$counts = wp_count_posts( $post_type );
			$total += isset( $counts->publish ) ? absint( $counts->publish ) : 0;
		}
		return $total;
	}

	private function term_count( array $taxonomies ): int {
		$total = 0;
		foreach ( $taxonomies as $taxonomy ) {
			$count = wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
			if ( ! is_wp_error( $count ) ) {
				$total += absint( $count );
			}
		}
		return $total;
	}

	private function media_count(): int {
		return count( $this->transferable_media_ids() );
	}

	private function transferable_media_ids(): array {
		if ( null !== $this->transferable_media_ids ) {
			return $this->transferable_media_ids;
		}

		$ids = [
			Site_Identity_Service::attachment_id(),
			Site_Identity_Service::attachment_id( Site_Identity_Service::OPTION_LOGO_INVERSE ),
			absint( get_option( 'site_icon', 0 ) ),
		];
		$profile = ( new Design_Context_Profile_Service() )->public_context( true );
		$ids[] = absint( $profile['about']['founder']['imageId'] ?? 0 );
		foreach ( (array) ( $profile['about']['team']['members'] ?? [] ) as $member ) {
			$ids[] = absint( is_array( $member ) ? ( $member['imageId'] ?? 0 ) : 0 );
		}
		foreach ( (array) ( $profile['resources']['items'] ?? [] ) as $resource ) {
			$ids[] = absint( is_array( $resource ) ? ( $resource['attachmentId'] ?? 0 ) : 0 );
		}

		$post_ids = get_posts(
			[
				'post_type'              => array_merge( $this->public_content_types(), post_type_exists( 'product' ) ? [ 'product' ] : [] ),
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		foreach ( array_map( 'absint', (array) $post_ids ) as $post_id ) {
			$ids[] = absint( get_post_thumbnail_id( $post_id ) );
			$content = (string) get_post_field( 'post_content', $post_id );
			if ( preg_match_all( '/(?:wp-image-|attachment_)(\d+)/', $content, $matches ) ) {
				$ids = array_merge( $ids, array_map( 'absint', $matches[1] ) );
			}
			if ( 'product' === get_post_type( $post_id ) && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );
				if ( $product ) {
					$ids = array_merge( $ids, array_map( 'absint', (array) $product->get_gallery_image_ids() ) );
					$ids[] = absint( get_post_meta( $post_id, Product_Context_Service::META_NUTRITION_IMAGE_ID, true ) );
					foreach ( 'variable' === $product->get_type() ? (array) $product->get_children() : [] as $variation_id ) {
						$variation = wc_get_product( absint( $variation_id ) );
						if ( $variation ) {
							$ids[] = absint( $variation->get_image_id() );
						}
					}
				}
			}
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ), static fn( int $id ): bool => $id > 0 && 'attachment' === get_post_type( $id ) ) ) );
		sort( $ids );
		$this->transferable_media_ids = $ids;
		return $ids;
	}

	private function latest_public_modified(): string {
		global $wpdb;
		$post_types = array_merge( $this->public_content_types(), post_type_exists( 'product' ) ? [ 'product' ] : [], [ 'attachment' ] );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql = $wpdb->prepare( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status IN ('publish','inherit')", ...$post_types ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return sanitize_text_field( (string) $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function paged( string $resource, array $data, int $total, int $page, int $limit, array $extra = [] ): array {
		return [
			'schema'      => self::SCHEMA,
			'generatedAt' => gmdate( 'c' ),
			'resource'    => $resource,
			'data'        => $data,
			'pageInfo'    => [ 'page' => $page, 'perPage' => $limit, 'total' => $total, 'totalPages' => $limit > 0 ? (int) ceil( $total / $limit ) : 0 ],
		] + $extra;
	}

	private function single( string $resource, array $data ): array {
		return [ 'schema' => self::SCHEMA, 'generatedAt' => gmdate( 'c' ), 'resource' => $resource, 'data' => $data ];
	}

	private function source_key( string $type, int $id ): string {
		return substr( hash( 'sha256', $this->origin() . '|' . sanitize_key( $type ) . '|' . $id ), 0, 32 );
	}

	private function origin(): string {
		return strtolower( untrailingslashit( esc_url_raw( home_url( '/' ) ) ) );
	}

	private function json( array $value ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}
}
