<?php

namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Keep the gateway's native receipt dependency chain; omit its duplicate head SDK. */
final class Gateway_Asset_Service {
	private const SDK = 'https://checkout.razorpay.com/v1/checkout.js';
	private bool $omitted = false;

	public function register(): void {
		if ( ! \DSA\Apps\Store_App_Service::enabled() ) return;
		add_action( 'wp_enqueue_scripts', [ $this, 'prepare_receipt' ], 100 );
		add_action( 'wp_footer', [ $this, 'preserve_fallback' ], 1 );
	}

	public function prepare_receipt(): void {
		if ( is_admin() || wp_doing_ajax() || ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() || isset( $_GET['pay_for_order'] ) || ! is_string( $_GET['key'] ?? null ) ) return;
		$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
		if ( ! $order instanceof \WC_Order || 'razorpay' !== $order->get_payment_method() || ! hash_equals( $order->get_order_key(), wc_clean( wp_unslash( $_GET['key'] ) ) ) ) return;
		$scripts = wp_scripts();
		$early = $scripts->registered['razorpay-checkout-js'] ?? null;
		if ( ! $early || self::SDK !== $early->src || ! wp_script_is( 'razorpay-checkout-js', 'enqueued' ) || wp_script_is( 'razorpay-checkout-js', 'done' ) ) return;
		// Another extension's declared dependency wins. No deregistration, vendor
		// rewriting, order/payment mutation, external call or generic defer policy.
		foreach ( $scripts->registered as $script ) if ( in_array( 'razorpay-checkout-js', (array) $script->deps, true ) ) return;
		wp_dequeue_script( 'razorpay-checkout-js' );
		$this->omitted = true;
	}

	public function preserve_fallback(): void {
		if ( ! $this->omitted ) return;
		$scripts = wp_scripts();
		$native = $scripts->registered['razorpay_checkout'] ?? null;
		$consumer = $scripts->registered['razorpay_wc_script'] ?? null;
		if ( $native && self::SDK === $native->src && $consumer && in_array( 'razorpay_checkout', (array) $consumer->deps, true ) && wp_script_is( 'razorpay_wc_script', 'enqueued' ) ) return;
		// Unknown/hosted gateway integrations keep their original SDK behavior.
		wp_enqueue_script( 'razorpay-checkout-js' );
	}
}
