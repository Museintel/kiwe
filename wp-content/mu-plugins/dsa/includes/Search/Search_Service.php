<?php

namespace DSA\Search;

use DSA\Commerce\Cart_Payload_Service;
use DSA\Settings;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Search_Service {
	private const CACHE_GROUP = 'dsa_search';
	private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;
	private const CACHE_VERSION_OPTION = 'dsa_search_cache_version';

	public function __construct( private Settings $settings, private Cart_Payload_Service $cartPayload ) {}

	public function register(): void {
		add_action( 'save_post', [ $this, 'invalidate_for_post' ], 20, 2 );
		add_action( 'deleted_post', [ $this, 'invalidate' ] );
		add_action( 'profile_update', [ $this, 'invalidate' ] );
		add_action( 'user_register', [ $this, 'invalidate' ] );
		add_action( 'deleted_user', [ $this, 'invalidate' ] );
		add_action( 'created_term', [ $this, 'invalidate' ] );
		add_action( 'edited_term', [ $this, 'invalidate' ] );
		add_action( 'delete_term', [ $this, 'invalidate' ] );
	}

	public function invalidate_for_post( int $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post_type = is_object( $post ) && isset( $post->post_type ) ? (string) $post->post_type : (string) get_post_type( $post_id );

		if ( in_array( $post_type, [ 'post', 'product' ], true ) ) {
			$this->invalidate();
		}
	}

	public function invalidate( int $post_id = 0 ): void {
		$version = max( 1, (int) get_option( self::CACHE_VERSION_OPTION, 1 ) );
		update_option( self::CACHE_VERSION_OPTION, $version + 1, false );
	}

	public function results( string $query, int $limit = 6, string $scope = 'all', string $prefix = '' ): array {
		$query = trim( sanitize_text_field( $query ) );
		$query = function_exists( 'mb_substr' ) ? mb_substr( $query, 0, 80 ) : substr( $query, 0, 80 );
		$limit = max( 1, min( 12, $limit ) );
		$prefix = strtoupper( preg_replace( '/[^A-Z]/i', '', $prefix ) ?? '' );
		$prefix = function_exists( 'mb_substr' ) ? mb_substr( $prefix, 0, 3 ) : substr( $prefix, 0, 3 );
		$scope = $this->allowed_scope( $scope );
		$key   = $this->cache_key( $query, $limit, $scope, $prefix );
		$found = false;
		$data  = wp_cache_get( $key, self::CACHE_GROUP, false, $found );

		if ( $found && is_array( $data ) ) {
			$data['cached'] = true;
			$this->record_search( $query, $scope, $prefix, $data );
			return $data;
		}

		$families     = $this->families();
		$has_commerce = function_exists( 'wc_get_product' ) && post_type_exists( 'product' );
		$products     = $has_commerce && $families['products'] && in_array( $scope, [ 'all', 'products' ], true ) ? $this->product_results( $query, $limit, $prefix ) : [];
		$posts        = $families['posts'] && in_array( $scope, [ 'all', 'posts' ], true ) ? $this->post_results( $query, $limit, $prefix ) : [];
		$authors      = $families['authors'] && in_array( $scope, [ 'all', 'authors' ], true ) ? $this->author_results( $query, $limit, $prefix ) : [];
		$categories   = $families['categories'] && in_array( $scope, [ 'all', 'categories' ], true ) ? $this->category_results( $query, $limit, $prefix ) : [];
		$data         = [
			'query'       => $query,
			'scope'       => $scope,
			'prefix'      => $prefix,
			'families'    => $families,
			'alphabetEnabled' => ! empty( $this->settings->get( 'search', [] )['alphabet_enabled'] ),
			'alphabet'    => '' === $query ? $this->alphabet( $scope, $prefix, $has_commerce ) : [],
			'hasCommerce' => $has_commerce,
			'products'    => $products,
			'posts'       => $posts,
			'authors'     => $authors,
			'categories'  => $categories,
			'total'       => count( $products ) + count( $posts ) + count( $authors ) + count( $categories ),
			'cached'      => false,
		];

		wp_cache_set( $key, $data, self::CACHE_GROUP, self::CACHE_TTL );
		$this->record_search( $query, $scope, $prefix, $data );

		return $data;
	}

	private function product_results( string $query, int $limit, string $prefix = '' ): array {
		$args = $this->query_args( 'product', $query, $limit, $prefix );

		if ( function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			$terms = wc_get_product_visibility_term_ids();
			$exclude = array_filter(
				[
					absint( $terms['exclude-from-search'] ?? 0 ),
					absint( $terms['exclude-from-catalog'] ?? 0 ),
				]
			);

			if ( $exclude ) {
				$args['tax_query'] = [
					[
						'taxonomy' => 'product_visibility',
						'field'    => 'term_id',
						'terms'    => $exclude,
						'operator' => 'NOT IN',
					],
				];
			}
		}

		$ids = ( new WP_Query( $args ) )->posts;
		$out = [];

		foreach ( $ids as $id ) {
			$product = wc_get_product( (int) $id );

			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}

			$title    = wp_strip_all_tags( $product->get_name() );
			$excerpt  = $this->excerpt( (int) $id );
			$image_id = (int) $product->get_image_id();
			$out[] = [
				'id'          => (int) $id,
				'type'        => 'product',
				'typeLabel'   => __( 'Product', 'dsa' ),
				'title'       => $title,
				'titleHtml'   => $this->highlight( $title, $query ),
				'excerpt'     => $excerpt,
				'excerptHtml' => $this->highlight( $excerpt, $query ),
				'url'         => esc_url_raw( get_permalink( (int) $id ) ),
				'image'       => $image_id ? esc_url_raw( (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) ) : '',
				'price'       => $this->product_price( $product ),
				'weight'      => $this->product_weight( $product ),
				'stockBadge'  => $this->cartPayload->stock_badge( $product ),
				'addable'     => $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock(),
			];
		}

		return $out;
	}

	private function category_results( string $query, int $limit, string $prefix = '' ): array {
		$taxonomies = $this->custom_taxonomies();

		if ( ! $taxonomies ) {
			return [];
		}

		$args = [
			'taxonomy'   => $taxonomies,
			'hide_empty' => true,
			'number'     => $limit,
			'orderby'    => '' === $query && '' === $prefix ? 'count' : 'name',
			'order'      => '' === $query && '' === $prefix ? 'DESC' : 'ASC',
		];

		if ( '' !== $query ) {
			$args['search'] = $query;
		}

		if ( '' !== $prefix ) {
			$args['name__like'] = $prefix;
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		$out = [];
		foreach ( $terms as $term ) {
			if ( count( $out ) >= $limit || ! is_object( $term ) ) {
				break;
			}

			$name = wp_strip_all_tags( (string) ( $term->name ?? '' ) );
			$url  = get_term_link( $term );
			if ( '' === $name || is_wp_error( $url ) ) {
				continue;
			}

			$taxonomy = get_taxonomy( (string) ( $term->taxonomy ?? '' ) );
			$label    = $taxonomy ? (string) $taxonomy->labels->singular_name : __( 'Category', 'dsa' );
			$count    = absint( $term->count ?? 0 );
			$out[] = [
				'id'          => (int) ( $term->term_id ?? 0 ),
				'type'        => 'category',
				'typeLabel'   => $label,
				'title'       => $name,
				'titleHtml'   => $this->highlight( $name, $query ),
				'excerpt'     => sprintf(
					/* translators: %s: item count. */
					_n( '%s item', '%s items', $count, 'dsa' ),
					number_format_i18n( $count )
				),
				'excerptHtml' => sprintf(
					/* translators: %s: item count. */
					_n( '%s item', '%s items', $count, 'dsa' ),
					number_format_i18n( $count )
				),
				'url'         => esc_url_raw( (string) $url ),
				'image'       => '',
			];
		}

		return $out;
	}

	private function post_results( string $query, int $limit, string $prefix = '' ): array {
		$ids = ( new WP_Query( $this->query_args( 'post', $query, $limit, $prefix ) ) )->posts;
		$out = [];

		foreach ( $ids as $id ) {
			$title   = wp_strip_all_tags( get_the_title( (int) $id ) );
			$excerpt = $this->excerpt( (int) $id );
			$out[] = [
				'id'          => (int) $id,
				'type'        => 'post',
				'typeLabel'   => __( 'Post', 'dsa' ),
				'title'       => $title,
				'titleHtml'   => $this->highlight( $title, $query ),
				'excerpt'     => $excerpt,
				'excerptHtml' => $this->highlight( $excerpt, $query ),
				'url'         => esc_url_raw( get_permalink( (int) $id ) ),
				'image'       => esc_url_raw( (string) get_the_post_thumbnail_url( (int) $id, 'medium' ) ),
			];
		}

		return $out;
	}

	private function author_results( string $query, int $limit, string $prefix = '' ): array {
		$args = [
			'number'              => $limit,
			'orderby'             => 'display_name',
			'order'               => 'ASC',
			'has_published_posts' => [ 'post' ],
			'fields'              => 'all',
		];

		if ( '' !== $query || '' !== $prefix ) {
			$args['search'] = ( '' !== $prefix ? $prefix : '*' . $query ) . '*';
			$args['search_columns'] = [ 'display_name', 'user_nicename' ];
		}

		$users = get_users( $args );
		$out   = [];

		foreach ( $users as $user ) {
			$name = wp_strip_all_tags( (string) $user->display_name );
			$bio  = wp_trim_words( wp_strip_all_tags( (string) $user->description ), 18, '...' );
			$out[] = [
				'id'          => (int) $user->ID,
				'type'        => 'author',
				'typeLabel'   => __( 'Author', 'dsa' ),
				'title'       => $name,
				'titleHtml'   => $this->highlight( $name, $query ),
				'excerpt'     => $bio,
				'excerptHtml' => $this->highlight( $bio, $query ),
				'url'         => esc_url_raw( get_author_posts_url( (int) $user->ID ) ),
				'image'       => esc_url_raw( (string) get_avatar_url( (int) $user->ID, [ 'size' => 160 ] ) ),
			];
		}

		return $out;
	}

	private function query_args( string $post_type, string $query, int $limit, string $prefix = '' ): array {
		$args = [
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => $query ? 'relevance' : 'date',
			'order'                  => 'DESC',
		];

		if ( '' !== $query ) {
			$args['s'] = $query;
		}

		if ( '' !== $prefix ) {
			$prefix_ids       = $this->prefix_post_ids( $post_type, $prefix, $limit );
			$args['post__in'] = $prefix_ids ?: [ 0 ];
			$args['orderby']  = 'post__in';
		}

		return $args;
	}

	private function excerpt( int $post_id ): string {
		$text = get_the_excerpt( $post_id );

		if ( '' === trim( (string) $text ) ) {
			$text = get_post_field( 'post_content', $post_id );
		}

		return wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), 20, '...' );
	}

	private function highlight( string $text, string $query ): string {
		$safe = esc_html( $text );

		if ( '' === $query ) {
			return $safe;
		}

		$pattern = '/(' . preg_quote( esc_html( $query ), '/' ) . ')/iu';
		$marked  = preg_replace( $pattern, '<mark>$1</mark>', $safe );

		return is_string( $marked ) ? wp_kses( $marked, [ 'mark' => [] ] ) : $safe;
	}

	private function product_weight( $product ): string {
		$weight = is_object( $product ) && method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '';

		return '' !== $weight && function_exists( 'wc_format_weight' ) ? $this->plain_text( wc_format_weight( $weight ) ) : '';
	}

	private function product_price( $product ): string {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_price' ) || '' === (string) $product->get_price() || ! function_exists( 'wc_price' ) ) {
			return '';
		}

		$amount = function_exists( 'wc_get_price_to_display' ) ? wc_get_price_to_display( $product ) : (float) $product->get_price();
		return $this->plain_text( wc_price( $amount ) );
	}

	private function plain_text( string $html ): string {
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, $charset );
	}

	private function families(): array {
		$defaults = $this->settings->defaults()['search']['families'];
		$config   = $this->settings->get( 'search', [] );
		$families = is_array( $config['families'] ?? null ) ? $config['families'] : [];
		$families = wp_parse_args( $families, $defaults );

		return [
			'products' => ! empty( $families['products'] ),
			'posts'    => ! empty( $families['posts'] ),
			'authors'  => ! empty( $families['authors'] ),
			'categories' => ! empty( $this->custom_taxonomies() ),
		];
	}

	private function allowed_scope( string $scope ): string {
		$scope    = in_array( $scope, [ 'products', 'posts', 'authors', 'categories' ], true ) ? $scope : 'all';
		$families = $this->families();

		return 'all' !== $scope && empty( $families[ $scope ] ) ? 'all' : $scope;
	}

	private function custom_taxonomies(): array {
		$config = $this->settings->get( 'search', [] );

		return array_values(
			array_filter(
				array_map( 'sanitize_key', (array) ( $config['custom_taxonomies'] ?? [] ) ),
				static function ( string $taxonomy ): bool {
					if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
						return false;
					}
					$object = get_taxonomy( $taxonomy );
					return $object && ! empty( $object->public );
				}
			)
		);
	}

	private function prefix_post_ids( string $post_type, string $prefix, int $limit ): array {
		global $wpdb;

		$like = $wpdb->esc_like( $prefix ) . '%';
		return array_map(
			'absint',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title LIKE %s ORDER BY post_title ASC LIMIT %d",
					$post_type,
					$like,
					$limit
				)
			)
		);
	}

	private function alphabet( string $scope, string $prefix, bool $has_commerce ): array {
		$config = $this->settings->get( 'search', [] );
		if ( empty( $config['alphabet_enabled'] ) ) {
			return [];
		}

		$families = $this->families();
		$tokens   = [];
		if ( $has_commerce && $families['products'] && in_array( $scope, [ 'all', 'products' ], true ) ) {
			$tokens = array_merge( $tokens, $this->post_alphabet( 'product', $prefix ) );
		}
		if ( $families['posts'] && in_array( $scope, [ 'all', 'posts' ], true ) ) {
			$tokens = array_merge( $tokens, $this->post_alphabet( 'post', $prefix ) );
		}
		if ( $families['authors'] && in_array( $scope, [ 'all', 'authors' ], true ) ) {
			$tokens = array_merge( $tokens, $this->author_alphabet( $prefix ) );
		}
		if ( $families['categories'] && in_array( $scope, [ 'all', 'categories' ], true ) ) {
			$tokens = array_merge( $tokens, $this->term_alphabet( $prefix ) );
		}

		$tokens = array_values( array_unique( array_filter( $tokens, static fn( $token ): bool => is_string( $token ) && 1 === preg_match( '/^[A-Z]+$/', $token ) ) ) );
		sort( $tokens, SORT_NATURAL | SORT_FLAG_CASE );
		return array_map( static fn( string $token ): string => ucfirst( strtolower( $token ) ), $tokens );
	}

	private function post_alphabet( string $post_type, string $prefix ): array {
		global $wpdb;

		$length = strlen( $prefix ) + 1;
		$like   = $wpdb->esc_like( $prefix ) . '%';
		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT UPPER(SUBSTRING(post_title, 1, %d)) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title LIKE %s AND CHAR_LENGTH(post_title) >= %d ORDER BY 1 ASC LIMIT 36",
				$length,
				$post_type,
				$like,
				$length
			)
		);
	}

	private function author_alphabet( string $prefix ): array {
		global $wpdb;

		$length = strlen( $prefix ) + 1;
		$like   = $wpdb->esc_like( $prefix ) . '%';
		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT UPPER(SUBSTRING(u.display_name, 1, %d)) FROM {$wpdb->users} u INNER JOIN {$wpdb->posts} p ON p.post_author = u.ID AND p.post_type = 'post' AND p.post_status = 'publish' WHERE u.display_name LIKE %s AND CHAR_LENGTH(u.display_name) >= %d ORDER BY 1 ASC LIMIT 36",
				$length,
				$like,
				$length
			)
		);
	}

	private function term_alphabet( string $prefix ): array {
		global $wpdb;

		$taxonomies = $this->custom_taxonomies();
		if ( ! $taxonomies ) {
			return [];
		}

		$length = strlen( $prefix ) + 1;
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT UPPER(SUBSTRING(t.name, 1, %d)) FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ($placeholders) AND tt.count > 0 AND t.name LIKE %s AND CHAR_LENGTH(t.name) >= %d ORDER BY 1 ASC LIMIT 36",
				...array_merge( [ $length ], $taxonomies, [ $like, $length ] )
			)
		);
	}

	private function record_search( string $query, string $scope, string $prefix, array $data ): void {
		if ( '' === $query && '' === $prefix ) {
			return;
		}
		do_action(
			'dsa_search_performed',
			[
				'query'  => $query,
				'prefix' => $prefix,
				'scope'  => $scope,
				'total'  => absint( $data['total'] ?? 0 ),
			]
		);
	}

	private function cache_key( string $query, int $limit, string $scope, string $prefix ): string {
		$version = max( 1, (int) get_option( self::CACHE_VERSION_OPTION, 1 ) );
		$user    = wp_get_current_user();
		$role    = $user && $user->exists()
			? 'user:' . (int) $user->ID . ':' . implode( ',', array_map( 'sanitize_key', (array) $user->roles ) )
			: 'public';
		$locale   = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$location = '';

		if ( function_exists( 'WC' ) && WC() && WC()->customer ) {
			$location = implode(
				':',
				[
					(string) WC()->customer->get_billing_country(),
					(string) WC()->customer->get_billing_state(),
					(string) WC()->customer->get_billing_postcode(),
				]
			);
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$plugin_version = defined( 'DSA_VERSION' ) ? DSA_VERSION : 'dev';
		$families = $this->families();
		$source   = implode( '|', [ $plugin_version, $version, $role, $locale, $currency, $location, $limit, $scope, $prefix, strtolower( $query ), implode( ',', array_keys( array_filter( $families ) ) ), implode( ',', $this->custom_taxonomies() ), function_exists( 'wc_get_product' ) ? 'woo' : 'wp' ] );

		return 'dsa_search_' . md5( $source );
	}
}
