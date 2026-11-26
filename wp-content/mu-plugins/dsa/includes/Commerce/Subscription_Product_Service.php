<?php
namespace DSA\Commerce;

use DSA\Apps\Store_Checkout_Gate;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Recurring terms decorate native WooCommerce records. */
final class Subscription_Product_Service {
	public const META = Store_Checkout_Gate::PRODUCT_TERMS;
	public const OPTION = 'kiwe_store_subscription_authoring_v1';

	public static function can_author(): bool {
		return '1' === (string) get_option( self::OPTION, '0' )
			&& 'INR' === get_woocommerce_currency()
			&& current_user_can( 'edit_products' )
			&& ! \DSA\Access\Team_Workspace_Service::suspended( get_current_user_id() );
	}

	public static function terms( \WC_Product $product ): mixed {
		$terms = $product->get_meta( self::META, true, 'edit' );
		return '' === $terms ? null : $terms;
	}

	public static function editor( \WC_Product $product ): array {
		if ( ! self::can_author() || ! $product->is_type( [ 'simple', 'variation' ] ) ) return [];
		return [
			'subscriptionTerms' => self::terms( $product ),
			'subscriptionCheckoutReady' => Subscription_Checkout_Service::enabled(),
		];
	}

	public static function validate( mixed $input, bool $virtual ): array|\WP_Error|null {
		if ( ! self::can_author() ) return new \WP_Error( 'kiwe_subscription_forbidden', 'Recurring product setup is unavailable for your account.', [ 'status' => 403 ] );
		if ( null === $input ) return null;
		if ( ! $virtual ) {
			return new \WP_Error( 'kiwe_subscription_virtual', 'Use a virtual product for recurring billing.', [ 'status' => 409 ] );
		}
		$limits = [ 'renewalMinor' => 999999999, 'intervalMonths' => 12, 'firstTermMonths' => 12 ];
		if ( ! is_array( $input ) || array_diff_key( $input, $limits + [ 'membershipGroup'=>true ] ) ) return self::invalid_terms();
		$terms = [];
		foreach ( $limits as $field => $maximum ) {
			$value = $input[$field] ?? null;
			if ( ! is_int( $value ) || $value < 1 || $value > $maximum ) return self::invalid_terms();
			$terms[$field] = $value;
		}
		if ( array_key_exists( 'membershipGroup', $input ) ) {
			$group = $input['membershipGroup'];
			if ( ! is_string( $group ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $group ) || strlen( $group ) > 64 ) {
				return new \WP_Error( 'kiwe_subscription_group', __( 'Enter a membership group using lowercase letters, numbers and hyphens.', 'dsa' ), [ 'status'=>400 ] );
			}
			$terms['membershipGroup'] = $group;
		}
		return $terms;
	}

	private static function invalid_terms(): \WP_Error {
		return new \WP_Error( 'kiwe_subscription_terms', 'Enter a positive renewal price and billing periods between one and twelve months.', [ 'status' => 400 ] );
	}

	public static function write( \WC_Product $product, ?array $terms ): void {
		if ( null === $terms ) {
			$product->delete_meta_data( self::META );
			return;
		}
		Store_Checkout_Gate::remember_usage();
		$product->update_meta_data( self::META, $terms );
	}

	public static function supported_for_checkout( \WC_Product $product ): bool {
		return $product->is_virtual() && ! $product->managing_stock()
			&& ( ! wc_tax_enabled() || 'none' === $product->get_tax_status() );
	}

	public static function checkout_allowed( \WC_Product $product, mixed $terms ): bool {
		return is_array( $terms ) && self::supported_for_checkout( $product ) && '' !== Subscription_Checkout_Service::plan_id( $terms );
	}

	public function register(): void {
		add_filter( 'woocommerce_is_purchasable', [ $this, 'purchasable' ], 50, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', [ $this, 'purchasable' ], 50, 2 );
	}

	public function purchasable( bool $purchasable, \WC_Product $product ): bool {
		if ( ! $purchasable ) return false;
		$terms = self::terms( $product );
		return null === $terms || ( 'publish' === $product->get_status() && self::checkout_allowed( $product, $terms ) );
	}
}
