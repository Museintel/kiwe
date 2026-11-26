<?php
namespace DSA\Commerce;

use DSA\Access\Team_Workspace_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Classic Woo checkout owns the cart, order and customer. This service records the shopper's recurring consent. */
final class Subscription_Checkout_Service {
	private const ORDER_POINTER = '_kiwe_subscription_checkout_order_v1';
	private array $accepted = [];
	private string $lock = '';

	public static function enabled(): bool {
		return defined( 'KIWE_RAZORPAY_CHECKOUT_TEST_PLANS' ) && is_array( KIWE_RAZORPAY_CHECKOUT_TEST_PLANS )
			&& [] !== KIWE_RAZORPAY_CHECKOUT_TEST_PLANS && Subscription_Billing_Service::available();
	}

	public static function plan_id( array $quote ): string {
		if ( ! self::enabled() ) return '';
		$minor = $quote['renewalMinor'] ?? null;
		$months = $quote['intervalMonths'] ?? null;
		if ( ! is_int( $minor ) || $minor < 100 || ! is_int( $months ) || $months < 1 || $months > 12 ) return '';
		$id = KIWE_RAZORPAY_CHECKOUT_TEST_PLANS[ $minor . ':' . $months ] ?? '';
		return Razorpay_Subscription_Provider::id( $id, 'plan' ) ? $id : '';
	}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout' ], 100, 2 );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'account_status' ] );
		if ( ! self::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'checkout_return_route' ] );
		add_action( 'woocommerce_thankyou_' . Subscription_Renewal_Orders::GATEWAY, [ $this, 'render_authorization' ] );
		add_filter( 'woocommerce_thankyou_order_received_text', static function ( $text, $order ) {
			return $order instanceof \WC_Order && Subscription_Renewal_Orders::GATEWAY === $order->get_payment_method()
				&& $order->has_status( 'on-hold' ) ? __( 'Your subscription payment is awaiting confirmation.', 'dsa' ) : $text;
		}, 20, 2 );
		add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'available_gateways' ], 100 );
		add_filter( 'woocommerce_cart_needs_payment', [ $this, 'needs_payment' ], 100 );
	}

	public function register_gateway( array $gateways ): array {
		$gateways[] = new Subscription_Checkout_Gateway( $this );
		return $gateways;
	}

	public function available_gateways( array $gateways ): array {
		$id = Subscription_Renewal_Orders::GATEWAY;
		if ( ! is_checkout_pay_page() && $this->recurring_cart() ) return array_intersect_key( $gateways, [ $id=>true ] );
		unset( $gateways[ $id ] );
		return $gateways;
	}

	private function recurring_cart(): bool {
		foreach ( WC()->cart?->get_cart() ?? [] as $line ) {
			if ( null !== Subscription_Product_Service::terms( $line['data'] ) ) return true;
		}
		return false;
	}

	private static function customer(): int {
		$id = get_current_user_id();
		return $id && current_user_can( 'read' ) && ! Team_Workspace_Service::suspended( $id )
			&& ( ! is_multisite() || is_user_member_of_blog( $id ) ) ? $id : 0;
	}

	public static function owns_order( \WC_Order $order ): bool {
		$id = self::customer();
		return $id > 0 && $id === $order->get_customer_id() && current_user_can( 'view_order', $order->get_id() );
	}

	public static function remember_order( \WC_Order $order ): void {
		$customer = $order->get_customer_id();
		$id = $order->get_id();
		update_user_meta( $customer, self::ORDER_POINTER, $id );
		if ( $id !== (int) get_user_meta( $customer, self::ORDER_POINTER, true ) ) {
			throw new \RuntimeException( 'WooCommerce could not record the pending subscription checkout.' );
		}
	}

	private static function pending_order( int $customer ): ?\WC_Order {
		$id = (int) get_user_meta( $customer, self::ORDER_POINTER, true );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order || ! self::owns_order( $order ) ) return null;
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		$charge = $order->get_meta( Subscription_Renewal_Orders::CHARGE );
		if ( empty( $agreement['checkoutConsentAt'] ) || ( $charge && $order->get_date_paid() )
			|| ( 'expired' === $agreement['providerStatus'] && $order->has_status( 'cancelled' ) ) ) return null;
		return $order;
	}

	/** The already-created authorization is private to the shopper who owns its WooCommerce order. */
	public static function pending_approval_card( int $product_id = 0, string $group = '' ): array {
		$customer = self::customer();
		if ( ! $customer || ! self::enabled() ) return [];
		$order = self::pending_order( $customer );
		if ( ! $order || ! $order->has_status( 'on-hold' ) || $order->get_date_paid()
			|| Subscription_Renewal_Orders::GATEWAY !== $order->get_payment_method()
			|| ! Subscription_Renewal_Orders::owned_by( $customer, $order ) ) return [];
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		if ( $product_id && ! in_array( $product_id, array_column( $agreement['lines'] ?? [], 'productId' ), true )
			&& ( '' === $group || ! in_array( $group, array_column( $agreement['lines'] ?? [], 'membershipGroup' ), true ) ) ) return [];
		$can_continue = '' !== self::continuation( $agreement );
		$closed = (int) ( $agreement['approvalExpiresAt'] ?? 0 ) > 0 && $agreement['approvalExpiresAt'] <= time();
		return [
			'orderId'=>$order->get_id(),
			'name'=>Membership_Directory::plan_names( $order ) ?: __( 'Subscription', 'dsa' ),
			'label'=>$can_continue ? __( 'Continue subscription approval', 'dsa' ) : __( 'View subscription order', 'dsa' ),
			'status'=>$can_continue ? __( 'Awaiting payment authorization', 'dsa' )
				: ( $closed ? __( 'Authorization expired', 'dsa' ) : __( 'Awaiting confirmation', 'dsa' ) ),
			'url'=>esc_url_raw( $can_continue ? $order->get_checkout_order_received_url() : $order->get_view_order_url() ),
		];
	}

	private function current_quote(): array {
		$cart = WC()->cart;
		$quote = Subscription_Quote_Service::cart_snapshot( $cart );
		if ( $quote['firstChargeMinor'] < 100 || $quote['recurringLines'] > 50 || ! self::plan_id( $quote ) ) {
			throw new \DomainException( __( 'This combination of subscription items is unavailable. Review your cart or contact the store.', 'dsa' ) );
		}
		return $quote;
	}

	private function consent_token( array $quote, int $customer ): string {
		return hash_hmac( 'sha256', serialize( [
			$customer, WC()->cart->get_cart_hash(), Subscription_Quote_Service::terms_digest( $quote, WC()->cart ),
			self::plan_id( $quote ), Subscription_Billing_Service::cycles( $quote['intervalMonths'] ),
		] ), wp_salt( 'nonce' ) );
	}

	public function review(): ?array {
		if ( ! $this->recurring_cart() ) return null;
		if ( ! self::enabled() ) return [ 'message'=>__( 'Subscription payments are currently unavailable.', 'dsa' ) ];
		$customer = self::customer();
		if ( ! $customer ) return [ 'message'=>__( 'Sign in or create an account to buy a subscription.', 'dsa' ), 'signIn'=>true ];
		try {
			$quote = $this->current_quote();
			Subscription_Quote_Service::assert_groups_available( $customer, $quote['lines'] );
			$token = $this->consent_token( $quote, $customer );
		} catch ( \DomainException $error ) {
			return [ 'message'=>$error->getMessage() ];
		} catch ( \RuntimeException $error ) {
			return [ 'message'=>__( 'Review the subscription items in your cart before continuing.', 'dsa' ) ];
		}
		$summary = sprintf(
			/* translators: 1: first charge, 2: renewal charge, 3: number of months in the first term, 4: months between renewals, 5: renewal count. */
			__( '%1$s today. After the first %3$s, %2$s every %4$s for %5$s automatic renewals.', 'dsa' ),
			wc_price( $quote['firstChargeMinor'] / 100, [ 'currency'=>'INR' ] ),
			wc_price( $quote['renewalMinor'] / 100, [ 'currency'=>'INR' ] ),
			self::months( $quote['firstTermMonths'] ), self::months( $quote['intervalMonths'] ),
			number_format_i18n( Subscription_Billing_Service::CYCLES )
		);
		return [
			'quote'=>$token,
			'summary'=>html_entity_decode( wp_strip_all_tags( $summary ), ENT_QUOTES, 'UTF-8' ),
			'sharedAgreement'=>$quote['recurringLines'] > 1,
		];
	}

	public function payment_fields(): void {
		$review = $this->review() ?? [];
		if ( ! empty( $review['message'] ) ) {
			echo '<p>' . esc_html( $review['message'] );
			if ( ! empty( $review['signIn'] ) ) {
				echo ' <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html__( 'My account', 'dsa' ) . '</a>';
			}
			echo '</p>';
		}
		if ( empty( $review['quote'] ) ) return;
		echo '<p>' . esc_html( $review['summary'] ) . '</p>';
		if ( $review['sharedAgreement'] ) {
			echo '<p>' . esc_html__( 'These memberships share one billing agreement. Buy them separately if you want to manage renewals independently.', 'dsa' ) . '</p>';
		}
		// Woo restores controls by ID after fragment swaps. A changed quote needs a new unchecked control.
		printf( '<input type="hidden" name="kiwe_subscription_quote" value="%1$s"><label for="kiwe-consent-%1$s"><input type="checkbox" id="kiwe-consent-%1$s" name="kiwe_subscription_consent" value="yes"> %2$s</label>',
			esc_attr( $review['quote'] ), esc_html__( 'I agree to the first charge and automatic renewals through Razorpay.', 'dsa' ) );
	}

	private static function months( int $count ): string {
		return sprintf( _n( '%s month', '%s months', $count, 'dsa' ), number_format_i18n( $count ) );
	}

	public function validate_checkout( array $data, \WP_Error $errors ): void {
		if ( ! $this->recurring_cart() ) return;
		if ( ! self::enabled() || Subscription_Renewal_Orders::GATEWAY !== $data['payment_method'] ) {
			$errors->add( 'kiwe_subscription_method', __( 'Use Razorpay subscription checkout for recurring items.', 'dsa' ) );
			return;
		}
		$customer = self::customer();
		if ( ! $customer ) {
			$errors->add( 'kiwe_subscription_account', __( 'Sign in to an active account before buying a subscription.', 'dsa' ) );
			return;
		}
		if ( $errors->has_errors() ) return;

		try {
			$quote = $this->current_quote();
			$submitted = $_POST['kiwe_subscription_quote'] ?? '';
			if ( ! is_string( $submitted ) || ! hash_equals( $this->consent_token( $quote, $customer ), wp_unslash( $submitted ) )
				|| 'yes' !== ( $_POST['kiwe_subscription_consent'] ?? '' ) ) {
				$errors->add( 'kiwe_subscription_consent', __( 'Review the current total and renewal terms, then agree to continue.', 'dsa' ) );
				return;
			}
			$this->lock = Subscription_Billing_Service::lock( 'shopper-checkout', $customer );
			if ( $pending = self::pending_order( $customer ) ) {
				$errors->add( 'kiwe_subscription_pending', sprintf(
					/* translators: %s: link to the customer's existing WooCommerce order. */
					__( 'A subscription order already awaits approval. %s', 'dsa' ),
					'<a href="' . esc_url( $pending->get_view_order_url() ) . '">' . esc_html__( 'View your order', 'dsa' ) . '</a>'
				) );
				$this->release_checkout();
				return;
			}
			Subscription_Quote_Service::assert_groups_available( $customer, $quote['lines'] );
			$waiting = absint( WC()->session->get( 'order_awaiting_payment' ) );
			if ( $waiting ) {
				$order = wc_get_order( $waiting );
				if ( ! $order || ! self::owns_order( $order ) || Subscription_Renewal_Orders::GATEWAY !== $order->get_payment_method()
					|| $order->get_transaction_id() || $order->get_meta( Subscription_Renewal_Orders::AGREEMENT ) ) {
					WC()->session->__unset( 'order_awaiting_payment' );
				}
			}
			$this->accepted = [ 'customer'=>$customer, 'terms'=>Subscription_Quote_Service::terms_digest( $quote, WC()->cart ) ];
			add_action( 'shutdown', [ $this, 'release_checkout' ], 0 );
		} catch ( \DomainException $error ) {
			$this->release_checkout();
			$errors->add( 'kiwe_subscription_plan', $error->getMessage() );
		} catch ( \RuntimeException $error ) {
			$this->release_checkout();
			$errors->add( 'kiwe_subscription_busy', __( 'Checkout could not confirm these subscription terms. Review your cart and try again.', 'dsa' ) );
		}
	}

	public function release_checkout(): void {
		if ( $this->lock ) Subscription_Billing_Service::unlock( $this->lock );
		$this->lock = '';
		$this->accepted = [];
	}

	/** Existing subscription orders stay excluded from Woo's ordinary order-pay flow. */
	public function needs_payment( bool $needs_payment ): bool {
		return $needs_payment || [] !== $this->accepted;
	}

	public function process_payment( int $id ): array {
		if ( ! $this->lock || self::customer() !== ( $this->accepted['customer'] ?? 0 ) ) return [ 'result'=>'failure' ];
		try {
			Subscription_Billing_Service::open_checkout( $id, $this->accepted['terms'] );
		} catch ( \Throwable $error ) {
			// The provider may have accepted a timed-out request. Inspect the saved order before allowing a retry.
			wc_get_logger()->warning( 'The subscription checkout returned an incomplete result.', [ 'source'=>'kiwe-subscriptions', 'order_id'=>$id ] );
		} finally {
			$this->release_checkout();
		}
		$order = wc_get_order( $id );
		$agreement = $order ? $order->get_meta( Subscription_Renewal_Orders::AGREEMENT ) : null;
		if ( ! $order || ! self::owns_order( $order ) || empty( $agreement['checkoutConsentAt'] ) || ! $order->has_status( 'on-hold' ) ) {
			wc_add_notice( __( 'Checkout could not start the subscription. Review the current terms or contact the store.', 'dsa' ), 'error' );
			return [ 'result'=>'failure' ];
		}
		WC()->cart->empty_cart();
		return [ 'result'=>'success', 'redirect'=>$order->get_checkout_order_received_url() ];
	}

	private static function continuation( array $agreement ): string {
		if ( ! self::enabled() || $agreement['approvalExpiresAt'] <= time()
			|| in_array( $agreement['providerStatus'], [ 'cancelled', 'expired' ], true ) ) return '';
		return Razorpay_Subscription_Provider::authorization_url( $agreement['authorizationUrl'] ?? '' );
	}

	public function checkout_return_route(): void {
		register_rest_route( 'dsa/v1', '/checkout/subscription/(?P<id>[1-9][0-9]*)', [
			'methods'=>'POST',
			'permission_callback'=>static fn( $request ) => is_user_logged_in() && \DSA\Utilities\Origin_Checker::mutation_allowed( $request ),
			'callback'=>static function ( \WP_REST_Request $request ) {
				$order = wc_get_order( (int) $request['id'] );
				try {
					if ( $order instanceof \WC_Order && Subscription_Billing_Service::confirm_checkout_payment( $order,
						(string) $request['payment'], (string) $request['subscription'], (string) $request['signature'] ) ) {
						$response = rest_ensure_response( [ 'returnUrl'=>$order->get_checkout_order_received_url() ] );
						$response->header( 'Cache-Control', 'private, no-store' );
						return $response;
					}
				} catch ( \DomainException $error ) {
					// Keep order and provider mismatch details private.
				}
				return new \WP_Error( 'kiwe_subscription_confirmation', __( 'We could not verify this authorization. Check your order before trying again.', 'dsa' ), [ 'status'=>403 ] );
			},
		] );
	}

	public function render_authorization( int $id ): void {
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || ! self::owns_order( $order ) || $order->is_paid() || ! $order->has_status( 'on-hold' ) ) return;
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		if ( empty( $agreement['checkoutConsentAt'] ) || ! self::continuation( $agreement ) ) {
			echo '<p>' . esc_html__( 'Your subscription is awaiting confirmation.', 'dsa' ) . '</p>';
			return;
		}
		$options = [
			'key'=>Razorpay_Subscription_Provider::checkout_key(), 'subscription_id'=>$agreement['subscriptionId'],
			'name'=>get_bloginfo( 'name' ), 'description'=>Membership_Directory::plan_names( $order ),
			'prefill'=>[ 'name'=>$order->get_formatted_billing_full_name(), 'email'=>$order->get_billing_email(), 'contact'=>$order->get_billing_phone() ],
		];
		wp_enqueue_script( 'kiwe-subscription-razorpay', 'https://checkout.razorpay.com/v1/checkout.js', [], null, true );
		$script = <<<'JS'
