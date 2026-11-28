<?php

namespace DSA\Access;

use DSA\Apps\Store_App_Service;
use DSA\Link_Hub\Public_Subject_Service;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Guest_Referral_Service {
	private const PARAM = 'kiwe_ref';
	private const COOKIE = 'kiwe_guest_referral';
	private const ACCOUNT_META = '_kiwe_guest_referrer_';
	private const ORDER_META = '_kiwe_guest_referrer';
	private const PRODUCT_META = '_kiwe_guest_product_author_';
	private const ITEM_META = '_kiwe_guest_product_author';

	public static function register(): void {
		add_action( 'template_redirect', [ self::class, 'landing' ], 0 );
		add_action( 'user_register', [ self::class, 'attribute_registration' ] );
		if ( ! Store_App_Service::enabled() ) return;
		add_action( 'woocommerce_checkout_create_order', [ self::class, 'attribute_order' ], 90 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ self::class, 'attribute_order' ], 90 );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ self::class, 'legacy_order_query' ], 10, 2 );
	}

	private static function complete_meta_key(): string {
		return self::ACCOUNT_META . get_current_blog_id();
	}

	private static function signature( int $referrer, int $subject ): ?string {
		if ( ! Guest_Contribution_Service::can_refer( $referrer ) ) return null;
		$key = Guest_Contribution_Service::application( $referrer )['referralKey'] ?? '';
		if ( ! is_string( $key ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $key ) ) return null;
		return hash_hmac( 'sha256', get_current_blog_id() . ':' . $referrer . ':' . $subject . ':' . $key, wp_salt( 'auth' ) );
	}

	public static function url( int $referrer, array $subject ): ?string {
		$id = (int) ( $subject['id'] ?? 0 );
		$sig = self::signature( $referrer, $id );
		return $sig && ! empty( $subject['url'] )
			? add_query_arg( self::PARAM, $referrer . '.' . $id . '.' . $sig, home_url( '/' ) )
			: null;
	}

	private static function parse( $token ): ?array {
		if ( ! is_string( $token ) || ! preg_match( '/\A([1-9][0-9]{0,14})\.(0|[1-9][0-9]{0,14})\.([a-f0-9]{64})\z/', $token, $matches ) ) return null;
		$referrer = (int) $matches[1];
		$subject = (int) $matches[2];
		$sig = self::signature( $referrer, $subject );
		return $sig && hash_equals( $sig, $matches[3] ) ? [ $referrer, $subject ] : null;
	}

	private static function consent_cookie( string $token, int $expires ): string {
		$body = $token . ':' . $expires;
		return $body . ':' . hash_hmac( 'sha256', $body, wp_salt( 'nonce' ) );
	}

	private static function consented_referrer(): int {
		$value = $_COOKIE[ self::COOKIE ] ?? '';
		if ( ! is_string( $value ) || ! preg_match( '/\A([a-f0-9.]{1,100}):([0-9]{10}):[a-f0-9]{64}\z/', $value, $parts ) ) return 0;
		$expires = (int) $parts[2];
		if ( $expires <= time() || ! hash_equals( self::consent_cookie( $parts[1], $expires ), $value ) ) return 0;
		return self::parse( $parts[1] )[0] ?? 0;
	}

	public static function landing(): void {
		if ( ! isset( $_GET[ self::PARAM ] ) ) return;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		nocache_headers();
		header( 'X-Robots-Tag: noindex', true );
		header( 'Referrer-Policy: no-referrer', true );
		$token = $_GET[ self::PARAM ];
		[ $referrer, $subject ] = self::parse( $token ) ?? [ 0, 0 ];
		$url = Public_Subject_Service::resolve( $subject )['url'] ?: home_url( '/' );
		if ( ! $referrer || get_current_user_id() === $referrer || self::consented_referrer() ) {
			wp_safe_redirect( $url ); exit;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( ! Origin_Checker::is_same_site_request() ) wp_die( esc_html__( 'Open the referral on this website.', 'dsa' ), '', [ 'response'=>403 ] );
			check_admin_referer( 'kiwe_guest_referral_' . $token );
			if ( 'yes' === ( $_POST['credit'] ?? '' ) ) {
				$expires = time() + 30 * DAY_IN_SECONDS;
				setcookie( self::COOKIE, self::consent_cookie( $token, $expires ), [ 'expires'=>$expires, 'path'=>defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/', 'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax' ] );
			}
			wp_safe_redirect( $url, 303 ); exit;
		}
		$heading = __( 'Continue to the website', 'dsa' );
		$form = '<form method="post">' . wp_nonce_field( 'kiwe_guest_referral_' . $token, '_wpnonce', false, false )
			. '<button name="credit" value="yes">' . esc_html__( 'Continue and credit the referral', 'dsa' ) . '</button> '
			. '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Continue without referral credit', 'dsa' ) . '</a></form>';
		$description = __( 'If you choose to credit this referral, a cookie remembers it for 30 days. It can credit a new account and purchases to the person who shared the link.', 'dsa' );
		wp_die( '<p>' . esc_html( $description ) . '</p>' . $form, $heading, [ 'response'=>200 ] );
	}

	public static function attribute_registration( int $id ): void {
		$referrer = self::consented_referrer();
		if ( $referrer && $referrer !== $id ) add_user_meta( $id, self::complete_meta_key(), $referrer, true );
	}

	public static function attribute_order( $order ): void {
		if ( ! $order instanceof \WC_Order || $order->get_date_paid() ) return;
		self::attribute_products( $order );
		if ( $order->get_meta( self::ORDER_META ) ) return;
		$buyer = (int) $order->get_customer_id();
		$account_referrer = $buyer ? (int) get_user_meta( $buyer, self::complete_meta_key(), true ) : 0;
		$visit_referrer = self::consented_referrer();
		$referrer = $account_referrer && Guest_Contribution_Service::can_refer( $account_referrer ) ? $account_referrer : $visit_referrer;
		if ( ! $referrer || $referrer === $buyer || $referrer === get_current_user_id() ) return;
		$order->update_meta_data( self::ORDER_META, $referrer );
	}

	private static function attribute_products( \WC_Order $order ): void {
		foreach ( $order->get_meta_data() as $meta ) {
			$key = $meta->get_data()['key'];
			if ( str_starts_with( $key, self::PRODUCT_META ) ) $order->delete_meta_data( $key );
		}
		foreach ( $order->get_items() as $item ) {
			$item->delete_meta_data( self::ITEM_META );
			$id = $item->get_product_id();
			if ( ! Guest_Contribution_Service::is_submission( $id ) ) continue;
			$author = (int) get_post_field( 'post_author', $id );
			if ( ! $author ) continue;
			$item->update_meta_data( self::ITEM_META, $author );
			$order->update_meta_data( self::PRODUCT_META . $author, 1 );
		}
	}

	public static function legacy_order_query( array $native, array $arguments ): array {
		if ( isset( $arguments['kiwe_guest_referrer'] ) ) $native['meta_query'][] = self::order_meta_query( (int) $arguments['kiwe_guest_referrer'] );
		return $native;
	}

	private static function order_meta_query( int $referrer ): array {
		return [ 'relation'=>'OR', [ 'key'=>self::ORDER_META, 'value'=>$referrer ],
			[ 'key'=>self::PRODUCT_META . $referrer, 'value'=>1 ] ];
	}

	public static function report( int $referrer, int $page = 1 ): ?array {
		if ( ! current_user_can( 'kiwe_manage_guest_applications' ) || ! get_userdata( $referrer ) || ! Guest_Contribution_Service::application( $referrer ) ) return null;
		$accounts = new \WP_User_Query( [ 'meta_key'=>self::complete_meta_key(), 'meta_value'=>$referrer, 'fields'=>'ID', 'number'=>1, 'count_total'=>true ] );
		$view_orders = Store_App_Service::enabled() && current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_others_shop_orders' );
		$report = [ 'referrerId'=>$referrer, 'registeredAccounts'=>(int) $accounts->get_total(), 'ordersAvailable'=>$view_orders, 'orders'=>[], 'hasMore'=>false ];
		if ( ! $view_orders ) return $report;
		$args = [ 'limit'=>21, 'offset'=>( max( 1, min( 1000, $page ) ) - 1 ) * 20, 'order'=>'DESC', 'orderby'=>'date',
			'date_paid'=>'>0', 'status'=>array_merge( wc_get_is_paid_statuses(), [ 'refunded' ] ) ];
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$args['meta_query'] = [ self::order_meta_query( $referrer ) ];
		} else {
			$args['kiwe_guest_referrer'] = $referrer;
		}
		$orders = array_values( array_filter( wc_get_orders( $args ), static fn ( $order ) => current_user_can( 'edit_shop_order', $order->get_id() ) ) );
		$report['hasMore'] = count( $orders ) > 20;
		foreach ( array_slice( $orders, 0, 20 ) as $order ) {
			$referred = (int) $order->get_meta( self::ORDER_META ) === $referrer;
			$products = [];
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( (int) $item->get_meta( self::ITEM_META ) !== $referrer ) continue;
				$products[] = [ 'name'=>Task_Workspace_UI::text( $item->get_name(), 80 ), 'total'=>(string) $item->get_total(),
					'refunded'=>(string) abs( $order->get_total_refunded_for_item( $item_id ) ) ];
			}
			if ( ! $referred && ! $products ) continue;
			$report['orders'][] = [
				'id'=>(int) $order->get_id(), 'status'=>$order->get_status(), 'currency'=>$order->get_currency(),
				'referred'=>$referred, 'total'=>$referred ? (string) $order->get_total() : null,
				'refunded'=>$referred ? (string) $order->get_total_refunded() : null,
				'productLines'=>count( $products ), 'products'=>array_slice( $products, 0, 3 ),
			];
		}
		return $report;
	}
}
