<?php
namespace DSA\Commerce;

use DSA\Apps\Shiprocket_App_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Keeps authored checkout delivery choices in WooCommerce's session and order pipeline. */
final class Checkout_Shipping_Service {
	public static function payment_mode(): string {
		return 'cod' === WC()->session->get( 'chosen_payment_method' ) ? 'cod' : 'prepaid';
	}

	public static function shiprocket_rates(): bool {
		return Shiprocket_App_Service::realtime_configured();
	}

	public static function vary_shiprocket_packages( array $packages ): array {
		if ( ! WC()->session || ! self::shiprocket_rates() ) return $packages;
		foreach ( $packages as &$package ) {
			$package['kiwe_shiprocket_payment'] = self::payment_mode();
		}
		return $packages;
	}

	public static function review(): array {
		$required = WC()->cart->needs_shipping();
		$mode = self::payment_mode();
		$result = [ 'required'=>$required, 'state'=>'hidden', 'token'=>'', 'mode'=>$mode, 'modeSensitive'=>self::shiprocket_rates() ];
		if ( ! $required ) return $result;

		$packages = WC()->shipping()->get_packages();
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', [] );
		$result['state'] = empty( $packages ) ? 'address' : 'ready';
		$proof = [];
		foreach ( $packages as $index => $package ) {
			$destination = $package['destination'] ?? [];
			$country = $destination['country'] ?? '';
			$fields = $country ? WC()->countries->get_address_fields( $country, 'shipping_' ) : [];
			if ( ! $country || empty( $destination['address_1'] )
				|| ( ! empty( $fields['shipping_postcode']['required'] ) && empty( $destination['postcode'] ) ) ) {
				$result['state'] = 'address';
				return $result;
			}
			$rates = $package['rates'] ?? [];
			$selected = $chosen[ $index ] ?? '';
			if ( ! isset( $rates[ $selected ] ) ) $result['state'] = 'unavailable';
			if ( isset( $rates[ $selected ] ) ) {
				$proof[ $index ] = [ $destination, $selected, $rates[ $selected ]->get_cost(), $rates[ $selected ]->get_taxes() ];
			}
		}
		if ( 'ready' === $result['state'] ) {
			$result['token'] = hash_hmac( 'sha256', wp_json_encode( [
				WC()->session->get_customer_id(), WC()->cart->get_cart_hash(), WC()->cart->get_total( 'edit' ), $proof,
				$result['modeSensitive'] ? $mode : '',
			] ), wp_salt( 'nonce' ) );
		}
		return $result;
	}

	public static function update( $index, $rate, $mode ): ?\WP_Error {
		$cart = WC()->cart;
		$session = WC()->session;
		$cart->calculate_totals();
		$review = self::review();
		$error = new \WP_Error( 'kiwe_shipping_changed', __( 'Delivery options changed. Review the current rates and try again.', 'dsa' ), [ 'status'=>409 ] );
		if ( ! $review['required'] || 'address' === $review['state'] ) return $error;

		if ( is_string( $mode ) && null === $index && null === $rate ) {
			if ( ! $review['modeSensitive'] || ! in_array( $mode, [ 'cod', 'prepaid' ], true ) ) return $error;
			if ( 'cod' === $mode && ! self::can_quote_cod( ( new Subscription_Checkout_Service() )->review() ) ) return $error;
			$session->set( 'chosen_payment_method', 'cod' === $mode ? 'cod' : 'razorpay' );
			return null;
		}

		if ( null !== $mode || ! is_int( $index ) || $index < 0 || ! is_string( $rate )
			|| ! isset( WC()->shipping()->get_packages()[ $index ]['rates'][ $rate ] ) ) return $error;
		$chosen = (array) $session->get( 'chosen_shipping_methods', [] );
		$chosen[ $index ] = $rate;
		$session->set( 'chosen_shipping_methods', $chosen );
		return null;
	}

	public static function can_quote_cod( ?array $terms ): bool {
		$gateway = WC()->payment_gateways()->payment_gateways()['cod'] ?? null;
		return null === $terms && $gateway && 'yes' === $gateway->enabled;
	}

	public static function validate( array $data, \WP_Error $errors ): void {
		if ( ! isset( $_POST['kiwe_checkout_shipping_quote'] ) || ! WC()->cart->needs_shipping() ) return;
		$token = $_POST['kiwe_checkout_shipping_quote'];
		$current = self::review()['token'];
		if ( ! is_string( $token ) || '' === $current || ! hash_equals( $current, wp_unslash( $token ) ) ) {
			$errors->add( 'kiwe_shipping_changed', __( 'Delivery rates or the order total changed. Review the updated checkout before paying.', 'dsa' ) );
		}
	}
}
