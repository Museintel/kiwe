<?php

namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** A recovered Store remains readable until its destination is separately enrolled. */
final class Store_Recovery_Gate_Service {
	public const OPTION = 'kiwe_restored_store_v1';
	public const ERROR = 'kiwe_store_recovery_paused';
	private const MESSAGE = 'This restored Store is paused. Native products, orders and history remain available for review. Destination payment, delivery and consent enrollment must be reviewed before Store operations resume.';
	private static ?bool $paused = null;
	private static ?array $active_plugins = null;
	private static bool $registered = false;

	private static function woo_available(): bool {
		if ( defined( 'WC_VERSION' ) || class_exists( 'WooCommerce', false ) ) return true;
		if ( self::$active_plugins === null ) {
			$plugins = get_option( 'active_plugins', [] );
			self::$active_plugins = is_array( $plugins ) ? $plugins : [];
		}
		return in_array( 'woocommerce/woocommerce.php', self::$active_plugins, true );
	}

	/** Missing means an ordinary site; every present marker fails closed, including paused=false. */
	public static function is_paused(): bool {
		if ( ! self::woo_available() ) return false;
		if ( self::$paused === null ) {
			$missing = new \stdClass();
			self::$paused = get_option( self::OPTION, $missing ) !== $missing;
		}
		return self::$paused;
	}

	public static function reason(): string { return self::is_paused() ? self::MESSAGE : ''; }
	public static function projection(): array {
		$paused = self::is_paused();
		return [ 'paused'=>$paused, 'readOnly'=>$paused, 'code'=>$paused ? self::ERROR : '', 'reason'=>$paused ? self::MESSAGE : '' ];
	}
	public static function error(): \WP_Error { return new \WP_Error( self::ERROR, self::MESSAGE, [ 'status'=>409 ] ); }

	/** Called from the MU entry, before normal plugin files or Woo initialization. */
	public static function register_early(): void {
		if ( self::$registered || ! self::is_paused() ) return;
		self::$registered = true;
		add_filter( 'woocommerce_session_handler', [ self::class, 'guard_session_handler' ], PHP_INT_MAX );
		foreach ( [ 'woocommerce_available_payment_gateways', 'woocommerce_valid_order_statuses_for_payment', 'woocommerce_valid_order_statuses_for_payment_complete' ] as $hook ) add_filter( $hook, [ self::class, 'empty_array' ], PHP_INT_MAX );
		foreach ( [ 'woocommerce_is_purchasable', 'woocommerce_variation_is_purchasable', 'woocommerce_add_to_cart_validation', 'woocommerce_update_cart_validation', 'woocommerce_coupon_is_valid', 'woocommerce_order_needs_payment', 'woocommerce_can_reduce_order_stock', 'woocommerce_can_restore_order_stock', 'action_scheduler_allow_async_request_runner', 'woocommerce_allow_send_queued_transactional_email' ] as $hook ) add_filter( $hook, [ self::class, 'deny_boolean' ], PHP_INT_MAX );
		add_filter( 'action_scheduler_queue_runner_concurrent_batches', [ self::class, 'zero' ], PHP_INT_MAX );
		add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', [ self::class, 'prevent_stock_adjustment' ], PHP_INT_MAX );
		foreach ( [ 'order', 'order_refund', 'order_item', 'product' ] as $type ) add_filter( 'woocommerce_pre_delete_'.$type, [ self::class, 'deny_boolean' ], PHP_INT_MIN );
		add_filter( 'rest_pre_dispatch', [ self::class, 'guard_rest' ], PHP_INT_MAX, 3 );
		add_action( 'woocommerce_after_checkout_validation', [ self::class, 'validate_checkout' ], PHP_INT_MAX, 2 );
		foreach ( [ 'woocommerce_before_pay_action', 'woocommerce_pre_payment_complete', 'woocommerce_before_checkout_process', 'woocommerce_checkout_create_order', 'woocommerce_store_api_checkout_update_order_from_request', 'woocommerce_before_order_object_save', 'woocommerce_before_order_refund_object_save', 'woocommerce_before_order_item_object_save', 'woocommerce_before_product_object_save', 'woocommerce_before_product_variation_object_save', 'woocommerce_before_delete_order', 'woocommerce_before_delete_order_refund', 'woocommerce_before_delete_order_item', 'woocommerce_before_trash_order', 'woocommerce_product_before_set_stock', 'woocommerce_variation_before_set_stock', 'woocommerce_remove_cart_item', 'woocommerce_before_cart_emptied', 'action_scheduler_before_execute' ] as $hook ) add_action( $hook, [ self::class, 'deny_mutation' ], PHP_INT_MIN, 0 );
		// Native CPT forms can bypass WC_Data::save(). Stop their writes before WordPress SQL.
		add_filter( 'wp_insert_post_data', [ self::class, 'guard_post_write' ], PHP_INT_MIN, 2 );
		add_filter( 'pre_delete_post', [ self::class, 'guard_post_delete' ], PHP_INT_MIN, 2 );
		add_filter( 'pre_trash_post', [ self::class, 'guard_post_delete' ], PHP_INT_MIN, 2 );
		// Woo supplies this data before wp_insert_comment(); core has no pre-insert data hook there.
		add_filter( 'woocommerce_new_order_note_data', [ self::class, 'guard_order_note' ], PHP_INT_MIN, 1 );
		// Both WC_Customer_Download::save() and legacy create_from_data() use this before SQL.
		add_filter( 'woocommerce_downloadable_file_permission_data', [ self::class, 'deny_mutation' ], PHP_INT_MIN, 1 );
		foreach ( [ 'grant_access_to_download', 'revoke_access_to_download' ] as $action ) add_action( 'wp_ajax_woocommerce_'.$action, [ self::class, 'deny_mutation' ], PHP_INT_MIN, 0 );
		add_action( 'parse_request', [ self::class, 'guard_legacy_callback' ], PHP_INT_MIN, 1 );
		foreach ( [ 'checkout', 'add_to_cart', 'apply_coupon', 'remove_coupon', 'update_shipping_method', 'update_order_review' ] as $action ) add_action( 'wc_ajax_'.$action, [ self::class, 'deny_mutation' ], PHP_INT_MIN, 0 );
		foreach ( [ 'refund_line_items', 'reduce_order_item_stock', 'increase_order_item_stock', 'add_order_item', 'add_order_fee', 'add_order_shipping', 'remove_order_item', 'save_order_items', 'calc_line_taxes', 'add_order_note', 'delete_order_note', 'delete_refund', 'remove_order_coupon', 'add_order_coupon' ] as $action ) add_action( 'wp_ajax_woocommerce_'.$action, [ self::class, 'deny_mutation' ], PHP_INT_MIN, 0 );
		add_filter( 'woocommerce_email_classes', [ self::class, 'guard_email_classes' ], PHP_INT_MAX );
		self::guard_email_ids( [ 'new_order', 'cancelled_order', 'failed_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_refunded_order', 'customer_partially_refunded_order', 'customer_fully_refunded_order', 'customer_note', 'customer_invoice', 'customer_new_account', 'customer_reset_password', 'low_stock', 'no_stock', 'backorder' ] );
		add_action( 'admin_notices', [ self::class, 'admin_notice' ] );
	}

