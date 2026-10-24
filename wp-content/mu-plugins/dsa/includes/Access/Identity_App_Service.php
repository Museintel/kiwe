<?php
namespace DSA\Access;

use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Utilities\Origin_Checker;
use DSA\Design\Seam_Token_Service;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Opt-in React identity surface over Design Context's existing native writers. */
final class Identity_App_Service {
	public static function enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }
	public function register(): void {
		if ( ! self::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'admin_menu', [ $this, 'menu' ], 30 );
		if ( is_admin() ) add_action( 'load-toplevel_page_kiwe-identity', [ $this, 'document' ] );
	}
	public function menu(): void {
		if ( ! self::enabled() || ! current_user_can( 'kiwe_manage_context' ) ) return;
		add_menu_page( __( 'Business identity', 'dsa' ), __( 'Website identity', 'dsa' ), 'kiwe_manage_context', 'kiwe-identity', [ $this, 'document' ], 'dashicons-admin-site-alt3', 31 );
	}
	public function routes(): void {
		if ( ! self::enabled() ) return;
		register_rest_route( 'dsa/v1', '/workspace/identity', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'save' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		if ( ! self::enabled() || ! is_user_logged_in() || ! current_user_can( 'kiwe_manage_context' ) || ! in_array( $request->get_method(), [ 'GET', 'POST' ], true ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}
	private function response( $value ): \WP_REST_Response {
		if ( is_wp_error( $value ) ) return Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? 400 ) );
		return Task_Workspace_UI::response( $value );
	}
	private function denied(): \WP_REST_Response { return $this->response( new \WP_Error( 'kiwe_identity_forbidden', __( 'Business identity is unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); }
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) ) return $this->denied();
		return $this->response( ( new Design_Context_Profile_Service() )->identity_projection() );
	}
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->denied();
		$body = $request->get_json_params(); if ( ! is_array( $body ) ) $body = [];
		if ( array_diff( array_keys( $body ), [ 'expected','changes' ] ) || ! is_string( $body['expected'] ?? null ) || ! is_array( $body['changes'] ?? null ) ) return $this->response( new \WP_Error( 'kiwe_identity_request', __( 'Refresh business identity and save only the details you changed.', 'dsa' ), [ 'status'=>400 ] ) );
		return $this->response( ( new Design_Context_Profile_Service() )->save_identity_changes( $body['changes'], $body['expected'], get_current_user_id() ) );
	}
	public function document(): void {
		if ( ! self::enabled() || ! is_admin() || is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() || ! current_user_can( 'kiwe_manage_context' ) || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) return;
		nocache_headers(); header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true ); header( 'X-Robots-Tag: noindex, nofollow', true ); header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		Workspace_Media::enqueue();
		wp_enqueue_style( 'kiwe-identity-app', DSA_URL . 'assets/css/identity-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION );
		wp_enqueue_script( 'kiwe-identity-app', DSA_URL . 'assets/js/identity-app.js', [ 'wp-element', 'media-editor', 'media-views' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-identity-app', 'KIWE_IDENTITY_APP', [ 'endpoint'=>rest_url( 'dsa/v1/workspace/identity' ), 'nonce'=>wp_create_nonce( 'wp_rest' ), 'home'=>Workspace_Admin_Service::url(), ...Workspace_Chrome_Service::projection(), 'contact'=>admin_url( 'admin.php?page=kiwe-contact' ), 'areas'=>( new Continuity_App_Service() )->area_urls() ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( __( 'Website identity', 'dsa' ) . ' · ' . get_bloginfo( 'name' ) ); ?></title><?php Workspace_Media::styles(); wp_print_styles( [ 'kiwe-identity-app' ] ); ?></head><body class="kiwe-identity-app"><div id="kiwe-identity-app"><p><?php esc_html_e( 'Opening business identity…', 'dsa' ); ?></p><noscript><?php esc_html_e( 'This identity screen needs JavaScript. Switch off the experiment to restore the existing identity editor.', 'dsa' ); ?></noscript></div><?php Workspace_Media::footer(); wp_print_scripts( [ 'wp-element', 'kiwe-identity-app' ] ); ?></body></html><?php exit;
	}
}
