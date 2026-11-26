<?php
namespace DSA\Commerce;

use DSA\Apps\Store_Checkout_Gate;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native Woo order receipts are the ledger for verified paid periods. */
final class Subscription_Renewal_Orders {
	public const AGREEMENT = Store_Checkout_Gate::AGREEMENT;
	public const CHARGE = '_kiwe_subscription_charge_v1';
	public const GATEWAY = Store_Checkout_Gate::GATEWAY;
	public const JOINED = '_kiwe_membership_joined_v1';
	private const SOURCE = 'kiwe-subscription';

	private static function children( \WC_Order $original ): array {
		return wc_get_orders( [
			'type'=>'shop_order',
			'parent'=>$original->get_id(),
			'created_via'=>self::SOURCE,
			'status'=>array_keys( wc_get_order_statuses() ), // Include unpaid and refunded receipts in retry matching.
			'limit'=>-1,
		] );
	}

	/** Call while the original order's billing lock is held. */
	public static function apply( \WC_Order $original, array $agreement, array $facts ): \WC_Order {
		if ( $facts['initial'] ) {
			$order = $original;
		} else {
			$known = null;
			foreach ( self::children( $original ) as $candidate ) {
				$charge = $candidate->get_meta( self::CHARGE );
				if ( ! is_array( $charge ) ) continue;
				if ( $charge['invoiceId'] === $facts['invoiceId'] ) {
					$known = $candidate;
				} elseif ( $charge['paymentId'] === $facts['paymentId']
					|| ( $charge['startsAt'] < $facts['endsAt'] && $facts['startsAt'] < $charge['endsAt'] ) ) {
					throw new \DomainException( 'Another invoice already covers this renewal period.' );
				}
			}
			$order = $known ?? self::renewal_order( $original, $agreement, $facts );
		}

		$previous = $order->get_meta( self::CHARGE );
		if ( $previous && ( ! is_array( $previous ) || self::identity( $facts ) !== self::identity( $previous ) ) ) {
			throw new \DomainException( 'A different payment is already recorded on this order.' );
		}
		if ( $order->get_payment_method() !== self::GATEWAY || $order->get_currency() !== 'INR'
			|| $order->get_customer_id() !== $agreement['customerId']
			|| Subscription_Quote_Service::minor( (string) $order->get_total() ) !== $facts['amountMinor']
			|| ( $order->get_transaction_id() && $order->get_transaction_id() !== $facts['paymentId'] ) ) {
			throw new \DomainException( 'The WooCommerce order no longer matches this payment.' );
		}
		$facts['refundedMinor'] = max( $facts['refundedMinor'], (int) ( $previous['refundedMinor'] ?? 0 ) );
		if ( $previous !== $facts ) {
			$order->update_meta_data( self::CHARGE, $facts );
			$order->save_meta_data();
		}

		if ( ! $order->is_paid() && ! $order->get_date_paid() && $facts['refundedMinor'] < $facts['amountMinor'] ) {
			if ( ! $order->has_status( [ 'pending', 'failed', 'on-hold' ] ) ) {
				throw new \DomainException( 'Review this order before applying the captured payment.' );
			}
			if ( ! $order->payment_complete( $facts['paymentId'] ) || ! $order->is_paid() || $order->get_transaction_id() !== $facts['paymentId'] ) {
				throw new \RuntimeException( 'WooCommerce did not confirm the paid order.' );
			}
		}
		if ( $facts['refundedMinor'] > 0 ) self::record_refund( $order, $facts['refundedMinor'] );
		return $order;
	}

	private static function record_refund( \WC_Order $order, int $verified_total ): void {
		$recorded = Subscription_Quote_Service::minor( (string) $order->get_total_refunded() );
		if ( $recorded >= $verified_total ) return;
		$refund = wc_create_refund( [
			'order_id'=>$order->get_id(), 'amount'=>self::decimal( $verified_total - $recorded ),
			'reason'=>__( 'Refund confirmed by Razorpay Subscriptions', 'dsa' ), 'refund_payment'=>false, 'restock_items'=>false,
		] );
		if ( is_wp_error( $refund ) || ! $refund ) throw new \RuntimeException( 'WooCommerce could not record the verified refund.' );
	}

	private static function identity( array $charge ): array {
		unset( $charge['refundedMinor'] );
		return $charge;
	}

