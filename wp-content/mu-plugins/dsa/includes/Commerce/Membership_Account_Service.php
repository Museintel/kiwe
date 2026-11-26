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
	private const SWITCH_PLAN = 'kiwe_switch_membership_plan';

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
			'startsAt'=>$period['startsAt'] ?? 0,
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
		$items = array_values( array_filter( array_map( [ self::class, 'projection' ], $orders->orders ) ) );
		return [ 'page'=>$page, 'hasMore'=>$page < $orders->max_num_pages, 'memberships'=>$items ];
	}

	public static function active_cards(): array {
		if ( ! self::available() || ! get_user_meta( get_current_user_id(), Subscription_Renewal_Orders::JOINED, true ) ) return [];
		$cards = [];
		$page = 1;
		do {
			$list = self::listing( $page++ );
			foreach ( $list['memberships'] as $membership ) {
				if ( ! $membership['active'] && 'scheduled' !== $membership['status'] ) continue;
				$cards[] = $membership;
				if ( count( $cards ) === 6 ) return $cards;
			}
		} while ( $list['hasMore'] );
		return $cards;
	}

	public static function projection( \WC_Order $order, bool $controls = false ): array {
		$item = self::status( $order );
		if ( ! $item ) return [];
		$date = get_option( 'date_format' );
		$item += [
			'name'=>Membership_Directory::plan_names( $order ) ?: __( 'Membership', 'dsa' ),
			'paidThroughLabel'=>$item['paidThrough'] ? wp_date( $date, $item['paidThrough'] ) : '',
			'startsLabel'=>$item['startsAt'] ? wp_date( $date, $item['startsAt'] ) : '',
		];
		if ( ! $controls ) return $item;
		$item['actions'] = [];
		if ( $item['canCancel'] ) $item['actions'][] = [ 'action'=>'cancel','label'=>__( 'Stop renewals', 'dsa' ),
			'message'=>__( 'Keep paid access and stop future automatic charges.', 'dsa' ), 'expected'=>self::cancellation( $order )['expected'] ];
		foreach ( [ 'pause'=>'canPause', 'resume'=>'canResume' ] as $action=>$cap ) {
			if ( $item[ $cap ] ) $item['actions'][] = [ 'action'=>$action,
				'label'=>'pause' === $action ? __( 'Pause renewals', 'dsa' ) : __( 'Resume renewals', 'dsa' ),
				'message'=>'pause' === $action ? __( 'Keep paid access and pause future automatic charges.', 'dsa' ) : __( 'Allow automatic charges to resume for this plan.', 'dsa' ),
				'expected'=>self::pause_review( $order, 'pause' === $action ) ];
		}
		foreach ( self::switches( $order ) as $variation=>$review ) {
			$item['actions'][] = [ 'action'=>'switch','variation'=>$variation,'label'=>$review['label'],
				'message'=>$review['message'],'expected'=>$review['expected'] ];
		}
		return $item;
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
		$page = max( 1, min( 1000, absint( $_GET['kiwe_membership_page'] ?? 1 ) ) );
		$list = self::listing( $page );
		if ( ! $list['memberships'] ) return;
		echo '<section id="kiwe-memberships" class="woocommerce-MyAccount-memberships"><h2>' . esc_html__( 'Your memberships', 'dsa' ) . '</h2><ul>';
		foreach ( $list['memberships'] as $item ) {
			$order = wc_get_order( $item['orderId'] );
			$label = ( $order ? Membership_Directory::plan_names( $order ) : '' ) ?: sprintf( __( 'Membership order #%s', 'dsa' ), number_format_i18n( $item['orderId'] ) );
			printf( '<li><a href="%s">%s</a></li>', esc_url( $item['url'] ), esc_html( $label ) );
		}
		echo '</ul>';
		foreach ( [ $page - 1=>__( 'Previous memberships', 'dsa' ), $page + 1=>__( 'More memberships', 'dsa' ) ] as $index=>$label ) {
			if ( $index < 1 || ( $index > $page && ! $list['hasMore'] ) ) continue;
			printf( '<p><a href="%s">%s</a></p>', esc_url( add_query_arg( 'kiwe_membership_page', $index, wc_get_page_permalink( 'myaccount' ) ) . '#kiwe-memberships' ), esc_html( $label ) );
		}
		echo '</section>';
	}

	private static function switch_source( \WC_Order $order ): array {
		if ( ! self::owns( $order ) || ! Subscription_Checkout_Service::enabled() ) return [];
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		$lines = $agreement['lines'] ?? [];
		$paid = Subscription_Renewal_Orders::paid_period( $order );
		$covered = Subscription_Renewal_Orders::paid_period( $order, null, true );
		if ( count( $lines ) !== 1 || empty( $lines[0]['variationId'] ) || 1 !== $lines[0]['quantity']
			|| ! in_array( $agreement['providerStatus'], [ 'authenticated','active','paused','cancelled','completed' ], true )
			|| empty( $paid['active'] ) || $paid['paidThrough'] < time() + DAY_IN_SECONDS
			|| $covered['paidThrough'] !== $paid['paidThrough'] ) return [];
		return [ 'agreement'=>$agreement, 'line'=>$lines[0], 'startsAt'=>$paid['paidThrough'] ];
	}

	public static function switch_review( \WC_Order $order, int $variation ): array {
		return self::review_variation( $order, $variation, self::switch_source( $order ) );
	}

	private static function review_variation( \WC_Order $order, int $variation, array $source ): array {
		$product = $source ? wc_get_product( $variation ) : null;
		$parent = $product ? wc_get_product( $product->get_parent_id() ) : null;
		$terms = $product ? Subscription_Product_Service::terms( $product ) : null;
		$plan = is_array( $terms ) ? Subscription_Checkout_Service::plan_id( $terms ) : '';
		if ( ! $product instanceof \WC_Product_Variation || ! $parent || ! $parent->is_type( 'variable' ) || ! $plan
			|| $product->get_parent_id() !== $source['line']['productId'] || $product->get_id() === $source['line']['variationId']
			|| 'publish' !== $parent->get_status() || 'publish' !== $product->get_status() || ! $product->is_purchasable()
			|| ! $product->is_in_stock() || ! Subscription_Product_Service::supported_for_checkout( $product )
			|| ( $terms['membershipGroup'] ?? '' ) !== ( $source['line']['membershipGroup'] ?? '' )
			|| $terms['firstTermMonths'] <= $source['agreement']['firstTermMonths'] ) {
			throw new \DomainException( __( 'This membership cannot switch to that term.', 'dsa' ) );
		}
		$amount = Subscription_Quote_Service::minor( (string) $product->get_price() );
		if ( $amount < 100 ) throw new \DomainException( __( 'This membership is missing a purchase price.', 'dsa' ) );
		return [ 'product'=>$product, 'terms'=>$terms, 'planId'=>$plan, 'startsAt'=>$source['startsAt'], 'firstChargeMinor'=>$amount,
			'expected'=>hash_hmac( 'sha256', serialize( [ $order->get_id(), $order->get_customer_id(), $source['agreement']['reference'],
				$variation, $plan, $source['startsAt'], $amount, $terms ] ), wp_salt( 'nonce' ) ) ];
	}

	private static function switches( \WC_Order $order ): array {
		$source = self::switch_source( $order );
		if ( ! $source || ! ( $parent = wc_get_product( $source['line']['productId'] ) ) || ! $parent->is_type( 'variable' ) ) return [];
		$options = [];
		foreach ( $parent->get_children() as $variation ) {
			try {
				$review = self::review_variation( $order, $variation, $source );
			} catch ( \DomainException $error ) {
				continue;
			}
			$terms = $review['terms'];
			$prompt = sprintf( __( 'Switch to %1$s starting %2$s. Stop the current renewals and authorize %3$s now for the next term, then %4$s every %5$d months.', 'dsa' ),
				$review['product']->get_name(), wp_date( get_option( 'date_format' ), $review['startsAt'] ),
				wp_strip_all_tags( wc_price( $review['firstChargeMinor'] / 100, [ 'currency'=>'INR' ] ) ),
				wp_strip_all_tags( wc_price( $terms['renewalMinor'] / 100, [ 'currency'=>'INR' ] ) ), $terms['intervalMonths'] );
			$label = sprintf( __( 'Switch to %d months', 'dsa' ), $terms['firstTermMonths'] );
			$options[ $variation ] = [ 'expected'=>$review['expected'], 'message'=>$prompt, 'label'=>$label ];
		}
		return $options;
	}

	private function render_plan_switches( \WC_Order $order ): void {
		$options = self::switches( $order );
		if ( $options ) {
			echo '<p>' . esc_html__( 'Your current paid access continues if you leave Razorpay before paying for the next term.', 'dsa' ) . '</p>';
		}
		foreach ( $options as $variation=>$option ) {
			$this->billing_form( $order, self::SWITCH_PLAN, $option['expected'], $option['message'], $option['label'], $variation );
		}
	}

	public function render_cancellation( \WC_Order $order ): void {
		if ( ! is_wc_endpoint_url( 'view-order' ) || ! self::status( $order ) ) return;
		$this->render_plan_switches( $order );
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
			echo '<p>' . esc_html__( 'Visit the shop for separate memberships. Eligible longer terms can be scheduled here to follow your current paid term.', 'dsa' ) . '</p>';
		}
	}

	private function billing_form( \WC_Order $order, string $action, string $expected, string $prompt, string $label, int $variation = 0 ): void {
		printf( '<form method="post" action="%s"><input type="hidden" name="kiwe_membership_action" value="%s"><input type="hidden" name="expected" value="%s">',
			esc_url( $order->get_view_order_url() ), esc_attr( $action ), esc_attr( $expected ) );
		wp_nonce_field( $action . '_' . $order->get_id(), '_wpnonce', false );
		if ( $variation ) printf( '<input type="hidden" name="variation" value="%d">', $variation );
		echo '<p><label><input type="checkbox" name="confirm" value="yes" required> ' . esc_html( $prompt ) . '</label></p>';
		echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
	}

	public function handle_cancellation(): void {
		$action = $_POST['kiwe_membership_action'] ?? '';
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! in_array( $action, [ self::ACTION, self::PAUSE, self::RESUME, self::SWITCH_PLAN ], true )
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
			if ( self::SWITCH_PLAN === $action ) {
				$target = Subscription_Billing_Service::switch_own_plan( $id, absint( $_POST['variation'] ?? 0 ), wp_unslash( $expected ) );
				wc_add_notice( __( 'Review the replacement order and continue to Razorpay to authorize the next term.', 'dsa' ) );
				wp_safe_redirect( $target, 303 );
				exit;
			} elseif ( self::ACTION === $action ) {
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
