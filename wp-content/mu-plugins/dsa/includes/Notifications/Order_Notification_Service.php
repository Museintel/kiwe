<?php

namespace DSA\Notifications;

use DSA\Communications\Channel_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Order_Notification_Service {
	private $preferences;
	private $channels;
	private $push;

	public function __construct( Notification_Preference_Service $preferences, Channel_Service $channels, Push_Service $push ) {
		$this->preferences = $preferences;
		$this->channels    = $channels;
		$this->push        = $push;
	}

	public function register(): void {
		if ( ! \DSA\Apps\Store_App_Service::enabled() ) return;
		add_action( 'woocommerce_order_status_changed', [ $this, 'notify_status_changed' ], 30, 4 );
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

		// One router owns both native inbox and explicitly chosen delivery methods.
		do_action( 'kiwe_notification_event', 'order_status', [
			'id'=>'order-' . $order_id . '-status-' . sanitize_key( (string) $from ) . '-' . sanitize_key( (string) $to ) . '-' . time(),
			'userIds'=>[ $user_id ],'objectId'=>$order_id,'title'=>$subject,'message'=>$message,
			'url'=>$url,'actionLabel'=>__( 'View your order', 'dsa' ),
		] );
	}
}
