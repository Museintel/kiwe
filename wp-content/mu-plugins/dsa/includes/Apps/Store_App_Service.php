<?php
namespace DSA\Apps;

use DSA\Access\Task_Workspace_UI as UI;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native Store availability, including the stack applied by managed setup in Start. */
final class Store_App_Service {
 public const OPTION = 'kiwe_store_app_enabled_v1';
 public const ACTION = 'kiwe_store_app';
 public const CAPABILITY = 'kiwe_manage_context';
 private const MANAGED_MODES = [ 'services', 'ecommerce', 'membership', 'both' ];
 public static function available(): bool { if(!function_exists('WC'))return false;$provider=Runtime_Registry::provider('store');return $provider===null || (defined('WC_VERSION') && version_compare(WC_VERSION,$provider['requiresWoo'],'>=')); }
 private static function managed_mode( mixed $value ): ?string {
  if ( !is_string( $value ) || !str_starts_with( $value, 'start:' ) ) return null;
  $mode = substr( $value, 6 );
  return in_array( $mode, self::MANAGED_MODES, true ) ? $mode : null;
 }
 public static function managed_setup(): ?string { return self::managed_mode( get_option( self::OPTION, null ) ); }
 public static function supports_memberships(): bool {
  return in_array( self::managed_setup(), [ null, 'membership', 'both' ], true );
 }
 public static function requested(): bool {
  $stored = get_option( self::OPTION, null );
  $managed = self::managed_mode( $stored );
  if ( null !== $managed ) return 'services' !== $managed;
  return null === $stored ? self::available() : in_array( $stored, [ true, 1, '1' ], true );
 }
 /** Called by the managed WordPress installer after it verifies the exact site and signed Apps. */
 public static function provision_setup( string $mode ): true|\WP_Error {
  if ( !defined( 'WP_CLI' ) || !WP_CLI || !self::allowed() || is_multisite() || !in_array( $mode, self::MANAGED_MODES, true ) ) {
   return new \WP_Error( 'kiwe_site_setup_forbidden', 'The managed website setup requires the site installer and owner.' );
  }
  $existing = self::managed_setup();
  if ( null !== $existing && $mode !== $existing ) {
   return new \WP_Error( 'kiwe_site_setup_locked', 'This website already has a different managed setup.' );
  }
  if ( 'services' === $mode ) {
   if ( function_exists( 'WC' ) || is_file( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ) {
    return new \WP_Error( 'kiwe_site_setup_contains_store', 'The services and portfolio setup requires WordPress without WooCommerce.' );
   }
  } elseif ( !self::available() || !Runtime_Registry::installed( 'store' ) || !class_exists( \DSA\Commerce\Subscription_Product_Service::class ) ) {
   return new \WP_Error( 'kiwe_site_setup_store', 'Install and verify the matching WooCommerce and signed Kiwe Store packages first.' );
  } elseif ( null === $existing ) {
   $billing = in_array( $mode, [ 'membership', 'both' ], true ) ? '1' : '0';
   $option = \DSA\Commerce\Subscription_Product_Service::OPTION;
   update_option( $option, $billing, false );
   if ( $billing !== get_option( $option ) ) return new \WP_Error( 'kiwe_site_setup_billing', 'Could not verify the website billing setup.' );
  }
  $value = 'start:' . $mode;
  update_option( self::OPTION, $value, false );
  if ( $value !== get_option( self::OPTION, null ) || ( 'services' !== $mode ) !== self::enabled() ) {
   return new \WP_Error( 'kiwe_site_setup_save', 'Could not verify the managed website setup.' );
  }
  return true;
 }
 public static function enabled(): bool { return Runtime_Registry::installed('store') && self::available() && self::requested(); }
 public static function content_available( string $type ): bool {
  return !in_array( $type, [ 'product','product_variation','shop_order','shop_order_refund','shop_coupon' ], true ) || self::enabled();
 }
 public static function taxonomy_available( string $taxonomy ): bool {
  if ( self::enabled() ) return true;
  if ( in_array( $taxonomy, [ 'product_cat','product_tag','product_type','product_visibility','product_shipping_class' ], true ) || str_starts_with( $taxonomy, 'pa_' ) ) return false;
  $object=function_exists( 'get_taxonomy' ) ? get_taxonomy( $taxonomy ) : null;
  return !$object || !in_array( 'product', (array) ( $object->object_type ?? [] ), true );
 }
 public static function allowed(): bool { return current_user_can( self::CAPABILITY ); }
 public static function url(): string { return \DSA\Access\Commerce_App_Service::url( 'settings' ); }
 public static function home_url(): string { return admin_url( 'admin.php?page=kiwe-workspace&area=apps' ); }
 public static function expected(): string { return hash( 'sha256', (string) wp_json_encode( [ get_option( self::OPTION, null ), self::available(), Runtime_Registry::installed('store'), Runtime_Registry::provider('store')['version'] ?? '' ] ) ); }
 /** Core callers receive an honest unavailable capability without constructing Store services. */
 public static function inactive_cart( bool $runtime = false ): array {
  $out=[ 'available'=>false, 'count'=>0, 'total'=>'', 'cartUrl'=>'', 'checkoutUrl'=>'', 'items'=>[] ];
  if ( $runtime ) $out += [ 'upsells'=>[], 'runtimeOnly'=>true ];
  return $out;
 }
 public static function inactive_context(): array {
  return [ 'available'=>false, 'cart'=>self::inactive_cart(), 'current'=>[], 'routes'=>[], 'decision'=>[], 'transitionMessages'=>[], 'complements'=>[], 'safety'=>[ 'navigationBlocking'=>false, 'cartMutation'=>false, 'fragmentCheckout'=>false ] ];
 }
 public function register(): void {
  // The Core gate respects an explicit Off choice even when Store services are unloaded.
  add_action('plugins_loaded',static function():void{if(function_exists('WC'))Store_Checkout_Gate::register(self::enabled());},29);
  add_filter( 'wp_insert_post_data', [ \DSA\Utilities\Content_Visibility::class, 'keep_membership_pages_private' ], 20, 2 );
  // The closure is inert on visitor requests and loads no optional Store/MCP code.
  add_action('kiwe_mcp_register_providers',static function($registry,int $api):void{if(self::enabled()&&class_exists(\DSA\Commerce\MCP_Store_Provider::class))\DSA\Commerce\MCP_Store_Provider::register($registry,$api);},10,2);
  add_action( 'rest_api_init', [ $this, 'routes' ] );
  add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
 }
 public static function card(): array {
  $enabled = self::enabled(); $available = self::available(); $managed = self::managed_setup();
  $editable = null === $managed && self::allowed();
  return [ 'id'=>'store', 'enabled'=>$enabled, 'canChange'=>$editable, 'blocked'=>!$available || !Runtime_Registry::installed('store'), 'installed'=>Runtime_Registry::installed('store'), 'manageUrl'=>'services' !== $managed && ( current_user_can('install_plugins') || current_user_can('update_plugins') ) ? Library_Service::url('store') : '',
   'status'=>$enabled ? 'On' : 'Off', 'toggleLabel'=>'Store',
   'description'=>null !== $managed ? ( 'services' === $managed ? 'Services and portfolio setup selected in Kiwe Start.' : 'Store setup selected in Kiwe Start.' ) : ( !Runtime_Registry::installed('store') ? 'Add Kiwe Store when this website needs ecommerce. Existing business details and native records stay in place.' : (!$available ? 'WooCommerce is not active. Your site manager can add it when this website needs a store.' : ( $enabled ? 'Products, delivery, taxes, offers, carts, wishlists and orders.' : 'Turn on Store to open checkout for products and subscriptions.' )) ),
   'url'=>$enabled ? self::url() : '', 'endpoint'=>$editable ? rest_url( 'dsa/v1/workspace/apps/store' ) : '',
   'expected'=>$editable ? self::expected() : '', 'action'=>admin_url( 'admin-post.php' ), 'nonce'=>$editable ? wp_create_nonce( self::ACTION ) : '',
  ];
 }
 public function routes(): void {
  register_rest_route( 'dsa/v1', '/workspace/apps/store', [ 'methods'=>'POST', 'callback'=>[ $this, 'save' ], 'permission_callback'=>[ $this, 'permission' ] ] );
 }
 public function permission( \WP_REST_Request $request ): bool {
  return self::allowed() && 'POST' === $request->get_method() && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && Origin_Checker::is_same_site_request() && true === Origin_Checker::mutation_allowed( $request );
 }
 public function save( \WP_REST_Request $request ): \WP_REST_Response {
  if ( !$this->permission( $request ) ) return UI::failure( new \WP_Error( 'kiwe_store_app_forbidden', 'Your website manager controls this app.' ), 403 );
  $body = $request->get_json_params();
  if ( !is_array( $body ) || array_diff( array_keys( $body ), [ 'enabled','expected' ] ) || !is_bool( $body['enabled'] ?? null ) || !is_string( $body['expected'] ?? null ) ) return UI::failure( new \WP_Error( 'kiwe_store_app_value', 'Choose whether Kiwe Store should be on.' ), 400 );
  $result = self::change( $body['enabled'], $body['expected'] );
  return is_wp_error( $result ) ? UI::failure( $result, 409 ) : UI::response( [ 'app'=>self::card(), 'reload'=>true ] );
 }
 public static function change( bool $enabled, string $expected ) {
  if ( !self::allowed() ) return new \WP_Error( 'kiwe_store_app_forbidden', 'Your website manager controls this app.' );
  global $wpdb;
  $lock = 'kiwe-store-app-' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix ), 0, 32 );
  if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) return new \WP_Error( 'kiwe_store_app_busy', 'Another app change is finishing. Refresh before trying again.' );
  try {
   wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
   if ( !hash_equals( self::expected(), $expected ) ) return new \WP_Error( 'kiwe_store_app_stale', 'The app state changed. Refresh before saving.' );
   if ( $enabled && (!self::available() || !Runtime_Registry::installed('store')) ) return new \WP_Error( 'kiwe_store_app_dependency', 'WooCommerce must be active before Kiwe Store can be turned on.' );
   $managed = self::managed_setup();
   if ( null !== $managed ) {
    return $enabled === ( 'services' !== $managed ) ? true : new \WP_Error( 'kiwe_store_setup_managed', 'This website’s Store choice was set during setup in Kiwe Start.' );
   }
   $value = $enabled ? '1' : '0'; update_option( self::OPTION, $value, false );
   if ( get_option( self::OPTION, null ) !== $value ) return new \WP_Error( 'kiwe_store_app_save', 'The app change could not be confirmed. Refresh and review.' );
  } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
  if ( !$enabled ) foreach ( [ 'dsa_abandoned_cart_maintenance', 'dsa_co_purchase_daily_sync', 'dsa_bestseller_daily_sync' ] as $hook ) wp_clear_scheduled_hook( $hook );
  // Only this explicit manager action invalidates native caches; never a visitor read.
  if ( class_exists( '\WPO_Page_Cache', false ) ) \WPO_Page_Cache::instance()->purge();
  do_action( 'litespeed_purge_all' );
  return true;
 }
 public function handle(): void {
  if ( !self::allowed() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || !Origin_Checker::is_same_site_request() ) wp_die( 'Your website manager controls this app.', '', [ 'response'=>403 ] );
  check_admin_referer( self::ACTION ); $input=wp_unslash( $_POST );
  if ( !is_string( $input['enabled'] ?? null ) || !in_array( $input['enabled'], ['0','1'], true ) || !is_string( $input['expected'] ?? null ) ) wp_die( 'Choose a valid app state.', '', [ 'response'=>400 ] );
  $result=self::change( '1' === $input['enabled'], $input['expected'] );
  if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', [ 'response'=>409, 'back_link'=>true ] );
  wp_safe_redirect( self::home_url() ); exit;
 }
}
