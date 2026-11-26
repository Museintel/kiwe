<?php
namespace DSA\Commerce;

use DSA\Access\Team_Workspace_Service as Users;
use DSA\Apps\Store_App_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private account and notification projections over WooCommerce's verified receipts. */
final class Membership_Directory {
	private const EXPIRATION = 'kiwe_membership_term_ended';
	private static array $paid_products = [];

	public function register(): void {
		add_action( self::EXPIRATION, [ self::class, 'notify_expiration' ], 10, 2 );
		add_action( 'woocommerce_after_order_object_save', static function (): void { self::$paid_products = []; }, 10, 0 );
	}

	public static function can_list( int $actor ): bool {
		return $actor > 0 && Store_App_Service::enabled() && ! Store_Recovery_Gate_Service::is_paused()
			&& ! Users::suspended( $actor ) && user_can( $actor, 'read' ) && user_can( $actor, 'kiwe_manage_team' )
			&& ( ! is_multisite() || is_user_member_of_blog( $actor ) )
			&& user_can( $actor, 'list_users' ) && user_can( $actor, 'edit_users' ) && user_can( $actor, 'edit_shop_orders' );
	}

	public static function can_review( int $actor, \WC_Order $order ): bool {
		return self::can_list( $actor ) && Users::can_review_account_for( $actor, $order->get_customer_id() )
			&& user_can( $actor, 'edit_shop_order', $order->get_id() );
	}

