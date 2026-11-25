<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Optional presentation for WooCommerce's existing classic checkout form. */
final class Checkout_Experience_Service {
	public const OPTION = 'kiwe_store_checkout_experience_v1';

	public static function enabled(): bool {
		return '1' === (string) get_option( self::OPTION, '0' );
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'template_redirect', [ $this, 'restore_shortcode_coupon' ] );
		add_filter( 'body_class', [ $this, 'body_classes' ] );
		add_action( 'woocommerce_checkout_before_order_review', [ $this, 'payment_choices' ] );
	}

	private static function checkout_page(): bool {
		return is_checkout() && ! is_wc_endpoint_url();
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
		wp_enqueue_script( 'kiwe-checkout-experience', $url . 'js/checkout-experience.js', [ 'jquery' ], (string) filemtime( $base . 'js/checkout-experience.js' ), true );
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
