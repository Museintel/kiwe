<?php

namespace DSA\Access;

use DSA\Onboarding\SEO_Context_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Small controls on native WP Pages, never a second page inventory/editor. */
final class Page_Workspace_Service {
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 31 );
		add_filter( 'manage_pages_columns', [ $this, 'columns' ], 100 );
		add_action( 'manage_pages_custom_column', [ $this, 'column' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'row_actions' ], PHP_INT_MAX, 2 );
		add_filter( 'bulk_actions-edit-page', [ $this, 'bulk_actions' ], PHP_INT_MAX );
		add_action( 'admin_post_kiwe_create_page', [ $this, 'handle_create' ] );
		add_action( 'admin_post_kiwe_page_indexing', [ $this, 'handle_indexing' ] );
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
		add_submenu_page( 'edit.php?post_type=page', 'Page details', $this->client() ? 'Add Page' : 'Page indexing', $cap, 'kiwe-page-details', [ $this, 'render' ] );
		if ( $this->client() ) remove_submenu_page( 'edit.php?post_type=page', 'post-new.php?post_type=page' );
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
		echo '<br><a href="' . esc_url( self::url( $id ) ) . '">Change indexing</a>';
	}
	public function row_actions( array $actions, $post ): array {
		if ( ! $this->client() || ! $this->allowed() ) return $actions;
		// Allowlist hides third-party builder/clone/quick-edit actions as well.
		return array_merge( array_intersect_key( $actions, [ 'view'=>true ] ), [ 'kiwe_details'=>'<a href="' . esc_url( self::url( (int) $post->ID ) ) . '">View details / indexing</a>' ] );
	}
	public function bulk_actions( array $actions ): array { return $this->client() ? [] : $actions; }

	public function render(): void {
		if ( ! $this->allowed() ) wp_die( 'Page management is unavailable.', '', [ 'response'=>403 ] );
		$id = absint( $_GET['page_id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( $id && ( ! $post || 'page' !== $post->post_type || in_array( $post->post_status, [ 'trash','auto-draft' ], true ) ) ) wp_die( 'Page unavailable.', '', [ 'response'=>404 ] );
		echo '<div class="wrap"><h1>' . ( $post ? esc_html( $post->post_title ) : 'Add Page' ) . '</h1><p><a href="' . esc_url( admin_url( 'edit.php?post_type=page' ) ) . '">All Pages</a></p>';
		if ( isset( $_GET['saved'] ) ) echo '<div class="notice notice-success"><p>Page details saved.</p></div>';
		echo '<p>Page content and layout are managed by your designer. New pages start as drafts. Allowing indexing is not a guarantee of search inclusion: draft/private status, site-wide settings and search engines still apply.</p>';
		if ( $post ) echo '<p>Status: ' . esc_html( $post->post_status ) . '</p><div>' . wp_kses_post( wpautop( $post->post_content ) ) . '</div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . ( $post ? 'kiwe_page_indexing' : 'kiwe_create_page' ) . '">';
		wp_nonce_field( $post ? 'kiwe_page_indexing_' . $id : 'kiwe_create_page' );
		if ( $post ) echo '<input type="hidden" name="page_id" value="' . esc_attr( (string) $id ) . '">';
		else echo '<p><label>Page title <input name="page_title" class="regular-text" maxlength="200" required></label></p>';
		echo '<p><label>Search indexing <select name="indexing"><option value="index"' . selected( $post && ! SEO_Context_Service::page_noindex( $id ), true, false ) . '>Allow indexing when published</option><option value="noindex"' . selected( ! $post || SEO_Context_Service::page_noindex( $id ), true, false ) . '>Noindex — keep out of search results</option></select></label></p>';
		submit_button( $post ? 'Save indexing' : 'Create draft page' );
		echo '</form></div>';
	}

	public function handle_create(): void {
		check_admin_referer( 'kiwe_create_page' );
		if ( ! $this->allowed() || ! ( current_user_can( 'manage_options' ) || current_user_can( 'kiwe_create_page_drafts' ) ) ) wp_die( 'Page creation is unavailable.', '', [ 'response'=>403 ] );
		$id = $this->create_draft( (array) wp_unslash( $_POST ) );
		if ( is_wp_error( $id ) ) wp_die( esc_html( $id->get_error_message() ), '', [ 'response'=>400 ] );
		wp_safe_redirect( add_query_arg( 'saved', 1, self::url( $id ) ) ); exit;
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
		wp_safe_redirect( add_query_arg( 'saved', 1, self::url( $id ) ) ); exit;
	}
	public function save_indexing( int $id, string $mode ) {
		if ( ! $this->allowed() ) return new \WP_Error( 'kiwe_page_forbidden', 'Page indexing is unavailable.' );
		$post = get_post( $id );
		if ( ! $post || 'page' !== $post->post_type || in_array( $post->post_status, [ 'trash','auto-draft' ], true ) || ! in_array( $mode, [ 'index','noindex' ], true ) ) return new \WP_Error( 'kiwe_page_indexing', 'Invalid page or indexing setting.' );
		return SEO_Context_Service::set_page_indexing( $id, 'noindex' === $mode );
	}
}
