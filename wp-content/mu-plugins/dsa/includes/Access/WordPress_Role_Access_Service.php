<?php

namespace DSA\Access;

use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps editorial WordPress roles inside their native publishing lane.
 *
 * WordPress remains the capability authority. Kiwe only gives the built-in
 * post type a distinct create_posts primitive so an Editor can update and
 * publish existing stories without also being able to create a new one.
 */
final class WordPress_Role_Access_Service {
	public function register(): void {
		( new Site_Owner_Service() )->register();
		( new Page_Workspace_Service() )->register();
		add_filter( 'register_post_type_args', [ $this, 'post_type_capabilities' ], 20, 2 );
		add_filter( 'user_has_cap', [ $this, 'editorial_capabilities' ], 20, 4 );
		add_filter( 'user_has_cap', [ $this, 'client_capabilities' ], PHP_INT_MAX, 4 );
		add_filter( 'map_meta_cap', [ $this, 'content_boundary' ], PHP_INT_MAX, 4 );
		add_action( 'init', [ $this, 'enforce_bricks_boundary' ], 9 );
		add_filter( 'manage_users_custom_column', [ $this, 'bricks_access_column' ], PHP_INT_MAX, 3 );
		add_action( 'admin_menu', [ $this, 'limit_editorial_menu' ], PHP_INT_MAX );
		add_action( 'admin_init', [ $this, 'guard_editorial_admin_route' ], 1 );
		add_filter( 'rest_pre_dispatch', [ $this, 'guard_rest' ], 5, 3 );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', [ $this, 'menu_order' ] );
		add_action( 'admin_bar_menu', [ $this, 'toolbar' ], PHP_INT_MAX );
		add_action( 'template_redirect', [ $this, 'guard_builder' ], 0 );
	}

	/** This deny-by-default capability boundary also covers AJAX and REST writes. */
	public function client_capabilities( array $allcaps, array $caps, array $args, WP_User $user ): array {
		if ( ! Site_Owner_Service::enabled() ) {
			if ( ! empty( $allcaps['manage_options'] ) ) {
				$allcaps['kiwe_manage_context'] = $allcaps['kiwe_manage_team'] = $allcaps['kiwe_manage_guest_applications'] = $allcaps['kiwe_manage_notification_policy'] = $allcaps['kiwe_use_notifications'] = true;
			}
			if ( array_intersect( [ 'author', 'editor', 'shop_manager' ], (array) $user->roles ) ) $allcaps['kiwe_use_notifications'] = true;
			return $allcaps;
		}
		if ( Site_Owner_Service::is_owner( (int) $user->ID ) ) {
			$admin = get_role( 'administrator' );
			$allcaps = array_replace( $admin ? $admin->capabilities : [], $allcaps );
			foreach ( [ 'administrator', 'create_posts', 'create_pages', 'kiwe_manage_ownership', 'kiwe_manage_context', 'kiwe_manage_team', 'kiwe_manage_guest_applications', 'kiwe_manage_notification_policy', 'kiwe_use_notifications', 'kiwe_view_pages' ] as $cap ) $allcaps[ $cap ] = true;
			return $allcaps;
		}
		$role = self::role_for( $user );
		$allowed = self::capabilities_for( $role );
		// Rank Math integration is confined to its declared capability namespace.
		if ( 'administrator' === $role ) {
			$mode = Site_Owner_Service::policy()['rankMath'] ?? 'inline';
			if ( 'dashboard' === $mode ) foreach ( array_keys( $allcaps ) as $cap ) { if ( str_starts_with( $cap, 'rank_math_' ) ) $allowed[] = $cap; }
			if ( 'off' !== $mode ) $allowed = array_merge( $allowed, [ 'rank_math_onpage_general', 'rank_math_onpage_advanced', 'rank_math_onpage_snippet', 'rank_math_onpage_social' ] );
		}
		$out = array_fill_keys( $allowed, true );
		$out['do_not_allow'] = false;
		return $out;
	}

	public static function role_for( WP_User $user ): string {
		foreach ( [ 'administrator', 'shop_manager', 'editor', 'author', 'contributor', 'customer', 'kiwe_user', 'subscriber' ] as $role ) if ( in_array( $role, $user->roles, true ) ) return $role;
		return 'unassigned';
	}

