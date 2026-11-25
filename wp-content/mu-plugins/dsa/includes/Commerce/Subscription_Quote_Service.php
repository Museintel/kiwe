<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Calculations for a reviewed native order, with no gateway or order writes. */
final class Subscription_Quote_Service {
	public const PRODUCT_META = Subscription_Product_Service::META;

	public static function can_review(): bool {
		return current_user_can( 'edit_shop_orders' )
			&& ! \DSA\Access\Team_Workspace_Service::suspended( get_current_user_id() );
	}

	public static function can_review_order( int $order_id ): bool {
		return self::can_review() && $order_id > 0 && current_user_can( 'edit_shop_order', $order_id );
	}

	public static function review_order( int $order_id ): array {
		if ( ! self::can_review_order( $order_id ) ) throw new \RuntimeException( 'Reviewing subscription terms requires order access.' );
		$order = wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() || ! $order->has_status( [ 'checkout-draft', 'pending' ] ) ) {
			throw new \RuntimeException( 'Use an unpaid WooCommerce order to review current subscription terms.' );
		}
		$quote = self::snapshot( $order );
		unset( $quote['lines'] );
		return $quote;
	}

	public static function snapshot( \WC_Order $order ): array {
		return self::quote( $order->get_currency(), (string) $order->get_total(), self::order_lines( $order ) );
	}

	public static function cart_snapshot( \WC_Cart $cart ): array {
		return self::quote( get_woocommerce_currency(), (string) $cart->get_total( 'edit' ), self::cart_lines( $cart ) );
	}

	private static function order_lines( \WC_Order $order ): iterable {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			yield [ $item->get_product(), $item->get_product_id(), $item->get_variation_id(), $item->get_quantity(), $item->get_name() ];
		}
	}

	private static function cart_lines( \WC_Cart $cart ): iterable {
		foreach ( $cart->get_cart() as $line ) {
			yield [ $line['data'], $line['product_id'], $line['variation_id'], $line['quantity'], $line['data']->get_name() ];
		}
	}

	/** Variation names may be formatted differently at checkout and on a saved receipt. */
	public static function terms_digest( array $quote, \WC_Cart|\WC_Order $source ): string {
		foreach ( $quote['lines'] as &$line ) unset( $line['name'] );
		unset( $line );
		$lines = $source instanceof \WC_Cart ? self::cart_lines( $source ) : self::order_lines( $source );
		$basket = [];
		foreach ( $lines as [ , $product_id, $variation_id, $quantity ] ) {
			$basket[] = [ (int) $product_id, (int) $variation_id, (string) $quantity ];
		}
		sort( $basket );
		return hash( 'sha256', serialize( [ $quote, $basket ] ) );
	}

	private static function quote( string $currency, string $total, iterable $items ): array {
		if ( 'INR' !== $currency ) throw new \DomainException( 'The initial Razorpay subscription pilot supports INR.' );
		$first_charge = self::minor( $total );
		$recurring = 0;
		$one_time = 0;
		$renewal = 0;
		$interval = 0;
		$first_term = 0;
		$lines = [];
		foreach ( $items as [ $product, $product_id, $variation_id, $quantity, $name ] ) {
			if ( ! $product ) throw new \DomainException( 'Review this order because a product is no longer available.' );
			$terms = Subscription_Product_Service::terms( $product );
			if ( null === $terms ) {
				++$one_time;
				continue;
			}

			if ( ! is_array( $terms ) ) throw new \DomainException( 'A subscription line has invalid stored terms.' );
			$months = $terms['intervalMonths'] ?? 0;
			$covered = $terms['firstTermMonths'] ?? 0;
			$price = $terms['renewalMinor'] ?? 0;
			if ( ! $product->is_virtual() || ! is_int( $months ) || $months < 1 || $months > 12
				|| ! is_int( $covered ) || $covered < 1 || $covered > 12
				|| ! is_int( $price ) || $price < 1 || ! is_int( $quantity ) || $quantity < 1
				|| $price > intdiv( PHP_INT_MAX - $renewal, $quantity ) ) {
				throw new \DomainException( 'A subscription line needs a virtual product, a whole quantity and valid renewal terms.' );
			}
			if ( $recurring && ( $months !== $interval || $covered !== $first_term ) ) {
				throw new \DomainException( 'Renewing items in this checkout need the same billing interval and first term.' );
			}
			$interval = $months;
			$first_term = $covered;
			$renewal += $price * $quantity;
			++$recurring;
			$lines[] = [
				'productId' => (int) $product_id,
				'variationId' => (int) $variation_id,
				'name' => $name,
				'quantity' => $quantity,
				'unitMinor' => $price,
			];
		}
		if ( ! $recurring ) throw new \DomainException( 'This order has no Kiwe subscription products.' );
		return [
			'currency' => 'INR',
			'firstChargeMinor' => $first_charge,
			'renewalMinor' => $renewal,
			'firstTermMonths' => $first_term,
			'intervalMonths' => $interval,
			'recurringLines' => $recurring,
			'oneTimeLines' => $one_time,
			'lines' => $lines,
		];
	}

	public static function minor( string $total ): int {
		if ( ! preg_match( '/^(\d{1,11})(?:\.(\d{1,2})0*)?$/D', $total, $parts ) ) {
			throw new \RuntimeException( 'Review the native WooCommerce total before quoting this order.' );
		}
		return (int) $parts[1] * 100 + (int) str_pad( $parts[2] ?? '', 2, '0' );
	}
}
