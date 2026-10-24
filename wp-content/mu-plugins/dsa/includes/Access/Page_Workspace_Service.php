<?php

namespace DSA\Access;

use DSA\Onboarding\SEO_Context_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Small controls on native WP Pages, never a second page inventory/editor. */
final class Page_Workspace_Service {
	private string $screen = '';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_menu', [ $this, 'menu' ], 31 );
		add_filter( 'manage_pages_columns', [ $this, 'columns' ], 100 );
		add_action( 'manage_pages_custom_column', [ $this, 'column' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'row_actions' ], PHP_INT_MAX, 2 );
		add_filter( 'bulk_actions-edit-page', [ $this, 'bulk_actions' ], PHP_INT_MAX );
		add_action( 'admin_post_kiwe_create_page', [ $this, 'handle_create' ] );
		add_action( 'admin_post_kiwe_page_indexing', [ $this, 'handle_indexing' ] );
		add_action( 'admin_post_kiwe_page_title', [ $this, 'handle_title' ] );
	}

	private function allowed(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'kiwe_manage_page_indexing' );
	}
	private function client(): bool { return Site_Owner_Service::enabled() && ! Site_Owner_Service::is_owner(); }
	public static function url( int $id = 0 ): string {
		return add_query_arg( [ 'post_type'=>'page', 'page'=>'kiwe-page-details', 'page_id'=>$id ], admin_url( 'edit.php' ) );
	}
	public function menu(): void {
		if ( ! $this->allowed() ) return;
		$cap = current_user_can( 'manage_options' ) ? 'manage_options' : 'kiwe_manage_page_indexing';
		$this->screen = (string) add_submenu_page( 'edit.php?post_type=page', 'Page details', $this->client() ? 'Add Page' : 'Page details', $cap, 'kiwe-page-details', [ $this, 'render' ] );
		if ( $this->client() ) remove_submenu_page( 'edit.php?post_type=page', 'post-new.php?post_type=page' );
	}
	public function assets( string $hook ): void {
		if ( ! $this->screen || $hook !== $this->screen || ! $this->allowed() ) return;
		Workspace_Design_Service::enqueue();
		wp_enqueue_style( 'kiwe-task-workspaces', DSA_URL . 'assets/css/task-workspaces.css', [ 'kiwe-app-primitives' ], DSA_VERSION );
		if ( ! $this->can_rename() || empty( $_GET['page_id'] ) ) return;
		wp_enqueue_script( 'kiwe-page-name', DSA_URL . 'assets/js/workspace-page-name.js', [], DSA_VERSION, true );
		wp_localize_script( 'kiwe-page-name', 'KIWE_PAGE_NAME', [ 'nonce'=>wp_create_nonce( 'wp_rest' ), 'saving'=>__( 'Saving page name…', 'dsa' ), 'changed'=>__( 'You have unsaved changes.', 'dsa' ), 'unchanged'=>__( 'No unsaved changes.', 'dsa' ), 'uncertain'=>__( 'The save could not be confirmed. Your text is still here. Reload this page to check its current name before trying again.', 'dsa' ) ] );
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/pages/(?P<id>\d+)/name', [
			'methods'=>'POST', 'callback'=>[ $this, 'rest_title' ], 'permission_callback'=>[ $this, 'rest_title_permission' ],
			'args'=>[ 'id'=>[ 'type'=>'integer','minimum'=>1,'required'=>true ], 'page_title'=>[ 'type'=>'string','minLength'=>1,'maxLength'=>200,'required'=>true ], 'expected_title'=>[ 'type'=>'string','required'=>true ] ],
		] );
	}
	public function rest_title_permission(): bool { return $this->allowed() && $this->can_rename(); }
	public function rest_title( \WP_REST_Request $request ) {
		if ( ! $this->rest_title_permission() ) return new \WP_Error( 'kiwe_page_forbidden', __( 'Page naming is unavailable.', 'dsa' ), [ 'status'=>403 ] );
		$id = (int) $request->get_param( 'id' );
		$result = $this->rename_page( $id, [ 'page_title'=>$request->get_param( 'page_title' ), 'expected_title'=>$request->get_param( 'expected_title' ) ] );
		if ( is_wp_error( $result ) ) { $result->add_data( [ 'status'=>'kiwe_page_conflict' === $result->get_error_code() ? 409 : 400 ] ); return $result; }
		$post = get_post( $id );
		if ( ! $post ) return new \WP_Error( 'kiwe_page_unconfirmed', __( 'The page could not be read back. Reload before trying again.', 'dsa' ), [ 'status'=>409 ] );
		$response = new \WP_REST_Response( [ 'id'=>$id,'title'=>$post->post_title,'message'=>__( 'Page name saved.', 'dsa' ) ], 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
	public function columns( array $columns ): array {
		if ( ! $this->allowed() ) return $columns;
		if ( $this->client() ) unset( $columns['cb'] );
		$columns['kiwe_indexing'] = 'Search indexing';
		return $columns;
	}
	public function column( string $column, int $id ): void {
		if ( 'kiwe_indexing' !== $column || ! $this->allowed() ) return;
		echo esc_html( SEO_Context_Service::page_noindex( $id ) ? 'Noindex' : 'Indexing allowed' );
		echo '<br><a href="' . esc_url( self::url( $id ) ) . '">Name and indexing</a>';
	}
	public function row_actions( array $actions, $post ): array {
		if ( ! $this->client() || ! $this->allowed() ) return $actions;
		// Allowlist hides third-party builder/clone/quick-edit actions as well.
		return array_merge( array_intersect_key( $actions, [ 'view'=>true ] ), [ 'kiwe_details'=>'<a href="' . esc_url( self::url( (int) $post->ID ) ) . '">Name / indexing</a>' ] );
	}
	public function bulk_actions( array $actions ): array { return $this->client() ? [] : $actions; }

	public function render(): void {
		if ( ! $this->allowed() ) wp_die( 'Page management is unavailable.', '', [ 'response'=>403 ] );
		$id = absint( $_GET['page_id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( $id && ( ! $post || 'page' !== $post->post_type || in_array( $post->post_status, [ 'trash','auto-draft' ], true ) ) ) wp_die( 'Page unavailable.', '', [ 'response'=>404 ] );
		echo '<div class="wrap kiwe-tasks"><p class="kiwe-tasks__eyebrow">' . esc_html__( 'Your website', 'dsa' ) . '</p><h1 data-kiwe-page-heading>' . ( $post ? esc_html( $post->post_title ) : 'Add Page' ) . '</h1><p><a href="' . esc_url( admin_url( 'edit.php?post_type=page' ) ) . '">All Pages</a></p>';
		$receipt = sanitize_key( (string) ( $_GET['page_saved'] ?? '' ) );
		if ( preg_match( '/^[a-f0-9]{32}$/', $receipt ) ) {
			$key = 'kiwe_page_saved_' . get_current_user_id() . '_' . $receipt;
			if ( (int) get_transient( $key ) === $id && $id > 0 ) {
				delete_transient( $key );
				echo '<div class="notice notice-success"><p>Page details saved.</p></div>';
			}
		}
		echo '<p>Page content and layout are managed by your designer. New pages start as drafts. Allowing indexing is not a guarantee of search inclusion: draft/private status, site-wide settings and search engines still apply.</p>';
		if ( $post && $this->can_rename() ) {
			if ( current_user_can( 'kiwe_manage_navigation' ) ) echo '<p><a href="' . esc_url( Navigation_Workspace_Service::url( 'page', $id ) ) . '">' . esc_html__( 'Manage this page in Navigation', 'dsa' ) . '</a></p>';
			echo '<section class="kiwe-tasks__card kiwe-app-card"><h2>' . esc_html__( 'Page name', 'dsa' ) . '</h2><form data-kiwe-page-name data-rest-url="' . esc_url( rest_url( 'dsa/v1/workspace/pages/' . $id . '/name' ) ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="kiwe_page_title"><input type="hidden" name="page_id" value="' . esc_attr( (string) $id ) . '">';
			echo '<input type="hidden" name="expected_title" value="' . esc_attr( $post->post_title ) . '">';
			wp_nonce_field( 'kiwe_page_title_' . $id );
			echo '<p><label for="kiwe-page-title">Page title</label><br><input id="kiwe-page-title" name="page_title" class="large-text" maxlength="200" required value="' . esc_attr( $post->post_title ) . '"></p>';
			echo '<p>The existing URL and design stay in place. Connected headings and default menu labels follow the name; custom labels keep their text.</p>';
			echo '<div class="kiwe-tasks__actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save page name', 'dsa' ) . '</button><button type="reset" class="button" data-kiwe-reset hidden>' . esc_html__( 'Undo changes', 'dsa' ) . '</button></div><p class="kiwe-app-state" data-kiwe-save-state role="status" aria-live="polite" hidden></p>';
			echo '</form></section>';
		}
		if ( $post ) echo '<details class="kiwe-tasks__card"><summary>' . esc_html__( 'Current page content', 'dsa' ) . '</summary><p>' . esc_html__( 'Status:', 'dsa' ) . ' ' . esc_html( $post->post_status ) . '</p><div>' . wp_kses_post( wpautop( $post->post_content ) ) . '</div></details>';
		echo '<section class="kiwe-tasks__card"><h2>' . esc_html( $post ? __( 'Search visibility', 'dsa' ) : __( 'New page brief', 'dsa' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . ( $post ? 'kiwe_page_indexing' : 'kiwe_create_page' ) . '">';
		wp_nonce_field( $post ? 'kiwe_page_indexing_' . $id : 'kiwe_create_page' );
		if ( $post ) echo '<input type="hidden" name="page_id" value="' . esc_attr( (string) $id ) . '">';
		else echo '<p><label>Page title <input name="page_title" class="regular-text" maxlength="200" required></label></p>';
		echo '<p><label>Search indexing <select name="indexing"><option value="index"' . selected( $post && ! SEO_Context_Service::page_noindex( $id ), true, false ) . '>Allow indexing when published</option><option value="noindex"' . selected( ! $post || SEO_Context_Service::page_noindex( $id ), true, false ) . '>Noindex — keep out of search results</option></select></label></p>';
		submit_button( $post ? 'Save indexing' : 'Create draft page' );
		echo '</form></section></div>';
	}

	public function handle_create(): void {
		check_admin_referer( 'kiwe_create_page' );
		if ( ! $this->allowed() || ! ( current_user_can( 'manage_options' ) || current_user_can( 'kiwe_create_page_drafts' ) ) ) wp_die( 'Page creation is unavailable.', '', [ 'response'=>403 ] );
		$id = $this->create_draft( (array) wp_unslash( $_POST ) );
		if ( is_wp_error( $id ) ) wp_die( esc_html( $id->get_error_message() ), '', [ 'response'=>400 ] );
		$this->saved_redirect( $id );
	}
	public function create_draft( array $input ) {
		if ( ! $this->allowed() || ! ( current_user_can( 'manage_options' ) || current_user_can( 'kiwe_create_page_drafts' ) ) ) return new \WP_Error( 'kiwe_page_forbidden', 'Page creation is unavailable.' );
		$title = sanitize_text_field( $input['page_title'] ?? '' );
		if ( '' === $title || preg_match_all( '/./us', $title ) > 200 ) return new \WP_Error( 'kiwe_page_title', 'Enter a page title of at most 200 characters.' );
		if ( ! in_array( $input['indexing'] ?? '', [ 'index','noindex' ], true ) ) return new \WP_Error( 'kiwe_page_indexing', 'Choose an indexing setting.' );
		// Never pass arbitrary submitted post fields (ID/content/author/status/meta) to core.
		$id = wp_insert_post( [ 'post_type'=>'page', 'post_title'=>$title, 'post_content'=>'', 'post_status'=>'draft', 'post_author'=>get_current_user_id() ], true );
		if ( is_wp_error( $id ) || ! $id ) return is_wp_error( $id ) ? $id : new \WP_Error( 'kiwe_page_create', 'Could not create the draft.' );
		$saved = SEO_Context_Service::set_page_indexing( (int) $id, 'noindex' === $input['indexing'] );
		if ( is_wp_error( $saved ) ) return new \WP_Error( 'kiwe_page_indexing', 'The draft was created, but its indexing setting could not be saved. Open All Pages and retry its indexing setting; do not create it again.' );
		return (int) $id;
	}
	public function handle_indexing(): void {
		$id = absint( $_POST['page_id'] ?? 0 );
		check_admin_referer( 'kiwe_page_indexing_' . $id );
		if ( ! $this->allowed() ) wp_die( 'Page indexing is unavailable.', '', [ 'response'=>403 ] );
		$result = $this->save_indexing( $id, sanitize_key( $_POST['indexing'] ?? '' ) );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', [ 'response'=>400 ] );
		$this->saved_redirect( $id );
	}
	public function save_indexing( int $id, string $mode ) {
		if ( ! $this->allowed() ) return new \WP_Error( 'kiwe_page_forbidden', 'Page indexing is unavailable.' );
		$post = get_post( $id );
		if ( ! $post || 'page' !== $post->post_type || in_array( $post->post_status, [ 'trash','auto-draft' ], true ) || ! in_array( $mode, [ 'index','noindex' ], true ) ) return new \WP_Error( 'kiwe_page_indexing', 'Invalid page or indexing setting.' );
		return SEO_Context_Service::set_page_indexing( $id, 'noindex' === $mode );
	}

	private function can_rename(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'kiwe_manage_page_titles' );
	}
	public function handle_title(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'Use the page-name form.', '', [ 'response'=>405 ] );
		if ( ! $this->allowed() || ! $this->can_rename() ) wp_die( 'Page naming is unavailable.', '', [ 'response'=>403 ] );
		$id = absint( $_POST['page_id'] ?? 0 );
		check_admin_referer( 'kiwe_page_title_' . $id );
		$result = $this->rename_page( $id, (array) wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', [ 'response'=>400 ] );
		$this->saved_redirect( $id );
	}
	public function rename_page( int $id, array $input ) {
		if ( ! $this->allowed() || ! $this->can_rename() ) return new \WP_Error( 'kiwe_page_forbidden', 'Page naming is unavailable.' );
		$post = get_post( $id );
		if ( ! $post || 'page' !== $post->post_type || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) return new \WP_Error( 'kiwe_page_unavailable', 'Page unavailable.' );
		if ( ! is_string( $input['page_title'] ?? null ) || ! is_string( $input['expected_title'] ?? null ) ) return new \WP_Error( 'kiwe_page_title', 'Enter a page title.' );
		$length = preg_match_all( '/./us', $input['page_title'] );
		if ( false === $length || $length > 200 ) return new \WP_Error( 'kiwe_page_title', 'Enter a page title of at most 200 characters.' );
		$title = sanitize_text_field( $input['page_title'] );
		if ( '' === $title ) return new \WP_Error( 'kiwe_page_title', 'Enter a page title.' );
		if ( $input['expected_title'] !== $post->post_title ) return new \WP_Error( 'kiwe_page_conflict', 'This page name changed since you opened it. Reload the details before saving.' );
		if ( $title === $post->post_title ) return true;
		// Dedicated title authority never grants general page/Bricks editing.
		$result = wp_update_post( wp_slash( [ 'ID'=>$id, 'post_title'=>$title, 'post_name'=>$post->post_name ] ), true );
		if ( is_wp_error( $result ) || ! $result ) return new \WP_Error( 'kiwe_page_write', 'The page name could not be saved.' );
		$updated = get_post( $id );
		if ( ! $updated || $updated->post_title !== $title ) return new \WP_Error( 'kiwe_page_write', 'The page name could not be confirmed. Reload its details before retrying.' );
		return true;
	}
	private function saved_redirect( int $id ): void {
		$receipt = str_replace( '-', '', wp_generate_uuid4() );
		set_transient( 'kiwe_page_saved_' . get_current_user_id() . '_' . $receipt, $id, 120 );
		wp_safe_redirect( add_query_arg( 'page_saved', $receipt, self::url( $id ) ) ); exit;
	}
}