	public static function capabilities_for( string $role ): array {
		$read = [ 'read' ];
		$author = [ 'read', 'upload_files', 'edit_posts', 'edit_published_posts', 'publish_posts', 'create_posts', 'delete_posts', 'delete_published_posts' ];
		$editor = array_merge( $author, [ 'edit_others_posts', 'edit_private_posts', 'read_private_posts', 'delete_others_posts', 'delete_private_posts' ] );
		// edit_pages is required by WP's native list; object-level writes remain denied below.
		if ( 'administrator' === $role ) return array_merge( $editor, [ 'manage_categories', 'moderate_comments', 'list_users', 'edit_users', 'create_users', 'promote_users', 'delete_users', 'remove_users', 'kiwe_manage_context', 'kiwe_manage_team', 'kiwe_manage_guest_applications', 'kiwe_manage_notification_policy', 'kiwe_use_notifications', 'kiwe_view_pages', 'kiwe_create_page_drafts', 'kiwe_manage_page_indexing', 'edit_pages', 'read_private_pages' ] );
		if ( 'editor' === $role ) return array_merge( array_values( array_diff( $editor, [ 'create_posts' ] ) ), [ 'kiwe_use_notifications' ] );
		if ( 'author' === $role ) return array_merge( $author, [ 'kiwe_use_notifications' ] );
		if ( 'contributor' === $role ) return [ 'read', 'kiwe_guest_submit', 'kiwe_use_notifications' ];
		if ( 'shop_manager' === $role && function_exists( 'WC' ) ) return [ 'read', 'upload_files', 'edit_products', 'edit_others_products', 'publish_products', 'read_private_products', 'edit_private_products', 'edit_published_products', 'create_products', 'assign_product_terms', 'kiwe_use_notifications' ];
		return $read;
	}

	public function content_boundary( array $caps, string $cap, int $actor, array $args ): array {
		if ( ! Site_Owner_Service::enabled() || Site_Owner_Service::is_owner( $actor ) ) return $caps;
		if ( in_array( $cap, [ 'edit_post', 'delete_post', 'edit_page', 'delete_page', 'edit_post_meta', 'add_post_meta', 'delete_post_meta' ], true ) ) {
			$id = absint( $args[0] ?? 0 );
			$type = get_post_type( $id );
			if ( 'revision' === $type ) $type = get_post_type( wp_get_post_parent_id( $id ) );
			$user = get_userdata( $actor );
			$role = $user ? self::role_for( $user ) : 'unassigned';
			$allowed = 'shop_manager' === $role ? [ 'product', 'attachment' ] : [ 'post', 'attachment' ];
			if ( ! in_array( $type, $allowed, true ) ) return [ 'do_not_allow' ];
			$meta = (string) ( $args[1] ?? '' );
			if ( str_starts_with( $meta, '_bricks' ) || str_starts_with( $meta, 'bricks_' ) ) return [ 'do_not_allow' ];
		}
		return $caps;
	}

	public function post_type_capabilities( array $args, string $post_type ): array {
		if ( 'page' === $post_type && Site_Owner_Service::enabled() ) {
			// Native list access must not grant generic REST/XML-RPC page creation.
			$args['capabilities'] = array_replace( (array) ( $args['capabilities'] ?? [] ), [ 'create_posts'=>'create_pages' ] );
			return $args;
		}
		if ( 'post' !== $post_type ) {
			return $args;
		}

		$capabilities = is_array( $args['capabilities'] ?? null ) ? $args['capabilities'] : [];
		$capabilities['create_posts'] = 'create_posts';
		$args['capabilities'] = $capabilities;
		return $args;
	}

