<?php
/** Private startup adapter cost boundaries with native-owner spies; real Woo acceptance is separate. */
declare(strict_types=1);

namespace DSA {
	class Settings {
		public function get( $name, $default = [] ) { return 'commerce' === $name ? [ 'cart_show_delivery' => false ] : $default; }
		public function all() { return [ 'commerce' => [ 'cart_show_delivery' => false ] ]; }
	}
}
namespace DSA\Commerce {
	class Linked_Products_Service { public function cart_recommendations( $limit ) { $GLOBALS['calls']['recommendations']++; return [ [ 'id' => 33 ] ]; } }
	class Store_Analytics_Service { public function cart_upsell_offers( $limit ) { $GLOBALS['calls']['offers']++; return array_slice( $GLOBALS['offers'], 0, $limit ); } }
	class Commerce_Context_Service { public function context( $trust, $protected ) { return [ 'available' => null !== \WC(), 'current' => [ 'id' => 0 ], 'cart' => [ 'count' => \WC()?->cart?->get_cart_contents_count() ?? 0 ] ]; } }
}
namespace DSA\Protected_Flow { class Flow_Guard { public function current() { return [ 'context' => 'page' ]; } } }

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
function get_option($key,$default=false){return $default;}

	$calls = [ 'totals' => 0, 'shipping' => 0, 'recommendations' => 0, 'offers' => 0, 'gateways' => 0, 'nativeInit' => 0 ];
	$offers = [ [ 'id' => 22, 'triggerId' => 11, 'title' => 'Companion', 'state' => 'eligible', 'discount' => 10 ] ];
	$provider_titles = [ 'Native provider' ];
	$inclusive = false;
	$native_calculations = 0;
	function did_action( $hook ) { return 'woocommerce_after_calculate_totals' === $hook ? $GLOBALS['native_calculations'] : 0; }
	$checks = [];
	function check( bool $passed, string $label ): void { $GLOBALS['checks'][] = [ 'label' => $label, 'passed' => $passed ]; if ( ! $passed ) throw new \RuntimeException( $label ); }
	function __( $value, $domain = '' ) { return $value; }
	function WC() { return $GLOBALS['wc']; }
	function wc_load_cart() { $GLOBALS['calls']['nativeInit']++; WC()->cart = new Startup_Cart(); }
	function get_bloginfo( $key ) { return 'UTF-8'; }
	function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
	function sanitize_text_field( $value ) { return strip_tags( (string) $value ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	function wc_price( $amount ) { return '$' . number_format( (float) $amount, 2, '.', '' ); }
	function number_format_i18n( $amount, $places ) { return number_format( (float) $amount, $places, '.', '' ); }
	function wc_format_coupon_code( $code ) { return strtolower( (string) $code ); }
	function wc_get_cart_url() { return 'https://site.test/cart/'; }
	function wc_get_checkout_url() { return 'https://site.test/buy/'; }
	function wc_placeholder_img_src() { return ''; }
	function is_ssl() { return true; }
	function esc_url_raw( $value ) { return $value; }
	function is_user_logged_in() { return false; }
	function current_user_can( $capability ) { return false; }
	function apply_filters( $name, $value ) { return $value; }
	function wp_create_nonce( $action ) { return 'same-private-nonce'; }
	function wp_login_url( $url ) { return 'https://site.test/login/'; }
	function wp_logout_url( $url ) { return 'https://site.test/logout/'; }
	function wp_lostpassword_url() { return 'https://site.test/reset/'; }
	function home_url( $url = '/' ) { return 'https://site.test/' . ltrim( $url, '/' ); }
	function wp_unslash( $value ) { return $value; }
	class Startup_Product {
		public function get_name() { return 'Native product'; }
		public function get_permalink() { return 'https://site.test/product/'; }
		public function get_weight() { return ''; }
		public function managing_stock() { return false; }
		public function is_sold_individually() { return false; }
	}
	class Startup_Cart {
		public array $snapshot = [ 'total' => 133, 'subtotal' => 120, 'subtotal_tax' => 12, 'cart_contents_total' => 100, 'cart_contents_tax' => 10 ];
		public function calculate_totals() { $GLOBALS['calls']['totals']++; $GLOBALS['native_calculations']++; $this->snapshot['total'] = 151; }
		public function get_totals() { return $this->snapshot; }
		public function display_prices_including_tax() { return $GLOBALS['inclusive']; }
		public function get_cart_contents_count() { return 2; }
		public function get_cart() { return [ 'current-session-key' => [ 'data' => new Startup_Product(), 'quantity' => 2, 'product_id' => 11, 'variation_id' => 0 ] ]; }
		public function get_product_price( $product ) { return '$60.00'; }
		public function get_product_subtotal( $product, $quantity ) { return '$120.00'; }
		public function get_total( $context = 'view' ) { return 'edit' === $context ? $this->snapshot['total'] : wc_price( $this->snapshot['total'] ); }
		public function get_subtotal() { return $this->snapshot['subtotal']; }
		public function get_subtotal_tax() { return $this->snapshot['subtotal_tax']; }
		public function get_cart_contents_total() { return $this->snapshot['cart_contents_total']; }
		public function get_cart_contents_tax() { return $this->snapshot['cart_contents_tax']; }
		public function get_coupon_discount_tax_totals() { return []; }
		public function get_coupon_discount_totals() { return []; }
		public function get_fees() { return []; }
		public function needs_shipping() { return true; }
		public function show_shipping() { return false; }
		public function get_total_tax() { return 0; }
	}
	$wc = new class {
		public $cart;
		public $session = null;
		public function shipping() { $GLOBALS['calls']['shipping']++; return new class { public function get_packages() { return []; } }; }
		public function payment_gateways() { return new class {
			public function get_available_payment_gateways() { $GLOBALS['calls']['gateways']++; return array_map( static fn( $title ) => new class( $title ) { public function __construct( private $title ) {} public function get_title() { return $this->title; } }, $GLOBALS['provider_titles'] ); }
		}; }
	};
	$wc->cart = new Startup_Cart();
	$root = dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/';
	require $root . 'Commerce/Cart_Payload_Service.php';
	require $root . 'PhoneKey/PhoneKey_Bridge.php';
	require $root . 'Trust/Trust_Service.php';
	require $root . 'Rest/Runtime_Hydration_Controller.php';
	$settings = new \DSA\Settings();
	$new_payload = static fn() => new \DSA\Commerce\Cart_Payload_Service( $settings, new \DSA\Commerce\Linked_Products_Service(), new \DSA\Commerce\Store_Analytics_Service() );
	$payload = $new_payload();
	$snapshot = $payload->runtime_data();
	check( 0 === $calls['totals'] && 0 === $calls['shipping'] && 0 === $calls['recommendations'], 'Startup does not explicitly calculate totals, inspect shipping or build FBT recommendations' );
	check( $snapshot['runtimeOnly'] && 2 === $snapshot['count'] && 'current-session-key' === $snapshot['items'][0]['key'] && 11 === $snapshot['items'][0]['productId'], 'Startup count/items retain the loaded native session identity' );
	check( '$133.00' === $snapshot['total'] && '$100.00' === $snapshot['productTotal'] && '$120.00' === $snapshot['productSubtotal'], 'Startup reads native totals without recomputing the payable amount' );
	check( $snapshot['upsells'] === $offers && 1 === $calls['offers'], 'Existing bounded eligible benefit remains available for notification reminders' );
	check( ! isset( $snapshot['totalsSummary'], $snapshot['discountSummary'], $snapshot['recommendations'] ), 'Startup defers full delivery, discount and recommendation presentation' );
	$inclusive = true;
	$inclusive_snapshot = $payload->runtime_data();
	check( '$110.00' === $inclusive_snapshot['productTotal'] && '$132.00' === $inclusive_snapshot['productSubtotal'] && 0 === $calls['totals'], 'Inclusive product amounts use only the native product tax components' );
	$wc->cart->snapshot = [];
	$missing = $payload->runtime_data();
	check( '' === $missing['total'] && '' === $missing['productTotal'], 'Missing native totals are unknown rather than a fabricated zero quote' );
	$wc->cart = new class extends Startup_Cart {
		public function get_cart() { $this->snapshot = [ 'total' => 77, 'subtotal' => 70, 'cart_contents_total' => 70 ]; return parent::get_cart(); }
	};
	$lazy = $payload->runtime_data();
	check( '$77.00' === $lazy['total'] && '$70.00' === $lazy['productTotal'] && 0 === $calls['totals'], 'A lazy native session restores items and saved totals before the snapshot reads them' );
	$wc->cart = new Startup_Cart();
	$inclusive = false;
	$full = $payload->cart_data();
	check( 1 === $calls['totals'] && 1 === $calls['shipping'] && 1 === $calls['recommendations'], 'Subsequent full cart projection still refreshes native totals and details' );
	check( ! isset( $full['runtimeOnly'] ) && '$151.00' === $full['total'] && isset( $full['totalsSummary'], $full['discountSummary'], $full['recommendations'] ), 'Full cart replaces the deferred snapshot with authoritative complete data' );
	$payload->cart_data();
	check( 1 === $calls['totals'], 'Full payload keeps its existing once-per-owner totals refresh guard' );
	$wc->cart = null;
	$new_payload()->runtime_data();
	check( 1 === $calls['nativeInit'] && 1 === $calls['totals'], 'Missing cart delegates to native loading without an extra Kiwe totals refresh' );
	$bridge = new \DSA\PhoneKey\PhoneKey_Bridge( $new_payload() );
	$before = $calls['totals'];
	$startup_phonekey = $bridge->public_data( true );
	check( $startup_phonekey['cart']['runtimeOnly'] && $before === $calls['totals'] && false === $startup_phonekey['user']['loggedIn'] && 'same-private-nonce' === $startup_phonekey['nonce'], 'Startup PhoneKey projection preserves identity/nonce authority while deferring full cart' );
	$bridge->public_data();
	check( $before + 1 === $calls['totals'], 'Unchanged default PhoneKey projection retains the complete cart owner' );
	$trust = new \DSA\Trust\Trust_Service();
	$first = $trust->summary();
	check( 1 === $calls['gateways'] && $first['payment']['active'] && [ 'Native provider' ] === $first['payment']['providers'] && 'Native provider' === $first['payment']['label'], 'Trust summary resolves native gateway availability once for all three fields' );
	$provider_titles = [];
	$second = $trust->summary();
	check( 2 === $calls['gateways'] && ! $second['payment']['active'] && [] === $second['payment']['providers'], 'A later changed cart/payment context is not served a cached gateway result' );
	$manual = $trust->summary( [ 'payment_provider' => 'Configured provider' ] );
	check( 2 === $calls['gateways'] && [ 'Configured provider' ] === $manual['payment']['providers'], 'Existing explicit public provider label avoids gateway enumeration' );
	$controller = new \DSA\Rest\Runtime_Hydration_Controller( $settings, new \DSA\PhoneKey\PhoneKey_Bridge( $new_payload() ), $trust, new \DSA\Protected_Flow\Flow_Guard(), new \DSA\Commerce\Commerce_Context_Service() );
	$before = $calls;
	$private = ( new \ReflectionMethod( $controller, 'payload' ) )->invoke( $controller );
	check( $private['phonekey']['cart']['runtimeOnly'] && 2 === $private['nativeData']['cart']['count'] && 'same-private-nonce' === $private['nonce'], 'Actual hydration payload selects lightweight private cart data and mirrors the correct count' );
	check( $before['totals'] === $calls['totals'] && $before['shipping'] === $calls['shipping'] && $before['recommendations'] === $calls['recommendations'], 'Actual hydration composition adds no forced Woo total/shipping/FBT work' );
	$mutation_payload = $new_payload();
	$cart = $wc->cart; $checkpoint = did_action( 'woocommerce_after_calculate_totals' ); $count_before = $calls['totals'];
	$cart->calculate_totals(); $mutation_payload->refresh_after_mutation( $cart, $checkpoint ); $mutation_payload->cart_data();
	check( $calls['totals'] === $count_before + 1, 'Completed native mutation and its complete payload calculate totals only once' );
	$checkpoint = did_action( 'woocommerce_after_calculate_totals' ); $count_before = $calls['totals'];
	$mutation_payload->refresh_after_mutation( $cart, $checkpoint ); $mutation_payload->cart_data();
	check( $calls['totals'] === $count_before + 1, 'A later mutation without a native callback gets one fresh calculation despite a reused payload owner' );
	$checkpoint = did_action( 'woocommerce_after_calculate_totals' ); $count_before = $calls['totals'];
	$cart->calculate_totals(); $wc->cart = new Startup_Cart(); $mutation_payload->refresh_after_mutation( $cart, $checkpoint );
	check( $calls['totals'] === $count_before + 2, 'A calculation on a different cart cannot authorize stale totals on a replaced native cart' );
	$wc = null;
	$absent = $new_payload()->runtime_data();
	check( false === $absent['available'] && 0 === $absent['count'] && [] === $absent['items'], 'Unavailable Woo runtime returns a neutral private projection' );
	check( ! $trust->summary()['payment']['active'] && $before['gateways'] + 1 === $calls['gateways'], 'Unavailable Woo does not enumerate payment gateways' );
	echo json_encode( [ 'passed' => true, 'checks' => $checks, 'scope' => 'Actual Kiwe adapters with native-owner spies; no 21-second cause or native Woo/browser latency claim.' ] ) . PHP_EOL;
}
