<?php

namespace DSA\Access;

use DSA\Utilities\Origin_Checker;
use DSA\Site\Site_Identity_Service;
use DSA\Design\Seam_Token_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Opt-in React writing surface over the native bounded post service. */
final class Post_App_Service {
	public function register(): void {
		if ( ! Post_Workspace_Service::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'admin_menu', [ $this, 'menu' ], 30 );
		if ( is_admin() ) add_action( 'load-toplevel_page_' . Post_Workspace_Service::PAGE, [ $this, 'document' ] );
	}
	public function menu(): void {
		if ( ! Post_Workspace_Service::enabled() || ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) return;
		add_menu_page( __( 'Posts', 'dsa' ), __( 'Posts', 'dsa' ), 'edit_posts', Post_Workspace_Service::PAGE, [ $this, 'document' ], 'dashicons-admin-post', 29 );
	}
	public function permission( \WP_REST_Request $request ): bool {
		if ( ! Post_Workspace_Service::enabled() || ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) || ! in_array( $request->get_method(), [ 'GET', 'POST' ], true ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}
	public function routes(): void {
		if ( ! Post_Workspace_Service::enabled() ) return;
		register_rest_route( 'dsa/v1', '/workspace/posts', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'listing' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'create' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/posts/terms', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'terms' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'create_term' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/posts/authors', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'authors' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/posts/(?P<id>[1-9][0-9]*)', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'update' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/posts/(?P<id>[1-9][0-9]*)/revisions', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'revisions' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/posts/(?P<id>[1-9][0-9]*)/revisions/(?P<revision_id>[1-9][0-9]*)/restore', [
			[ 'methods'=>'POST', 'callback'=>[ $this, 'restore_revision' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/posts/(?P<id>[1-9][0-9]*)/autosave', [
			[ 'methods'=>'POST', 'callback'=>[ $this, 'autosave' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/posts/(?P<id>[1-9][0-9]*)/autosave/(?P<autosave_id>[1-9][0-9]*)/discard', [
			[ 'methods'=>'POST', 'callback'=>[ $this, 'discard_autosave' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
	}
	private function response( $value ): \WP_REST_Response {
		if ( is_wp_error( $value ) ) return Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? 400 ) );
		return Task_Workspace_UI::response( $value );
	}
	public function listing( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); return $this->response( ( new Post_Workspace_Service() )->listing( $request->get_query_params() ) ); }
	public function terms( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); return $this->response( ( new Post_Workspace_Service() )->terms( $request->get_query_params() ) ); }
	public function create_term( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); $body=$request->get_json_params(); return $this->response( ( new Post_Workspace_Service() )->create_term( is_array( $body ) ? $body : [] ) ); }
	public function authors( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); return $this->response( ( new Post_Workspace_Service() )->authors( $request->get_query_params() ) ); }
	public function read( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); return $this->response( ( new Post_Workspace_Service() )->post( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ) ) ); }
	public function create( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); $body=$request->get_json_params(); return $this->response( ( new Post_Workspace_Service() )->save( 0, is_array( $body ) ? $body : [] ) ); }
	public function update( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); $body=$request->get_json_params(); return $this->response( ( new Post_Workspace_Service() )->save( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_array( $body ) ? $body : [] ) ); }
	public function revisions( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); return $this->response( ( new Post_Workspace_Service() )->revisions( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ) ) ); }
	public function restore_revision( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); $body=$request->get_json_params(); return $this->response( ( new Post_Workspace_Service() )->restore_revision( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), Task_Workspace_UI::id( $request->get_url_params()['revision_id'] ?? 0 ), is_array( $body ) ? $body : [] ) ); }
	public function autosave( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); $body=$request->get_json_params(); return $this->response( ( new Post_Workspace_Service() )->autosave( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_array( $body ) ? $body : [] ) ); }
	public function discard_autosave( \WP_REST_Request $request ): \WP_REST_Response { if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_post_forbidden', __( 'Posts are unavailable for your account.', 'dsa' ), [ 'status'=>403 ] ) ); $body=$request->get_json_params(); return $this->response( ( new Post_Workspace_Service() )->discard_autosave( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), Task_Workspace_UI::id( $request->get_url_params()['autosave_id'] ?? 0 ), is_array( $body ) ? $body : [] ) ); }
	public function document(): void {
		if ( ! Post_Workspace_Service::enabled() || ! is_admin() || is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) return;
		nocache_headers(); header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true ); header( 'X-Robots-Tag: noindex, nofollow', true ); header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		Workspace_Media::enqueue();
		wp_enqueue_style( 'kiwe-posts-app', DSA_URL . 'assets/css/posts-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [ 'wp-block-library', 'wp-block-editor', 'wp-edit-blocks', 'wp-format-library', 'wp-components' ] ), DSA_VERSION );
		wp_enqueue_script( 'kiwe-posts-app', DSA_URL . 'assets/js/posts-app.js', [ 'wp-element', 'wp-blocks', 'wp-block-editor', 'wp-block-library', 'wp-data', 'wp-components', 'wp-rich-text', 'wp-format-library' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-posts-app', 'KIWE_POSTS_APP', [ 'endpoint'=>rest_url( 'dsa/v1/workspace/posts' ), 'controlsEndpoint'=>rest_url( 'dsa/v1/workspace/record-controls' ), 'navigationEndpoint'=>rest_url( 'dsa/v1/workspace/navigation' ), 'seoEndpoint'=>\DSA\AI\AI_Feature_Policy::seo_enabled() ? rest_url( 'dsa/v1/workspace/seo' ) : '', 'tagGroupEndpoint'=>current_user_can( 'edit_posts' ) ? rest_url( 'dsa/v1/workspace/tag-groups' ) : '', 'nonce'=>wp_create_nonce( 'wp_rest' ), 'home'=>Workspace_Admin_Service::url(), ...Workspace_Chrome_Service::projection(), ...( class_exists( Content_Archive_App_Service::class ) ? Content_Archive_App_Service::config() : [] ), 'siteTimeZone'=>wp_timezone_string(), 'canUpload'=>current_user_can( 'upload_files' ), 'canPublish'=>current_user_can( 'publish_posts' ), 'canCreate'=>current_user_can( 'create_posts' ), 'canSeeAuthors'=>current_user_can( 'edit_others_posts' ), 'canSeoPosts'=>current_user_can( 'edit_posts' ) && current_user_can( 'publish_posts' ), 'canSeoMedia'=>current_user_can( 'upload_files' ), 'seoUrl'=>\DSA\Apps\SEO_App_Service::url(), 'guestUrl'=>admin_url( 'admin.php?page=' . Guest_App_Service::PAGE ), 'initialTask'=>'new' === ( $_GET['task'] ?? '' ) ? 'new' : '', 'initialStatus'=>in_array( $_GET['status'] ?? '', [ 'draft','pending','publish','future' ], true ) ? $_GET['status'] : 'all' ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( __( 'Posts', 'dsa' ) . ' · ' . get_bloginfo( 'name' ) ); ?></title><?php Workspace_Media::styles(); wp_print_styles( [ 'kiwe-posts-app' ] ); ?></head><body class="kiwe-posts-app"><div id="kiwe-posts-app"><p><?php esc_html_e( 'Opening your posts…', 'dsa' ); ?></p><noscript><?php esc_html_e( 'This writing screen needs JavaScript. Switch off the experiment to restore the native editor.', 'dsa' ); ?></noscript></div><?php Workspace_Media::footer(); wp_print_scripts( [ 'wp-element', 'kiwe-posts-app' ] ); ?></body></html><?php exit;
	}
}
