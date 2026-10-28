<?php
namespace DSA\Access;

use DSA\Apps\Required_App_Service as App;
use DSA\PhoneKey\PhoneKey_Bridge;
use DSA\Secure\SecureTrack_Settings_Policy;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One private editing home for the two included Core Apps. No authentication engine changes. */
final class System_App_Workspace_Service {
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 38 );
		add_action( 'admin_init', [ $this, 'legacy_redirect' ], 4 );
	}
	public function menu(): void {
		foreach ( App::IDS as $id ) {
			if ( ! App::allowed( $id ) ) continue;
			$callback = fn() => $this->document( $id );
			$hook = add_submenu_page( null, ucfirst( $id ), ucfirst( $id ), App::capability( $id ), 'kiwe-' . $id . '-app', $callback );
			add_action( 'load-' . $hook, $callback, 1 );
		}
	}
	public function legacy_redirect(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) return;
		$page = is_string( $_GET['page'] ?? null ) ? $_GET['page'] : '';
		$id = 'kiwe-auth' === $page ? 'auth' : ( 'stp-settings' === $page || ( 'kiwe-secure' === $page && 'settings' === ( $_GET['tab'] ?? '' ) ) ? 'security' : '' );
		if ( $id && App::allowed( $id ) ) { wp_safe_redirect( App::url( $id ) ); exit; }
	}
	public function routes(): void {
		System_App_Routes::register();
	}
	private function app_id( \WP_REST_Request $request ): string { $params = $request->get_url_params(); return is_string( $params['app'] ?? null ) ? $params['app'] : ''; }
	public function permission( \WP_REST_Request $request ): bool {
		return App::allowed( $this->app_id( $request ) ) && in_array( $request->get_method(), [ 'GET','POST' ], true ) && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && Origin_Checker::is_same_site_request() && ( 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request ) );
	}
	private function option( string $id ): string { return 'auth' === $id ? 'pk_settings_v3' : 'stp_settings'; }
	private function expected( string $id ): string { return hash_hmac( 'sha256', serialize( [ get_current_user_id(), $id, get_option( $this->option( $id ), [] ), 'security' === $id ? ( (array) get_option( DSA_OPTION_SETTINGS, [] ) )['secure'] ?? [] : [] ] ), wp_salt( 'nonce' ) ); }
	private static function value( array $values, string $path ) { foreach ( explode( '.', $path ) as $key ) $values = is_array( $values ) ? ( $values[$key] ?? null ) : null; return $values; }
	private static function assign( array &$values, string $path, $value ): void { $target =& $values; foreach ( explode( '.', $path ) as $key ) $target =& $target[$key]; $target = $value; }
	public function snapshot( string $id ): array {
		$native = 'auth' === $id ? PhoneKey_Bridge::admin_settings() : SecureTrack_Settings_Policy::notification_policy();
		if ( 'security' === $id ) $native['kiwe'] = ( new \DSA\Settings() )->get( 'secure', [] );
		$fields = System_Control_Catalogue::fields( $id, App::technical() ); $values = []; $secret_set = [];
		foreach ( $fields as $key=>$field ) {
			$value = self::value( $native, $key );
			if ( 'secret' === $field['type'] ) { $values[$key] = ''; $secret_set[$key] = is_string( $value ) && '' !== $value; }
			elseif ( 'bool' === $field['type'] ) $values[$key] = (bool) $value;
			elseif ( 'int' === $field['type'] ) $values[$key] = (int) $value;
			elseif ( 'roles' === $field['type'] ) $values[$key] = is_array( $value ) ? array_values( $value ) : [];
			else $values[$key] = is_scalar( $value ) ? (string) $value : '';
		}
		$links = 'security' === $id ? [ [ 'label'=>'Security activity', 'url'=>admin_url( 'admin.php?page=kiwe-secure&tab=events' ) ], [ 'label'=>'Notification delivery choices', 'url'=>admin_url( 'admin.php?page=kiwe-notifications&view=preferences' ) ], [ 'label'=>'Visitor measurement', 'url'=>Analytics_Workspace_Service::url() ] ] : [];
		if ( App::technical() && 'security' === $id ) $links[] = [ 'label'=>'Technical recovery and diagnostics', 'url'=>admin_url( 'admin.php?page=kiwe-secure&tab=protections' ) ];
		return [ 'app'=>$id, 'title'=>ucfirst( $id ), 'included'=>true, 'available'=>! empty( $native ), 'fields'=>array_values( $fields ), 'values'=>$values, 'secretSet'=>$secret_set, 'roles'=>wp_roles()->get_names(), 'expected'=>$this->expected( $id ), 'links'=>$links ];
	}
	private function error( string $message, int $status = 409 ): \WP_Error { return new \WP_Error( 'kiwe_system_app', $message, [ 'status'=>$status ] ); }
	public function update( string $id, array $input ) {
		if ( ! App::allowed( $id ) ) return $this->error( 'These settings are unavailable for your account.', 403 );
		if ( array_diff( array_keys( $input ), [ 'expected','changes' ] ) || ! is_string( $input['expected'] ?? null ) || ! is_array( $input['changes'] ?? null ) || ! $input['changes'] || count( $input['changes'] ) > 100 ) return $this->error( 'Choose the settings you want to change.', 400 );
		$fields = System_Control_Catalogue::fields( $id, App::technical() ); $changes = [];
		foreach ( $input['changes'] as $key=>$value ) {
			if ( ! is_string( $key ) || ! isset( $fields[$key] ) ) return $this->error( 'That setting is unavailable for your account.', 403 );
			$field = $fields[$key]; $valid = false;
			switch ( $field['type'] ) {
				case 'bool': $valid = is_bool( $value ); break;
				case 'int': $valid = is_int( $value ) && $value >= $field['min'] && $value <= $field['max']; break;
				case 'choice': $valid = is_string( $value ) && array_key_exists( $value, $field['options'] ); break;
				case 'roles': $valid = is_array( $value ) && count( $value ) <= 100 && ! array_filter( $value, static fn( $role ) => ! is_string( $role ) || ! get_role( $role ) ); break;
				default: $valid = is_string( $value ) && strlen( $value ) <= ( $field['max'] ?? 2048 );
			}
			if ( ! $valid ) return $this->error( 'Review the value for ' . $field['label'] . '.', 400 );
			if ( 'secret' === $field['type'] && '' === trim( $value ) ) continue;
			if ( 'url' === $field['type'] && $value && ( ! wp_http_validate_url( $value ) || 'https' !== wp_parse_url( $value, PHP_URL_SCHEME ) ) ) return $this->error( 'Use a valid HTTPS URL.', 400 );
			if ( 'csp_report_uri' === $key && $value && wp_parse_url( $value, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) return $this->error( 'The report URL must belong to this website.', 400 );
			if ( 'default_country_code' === $key && ! preg_match( '/^\+[1-9][0-9]{0,3}$/D', $value ) ) return $this->error( 'Use a phone country code such as +91.', 400 );
			self::assign( $changes, $key, $value );
		}
		global $wpdb; $lock = 'kiwe-system-policy-' . substr( hash( 'sha256', $wpdb->prefix . ':' . $id ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) return $this->error( 'Another policy save is finishing. Try again shortly.' );
		$begun = false; $committed = false;
		$options = [ $this->option( $id ) ]; if ( 'security' === $id ) $options[] = DSA_OPTION_SETTINGS;
		try {
			$engine = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->options ) ), ARRAY_A );
			if ( ! is_array( $engine ) || 'innodb' !== strtolower( (string) ( $engine['Engine'] ?? '' ) ) ) return $this->error( 'These settings need the native transactional options store. Ask your website manager to review the database.', 503 );
			// Next-transaction scope is portable and refuses an already-active transaction.
			// Never START TRANSACTION over another plugin's pending native work.
			$errors = $wpdb->suppress_errors( true ); $admitted = false !== $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ); $wpdb->suppress_errors( $errors );
			if ( ! $admitted ) return $this->error( 'Another native transaction is active. Finish it before changing policy.', 503 );
			$begun = false !== $wpdb->query( 'START TRANSACTION' ); if ( ! $begun ) return $this->error( 'A safe settings save could not start.', 503 );
			$wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', array_fill( 0, count( $options ), '%s' ) ) . ') FOR UPDATE', $options ) );
			if ( $wpdb->last_error ) return $this->error( 'The settings are busy. Reload before saving.', 503 );
			foreach ( $options as $option ) wp_cache_delete( $option, 'options' ); wp_cache_delete( 'alloptions', 'options' );
			if ( ! hash_equals( $this->expected( $id ), $input['expected'] ) ) return $this->error( 'These settings changed elsewhere. Reload and review them before saving.' );
			if ( 'auth' === $id ) {
				$result = PhoneKey_Bridge::save_admin_settings( $changes ); if ( is_wp_error( $result ) ) return $result;
			} else {
				$kiwe = (array) ( $changes['kiwe'] ?? [] ); unset( $changes['kiwe'] );
				if ( $kiwe ) {
					$settings = new \DSA\Settings(); $raw = (array) get_option( DSA_OPTION_SETTINGS, [] );
					$current = (array) $settings->get( 'secure', [] );
					$sanitized = SecureTrack_Settings_Policy::sanitize_kiwe_settings( array_replace( $current, $kiwe ), $current );
					$selected = array_intersect_key( $sanitized, $kiwe );
					if ( ! $settings->compare_and_update_selected( $raw, [ 'secure'=>$selected ] ) ) return $this->error( 'Website security settings changed. Reload before saving.' );
					if ( array_intersect_key( $kiwe, array_flip( [ 'protection_profile','auto_logout_enabled','auto_logout_minutes','auto_logout_roles' ] ) ) ) SecureTrack_Settings_Policy::sync_from_kiwe( (array) $settings->get( 'secure', [] ) );
				}
				if ( $changes ) { $result = SecureTrack_Settings_Policy::update_app_policy( $changes ); if ( is_wp_error( $result ) ) return $result; }
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) return $this->error( 'The settings save could not be confirmed. Reload before retrying.', 503 );
			$committed = true; return $this->snapshot( $id );
		} catch ( \Throwable $error ) { return $this->error( 'The save could not be confirmed. Reload and check the stored settings before retrying.', 503 ); }
		finally {
			if ( $begun && ! $committed ) $wpdb->query( 'ROLLBACK' );
			foreach ( $options as $option ) wp_cache_delete( $option, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
			if ( 'security' === $id ) SecureTrack_Settings_Policy::refresh_runtime_cache();
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return Task_Workspace_UI::failure( $this->error( 'These settings are unavailable.', 403 ), 403 );
		return Task_Workspace_UI::response( $this->snapshot( $this->app_id( $request ) ) );
	}
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return Task_Workspace_UI::failure( $this->error( 'These settings are unavailable.', 403 ), 403 );
		$body = $request->get_json_params(); $result = $this->update( $this->app_id( $request ), is_array( $body ) ? $body : [] );
		return is_wp_error( $result ) ? Task_Workspace_UI::failure( $result, (int) ( $result->get_error_data()['status'] ?? 400 ) ) : Task_Workspace_UI::response( $result );
	}
	public function document( string $id ): void {
		if ( ! App::allowed( $id ) ) wp_die( 'These settings are unavailable for your account.', '', [ 'response'=>403 ] );
		Task_Workspace_UI::app_document( 'system', ucfirst( $id ), [ 'endpoint'=>rest_url( 'dsa/v1/workspace/system/' . $id ), 'app'=>$id, 'appsHome'=>\DSA\Apps\Store_App_Service::home_url() ] );
	}
}
