<?php

namespace DSA\Saved;

use DSA\Commerce\Store_Analytics_Service;
use DSA\Diagnostics\Runtime_Profiler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Saved_Items_Service {
	private const META_KEY = 'dsa_saved_items';

	public function __construct( private Store_Analytics_Service $analytics ) {}

	public function current_items(): array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}

		$items = get_user_meta( $user_id, self::META_KEY, true );
		$items = is_array( $items ) ? $items : [];
		return array_values( array_filter( array_map( [ $this, 'normalize_item' ], $items ) ) );
	}

	public function mutate( string $action, array $raw_item ): array {
		$item = $this->normalize_item( $raw_item );
		if ( empty( $item['key'] ) ) {
			return [ 'ok' => false, 'message' => __( 'This item could not be saved.', 'dsa' ), 'items' => $this->current_items() ];
		}

		$items = array_values( array_filter( $this->current_items(), static fn( array $existing ): bool => (string) ( $existing['key'] ?? '' ) !== (string) $item['key'] ) );
		$adding = 'remove' !== sanitize_key( $action );
		if ( $adding ) {
			array_unshift( $items, $item );
		}
		$items = array_slice( $items, 0, 100 );

		$user_id = get_current_user_id();
		if ( $user_id ) {
			$profile = Runtime_Profiler::start();
			update_user_meta( $user_id, self::META_KEY, $items );
			Runtime_Profiler::finish( 'saved_items.meta_write', $profile );
			delete_transient( 'dsa_saved_admin_snapshot_v1' );
		}

		$this->analytics->record_cart_event(
			[
				'event_type'  => sanitize_key( $item['type'] . '_' . ( $adding ? 'add' : 'remove' ) ),
				'source'      => 'dsa_saved',
				'product_id'  => 'wishlist' === $item['type'] ? absint( $item['id'] ) : 0,
				'context'     => $item['type'],
				'object_title' => $item['title'],
			]
		);

		return [ 'ok' => true, 'action' => $adding ? 'add' : 'remove', 'item' => $item, 'items' => $items ];
	}

	public function admin_snapshot(): array {
		$cached = get_transient( 'dsa_saved_admin_snapshot_v1' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::META_KEY
			),
			ARRAY_A
		);
		$objects = [];
		$users   = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			$items   = maybe_unserialize( $row['meta_value'] ?? '' );
			if ( ! $user_id || ! is_array( $items ) ) continue;

			foreach ( $items as $raw_item ) {
				$item = $this->normalize_item( $raw_item );
				if ( empty( $item['key'] ) ) continue;
				$key = (string) $item['key'];
				if ( ! isset( $objects[ $key ] ) ) {
					$objects[ $key ] = [
						'key' => $key,
						'id' => absint( $item['id'] ?? 0 ),
						'type' => $item['type'],
						'title' => $item['title'],
						'url' => $item['url'],
						'users' => [],
					];
				}
				$objects[ $key ]['users'][ $user_id ] = true;
				$users[ $user_id ][ $item['type'] ] = ( $users[ $user_id ][ $item['type'] ] ?? 0 ) + 1;
				$users[ $user_id ]['latest'] = max( absint( $users[ $user_id ]['latest'] ?? 0 ), absint( $item['savedAt'] ?? 0 ) );
			}
		}

		$object_rows = array_values( array_map( static function ( array $item ): array {
			$item['user_count'] = count( $item['users'] );
			unset( $item['users'] );
			return $item;
		}, $objects ) );
		usort( $object_rows, static fn( array $a, array $b ): int => ( $b['user_count'] <=> $a['user_count'] ) ?: strcasecmp( $a['title'], $b['title'] ) );

		$user_rows = [];
		foreach ( $users as $user_id => $counts ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user ) continue;
			$user_rows[] = [
				'id' => (int) $user_id,
				'name' => $user->display_name ?: $user->user_login,
				'email' => $user->user_email,
				'wishlist' => absint( $counts['wishlist'] ?? 0 ),
				'bookmark' => absint( $counts['bookmark'] ?? 0 ),
				'latest' => absint( $counts['latest'] ?? 0 ),
			];
		}
		usort( $user_rows, static fn( array $a, array $b ): int => $b['latest'] <=> $a['latest'] );

		$snapshot = [
			'objects' => array_slice( $object_rows, 0, 250 ),
			'users' => array_slice( $user_rows, 0, 250 ),
			'totals' => [
				'users' => count( $users ),
				'wishlist_users' => count( array_filter( $users, static fn( array $counts ): bool => ! empty( $counts['wishlist'] ) ) ),
				'bookmark_users' => count( array_filter( $users, static fn( array $counts ): bool => ! empty( $counts['bookmark'] ) ) ),
				'objects' => count( $objects ),
			],
		];
		set_transient( 'dsa_saved_admin_snapshot_v1', $snapshot, 5 * MINUTE_IN_SECONDS );
		return $snapshot;
	}

	public function items_for_user( int $user_id ): array {
		if ( $user_id < 1 ) return [];
		$items = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $items ) ? array_values( array_filter( array_map( [ $this, 'normalize_item' ], $items ) ) ) : [];
	}

	public function normalize_item( $raw_item ): array {
		$raw_item = is_array( $raw_item ) ? $raw_item : [];
		$id       = absint( $raw_item['id'] ?? 0 );
		$type     = sanitize_key( (string) ( $raw_item['type'] ?? 'bookmark' ) );
		$type     = 'wishlist' === $type && function_exists( 'wc_get_product' ) ? 'wishlist' : 'bookmark';
		$product  = $id && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

		if ( $id && $product && is_object( $product ) ) {
			$image_id = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
			$raw_item['title'] = $product->get_name();
			$raw_item['url']   = $product->get_permalink();
			$raw_item['image'] = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
			$raw_item['kindLabel'] = 'wishlist' === $type ? __( 'Wishlist', 'dsa' ) : __( 'Bookmark', 'dsa' );
			$raw_item['price'] = '' !== (string) $product->get_price() ? self::clean_display_text( wp_strip_all_tags( wc_price( $product->get_price() ), true ) ) : '';
			$raw_item['weight'] = method_exists( $product, 'get_weight' ) && $product->get_weight() ? wc_format_weight( $product->get_weight() ) : '';
			$raw_item['stockLabel'] = $product->is_in_stock() ? __( 'In stock', 'dsa' ) : __( 'Out of stock', 'dsa' );
			if ( $product->is_in_stock() && $product->managing_stock() && null !== $product->get_stock_quantity() ) {
				$raw_item['stockLabel'] = sprintf( _n( '%d available', '%d available', (int) $product->get_stock_quantity(), 'dsa' ), (int) $product->get_stock_quantity() );
			}
			$raw_item['excerpt'] = wp_trim_words( self::clean_display_text( wp_strip_all_tags( (string) $product->get_short_description(), true ) ), 18 );
			$categories = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'names' ] );
			$raw_item['category'] = ! is_wp_error( $categories ) ? implode( ', ', array_slice( $categories, 0, 2 ) ) : '';
		} elseif ( $id && get_post( $id ) ) {
			$raw_item['title'] = get_the_title( $id );
			$raw_item['url']   = get_permalink( $id );
			$raw_item['image'] = get_the_post_thumbnail_url( $id, 'medium' );
			$post_type = get_post_type_object( get_post_type( $id ) );
			$raw_item['kindLabel'] = $post_type ? $post_type->labels->singular_name : __( 'Post', 'dsa' );
			$raw_item['excerpt'] = wp_trim_words( wp_strip_all_tags( (string) get_the_excerpt( $id ) ), 22 );
			$raw_item['date'] = get_the_date( '', $id );
			$taxonomies = get_object_taxonomies( get_post_type( $id ), 'names' );
			$categories = [];
			foreach ( (array) $taxonomies as $taxonomy ) {
				if ( ! is_taxonomy_hierarchical( $taxonomy ) ) continue;
				$names = wp_get_post_terms( $id, $taxonomy, [ 'fields' => 'names' ] );
				if ( ! is_wp_error( $names ) ) $categories = array_merge( $categories, (array) $names );
			}
			$raw_item['category'] = implode( ', ', array_slice( array_unique( $categories ), 0, 2 ) );
		}

		$title = sanitize_text_field( (string) ( $raw_item['title'] ?? '' ) );
		$url   = esc_url_raw( (string) ( $raw_item['url'] ?? '' ) );
		$key   = $id ? $type . ':' . $id : $type . ':' . md5( $url );
		if ( '' === $title || '' === $url ) {
			return [];
		}

		return [
			'key'     => sanitize_text_field( $key ),
			'id'      => $id,
			'type'    => $type,
			'title'   => $title,
			'url'     => $url,
			'image'   => esc_url_raw( (string) ( $raw_item['image'] ?? '' ) ),
			'kindLabel' => sanitize_text_field( (string) ( $raw_item['kindLabel'] ?? ( 'wishlist' === $type ? __( 'Product', 'dsa' ) : __( 'Bookmark', 'dsa' ) ) ) ),
			'price' => sanitize_text_field( (string) ( $raw_item['price'] ?? '' ) ),
			'weight' => sanitize_text_field( (string) ( $raw_item['weight'] ?? '' ) ),
			'stockLabel' => sanitize_text_field( (string) ( $raw_item['stockLabel'] ?? '' ) ),
			'category' => sanitize_text_field( (string) ( $raw_item['category'] ?? '' ) ),
			'excerpt' => sanitize_text_field( (string) ( $raw_item['excerpt'] ?? '' ) ),
			'date' => sanitize_text_field( (string) ( $raw_item['date'] ?? '' ) ),
			'savedAt' => absint( $raw_item['savedAt'] ?? time() ),
		];
	}

	private static function clean_display_text( string $value ): string {
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}
}
