<?php

namespace DSA\Notifications;

use DSA\Communications\Channel_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Order_Notification_Service {
	private const RECEIVED_HOOK = 'kiwe_order_received_notice';
	private const RECEIVED_META = '_kiwe_received_notice_attempt_v1';
	private $preferences;
	private $channels;
	private $push;

	/** Native order events stay authoritative; this policy only narrows external delivery. */
	public static function delivery_policy(): array {
		$all = ( new \DSA\Settings() )->all();
		$stored = is_array( $all['order_notifications'] ?? null ) ? $all['order_notifications'] : [];
		$available = function_exists( 'wc_get_order_statuses' ) ? array_map( static fn( $key ) => preg_replace( '/^wc-/', '', $key ), array_keys( wc_get_order_statuses() ) ) : [ 'pending','processing','on-hold','completed','cancelled','failed','refunded' ];
		$available = array_values( array_diff( $available, [ 'checkout-draft','auto-draft','draft' ] ) );
		$statuses = array_key_exists( 'statuses', $stored ) && is_array( $stored['statuses'] ) ? array_values( array_intersect( $available, $stored['statuses'] ) ) : $available;
		$channels = [];
		foreach ( [ 'whatsapp','email','sms','app' ] as $channel ) if ( ! array_key_exists( $channel, $stored ) || true === $stored[$channel] ) $channels[] = $channel;
		return [ 'statuses'=>$statuses, 'channels'=>$channels ];
	}

	public function __construct( Notification_Preference_Service $preferences, Channel_Service $channels, Push_Service $push ) {
		$this->preferences = $preferences;
		$this->channels    = $channels;
		$this->push        = $push;
	}

	public function register(): void {
		if ( ! \DSA\Apps\Store_App_Service::enabled() ) return;
		add_action( 'woocommerce_order_status_changed', [ $this, 'notify_status_changed' ], 30, 4 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'queue_received' ], 30, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'queue_store_api_received' ], 30 );
		add_action( self::RECEIVED_HOOK, [ $this, 'notify_received' ] );
	}

	public function queue_store_api_received( $order ): void {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) $this->queue_received( $order->get_id(), [], $order );
	}

	/** Native checkout owns acceptance. Gateway delivery never holds checkout open. */
	public function queue_received( $order_id, $posted = [], $order = null ): void {
		$order_id = absint( $order_id );
		$order = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! $order || ! $order->get_user_id() || ! in_array( $order->get_status(), [ 'pending','processing','on-hold','completed' ], true ) || $order->get_meta( self::RECEIVED_META, true ) ) return;
		if ( function_exists( 'as_enqueue_async_action' ) ) as_enqueue_async_action( self::RECEIVED_HOOK, [ $order_id ], 'kiwe', true );
		elseif ( ! wp_next_scheduled( self::RECEIVED_HOOK, [ $order_id ] ) ) wp_schedule_single_event( time(), self::RECEIVED_HOOK, [ $order_id ] );
	}

	public function notify_received( $order_id ): void {
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! function_exists( 'wc_get_order' ) ) return;
		global $wpdb;
		$order_id = absint( $order_id ); if ( ! $order_id ) return;
		$lock = 'kiwe-received-' . substr( hash( 'sha256', $wpdb->prefix . '|' . $order_id ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) return;
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order || ! $order->get_user_id() || ! in_array( $order->get_status(), [ 'pending','processing','on-hold','completed' ], true ) ) return;
			$order->read_meta_data( true ); if ( $order->get_meta( self::RECEIVED_META, true ) ) return;
			// A crash/uncertain provider response must not silently replay a receipt.
			$attempt = wp_generate_uuid4(); $order->update_meta_data( self::RECEIVED_META, $attempt ); $order->save_meta_data(); $order->read_meta_data( true );
			if ( $order->get_meta( self::RECEIVED_META, true ) !== $attempt ) return;
			$number = sanitize_text_field( (string) $order->get_order_number() ); $policy = self::delivery_policy();
			do_action( 'kiwe_notification_event', 'order_status', [
				'id'=>'order-' . $order_id . '-received', 'userIds'=>[ absint( $order->get_user_id() ) ], 'objectId'=>$order_id,
				'title'=>sprintf( __( 'We received your order #%s', 'dsa' ), $number ),
				'message'=>sprintf( __( 'Your order #%1$s at %2$s was received. Current status: %3$s.', 'dsa' ), $number, get_bloginfo( 'name' ), wc_get_order_status_name( $order->get_status() ) ),
				'url'=>$order->get_view_order_url(), 'actionLabel'=>__( 'View your order', 'dsa' ),
				'deliveryChannels'=>in_array( $order->get_status(), $policy['statuses'], true ) ? $policy['channels'] : [],
				'inboxOnly'=>! method_exists( Admin_Event_Notification_Service::class, 'supports_delivery_policy' ),
			] );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	public function notify_status_changed( $order_id, $from, $to, $order = null ): void {
		$order_id = absint( $order_id );
		$order = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
		if ( ! $order || ! method_exists( $order, 'get_user_id' ) ) {
			return;
		}

		$user_id = absint( $order->get_user_id() );
		if ( ! $user_id || in_array( sanitize_key( (string) $to ), [ 'checkout-draft', 'auto-draft', 'draft' ], true ) ) {
			return;
		}

		$order_number = sanitize_text_field( (string) $order->get_order_number() );
		$status_label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $to ) : ucfirst( str_replace( '-', ' ', sanitize_key( (string) $to ) ) );
		$subject = sprintf( __( 'Order #%1$s is now %2$s', 'dsa' ), $order_number, $status_label );
		$url = method_exists( $order, 'get_view_order_url' ) ? $order->get_view_order_url() : home_url( '/' );
		$message = sprintf( __( 'Your order #%1$s at %2$s is now %3$s. View your order: %4$s', 'dsa' ), $order_number, get_bloginfo( 'name' ), $status_label, $url );
		$policy = self::delivery_policy();

		// One router owns both native inbox and explicitly chosen delivery methods.
		do_action( 'kiwe_notification_event', 'order_status', [
			'id'=>'order-' . $order_id . '-status-' . sanitize_key( (string) $from ) . '-' . sanitize_key( (string) $to ) . '-' . time(),
			'userIds'=>[ $user_id ],'objectId'=>$order_id,'title'=>$subject,'message'=>$message,
			'url'=>$url,'actionLabel'=>__( 'View your order', 'dsa' ),
			'deliveryChannels'=>in_array( sanitize_key( (string) $to ), $policy['statuses'], true ) ? $policy['channels'] : [],
			'inboxOnly'=>! method_exists( Admin_Event_Notification_Service::class, 'supports_delivery_policy' ),
		] );
	}
}
