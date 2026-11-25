<?php
namespace DSA\Apps;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Enforce Store availability and keep recurring Woo records out of ordinary payment flows. */
final class Store_Checkout_Gate {
	public const PRODUCT_TERMS = '_kiwe_subscription_terms_v1';
	public const AGREEMENT = '_kiwe_subscription_agreement_v1';
	public const GATEWAY = 'kiwe_razorpay_subscription';
	private const USED_OPTION = 'kiwe_has_subscription_records_v1';

	public static function remember_usage(): void {
		if ( '1' === (string) get_option( self::USED_OPTION ) ) return;
		add_option( self::USED_OPTION, '1', '', true );
		if ( '1' !== (string) get_option( self::USED_OPTION ) ) throw new \RuntimeException( 'WooCommerce could not protect the recurring records.' );
	}

	public static function register( bool $store ): void {
		if ( ! function_exists( 'WC' ) ) return;
		if ( ! $store ) {
			$choice = get_option( Store_App_Service::OPTION, null );
			if ( null !== $choice && ! Store_App_Service::requested() ) {
				self::close_store();
				return;
			}
			if ( '1' !== (string) get_option( self::USED_OPTION ) ) return;
		}
		add_filter( 'woocommerce_order_needs_payment', [ self::class, 'ordinary_payment_allowed' ], 100, 2 );
		add_action( 'woocommerce_before_pay_action', [ self::class, 'guard_order_pay' ] );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ self::class, 'guard_store_api' ] );
		if ( ! $store ) {
			add_filter( 'woocommerce_is_purchasable', [ self::class, 'ordinary_product' ], 100, 2 );
			add_filter( 'woocommerce_variation_is_purchasable', [ self::class, 'ordinary_product' ], 100, 2 );
			add_action( 'woocommerce_after_checkout_validation', [ self::class, 'guard_cart' ], 100, 2 );
		}
	}

	private static function close_store(): void {
		add_filter( 'woocommerce_is_purchasable', '__return_false', 100 );
		add_filter( 'woocommerce_variation_is_purchasable', '__return_false', 100 );
		add_filter( 'woocommerce_order_needs_payment', static fn ( bool $needed ): bool => $needed && ! is_checkout_pay_page(), 100 );
		add_action( 'woocommerce_before_pay_action', static function (): void {
			throw new \DomainException( self::closed_message() );
		} );
		add_action( 'woocommerce_after_checkout_validation', static function ( array $data, \WP_Error $errors ): void {
			$errors->add( 'kiwe_store_closed', self::closed_message() );
		}, 100, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', static function (): void {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'kiwe_store_closed', self::closed_message(), 409 );
		} );
	}

	private static function closed_message(): string {
		return __( 'Store checkout is currently closed.', 'dsa' );
	}

	public static function ordinary_product( bool $allowed, \WC_Product $product ): bool {
		return $allowed && '' === $product->get_meta( self::PRODUCT_TERMS, true, 'edit' );
	}

	private static function recurring_order( \WC_Order $order ): bool {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( $product && ! self::ordinary_product( true, $product ) ) return true;
		}
		return false;
	}

	public static function ordinary_payment_allowed( bool $needs_payment, \WC_Order $order ): bool {
		return $needs_payment && self::GATEWAY !== $order->get_payment_method() && ! $order->get_meta( self::AGREEMENT )
			&& ( ! is_checkout_pay_page() || ! self::recurring_order( $order ) );
	}

	public static function guard_cart( array $data, \WP_Error $errors ): void {
		foreach ( WC()->cart?->get_cart() ?? [] as $line ) {
			if ( ! self::ordinary_product( true, $line['data'] ) ) {
				$errors->add( 'kiwe_subscription_checkout_required', __( 'Subscription checkout is temporarily unavailable. Contact the store about these items.', 'dsa' ) );
				return;
			}
		}
	}

	public static function guard_order_pay( \WC_Order $order ): void {
		if ( self::recurring_order( $order ) ) {
			throw new \DomainException( __( 'Use subscription checkout to agree to the renewal terms for these items.', 'dsa' ) );
		}
	}

	public static function guard_store_api( \WC_Order $order ): void {
		if ( self::GATEWAY === $order->get_payment_method() || $order->get_meta( self::AGREEMENT ) || self::recurring_order( $order ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'kiwe_subscription_checkout_required', __( 'Use the store’s subscription checkout to agree to the renewal terms.', 'dsa' ), 409
			);
		}
	}
}
