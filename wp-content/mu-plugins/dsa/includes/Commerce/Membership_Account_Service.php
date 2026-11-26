<?php
namespace DSA\Commerce;

use DSA\Access\Team_Workspace_Service;
use DSA\Apps\Store_App_Service;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Buyer-only WooCommerce account and renewal controls. */
final class Membership_Account_Service {
	private const ACTION = 'kiwe_stop_membership_renewals';
	private const PAUSE = 'kiwe_pause_membership_renewals';
	private const RESUME = 'kiwe_resume_membership_renewals';

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
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		$controls = self::can_cancel( $order );
		return [
			'orderId'=>$order->get_id(), 'status'=>$period['status'], 'active'=>$period['active'],
			'paidThrough'=>$period['paidThrough'], 'url'=>$order->get_view_order_url(), 'canCancel'=>$controls,
			'canPause'=>$controls && 'active' === $agreement['providerStatus'],
			'canResume'=>$controls && 'paused' === $agreement['providerStatus'],
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

	public static function active_cards(): array {
		if ( ! self::available() || ! get_user_meta( get_current_user_id(), Subscription_Renewal_Orders::JOINED, true ) ) return [];
		$cards = [];
		$page = 1;
		do {
			$list = self::listing( $page++ );
			foreach ( $list['memberships'] as $membership ) {
				if ( ! $membership['active'] ) continue;
				$order = wc_get_order( $membership['orderId'] );
				if ( ! $order ) continue;
				$cards[] = $membership + [
					'name'=>Membership_Directory::plan_names( $order ) ?: __( 'Membership', 'dsa' ),
					'paidThroughLabel'=>wp_date( get_option( 'date_format' ), $membership['paidThrough'] ),
				];
				if ( count( $cards ) === 6 ) return $cards;
			}
		} while ( $list['hasMore'] );
		return $cards;
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

	public static function pause_review( \WC_Order $order, bool $pause ): string {
		if ( ! self::can_cancel( $order ) ) throw new \DomainException( __( 'This membership is unavailable for your account.', 'dsa' ) );
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		if ( ( $pause ? 'active' : 'paused' ) !== $agreement['providerStatus'] ) {
			throw new \DomainException( __( 'Razorpay does not allow this change in the current billing state.', 'dsa' ) );
		}
		return hash_hmac( 'sha256', serialize( [
			$order->get_id(), $order->get_customer_id(), $agreement['subscriptionId'], $agreement['providerStatus'], $pause,
		] ), wp_salt( 'nonce' ) );
	}

	public function render_account(): void {
		if ( ! self::available() || ! get_user_meta( get_current_user_id(), Subscription_Renewal_Orders::JOINED, true ) ) return;
		$list = self::listing();
		if ( ! $list['memberships'] ) return;
		echo '<section id="kiwe-memberships" class="woocommerce-MyAccount-memberships"><h2>' . esc_html__( 'Recent memberships', 'dsa' ) . '</h2><ul>';
		foreach ( $list['memberships'] as $item ) {
			$order = wc_get_order( $item['orderId'] );
			$label = ( $order ? Membership_Directory::plan_names( $order ) : '' ) ?: sprintf( __( 'Membership order #%s', 'dsa' ), number_format_i18n( $item['orderId'] ) );
			printf( '<li><a href="%s">%s</a></li>', esc_url( $item['url'] ), esc_html( $label ) );
		}
		echo '</ul></section>';
	}

	public function render_cancellation( \WC_Order $order ): void {
		if ( ! is_wc_endpoint_url( 'view-order' ) || ! self::status( $order ) ) return;
		if ( self::can_cancel( $order ) ) {
			$review = self::cancellation( $order );
			$this->billing_form( $order, self::ACTION, $review['expected'], __( 'Stop automatic renewals and keep access for the period already paid.', 'dsa' ), __( 'Stop renewals', 'dsa' ) );
			$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
			if ( 'active' === $agreement['providerStatus'] ) {
				$this->billing_form( $order, self::PAUSE, self::pause_review( $order, true ), __( 'Pause future charges and keep the access already paid for.', 'dsa' ), __( 'Pause renewals', 'dsa' ) );
			} elseif ( 'paused' === $agreement['providerStatus'] ) {
				$this->billing_form( $order, self::RESUME, self::pause_review( $order, false ), __( 'Allow Razorpay to resume automatic charges on this plan.', 'dsa' ), __( 'Resume renewals', 'dsa' ) );
			}
		}
		$shop = wc_get_page_permalink( 'shop' );
		if ( $shop ) {
			printf( '<p><a href="%s">%s</a></p>', esc_url( $shop ), esc_html__( 'Change plan', 'dsa' ) );
			echo '<p>' . esc_html__( 'You can buy a separate membership now. To change plans in the same group, stop renewals and choose the new plan after paid access ends.', 'dsa' ) . '</p>';
		}
	}

	private function billing_form( \WC_Order $order, string $action, string $expected, string $prompt, string $label ): void {
		printf( '<form method="post" action="%s"><input type="hidden" name="kiwe_membership_action" value="%s"><input type="hidden" name="expected" value="%s">',
			esc_url( $order->get_view_order_url() ), esc_attr( $action ), esc_attr( $expected ) );
		wp_nonce_field( $action . '_' . $order->get_id(), '_wpnonce', false );
		echo '<p><label><input type="checkbox" name="confirm" value="yes" required> ' . esc_html( $prompt ) . '</label></p>';
		echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
	}

	public function handle_cancellation(): void {
		$action = $_POST['kiwe_membership_action'] ?? '';
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! in_array( $action, [ self::ACTION, self::PAUSE, self::RESUME ], true )
			|| ! is_wc_endpoint_url( 'view-order' ) ) return;
		$id = absint( get_query_var( 'view-order' ) );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! Origin_Checker::is_same_site_request() || ! $order || ! self::owns( $order ) ) {
			wp_die( esc_html__( 'This membership is unavailable for your account.', 'dsa' ), '', [ 'response'=>403 ] );
		}
		check_admin_referer( $action . '_' . $id );
		$expected = $_POST['expected'] ?? '';
		try {
			if ( 'yes' !== ( $_POST['confirm'] ?? '' ) || ! is_string( $expected ) ) throw new \DomainException();
			if ( self::ACTION === $action ) {
				$result = Subscription_Billing_Service::cancel_own_subscription( $id, wp_unslash( $expected ) );
				$notice = 'cancelled' === $result['status'] ? __( 'Automatic renewals stopped.', 'dsa' ) : __( 'This membership has no further renewals.', 'dsa' );
			} else {
				$pause = self::PAUSE === $action;
				Subscription_Billing_Service::change_own_pause( $id, $pause, wp_unslash( $expected ) );
				$notice = $pause ? __( 'Automatic renewals paused.', 'dsa' ) : __( 'Automatic renewals resumed.', 'dsa' );
			}
			wc_add_notice( $notice );
		} catch ( \Throwable $error ) {
			wc_add_notice( __( 'The change to renewals could not be confirmed. Review your membership and try again.', 'dsa' ), 'error' );
		}
		wp_safe_redirect( $order->get_view_order_url(), 303 );
		exit;
	}
}
