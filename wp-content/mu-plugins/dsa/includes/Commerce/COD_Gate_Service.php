<?php

namespace DSA\Commerce;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class COD_Gate_Service {
	private const REPUTATION_OPTION = 'dsa_cod_reputation';
	private const TOKEN_TRANSIENT   = 'dsa_cod_otp_';
	private const REPUTATION_META   = 'dsa_cod_reputation';

	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_gateways' ], 20 );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_classic_checkout' ], 20, 2 );
		add_action( 'woocommerce_store_api_validate_checkout', [ $this, 'validate_store_api_checkout' ], 20 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'flag_unverified_order' ], 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'flag_unverified_store_api_order' ], 20 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'record_completed' ], 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'record_cancelled' ], 20, 1 );
	}

	public function config(): array {
		$commerce = $this->settings->get( 'commerce', [] );
		$commerce = is_array( $commerce ) ? $commerce : [];
		$gate     = isset( $commerce['cod_gate'] ) && is_array( $commerce['cod_gate'] ) ? $commerce['cod_gate'] : [];
		$regain   = sanitize_key( $gate['regain'] ?? 'prepaid_success' );

		return [
			'enabled'                      => ! empty( $gate['enabled'] ),
			'strikes_to_block'             => max( 1, min( 10, (int) ( $gate['strikes_to_block'] ?? 1 ) ) ),
			'trusted_skip_after_completed' => max( 0, min( 50, (int) ( $gate['trusted_skip_after_completed'] ?? 1 ) ) ),
			'regain'                       => in_array( $regain, [ 'prepaid_success', 'never' ], true ) ? $regain : 'prepaid_success',
			'block_message'                => sanitize_text_field( $gate['block_message'] ?? __( 'Cash on delivery is not available for this order. Please choose a prepaid payment method.', 'dsa' ) ),
			'allow_unverified_on_failure'  => array_key_exists( 'allow_unverified_on_failure', $gate ) ? ! empty( $gate['allow_unverified_on_failure'] ) : true,
		];
	}

	public function is_enabled(): bool {
		return $this->config()['enabled'] && $this->woo_available();
	}

	public function filter_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ! isset( $gateways['cod'] ) || ! $this->is_enabled() ) {
			return $gateways;
		}

		if ( $this->is_blocked( $this->current_customer_anchor() ) ) {
			unset( $gateways['cod'] );
		}

		return $gateways;
	}

	public function validate_classic_checkout( $data, $errors = null ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$data   = is_array( $data ) ? $data : [];
		$posted = wp_unslash( $_POST );
		$method = sanitize_text_field( (string) ( $data['payment_method'] ?? ( $posted['payment_method'] ?? '' ) ) );
		$phone  = $this->normalize_phone( (string) ( $data['billing_phone'] ?? ( $posted['billing_phone'] ?? '' ) ) );

		if ( 'cod' !== $method ) {
			return;
		}

		$result = $this->evaluate( $phone );

		if ( 'block' === $result['action'] && $errors instanceof \WP_Error ) {
			$errors->add( 'dsa_cod_verify', $result['message'] );
		}
	}

	public function validate_store_api_checkout( $request ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$method = '';
		$phone  = '';

		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$method  = sanitize_text_field( (string) $request->get_param( 'payment_method' ) );
			$billing = (array) $request->get_param( 'billing_address' );
			$phone   = $this->normalize_phone( (string) ( $billing['phone'] ?? '' ) );
		}

		if ( '' === $phone && function_exists( 'WC' ) && WC()->customer ) {
			$phone = $this->normalize_phone( (string) WC()->customer->get_billing_phone() );
		}

		if ( 'cod' !== $method ) {
			return;
		}

		$result = $this->evaluate( $phone );

		if ( 'block' === $result['action'] ) {
			if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'dsa_cod_verify',
					$result['message'],
					409
				);
			}

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $result['message'], 'error' );
			}
		}
	}

	private function evaluate( string $phone ): array {
		$config = $this->config();

		if ( '' === $phone ) {
			return [ 'action' => 'allow', 'reason' => 'no_phone' ];
		}

		$anchor = $this->anchor_from_phone( $phone );
		$reputation = $this->reputation( $anchor );

		if ( $config['trusted_skip_after_completed'] > 0 && (int) $reputation['completed'] >= $config['trusted_skip_after_completed'] && ! $this->is_blocked( $anchor ) ) {
			return [ 'action' => 'allow', 'reason' => 'trusted' ];
		}

		if ( $this->phone_verified_for_current_user( $phone ) ) {
			return [ 'action' => 'allow', 'reason' => 'verified' ];
		}

		if ( $this->has_valid_otp_token( $anchor ) ) {
			return [ 'action' => 'allow', 'reason' => 'otp_confirmed' ];
		}

		if ( ! $this->phone_delivery_ready() ) {
			if ( $config['allow_unverified_on_failure'] ) {
				return [ 'action' => 'allow', 'reason' => 'delivery_unconfigured' ];
			}

			return [
				'action'  => 'block',
				'reason'  => 'delivery_unconfigured',
				'message' => $config['block_message'],
				'anchor'  => $anchor,
			];
		}

		return [
			'action'  => 'block',
			'reason'  => 'otp_required',
			'message' => __( 'Confirm your phone number to place a cash on delivery order.', 'dsa' ),
			'anchor'  => $anchor,
		];
	}

	public function flag_unverified_order( $order_id, $posted_data = [], $order = null ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
		$this->maybe_flag_order( $order );
	}

	public function flag_unverified_store_api_order( $order ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->maybe_flag_order( $order instanceof \WC_Order ? $order : null );
	}

	private function maybe_flag_order( $order ): void {
		if ( ! $order instanceof \WC_Order || 'cod' !== $order->get_payment_method() ) {
			return;
		}

		$phone  = $this->normalize_phone( (string) $order->get_billing_phone() );
		$anchor = '' !== $phone ? $this->anchor_from_phone( $phone ) : '';
		$verified = '' !== $anchor && (
			$this->has_valid_otp_token( $anchor )
			|| $this->phone_verified_for_current_user( $phone )
		);

		if ( $verified ) {
			$order->update_meta_data( '_dsa_cod_verified', 'yes' );
			$this->consume_otp_token( $anchor );
		} else {
			$order->update_meta_data( '_dsa_cod_unverified', 'yes' );
			$order->add_order_note( __( 'Kiwe: COD order placed without verified phone. Confirm by phone before dispatch.', 'dsa' ) );
		}

		$order->save();
	}

	public function record_completed( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$phone = $this->normalize_phone( (string) $order->get_billing_phone() );

		if ( '' === $phone ) {
			return;
		}

		$anchor = $this->anchor_from_phone( $phone );
		$rep    = $this->reputation( $anchor );
		$is_cod = 'cod' === $order->get_payment_method();

		$rep['completed']    = (int) $rep['completed'] + 1;
		$rep['last_outcome'] = 'completed';

		if ( $is_cod && 'yes' === (string) $order->get_meta( '_dsa_cod_verified', true ) ) {
			$rep['strikes'] = 0;
		} elseif ( ! $is_cod && 'prepaid_success' === $this->config()['regain'] ) {
			$rep['strikes'] = max( 0, (int) $rep['strikes'] - 1 );
		}

		$this->save_reputation( $anchor, $rep, (int) $order->get_customer_id() );
	}

	public function record_cancelled( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || 'cod' !== $order->get_payment_method() ) {
			return;
		}

		$phone = $this->normalize_phone( (string) $order->get_billing_phone() );

		if ( '' === $phone ) {
			return;
		}

		$anchor = $this->anchor_from_phone( $phone );
		$rep    = $this->reputation( $anchor );

		$rep['strikes']      = (int) $rep['strikes'] + 1;
		$rep['last_outcome'] = 'cancelled';

		$this->save_reputation( $anchor, $rep, (int) $order->get_customer_id() );
	}

	public static function token_key( string $anchor ): string {
		return self::TOKEN_TRANSIENT . md5( $anchor );
	}

	public static function confirm_phone_for_cod( string $phone_hmac_anchor ): void {
		if ( '' === $phone_hmac_anchor ) {
			return;
		}

		set_transient( self::token_key( $phone_hmac_anchor ), 1, 30 * MINUTE_IN_SECONDS );
	}

	public function reputation( string $anchor ): array {
		$defaults = [ 'completed' => 0, 'strikes' => 0, 'last_outcome' => '', 'updated' => 0 ];

		if ( '' === $anchor ) {
			return $defaults;
		}

		$map = get_option( self::REPUTATION_OPTION, [] );
		$map = is_array( $map ) ? $map : [];
		$row = isset( $map[ $anchor ] ) && is_array( $map[ $anchor ] ) ? $map[ $anchor ] : [];

		return [
			'completed'    => max( 0, (int) ( $row['completed'] ?? 0 ) ),
			'strikes'      => max( 0, (int) ( $row['strikes'] ?? 0 ) ),
			'last_outcome' => sanitize_key( $row['last_outcome'] ?? '' ),
			'updated'      => (int) ( $row['updated'] ?? 0 ),
		];
	}

	private function has_valid_otp_token( string $anchor ): bool {
		return '' !== $anchor && (bool) get_transient( self::token_key( $anchor ) );
	}

	private function consume_otp_token( string $anchor ): void {
		if ( '' !== $anchor ) {
			delete_transient( self::token_key( $anchor ) );
		}
	}

	private function save_reputation( string $anchor, array $rep, int $user_id = 0 ): void {
		if ( '' === $anchor ) {
			return;
		}

		$map = get_option( self::REPUTATION_OPTION, [] );
		$map = is_array( $map ) ? $map : [];
		$map[ $anchor ] = [
			'completed'    => max( 0, (int) $rep['completed'] ),
			'strikes'      => max( 0, (int) $rep['strikes'] ),
			'last_outcome' => sanitize_key( $rep['last_outcome'] ?? '' ),
			'updated'      => time(),
		];

		if ( count( $map ) > 5000 ) {
			uasort(
				$map,
				static function ( $a, $b ): int {
					return (int) ( $a['updated'] ?? 0 ) <=> (int) ( $b['updated'] ?? 0 );
				}
			);
			$map = array_slice( $map, -4000, null, true );
		}

		update_option( self::REPUTATION_OPTION, $map, false );

		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::REPUTATION_META, $map[ $anchor ] );
		}
	}

	private function is_blocked( string $anchor ): bool {
		if ( '' === $anchor ) {
			return false;
		}

		$config = $this->config();
		$rep    = $this->reputation( $anchor );

		return (int) $rep['strikes'] >= $config['strikes_to_block'];
	}

	private function current_customer_anchor(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}

		$phone = $this->normalize_phone( (string) WC()->customer->get_billing_phone() );

		return '' !== $phone ? $this->anchor_from_phone( $phone ) : '';
	}

	private function anchor_from_phone( string $phone ): string {
		if ( '' === $phone ) {
			return '';
		}

		if ( function_exists( 'pk_hmac' ) ) {
			return (string) pk_hmac( $phone );
		}

		return hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
	}

	private function normalize_phone( string $raw ): string {
		if ( function_exists( 'pk_normalize_phone' ) ) {
			$normalized = (string) pk_normalize_phone( $raw );
		} else {
			$normalized = preg_replace( '/[^0-9+]/', '', $raw );
		}

		$digits = preg_replace( '/[^0-9]/', '', (string) $normalized );

		return strlen( $digits ) >= 7 ? (string) $normalized : '';
	}

	private function phone_verified_for_current_user( string $phone ): bool {
		$user_id = get_current_user_id();

		if ( ! $user_id || '' === $phone || ! function_exists( 'pk_factor' ) || ! function_exists( 'pk_hmac' ) ) {
			return false;
		}

		$factor = pk_factor( $user_id, 'phone', pk_hmac( $phone ) );

		return is_array( $factor ) && 'verified' === ( $factor['status'] ?? '' );
	}

	private function phone_delivery_ready(): bool {
		return function_exists( 'pk_phone_provider_ready' ) ? (bool) pk_phone_provider_ready() : false;
	}

	private function woo_available(): bool {
		return function_exists( 'WC' ) && class_exists( '\\WC_Order' );
	}
}
