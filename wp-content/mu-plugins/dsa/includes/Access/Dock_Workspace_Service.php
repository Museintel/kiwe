<?php
namespace DSA\Access;

use DSA\Settings;
use DSA\Dock\Dock_Settings_Service;
use DSA\Modules\Module_Registry;
use DSA\Theme\Theme_Package_Service;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private presentation of existing Dock settings. Never initializes a visitor surface. */
final class Dock_Workspace_Service {
	public const PAGE = 'kiwe-dock-settings';
	public const CAPABILITY = 'kiwe_manage_navigation';
	public static function allowed(): bool { return current_user_can( self::CAPABILITY ); }
	public static function url(): string { return admin_url( 'admin.php?page=' . self::PAGE ); }
	public static function enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 34 );
		if ( ! self::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		if ( is_admin() ) add_action( 'load-toplevel_page_' . self::PAGE, [ $this, 'document' ], 1 );
	}
	public function menu(): void {
		if ( self::allowed() ) add_menu_page( __( 'Dock', 'dsa' ), __( 'Dock', 'dsa' ), self::CAPABILITY, self::PAGE, [ $this, 'fallback' ], 'dashicons-menu', 34 );
	}
	public function fallback(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Dock', 'dsa' ) . '</h1><p>' . esc_html__( 'The Dock workspace requires the client dashboard. Your website manager can also use native Kiwe Dock settings.', 'dsa' ) . '</p></div>';
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/dock', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'save' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		return self::enabled() && is_user_logged_in() && self::allowed()
			&& in_array( $request->get_method(), [ 'GET', 'POST' ], true )
			&& wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' )
			&& Origin_Checker::is_same_site_request()
			&& ( 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request ) );
	}
	private function labels( array $dock ): array {
		$registry = new Module_Registry(); $registry->register_defaults(); $labels = [];
		foreach ( $registry->public_modules() as $id=>$module ) if ( in_array( $module['mode'] ?? '', [ 'dock', 'action' ], true ) ) $labels[ $id ] = $module['label'];
		if ( ! \DSA\Apps\Store_App_Service::enabled() ) unset( $labels['cart'] );
		$normalizer = new Dock_Settings_Service( $labels );
		return $labels + $normalizer->dock_custom_item_labels( $normalizer->dock_custom_items_for_admin( $dock ) );
	}
	/** Public choices only; never send provider credentials or the complete Settings row. */
	public function snapshot(): array {
		$all = ( new Settings() )->all(); $dock = $all['dock']; $choices = [ 'enabled'=>(bool) $all['enabled'] ];
		$choices['dock'] = array_intersect_key( $dock, $this->fields()['dock'] );
		$labels = $this->labels( $dock );
		$choices['dock']['enabled_items'] = array_intersect_key( $dock['enabled_items'], $labels );
		$choices['dock']['item_order'] = array_values( array_filter( $dock['item_order'], static fn( $id ) => isset( $labels[ $id ] ) ) );
		$choices['style'] = array_intersect_key( $all['style'], $this->fields()['style'] );
		$choices['dsa_theme'] = array_intersect_key( $all['dsa_theme'], $this->fields()['dsa_theme'] );
		$choices['commerce'] = \DSA\Apps\Store_App_Service::enabled() ? array_intersect_key( $all['commerce'], $this->fields()['commerce'] ) : [];
		if ( !isset( $labels[$choices['dock']['focus_item']] ) ) $choices['dock']['focus_item'] = '';
		$themes = ( new Theme_Package_Service() )->all(); $catalog = [];
		foreach ( $themes as $theme ) $catalog[] = array_intersect_key( ( new Theme_Package_Service() )->public_record( $theme ), array_flip( [ 'id', 'name', 'description' ] ) );
		$menus = [];
		foreach ( wp_get_nav_menus( [ 'number'=>100, 'orderby'=>'name' ] ) as $menu ) $menus[] = [ 'id'=>(int) $menu->term_id, 'name'=>$menu->name, 'count'=>(int) $menu->count ];
		return [ 'schema'=>'kiwe.dock-workspace.v1', 'choices'=>$choices, 'expected'=>hash( 'sha256', wp_json_encode( [ $choices, hash( 'sha256', wp_json_encode( $themes ) ), \DSA\Apps\Store_App_Service::enabled() ] ) ), 'labels'=>$this->labels( $dock ), 'menus'=>$menus, 'themes'=>$catalog,
			'woo'=>\DSA\Apps\Store_App_Service::enabled(), 'phonekey'=>! empty( $all['phonekey']['enabled'] ), 'navigationUrl'=>Navigation_Workspace_Service::url() ];
	}
	/** Each field describes its existing native storage and accepted input type. */
	public function fields(): array {
		$dock = [ 'visible'=>'bool', 'enabled_items'=>'map', 'item_order'=>'order', 'focus_item'=>'item', 'menu_nav_ids'=>'menus', 'menu_label'=>'text', 'menu_context_title'=>'text',
			'phonekey_visibility'=>[ 'all','visitors','users','customers','admins' ], 'presentation'=>[ 'dock','navbar' ], 'shape'=>[ 'pill','box','square' ], 'material'=>[ 'glass','solid' ],
			'split_style'=>'bool', 'hide_frontend_admin_bar'=>'bool', 'menu_context_enabled'=>'bool' ];
		foreach ( [ 'desktop','tablet','mobile' ] as $device ) $dock += [ $device.'_orientation'=>[ 'auto','vertical','horizontal' ], $device.'_vertical_edge'=>[ 'left','right' ], $device.'_vertical_position'=>[ 'center','bottom' ], $device.'_horizontal_edge'=>[ 'top','bottom' ], $device.'_horizontal_position'=>[ 'left','center','right' ], $device.'_horizontal_vertical_position'=>[ 'center','bottom' ] ];
		return [ 'dock'=>$dock, 'style'=>[ 'active_theme_id'=>'theme', 'mode'=>[ 'classic','sheet' ], 'sheet_position'=>[ 'bottom','right','left' ], 'sheet_animation'=>[ 'slide','soft','snap' ], 'sheet_backdrop'=>[ 'blur','fade' ], 'sheet_spacing'=>[ 'edge','inset' ], 'sheet_duration_ms'=>'duration', 'sheet_max_height'=>'height', 'sheet_width_percent'=>'width', 'sheet_side_max_width'=>'sidewidth' ], 'dsa_theme'=>[ 'active_color'=>'color', 'hover_color'=>'color' ], 'commerce'=>[ 'cart_surface_enabled'=>'bool', 'cart_quantity_controls'=>'bool' ] ];
	}
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_dock_forbidden', 'Dock settings are unavailable for your account.' ), 403 );
		return Task_Workspace_UI::response( $this->snapshot() );
	}
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_dock_forbidden', 'Dock settings are unavailable for your account.' ), 403 );
		$body = $request->get_json_params();
		$result = $this->update( is_array( $body ) ? $body : [] );
		return is_wp_error( $result ) ? Task_Workspace_UI::failure( $result, (int) ( $result->get_error_data()['status'] ?? 400 ) ) : Task_Workspace_UI::response( $result );
	}
	public function update( array $body ) {
		if ( ! self::allowed() ) return new \WP_Error( 'kiwe_dock_forbidden', 'Dock settings are unavailable for your account.', [ 'status'=>403 ] );
		$snapshot = $this->snapshot(); $changes = $body['changes'] ?? null;
		if ( array_diff( array_keys( $body ), [ 'expected','changes' ] ) ) return new \WP_Error( 'kiwe_dock_value', 'Refresh and choose a Dock setting to save.' );
		if ( ! is_string( $body['expected'] ?? null ) || ! hash_equals( $snapshot['expected'], $body['expected'] ) ) return new \WP_Error( 'kiwe_dock_conflict', 'Dock settings changed. Refresh from the website before saving.', [ 'status'=>409 ] );
		if ( ! is_array( $changes ) || ! $changes || count( $changes ) > 5 ) return new \WP_Error( 'kiwe_dock_value', 'Choose a Dock setting to save.' );
		$settings = new Settings(); $raw = get_option( DSA_OPTION_SETTINGS, null ); $all = $settings->all();
		if ( ! is_array( $raw ) ) return new \WP_Error( 'kiwe_dock_state', 'Refresh the website settings before saving.', [ 'status'=>409 ] );
		// Re-read after taking the raw CAS snapshot, so an intervening native save is refused.
		if ( $snapshot['expected'] !== $this->snapshot()['expected'] ) return new \WP_Error( 'kiwe_dock_conflict', 'Dock settings changed. Refresh before saving.', [ 'status'=>409 ] );
		$patch = $this->prepare_patch( $changes, $all, $snapshot );
		if ( is_wp_error( $patch ) ) return $patch;
		if ( ! $settings->compare_and_update_selected( $raw, $patch ) ) return new \WP_Error( 'kiwe_dock_conflict', 'Website settings changed during saving. Refresh and review the saved choices.', [ 'status'=>409 ] );
		do_action( 'litespeed_purge_all' );
		return $this->snapshot();
	}
	/** Validate and normalize for either editing entrance; the caller owns one atomic save. */
	public function prepare_patch( array $changes, array $all, array $snapshot ) {
		if ( ! self::allowed() ) return new \WP_Error( 'kiwe_dock_forbidden', 'Dock settings are unavailable for your account.', [ 'status'=>403 ] );
		$patch = []; $fields = $this->fields(); $labels = $snapshot['labels'];
		foreach ( $changes as $group=>$values ) {
			if ( 'enabled' === $group && is_bool( $values ) ) { $patch['enabled'] = $values; continue; }
			if ( ! isset( $fields[ $group ] ) || ! is_array( $values ) || count( $values ) > 40 ) return new \WP_Error( 'kiwe_dock_value', 'This Dock setting is unavailable.' );
			foreach ( $values as $key=>$value ) {
				$type = $fields[ $group ][ $key ] ?? null; $valid = false;
				if ( is_array( $type ) ) $valid = is_string( $value ) && in_array( $value, $type, true );
				elseif ( 'bool' === $type ) $valid = is_bool( $value );
				elseif ( 'text' === $type ) $valid = is_string( $value ) && strlen( $value ) <= 160;
				elseif ( 'color' === $type ) $valid = is_string( $value ) && (bool) preg_match( '/^#[a-f0-9]{6}$/iD', $value );
				elseif ( 'item' === $type ) $valid = is_string( $value ) && ( '' === $value || isset( $labels[ $value ] ) );
				elseif ( 'theme' === $type ) $valid = is_string( $value ) && $value === ( new Theme_Package_Service() )->sanitize_id( $value ) && [] !== ( new Theme_Package_Service() )->find( $value );
				elseif ( in_array( $type, [ 'duration','height','width','sidewidth' ], true ) ) { $range = [ 'duration'=>[120,900], 'height'=>[45,96], 'width'=>[50,90], 'sidewidth'=>[280,1200] ][ $type ]; $valid = is_int( $value ) && $value >= $range[0] && $value <= $range[1]; }
				elseif ( 'map' === $type ) {
					$valid = is_array( $value ) && count( $value ) <= 32;
					if ( $valid ) foreach ( $value as $id=>$on ) if ( ! isset( $labels[ $id ] ) || ! is_bool( $on ) || ( 'cart' === $id && $on && ! $snapshot['woo'] && empty( $all['dock']['enabled_items']['cart'] ) ) ) $valid = false;
				} elseif ( in_array( $type, [ 'order','menus' ], true ) ) {
					$valid = is_array( $value ) && array_is_list( $value ) && count( $value ) <= ( 'menus' === $type ? 100 : 32 ) && count( array_unique( $value, SORT_REGULAR ) ) === count( $value );
					if ( $valid ) foreach ( $value as $id ) if ( 'menus' === $type ? ( ! is_int( $id ) || ! is_nav_menu( $id ) ) : ( ! is_string( $id ) || ! isset( $labels[ $id ] ) ) ) $valid = false;
				}
				if ( ! $valid || ( 'commerce' === $group && ! $snapshot['woo'] ) ) return new \WP_Error( 'kiwe_dock_value', 'A Dock choice is invalid. Refresh and review it.' );
			}
			$patch[ $group ] = $values;
		}
		$normalizer = new Dock_Settings_Service( $labels );
		if ( isset( $patch['style']['active_theme_id'] ) ) {
			// Select the installed skin and its native foundation; retain this site's layout and module choices.
			$themes = new Theme_Package_Service(); $record = $themes->find( $patch['style']['active_theme_id'] );
			if ( [] === $record ) return new \WP_Error( 'kiwe_dock_conflict', 'This theme is no longer installed. Refresh and choose another.', [ 'status'=>409 ] );
			$overlay = $themes->safe_settings_overlay( $record, $all );
			$patch['style']['visual_profile'] = $overlay['style']['visual_profile'];
		}
		if ( isset( $patch['dock'] ) ) {
			$input = $patch['dock'];
			if ( isset( $input['enabled_items'] ) ) $input['enabled_items'] = array_replace( $all['dock']['enabled_items'], $input['enabled_items'] );
			if ( isset( $input['menu_nav_ids'] ) ) $input['menu_nav_id'] = $input['menu_nav_ids'][0] ?? 0;
			$normalized = $normalizer->sanitize_dock_settings( wp_slash( $input ), $all['dock'] );
			$keys = array_fill_keys( array_keys( $input ), true );
			if ( isset( $input['presentation'] ) ) $keys['fill_axis'] = true;
			$patch['dock'] = array_intersect_key( $normalized, $keys );
			// Hidden cart preferences survive reordering or changing other visible buttons.
			if ( ! \DSA\Apps\Store_App_Service::enabled() ) {
				if ( isset( $patch['dock']['enabled_items'] ) && array_key_exists( 'cart', $all['dock']['enabled_items'] ) ) $patch['dock']['enabled_items']['cart'] = $all['dock']['enabled_items']['cart'];
				if ( isset( $patch['dock']['item_order'] ) && false !== ( $index = array_search( 'cart', $all['dock']['item_order'], true ) ) ) array_splice( $patch['dock']['item_order'], min( $index, count( $patch['dock']['item_order'] ) ), 0, [ 'cart' ] );
			}

		}
		foreach ( [ 'style'=>'sanitize_style_settings', 'dsa_theme'=>'sanitize_dsa_theme' ] as $group=>$method ) if ( isset( $patch[ $group ] ) ) $patch[ $group ] = array_intersect_key( $normalizer->$method( wp_slash( $patch[ $group ] ), $all[ $group ] ), $patch[ $group ] );
		// An explicit request to show the Dock must work on an older site-graph-only
		// installation too. Hiding it never disables attributed surfaces or App support.
		if ( ! empty( $patch['dock']['visible'] ) && empty( $all['enabled'] ) ) $patch['enabled'] = true;
		return $patch;
	}
	public function document(): void {
		if ( ! self::allowed() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) return;
		// Retain old bookmarks without maintaining a second client editing home.
		if ( App_Workspace_Service::enabled() ) { wp_safe_redirect( App_Workspace_Service::url( 'settings', 'dock' ) ); exit; }
		nocache_headers(); header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true ); header( 'X-Robots-Tag: noindex, nofollow', true ); header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		wp_enqueue_style( 'kiwe-dock-app', DSA_URL . 'assets/css/dock-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION );
		wp_enqueue_script( 'kiwe-dock-app', DSA_URL . 'assets/js/dock-app.js', [ 'wp-element' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-dock-app', 'KIWE_DOCK_APP', [ 'endpoint'=>rest_url( 'dsa/v1/workspace/dock' ), 'nonce'=>wp_create_nonce( 'wp_rest' ), 'home'=>Workspace_Admin_Service::url(), ...Workspace_Chrome_Service::projection() ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'Dock · Kiwe', 'dsa' ); ?></title><?php wp_print_styles( [ 'kiwe-dock-app' ] ); ?></head><body class="kiwe-dock-app"><div id="kiwe-dock-app"><p><?php esc_html_e( 'Opening Dock settings…', 'dsa' ); ?></p></div><?php wp_print_scripts( [ 'wp-element', 'kiwe-dock-app' ] ); ?></body></html><?php exit;
	}
}
