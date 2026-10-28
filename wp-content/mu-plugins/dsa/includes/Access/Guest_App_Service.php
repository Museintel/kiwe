<?php

namespace DSA\Access;

use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native React adapter for Guest applications, review and contribution work. */
final class Guest_App_Service {
	public const PAGE = 'kiwe-guests';

	public static function enabled(): bool { return \DSA\Apps\Guest_App_Service::enabled() && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }

	public function register(): void {
		if ( ! self::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		if ( is_admin() ) {
			add_action( 'load-toplevel_page_' . self::PAGE, [ $this, 'document' ], 1 );
			// Guest's menu is owned by the native fallback service. Some WordPress
			// menu registries assign a different screen suffix, so bind the exact
			// requested page before admin chrome starts as a fail-safe.
			add_action( 'admin_init', [ $this, 'requested_document' ], 1 );
		}
	}

	public function requested_document(): void {
		$page = is_scalar( $_GET['page'] ?? null ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( self::PAGE === $page ) $this->document();
	}

	private function can_any(): bool {
		return is_user_logged_in() && ( current_user_can( 'kiwe_manage_guest_applications' ) || current_user_can( 'edit_others_posts' ) || current_user_can( 'edit_others_products' ) || current_user_can( 'kiwe_guest_submit' ) );
	}

	public function permission( \WP_REST_Request $request ): bool {
		if ( ! self::enabled() || ! $this->can_any() || ! in_array( $request->get_method(), [ 'GET', 'POST' ], true ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}

	public function routes(): void {
		if ( ! self::enabled() ) return;
		register_rest_route( 'dsa/v1', '/workspace/guests', [ 'methods' => 'GET', 'callback' => [ $this, 'read' ], 'permission_callback' => [ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/guests/applications/(?P<id>[1-9][0-9]*)', [ 'methods' => 'POST', 'callback' => [ $this, 'decide' ], 'permission_callback' => [ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/guests/settings', [ 'methods' => 'POST', 'callback' => [ $this, 'settings' ], 'permission_callback' => [ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/guests/submissions', [ 'methods' => 'POST', 'callback' => [ $this, 'submit' ], 'permission_callback' => [ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/guests/submissions/(?P<id>[1-9][0-9]*)', [ 'methods' => 'POST', 'callback' => [ $this, 'review' ], 'permission_callback' => [ $this, 'permission' ] ] );
	}

	private function response( $value, int $status = 200 ): \WP_REST_Response {
		if ( is_wp_error( $value ) ) return Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? $status ) );
		return Task_Workspace_UI::response( $value );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_guest_forbidden', __( 'Guest work is unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		return $this->response( ( new Guest_Contribution_Service() )->workspace( $request->get_query_params() ) );
	}

	public function decide( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_guest_forbidden', __( 'Guest applications are unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		$result = ( new Guest_Contribution_Service() )->decide_application( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_string( $body['decision'] ?? null ) ? $body['decision'] : '', is_string( $body['reason'] ?? null ) ? $body['reason'] : '', is_string( $body['expected'] ?? null ) ? $body['expected'] : '' );
		return $this->response( $result, 409 );
	}

	public function settings( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_guest_forbidden', __( 'Guest settings are unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		$body['expected'] = is_string( $body['expected'] ?? null ) ? $body['expected'] : '';
		return $this->response( ( new Guest_Contribution_Service() )->save_workspace_settings( $body ) );
	}

	public function submit( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_guest_forbidden', __( 'Guest submissions are unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		return $this->response( ( new Guest_Contribution_Service() )->submit_workspace( $body ) );
	}

	public function review( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_guest_forbidden', __( 'Guest review is unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		return $this->response( ( new Guest_Contribution_Service() )->review_submission( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), $body ), 409 );
	}

	public function document(): void {
		if ( ! self::enabled() || ! is_admin() || is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! $this->can_any() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) ) return;
		nocache_headers(); header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true ); header( 'X-Robots-Tag: noindex, nofollow', true ); header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		wp_enqueue_editor();
		wp_enqueue_script( 'wp-i18n' );
		wp_enqueue_style( 'kiwe-guest-app', \DSA\Apps\Asset_Locator::url('assets/css/guest-app.css'), \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), \DSA\Apps\Asset_Locator::version('assets/css/guest-app.css') );
		wp_enqueue_script( 'kiwe-guest-app', \DSA\Apps\Asset_Locator::url('assets/js/guest-app.js'), [ 'wp-element' ], \DSA\Apps\Asset_Locator::version('assets/js/guest-app.js'), true );
		wp_localize_script( 'kiwe-guest-app', 'KIWE_GUEST_APP', [ 'endpoint' => rest_url( 'dsa/v1/workspace/guests' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'home' => Workspace_Admin_Service::url(), ...Workspace_Chrome_Service::projection() ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'Guests · Kiwe', 'dsa' ); ?></title><?php wp_print_styles( [ 'editor-buttons', 'kiwe-guest-app' ] ); ?></head><body class="kiwe-guest-app"><div id="kiwe-guest-app"><p><?php esc_html_e( 'Opening Guest work…', 'dsa' ); ?></p><noscript><?php esc_html_e( 'This workspace needs JavaScript. Switch off the experiment to restore the native Guest screen.', 'dsa' ); ?></noscript></div><script>var ajaxurl=<?php echo wp_json_encode( admin_url( 'admin-ajax.php', 'relative' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script><?php wp_print_scripts( [ 'wp-i18n', 'editor', 'quicktags', 'wp-element' ] ); \_WP_Editors::print_default_editor_scripts(); wp_print_scripts( [ 'kiwe-guest-app' ] ); ?></body></html><?php exit;
	}
}
