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
			if ( ! empty( $terms['membershipGroup'] ) ) {
				$lines[ count( $lines ) - 1 ]['membershipGroup'] = $terms['membershipGroup'];
			}
		}
		if ( ! $recurring ) throw new \DomainException( 'This order has no Kiwe subscription products.' );
		if ( count( self::groups( $lines ) ) !== count( $lines ) || count( array_unique( array_column( $lines, 'productId' ) ) ) !== count( $lines ) ) {
			throw new \DomainException( __( 'Choose only one term or plan from each membership group.', 'dsa' ) );
		}
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

	/** Older subscriptions and ungrouped variations share their Woo parent product's group. */
	public static function groups( array $lines ): array {
		return array_values( array_unique( array_map( static fn( $line ) => ! empty( $line['membershipGroup'] )
			? 'group:' . $line['membershipGroup'] : 'product:' . (int) $line['productId'], $lines ) ) );
	}

	public static function assert_groups_available( int $customer, array $lines, int $replaced_order = 0 ): void {
		$groups = self::groups( $lines );
		$products = array_column( $lines, 'productId' );
		$page = 1;
		do {
			$orders = wc_get_orders( [
				'customer_id'=>$customer, 'parent'=>0, 'payment_method'=>Subscription_Renewal_Orders::GATEWAY,
				'status'=>array_keys( wc_get_order_statuses() ), 'limit'=>20, 'page'=>$page++,
			] );
			foreach ( $orders as $order ) {
				if ( $replaced_order && $replaced_order === $order->get_id() ) continue;
				$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
				if ( ! is_array( $agreement['lines'] ?? null ) || ! Subscription_Renewal_Orders::owned_by( $customer, $order )
					|| ( ! array_intersect( $groups, self::groups( $agreement['lines'] ) )
						&& ! array_intersect( $products, array_column( $agreement['lines'], 'productId' ) ) ) ) continue;
				$ended = in_array( $agreement['providerStatus'], [ 'cancelled','completed','expired' ], true );
				// A previously paid renewal may cover a future term even after billing stops.
				$period = Subscription_Renewal_Orders::paid_period( $order, null, true );
				if ( ! $ended || ! empty( $period['active'] ) ) {
					throw new \DomainException( __( 'This group already has a membership or an approval in progress. Stop its renewals and wait until paid access ends to switch terms.', 'dsa' ) );
				}
			}
		} while ( count( $orders ) === 20 );
	}

	public static function minor( string $total ): int {
		if ( ! preg_match( '/^(\d{1,11})(?:\.(\d{1,2})0*)?$/D', $total, $parts ) ) {
			throw new \RuntimeException( 'Review the native WooCommerce total before quoting this order.' );
		}
		return (int) $parts[1] * 100 + (int) str_pad( $parts[2] ?? '', 2, '0' );
	}
}
