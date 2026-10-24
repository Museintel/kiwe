<?php
declare( strict_types=1 );
define( 'ABSPATH', __DIR__.'/' );
final class WP_Error {
	public array $errors = [];
	public function __construct( $code = '', $message = '', $data = null ) { if ( $code !== '' ) $this->errors[$code] = [ $message, $data ]; }
	public function add( $code, $message ): void { $this->errors[$code] = [ $message, null ]; }
}
$options = [ 'active_plugins'=>[] ]; $reads = []; $hooks = []; $checks = [];
function get_option( $name, $default = false ) { global $options, $reads; $reads[$name] = ( $reads[$name] ?? 0 )+1; return array_key_exists( $name, $options ) ? $options[$name] : $default; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ): void { global $hooks; $hooks[$hook][] = [ $callback, $priority, $args ]; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ): void { add_filter( $hook, $callback, $priority, $args ); }
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Commerce/Store_Recovery_Gate_Service.php';
use DSA\Commerce\Store_Recovery_Gate_Service as Gate;
$check = static function( bool $ok, string $label ) use ( &$checks ): void { if ( ! $ok ) throw new RuntimeException( $label ); $checks[$label] = true; };
$reset = static function( array $next ) use ( &$options, &$reads, &$hooks ): void {
	$options = $next; $reads = []; $hooks = [];
	foreach ( [ 'paused'=>null, 'active_plugins'=>null, 'registered'=>false ] as $name=>$value ) ( new ReflectionProperty( Gate::class, $name ) )->setValue( null, $value );
};
$reject = static function( callable $fn, string $label ) use ( $check ): void { try { $fn(); } catch ( RuntimeException $e ) { $check( str_contains( $e->getMessage(), 'restored Store is paused' ), $label ); return; } throw new RuntimeException( 'Expected refusal: '.$label ); };
$request = static fn( string $route, string $method ) => new class( $route, $method ) {
	public function __construct( private string $route, private string $method ) {}
	public function get_route(): string { return $this->route; }
	public function get_method(): string { return $this->method; }
};
try {
	$reset( [ 'active_plugins'=>[], Gate::OPTION=>[ 'schema'=>1, 'paused'=>true ] ] ); Gate::register_early();
	$check( ! Gate::is_paused() && ! isset( $reads[Gate::OPTION] ) && $hooks === [], 'non-Woo boot does not read recovery marker or register commerce hooks' );
	$reset( [ 'active_plugins'=>[ 'woocommerce/woocommerce.php' ] ] ); Gate::register_early();
	for ( $i=0; $i<5; ++$i ) { Gate::is_paused(); Gate::reason(); Gate::projection(); }
	$check( $reads[Gate::OPTION] === 1 && ! Gate::is_paused() && $hooks === [], 'ordinary Woo reads marker once and installs no recovery hooks' );
	foreach ( [ null, false, '', 'bad', [], [ 'schema'=>1, 'paused'=>false ], [ 'schema'=>1, 'paused'=>true, 'recoverySha256'=>str_repeat( 'a',64 ) ] ] as $i=>$marker ) {
		$reset( [ 'active_plugins'=>[ 'woocommerce/woocommerce.php' ], Gate::OPTION=>$marker ] ); Gate::register_early(); Gate::register_early();
		$check( Gate::is_paused() && Gate::projection()['readOnly'] && $reads[Gate::OPTION] === 1, 'present marker fails closed and is request-cached '.$i );
		$check( count( $hooks['rest_pre_dispatch'] ) === 1, 'early guard registration is idempotent '.$i );
	}
	foreach ( [ 'woocommerce_before_order_object_save', 'woocommerce_before_order_refund_object_save', 'woocommerce_before_order_item_object_save', 'woocommerce_before_product_object_save', 'woocommerce_product_before_set_stock', 'woocommerce_variation_before_set_stock', 'woocommerce_before_pay_action', 'woocommerce_pre_payment_complete', 'woocommerce_before_delete_order_item', 'action_scheduler_before_execute', 'wc_ajax_checkout', 'wp_ajax_woocommerce_refund_line_items', 'woocommerce_downloadable_file_permission_data', 'wp_ajax_woocommerce_grant_access_to_download', 'wp_ajax_woocommerce_revoke_access_to_download' ] as $hook ) {
		$check( isset( $hooks[$hook] ), 'registers native mutation boundary '.$hook ); $reject( $hooks[$hook][0][0], 'blocks native mutation before side effects '.$hook );
	}
	foreach ( [ '/wc/v3/orders', '/wc/v3/products', '/wc/v3/orders/23/refunds', '/wc/store/v1/products', '/wc/store/v1/cart' ] as $route ) foreach ( [ 'GET', 'HEAD', 'OPTIONS' ] as $method ) $check( Gate::guard_rest( 'prior', null, $request( $route, $method ) ) === 'prior', 'native read survives '.$method.' '.$route );
	foreach ( [ '/wc/v3/orders/23', '/wc/store/v1/cart/add-item', '/wc/store/v1/batch', '/wc/v3/products/4' ] as $route ) foreach ( [ 'POST', 'PUT', 'PATCH', 'DELETE' ] as $method ) $check( Gate::guard_rest( null, null, $request( $route, $method ) ) instanceof WP_Error, 'native REST mutation refuses '.$method.' '.$route );
	$check( Gate::guard_rest( null, null, $request( '/wc/store/v1/checkout', 'GET' ) ) instanceof WP_Error, 'Store API GET checkout cannot create a historical replacement order' );
	foreach ( [ '/WC/V3/orders/23', '/WC/STORE/V1/CHECKOUT', '/wc-admin/options', '/wc-analytics/reports/export', '/wc/future/operations' ] as $route ) $check( Gate::guard_rest( null, null, $request( $route, 'POST' ) ) instanceof WP_Error, 'native Woo namespace and case variants fail closed '.$route );
	$check( Gate::guard_rest( null, null, $request( '/WC/STORE/V1/CHECKOUT', 'GET' ) ) instanceof WP_Error, 'case variants cannot bypass side-effecting Store API checkout read' );
	foreach ( [ '/dsa/v1/workspace/commerce/orders/23/actions', '/dsa/v1/workspace/commerce/orders/23/refunds', '/dsa/v1/workspace/commerce/shipping/0/methods/2', '/dsa/v1/workspace/commerce/tax/rates/2', '/dsa/v1/workspace/commerce/checkout-policy', '/dsa/v1/cart/add', '/dsa/v1/cart/item', '/dsa/v1/cart/upsell/claim', '/dsa/v1/checkout' ] as $route ) $check( Gate::guard_rest( null, null, $request( $route, 'POST' ) ) instanceof WP_Error, 'Kiwe commerce mutation refuses before controller side effects '.$route );
	$check( Gate::guard_rest( 'prior', null, $request( '/dsa/v1/workspace/commerce/orders/23', 'GET' ) ) === 'prior', 'Kiwe native order history remains readable' );
	$check( Gate::guard_rest( null, null, $request( '/dsa/v1/checkout', 'GET' ) ) instanceof WP_Error, 'old Kiwe checkout read cannot consume or write saved session errors' );
	$check( Gate::guard_rest( 'prior', null, $request( '/wc/store/v1/checkout', 'OPTIONS' ) ) === 'prior', 'Store API schema discovery remains readable' );
	$check( Gate::guard_rest( 'prior', null, $request( '/wp/v2/posts', 'POST' ) ) === 'prior', 'unrelated WordPress writes remain owned by WordPress' );
	$error = new WP_Error(); Gate::validate_checkout( [], $error ); $check( isset( $error->errors[Gate::ERROR] ), 'classic checkout receives actionable native validation error' );
	$check( Gate::empty_array( [ 'cod'=>new stdClass() ] ) === [] && ! Gate::deny_boolean( true ) && Gate::zero( 5 ) === 0 && Gate::prevent_stock_adjustment( false ), 'gateways purchases stock changes and queue claims are denied' );
	$reject( static fn()=>Gate::guard_post_write( [ 'post_type'=>'shop_order' ], [] ), 'native CPT forms refuse order writes before SQL' );
	$check( Gate::guard_post_write( [ 'post_type'=>'post' ], [] ) === [ 'post_type'=>'post' ], 'native editorial writes remain available' );
	$check( Gate::guard_post_delete( null, (object)[ 'post_type'=>'product' ] ) === false && Gate::guard_post_delete( null, (object)[ 'post_type'=>'post' ] ) === null, 'native deletion is scoped to commerce records' );
	foreach ( [ 'order', 'order_refund', 'order_item', 'product' ] as $type ) $check( isset( $hooks['woocommerce_pre_delete_'.$type] ) && $hooks['woocommerce_pre_delete_'.$type][0][0]( null ) === false, 'WC_Data refuses deletion before its data store '.$type );
	$note_hook = $hooks['woocommerce_new_order_note_data'][0] ?? null;
	$check( is_array( $note_hook ) && $note_hook[1] === PHP_INT_MIN && $note_hook[2] === 1, 'order note guard registers at the native Woo pre-insert data boundary' );
	$reject( static fn()=>$note_hook[0]( [ 'comment_type'=>'order_note' ] ), 'historical order notes cannot be appended before comment SQL' );
	$check( ! isset( $hooks['wp_insert_comment_data'] ), 'nonexistent core comment data hook is not mistaken for an order note boundary' );
	$check( Gate::guard_order_note( [ 'comment_type'=>'comment' ] ) === [ 'comment_type'=>'comment' ], 'ordinary native comments remain available' );
	$reject( static fn()=>Gate::guard_legacy_callback( (object)[ 'query_vars'=>[ 'wc-api'=>'wc_gateway_fixture' ] ] ), 'legacy gateway callback refuses before provider handler' );
	$emails = [ (object)[ 'id'=>'fixture_gateway_email' ] ]; $check( Gate::guard_email_classes( $emails ) === $emails && isset( $hooks['woocommerce_email_enabled_fixture_gateway_email'], $hooks['woocommerce_email_recipient_fixture_gateway_email'] ), 'native registered Woo email classes are disabled without removing history' );
	$check( Gate::empty_recipient( 'fixture@example.invalid' ) === '' && ! isset( $hooks['pre_wp_mail'], $hooks['wp_mail'] ), 'manual Woo mail recipients clear without taking over WordPress mail' );
	foreach ( [ 'low_stock', 'no_stock', 'backorder' ] as $type ) $check( isset( $hooks['woocommerce_email_recipient_'.$type] ), 'native stock notification recipient is quarantined '.$type );
	$check( ! method_exists( Gate::class, 'resume' ) && ! method_exists( Gate::class, 'unpause' ), 'no reenrollment bypass is exposed' );
	$check( ! class_exists( DSA\Commerce\Store_Recovery_Session::class, false ), 'ordinary and early paused registration do not eagerly load a native session replacement' );
	class WC_Session_Handler {
		public static int $effects = 0;
		protected $_customer_id = null; protected $_data = []; protected $_dirty = false;
		public function __construct() { ++self::$effects; }
		public function init() { ++self::$effects; }
		public function set( $key, $value ) { $this->_data[$key] = $value; $this->_dirty = true; }
		public function get( $key ) { return $this->_data[$key] ?? null; }
		public function get_customer_id() { return $this->_customer_id ?? ''; }
		public function save_data( $old_session_key = '' ) { ++self::$effects; }
		public function delete_session( $customer_id ) { ++self::$effects; }
		public function update_session_timestamp( $customer_id, $timestamp ) { ++self::$effects; }
		public function cleanup_sessions() { ++self::$effects; }
		public function set_customer_session_cookie( $set ) { ++self::$effects; }
		public function init_session_cookie() { ++self::$effects; }
		public function set_session_expiration() { ++self::$effects; }
		public function destroy_session() { ++self::$effects; }
		public function forget_session() { ++self::$effects; }
		public function get_session( $customer_id, $default_value = false ) { ++self::$effects; return ['copied'=>'authority']; }
	}
	$session_hook = $hooks['woocommerce_session_handler'][0] ?? null;
	$check( is_array( $session_hook ) && $session_hook[1] === PHP_INT_MAX, 'paused session replacement registers through the native Woo handler selector' );
	$handler = $session_hook[0]( 'WC_Session_Handler' ); $session = new $handler(); $session->init();
	$check( $session instanceof WC_Session_Handler && WC_Session_Handler::$effects === 0, 'paused native session initializes without hooks cookies database or cache work' );
	$session->set( 'customer', ['id'=>23] ); $session->save_data( 'old-guest' );
	$check( $session->get( 'customer' ) === ['id'=>23] && WC_Session_Handler::$effects === 0, 'temporary native cart data remains request-local even when explicitly saved' );
	foreach ( [ ['delete_session','old-guest'], ['update_session_timestamp','old-guest',123], ['cleanup_sessions'], ['set_customer_session_cookie',true], ['init_session_cookie'], ['set_session_expiration'] ] as $call ) { $method=array_shift($call); $session->$method(...$call); $check( WC_Session_Handler::$effects === 0, 'paused native session has no persistent or cookie effect '.$method ); }
	$check( $session->get_session('copied', ['default'=>true]) === ['default'=>true] && WC_Session_Handler::$effects === 0, 'copied session tokens cannot read native storage or cache' );
	$check( ! $session->has_session() && ! $session->get_session_cookie() && $session->generate_customer_id() === '' && $session->get_customer_id() === '', 'paused recovery grants no commerce session or guest identity' );
	$session->forget_session(); $session->destroy_session();
	$check( $session->get('customer') === null && WC_Session_Handler::$effects === 0, 'paused forgetting and destruction clear only request-local data' );
	$reject( static fn()=>Gate::guard_session_handler('Unreviewed_Extension_Session'), 'unreviewed native session extensions cannot acquire recovery persistence' );
	echo json_encode( [ 'passed'=>true, 'cleanup'=>true, 'checks'=>$checks, 'scope'=>'Source-bound gate callbacks; native Woo CPT/HPOS and provider execution remain separate evidence.' ], JSON_UNESCAPED_SLASHES )."\n";
} catch ( Throwable $error ) { fwrite( STDERR, $error->getMessage()."\n" ); exit( 1 ); }