	public static function notification_visible( int $actor, string $topic, int $order_id ): bool {
		if ( $actor < 1 || ! user_can( $actor, 'kiwe_use_notifications' ) || ! Store_App_Service::enabled()
			|| ! function_exists( 'wc_get_order' ) || Store_Recovery_Gate_Service::is_paused() ) return false;
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! is_array( $order->get_meta( Subscription_Renewal_Orders::AGREEMENT ) ) ) return false;
		return 'admin_membership' === $topic ? self::can_review( $actor, $order ) : Subscription_Renewal_Orders::owned_by( $actor, $order );
	}

	public static function summary( int $user_id ): array {
		$actor = get_current_user_id();
		if ( ! self::can_list( $actor ) || ! Users::can_review_account_for( $actor, $user_id ) || ! get_user_meta( $user_id, Subscription_Renewal_Orders::JOINED, true ) ) return [];

		$orders = wc_get_orders( [
			'type'=>'shop_order', 'customer_id'=>$user_id, 'parent'=>0, 'payment_method'=>Subscription_Renewal_Orders::GATEWAY,
			'orderby'=>'date', 'order'=>'DESC', 'limit'=>20,
			'status'=>array_keys( wc_get_order_statuses() ),
		] );
		$best = [];
		foreach ( $orders as $order ) {
			if ( ! self::can_review( $actor, $order ) || ! ( $membership = Subscription_Renewal_Orders::paid_period( $order ) ) ) continue;
			// Prefer a currently renewing membership when this customer has more than one.
			if ( ! $best || [ 'active' === $membership['status'], $membership['active'], $membership['paidThrough'] ]
				> [ 'active' === $best['status'], $best['active'], $best['paidThrough'] ] ) $best = $membership;
		}
		return $best ? array_intersect_key( $best, [ 'status'=>true, 'active'=>true, 'paidThrough'=>true ] ) : [];
	}

	public static function has_paid_access( int $user_id ): bool {
		foreach ( self::paid_memberships( $user_id ) as $membership ) return true;
		return false;
	}

	/** Includes a paid variation and its parent so a store can reward either level. */
	public static function paid_product_ids( int $user_id ): array {
		if ( $user_id < 1 || Users::suspended( $user_id ) || Store_Recovery_Gate_Service::is_paused() ) return [];
		if ( isset( self::$paid_products[$user_id] ) ) return self::$paid_products[$user_id];
		$ids = [];
		foreach ( self::paid_memberships( $user_id ) as $membership ) {
			foreach ( $membership['products'] as $line ) {
				$ids[(int) $line['productId']] = true;
				if ( $line['variationId'] ) $ids[(int) $line['variationId']] = true;
			}
		}
		return self::$paid_products[$user_id] = $ids;
	}

	private static function paid_memberships( int $user_id ): iterable {
		if ( $user_id < 1 || ! Store_App_Service::enabled() || Store_Recovery_Gate_Service::is_paused()
			|| ! get_user_meta( $user_id, Subscription_Renewal_Orders::JOINED, true ) ) return;
		$now = time();
		$checked = [];
		$page = 1;
		do {
			$receipts = wc_get_orders( [
				'customer_id'=>$user_id, 'payment_method'=>Subscription_Renewal_Orders::GATEWAY,
				'status'=>array_map( static fn ( $status ) => 'wc-' . $status, wc_get_is_paid_statuses() ),
				'date_paid'=>'>' . ( $now - 400 * DAY_IN_SECONDS ), 'limit'=>20, 'page'=>$page++,
			] );
			foreach ( $receipts as $receipt ) {
				$id = $receipt->get_parent_id();
				$order = $id ? wc_get_order( $id ) : $receipt;
				if ( ! $order || isset( $checked[ $order->get_id() ] ) ) continue;
				$checked[ $order->get_id() ] = true;
				if ( ! Subscription_Renewal_Orders::owned_by( $user_id, $order ) ) continue;
				$membership = Subscription_Renewal_Orders::paid_period( $order, $now );
				if ( ! empty( $membership['active'] ) ) yield $membership;
			}
		} while ( count( $receipts ) === 20 );
	}

	public static function record_payment( \WC_Order $original, \WC_Order $receipt, array $charge ): void {
		$customer_id = $original->get_customer_id();
		if ( ! $customer_id ) return;
		if ( $receipt->is_paid() && $charge['refundedMinor'] < $charge['amountMinor'] ) {
			if ( $original->get_date_paid() && ! get_user_meta( $customer_id, Subscription_Renewal_Orders::JOINED, true ) ) {
				Subscription_Billing_Service::synchronized( 'customer', $customer_id, static function () use ( $customer_id ): void {
					if ( ! get_user_meta( $customer_id, Subscription_Renewal_Orders::JOINED, true )
						&& ! add_user_meta( $customer_id, Subscription_Renewal_Orders::JOINED, time(), true ) ) {
						throw new \RuntimeException( 'The new member could not be added to the directory.' );
					}
				} );
			}
			$kind = $charge['initial'] ? ( ! empty( $charge['startsAt'] ) && $charge['startsAt'] > time() ? 'scheduled' : 'started' ) : 'renewed';
			self::notify( $original, $kind, (string) $receipt->get_id() );
			$args = [ $original->get_id(), $charge['endsAt'] ];
			if ( $charge['endsAt'] > time() && function_exists( 'as_has_scheduled_action' )
				&& ! as_has_scheduled_action( self::EXPIRATION, $args, 'kiwe-membership' ) ) {
				as_schedule_single_action( $charge['endsAt'], self::EXPIRATION, $args, 'kiwe-membership', true );
			}
		} elseif ( $receipt->get_date_paid() && $charge['refundedMinor'] >= $charge['amountMinor'] ) {
			self::notify( $original, 'refunded', (string) $receipt->get_id() );
		}
	}

	public static function notify_expiration( int $order_id, int $end ): void {
		if ( Store_Recovery_Gate_Service::is_paused() || ! Store_App_Service::enabled() || $end > time() ) return;
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( Subscription_Renewal_Orders::AGREEMENT ) ) return;
		$membership = Subscription_Renewal_Orders::paid_period( $order );
		if ( $membership && ! $membership['active'] && $membership['paidThrough'] === $end ) self::notify( $order, 'expired', (string) $end );
	}

	public static function notify( \WC_Order $order, string $kind, string $source ): void {
		if ( ! $order->get_date_paid() ) return;
		$label = match ( $kind ) {
			'started'=>__( 'Membership started', 'dsa' ),
			'scheduled'=>__( 'Membership scheduled', 'dsa' ),
			'renewed'=>__( 'Membership automatically renewed', 'dsa' ),
			'cancelled'=>__( 'Membership cancelled', 'dsa' ),
			'expired'=>__( 'Membership expired', 'dsa' ),
			'refunded'=>__( 'Membership payment refunded', 'dsa' ),
			default=>'',
		};
		if ( ! $label ) return;
		$plans = self::plan_names( $order );
		if ( $plans ) $label = sprintf( __( '%1$s: %2$s', 'dsa' ), $label, $plans );
		$id = 'membership-' . $order->get_id() . '-' . $kind . '-' . $source;
		$base = [
			'objectId'=>$order->get_id(), 'kicker'=>__( 'Membership', 'dsa' ), 'title'=>$label, 'inboxOnly'=>true,
		];
		do_action( 'kiwe_notification_event', 'membership_update', $base + [
			'id'=>$id, 'userIds'=>[ $order->get_customer_id() ], 'url'=>$order->get_view_order_url(),
			'message'=>__( 'Open your membership to see the current paid access dates.', 'dsa' ), 'actionLabel'=>__( 'View membership', 'dsa' ),
		] );
		do_action( 'kiwe_notification_event', 'admin_membership', $base + [
			'id'=>$id . '-staff', 'url'=>Users::url( [ 'team_view'=>'subscriptions', 'team_account'=>$order->get_customer_id() ] ),
			'message'=>__( 'Review this account’s membership in Daily → Users.', 'dsa' ), 'actionLabel'=>__( 'Review membership', 'dsa' ),
		] );
	}

	/** Use the plan names agreed to at purchase, including after a product is renamed. */
	public static function plan_names( \WC_Order $order ): string {
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		if ( ! is_array( $agreement['lines'] ?? null ) ) return '';
		$names = array_values( array_unique( array_filter( array_map(
			static fn( $line ) => sanitize_text_field( (string) ( $line['name'] ?? '' ) ),
			$agreement['lines']
		) ) ) );
		$label = implode( ', ', array_slice( $names, 0, 3 ) );
		return count( $names ) > 3 ? sprintf( __( '%1$s and %2$s more', 'dsa' ), $label, number_format_i18n( count( $names ) - 3 ) ) : $label;
	}
}
