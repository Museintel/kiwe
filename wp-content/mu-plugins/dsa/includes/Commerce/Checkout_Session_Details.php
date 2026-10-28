<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Best-effort legacy recovery on one private review, never a visitor scan or persisted copy. */
final class Checkout_Session_Details {
	public static function recover( array $row ): ?array {
		if ( ! current_user_can( 'edit_others_shop_orders' ) || empty( $row['checkout_details_at'] ) || ! in_array( $row['status'] ?? '',[ 'active','abandoned' ],true ) ) return null;
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_sessions';
		$keys = [];
		if ( ! empty( $row['user_id'] ) ) $keys = [ (string) (int) $row['user_id'] ];
		else {
			// At most five indexed pages of keys, without reading other shoppers' payloads.
			$cursor = PHP_INT_MAX; $started = microtime( true );
			for ( $page=0;$page<5;$page++ ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT session_id,session_key FROM {$table} WHERE session_id<%d ORDER BY session_id DESC LIMIT 100",$cursor ),ARRAY_A ) ?: [];
				foreach ( $rows as $candidate ) {
					$key = (string) $candidate['session_key'];
					if ( hash_equals( (string) ( $row['customer_hash'] ?? '' ),hash_hmac( 'sha256','wc:' . $key,wp_salt( 'auth' ) ) ) ) { $keys = [ $key ]; break 2; }
				}
				if ( count( $rows ) < 100 || microtime( true ) - $started > 0.1 ) break;
				$cursor = (int) end( $rows )['session_id'];
			}
		}
		if ( ! $keys ) return null;
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$table} WHERE session_key=%s AND session_expiry>%d LIMIT 1",$keys[0],time() ) );
		$session = self::unpack( $stored );
		if ( ! is_array( $session ) ) return null;
		$cart = self::unpack( $session['cart'] ?? null );
		$items = json_decode( (string) ( $row['items'] ?? '' ),true,12 );
		if ( ! is_array( $cart ) || ! is_array( $items ) || self::cart_key( $cart ) !== self::cart_key( $items ) || ! self::cart_key( $items ) ) return null;
		$draft = self::unpack( $session['dsa_checkout_draft'] ?? null );
		if ( ! is_array( $draft ) || ! $draft ) return null;
		$details = Checkout_Details_Model::envelope( $draft,'active-session',current_time( 'mysql' ) );
		return Checkout_Details_Model::meaningful( $details ) ? $details : null;
	}
	private static function unpack( $value ) {
		if ( is_array( $value ) ) return $value;
		if ( ! is_string( $value ) || strlen( $value ) > 262144 || ! is_serialized( $value ) ) return null;
		try { return @unserialize( $value,[ 'allowed_classes'=>false,'max_depth'=>16 ] ); } catch ( \Throwable $e ) { return null; }
	}
	private static function cart_key( array $cart ): string {
		if ( ! $cart || count( $cart ) > 100 ) return '';
		$items = [];
		foreach ( $cart as $line ) {
			if ( ! is_array( $line ) || ! is_numeric( $line['product_id'] ?? null ) || ! is_numeric( $line['quantity'] ?? null ) || (int) $line['quantity'] < 1 ) return '';
			$variation = $line['variation'] ?? [];
			if ( ! is_array( $variation ) || count( $variation ) > 32 || count( array_filter( $variation,'is_string' ) ) !== count( $variation ) ) return '';
			ksort( $variation );
			$items[] = wp_json_encode( [ (int) $line['product_id'],(int) ( $line['variation_id'] ?? 0 ),(int) $line['quantity'],$variation ] );
		}
		sort( $items,SORT_STRING );
		return hash( 'sha256',wp_json_encode( $items ) );
	}
}
