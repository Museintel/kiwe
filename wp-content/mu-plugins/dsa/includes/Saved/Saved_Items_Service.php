<?php

namespace DSA\Saved;

use DSA\Commerce\Store_Analytics_Service;
use DSA\Diagnostics\Runtime_Profiler;
use DSA\Utilities\Content_Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Saved_Items_Service {
	private const META_KEY = 'dsa_saved_items';

	public function __construct( private Store_Analytics_Service|\Closure|null $analytics = null ) {}

	public function register(): void {
		// Cheap registration; journal/report code is loaded only for relevant work.
		foreach ( [ 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ] as $hook ) {
			add_action( $hook, static function( $meta_id, $user_id, $key ): void {
				if ( $key === self::META_KEY ) Saved_Report_Journal::mark_user( (int) $user_id );
			}, 10, 3 );
		}
		add_action( 'deleted_user', static fn( $id ) => Saved_Report_Journal::mark_user( (int) $id ) );
		add_action( 'wp_after_insert_post', static fn( $id ) => Saved_Report_Journal::mark_post( (int) $id ) );
		add_action( 'deleted_post', static fn( $id ) => Saved_Report_Journal::mark_post( (int) $id ) );
		add_action( 'dsa_saved_report_step_v2', [ Saved_Report_Index::class, 'scheduled_step' ], 10, 2 );
	}

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
		$user_id = get_current_user_id();
		if ( ! $user_id ) return $this->failure( 401 );
		$item = $this->normalize_item( $raw_item );
		if ( empty( $item['key'] ) ) {
			return [ 'ok' => false, 'message' => __( 'This item could not be saved.', 'dsa' ), 'items' => $this->current_items() ];
		}

		$adding = 'remove' !== sanitize_key( $action );
		$claim = Saved_DB_Session::claim( 'user:' . $user_id );
		if ( empty( $claim['owner'] ) ) return $this->failure( (int) $claim['status'] );
		$owner = $claim['owner'];
		$profile = Runtime_Profiler::start();
		try {
			for ( $attempt = 0; $attempt < 4; ++$attempt ) {
				$before = Saved_Items_State::read( $user_id );
				if ( ! $before ) return $this->failure( 503 );
				// Preserve dormant wishlist rows byte-for-byte during Core edits.
				$items = array_values( array_filter( $before['items'], static fn( $existing ): bool => ! is_array( $existing ) || self::item_key( $existing ) !== (string) $item['key'] ) );
				if ( $adding ) array_unshift( $items, $item );
				if ( ! \DSA\Apps\Store_App_Service::enabled() && count( $items ) > 100 ) return [ 'ok'=>false, 'message'=>__( 'Your saved list is full. Remove an item before saving another.', 'dsa' ), 'items'=>$this->current_items() ];
				$result = Saved_Items_State::write( $user_id, $before, array_slice( $items, 0, 100 ), $owner );
				if ( $result['status'] === 200 ) break;
				if ( $result['status'] !== 409 || ! $owner->owns() ) return $this->failure( 503 );
			}
			if ( $result['status'] !== 200 ) return $this->failure( 409 );
			$items = $result['items'];
		} finally {
			$owner->release();
			Runtime_Profiler::finish( 'saved_items.meta_write', $profile );
		}
		delete_transient( 'dsa_saved_admin_snapshot_v1' );
		delete_transient( 'dsa_saved_admin_snapshot_v1_core' );

		if ( $this->analytics instanceof \Closure ) $this->analytics = ( $this->analytics )();
		$this->analytics?->record_cart_event(
			[
				'event_type'  => sanitize_key( $item['type'] . '_' . ( $adding ? 'add' : 'remove' ) ),
				'source'      => 'dsa_saved',
				'product_id'  => 'wishlist' === $item['type'] ? absint( $item['id'] ) : 0,
				'context'     => $item['type'],
				'object_title' => $item['title'],
			]
		);

		return [ 'ok' => true, 'action' => $adding ? 'add' : 'remove', 'item' => $item, 'items' => array_values( array_filter( array_map( [ $this, 'normalize_item' ], $items ) ) ) ];
	}

	private function failure( int $status ): array {
		$message = $status === 401 ? __( 'Sign in before saving bookmarks or wishlist items.', 'dsa' ) : ( $status === 409 ? __( 'Your saved list is being updated. Please try again.', 'dsa' ) : __( 'Your saved list could not be updated. Please try again.', 'dsa' ) );
		return [ 'ok'=>false, 'status'=>$status, 'code'=>$status === 401 ? 'dsa_saved_items_login_required' : ( $status === 409 ? 'dsa_saved_items_conflict' : 'dsa_saved_items_unavailable' ), 'message'=>$message, 'items'=>$this->current_items() ];
	}

	public function admin_snapshot(): array {
		return ( new Saved_Report_Index() )->snapshot();
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
		$store = \DSA\Apps\Store_App_Service::enabled();
		if ( !$store && ( 'wishlist' === $type || ( $id && in_array( get_post_type( $id ), [ 'product','product_variation' ], true ) ) ) ) return [];
		if ( $id && ! Content_Visibility::can_read( $id ) ) return [];
		$type = 'wishlist' === $type && $store ? 'wishlist' : 'bookmark';
		$product = $store && $id && ( 'wishlist' === $type || in_array( get_post_type( $id ), [ 'product','product_variation' ], true ) ) && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

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
		$key   = self::item_key( [ 'id'=>$id, 'type'=>$type, 'url'=>$url ] );
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

	public static function item_key( array $item ): string {
		$id=absint( $item['id'] ?? 0 ); $type='wishlist' === ( $item['type'] ?? '' ) ? 'wishlist' : 'bookmark';
		return $id ? $type . ':' . $id : $type . ':' . md5( esc_url_raw( (string) ( $item['url'] ?? '' ) ) );
	}

	private static function clean_display_text( string $value ): string {
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}
}
