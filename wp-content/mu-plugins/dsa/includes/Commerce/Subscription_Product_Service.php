<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Recurring terms decorate native WooCommerce records. */
final class Subscription_Product_Service {
	public const META = '_kiwe_subscription_terms_v1';
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
		return [ 'subscriptionTerms' => self::terms( $product ) ];
	}

	public static function validate( mixed $input, bool $virtual, bool $public ): array|\WP_Error|null {
		if ( ! self::can_author() ) return new \WP_Error( 'kiwe_subscription_forbidden', 'Recurring product setup is unavailable for your account.', [ 'status' => 403 ] );
		if ( null === $input ) return null;
		if ( $public || ! $virtual ) {
			return new \WP_Error( 'kiwe_subscription_draft', 'During the billing pilot, keep the product virtual and save it as a draft or keep the variation disabled.', [ 'status' => 409 ] );
		}
		$limits = [ 'renewalMinor' => 999999999, 'intervalMonths' => 12, 'firstTermMonths' => 12 ];
		if ( ! is_array( $input ) || count( $input ) !== count( $limits ) ) return self::invalid_terms();
		$terms = [];
		foreach ( $limits as $field => $maximum ) {
			$value = $input[$field] ?? null;
			if ( ! is_int( $value ) || $value < 1 || $value > $maximum ) return self::invalid_terms();
			$terms[$field] = $value;
		}
		return $terms;
	}

	private static function invalid_terms(): \WP_Error {
		return new \WP_Error( 'kiwe_subscription_terms', 'Enter a positive renewal price and billing periods between one and twelve months.', [ 'status' => 400 ] );
	}

	public static function write( \WC_Product $product, ?array $terms ): void {
		if ( null === $terms ) $product->delete_meta_data( self::META );
		else $product->update_meta_data( self::META, $terms );
	}

	public function register(): void {
		add_filter( 'woocommerce_is_purchasable', [ $this, 'purchasable' ], 50, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', [ $this, 'purchasable' ], 50, 2 );
	}

	public function purchasable( bool $purchasable, \WC_Product $product ): bool {
		return $purchasable && null === self::terms( $product );
	}
}
