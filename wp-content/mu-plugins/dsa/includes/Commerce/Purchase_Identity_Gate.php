<?php

namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Require a Key.kiwe subscriber and one verified recovery factor at payment. */
final class Purchase_Identity_Gate {
	private bool $registered = false;

	/** Do not attach a commerce hook on editorial/non-WooCommerce sites. */
	public function register_if_available(): void {
		if ( $this->registered || ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return;
		}

		$this->registered = true;
		$this->register();
	}

	public function register(): void {
		add_filter( 'dsa_purchase_identity_gate_enabled', [ $this, 'enabled' ] );
		add_filter( 'dsa_purchase_identity_gate_state', [ $this, 'state' ] );
		add_action( 'template_redirect', [ $this, 'guard_checkout_entry' ], 1 );
		add_action( 'woocommerce_checkout_process', [ $this, 'validate_classic_checkout' ], 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'validate_store_api_checkout' ], 1 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'transition_classic_customer' ], 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'transition_store_api_customer' ], 20, 1 );
	}

	public function enabled( $enabled = false ): bool {
		if ( ! function_exists( 'WC' ) && ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		// WooCommerce's own account requirement is a hard boundary. When guest
		// checkout is enabled, Kiwe's verified-contact policy remains configurable.
		return $this->woocommerce_account_required()
			|| (bool) apply_filters( 'dsa_phonekey_purchase_verified_contact_enabled', true );
	}

	public function state( $state = [] ): array {
		if ( ! $this->enabled() ) {
			return [
				'enabled'         => false,
				'accountRequired' => $this->woocommerce_account_required(),
				'state'           => 'allowed',
				'allowed'         => true,
				'message'         => '',
			];
		}

		if ( ! is_user_logged_in() ) {
			return [
				'enabled'         => true,
				'accountRequired' => $this->woocommerce_account_required(),
				'state'           => 'signup_required',
				'allowed'         => false,
				'message'         => __( 'Create or sign in to your free account before purchasing.', 'dsa' ),
			];
		}

		$verified = (bool) apply_filters( 'dsa_phonekey_account_identity_verified', false, get_current_user_id() );
		if ( ! $verified ) {
			return [
				'enabled'         => true,
				'accountRequired' => $this->woocommerce_account_required(),
				'state'           => 'verification_required',
				'allowed'         => false,
				'message'         => __( 'Verify either your email address or phone number before purchasing.', 'dsa' ),
			];
		}

		return [
			'enabled'         => true,
			'accountRequired' => $this->woocommerce_account_required(),
			'state'           => 'allowed',
			'allowed'         => true,
			'message'         => '',
		];
	}

	/**
	 * A direct /checkout request must enter through the same Key.kiwe intent as
	 * a cart button. Order-payment and receipt endpoints retain Woo authority.
	 */
	public function guard_checkout_entry(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_user_logged_in() && ! empty( $this->state()['allowed'] ) ) {
			return;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			return;
		}
		$method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
			return;
		}

		$state = $this->state();
		if ( ! empty( $state['allowed'] ) ) {
			return;
		}

		$target = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
		$target = add_query_arg( 'dsa-checkout-intent', '1', $target ?: home_url( '/' ) );
		nocache_headers();
		wp_safe_redirect( $target, 302, 'Kiwe Key.kiwe' );
		exit;
	}

	public function validate_classic_checkout(): void {
		$state = $this->state();
		if ( empty( $state['allowed'] ) && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( (string) $state['message'], 'error' );
		}
	}

	/**
	 * WooCommerce documents this action as an order-placement validation seam:
	 * throwing stops Checkout Block/Store API payment and renders a warning.
	 */
	public function validate_store_api_checkout( $order ): void {
		$state = $this->state();
		if ( empty( $state['allowed'] ) ) {
			throw new \Exception( esc_html( (string) $state['message'] ) );
		}
	}

	public function transition_classic_customer( $order_id, $posted_data = [], $order = null ): void {
		$this->transition_subscriber_to_customer( $order ?: $order_id );
	}

	public function transition_store_api_customer( $order ): void {
		$this->transition_subscriber_to_customer( $order );
	}

	private function transition_subscriber_to_customer( $order ): void {
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( absint( $order ) );
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_user_id' ) || ! get_role( 'customer' ) ) {
			return;
		}

		$user_id = absint( $order->get_user_id() );
		$user = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user || ! array_intersect( [ 'subscriber', 'kiwe_user' ], (array) $user->roles ) ) {
			return;
		}

		// A content/store role is never silently replaced by a purchase. Key.kiwe's
		// ordinary account starts with Subscriber alone, so only that exact lifecycle
		// state becomes WooCommerce Customer after its first order is placed.
		if ( array_diff( (array) $user->roles, [ 'subscriber', 'kiwe_user' ] ) ) {
			return;
		}

		$user->set_role( 'customer' );
		update_user_meta( $user_id, 'dsa_customer_since_order', method_exists( $order, 'get_id' ) ? absint( $order->get_id() ) : 0 );
		do_action( 'dsa_phonekey_customer_transitioned', $user_id, $order );
	}

	private function woocommerce_account_required(): bool {
		return 'no' === get_option( 'woocommerce_enable_guest_checkout', 'yes' );
	}
}