	public static function empty_array( $value ): array { return []; }
	public static function deny_boolean( $value ): bool { return false; }
	public static function prevent_stock_adjustment( $value ): bool { return true; }
	public static function zero( $value ): int { return 0; }
	/** Woo loads this native-compatible session only for the paused recovery context. */
	public static function guard_session_handler( $handler ): string {
		if ( ! in_array( $handler, [ 'WC_Session_Handler', Store_Recovery_Session::class ], true ) || ! class_exists( '\\WC_Session_Handler' ) ) self::deny_mutation();
		require_once __DIR__.'/Store_Recovery_Session.php';
		return Store_Recovery_Session::class;
	}
	public static function deny_mutation(): void { throw new \RuntimeException( self::MESSAGE ); }
	public static function validate_checkout( $data, $errors ): void {
		if ( $errors instanceof \WP_Error ) $errors->add( self::ERROR, self::MESSAGE );
		else self::deny_mutation();
	}

	/** Store API checkout GET can create an order; ordinary catalog/order GET remains readable. */
	public static function guard_rest( $result, $server, $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || ! method_exists( $request, 'get_method' ) ) return $result;
		$route = $request->get_route();
		if ( ! is_string( $route ) || ! preg_match( '~^/(?:wc|wc-admin|wc-analytics|dsa/v1/(?:workspace/commerce|cart|checkout))(?:/|$)~iD', $route ) ) return $result;
		$method = strtoupper( (string) $request->get_method() );
		$checkout = (bool) preg_match( '~^/(?:wc/store/v[0-9]+/checkout|dsa/v1/checkout)(?:/|$)~iD', $route );
		if ( ! in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true ) || ( $checkout && $method !== 'OPTIONS' ) ) return self::error();
		return $result;
	}

	public static function guard_post_write( $data, $postarr ) {
		if ( is_array( $data ) && in_array( $data['post_type'] ?? '', [ 'product', 'product_variation', 'shop_order', 'shop_order_refund' ], true ) ) self::deny_mutation();
		return $data;
	}
	public static function guard_post_delete( $delete, $post ) {
		if ( is_object( $post ) && in_array( $post->post_type ?? '', [ 'product', 'product_variation', 'shop_order', 'shop_order_refund' ], true ) ) return false;
		return $delete;
	}
	public static function guard_order_note( $data ) {
		if ( is_array( $data ) && ( $data['comment_type'] ?? '' ) === 'order_note' ) self::deny_mutation();
		return $data;
	}
	public static function guard_legacy_callback( $wp ): void {
		$api = is_object( $wp ) && isset( $wp->query_vars['wc-api'] ) ? $wp->query_vars['wc-api'] : ( $_GET['wc-api'] ?? null );
		if ( $api !== null ) self::deny_mutation();
	}

	private static function guard_email_ids( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $id ) ) continue;
			add_filter( 'woocommerce_email_enabled_'.$id, [ self::class, 'deny_boolean' ], PHP_INT_MAX );
			// Manually triggered native invoices can bypass is_enabled(). Empty recipients stop delivery too.
			add_filter( 'woocommerce_email_recipient_'.$id, [ self::class, 'empty_recipient' ], PHP_INT_MAX );
		}
	}
	public static function empty_recipient( $recipient ): string { return ''; }
	public static function guard_email_classes( $emails ) {
		if ( is_array( $emails ) ) foreach ( $emails as $email ) if ( is_object( $email ) && isset( $email->id ) ) self::guard_email_ids( [ $email->id ] );
		return $emails;
	}
	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) return;
		echo '<div class="notice notice-warning"><p>'.esc_html( self::MESSAGE ).'</p></div>';
	}
}
