<?php
namespace DSA\Apps;

use DSA\Access\Task_Workspace_UI as UI;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One local Store choice; native Woo data and every existing store setting remain authoritative. */
final class Store_App_Service {
 public const OPTION = 'kiwe_store_app_enabled_v1';
 public const ACTION = 'kiwe_store_app';
 public const CAPABILITY = 'kiwe_manage_context';
 public static function available(): bool { if(!function_exists('WC'))return false;$provider=Runtime_Registry::provider('store');return $provider===null || (defined('WC_VERSION') && version_compare(WC_VERSION,$provider['requiresWoo'],'>=')); }
 public static function requested(): bool {
  $stored = get_option( self::OPTION, null );
  return null === $stored ? self::available() : in_array( $stored, [ true, 1, '1' ], true );
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
  add_action( 'rest_api_init', [ $this, 'routes' ] );
  add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
 }
 public static function card(): array {
  $enabled = self::enabled(); $available = self::available();
  return [ 'id'=>'store', 'enabled'=>$enabled, 'canChange'=>self::allowed(), 'blocked'=>!$available || !Runtime_Registry::installed('store'), 'installed'=>Runtime_Registry::installed('store'), 'manageUrl'=>current_user_can('install_plugins') || current_user_can('update_plugins') ? Library_Service::url('store') : '',
   'status'=>$enabled ? 'On' : 'Off', 'toggleLabel'=>'Store',
   'description'=>!Runtime_Registry::installed('store') ? 'Add Kiwe Store when this website needs ecommerce. Existing business details and native records stay in place.' : (!$available ? 'WooCommerce is not active. Your site manager can add it when this website needs a store.' : ( $enabled ? 'Products, delivery, taxes, offers, carts, wishlists and orders.' : 'Turn on to use Kiwe Store. Your products, orders and saved settings stay on the website.' )),
   'url'=>$enabled ? self::url() : '', 'endpoint'=>self::allowed() ? rest_url( 'dsa/v1/workspace/apps/store' ) : '',
   'expected'=>self::allowed() ? self::expected() : '', 'action'=>admin_url( 'admin-post.php' ), 'nonce'=>self::allowed() ? wp_create_nonce( self::ACTION ) : '',
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
