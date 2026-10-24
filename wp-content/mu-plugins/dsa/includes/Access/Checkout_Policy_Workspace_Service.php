<?php
namespace DSA\Access;

use DSA\Commerce\Abandoned_Cart_Service;
use DSA\Commerce\COD_Gate_Service;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Reviewed controls around the existing checkout and campaign owners; no gateway execution. */
final class Checkout_Policy_Workspace_Service {
	public function __construct( private Abandoned_Cart_Service $carts ) {}
	public function register(): void { if ( Commerce_App_Service::enabled() ) add_action( 'rest_api_init', [ $this,'routes' ] ); }
	public function permission( \WP_REST_Request $r ): bool { return ( new Commerce_App_Service() )->permission( $r ); }
	public function routes(): void {
		foreach ( [ '/workspace/commerce/checkout-policy'=>[ 'read_policy','write_policy' ], '/workspace/commerce/saved-carts'=>[ 'read_carts',null ], '/workspace/commerce/saved-carts/(?P<id>[1-9][0-9]*)'=>[ 'read_cart','write_cart' ] ] as $path=>$methods ) {
			$args = []; foreach ( $methods as $i=>$callback ) if ( $callback ) $args[] = [ 'methods'=>$i ? 'POST' : 'GET','callback'=>[ $this,$callback ],'permission_callback'=>[ $this,'permission' ] ];
			register_rest_route( 'dsa/v1',$path,$args );
		}
	}
	private function error( string $message,int $status=400 ): \WP_Error { return new \WP_Error( 'kiwe_checkout_policy',$message,[ 'status'=>$status ] ); }
	private function response( $v ): \WP_REST_Response { return is_wp_error( $v ) ? Task_Workspace_UI::failure( $v,(int) ( $v->get_error_data()['status'] ?? 400 ) ) : Task_Workspace_UI::response( $v ); }
	public function policy() {
		if ( !\DSA\Apps\Store_App_Service::enabled() || ! class_exists( 'WC_Order' ) || ! ( current_user_can( 'edit_products' ) || current_user_can( 'edit_others_shop_orders' ) ) ) return $this->error( 'Checkout policy requires an active WooCommerce store.',403 );
		$raw = get_option( DSA_OPTION_SETTINGS,null ); if ( ! is_array( $raw ) ) return $this->error( 'Kiwe settings need setup before editing checkout policy.',409 );
		$config = ( new COD_Gate_Service( new Settings() ) )->config();
		$gateway = get_option( 'woocommerce_cod_settings',[] ); $gateway = is_array( $gateway ) ? $gateway : [];
		$guest = get_option( 'woocommerce_enable_guest_checkout','yes' );
		if ( ! in_array( $guest,[ 'yes','no' ],true ) ) return $this->error( 'The native guest-checkout setting needs site-manager review.',409 );
		$out = [ 'fields'=>$config,'canEdit'=>current_user_can( 'kiwe_manage_context' ),'gateway'=>[ 'enabled'=>'yes' === ( $gateway['enabled'] ?? 'no' ),'title'=>Task_Workspace_UI::text( $gateway['title'] ?? 'Cash on delivery',120 ) ],'prepaidDiscount'=>[ 'status'=>'external_owner','message'=>'Payment-method discounts stay with their installed Woo provider. Kiwe’s prepaid setting restores COD eligibility after a native paid, completed order.' ] ];
		$out['guestCheckout'] = 'yes' === $guest;
		$out['canEditGuestCheckout'] = $out['canEdit'] && current_user_can( 'manage_woocommerce' );
		$out['verifiedContactRequired'] = (bool) apply_filters( 'dsa_phonekey_purchase_verified_contact_enabled',false );
		$out['expected'] = hash_hmac( 'sha256',serialize( [ $raw,$gateway,$out ] ),wp_salt( 'nonce' ) ); return $out;
	}
	public function change_guest_checkout( array $input ) {
		$before = $this->policy(); if ( is_wp_error( $before ) ) return $before;
		if ( ! $before['canEditGuestCheckout'] ) return $this->error( 'Your site owner manages guest checkout.',403 );
		if ( array_diff( array_keys( $input ),[ 'operation','enabled','expected' ] ) || ( $input['operation'] ?? null ) !== 'guestCheckout' || ! is_bool( $input['enabled'] ?? null ) || ! is_string( $input['expected'] ?? null ) ) return $this->error( 'Choose whether guests can check out.' );
		global $wpdb;
		$lock = 'kiwe-guest-checkout-' . substr( hash( 'sha256',$wpdb->prefix ),0,32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)',$lock ) ) ) return $this->error( 'Another checkout save is running. Reload before retrying.',409 );
		try {
			$current = $this->policy();
			if ( is_wp_error( $current ) || ! hash_equals( $current['expected'],$input['expected'] ) ) return $this->error( 'Checkout settings changed. Reload before saving.',409 );
			if ( $current['guestCheckout'] === $input['enabled'] ) return $current;
			// Woo owns the option and invalidation. Do not write an account or Kiwe shadow policy.
			update_option( 'woocommerce_enable_guest_checkout',$input['enabled'] ? 'yes' : 'no' );
			$after = $this->policy();
			if ( is_wp_error( $after ) || $after['guestCheckout'] !== $input['enabled'] ) return $this->error( 'Guest checkout could not be confirmed. Reload before retrying.',409 );
			return $after;
		} catch ( \Throwable $error ) { return $this->error( 'Guest checkout could not be confirmed. Reload before retrying.',409 ); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)',$lock ) ); }
	}
	public function change_policy( array $input ) {
		$before = $this->policy(); if ( is_wp_error( $before ) ) return $before;
		if ( ! $before['canEdit'] ) return $this->error( 'Your role can review this policy. A site manager controls changes.',403 );
		$f = $input['fields'] ?? null; $keys = array_keys( $before['fields'] );
		if ( array_diff( array_keys( $input ),[ 'fields','expected','confirmed' ] ) || true !== ( $input['confirmed'] ?? null ) || ! is_string( $input['expected'] ?? null ) || ! hash_equals( $before['expected'],$input['expected'] ) ) return $this->error( 'Review and confirm the current checkout policy before saving.',409 );
		if ( ! is_array( $f ) || array_diff( array_keys( $f ),$keys ) || array_diff( $keys,array_keys( $f ) ) ) return $this->error( 'Use the supported checkout controls.' );
		foreach ( [ 'enabled','allow_unverified_on_failure' ] as $key ) if ( ! is_bool( $f[$key] ) ) return $this->error( 'Choose the policy switches.' );
		foreach ( [ 'strikes_to_block'=>[ 1,10 ],'trusted_skip_after_completed'=>[ 0,50 ] ] as $key=>$range ) if ( ! is_int( $f[$key] ) || $f[$key] < $range[0] || $f[$key] > $range[1] ) return $this->error( 'Choose valid cancellation and trust limits.' );
		if ( ! in_array( $f['regain'],[ 'prepaid_success','never' ],true ) || ! is_string( $f['block_message'] ) || ! trim( $f['block_message'] ) || strlen( $f['block_message'] ) > 1000 ) return $this->error( 'Choose the prepaid policy and a short customer message.' );
		$f['block_message'] = sanitize_text_field( $f['block_message'] );
		if ( '' === trim( $f['block_message'] ) ) return $this->error( 'Add a readable customer message.' );
		$raw = get_option( DSA_OPTION_SETTINGS,null ); $current = $this->policy();
		if ( ! is_array( $raw ) || is_wp_error( $current ) || ! hash_equals( $current['expected'],$input['expected'] ) ) return $this->error( 'Settings changed. Reload and review before saving.',409 );
		$settings = new Settings();
		if ( ! $settings->compare_and_update_selected( $raw,[ 'commerce'=>[ 'cod_gate'=>$f ] ] ) ) return $this->error( 'The policy save is uncertain or settings changed. Reload before another attempt.',409 );
		$after = $this->policy(); if ( is_wp_error( $after ) || $after['fields'] !== $f ) return $this->error( 'The saved policy could not be confirmed. Reload before another attempt.',409 );
		return $after;
	}
	public function read_policy( \WP_REST_Request $r ): \WP_REST_Response { return $this->response( $r->get_method() === 'GET' && $this->permission( $r ) ? $this->policy() : $this->error( 'Checkout policy is unavailable.',403 ) ); }
	public function write_policy( \WP_REST_Request $r ): \WP_REST_Response {
		if ( $r->get_method() !== 'POST' || ! $this->permission( $r ) ) return $this->response( $this->error( 'Checkout policy is unavailable.',403 ) );
		$input = (array) $r->get_json_params();
		return $this->response( ( $input['operation'] ?? null ) === 'guestCheckout' ? $this->change_guest_checkout( $input ) : $this->change_policy( $input ) );
	}
	public function read_carts( \WP_REST_Request $r ): \WP_REST_Response {
		if ( $r->get_method() !== 'GET' || ! $this->permission( $r ) || ! current_user_can( 'edit_others_shop_orders' ) ) return $this->response( $this->error( 'Saved carts require order-management access.',403 ) );
		return $this->response( $this->carts->workspace_rows( $r->get_query_params() ) );
	}
	public function read_cart( \WP_REST_Request $r ): \WP_REST_Response { return $this->response( $r->get_method() === 'GET' && $this->permission( $r ) ? $this->carts->review_record( (int) $r->get_url_params()['id'] ) : $this->error( 'Saved cart is unavailable.',403 ) ); }
	public function write_cart( \WP_REST_Request $r ): \WP_REST_Response {
		if ( $r->get_method() !== 'POST' || ! $this->permission( $r ) ) return $this->response( $this->error( 'Saved cart is unavailable.',403 ) );
		$input = $r->get_json_params(); $id = (int) $r->get_url_params()['id'];
		if ( ! is_array( $input ) || array_diff( array_keys( $input ),[ 'operation','expected','confirmed','channel','attempt','outcome' ] ) || true !== ( $input['confirmed'] ?? null ) || ! is_string( $input['expected'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D',$input['expected'] ) ) return $this->response( $this->error( 'Review and confirm this cart task first.',409 ) );
		if ( ( $input['operation'] ?? '' ) === 'resolve' && is_int( $input['attempt'] ?? null ) && is_string( $input['outcome'] ?? null ) ) return $this->response( $this->carts->resolve_attempt( $id,$input['attempt'],$input['expected'],$input['outcome'] ) );
		if ( ( $input['operation'] ?? '' ) !== 'send' || ! is_string( $input['channel'] ?? null ) ) return $this->response( $this->error( 'Choose a supported cart task.' ) );
		$result = $this->carts->send_reminder( $id,$input['channel'],false,$input['expected'] );
		return $this->response( is_wp_error( $result ) ? $result : $this->carts->review_record( $id ) );
	}
}