	private static function renewal_order( \WC_Order $original, array $agreement, array $facts ): \WC_Order {
		// The order key lets both Woo data stores recover a receipt after an interrupted write.
		$key = 'wc_order_' . substr( hash_hmac( 'sha256', $facts['invoiceId'], $agreement['reference'] . $original->get_order_key() ), 0, 24 );
		$id = wc_get_order_id_by_order_key( $key );
		$order = $id ? wc_get_order( $id ) : new \WC_Order();
		if ( ! $order || ( $id && ( $order->get_parent_id() !== $original->get_id() || self::SOURCE !== $order->get_created_via()
			|| ( $order->get_meta( self::CHARGE ) && self::identity( $order->get_meta( self::CHARGE ) ) !== self::identity( $facts ) ) ) ) ) {
			throw new \DomainException( 'Review the existing WooCommerce renewal receipt.' );
		}
		if ( $id && $order->has_status( [ 'pending', 'processing', 'completed', 'refunded', 'on-hold', 'failed' ] ) ) return $order;

		$order->set_order_key( $key );
		$order->set_parent_id( $original->get_id() );
		$order->set_created_via( self::SOURCE );
		$order->set_customer_id( $agreement['customerId'] );
		$order->set_currency( 'INR' );
		$order->set_payment_method( self::GATEWAY );
		$order->set_payment_method_title( __( 'Razorpay subscription', 'dsa' ) );
		$order->set_prices_include_tax( false );
		$order->set_address( $original->get_address( 'billing' ), 'billing' );
		$order->set_status( 'checkout-draft' );
		$order->remove_order_items( 'line_item' );
		foreach ( $agreement['lines'] as $line ) {
			$item = new \WC_Order_Item_Product();
			$item->set_name( $line['name'] );
			$item->set_product_id( $line['productId'] );
			$item->set_variation_id( $line['variationId'] );
			$item->set_quantity( $line['quantity'] );
			$total = self::decimal( $line['unitMinor'] * $line['quantity'] );
			$item->set_subtotal( $total );
			$item->set_total( $total );
			$order->add_item( $item );
		}
		$order->set_total( self::decimal( $facts['amountMinor'] ) );
		$order->set_order_stock_reduced( true );
		$order->update_meta_data( self::CHARGE, $facts );
		$order->save();
		$order->update_status( 'pending' );
		return $order;
	}

	private static function decimal( int $minor ): string {
		return sprintf( '%d.%02d', intdiv( $minor, 100 ), $minor % 100 );
	}

	/** The paid access read is for the signed-in Woo customer, including after cancellation. */
	public static function membership( \WC_Order $order ): array {
		return self::owned_by( get_current_user_id(), $order ) ? self::paid_period( $order ) : [];
	}

	public static function owned_by( int $user_id, \WC_Order $order ): bool {
		$agreement = $order->get_meta( self::AGREEMENT );
		return $user_id > 0 && is_array( $agreement ) && $agreement['customerId'] === $user_id
			&& $order->get_customer_id() === $user_id && user_can( $user_id, 'read' )
			&& ! \DSA\Access\Team_Workspace_Service::suspended( $user_id )
			&& ( ! is_multisite() || is_user_member_of_blog( $user_id ) ) && ! Store_Recovery_Gate_Service::is_paused();
	}

	public static function paid_period( \WC_Order $order, ?int $at = null, bool $include_future = false ): array {
		$agreement = $order->get_meta( self::AGREEMENT );
		$initial = $order->get_meta( self::CHARGE );
		if ( ! is_array( $agreement ) || ! is_array( $initial ) || ! $order->get_date_paid()
			|| $order->get_transaction_id() !== $initial['paymentId'] ) return [];
		$until = 0;
		$ended = 0;
		$now = $at ?? time();
		foreach ( [ $order, ...self::children( $order ) ] as $receipt ) {
			$charge = $receipt->get_meta( self::CHARGE );
			if ( ! is_array( $charge ) || ! $receipt->is_paid() || $receipt->get_customer_id() !== $agreement['customerId']
				|| $receipt->get_transaction_id() !== $charge['paymentId']
				|| $charge['refundedMinor'] >= $charge['amountMinor'] || Subscription_Quote_Service::minor( (string) $receipt->get_total_refunded() ) >= $charge['amountMinor']
				|| ( ! $include_future && $charge['startsAt'] > $now ) ) continue;
			if ( $charge['endsAt'] <= $now ) $ended = max( $ended, $charge['endsAt'] );
			else $until = max( $until, $charge['endsAt'] );
		}
		$provider = $agreement['providerStatus'] ?? '';
		$state = 'cancelled' === $provider ? 'cancelled' : ( $until > 0 ? ( 'paused' === $provider ? 'paused' : 'active' ) : 'expired' );
		return [ 'status'=>$state, 'active'=>$until > 0, 'paidThrough'=>$until ?: $ended, 'products'=>array_map( static fn( $line )=>[
			'productId'=>$line['productId'], 'variationId'=>$line['variationId'], 'name'=>$line['name'],
		], $agreement['lines'] ) ];
	}

	public function render_membership( $order ): void {
		if ( ! $order instanceof \WC_Order ) return;
		$membership = self::membership( $order );
		if ( ! $membership ) return;
		$label = match ( $membership['status'] ) {
			'active'=>__( 'Membership active', 'dsa' ),
			'paused'=>__( 'Membership renewals paused', 'dsa' ),
			'cancelled'=>__( 'Membership cancelled', 'dsa' ),
			default=>__( 'Membership expired', 'dsa' ),
		};
		echo '<p class="kiwe-membership-status">' . esc_html( $label ) . '</p>';
		if ( $membership['active'] ) {
			echo '<p>' . esc_html( sprintf( __( 'Access through %s', 'dsa' ), wp_date( get_option( 'date_format' ), $membership['paidThrough'] ) ) ) . '</p>';
		}
	}
}
