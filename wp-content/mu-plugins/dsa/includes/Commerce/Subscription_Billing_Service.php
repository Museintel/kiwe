<?php
namespace DSA\Commerce;

use DSA\Apps\Store_App_Service;
use DSA\Apps\Store_Checkout_Gate;
use DSA\MCP\Authoring_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Consented native order terms and Razorpay's verified invoice are the two billing authorities. */
final class Subscription_Billing_Service {
	public const ROUTE = '/razorpay/subscriptions';
	public const CYCLES = 12;
	public const EXPIRY_HOOK = 'dsa_expire_subscription_checkout';

	public static function available(): bool {
		return Store_App_Service::enabled() && Store_App_Service::supports_memberships()
			&& ! Store_Recovery_Gate_Service::is_paused() && Razorpay_Subscription_Provider::available();
	}

	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', [ new Subscription_Renewal_Orders(), 'render_membership' ] );
		( new Membership_Directory() )->register();
		( new Membership_Content_Service() )->register();
		( new Membership_Account_Service() )->register();
		add_action( self::EXPIRY_HOOK, [ $this, 'expire_checkout' ] );
		if ( ! self::available() ) return;
		add_action( 'rest_api_init', function (): void {
			register_rest_route( 'dsa/v1', self::ROUTE, [
				'methods'=>'POST',
				'permission_callback'=>'__return_true',
				'callback'=>[ $this, 'webhook' ],
			] );
		} );
	}

	public static function can_manage(): bool {
		return self::available() && current_user_can( 'manage_woocommerce' ) && Subscription_Quote_Service::can_review();
	}

	public static function can_manage_order( int $id ): bool {
		return self::can_manage() && Subscription_Quote_Service::can_review_order( $id );
	}

	public static function enrollment_plan( int $id, string $plan_id ): array {
		$order = self::managed_order( $id );
		if ( ! Razorpay_Subscription_Provider::id( $plan_id, 'plan' ) ) throw new \DomainException( 'Select a Razorpay Test Mode plan.' );
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		if ( is_array( $agreement ) ) {
			if ( $plan_id !== $agreement['planId'] ) throw new \DomainException( 'This order already has reviewed recurring terms.' );
			$quote = array_intersect_key( $agreement, array_flip( [ 'currency', 'firstChargeMinor', 'renewalMinor', 'firstTermMonths', 'intervalMonths' ] ) );
		} else {
			$quote = self::eligible_snapshot( $order );
			unset( $quote['lines'] );
		}
		return Authoring_Service::plan(
			'Prepare the Razorpay Test Mode first charge and recurring approval',
			Authoring_Service::digest( [ self::order_fingerprint( $order ), $order->get_status(), $agreement, $plan_id, $quote ] ),
			[ 'orderId'=>$id, 'state'=>$agreement ? 'agreement-recorded' : 'unpaid' ],
			$quote + [ 'planId'=>$plan_id, 'cycles'=>self::CYCLES, 'mode'=>'test' ],
			true
		);
	}

	public static function enroll( int $id, string $plan_id, string $expected ): array {
		return self::synchronized( 'order', $id, static function () use ( $id, $plan_id, $expected ): array {
			$order = self::managed_order( $id );
			$review = self::enrollment_plan( $id, $plan_id );
			if ( ! hash_equals( $review['nativeExpected'], $expected ) ) throw new \DomainException( 'The order changed. Review its recurring terms again.' );
			$existing = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
			if ( is_array( $existing ) ) return self::public_state( $order, $existing );

			$agreement = self::begin( $order, self::eligible_snapshot( $order ), $plan_id );
			return self::public_state( $order, $agreement );
		} );
	}

	/** Only the classic gateway calls this, while its validated shopper checkout is locked. */
	public static function open_checkout( int $id, string $terms_digest ): void {
		self::synchronized( 'order', $id, static function () use ( $id, $terms_digest ): void {
			$order = wc_get_order( $id );
			if ( ! $order || ! Subscription_Checkout_Service::owns_order( $order )
				|| 'checkout' !== $order->get_created_via() || $order->get_parent_id()
				|| $order->get_meta( Subscription_Renewal_Orders::AGREEMENT ) ) {
				throw new \DomainException( __( 'Review this subscription from your account orders.', 'dsa' ) );
			}
			$quote = self::eligible_snapshot( $order, Subscription_Renewal_Orders::GATEWAY );
			$plan_id = Subscription_Checkout_Service::plan_id( $quote );
			if ( ! $plan_id || ! hash_equals( $terms_digest, Subscription_Quote_Service::terms_digest( $quote, $order ) ) ) {
				throw new \DomainException( __( 'The order changed. Review the current total and renewal terms.', 'dsa' ) );
			}
			self::begin( $order, $quote, $plan_id, true );
		} );
	}

	private static function begin( \WC_Order $order, array $quote, string $plan_id, bool $checkout = false ): array {
		$plan = Razorpay_Subscription_Provider::plan( $plan_id );
		if ( 'monthly' !== ( $plan['period'] ?? null ) || $quote['intervalMonths'] !== ( $plan['interval'] ?? null )
			|| $quote['renewalMinor'] !== ( $plan['item']['amount'] ?? null ) || 'INR' !== ( $plan['item']['currency'] ?? null ) ) {
			throw new \DomainException( 'The Razorpay plan must match the reviewed renewal price, currency and interval.' );
		}
		Store_Checkout_Gate::remember_usage();
		$created = time();
		$agreement = $quote + [
			'orderId'=>$order->get_id(), 'customerId'=>$order->get_customer_id(), 'planId'=>$plan_id, 'cycles'=>self::CYCLES,
			'reference'=>bin2hex( random_bytes( 16 ) ), 'fingerprint'=>self::order_fingerprint( $order ),
			'createdAt'=>$created, 'firstRenewalAt'=>self::add_months( $created, $quote['firstTermMonths'] ),
			'approvalExpiresAt'=>$created + DAY_IN_SECONDS, 'subscriptionId'=>'', 'authorizationUrl'=>'', 'providerStatus'=>'',
		];
		if ( $checkout ) {
			$agreement['checkoutConsentAt'] = $created;
			Subscription_Checkout_Service::remember_order( $order );
		}
		$order->set_payment_method( Subscription_Renewal_Orders::GATEWAY );
		$order->set_payment_method_title( __( 'Razorpay subscription', 'dsa' ) );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( Subscription_Renewal_Orders::AGREEMENT, $agreement );
		$order->save();

		// Only send the request after the intent can be found again through native storage.
		$saved = wc_get_order( $order->get_id() );
		if ( ! $saved || ! $saved->has_status( 'on-hold' ) || $agreement !== $saved->get_meta( Subscription_Renewal_Orders::AGREEMENT ) ) {
			throw new \RuntimeException( 'WooCommerce could not confirm the subscription order.' );
		}
		return self::bind( $saved, $agreement, Razorpay_Subscription_Provider::create( $agreement ) );
	}

	public static function enrollment( int $id ): array {
		$order = self::managed_order( $id );
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		return self::public_state( $order, is_array( $agreement ) ? $agreement : [] );
	}

	public static function cancel_own_subscription( int $id, string $expected ): array {
		return self::synchronized( 'order', $id, static function () use ( $id, $expected ): array {
			$order = wc_get_order( $id );
			if ( ! $order || ! Membership_Account_Service::owns( $order ) || ! self::available() ) {
				throw new \DomainException( __( 'This membership is unavailable for your account.', 'dsa' ) );
			}
			$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
			if ( 'cancelled' === $agreement['providerStatus'] ) return Membership_Account_Service::status( $order );
			$review = Membership_Account_Service::cancellation( $order );
			if ( ! hash_equals( $review['expected'], $expected ) ) {
				throw new \DomainException( __( 'This membership changed. Review it before stopping renewals.', 'dsa' ) );
			}
			$subscription = Razorpay_Subscription_Provider::subscription( $agreement['subscriptionId'] );
			$agreement = self::bind( $order, $agreement, $subscription );
			if ( ! in_array( $agreement['providerStatus'], [ 'cancelled', 'completed', 'expired' ], true ) ) {
				$agreement = self::bind( $order, $agreement, Razorpay_Subscription_Provider::cancel( $agreement['subscriptionId'] ) );
				if ( 'cancelled' !== $agreement['providerStatus'] ) {
					throw new \RuntimeException( __( 'Razorpay is still confirming this cancellation. Check your membership again.', 'dsa' ) );
				}
			}
			if ( 'cancelled' === $agreement['providerStatus'] ) Membership_Directory::notify( $order, 'cancelled', 'confirmed' );
			return Membership_Account_Service::status( $order );
		} );
	}

	public static function recover( int $order_id, string $subscription_id, string $expected ): array {
		self::managed_order( $order_id );
		$subscription = Razorpay_Subscription_Provider::subscription( $subscription_id );
		return self::synchronized( 'order', $order_id, static function () use ( $order_id, $subscription_id, $subscription, $expected ): array {
			$order = self::managed_order( $order_id );
			self::require_review( $order_id, $subscription_id, false, $expected );
			$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
			if ( ! is_array( $agreement ) ) throw new \DomainException( 'This order has no recorded Razorpay approval request.' );
			return self::public_state( $order, self::bind( $order, $agreement, $subscription ) );
		} );
	}

	public static function sync_payment( int $id, string $payment_id, string $expected ): array {
		self::managed_order( $id );
		self::reconcile( $payment_id, $id, $expected );
		return self::enrollment( $id );
	}

	private static function require_review( int $id, string $record, bool $payment, string $expected ): void {
		if ( ! hash_equals( self::review_reconciliation( $id, $record, $payment )['nativeExpected'], $expected ) ) {
			throw new \DomainException( 'The WooCommerce order changed. Review the billing action again.' );
		}
	}

	public static function review_reconciliation( int $id, string $record_id, bool $payment ): array {
		$order = self::managed_order( $id );
		if ( ! Razorpay_Subscription_Provider::id( $record_id, $payment ? 'pay' : 'sub' ) ) {
			throw new \DomainException( 'Enter the Razorpay record for this test order.' );
		}
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		if ( ! is_array( $agreement ) ) throw new \DomainException( 'Record the order agreement before reconciling a test payment.' );
		return Authoring_Service::plan(
			$payment ? 'Verify a captured Test Mode payment and apply its invoice' : 'Read back a matching Test Mode subscription approval',
			Authoring_Service::digest( [ $record_id, $order->get_status(), self::order_fingerprint( $order ), $agreement ] ),
			self::public_state( $order, $agreement ),
			[ 'orderId'=>$id, 'recordId'=>$record_id, 'mode'=>'test' ],
			true
		);
	}

	private static function managed_order( int $id ): \WC_Order {
		if ( ! self::can_manage_order( $id ) ) throw new \RuntimeException( 'This subscription pilot requires access to the WooCommerce order.' );
		$order = wc_get_order( $id );
		if ( ! $order || 'shop_order' !== $order->get_type() || $order->get_parent_id() ) throw new \DomainException( 'Select the original WooCommerce order.' );
		return $order;
	}

	private static function public_state( \WC_Order $order, array $agreement ): array {
		$state = match ( true ) {
			! $agreement => 'no-agreement',
			'cancelled' === ( $agreement['providerStatus'] ?? '' ) => 'cancelled',
			$order->is_paid() => 'paid',
			empty( $agreement['subscriptionId'] ) => 'provider-review',
			$agreement['approvalExpiresAt'] <= time() => 'approval-window-closed',
			! $agreement['authorizationUrl'] => 'provider-review',
			default => 'awaiting-approval',
		};
		return [
			'orderId'=>$order->get_id(),
			'state'=>$state,
			'firstRenewalAt'=>$agreement['firstRenewalAt'] ?? 0,
			'authorizationUrl'=>'awaiting-approval' === $state && self::can_manage_order( $order->get_id() ) ? $agreement['authorizationUrl'] : '',
		];
	}

	private static function eligible_snapshot( \WC_Order $order, string $payment_method = '' ): array {
		$customer = $order->get_customer_id();
		if ( ! $order->has_status( [ 'pending', 'failed' ] ) || ! $customer || ! user_can( $customer, 'read' )
			|| \DSA\Access\Team_Workspace_Service::suspended( $customer ) || ( is_multisite() && ! is_user_member_of_blog( $customer ) )
			|| $payment_method !== $order->get_payment_method() || $order->get_transaction_id() ) {
			throw new \DomainException( 'Use an unpaid customer order that has not entered another payment flow.' );
		}
		$quote = Subscription_Quote_Service::snapshot( $order );
		if ( $quote['firstChargeMinor'] < 100 || $quote['renewalMinor'] < 100 || $quote['recurringLines'] > 50 ) {
			throw new \DomainException( 'Use a positive upfront and renewal price of at least ₹1 and up to fifty recurring lines.' );
		}
		foreach ( $quote['lines'] as $line ) {
			$product = wc_get_product( $line['variationId'] ?: $line['productId'] );
			if ( ! $product || ! Subscription_Product_Service::supported_for_checkout( $product ) ) {
				throw new \DomainException( 'The pilot needs recurring items without stock tracking or renewal taxes.' );
			}
		}
		return $quote;
	}

	private static function order_fingerprint( \WC_Order $order ): string {
		$lines = [];
		foreach ( $order->get_items() as $item ) {
			$lines[] = [ $item->get_id(), $item->get_product_id(), $item->get_variation_id(), $item->get_quantity(), $item->get_total(), $item->get_total_tax() ];
		}
		return hash( 'sha256', serialize( [ $order->get_id(), $order->get_customer_id(), $order->get_currency(), $order->get_total(),
			$order->get_address( 'billing' ), $order->get_address( 'shipping' ), $lines ] ) );
	}

	private static function add_months( int $timestamp, int $months ): int {
		$date = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
		$target = $date->modify( 'first day of this month' )->modify( '+' . $months . ' months' );
		return $target->setDate( (int) $target->format( 'Y' ), (int) $target->format( 'n' ), min( (int) $date->format( 'j' ), (int) $target->format( 't' ) ) )->getTimestamp();
	}

	/** Billing continues when the optional MCP app is turned off. */
	public static function synchronized( string $scope, int $id, callable $write ): mixed {
		$key = self::lock( $scope, $id );
		try { return $write(); }
		finally { self::unlock( $key ); }
	}

	public static function lock( string $scope, int $id ): string {
		global $wpdb;
		$key = 'kiwe-membership-' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $scope . '|' . $id ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $key ) ) ) {
			throw new \RuntimeException( 'Another request is reconciling this membership. Retry the event.' );
		}
		return $key;
	}

	public static function unlock( string $key ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}

	private static function bind( \WC_Order $order, array $agreement, array $subscription ): array {
		$id = $subscription['id'] ?? '';
		if ( ! Razorpay_Subscription_Provider::id( $id, 'sub' ) || ( $agreement['subscriptionId'] && $agreement['subscriptionId'] !== $id )
			|| (string) $order->get_id() !== ( $subscription['notes']['kiwe_order'] ?? '' )
			|| ! hash_equals( $agreement['reference'], (string) ( $subscription['notes']['kiwe_agreement'] ?? '' ) )
			|| $agreement['planId'] !== ( $subscription['plan_id'] ?? null ) || $agreement['firstRenewalAt'] !== ( $subscription['start_at'] ?? null )
			|| 1 !== ( $subscription['quantity'] ?? null ) || $agreement['cycles'] !== ( $subscription['total_count'] ?? null ) ) {
			throw new \DomainException( 'The subscription does not match the reviewed native order.' );
		}
		$before = $agreement;
		if ( ! $agreement['subscriptionId'] ) {
			$agreement['subscriptionId'] = $id;
			$agreement['authorizationUrl'] = Razorpay_Subscription_Provider::authorization_url( $subscription['short_url'] ?? null );
			if ( ! empty( $agreement['checkoutConsentAt'] ) ) {
				wp_schedule_single_event( max( time() + MINUTE_IN_SECONDS, $agreement['approvalExpiresAt'] + MINUTE_IN_SECONDS ), self::EXPIRY_HOOK, [ $order->get_id() ] );
			}
		}
		// A terminal state does not regress when an older payment notification arrives later.
		$terminal = [ 'cancelled', 'completed', 'expired' ];
		if ( ! in_array( $agreement['providerStatus'], $terminal, true ) && in_array( $subscription['status'] ?? '', $terminal, true ) ) {
			$agreement['providerStatus'] = $subscription['status'];
		}
		if ( $before !== $agreement ) {
			$order->update_meta_data( Subscription_Renewal_Orders::AGREEMENT, $agreement );
			$order->save_meta_data();
		}
		return $agreement;
	}

	public function expire_checkout( int $id ): void {
		if ( ! self::available() ) return;
		try {
			self::synchronized( 'order', $id, static function () use ( $id ): void {
				$order = wc_get_order( $id );
				$agreement = $order ? $order->get_meta( Subscription_Renewal_Orders::AGREEMENT ) : null;
				if ( empty( $agreement['checkoutConsentAt'] ) || empty( $agreement['subscriptionId'] ) || $agreement['approvalExpiresAt'] > time()
					|| ! $order->has_status( [ 'on-hold', 'cancelled' ] ) || $order->get_date_paid()
					|| $order->get_meta( Subscription_Renewal_Orders::CHARGE ) ) return;
				$remote = Razorpay_Subscription_Provider::subscription( $agreement['subscriptionId'] );
				if ( 'expired' !== ( $remote['status'] ?? '' ) ) return;
				$agreement = self::bind( $order, $agreement, $remote );
				$agreement['providerStatus'] = 'expired';
				$order->update_meta_data( Subscription_Renewal_Orders::AGREEMENT, $agreement );
				$order->update_status( 'cancelled', __( 'Razorpay confirmed the subscription authorization expired.', 'dsa' ) );
			} );
		} catch ( \Throwable $error ) {
			wc_get_logger()->warning( 'The expired subscription checkout needs provider review.', [ 'source'=>'kiwe-subscriptions', 'order_id'=>$id ] );
		}
	}

	public function webhook( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! self::available() ) return new \WP_Error( 'kiwe_subscription_unavailable', 'Subscription processing is unavailable.', [ 'status'=>503 ] );
		$event = Razorpay_Subscription_Provider::verified_event( $request->get_body(), (string) $request->get_header( 'x-razorpay-signature' ) );
		if ( is_wp_error( $event ) ) return $event;
		$payload = $event['payload'];
		$payment = match ( $event['event'] ) {
			'payment.captured', 'subscription.charged' => $payload['payment']['entity']['id'] ?? '',
			'refund.processed' => $payload['refund']['entity']['payment_id'] ?? '',
			default => null,
		};
		try {
			if ( 'subscription.cancelled' === $event['event'] ) {
				self::cancelled( $payload['subscription']['entity']['id'] ?? '' );
			} elseif ( null !== $payment ) {
				if ( ! Razorpay_Subscription_Provider::id( $payment, 'pay' ) ) return new \WP_Error( 'kiwe_subscription_payment', 'The event does not identify a payment.', [ 'status'=>400 ] );
				self::reconcile( $payment );
			}
			return new \WP_REST_Response( [ 'received'=>true ] );
		} catch ( \DomainException $error ) {
			return new \WP_Error( 'kiwe_subscription_review', $error->getMessage(), [ 'status'=>409 ] );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'kiwe_subscription_retry', 'The provider payment is awaiting reconciliation. Retry the event.', [ 'status'=>503 ] );
		}
	}

	private static function cancelled( mixed $subscription_id ): void {
		if ( ! Razorpay_Subscription_Provider::id( $subscription_id, 'sub' ) ) throw new \DomainException( 'The event does not identify the cancelled subscription.' );
		$subscription = Razorpay_Subscription_Provider::subscription( $subscription_id );
		$id = self::native_order_id( $subscription );
		if ( ! $id ) return;
		if ( 'cancelled' !== ( $subscription['status'] ?? '' ) ) throw new \RuntimeException( 'Razorpay has not yet confirmed this cancellation.' );
		self::synchronized( 'order', $id, static function () use ( $id, $subscription ): void {
			$order = wc_get_order( $id );
			$agreement = $order ? $order->get_meta( Subscription_Renewal_Orders::AGREEMENT ) : null;
			if ( ! is_array( $agreement ) ) return;
			self::bind( $order, $agreement, $subscription );
			Membership_Directory::notify( $order, 'cancelled', 'confirmed' );
		} );
	}

	private static function native_order_id( array $subscription ): int {
		$id = $subscription['notes']['kiwe_order'] ?? '';
		return is_string( $id ) && preg_match( '/^[1-9][0-9]{0,14}$/D', $id ) ? (int) $id : 0;
	}

	private static function reconcile( string $payment_id, int $expected_order = 0, string $expected = '' ): void {
		$payment = Razorpay_Subscription_Provider::payment( $payment_id );
		$invoice_id = $payment['invoice_id'] ?? null;
		if ( ! $invoice_id ) {
			if ( $expected_order ) throw new \DomainException( 'This payment has no subscription invoice.' );
			return;
		}
		$invoice = Razorpay_Subscription_Provider::invoice( $invoice_id );
		$subscription_id = $invoice['subscription_id'] ?? null;
		if ( ! $subscription_id ) {
			if ( $expected_order ) throw new \DomainException( 'This invoice has no subscription.' );
			return;
		}
		$subscription = Razorpay_Subscription_Provider::subscription( $subscription_id );
		$id = self::native_order_id( $subscription );
		if ( $expected_order && $expected_order !== $id ) throw new \DomainException( 'This payment belongs to another order.' );
		if ( ! $id ) return;
		self::synchronized( 'order', $id, static function () use ( $id, $subscription, $payment, $invoice, $expected_order, $expected ): void {
			if ( ! self::available() ) throw new \RuntimeException( 'Subscription processing is unavailable.' );
			if ( $expected_order ) self::require_review( $id, $payment['id'], true, $expected );
			$order = wc_get_order( $id );
			$agreement = $order ? $order->get_meta( Subscription_Renewal_Orders::AGREEMENT ) : null;
			if ( ! is_array( $agreement ) ) return;
			$facts = self::charge( $agreement, $payment, $invoice );
			if ( $facts['initial'] && ! hash_equals( $agreement['fingerprint'], self::order_fingerprint( $order ) ) ) {
				throw new \DomainException( 'The initial order changed after the test approval was prepared.' );
			}
			$agreement = self::bind( $order, $agreement, $subscription );
			$receipt = Subscription_Renewal_Orders::apply( $order, $agreement, $facts );
			Membership_Directory::record_payment( $order, $receipt, $receipt->get_meta( Subscription_Renewal_Orders::CHARGE ) );
			if ( 'cancelled' === $agreement['providerStatus'] ) Membership_Directory::notify( $order, 'cancelled', 'confirmed' );
		} );
	}

	private static function charge( array $agreement, array $payment, array $invoice ): array {
		$starts = $invoice['billing_start'] ?? null;
		$ends = $invoice['billing_end'] ?? null;
		$initial = null === $starts && null === $ends;
		$amount = $initial ? $agreement['firstChargeMinor'] : $agreement['renewalMinor'];
		$refunded = $payment['amount_refunded'] ?? null;
		$paid_at = $invoice['paid_at'] ?? null;
		if ( 'paid' !== ( $invoice['status'] ?? '' ) || ! in_array( $payment['status'] ?? '', [ 'captured', 'refunded' ], true ) ) {
			throw new \RuntimeException( 'Razorpay has not confirmed the captured invoice yet.' );
		}
		if ( ! Razorpay_Subscription_Provider::id( $payment['order_id'] ?? null, 'order' )
			|| $payment['order_id'] !== ( $invoice['order_id'] ?? null ) || $payment['id'] !== ( $invoice['payment_id'] ?? null )
			|| 'INR' !== ( $invoice['currency'] ?? '' ) || 'INR' !== ( $payment['currency'] ?? '' )
			|| $amount !== ( $invoice['amount'] ?? null ) || $amount !== ( $invoice['amount_paid'] ?? null ) || $amount !== ( $payment['amount'] ?? null )
			|| ! is_int( $refunded ) || $refunded < 0 || $refunded > $amount
			|| ( 'refunded' === $payment['status'] && $refunded !== $amount )
			|| ! is_int( $paid_at ) || $paid_at < $agreement['createdAt'] || $paid_at > time() + 300 ) {
			throw new \DomainException( 'The captured amount or invoice details do not match the agreed terms.' );
		}
		if ( $initial ) {
			$starts = $paid_at;
			$ends = $agreement['firstRenewalAt'];
			if ( $starts >= $ends ) throw new \DomainException( 'The first charge is outside the agreed first term.' );
		} elseif ( ! is_int( $starts ) || ! is_int( $ends )
			|| $starts < $agreement['firstRenewalAt'] || $ends <= $starts
			|| $ends - $starts > 32 * DAY_IN_SECONDS * $agreement['intervalMonths']
			|| $starts >= self::add_months( $agreement['firstRenewalAt'], $agreement['cycles'] * $agreement['intervalMonths'] ) ) {
			throw new \DomainException( 'The invoice period does not match the approved renewal schedule.' );
		}
		return [
			'invoiceId'=>$invoice['id'], 'paymentId'=>$payment['id'], 'initial'=>$initial,
			'amountMinor'=>$amount, 'refundedMinor'=>$refunded, 'startsAt'=>$starts, 'endsAt'=>$ends,
		];
	}
}
