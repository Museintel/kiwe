<?php
namespace DSA\Commerce;

use DSA\Apps\Razorpay_App_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Integrates method selection into the qualified official gateway's normal receipt. */
final class Razorpay_Checkout_Service {
	public const CHOICE_SETTING = 'kiwe_standard_method_choices';
	private const EXTRA_CHOICES = 'kiwe_additional_standard_methods';
	public const ORDER_META = '_kiwe_razorpay_checkout_method';
	private const STANDARD_CHOICES = [ 'upi', 'card', 'netbanking', 'wallet' ];
	private const QUALIFIED_VERSION = '4.8.9';

	public function register(): void {
		add_filter( 'woocommerce_settings_api_form_fields_razorpay', [ $this, 'gateway_fields' ] );
		add_filter( 'woocommerce_payment_gateways', [ $this, 'gateways' ], 20 );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'record_choice' ], 10, 2 );
	}

	public static function can_choose(): bool {
		static $qualified = null;
		if ( ! Checkout_Experience_Service::enabled() || ! class_exists( '\WC_Razorpay', false ) ) return false;
		$settings = get_option( 'woocommerce_razorpay_settings', [] );
		if ( 'yes' !== ( $settings[self::CHOICE_SETTING] ?? '' ) || 'yes' !== ( $settings['enabled'] ?? '' ) ) return false;
		if ( null === $qualified ) {
			$file = WP_PLUGIN_DIR . '/' . Razorpay_App_Service::PLUGIN;
			$qualified = is_readable( $file ) && self::QUALIFIED_VERSION === ( get_file_data( $file, [ 'version' => 'Version' ] )['version'] ?? '' );
		}
		return $qualified;
	}

	public function gateway_fields( array $fields ): array {
		$fields[self::CHOICE_SETTING] = [
			'title' => __( 'Kiwe UPI and card choices', 'dsa' ),
			'label' => __( 'Offer UPI and card on Kiwe checkout', 'dsa' ),
			'type' => 'checkbox',
			'default' => 'no',
			'description' => __( 'Enable this for standard Razorpay Checkout after verifying both methods in your Razorpay account.', 'dsa' ),
		];
		$fields[self::EXTRA_CHOICES] = [
			'title' => __( 'Additional Kiwe payment choices', 'dsa' ),
			'type' => 'multiselect',
			'options' => [ 'netbanking'=>__( 'Net banking', 'dsa' ), 'wallet'=>__( 'Wallet', 'dsa' ) ],
			'default' => [],
			'description' => __( 'Select methods after verifying them in your Razorpay account.', 'dsa' ),
		];
		return $fields;
	}

	public static function configured_choices(): array {
		if ( ! self::can_choose() ) return [];
		$settings = get_option( 'woocommerce_razorpay_settings', [] );
		return array_merge( [ 'upi', 'card' ], array_intersect( [ 'netbanking', 'wallet' ], (array) ( $settings[self::EXTRA_CHOICES] ?? [] ) ) );
	}

	public function gateways( array $gateways ): array {
		if ( ! self::can_choose() ) return $gateways;
		foreach ( $gateways as $index => $gateway ) {
			if ( 'WC_Razorpay' === $gateway ) $gateways[$index] = Razorpay_Checkout_Gateway::class;
		}
		return $gateways;
	}

	public function record_choice( \WC_Order $order, array $checkout ): void {
		$choice = $_POST['kiwe_checkout_method'] ?? '';
		if ( 'razorpay' === ( $checkout['payment_method'] ?? '' ) && self::can_choose()
			&& 'INR' === $order->get_currency() && in_array( $choice, self::configured_choices(), true ) ) {
			$order->update_meta_data( self::ORDER_META, $choice );
		} else {
			$order->delete_meta_data( self::ORDER_META );
		}
	}

	public static function receipt_method( ?\WC_Order $order ): string {
		if ( ! $order || ! $order->needs_payment() || 'razorpay' !== $order->get_payment_method()
			|| 'INR' !== $order->get_currency() || 'all' === ( $_GET['kiwe_payment_methods'] ?? '' ) ) return '';
		$method = $order->get_meta( self::ORDER_META );
		return in_array( $method, self::STANDARD_CHOICES, true ) ? $method : '';
	}

	public static function display_config( string $method ): array {
		return [
			'blocks' => [
				'kiwe_method' => [
					'name' => match ( $method ) {
						'upi' => __( 'UPI', 'dsa' ),
						'netbanking' => __( 'Net banking', 'dsa' ),
						'wallet' => __( 'Wallet', 'dsa' ),
						default => __( 'Card', 'dsa' ),
					},
					'instruments' => [ [ 'method' => $method ] ],
				],
			],
			'sequence' => [ 'block.kiwe_method' ],
			'preferences' => [ 'show_default_blocks' => false ],
		];
	}
}
