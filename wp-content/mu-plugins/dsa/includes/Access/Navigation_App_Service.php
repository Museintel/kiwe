<?php

namespace DSA\Access;

use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private React adapter for the single native WordPress menu used by DSA. */
final class Navigation_App_Service {
	public static function enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }
	public function register(): void {
		if ( ! self::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		if ( is_admin() ) add_action( 'load-toplevel_page_' . Navigation_Workspace_Service::PAGE, [ $this, 'document' ], 1 );
	}
	public function routes(): void {
		if ( ! self::enabled() ) return;
		register_rest_route( 'dsa/v1', '/workspace/navigation', [ 'methods'=>'GET', 'callback'=>[ $this, 'listing' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/navigation/groups', [ 'methods'=>'POST', 'callback'=>[ $this, 'groups' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/navigation/move', [ 'methods'=>'POST', 'callback'=>[ $this, 'move' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/navigation/(?P<id>[1-9][0-9]*)', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'save' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		if ( ! self::enabled() || ! is_user_logged_in() || ! current_user_can( 'kiwe_manage_navigation' ) || ! in_array( $request->get_method(), [ 'GET', 'POST' ], true ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}
	private function response( $value, int $status = 200 ): \WP_REST_Response { if ( is_wp_error( $value ) ) return Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? $status ) ); return Task_Workspace_UI::response( $value, $status ); }
	private function denied(): \WP_REST_Response { return $this->response( new \WP_Error( 'kiwe_navigation_forbidden', __( 'Frontend navigation is unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ), 403 ); }
	private function menu( int $id ) { $menus = ( new Navigation_Workspace_Service() )->menu_list(); foreach ( $menus as $menu ) if ( $menu['id'] === $id ) return $menu; return new \WP_Error( 'kiwe_menu_missing', __( 'Choose an existing menu.', 'dsa' ) ); }
	public function listing( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied();
		$service = new Navigation_Workspace_Service(); $menus = $service->menu_list(); $id = Task_Workspace_UI::id( $request->get_param( 'menu_id' ) ?: ( $menus[0]['id'] ?? 0 ) );
		$selected = $id ? $this->menu( $id ) : null;
		$search = Task_Workspace_UI::text( $request->get_param( 'search' ) ); $page = max( 1, (int) $request->get_param( 'page' ) );
		$kinds = [ 'page'=>'Pages', 'post'=>'Posts' ];
		if ( post_type_exists( 'product' ) ) $kinds['product'] = 'Products';
		$kinds['category'] = 'Post categories';
		if ( class_exists( Taxonomy_App_Service::class ) ) foreach ( ( new Taxonomy_App_Service() )->definitions() as $taxonomy=>$definition ) $kinds[ $taxonomy ] = $definition['label'];
		$stored = get_option( \DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE, [] );
		$service_type = is_array( $stored ) ? sanitize_key( (string) ( $stored['services']['sourcePostType'] ?? '' ) ) : '';
		if ( $service_type && post_type_exists( $service_type ) ) $kinds['service'] = 'Services';
		$sources = [];
		foreach ( $kinds as $kind=>$label ) $sources[ $kind ] = strlen( $search ) >= 2 ? $service->sources( $kind, $search, $page ) : [ 'items'=>[], 'page'=>$page, 'has_next'=>false, 'search'=>$search ];
		$kind = (string) $request->get_param( 'source_kind' ); $source_id = (int) $request->get_param( 'source_id' );
		if ( $source_id && isset( $kinds[ $kind ] ) ) {
			$focus = $service->focused_source( $kind, $source_id ); $key = $kind;
			if ( ! is_wp_error( $focus ) ) {
				// A Home or content-card shortcut names one exact record. Keep it first even
				// when it already appears in the current result page, without duplicating it.
				$others = array_values( array_filter( $sources[ $key ]['items'], static fn( array $item ): bool => (int) ( $item['id'] ?? 0 ) !== $source_id ) );
				$sources[ $key ]['items'] = array_slice( array_merge( [ $focus ], $others ), 0, 20 );
			}
		}
		return $this->response( [ 'menus'=>$menus, 'selected'=>$selected, 'sources'=>$sources, 'kinds'=>$kinds, 'groupExpected'=>$service->group_expected() ] );
	}
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied(); $id = Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ); $menu = $this->menu( $id ); if ( is_wp_error( $menu ) ) return $this->response( $menu );
		$service = new Navigation_Workspace_Service(); return $this->response( [ 'menu'=>$menu ] );
	}
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied(); $id = Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ); $body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		$result = ( new Navigation_Workspace_Service() )->change( $id, $body ); if ( is_wp_error( $result ) ) return $this->response( $result, 409 );
		$menu = $this->menu( $id ); if ( is_wp_error( $menu ) ) return $this->response( $menu, 409 ); return $this->response( [ 'menu'=>$menu, 'message'=>__( 'Frontend navigation saved to your website.', 'dsa' ) ] );
	}
	public function groups( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied();
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		$service = new Navigation_Workspace_Service();
		$expected = $body['expected'] ?? null;
		if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) return $this->response( new \WP_Error( 'kiwe_menu_expected', 'Reload navigation before changing groups.', [ 'status'=>409 ] ), 409 );
		if ( 'create' === ( $body['operation'] ?? '' ) && count( $body ) === 3 && is_string( $body['name'] ?? null ) ) $result = $service->create_group( $body['name'], $expected );
		elseif ( 'configure' === ( $body['operation'] ?? '' ) && count( $body ) === 4 && is_array( $body['ids'] ?? null ) && is_array( $body['limits'] ?? null ) ) $result = $service->configure_groups( $body['ids'], $body['limits'], $expected );
		elseif ( 'contents' === ( $body['operation'] ?? '' ) && count( $body ) === 5 && is_int( $body['id'] ?? null ) && is_bool( $body['enabled'] ?? null ) && is_string( $body['title'] ?? null ) ) $result = $service->configure_contents( $body['id'], $body['enabled'], $body['title'], $expected );
		elseif ( 'rename' === ( $body['operation'] ?? '' ) && count( $body ) === 4 && is_int( $body['id'] ?? null ) && is_string( $body['name'] ?? null ) ) $result = $service->rename_group( $body['id'], $body['name'], $expected );
		elseif ( 'delete' === ( $body['operation'] ?? '' ) && count( $body ) === 4 && is_int( $body['id'] ?? null ) && is_string( $body['menu_expected'] ?? null ) ) $result = $service->delete_group( $body['id'], $expected, $body['menu_expected'] );
		else return $this->response( new \WP_Error( 'kiwe_menu_operation', 'Choose a valid group change.', [ 'status'=>400 ] ), 400 );
		if ( is_wp_error( $result ) ) return $this->response( $result, 409 );
		return $this->response( [ 'menus'=>$service->menu_list(), 'groupExpected'=>$service->group_expected(), 'createdId'=>is_int( $result ) ? $result : 0 ] );
	}
	public function move( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->denied();
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		if ( count( $body ) !== 5 || ! is_int( $body['sourceId'] ?? null ) || ! is_int( $body['targetId'] ?? null ) || ! is_array( $body['itemIds'] ?? null ) || ! is_string( $body['sourceExpected'] ?? null ) || ! is_string( $body['targetExpected'] ?? null ) ) return $this->response( new \WP_Error( 'kiwe_menu_move_input', 'Choose a source, destination and links to move.', [ 'status'=>400 ] ), 400 );
		$service = new Navigation_Workspace_Service();
		$result = $service->move_items( $body['sourceId'], $body['targetId'], $body['itemIds'], $body['sourceExpected'], $body['targetExpected'] );
		if ( is_wp_error( $result ) ) return $this->response( $result, 409 );
		return $this->response( [ 'menus'=>$service->menu_list(), 'groupExpected'=>$service->group_expected(), 'moved'=>count( $body['itemIds'] ) ] );
	}
	public function document(): void {
		if ( ! self::enabled() || ! is_admin() || is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() || ! current_user_can( 'kiwe_manage_navigation' ) || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) return;
		nocache_headers(); header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true ); header( 'X-Robots-Tag: noindex, nofollow', true ); header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		wp_enqueue_style( 'kiwe-navigation-app', DSA_URL . 'assets/css/navigation-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION ); wp_enqueue_script( 'kiwe-navigation-app', DSA_URL . 'assets/js/navigation-app.js', [ 'wp-element' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-navigation-app', 'KIWE_NAVIGATION_APP', [ 'endpoint'=>rest_url( 'dsa/v1/workspace/navigation' ), 'nonce'=>wp_create_nonce( 'wp_rest' ), 'home'=>Workspace_Admin_Service::url(), ...Workspace_Chrome_Service::projection(), 'sourceKind'=>sanitize_key( (string) ( $_GET['source_kind'] ?? '' ) ), 'sourceId'=>absint( $_GET['source_id'] ?? 0 ) ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'Frontend navigation · Kiwe', 'dsa' ); ?></title><?php wp_print_styles( [ 'kiwe-navigation-app' ] ); ?></head><body class="kiwe-navigation-app"><div id="kiwe-navigation-app"><p><?php esc_html_e( 'Opening Frontend navigation…', 'dsa' ); ?></p></div><?php wp_print_scripts( [ 'wp-element', 'kiwe-navigation-app' ] ); ?></body></html><?php exit;
	}
}
