<?php

namespace DSA\Site_Graph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI-agnostic public/admin data reader for Kiwe Site Graph.
 *
 * This is the GraphQL-like read layer: consumers can request real WordPress,
 * WooCommerce, media, taxonomy, menu, and site data through one normalized
 * endpoint. Mutations remain outside Site Graph.
 */
final class Data_Query_Service {
	private const PUBLIC_LIMIT = 100;
	private const PRIVATE_LIMIT = 200;
	private const BATCH_LIMIT = 20;

	/**
	 * Describe the AI-less Site Graph data reader itself.
	 *
	 * This is intentionally small and deterministic so browser tools, headless
	 * frontends, and humans can discover the contract without reading plugin code.
	 */
	public function schema(): array {
		return [
			'schema'      => 'kiwe.site-graph.data-schema.v1',
			'generatedAt' => gmdate( 'c' ),
			'resources'   => [
				'site'       => [
					'description' => 'Public site identity, URLs, language, timezone, and logo.',
				],
				'menus'      => [
					'description' => 'WordPress navigation menus and assigned theme locations.',
					'args'        => [ 'location' ],
				],
				'posts'      => [
					'description' => 'Public WordPress posts or any public post type.',
					'args'        => [ 'postType', 'limit', 'page', 'search', 'slug', 'include', 'taxonomy', 'term', 'fields' ],
				],
				'pages'      => [
					'description' => 'Public WordPress pages.',
					'args'        => [ 'limit', 'page', 'search', 'slug', 'include', 'fields' ],
				],
				'products'   => [
					'description' => 'WooCommerce products when WooCommerce is active.',
					'args'        => [ 'limit', 'page', 'search', 'slug', 'include', 'taxonomy', 'term', 'category', 'fields' ],
				],
				'terms'      => [
					'description' => 'Public taxonomy terms.',
					'args'        => [ 'taxonomy', 'limit', 'search', 'slug', 'hideEmpty' ],
				],
				'media'      => [
					'description' => 'Published/inherited media, primarily images for headless previews.',
					'args'        => [ 'limit', 'page', 'mimeType' ],
				],
				'batch'      => [
					'description' => 'Run up to 20 named data queries in one request via the queries object/array, or use resources as a compact shorthand.',
					'args'        => [ 'queries', 'resources', 'limits' ],
				],
			],
			'fields'      => [
				'post' => [ 'id', 'type', 'slug', 'status', 'title', 'url', 'excerpt', 'date', 'modified', 'content', 'featuredImage', 'terms', 'product', 'meta' ],
				'term' => [ 'id', 'taxonomy', 'name', 'slug', 'description', 'count', 'url' ],
				'media' => [ 'id', 'url', 'alt', 'title', 'width', 'height', 'sizes' ],
			],
			'examples'    => [
				[
					'resource' => 'products',
					'taxonomy' => 'product_cat',
					'term'     => 'fudge',
					'limit'    => 4,
					'fields'   => [ 'id', 'title', 'url', 'featuredImage', 'product', 'terms' ],
				],
				[
					'queries' => [
						'site'        => [ 'resource' => 'site' ],
						'mainMenu'    => [ 'resource' => 'menus', 'location' => 'primary' ],
						'fudgeRail'   => [ 'resource' => 'products', 'category' => 'fudge', 'limit' => 4 ],
						'latestPosts' => [ 'resource' => 'posts', 'limit' => 6 ],
					],
				],
				[
					'resources' => [ 'site', 'products', 'pages', 'media' ],
					'limits'    => [ 'products' => 4, 'pages' => 4, 'media' => 4 ],
				],
			],
			'boundaries'  => [
				'Public calls only return public/published objects.',
				'Authenticated administrators can request broader status/meta fields.',
				'Writes stay in Kiwe Controlled Executor, not Site Graph Data.',
			],
		];
	}

