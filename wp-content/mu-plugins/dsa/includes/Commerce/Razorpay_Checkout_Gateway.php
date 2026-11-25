<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Keeps the native order, provider request, verification and refund methods. */
final class Razorpay_Checkout_Gateway extends \WC_Razorpay {
	public function generateOrderForm( $data ) {
		$id = absint( $data['notes']['woocommerce_order_id'] ?? 0 );
		$order = $id ? wc_get_order( $id ) : null;
		$method = Razorpay_Checkout_Service::receipt_method( $order instanceof \WC_Order ? $order : null );
		if ( '' === $method || isset( $data['config']['display'] ) ) return parent::generateOrderForm( $data );

		$data['config']['display'] = Razorpay_Checkout_Service::display_config( $method );
		$receipt = parent::generateOrderForm( $data );
		$all_methods = add_query_arg( 'kiwe_payment_methods', 'all', $order->get_checkout_payment_url( true ) );
		return $receipt . '<p><a href="' . esc_url( $all_methods ) . '">' . esc_html__( 'Use another payment method', 'dsa' ) . '</a></p>';
	}
}