	public function editorial_capabilities( array $allcaps, array $caps, array $args, WP_User $user ): array {
		if ( Site_Owner_Service::is_owner( (int) $user->ID ) ) return $allcaps;
		$roles = (array) $user->roles;
		if ( in_array( 'contributor', $roles, true ) ) {
			// Guests create through Kiwe's create-once form. Removing native post
			// primitives closes wp-admin, REST, XML-RPC and application-password edits.
			return [ 'read' => true, 'kiwe_guest_submit' => Guest_Contribution_Service::approved( (int) $user->ID ), 'kiwe_use_notifications' => true ];
		}
		if ( in_array( 'shop_manager', $roles, true ) && ! in_array( 'administrator', $roles, true ) && ! ( is_multisite() && is_super_admin( $user->ID ) ) ) {
			$allowed = [
				'read',
				'upload_files',
				'edit_products',
				'edit_others_products',
				'publish_products',
				'read_private_products',
				'edit_private_products',
				'edit_published_products',
				'create_products',
				'assign_product_terms',
			];
			$allowed_map = array_fill_keys( $allowed, true );
			foreach ( array_keys( $allcaps ) as $capability ) {
				if ( ! isset( $allowed_map[ $capability ] ) ) {
					unset( $allcaps[ $capability ] );
				}
			}
			foreach ( $allowed as $capability ) {
				$allcaps[ $capability ] = true;
			}
			return $allcaps;
		}
		$is_editor_only = in_array( 'editor', $roles, true ) && empty( $allcaps['manage_options'] );

		if ( $is_editor_only ) {
			unset( $allcaps['create_posts'] );
			return $allcaps;
		}

		// Preserve native creation for Administrators, Authors, Contributors, and
		// compatible custom roles that already possess the post editing primitive.
		if ( ! empty( $allcaps['edit_posts'] ) ) {
			$allcaps['create_posts'] = true;
		}

		return $allcaps;
	}