(function () {
	const button = document.getElementById('kiwe-subscription-authorize');
	const message = document.getElementById('kiwe-subscription-confirmation');
	if (!button || !message) return;
	button.addEventListener('click', function () {
		if (typeof window.Razorpay !== 'function') { message.textContent = 'Razorpay could not load. Try again.'; return; }
		button.disabled = true;
		const checkout = new window.Razorpay(Object.assign({}, kiweSubscription.options, {
			modal: { ondismiss: function () { button.disabled = false; } },
			handler: async function (payment) {
				message.textContent = 'Confirming your subscription…';
				try {
					const response = await fetch(kiweSubscription.endpoint, {
						method: 'POST', credentials: 'same-origin',
						headers: {'Content-Type':'application/json','X-WP-Nonce':kiweSubscription.nonce,'X-Kiwe-Mutation':'1'},
						body: JSON.stringify({payment: payment.razorpay_payment_id, subscription: payment.razorpay_subscription_id, signature: payment.razorpay_signature})
					});
					const result = await response.json();
					if (response.ok && result.returnUrl && new URL(result.returnUrl, location.href).origin === location.origin) {
						location.replace(result.returnUrl);
						return;
					}
				} catch (error) {}
				message.textContent = 'Check this order again before attempting another payment.';
			}
		}));
		checkout.open();
	});
})();
JS;
		wp_add_inline_script( 'kiwe-subscription-razorpay', 'const kiweSubscription = ' . wp_json_encode( [
			'options'=>$options, 'endpoint'=>rest_url( 'dsa/v1/checkout/subscription/' . $id ), 'nonce'=>wp_create_nonce( 'wp_rest' ),
		], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ";\n" . $script );
		echo '<p><button id="kiwe-subscription-authorize" type="button" class="button">' . esc_html__( 'Authorize subscription with Razorpay', 'dsa' ) . '</button></p>';
		echo '<p id="kiwe-subscription-confirmation" aria-live="polite"></p>';
	}

	public function account_status( \WC_Order $order ): void {
		if ( ! self::owns_order( $order ) || ! $order->has_status( 'on-hold' ) ) return;
		$agreement = $order->get_meta( Subscription_Renewal_Orders::AGREEMENT );
		if ( empty( $agreement['checkoutConsentAt'] ) ) return;
		$url = self::continuation( $agreement );
		if ( $url ) {
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $order->get_checkout_order_received_url() ), esc_html__( 'Continue subscription approval', 'dsa' ) );
			return;
		}
		echo '<p>' . esc_html__( 'Your subscription order is recorded. Check back here for approval or contact the store about this order.', 'dsa' ) . '</p>';
	}
}
