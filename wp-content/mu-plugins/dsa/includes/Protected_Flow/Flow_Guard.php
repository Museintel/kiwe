<?php

namespace DSA\Protected_Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Flow_Guard {
	public function current(): array {
		return $this->current_state()->to_array();
	}

	public function current_state(): Flow_State {
		$context = $this->detect_current_context();

		return new Flow_State( $context, $this->message_for_context( $context ) );
	}

	public function excluded_routes(): array {
		return [
			'/cart*',
			'/checkout*',
			'/my-account*',
			'/order-pay*',
			'/order-received*',
			'/?wc-ajax=*',
			'/*?add-to-cart=*',
		];
	}

	private function detect_current_context(): Flow_Context {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
				return Flow_Context::OrderReceived;
			}

			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
				return Flow_Context::Payment;
			}

			return Flow_Context::Checkout;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return Flow_Context::Cart;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'lost-password' ) ) {
				return Flow_Context::PasswordReset;
			}

			return is_user_logged_in() ? Flow_Context::Account : Flow_Context::Login;
		}

		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );

		if ( false !== stripos( $request_uri, 'wc-ajax=' ) || false !== stripos( $request_uri, 'add-to-cart=' ) ) {
			return Flow_Context::CartCommit;
		}

		if ( false !== stripos( $request_uri, '/wp-login.php' ) ) {
			return Flow_Context::Login;
		}

		return Flow_Context::None;
	}

	private function message_for_context( Flow_Context $context ): string {
		return match ( $context ) {
			Flow_Context::Checkout      => __( 'Checkout is protected by Kiwe trust rules.', 'dsa' ),
			Flow_Context::Payment       => __( 'Payment stays on the safest full-page flow.', 'dsa' ),
			Flow_Context::OrderReceived => __( 'Order confirmation is protected from partial swaps.', 'dsa' ),
			Flow_Context::Account       => __( 'Account pages stay on the safest full-page route.', 'dsa' ),
			Flow_Context::Login,
			Flow_Context::PasswordReset => __( 'Sign-in and recovery stay on protected routes.', 'dsa' ),
			Flow_Context::Cart,
			Flow_Context::CartCommit    => __( 'Cart updates stay server-authoritative.', 'dsa' ),
			Flow_Context::None          => '',
		};
	}
}
