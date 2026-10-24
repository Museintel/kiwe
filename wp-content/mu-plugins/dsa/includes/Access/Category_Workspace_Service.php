<?php

namespace DSA\Access;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Bounded, server-rendered tasks on native WordPress post categories. */
final class Category_Workspace_Service {
	public const PAGE_SIZE = 20;
	public const MOVE_LIMIT = 20;
	private string $screen = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 32 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_kiwe_category_create', [ $this, 'handle_create' ] );
		add_action( 'admin_post_kiwe_category_rename', [ $this, 'handle_rename' ] );
		add_action( 'admin_post_kiwe_category_move', [ $this, 'handle_move' ] );
	}

	public function can_manage(): bool {
		$taxonomy = get_taxonomy( 'category' );
		return $taxonomy && current_user_can( $taxonomy->cap->manage_terms );
	}

	public static function url( int $category_id = 0 ): string {
		return add_query_arg( [ 'page'=>'kiwe-categories', 'category_id'=>$category_id ], admin_url( 'edit.php' ) );
	}

	public function menu(): void {
		if ( ! $this->can_manage() ) return;
		$this->screen = (string) add_submenu_page( 'edit.php', __( 'Categories', 'dsa' ), __( 'Organize categories', 'dsa' ), get_taxonomy( 'category' )->cap->manage_terms, 'kiwe-categories', [ $this, 'render' ] );
		// Plugin pages fire their returned screen hook, not the parent load-edit.php.
		if ( '' !== $this->screen && class_exists( Taxonomy_App_Service::class ) && Taxonomy_App_Service::enabled() ) {
			add_action( 'load-' . $this->screen, [ new Taxonomy_App_Service(), 'category_document' ], 1 );
		}
	}

	public function assets( string $hook = '' ): void {
		if ( ! is_admin() || ! $this->can_manage() || '' === $this->screen || $hook !== $this->screen ) return;
		Task_Workspace_UI::native_form_assets();
		wp_enqueue_style( 'kiwe-category-workspace', DSA_URL . 'assets/css/category-workspace.css', [ 'kiwe-native-forms' ], DSA_VERSION );
	}

	private function forbidden(): \WP_Error {
		return new \WP_Error( 'kiwe_category_forbidden', __( 'Category management is unavailable for your account.', 'dsa' ) );
	}

	private function category( int $id ) {
		$term = $id > 0 ? get_term( $id, 'category' ) : null;
		return $term && ! is_wp_error( $term ) && 'category' === $term->taxonomy ? $term : new \WP_Error( 'kiwe_category_missing', __( 'This category is unavailable. Refresh and choose an existing category.', 'dsa' ) );
	}

	private function name( array $input ) {
		$name = isset( $input['name'] ) && is_string( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		$length = preg_match_all( '/./us', $name );
		return '' !== $name && false !== $length && $length <= 200 ? $name : new \WP_Error( 'kiwe_category_name', __( 'Enter a category name of 1 to 200 characters.', 'dsa' ) );
	}

	private function page( $value ): int {
		return is_scalar( $value ) ? max( 1, min( 10000, (int) $value ) ) : 1;
	}

	private function input_id( $value ): int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) return 0;
		return filter_var( $value, FILTER_VALIDATE_INT, [ 'options'=>[ 'min_range'=>1 ] ] ) ?: 0;
	}

	/** Search has a fixed taxonomy and a bounded lookahead; callers cannot inject query arguments. */
	public function list_categories( array $input = [] ) {
		if ( ! $this->can_manage() ) return $this->forbidden();
		$page = $this->page( $input['page'] ?? 1 );
		$search = isset( $input['search'] ) && is_string( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';
		$search = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 200 ) : substr( $search, 0, 200 );
		$terms = get_terms( [ 'taxonomy'=>'category', 'hide_empty'=>false, 'orderby'=>'name', 'order'=>'ASC', 'number'=>self::PAGE_SIZE + 1, 'offset'=>( $page - 1 ) * self::PAGE_SIZE, 'search'=>$search ] );
		if ( is_wp_error( $terms ) ) return new \WP_Error( 'kiwe_category_list', __( 'Categories could not be loaded. Try again.', 'dsa' ) );
		return [ 'items'=>array_slice( $terms, 0, self::PAGE_SIZE ), 'page'=>$page, 'has_more'=>count( $terms ) > self::PAGE_SIZE, 'search'=>$search ];
	}

	/** Only directly assigned ordinary posts are shown; child-category membership is not a move. */
	public function list_posts( int $category_id, int $page = 1 ) {
		if ( ! $this->can_manage() ) return $this->forbidden();
		$term = $this->category( $category_id );
		if ( is_wp_error( $term ) ) return $term;
		$page = $this->page( $page );
		$query = new \WP_Query( [ 'post_type'=>'post', 'post_status'=>[ 'publish','future','draft','pending','private' ], 'posts_per_page'=>self::PAGE_SIZE + 1, 'offset'=>( $page - 1 ) * self::PAGE_SIZE, 'orderby'=>'ID', 'order'=>'DESC', 'no_found_rows'=>true, 'ignore_sticky_posts'=>true, 'update_post_meta_cache'=>false, 'update_post_term_cache'=>false, 'tax_query'=>[ [ 'taxonomy'=>'category', 'field'=>'term_id', 'terms'=>[ $category_id ], 'include_children'=>false ] ] ] );
		$items = [];
		foreach ( array_slice( $query->posts, 0, self::PAGE_SIZE ) as $post ) {
			// Explicit statuses can include private/draft posts. Never reveal objects this actor cannot edit.
			if ( 'post' === $post->post_type && current_user_can( 'edit_post', (int) $post->ID ) ) $items[] = $post;
		}
		return [ 'items'=>$items, 'page'=>$page, 'has_more'=>count( $query->posts ) > self::PAGE_SIZE ];
	}

	public function create_category( array $input ) {
		if ( ! $this->can_manage() || ! current_user_can( get_taxonomy( 'category' )->cap->edit_terms ) ) return $this->forbidden();
		$name = $this->name( $input );
		if ( is_wp_error( $name ) ) return $name;
		// Core owns slug generation. Never forward submitted taxonomy/slug/parent/meta fields.
		$result = wp_insert_term( $name, 'category' );
		return is_wp_error( $result ) ? new \WP_Error( 'kiwe_category_create', __( 'The category could not be created. Check whether that name already exists.', 'dsa' ) ) : (int) $result['term_id'];
	}

	public static function fingerprint( $term ): string {
		return hash( 'sha256', wp_json_encode( [ (int) $term->term_id, $term->name, $term->slug, (int) $term->parent, $term->description ] ) );
	}

	public function rename_category( int $id, array $input ) {
		if ( ! $this->can_manage() || ! current_user_can( get_taxonomy( 'category' )->cap->edit_terms ) || ! current_user_can( 'edit_term', $id ) ) return $this->forbidden();
		$term = $this->category( $id );
		if ( is_wp_error( $term ) ) return $term;
		if ( array_key_exists( 'expected', $input ) && ( ! is_string( $input['expected'] ) || ! hash_equals( self::fingerprint( $term ), $input['expected'] ) ) ) return new \WP_Error( 'kiwe_category_stale', __( 'This category changed since the form opened. Refresh and review it before saving.', 'dsa' ) );
		$before = clone $term;
		$name = $this->name( $input );
		if ( is_wp_error( $name ) ) return $name;
		// A label change must preserve permalink slug, parent, description and all term metadata.
		$result = wp_update_term( $id, 'category', [ 'name'=>$name ] );
		$after = is_wp_error( $result ) ? $result : $this->category( $id );
		$before->name = $name;
		if ( is_wp_error( $after ) || ! hash_equals( self::fingerprint( $before ), self::fingerprint( $after ) ) ) return new \WP_Error( 'kiwe_category_rename', __( 'The category save could not be confirmed. Some details may have changed. Refresh and review it before trying again.', 'dsa' ) );
		return true;
	}

	/** Returns an explicit outcome for each selected post; failure never silently becomes success. */
	public function move_posts( int $source_id, int $target_id, array $post_ids ) {
		if ( ! $this->can_manage() ) return $this->forbidden();
		$taxonomy = get_taxonomy( 'category' );
		if ( ! current_user_can( $taxonomy->cap->assign_terms ) ) return $this->forbidden();
		foreach ( [ $source_id, $target_id ] as $id ) {
			$term = $this->category( $id );
			if ( is_wp_error( $term ) ) return $term;
		}
		if ( $source_id === $target_id ) return new \WP_Error( 'kiwe_category_target', __( 'Choose a different destination category.', 'dsa' ) );
		if ( ! $post_ids || count( $post_ids ) > self::MOVE_LIMIT ) return new \WP_Error( 'kiwe_category_selection', __( 'Choose between 1 and 20 posts to move.', 'dsa' ) );
		$ids = [];
		foreach ( $post_ids as $value ) {
			$id = $this->input_id( $value );
			if ( ! $id ) return new \WP_Error( 'kiwe_category_selection', __( 'The selection contains an invalid post. Refresh and select posts again.', 'dsa' ) );
			$ids[] = $id;
		}
		$ids = array_values( array_unique( $ids ) );
		$result = [ 'requested'=>count( $ids ), 'moved'=>0, 'failed'=>0, 'outcomes'=>[] ];
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$code = 'moved';
			if ( ! $post || 'post' !== $post->post_type || ! in_array( $post->post_status, [ 'publish','future','draft','pending','private' ], true ) ) $code = 'unavailable';
			elseif ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( $taxonomy->cap->assign_terms ) ) $code = 'forbidden';
			else {
				$before = wp_get_object_terms( $id, 'category', [ 'fields'=>'ids' ] );
				if ( is_wp_error( $before ) ) $code = 'read_failed';
				elseif ( ! in_array( $source_id, array_map( 'intval', $before ), true ) ) $code = 'source_changed';
				else {
					// Add/remove only these relationships, never replace a stale complete category list.
					$added = wp_add_object_terms( $id, [ $target_id ], 'category' );
					$after_add = is_wp_error( $added ) ? $added : wp_get_object_terms( $id, 'category', [ 'fields'=>'ids' ] );
					if ( is_wp_error( $after_add ) || ! in_array( $target_id, array_map( 'intval', $after_add ), true ) ) $code = 'target_failed';
					else {
						$removed = wp_remove_object_terms( $id, [ $source_id ], 'category' );
						$after = wp_get_object_terms( $id, 'category', [ 'fields'=>'ids' ] );
						if ( is_wp_error( $removed ) || false === $removed || is_wp_error( $after ) || in_array( $source_id, array_map( 'intval', $after ), true ) || ! in_array( $target_id, array_map( 'intval', $after ), true ) ) $code = 'incomplete';
					}
				}
			}
			$result['outcomes'][] = [ 'post_id'=>$id, 'status'=>$code ];
			$result[ 'moved' === $code ? 'moved' : 'failed' ]++;
		}
		return $result;
	}

	private function require_post( string $nonce ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $this->can_manage() ) wp_die( esc_html__( 'Category request unavailable.', 'dsa' ), '', [ 'response'=>403 ] );
		check_admin_referer( $nonce );
	}

	private function finish( int $id, array $result ): void {
		$key = str_replace( '-', '', wp_generate_uuid4() );
		if ( ! set_transient( 'kiwe_category_' . get_current_user_id() . '_' . $key, $result, 120 ) ) wp_die( esc_html__( 'The operation finished, but its receipt could not be saved. Refresh Categories to check the current state before trying again.', 'dsa' ), '', [ 'response'=>500 ] );
		wp_safe_redirect( add_query_arg( 'category_result', $key, self::url( $id ) ) ); exit;
	}

	private function fail( \WP_Error $error ): void {
		wp_die( esc_html( $error->get_error_message() ), '', [ 'response'=>400, 'back_link'=>true ] );
	}

	public function handle_create(): void {
		$this->require_post( 'kiwe_category_create' );
		$result = $this->create_category( (array) wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) { $this->fail( $result ); return; }
		$this->finish( $result, [ 'operation'=>'created' ] );
	}

	public function handle_rename(): void {
		$id = $this->input_id( $_POST['category_id'] ?? 0 );
		$this->require_post( 'kiwe_category_rename_' . $id );
		$result = $this->rename_category( $id, array_merge( [ 'expected'=>null ], (array) wp_unslash( $_POST ) ) );
		if ( is_wp_error( $result ) ) { $this->fail( $result ); return; }
		$this->finish( $id, [ 'operation'=>'renamed' ] );
	}

	public function handle_move(): void {
		$id = $this->input_id( $_POST['category_id'] ?? 0 );
		$this->require_post( 'kiwe_category_move_' . $id );
		$result = $this->move_posts( $id, $this->input_id( $_POST['target_id'] ?? 0 ), isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ? wp_unslash( $_POST['post_ids'] ) : [] );
		if ( is_wp_error( $result ) ) { $this->fail( $result ); return; }
		$this->finish( $id, [ 'operation'=>'moved' ] + $result );
	}

	private function receipt(): void {
		$key = $_GET['category_result'] ?? '';
		if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) return;
		$key = 'kiwe_category_' . get_current_user_id() . '_' . $key;
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) return;
		delete_transient( $key );
		$failed = (int) ( $result['failed'] ?? 0 );
		echo '<div class="notice ' . ( $failed ? 'notice-warning' : 'notice-success' ) . '" role="status"><p>';
		if ( 'moved' === ( $result['operation'] ?? '' ) ) {
			echo esc_html( sprintf( __( '%1$d of %2$d selected posts moved. %3$d could not be completed.', 'dsa' ), $result['moved'], $result['requested'], $failed ) );
		} else echo esc_html( 'created' === ( $result['operation'] ?? '' ) ? __( 'Category created.', 'dsa' ) : __( 'Category name saved. Its URL slug is unchanged.', 'dsa' ) );
		echo '</p>';
		if ( $failed ) {
			echo '<ul>';
			foreach ( (array) ( $result['outcomes'] ?? [] ) as $outcome ) {
				if ( 'moved' === $outcome['status'] ) continue;
				$messages = [ 'unavailable'=>__( 'Post unavailable.', 'dsa' ), 'forbidden'=>__( 'Your account cannot move this post.', 'dsa' ), 'read_failed'=>__( 'Categories could not be read.', 'dsa' ), 'source_changed'=>__( 'This post is no longer in the source category.', 'dsa' ), 'target_failed'=>__( 'Destination could not be confirmed. The source was retained.', 'dsa' ), 'incomplete'=>__( 'Move incomplete. The post may be in both categories; review it before retrying.', 'dsa' ) ];
				echo '<li>' . esc_html( sprintf( __( 'Post #%1$d: %2$s', 'dsa' ), $outcome['post_id'], $messages[ $outcome['status'] ] ?? __( 'Refresh and review this post.', 'dsa' ) ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	private function form_start( string $action, int $id = 0 ): void {
		echo '<form method="post" data-kiwe-native-form' . ( 'kiwe_category_move' === $action ? ' data-selection-limit="20"' : '' ) . ' action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action . ( $id ? '_' . $id : '' ) );
		if ( $id ) echo '<input type="hidden" name="category_id" value="' . esc_attr( (string) $id ) . '">';
	}

	private function pagination( array $data, string $parameter, int $id, array $extra = [] ): void {
		echo '<nav class="kiwe-categories__pagination" aria-label="' . esc_attr__( 'Result pages', 'dsa' ) . '">';
		if ( $data['page'] > 1 ) echo '<a class="button" href="' . esc_url( add_query_arg( [ $parameter=>$data['page'] - 1 ] + $extra, self::url( $id ) ) ) . '">' . esc_html__( 'Previous', 'dsa' ) . '</a>';
		echo '<span>' . esc_html( sprintf( __( 'Page %d', 'dsa' ), $data['page'] ) ) . '</span>';
		if ( $data['has_more'] ) echo '<a class="button" href="' . esc_url( add_query_arg( [ $parameter=>$data['page'] + 1 ] + $extra, self::url( $id ) ) ) . '">' . esc_html__( 'Next', 'dsa' ) . '</a>';
		echo '</nav>';
	}

	public function render(): void {
		if ( ! $this->can_manage() ) wp_die( esc_html__( 'Category management is unavailable.', 'dsa' ), '', [ 'response'=>403 ] );
		$id = $this->input_id( $_GET['category_id'] ?? 0 );
		$term = $id ? $this->category( $id ) : null;
		if ( is_wp_error( $term ) ) { $this->fail( $term ); return; }
		echo '<div class="wrap kiwe-categories kiwe-tasks kiwe-native-workspace"><p class="kiwe-categories__eyebrow">' . esc_html__( 'Content workspace', 'dsa' ) . '</p><h1>' . esc_html( $term ? $term->name : __( 'Categories', 'dsa' ) ) . '</h1>';
		$this->receipt();
		if ( $term ) {
			echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'All categories', 'dsa' ) . '</a></p>';
			$this->render_detail( $term );
		} else $this->render_list();
		echo '</div>';
	}

	private function render_list(): void {
		$data = $this->list_categories( [ 'search'=>wp_unslash( $_GET['category_search'] ?? '' ), 'page'=>$_GET['category_page'] ?? 1 ] );
		if ( is_wp_error( $data ) ) { $this->fail( $data ); return; }
		echo '<p>' . esc_html__( 'Organize your posts, rename a category, or move selected posts in one place.', 'dsa' ) . '</p><div class="kiwe-categories__layout"><section class="kiwe-app-card kiwe-categories__card"><h2>' . esc_html__( 'Your categories', 'dsa' ) . '</h2>';
		$this->search_form( 'category_search', $data['search'] );
		echo '<ul class="kiwe-categories__list">';
		foreach ( $data['items'] as $term ) echo '<li><a href="' . esc_url( self::url( (int) $term->term_id ) ) . '"><strong>' . esc_html( $term->name ) . '</strong><span>' . esc_html( $term->slug ) . '</span></a></li>';
		if ( ! $data['items'] ) echo '<li>' . esc_html__( 'No categories found. Try another search or create one.', 'dsa' ) . '</li>';
		echo '</ul>';
		$this->pagination( $data, 'category_page', 0, [ 'category_search'=>$data['search'] ] );
		echo '</section><section class="kiwe-app-card kiwe-categories__card"><h2>' . esc_html__( 'Create a category', 'dsa' ) . '</h2><p>' . esc_html__( 'Give it a clear name. WordPress creates its URL slug.', 'dsa' ) . '</p>';
		$this->form_start( 'kiwe_category_create' );
		echo '<label for="kiwe-category-name">' . esc_html__( 'Category name', 'dsa' ) . '</label><input id="kiwe-category-name" name="name" maxlength="200" required>';
		submit_button( __( 'Create category', 'dsa' ) );
		Task_Workspace_UI::native_form_feedback();
		echo '</form></section></div>';
	}

	private function search_form( string $field, string $value, int $id = 0, int $post_page = 1 ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '" class="kiwe-categories__search"><input type="hidden" name="page" value="kiwe-categories"><input type="hidden" name="category_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="post_page" value="' . esc_attr( (string) $post_page ) . '"><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Search categories', 'dsa' ) . '</label><input type="search" maxlength="200" id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '"><button class="button">' . esc_html__( 'Search', 'dsa' ) . '</button></form>';
	}

	private function render_detail( $term ): void {
		$id = (int) $term->term_id;
		if ( current_user_can( 'kiwe_manage_navigation' ) ) echo '<p><a href="' . esc_url( Navigation_Workspace_Service::url( 'category', $id ) ) . '">' . esc_html__( 'Manage this category in Navigation', 'dsa' ) . '</a></p>';
		$posts = $this->list_posts( $id, $this->page( $_GET['post_page'] ?? 1 ) );
		$targets = $this->list_categories( [ 'search'=>wp_unslash( $_GET['target_search'] ?? '' ), 'page'=>$_GET['target_page'] ?? 1 ] );
		if ( is_wp_error( $posts ) || is_wp_error( $targets ) ) { $this->fail( is_wp_error( $posts ) ? $posts : $targets ); return; }
		echo '<div class="kiwe-categories__layout"><section class="kiwe-app-card kiwe-categories__card"><h2>' . esc_html__( 'Category name', 'dsa' ) . '</h2>';
		$this->form_start( 'kiwe_category_rename', $id );
		echo '<input type="hidden" name="expected" value="' . esc_attr( self::fingerprint( $term ) ) . '">';
		echo '<label for="kiwe-category-name">' . esc_html__( 'Name', 'dsa' ) . '</label><input id="kiwe-category-name" name="name" maxlength="200" value="' . esc_attr( $term->name ) . '" required><p>' . esc_html( sprintf( __( 'URL slug stays: %s', 'dsa' ), $term->slug ) ) . '</p><p>' . esc_html__( 'Names update wherever your website reads this category. Custom menu labels may have their own text.', 'dsa' ) . '</p>';
		submit_button( __( 'Save name', 'dsa' ) );
		Task_Workspace_UI::native_form_feedback();
		echo '</form></section><section class="kiwe-app-card kiwe-categories__card kiwe-categories__move"><h2>' . esc_html__( 'Move selected posts', 'dsa' ) . '</h2><p>' . esc_html__( 'First find the destination, then select up to 20 posts below. Moving removes this category and adds the destination. Other categories stay assigned. Posts in child categories are not included.', 'dsa' ) . '</p>';
		$this->search_form( 'target_search', $targets['search'], $id, $posts['page'] );
		$this->pagination( $targets, 'target_page', $id, [ 'target_search'=>$targets['search'], 'post_page'=>$posts['page'] ] );
		$this->form_start( 'kiwe_category_move', $id );
		echo '<label for="kiwe-category-target">' . esc_html__( 'Destination category', 'dsa' ) . '</label><select id="kiwe-category-target" name="target_id" required><option value="">' . esc_html__( 'Choose a category', 'dsa' ) . '</option>';
		foreach ( $targets['items'] as $target ) if ( (int) $target->term_id !== $id ) echo '<option value="' . esc_attr( (string) $target->term_id ) . '">' . esc_html( $target->name . ' / ' . $target->slug ) . '</option>';
		echo '</select><fieldset><legend>' . esc_html__( 'Posts you can move', 'dsa' ) . '</legend><ul class="kiwe-categories__posts">';
		foreach ( $posts['items'] as $post ) echo '<li><label><input type="checkbox" name="post_ids[]" value="' . esc_attr( (string) $post->ID ) . '"><span><strong>' . esc_html( $post->post_title ?: __( '(No title)', 'dsa' ) ) . '</strong><small>' . esc_html( $post->post_status ) . '</small></span></label></li>';
		if ( ! $posts['items'] ) echo '<li>' . esc_html__( 'No editable posts on this page. Use Next if another page is available.', 'dsa' ) . '</li>';
		echo '</ul></fieldset>';
		if ( $posts['items'] ) {
			echo '<p data-kiwe-selection-count role="status" aria-live="polite">' . esc_html__( 'Choose up to 20 posts from this page.', 'dsa' ) . '</p>';
			submit_button( __( 'Move selected posts', 'dsa' ) );
			Task_Workspace_UI::native_form_feedback();
		}
		echo '</form>';
		$this->pagination( $posts, 'post_page', $id, [ 'target_search'=>$targets['search'], 'target_page'=>$targets['page'] ] );
		echo '</section></div>';
	}
}
