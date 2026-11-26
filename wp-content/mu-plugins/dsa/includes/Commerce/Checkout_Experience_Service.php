<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Optional presentation for WooCommerce's existing classic checkout form. */
final class Checkout_Experience_Service {
	public const OPTION = 'kiwe_store_checkout_experience_v1';

	public static function enabled(): bool {
		return in_array( (string) get_option( self::OPTION, '0' ), [ '1', 'bricks' ], true );
	}

	public static function authored(): bool {
		return 'bricks' === get_option( self::OPTION, '0' );
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
		add_filter( 'body_class', [ $this, 'body_classes' ] );
		if ( self::authored() ) {
			add_action( 'template_redirect', [ $this, 'protect_review_page' ] );
			add_filter( 'pre_do_shortcode_tag', [ $this, 'render_authored_template' ], 15, 2 );
		} else {
			add_action( 'template_redirect', [ $this, 'restore_shortcode_coupon' ] );
			add_action( 'woocommerce_checkout_before_order_review', [ $this, 'payment_choices' ] );
		}
	}

	private static function checkout_page(): bool {
		return is_checkout() && ! is_wc_endpoint_url();
	}

	public function protect_review_page(): void {
		if ( self::checkout_page() ) nocache_headers();
	}

	public function render_authored_template( $output, string $shortcode ) {
		if ( false !== $output || 'woocommerce_checkout' !== $shortcode || ! self::checkout_page()
			|| ! \DSA\Environment::should_render_frontend() || ! is_callable( [ \Bricks\Woocommerce::class, 'get_template_data_by_type' ] ) ) return $output;
		$template = \Bricks\Woocommerce::get_template_data_by_type( 'wc_form_checkout', false );
		if ( ! $template || ! defined( 'BRICKS_DB_PAGE_CONTENT' ) ) return $output;
		$elements = get_post_meta( $template, BRICKS_DB_PAGE_CONTENT, true );
		foreach ( is_array( $elements ) ? $elements : [] as $element ) {
			foreach ( $element['settings']['_attributes'] ?? [] as $attribute ) {
				if ( 'data-kiwe-checkout' === ( $attribute['name'] ?? '' ) ) {
					$rendered = \Bricks\Woocommerce::get_template_data_by_type( 'wc_form_checkout' );
					return is_string( $rendered ) && '' !== trim( $rendered ) ? $rendered : $output;
				}
			}
		}
		return $output;
	}

	/** A cached Bricks document contains only an address placeholder. */
	public static function binding( string $name ): string {
		return match ( $name ) {
			'kiwe_checkout_address' => '<span data-kiwe-checkout-value="address">' . esc_html__( 'Add your address', 'dsa' ) . '</span>',
			'kiwe_checkout_total'   => '<span data-kiwe-checkout-value="total">–</span>',
			'kiwe_checkout_renewal' => '<span data-kiwe-checkout-value="renewal"></span>',
			default => '',
		};
	}