	public function limit_editorial_menu(): void {
		$role = $this->restricted_role();
		if ( '' === $role ) {
			return;
		}

		global $menu;
		$allowed = 'shop_manager' === $role
			? [ 'edit.php?post_type=product' ]
			: [ 'index.php', 'edit.php', 'upload.php', 'profile.php' ];
		if ( Site_Owner_Service::enabled() ) {
			$allowed = 'administrator' === $role ? [ 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php', 'users.php', 'kiwe-guests', 'kiwe-notifications', 'profile.php' ] : array_diff( $allowed, [ 'index.php' ] );
			if ( 'contributor' === $role ) $allowed = [ 'kiwe-guests', 'kiwe-notifications', 'profile.php' ];
			if ( in_array( $role, [ 'author', 'editor', 'shop_manager' ], true ) ) $allowed[] = 'kiwe-notifications';
			if ( 'administrator' === $role ) {
				foreach ( \DSA\Onboarding\Onboarding_Service::section_slugs() as $slug ) $allowed[] = $slug;
				if ( 'dashboard' === ( Site_Owner_Service::policy()['rankMath'] ?? '' ) ) $allowed[] = 'rank-math';
			}
		}
		foreach ( (array) $menu as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( '' !== $slug && ! in_array( $slug, $allowed, true ) ) {
				remove_menu_page( $slug );
			}
		}

		if ( 'editor' === $role ) {
			remove_submenu_page( 'edit.php', 'post-new.php' );
		}
	}

	public function guard_editorial_admin_route(): void {
		$role = $this->restricted_role();
		if ( Site_Owner_Service::enabled() && '' !== $role ) {
			$this->guard_client_route( $role );
			return;
		}
		if ( '' === $role || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		global $pagenow;
		$page = (string) preg_replace( '/[^a-z0-9_.-]/', '', strtolower( (string) $pagenow ) );
		if ( 'shop_manager' === $role ) {
			$this->guard_shop_manager_route( $page );
			return;
		}
		$always_allowed = [
			'index.php',
			'edit.php',
			'post.php',
			'upload.php',
			'media-new.php',
			'media-upload.php',
			'async-upload.php',
			'profile.php',
			'user-edit.php',
			'admin-ajax.php',
			'admin-post.php',
			'load-scripts.php',
			'load-styles.php',
		];

		if ( 'editor' === $role && 'post-new.php' === $page ) {
			$this->redirect_to_posts();
		}

		if ( ! in_array( $page, $always_allowed, true ) ) {
			$this->redirect_to_posts();
		}

		if ( 'edit.php' === $page && 'post' !== sanitize_key( (string) ( $_GET['post_type'] ?? 'post' ) ) ) {
			$this->redirect_to_posts();
		}

		if ( 'post.php' === $page ) {
			$post_id = absint( $_GET['post'] ?? $_POST['post_ID'] ?? 0 );
			if ( $post_id && 'post' !== get_post_type( $post_id ) ) {
				$this->redirect_to_posts();
			}
		}

		if ( 'user-edit.php' === $page ) {
			$target_user_id = absint( $_GET['user_id'] ?? get_current_user_id() );
			if ( $target_user_id !== get_current_user_id() ) {
				wp_safe_redirect( admin_url( 'profile.php' ) );
				exit;
			}
		}
	}

	private function restricted_role(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$user = wp_get_current_user();
		if ( Site_Owner_Service::enabled() ) return Site_Owner_Service::is_owner( (int) $user->ID ) ? '' : self::role_for( $user );
		$roles = (array) $user->roles;
		if ( in_array( 'administrator', $roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) ) ) {
			return '';
		}
		if ( in_array( 'shop_manager', $roles, true ) ) {
			return 'shop_manager';
		}
		if ( in_array( 'editor', $roles, true ) ) {
			return 'editor';
		}
		if ( in_array( 'author', $roles, true ) ) {
			return 'author';
		}
		return '';
	}

	private function guard_shop_manager_route( string $page ): void {
		$support_routes = [ 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'media-upload.php', 'load-scripts.php', 'load-styles.php' ];
		if ( in_array( $page, $support_routes, true ) ) {
			return;
		}

		if ( 'edit.php' === $page && 'product' === sanitize_key( (string) ( $_GET['post_type'] ?? '' ) ) ) {
			return;
		}
		if ( 'post-new.php' === $page && 'product' === sanitize_key( (string) ( $_GET['post_type'] ?? '' ) ) ) {
			return;
		}
		if ( 'post.php' === $page ) {
			$post_id = absint( $_GET['post'] ?? $_POST['post_ID'] ?? 0 );
			if ( $post_id && 'product' === get_post_type( $post_id ) ) {
				return;
			}
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=product' ) );
		exit;
	}

	private function redirect_to_posts(): void {
		wp_safe_redirect( admin_url( 'edit.php' ) );
		exit;
	}

	public function menu_order( $order ) {
		if ( ! Site_Owner_Service::enabled() || Site_Owner_Service::is_owner() || ! is_array( $order ) ) return $order;
		$priority = array_merge( [ 'edit.php', 'upload.php', 'woocommerce', 'edit.php?post_type=product' ], \DSA\Onboarding\Onboarding_Service::section_slugs(), [ 'edit.php?post_type=page', 'edit-comments.php', 'kiwe-guests', 'kiwe-notifications', 'users.php', 'profile.php' ] );
		return array_merge( array_values( array_intersect( $priority, $order ) ), array_values( array_diff( $order, $priority ) ) );
	}

	public function toolbar( $bar ): void {
		if ( ! Site_Owner_Service::enabled() || Site_Owner_Service::is_owner() ) return;
		foreach ( [ 'wp-logo', 'updates', 'customize', 'themes', 'widgets', 'menus', 'rank-math', 'hostinger', 'litespeed-menu', 'dsa-cache', 'kiwe-cache', 'new-page', 'new-web-story' ] as $id ) $bar->remove_node( $id );
	}

	public function guard_builder(): void {
		if ( Site_Owner_Service::enabled() && is_user_logged_in() && ! Site_Owner_Service::is_owner() && isset( $_GET['bricks'] ) ) $this->deny();
	}

	/** Bricks 2.3.10 caches access from raw user/role caps, not only user_can(). */
	public function enforce_bricks_boundary(): void {
		if ( ! Site_Owner_Service::enabled() || ! class_exists( '\\Bricks\\Capabilities' ) || ! is_user_logged_in() || Site_Owner_Service::is_owner() ) return;
		\Bricks\Capabilities::$full_access = false;
		\Bricks\Capabilities::$edit_content = false;
		\Bricks\Capabilities::$no_access = true;
		\Bricks\Capabilities::$upload_svg = false;
		\Bricks\Capabilities::$execute_code = false;
		\Bricks\Capabilities::$form_submission_access = false;
		\Bricks\Capabilities::$capabilities_set = true;
		// Do not rewrite Bricks roles/options or touch visitor forms/rendering.
	}
	public function bricks_access_column( $value, string $column, int $id ) {
		if ( 'bricks_capabilities' !== $column || ! Site_Owner_Service::enabled() ) return $value;
		return Site_Owner_Service::is_owner( $id ) ? 'Builder: Full access (Super Admin)' : 'Builder: No access (Kiwe policy)';
	}

	private function guard_client_route( string $role ): void {
		if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
		global $pagenow;
		$script = (string) $pagenow;
		$work_roles = [ 'administrator','author','editor','contributor','shop_manager' ];
		if ( wp_doing_ajax() ) { $this->guard_ajax( $role ); return; }
		if ( ! in_array( $role, $work_roles, true ) ) { wp_safe_redirect( home_url( '/' ) ); exit; }
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( 'admin.php' === $script && 'kiwe-notifications' === $page && current_user_can( 'kiwe_use_notifications' ) ) return;
		if ( 'admin.php' === $script && 'kiwe-guests' === $page && ( current_user_can( 'kiwe_manage_guest_applications' ) || current_user_can( 'kiwe_guest_submit' ) ) ) return;
		if ( 'admin-post.php' === $script && in_array( sanitize_key( $_POST['action'] ?? '' ), [ 'kiwe_save_notification_preferences', 'kiwe_save_notification_policy', 'kiwe_guest_save_settings', 'kiwe_guest_decide', 'kiwe_guest_submit' ], true ) ) return;
		if ( 'contributor' === $role ) {
			if ( in_array( $script, [ 'profile.php', 'load-scripts.php', 'load-styles.php' ], true ) ) return;
			$this->deny();
		}
		if ( 'administrator' === $role ) {
			$type = sanitize_key( $_REQUEST['post_type'] ?? 'post' );
			if ( 'edit.php' === $script && 'page' === $type && in_array( $page, [ '', 'kiwe-page-details' ], true ) ) return;
			if ( 'post-new.php' === $script && 'page' === $type ) { wp_safe_redirect( Page_Workspace_Service::url() ); exit; }
			if ( 'admin-post.php' === $script && in_array( $_POST['action'] ?? '', [ 'kiwe_create_page', 'kiwe_page_indexing' ], true ) ) return;
			if ( 'admin.php' === $script && in_array( $page, array_merge( [ 'kiwe-pages','kiwe-onboarding' ], \DSA\Onboarding\Onboarding_Service::section_slugs() ), true ) ) return;
			if ( 'admin.php' === $script && 'dashboard' === ( Site_Owner_Service::policy()['rankMath'] ?? '' ) && str_starts_with( $page, 'rank-math' ) ) return;
			if ( 'admin-post.php' === $script && 'kiwe_save_onboarding' === ( $_POST['action'] ?? '' ) ) return;
			if ( ! $page && in_array( $script, [ 'users.php','user-new.php','user-edit.php','edit-comments.php','comment.php','edit-tags.php','term.php' ], true ) ) {
				if ( in_array( $script, [ 'edit-tags.php','term.php' ], true ) && ! in_array( $_REQUEST['taxonomy'] ?? '', [ 'category','post_tag' ], true ) ) $this->deny();
				return;
			}
		}
		if ( in_array( $script, [ 'profile.php','media-upload.php','async-upload.php','load-scripts.php','load-styles.php' ], true ) ) return;
		if ( in_array( $script, [ 'upload.php','media-new.php' ], true ) && 'shop_manager' !== $role ) return;
		$type = sanitize_key( $_REQUEST['post_type'] ?? 'post' );
		if ( 'post.php' === $script ) $type = (string) get_post_type( absint( $_REQUEST['post'] ?? $_POST['post_ID'] ?? 0 ) );
		$expected = 'shop_manager' === $role ? 'product' : 'post';
		if ( 'post.php' === $script && 'attachment' === $type && current_user_can( 'upload_files' ) ) return;
		if ( in_array( $script, [ 'edit.php','post.php','post-new.php' ], true ) && $expected === $type && empty( $_GET['page'] ) ) {
			if ( 'post-new.php' !== $script || current_user_can( 'shop_manager' === $role ? 'create_products' : 'create_posts' ) ) return;
		}
		if ( 'index.php' === $script ) { wp_safe_redirect( admin_url( 'edit.php' . ( 'shop_manager' === $role ? '?post_type=product' : '' ) ) ); exit; }
		$this->deny();
	}

	private function guard_ajax( string $role ): void {
		$action = sanitize_key( $_REQUEST['action'] ?? '' );
		// These are maintained visitor-facing handlers, not builder/admin APIs.
		if ( in_array( $action, [ 'dsa_runtime_hydrate', 'stp_exit', 'stp_behavior', 'bricks_form_submit', 'bricks_regenerate_form_nonce', 'bricks_regenerate_query_nonce' ], true ) ) return;
		// Native publishing/media/profile endpoints still enforce their own nonces and object caps.
		$allowed = [ 'heartbeat','wp-remove-post-lock','get-revision-diffs','autosave','inline-save','query-attachments','upload-attachment','save-attachment','save-attachment-compat','image-editor','crop-image','send-attachment-to-editor','get-attachment','save-attachment-order','get-post-thumbnail-html','set-post-thumbnail','fetch-list','wp-link-ajax','find_posts','parse-embed','oembed-cache','rest-nonce','logged-in' ];
		if ( 'administrator' === $role ) $allowed = array_merge( $allowed, [ 'add-tag','delete-tag','inline-save-tax','delete-comment','dim-comment','replyto-comment','edit-comment','get-comments','add-meta','delete-meta','add-user','send-password-reset' ] );
		if ( in_array( $action, $allowed, true ) ) return;
		// Key.kiwe authenticated flows have their own account-bound authorization.
		wp_send_json_error( [ 'message'=>'This action is outside your workspace.' ], 403 );
	}

	public function guard_rest( $result, $server, $request ) {
		if ( ! Site_Owner_Service::enabled() || ! is_user_logged_in() || Site_Owner_Service::is_owner() ) return $result;
		$route = $request->get_route(); $method = $request->get_method();
		$role = self::role_for( wp_get_current_user() );
		if ( 'contributor' === $role && ! in_array( $method, [ 'GET','HEAD','OPTIONS' ], true ) && preg_match( '#^/wp/v2/(?:posts|media|comments|users|categories|tags)(?:/|$)#', $route ) ) {
			return new \WP_Error( 'kiwe_guest_workspace_only', 'Guest contributions must use the protected Guest workspace.', [ 'status'=>403 ] );
		}
		// Public reads are kept public. Native REST permission callbacks still decide object access.
		if ( in_array( $method, [ 'GET','HEAD','OPTIONS' ], true ) ) return $result;
		if ( preg_match( '#^/wp/v2/(posts|media|comments|users|categories|tags)(?:/|$)#', $route ) || '/wp/v2/batch' === $route || '/batch/v1' === $route ) return $result;
		if ( str_starts_with( $route, '/phonekey/v3/' ) ) return $result;
		if ( preg_match( '#^/bricks/v1/(?:load_query_page|load_popup_content|query_result)/?$#', $route ) ) return $result;
		if ( preg_match( '#^/dsa/v1/(?:account|saved-items|permissions|notification-preferences|push/subscription|metrics/event|runtime/hydrate)(?:/|$)#', $route ) ) return $result;
		if ( str_starts_with( $route, '/rankmath/v1/' ) && 'administrator' === $role ) {
			$mode = Site_Owner_Service::policy()['rankMath'] ?? 'inline';
			if ( 'dashboard' === $mode ) return $result;
			if ( 'inline' === $mode && preg_match( '#^/rankmath/v1/(?:updateMeta|updateSchemas|analyzeKeywords)/?$#', $route ) ) {
				$id = absint( $request->get_param( 'objectID' ) ?: $request->get_param( 'post_id' ) );
				foreach ( array_keys( (array) $request->get_param( 'meta' ) ) as $key ) {
					if ( 'permalink' !== $key && ! str_starts_with( (string) $key, 'rank_math_' ) ) return new \WP_Error( 'kiwe_metadata_forbidden', 'Only post SEO fields can be changed here.', [ 'status'=>403 ] );
				}
				if ( $id && 'post' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) && in_array( $request->get_param( 'objectType' ), [ null, '', 'post' ], true ) ) return $result;
			}
		}
		if ( function_exists( 'WC' ) && preg_match( '#^/wc/store/#', $route ) ) return $result;
		if ( function_exists( 'WC' ) && preg_match( '#^/dsa/v1/(?:cart|checkout|rewards)(?:/|$)#', $route ) ) return $result;
		if ( 'shop_manager' === $role && preg_match( '#^/wc/v[1-9]/products(?:/|$)#', $route ) ) return $result;
		return new \WP_Error( 'kiwe_workspace_forbidden', 'This endpoint is outside your assigned workspace.', [ 'status'=>403 ] );
	}

	private function deny(): void { wp_die( esc_html__( 'This area is not part of your workspace. Use the menu to continue.', 'dsa' ), '', [ 'response'=>403 ] ); }
}
