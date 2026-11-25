<?php
namespace DSA\Commerce;

use DSA\Access\Team_Workspace_Service;
use DSA\Apps\Store_App_Service;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Buyer-only WooCommerce account and renewal controls. */
final class Membership_Account_Service {
	private const ACTION = 'kiwe_stop_membership_renewals';

	public function register(): void {
		add_action( 'woocommerce_account_dashboard', [ $this, 'render_account' ] );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render_cancellation' ], 20 );
		add_action( 'template_redirect', [ $this, 'handle_cancellation' ], 9 );
	}

	public static function available(): bool {
		$user = get_current_user_id();
		return $user > 0 && current_user_can( 'read' ) && ! Team_Workspace_Service::suspended( $user )
			&& ( ! is_multisite() || is_user_member_of_blog( $user ) )
			&& Store_App_Service::enabled() && Store_App_Service::supports_memberships() && ! Store_Recovery_Gate_Service::is_paused();
	}

	public static function owns( \WC_Order $order ): bool {
		return ! $order->get_parent_id() && self::available() && current_user_can( 'view_order', $order->get_id() )
			&& Subscription_Renewal_Orders::owned_by( get_current_user_id(), $order );
	}

	public static function can_cancel( \WC_Order $order ): bool {
		if ( ! self::owns( $order ) || ! $order->get_date_paid() || ! Subscription_Billing_Service::available() ) return false;
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		return Razorpay_Subscription_Provider::id( $agreement['subscriptionId'] ?? '', 'sub' )
			&& ! in_array( $agreement['providerStatus'], [ 'cancelled', 'expired', 'completed' ], true );
	}

	public static function status( \WC_Order $order ): array {
		if ( ! self::owns( $order ) || ! ( $period = Subscription_Renewal_Orders::paid_period( $order ) ) ) return [];
		return [
			'orderId'=>$order->get_id(), 'status'=>$period['status'], 'active'=>$period['active'],
			'paidThrough'=>$period['paidThrough'], 'url'=>$order->get_view_order_url(), 'canCancel'=>self::can_cancel( $order ),
		];
	}

	public static function listing( int $page = 1 ): array {
		if ( ! self::available() ) throw new \DomainException( __( 'Sign in to review your memberships.', 'dsa' ) );
		$orders = wc_get_orders( [
			'customer_id'=>get_current_user_id(), 'parent'=>0, 'payment_method'=>Subscription_Renewal_Orders::GATEWAY,
			'status'=>array_keys( wc_get_order_statuses() ),
			'limit'=>10, 'page'=>$page, 'paginate'=>true,
		] );
		$items = array_values( array_filter( array_map( [ self::class, 'status' ], $orders->orders ) ) );
		return [ 'page'=>$page, 'hasMore'=>$page < $orders->max_num_pages, 'memberships'=>$items ];
	}

	public static function cancellation( \WC_Order $order ): array {
		if ( ! self::can_cancel( $order ) ) throw new \DomainException( __( 'This membership is unavailable for cancellation by your account.', 'dsa' ) );
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		return self::status( $order ) + [
			'expected'=>hash_hmac( 'sha256', serialize( [
				$order->get_id(), $order->get_customer_id(), $agreement['subscriptionId'], $agreement['providerStatus'],
			] ), wp_salt( 'nonce' ) ),
		];
	}

	public function render_account(): void {
		if ( ! self::available() || ! get_user_meta( get_current_user_id(), Subscription_Renewal_Orders::JOINED, true ) ) return;
		$list = self::listing();
		if ( ! $list['memberships'] ) return;
		echo '<section class="woocommerce-MyAccount-memberships"><h2>' . esc_html__( 'Recent memberships', 'dsa' ) . '</h2><ul>';
		foreach ( $list['memberships'] as $item ) {
			$label = sprintf( __( 'Membership order #%s', 'dsa' ), number_format_i18n( $item['orderId'] ) );
			printf( '<li><a href="%s">%s</a></li>', esc_url( $item['url'] ), esc_html( $label ) );
		}
		echo '</ul></section>';
	}

	public function render_cancellation( \WC_Order $order ): void {
		if ( ! is_wc_endpoint_url( 'view-order' ) || ! self::can_cancel( $order ) ) return;
		$review = self::cancellation( $order );
		printf( '<form method="post" action="%s"><input type="hidden" name="kiwe_membership_action" value="%s"><input type="hidden" name="expected" value="%s">',
			esc_url( $order->get_view_order_url() ), esc_attr( self::ACTION ), esc_attr( $review['expected'] ) );
		wp_nonce_field( self::ACTION . '_' . $order->get_id(), '_wpnonce', false );
		echo '<p><label><input type="checkbox" name="confirm" value="yes" required> ' . esc_html__( 'Stop automatic renewals and keep access for the period already paid.', 'dsa' ) . '</label></p>';
		echo '<button class="button" type="submit">' . esc_html__( 'Stop renewals', 'dsa' ) . '</button></form>';
	}

	public function handle_cancellation(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || self::ACTION !== ( $_POST['kiwe_membership_action'] ?? '' )
			|| ! is_wc_endpoint_url( 'view-order' ) ) return;
		$id = absint( get_query_var( 'view-order' ) );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! Origin_Checker::is_same_site_request() || ! $order || ! self::owns( $order ) ) {
			wp_die( esc_html__( 'This membership is unavailable for your account.', 'dsa' ), '', [ 'response'=>403 ] );
		}
		check_admin_referer( self::ACTION . '_' . $id );
		$expected = $_POST['expected'] ?? '';
		try {
			if ( 'yes' !== ( $_POST['confirm'] ?? '' ) || ! is_string( $expected ) ) throw new \DomainException();
			$result = Subscription_Billing_Service::cancel_own_subscription( $id, wp_unslash( $expected ) );
			$notice = 'cancelled' === $result['status'] ? __( 'Automatic renewals stopped.', 'dsa' ) : __( 'This membership has no further renewals.', 'dsa' );
			wc_add_notice( $notice );
		} catch ( \Throwable $error ) {
			wc_add_notice( __( 'Cancellation could not be confirmed. Review your membership and try again.', 'dsa' ), 'error' );
		}
		wp_safe_redirect( $order->get_view_order_url(), 303 );
		exit;
	}
}