	public function query( array $args = [], bool $private = false ): array {
		if ( isset( $args['queries'] ) && is_array( $args['queries'] ) ) {
			return $this->batch( $args['queries'], $private );
		}

		if ( isset( $args['resources'] ) && is_array( $args['resources'] ) ) {
			return $this->batch( $this->queries_from_resources( $args['resources'], $args ), $private );
		}

		$resource = sanitize_key( (string) ( $args['resource'] ?? $args['type'] ?? 'posts' ) );

		return match ( $resource ) {
			'site' => $this->site(),
			'menu', 'menus' => $this->menus( $args, $private ),
			'category', 'categories' => $this->terms( array_merge( $args, [ 'taxonomy' => 'category' ] ), $private ),
			'productcategory', 'productcategories', 'product_cat', 'product-categories' => $this->terms( array_merge( $args, [ 'taxonomy' => 'product_cat' ] ), $private ),
			'term', 'terms', 'taxonomies', 'taxonomy' => $this->terms( $args, $private ),
			'media', 'images', 'attachments' => $this->media( $args, $private ),
			'page', 'pages' => $this->posts( array_merge( $args, [ 'postType' => 'page' ] ), $private ),
			'product', 'products' => $this->posts( array_merge( $args, [ 'postType' => 'product' ] ), $private ),
			'post', 'posts', 'content', 'nodes' => $this->posts( $args, $private ),
			default => $this->posts( array_merge( $args, [ 'postType' => $resource ] ), $private ),
		};
	}

	private function batch( array $queries, bool $private ): array {
		$out   = [];
		$count = 0;

		foreach ( $queries as $name => $query ) {
			if ( ++$count > self::BATCH_LIMIT ) {
				break;
			}

			if ( ! is_array( $query ) ) {
				continue;
			}

			$key         = is_string( $name ) ? $this->batch_key( $name ) : 'query_' . $count;
			$out[ $key ] = $this->query( $query, $private );
		}

		return [
			'schema'      => 'kiwe.site-graph.data-batch.v1',
			'generatedAt' => gmdate( 'c' ),
			'count'       => count( $out ),
			'private'     => $private,
			'data'        => $out,
		];
	}

	private function queries_from_resources( array $resources, array $args ): array {
		$out    = [];
		$limits = isset( $args['limits'] ) && is_array( $args['limits'] ) ? $args['limits'] : [];

		foreach ( $resources as $resource ) {
			$key = sanitize_key( (string) $resource );
			if ( '' === $key ) {
				continue;
			}

			$query = [ 'resource' => $key ];
			if ( isset( $limits[ $key ] ) ) {
				$query['limit'] = $limits[ $key ];
			} elseif ( isset( $args['limit'] ) ) {
				$query['limit'] = $args['limit'];
			}

			foreach ( [ 'fields', 'taxonomy', 'term', 'category', 'search', 'page', 'postType', 'post_type' ] as $shared_key ) {
				if ( isset( $args[ $shared_key ] ) ) {
					$query[ $shared_key ] = $args[ $shared_key ];
				}
			}

			$out[ $key ] = $query;
		}

		return $out;
	}

	private function batch_key( string $name ): string {
		$key = preg_replace( '/[^A-Za-z0-9_-]/', '', $name );

		return $key ?: 'query';
	}

	private function envelope( string $resource, array $args, array $data, array $extra = [] ): array {
		return [
			'schema'      => 'kiwe.site-graph.data.v1',
			'generatedAt' => gmdate( 'c' ),
			'resource'    => $resource,
			'args'        => $args,
			'data'        => $data,
		] + $extra;
	}

	private function site(): array {
		$custom_logo = get_theme_mod( 'custom_logo' );

		return $this->envelope(
			'site',
			[],
			[
				'name'        => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'description' => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
				'homeUrl'     => esc_url_raw( home_url( '/' ) ),
				'language'    => sanitize_text_field( (string) get_bloginfo( 'language' ) ),
				'timezone'    => sanitize_text_field( (string) wp_timezone_string() ),
				'logo'        => $custom_logo ? $this->image_node( (int) $custom_logo ) : null,
			]
		);
	}

