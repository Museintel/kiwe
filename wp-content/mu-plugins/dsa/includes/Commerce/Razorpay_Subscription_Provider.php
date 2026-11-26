<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Razorpay's API and signed event boundary for the Test Mode subscription pilot. */
final class Razorpay_Subscription_Provider {
	private const API = 'https://api.razorpay.com/v1/';

	public static function available(): bool {
		if ( ! defined( 'KIWE_RAZORPAY_SUBSCRIPTIONS_TEST' ) || true !== KIWE_RAZORPAY_SUBSCRIPTIONS_TEST
			|| ! in_array( wp_get_environment_type(), [ 'local', 'staging' ], true ) ) return false;
		$settings = self::settings();
		return defined( 'KIWE_RAZORPAY_SUBSCRIPTIONS_ACCOUNT' ) && self::id( KIWE_RAZORPAY_SUBSCRIPTIONS_ACCOUNT, 'acc' )
			&& defined( 'KIWE_RAZORPAY_SUBSCRIPTIONS_WEBHOOK_SECRET' ) && is_string( KIWE_RAZORPAY_SUBSCRIPTIONS_WEBHOOK_SECRET )
			&& strlen( KIWE_RAZORPAY_SUBSCRIPTIONS_WEBHOOK_SECRET ) >= 24
			&& is_string( $settings['key_id'] ?? null ) && preg_match( '/^rzp_test_[A-Za-z0-9]+$/D', $settings['key_id'] )
			&& is_string( $settings['key_secret'] ?? null ) && '' !== $settings['key_secret'];
	}

	private static function settings(): array {
		$settings = get_option( 'woocommerce_razorpay_settings', [] );
		return is_array( $settings ) ? $settings : [];
	}

	public static function checkout_key(): string {
		return self::available() ? (string) self::settings()['key_id'] : '';
	}

	public static function verifies_checkout( string $payment_id, string $subscription_id, string $signature ): bool {
		if ( ! self::available() || ! self::id( $payment_id, 'pay' ) || ! self::id( $subscription_id, 'sub' )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) return false;
		return hash_equals( hash_hmac( 'sha256', $payment_id . '|' . $subscription_id, self::settings()['key_secret'] ), $signature );
	}

	public static function id( mixed $value, string $prefix ): bool {
		return is_string( $value ) && (bool) preg_match( '/^' . preg_quote( $prefix, '/' ) . '_[A-Za-z0-9]{1,48}$/D', $value );
	}

	public static function verified_event( string $raw, string $signature ): array|\WP_Error {
		if ( ! self::available() || strlen( $raw ) > 131072 || ! preg_match( '/^[a-f0-9]{64}$/iD', $signature )
			|| ! hash_equals( hash_hmac( 'sha256', $raw, KIWE_RAZORPAY_SUBSCRIPTIONS_WEBHOOK_SECRET ), strtolower( $signature ) ) ) {
			return new \WP_Error( 'kiwe_razorpay_signature', 'Invalid Razorpay signature.', [ 'status'=>401 ] );
		}
		$event = json_decode( $raw, true, 24 );
		if ( ! is_array( $event ) || KIWE_RAZORPAY_SUBSCRIPTIONS_ACCOUNT !== ( $event['account_id'] ?? null ) ) {
			return new \WP_Error( 'kiwe_razorpay_account', 'The Razorpay account did not match this subscription pilot.', [ 'status'=>403 ] );
		}
		if ( ! is_string( $event['event'] ?? null ) || ! is_array( $event['payload'] ?? null ) ) {
			return new \WP_Error( 'kiwe_razorpay_event', 'Invalid Razorpay event.', [ 'status'=>400 ] );
		}
		return $event;
	}

	public static function plan( string $id ): array {
		return self::entity( 'plans', 'plan', $id );
	}

	public static function subscription( string $id ): array {
		return self::entity( 'subscriptions', 'sub', $id );
	}

	public static function invoice( string $id ): array {
		return self::entity( 'invoices', 'inv', $id );
	}

	public static function payment( string $id ): array {
		return self::entity( 'payments', 'pay', $id );
	}

	public static function create( array $agreement ): array {
		return self::request( 'subscriptions', [
			'plan_id'=>$agreement['planId'],
			'quantity'=>1,
			'total_count'=>$agreement['cycles'],
			'start_at'=>$agreement['firstRenewalAt'],
			'expire_by'=>$agreement['approvalExpiresAt'],
			'customer_notify'=>false,
			'addons'=>[ [ 'item'=>[ 'name'=>'First term and one-time items', 'amount'=>$agreement['firstChargeMinor'], 'currency'=>'INR' ] ] ],
			'notes'=>[ 'kiwe_order'=>(string) $agreement['orderId'], 'kiwe_agreement'=>$agreement['reference'] ],
		] );
	}

	public static function cancel( string $id ): array {
		if ( ! self::id( $id, 'sub' ) ) throw new \DomainException( 'The Razorpay subscription identity is invalid.' );
		return self::request( 'subscriptions/' . $id . '/cancel', [ 'cancel_at_cycle_end'=>false ] );
	}

	public static function change_pause( string $id, bool $pause ): array {
		if ( ! self::id( $id, 'sub' ) ) throw new \DomainException( 'The Razorpay subscription identity is invalid.' );
		$action = $pause ? 'pause' : 'resume';
		return self::request( 'subscriptions/' . $id . '/' . $action, [ $action . '_at'=>'now' ] );
	}

	public static function authorization_url( mixed $url ): string {
		if ( ! is_string( $url ) || strlen( $url ) > 512 ) return '';
		return preg_match( '~^https://(?:rzp\.io|rzp\.link)/(?:[A-Za-z0-9_/-]+)$~D', $url ) ? $url : '';
	}

	private static function entity( string $resource, string $prefix, string $id ): array {
		if ( ! self::id( $id, $prefix ) ) throw new \DomainException( 'The Razorpay record identity is invalid.' );
		$entity = self::request( $resource . '/' . $id );
		if ( $id !== ( $entity['id'] ?? '' ) ) throw new \DomainException( 'The Razorpay record did not match its requested identity.' );
		return $entity;
	}

	private static function request( string $path, ?array $payload = null ): array {
		if ( ! self::available() ) throw new \RuntimeException( 'Configure the Razorpay Test Mode subscription pilot first.' );
		$settings = self::settings();
		$args = [
			'method'=>null === $payload ? 'GET' : 'POST',
			'timeout'=>10,
			'redirection'=>0,
			'limit_response_size'=>262144,
			'headers'=>[
				'Authorization'=>'Basic ' . base64_encode( $settings['key_id'] . ':' . $settings['key_secret'] ),
				'Accept'=>'application/json',
			],
		];
		if ( null !== $payload ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $payload );
		}
		$response = wp_safe_remote_request( self::API . $path, $args );
		$status = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) throw new \RuntimeException( 'Razorpay could not confirm the subscription request.' );
		$data = json_decode( wp_remote_retrieve_body( $response ), true, 24 );
		if ( ! is_array( $data ) || isset( $data['error'] ) ) throw new \RuntimeException( 'Razorpay returned an incomplete subscription response.' );
		return $data;
	}
}
