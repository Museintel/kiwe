<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native Woo payment gateway for the opt-in recurring checkout. */
final class Subscription_Checkout_Gateway extends \WC_Payment_Gateway {
	public function __construct( private Subscription_Checkout_Service $checkout ) {
		$this->id = Subscription_Renewal_Orders::GATEWAY;
		$this->title = __( 'Razorpay subscription', 'dsa' );
		$this->method_title = $this->title;
		$this->method_description = __( 'Kiwe subscription checkout in Razorpay Test Mode.', 'dsa' );
		$this->enabled = 'yes';
		$this->has_fields = true;
	}

	public function payment_fields(): void {
		$this->checkout->payment_fields();
	}

	public function process_payment( $order_id ): array {
		return $this->checkout->process_payment( (int) $order_id );
	}
}