	private function posts( array $args, bool $private ): array {
		$post_type = sanitize_key( (string) ( $args['postType'] ?? $args['post_type'] ?? 'post' ) );
		$resource  = $this->post_resource_name( $post_type );
		if ( ! post_type_exists( $post_type ) ) {
			return $this->envelope( $resource, [ 'postType' => $post_type ], [], [ 'error' => 'unknown_post_type' ] );
		}

		$object = get_post_type_object( $post_type );
		if ( ! $private && ( ! $object || empty( $object->public ) ) ) {
			return $this->envelope( $resource, [ 'postType' => $post_type ], [], [ 'error' => 'post_type_not_public' ] );
		}

		$limit = $this->limit( $args, $private );
		$page  = max( 1, absint( $args['page'] ?? $args['paged'] ?? 1 ) );
		$order = strtoupper( (string) ( $args['order'] ?? 'DESC' ) );
		$order = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';
		$orderby = sanitize_key( (string) ( $args['orderby'] ?? 'date' ) );
		$orderby = in_array( $orderby, [ 'date', 'modified', 'title', 'menu_order', 'rand', 'id', 'post__in' ], true ) ? $orderby : 'date';
		if ( 'id' === $orderby ) {
			$orderby = 'ID';
		}

		$query_args = [
			'post_type'           => $post_type,
			'post_status'         => $private ? $this->status( $args ) : 'publish',
			'posts_per_page'      => $limit,
			'paged'               => $page,
			'orderby'             => $orderby,
			'order'               => $order,
			'ignore_sticky_posts' => true,
		];

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $args['search'] );
		}

		if ( ! empty( $args['slug'] ) ) {
			$query_args['name'] = sanitize_title( (string) $args['slug'] );
		}

		$include = $this->ids( $args['include'] ?? [] );
		if ( $include ) {
			$query_args['post__in'] = $include;
			$query_args['orderby']  = 'post__in';
		}

		$tax_query = $this->tax_query( $this->normalized_tax_args( $args, $post_type ), $private );
		if ( $tax_query ) {
			$query_args['tax_query'] = $tax_query;
		}

		$query  = new \WP_Query( $query_args );
		$fields = $this->fields( $args );
		$nodes  = [];

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$nodes[] = $this->post_node( $post, $fields, $args, $private );
			}
		}

		wp_reset_postdata();

		return $this->envelope(
			$resource,
			[
				'postType' => $post_type,
				'limit'    => $limit,
				'page'     => $page,
				'private'  => $private,
				'taxonomy' => $tax_query[0]['taxonomy'] ?? null,
				'terms'    => $tax_query[0]['terms'] ?? [],
			],
			$nodes,
			[
				'pageInfo' => [
					'total'      => (int) $query->found_posts,
					'totalPages' => (int) $query->max_num_pages,
					'page'       => $page,
					'limit'      => $limit,
				],
			]
		);
	}

	private function post_resource_name( string $post_type ): string {
		return match ( $post_type ) {
			'page' => 'pages',
			'product' => 'products',
			'post' => 'posts',
			default => $post_type,
		};
	}

	private function post_node( \WP_Post $post, array $fields, array $args, bool $private ): array {
		$product = 'product' === $post->post_type && function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
		$node    = [
			'id'       => (int) $post->ID,
			'type'     => sanitize_key( (string) $post->post_type ),
			'slug'     => sanitize_title( (string) $post->post_name ),
			'status'   => sanitize_key( (string) $post->post_status ),
			'title'    => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES ),
			'url'      => esc_url_raw( get_permalink( $post ) ),
			'excerpt'  => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'date'     => get_post_time( 'c', true, $post ),
			'modified' => get_post_modified_time( 'c', true, $post ),
		];

		if ( $this->wants( $fields, 'content' ) ) {
			$node['content'] = apply_filters( 'the_content', $post->post_content );
		}

		if ( $this->wants( $fields, 'featuredImage' ) || $this->wants( $fields, 'image' ) ) {
			$image_id = get_post_thumbnail_id( $post );
			$node['featuredImage'] = $image_id ? $this->image_node( (int) $image_id ) : null;
		}

		if ( $this->wants( $fields, 'terms' ) ) {
			$node['terms'] = $this->post_terms( $post );
		}

		if ( $product ) {
			$node['product'] = [
				'id'           => (int) $product->get_id(),
				'type'         => sanitize_key( (string) $product->get_type() ),
				'price'        => wp_strip_all_tags( (string) $product->get_price() ),
				'regularPrice' => wp_strip_all_tags( (string) $product->get_regular_price() ),
				'salePrice'    => wp_strip_all_tags( (string) $product->get_sale_price() ),
				'currency'     => function_exists( 'get_woocommerce_currency' ) ? sanitize_text_field( get_woocommerce_currency() ) : '',
				'inStock'      => $product->is_in_stock(),
				'purchasable'  => $product->is_purchasable(),
				'sku'          => sanitize_text_field( (string) $product->get_sku() ),
			];
		}

		if ( $private && ! empty( $args['metaKeys'] ) ) {
			$node['meta'] = $this->selected_meta( $post->ID, $args['metaKeys'] );
		}

		return $this->pick_fields( $node, $fields );
	}

	private function media( array $args, bool $private ): array {
		$limit = $this->limit( $args, $private );
		$query = new \WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => $private ? $this->status( $args, [ 'inherit', 'private' ] ) : 'inherit',
				'post_mime_type' => sanitize_text_field( (string) ( $args['mimeType'] ?? $args['mime'] ?? 'image' ) ),
				'posts_per_page' => $limit,
				'paged'          => max( 1, absint( $args['page'] ?? 1 ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);
		$nodes = [];

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$nodes[] = $this->image_node( (int) $post->ID );
			}
		}

		wp_reset_postdata();

		return $this->envelope( 'media', [ 'limit' => $limit, 'private' => $private ], array_values( array_filter( $nodes ) ) );
	}

	private function terms( array $args, bool $private ): array {
		$taxonomy = sanitize_key( (string) ( $args['taxonomy'] ?? $args['tax'] ?? 'category' ) );
		if ( 'productcategories' === $taxonomy || 'product-categories' === $taxonomy ) {
			$taxonomy = 'product_cat';
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->envelope( 'terms', [ 'taxonomy' => $taxonomy ], [], [ 'error' => 'unknown_taxonomy' ] );
		}

		$object = get_taxonomy( $taxonomy );
		if ( ! $private && ( ! $object || empty( $object->public ) ) ) {
			return $this->envelope( 'terms', [ 'taxonomy' => $taxonomy ], [], [ 'error' => 'taxonomy_not_public' ] );
		}

		$term_args = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => isset( $args['hideEmpty'] ) ? (bool) $args['hideEmpty'] : false,
			'number'     => $this->limit( $args, $private ),
		];

		if ( ! empty( $args['search'] ) ) {
			$term_args['search'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( ! empty( $args['slug'] ) ) {
			$term_args['slug'] = sanitize_title( (string) $args['slug'] );
		}

		$terms = get_terms( $term_args );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $this->envelope( 'terms', [ 'taxonomy' => $taxonomy ], [], [ 'error' => 'term_query_failed' ] );
		}

		$nodes = array_map(
			static function ( $term ) use ( $taxonomy ): array {
				return [
					'id'          => absint( $term->term_id ?? 0 ),
					'taxonomy'    => $taxonomy,
					'name'        => sanitize_text_field( (string) ( $term->name ?? '' ) ),
					'slug'        => sanitize_title( (string) ( $term->slug ?? '' ) ),
					'description' => wp_strip_all_tags( (string) ( $term->description ?? '' ) ),
					'count'       => max( 0, (int) ( $term->count ?? 0 ) ),
					'url'         => esc_url_raw( is_wp_error( get_term_link( $term ) ) ? '' : get_term_link( $term ) ),
				];
			},
			$terms
		);

		return $this->envelope( 'terms', [ 'taxonomy' => $taxonomy, 'private' => $private ], $nodes );
	}

	private function menus( array $args, bool $private ): array {
		$locations = get_nav_menu_locations();
		$menus     = [];
		$wanted    = sanitize_key( (string) ( $args['location'] ?? '' ) );

		foreach ( wp_get_nav_menus() as $menu ) {
			$term_id = absint( $menu->term_id ?? 0 );
			if ( ! $term_id ) {
				continue;
			}

			$menu_locations = array_values(
				array_keys(
					array_filter(
						is_array( $locations ) ? $locations : [],
						static fn( $value ): bool => absint( $value ) === $term_id
					)
				)
			);
			if ( $wanted && ! in_array( $wanted, $menu_locations, true ) ) {
				continue;
			}

			$items = [];
			foreach ( wp_get_nav_menu_items( $term_id ) ?: [] as $item ) {
				$items[] = [
					'id'       => absint( $item->ID ?? 0 ),
					'title'    => sanitize_text_field( (string) ( $item->title ?? '' ) ),
					'url'      => esc_url_raw( (string) ( $item->url ?? '' ) ),
					'type'     => sanitize_key( (string) ( $item->type ?? '' ) ),
					'object'   => sanitize_key( (string) ( $item->object ?? '' ) ),
					'objectId' => absint( $item->object_id ?? 0 ),
					'parent'   => absint( $item->menu_item_parent ?? 0 ),
					'classes'  => array_values( array_filter( array_map( 'sanitize_html_class', (array) ( $item->classes ?? [] ) ) ) ),
				];
			}

			$menus[] = [
				'id'        => $term_id,
				'name'      => sanitize_text_field( (string) ( $menu->name ?? '' ) ),
				'slug'      => sanitize_title( (string) ( $menu->slug ?? '' ) ),
				'locations' => $menu_locations,
				'items'     => $items,
			];
		}

		return $this->envelope( 'menus', [ 'location' => $wanted, 'private' => $private ], $menus );
	}

	private function image_node( int $id ): ?array {
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return null;
		}

		$meta  = wp_get_attachment_metadata( $id );
		$sizes = [];
		foreach ( [ 'thumbnail', 'medium', 'medium_large', 'large', 'full' ] as $size ) {
			$src = wp_get_attachment_image_src( $id, $size );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$sizes[ $size ] = [
					'url'    => esc_url_raw( $src[0] ),
					'width'  => absint( $src[1] ?? 0 ),
					'height' => absint( $src[2] ?? 0 ),
				];
			}
		}

		return [
			'id'     => $id,
			'url'    => esc_url_raw( $url ),
			'alt'    => sanitize_text_field( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
			'title'  => sanitize_text_field( (string) get_the_title( $id ) ),
			'width'  => absint( $meta['width'] ?? 0 ),
			'height' => absint( $meta['height'] ?? 0 ),
			'sizes'  => $sizes,
		];
	}

	private function post_terms( \WP_Post $post ): array {
		$out = [];
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy => $object ) {
			if ( empty( $object->public ) ) {
				continue;
			}

			$terms = wp_get_post_terms( $post->ID, (string) $taxonomy );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$out[] = [
					'id'       => absint( $term->term_id ?? 0 ),
					'taxonomy' => sanitize_key( (string) $taxonomy ),
					'name'     => sanitize_text_field( (string) ( $term->name ?? '' ) ),
					'slug'     => sanitize_title( (string) ( $term->slug ?? '' ) ),
				];
			}
		}

		return $out;
	}

	private function tax_query( array $args, bool $private ): array {
		$taxonomy = $this->normalize_taxonomy( (string) ( $args['taxonomy'] ?? $args['tax'] ?? '' ) );
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		$object = get_taxonomy( $taxonomy );
		if ( ! $private && ( ! $object || empty( $object->public ) ) ) {
			return [];
		}

		$term_ids = $this->ids( $args['termId'] ?? $args['termIds'] ?? [] );
		$terms    = $term_ids;
		$field    = 'term_id';

		if ( ! $terms ) {
			$raw_terms = $args['term'] ?? $args['terms'] ?? $args['termSlug'] ?? $args['termSlugs'] ?? [];
			$raw_terms = is_array( $raw_terms ) ? $raw_terms : [ $raw_terms ];
			$terms     = array_values( array_filter( array_map( 'sanitize_title', $raw_terms ) ) );
			$field     = 'slug';
		}

		if ( ! $terms ) {
			return [];
		}

		return [
			[
				'taxonomy' => $taxonomy,
				'field'    => $field,
				'terms'    => $terms,
			],
		];
	}

	private function normalized_tax_args( array $args, string $post_type ): array {
		if ( empty( $args['taxonomy'] ) && empty( $args['tax'] ) ) {
			if ( ! empty( $args['productCategory'] ) || ! empty( $args['product_category'] ) ) {
				$args['taxonomy'] = 'product_cat';
				$args['term']     = $args['productCategory'] ?? $args['product_category'];
			} elseif ( ! empty( $args['category'] ) ) {
				$args['taxonomy'] = 'product' === $post_type ? 'product_cat' : 'category';
				$args['term']     = $args['category'];
			} elseif ( ! empty( $args['tag'] ) ) {
				$args['taxonomy'] = 'post_tag';
				$args['term']     = $args['tag'];
			}
		}

		if ( ! empty( $args['taxonomy'] ) ) {
			$args['taxonomy'] = $this->normalize_taxonomy( (string) $args['taxonomy'] );
		}

		return $args;
	}

	private function normalize_taxonomy( string $taxonomy ): string {
		$taxonomy = sanitize_key( $taxonomy );

		return match ( $taxonomy ) {
			'productcategory', 'productcategories', 'product-categories', 'productcat' => 'product_cat',
			'posttag', 'tags' => 'post_tag',
			'categories' => 'category',
			default => $taxonomy,
		};
	}

	private function selected_meta( int $post_id, $keys ): array {
		$keys = is_array( $keys ) ? $keys : [ $keys ];
		$out  = [];

		foreach ( array_slice( $keys, 0, 30 ) as $key ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = get_post_meta( $post_id, $key, true );
		}

		return $out;
	}

	private function fields( array $args ): array {
		$fields = $args['fields'] ?? [];
		if ( is_string( $fields ) ) {
			$fields = preg_split( '/[\s,]+/', $fields );
		}
		if ( ! is_array( $fields ) ) {
			return [];
		}

		$out = [];
		foreach ( $fields as $field ) {
			$key = sanitize_key( (string) $field );
			if ( '' === $key ) {
				continue;
			}
			$out[] = $this->canonical_field( $key );
		}

		return array_values( array_unique( $out ) );
	}

	private function wants( array $fields, string $field ): bool {
		return empty( $fields ) || in_array( $this->canonical_field( sanitize_key( $field ) ), $fields, true );
	}

	private function pick_fields( array $node, array $fields ): array {
		if ( empty( $fields ) ) {
			return $node;
		}

		$out = [];
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $node ) ) {
				$out[ $field ] = $node[ $field ];
			}
		}

		if ( ! isset( $out['id'] ) && isset( $node['id'] ) ) {
			$out = [ 'id' => $node['id'] ] + $out;
		}

		return $out;
	}

	private function canonical_field( string $field ): string {
		return match ( $field ) {
			'featuredimage', 'featured_image', 'image' => 'featuredImage',
			'regularprice' => 'regularPrice',
			'saleprice' => 'salePrice',
			'objectid' => 'objectId',
			default => $field,
		};
	}

	private function ids( $raw ): array {
		$raw = is_array( $raw ) ? $raw : ( is_string( $raw ) ? preg_split( '/[\s,]+/', $raw ) : [ $raw ] );

		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	private function status( array $args, array $fallback = [ 'publish', 'future', 'draft', 'pending', 'private' ] ) {
		$status = $args['status'] ?? $fallback;
		$status = is_array( $status ) ? $status : [ $status ];
		$status = array_values( array_filter( array_map( 'sanitize_key', $status ) ) );

		return $status ?: $fallback;
	}

	private function limit( array $args, bool $private ): int {
		$max = $private ? self::PRIVATE_LIMIT : self::PUBLIC_LIMIT;

		return max( 1, min( $max, absint( $args['limit'] ?? $args['perPage'] ?? $args['per_page'] ?? 12 ) ) );
	}
}
