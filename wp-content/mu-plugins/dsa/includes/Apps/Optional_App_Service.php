<?php
namespace DSA\Apps;

use DSA\Access\Task_Workspace_UI as UI;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native availability for optional workspaces; never changes their retained business data. */
abstract class Optional_App_Service {
	public static function option(): string { return 'kiwe_' . static::ID . '_app_enabled_v1'; }
	public static function requested(): bool { return in_array( get_option( static::option(),'1' ),[ true,1,'1' ],true ); }
	public static function enabled(): bool { return Runtime_Registry::installed( static::ID ) && static::requested(); }
	public static function allowed(): bool { return current_user_can( static::CAPABILITY ); }
	public static function url(): string { return admin_url( 'admin.php?page=' . static::PAGE ); }
	public static function home_url(): string { return SEO_App_Service::home_url(); }
	public static function expected(): string { return hash( 'sha256',wp_json_encode( [ static::ID,get_option( static::option(),null ),Runtime_Registry::installed( static::ID ),Runtime_Registry::provider( static::ID )['version'] ?? '' ] ) ); }
	public static function action(): string { return 'kiwe_' . static::ID . '_app'; }
	public static function card(): array {
		$installed = Runtime_Registry::installed( static::ID ); $enabled = static::enabled(); $allowed = static::allowed();
		return [ 'id'=>static::ID,'enabled'=>$enabled,'installed'=>$installed,'canChange'=>$allowed,'blocked'=>! $installed,'externalOwner'=>'','status'=>$enabled ? 'On' : 'Off','description'=>static::DESCRIPTION,'url'=>$enabled ? static::url() : '', 'manageUrl'=>Native_State::can_review() ? Library_Service::url( static::ID ) : '', 'endpoint'=>$allowed ? rest_url( 'dsa/v1/workspace/apps/' . static::ID ) : '', 'expected'=>$allowed ? static::expected() : '', 'action'=>admin_url( 'admin-post.php' ),'actionName'=>static::action(),'nonce'=>$allowed ? wp_create_nonce( static::action() ) : '' ];
	}
	public function register(): void { add_action( 'rest_api_init',[ $this,'routes' ] ); add_action( 'admin_post_' . static::action(),[ $this,'handle' ] ); }
	public function routes(): void { register_rest_route( 'dsa/v1','/workspace/apps/' . static::ID,[ 'methods'=>'POST','callback'=>[ $this,'save' ],'permission_callback'=>[ $this,'permission' ] ] ); }
	public function permission( \WP_REST_Request $r ): bool { return static::allowed() && $r->get_method() === 'POST' && wp_verify_nonce( (string) $r->get_header( 'X-WP-Nonce' ),'wp_rest' ) && Origin_Checker::is_same_site_request() && true === Origin_Checker::mutation_allowed( $r ); }
	public static function change( bool $enabled,string $expected ) {
		if ( ! static::allowed() ) return new \WP_Error( 'kiwe_app_forbidden','Your website manager controls this App.' );
		global $wpdb;
		$lock = 'kiwe-app-' . substr( hash( 'sha256',$wpdb->prefix . static::ID ),0,32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)',$lock ) ) ) return new \WP_Error( 'kiwe_app_busy','Another App change is finishing. Refresh before retrying.' );
		try {
			wp_cache_delete( static::option(),'options' ); wp_cache_delete( 'alloptions','options' ); wp_cache_delete( 'notoptions','options' );
			if ( ! hash_equals( static::expected(),$expected ) ) return new \WP_Error( 'kiwe_app_stale','This App changed. Refresh before saving.' );
			if ( $enabled && ! Runtime_Registry::installed( static::ID ) ) return new \WP_Error( 'kiwe_app_package','Install and activate this App before turning it on.' );
			$value = $enabled ? '1' : '0'; update_option( static::option(),$value,false );
			if ( get_option( static::option(),null ) !== $value ) return new \WP_Error( 'kiwe_app_uncertain','The App change could not be confirmed. Refresh and review.' );
			return true;
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)',$lock ) ); }
	}
	public function save( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! $this->permission( $r ) ) return UI::failure( new \WP_Error( 'kiwe_app_forbidden','Your website manager controls this App.' ),403 );
		$v = $r->get_json_params();
		if ( ! is_array( $v ) || count( $v ) !== 2 || array_diff( array_keys( $v ),[ 'enabled','expected' ] ) || ! is_bool( $v['enabled'] ?? null ) || ! is_string( $v['expected'] ?? null ) ) return UI::failure( new \WP_Error( 'kiwe_app_value','Choose on or off.' ),400 );
		$result = static::change( $v['enabled'],$v['expected'] );
		return is_wp_error( $result ) ? UI::failure( $result,409 ) : UI::response( [ 'app'=>static::card(),'reload'=>true ] );
	}
	public function handle(): void {
		if ( ! static::allowed() || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! Origin_Checker::is_same_site_request() ) wp_die( 'This App is unavailable.','',[ 'response'=>403 ] );
		check_admin_referer( static::action() ); $v = wp_unslash( $_POST );
		if ( ! in_array( $v['enabled'] ?? null,[ '0','1' ],true ) || ! is_string( $v['expected'] ?? null ) ) wp_die( 'Choose a valid App state.','',[ 'response'=>400 ] );
		$result = static::change( '1' === $v['enabled'],$v['expected'] );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ),'',[ 'response'=>409 ] );
		wp_safe_redirect( static::home_url() ); exit;
	}
}