	public static function review( Checkout_Service $checkout ): array {
		if ( ! self::authored() || ! $checkout->available() ) return [ 'available'=>false ];

		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );
		$cart = WC()->cart;
		$cart->calculate_totals();
		$contract = $checkout->contract();
		$address_type = $contract['needsShipping'] && $contract['shipToDifferent'] ? 'shipping' : 'billing';
		$values = $contract['values'];
		$address = self::address_text( $values, $address_type );
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$subscription = new Subscription_Checkout_Service();
		$terms = $subscription->review();
		$methods = [];
		if ( ! $cart->is_empty() ) {
			if ( null !== $terms ) {
				if ( ! empty( $terms['quote'] ) && isset( $gateways[ Subscription_Renewal_Orders::GATEWAY ] ) ) $methods['subscription'] = Subscription_Renewal_Orders::GATEWAY;
			} elseif ( ! $cart->needs_payment() ) {
				$methods['free'] = '';
			} else {
				if ( isset( $gateways['razorpay'] ) ) {
					$methods['razorpay'] = 'razorpay';
					if ( 'INR' === get_woocommerce_currency() ) {
						$methods += array_fill_keys( Razorpay_Checkout_Service::configured_choices(), 'razorpay' );
					}
				}
				if ( isset( $gateways['cod'] ) ) $methods['cod'] = 'cod';
			}
		}
		foreach ( $contract['groups']['account'] ?? [] as $field ) {
			if ( 'password' === $field['type'] ) unset( $values[ $field['key'] ] );
		}
		return [
			'available'=>true, 'empty'=>$cart->is_empty(), 'address'=>$address,
			'total'=>self::plain( $cart->get_total() ), 'renewal'=>$terms['summary'] ?? $terms['message'] ?? '',
			'sharedAgreement'=>! empty( $terms['sharedAgreement'] ),
			'subscriptionQuote'=>$terms['quote'] ?? '', 'methods'=>empty( $methods ) ? (object) [] : $methods,
			'fields'=>$values, 'shipping'=>array_values( (array) WC()->session->get( 'chosen_shipping_methods', [] ) ),
			'termsRequired'=>wc_terms_and_conditions_checkbox_enabled(),
			'coupons'=>wc_coupons_enabled(), 'checkoutUrl'=>\WC_AJAX::get_endpoint( 'checkout' ),
			'couponUrl'=>\WC_AJAX::get_endpoint( 'apply_coupon' ),
			'checkoutNonce'=>wp_create_nonce( 'woocommerce-process_checkout' ), 'couponNonce'=>wp_create_nonce( 'apply-coupon' ),
		];
	}

	private static function address_text( array $values, string $type ): string {
		if ( empty( $values[ $type . '_address_1' ] ) ) return '';
		$address = [];
		foreach ( [ 'address_1','address_2','city','state','postcode','country' ] as $field ) {
			$address[ $field ] = $values[ $type . '_' . $field ] ?? '';
		}
		return self::plain( str_replace( '<br/>', ', ', WC()->countries->get_formatted_address( $address, '<br/>' ) ) );
	}

	private static function plain( string $value ): string {
		return html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
	}

	public function restore_shortcode_coupon(): void {
		if ( ! self::checkout_page() || has_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form' ) ) return;
		$checkout = get_post_field( 'post_content', wc_get_page_id( 'checkout' ) );
		if ( has_shortcode( (string) $checkout, 'woocommerce_checkout' ) ) {
			add_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
		}
	}

	public function assets(): void {
		if ( ! self::checkout_page() ) return;
		$base = dirname( __DIR__, 2 ) . '/assets/';
		$url = plugin_dir_url( dirname( __DIR__, 2 ) . '/dsa.php' ) . 'assets/';
		wp_enqueue_style( 'kiwe-checkout-experience', $url . 'css/checkout-experience.css', [], (string) filemtime( $base . 'css/checkout-experience.css' ) );
		$dependencies = self::authored() ? [ 'dsa-surface' ] : [ 'jquery' ];
		wp_enqueue_script( 'kiwe-checkout-experience', $url . 'js/checkout-experience.js', $dependencies, (string) filemtime( $base . 'js/checkout-experience.js' ), true );
	}

	public function body_classes( array $classes ): array {
		if ( self::checkout_page() ) $classes[] = 'kiwe-checkout-experience';
		return $classes;
	}

	public static function address_summary(): void {
		?>
		<section class="kiwe-checkout-address" aria-labelledby="kiwe-checkout-address-title">
			<h2 id="kiwe-checkout-address-title"><?php esc_html_e( 'Your address', 'dsa' ); ?></h2>
			<p data-kiwe-checkout-address aria-live="polite"><?php esc_html_e( 'Enter an address to review it here.', 'dsa' ); ?></p>
			<a href="#customer_details" data-kiwe-checkout-details><?php esc_html_e( 'Add or edit address', 'dsa' ); ?></a>
		</section>
		<?php
	}

	public function payment_choices(): void {
		$labels = [];
		if ( Razorpay_Checkout_Service::can_choose() ) {
			$labels = [
				'upi' => __( 'UPI', 'dsa' ),
				'card' => __( 'Card', 'dsa' ),
				'razorpay' => __( 'More Razorpay methods', 'dsa' ),
			];
		}
		$labels['cod'] = __( 'Cash on delivery', 'dsa' );
		?>
		<fieldset class="kiwe-checkout-payment-choices" data-kiwe-payment-choices hidden>
			<legend><?php esc_html_e( 'Quick payment choices', 'dsa' ); ?></legend>
			<input type="hidden" name="kiwe_checkout_method" value="">
			<?php foreach ( $labels as $method => $label ) : ?>
				<button type="button" data-kiwe-payment-choice="<?php echo esc_attr( $method ); ?>" aria-pressed="false" hidden><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}
}
