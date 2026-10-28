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
	private const CAP_PAGE_PUBLISH = 'kiwe_manage_page_publishing';
	private const CAP_PAGE_RESTORE = 'kiwe_restore_page_versions';
	private const SEO_OBJECT_PAGE = 'kiwe-seo-assistant';

	public function register(): void {
		( new Site_Owner_Service() )->register();
		( new Page_Workspace_Service() )->register();
		( new Category_Workspace_Service() )->register();
		( new Team_Workspace_Service() )->register();
		( new Post_Workspace_Service() )->register();
		( new Context_Workspace_Service() )->register();
		( new Navigation_Workspace_Service() )->register();
		( new Record_Card_Control_Service() )->register();
		( new Navigation_App_Service() )->register();
		( new Dock_Workspace_Service() )->register();
		( new Taxonomy_App_Service() )->register();
		( new Tag_Group_Service() )->register();
		( new User_Group_Service() )->register();
		( new Template_Workspace_Service() )->register();
		( new Analytics_Workspace_Service() )->register();
		( new Backup_Workspace_Service() )->register();
		( new Content_Archive_App_Service() )->register();
		( new Media_Archive_App_Service() )->register();
		( new Bricks_Design_Package_App_Service() )->register();
		( new Identity_App_Service() )->register();
		( new Continuity_App_Service() )->register();
		( new Media_App_Service() )->register();
		( new Trash_Workspace_Service() )->register();
		add_action( 'plugins_loaded',static function (): void {
			if ( \DSA\Apps\AI_App_Service::enabled() ) ( new AI_Workspace_Service() )->register();
			if ( \DSA\Apps\Guest_App_Service::enabled() ) { ( new Guest_Contribution_Service() )->register(); ( new Guest_App_Service() )->register(); }
		},30 );

		// MU plugins precede Woo. Editorial sites do not parse the store workspace.
		add_action( 'plugins_loaded', static function (): void {
			if ( \DSA\Apps\Store_App_Service::enabled() ) ( new Commerce_App_Service() )->register();
		}, 30 );
		( new Workspace_Admin_Service() )->register();
		if ( is_admin() ) ( new Workspace_Shell_Service() )->register();
		add_filter( 'register_post_type_args', [ $this, 'post_type_capabilities' ], 20, 2 );
		add_filter( 'user_has_cap', [ $this, 'editorial_capabilities' ], 20, 4 );
		add_filter( 'user_has_cap', [ $this, 'client_capabilities' ], PHP_INT_MAX, 4 );
		add_filter( 'determine_current_user', [ $this, 'deny_suspended_cookie' ], PHP_INT_MAX );
		add_filter( 'wp_authenticate_user', [ $this, 'deny_suspended_password' ], PHP_INT_MAX );
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
	/** A ban applies to existing cookies as well as new WordPress password sign-ins. */
	public function deny_suspended_cookie( $user_id ) {
		return $user_id && Team_Workspace_Service::suspended( (int) $user_id ) ? false : $user_id;
	}
	public function deny_suspended_password( $user ) {
		return $user instanceof WP_User && Team_Workspace_Service::suspended( (int) $user->ID ) ? new \WP_Error( 'kiwe_account_suspended', __( 'This account cannot sign in. Contact the website administrator.', 'dsa' ) ) : $user;
	}

	/** This deny-by-default capability boundary also covers AJAX and REST writes. */
	public function client_capabilities( array $allcaps, array $caps, array $args, WP_User $user ): array {
		if ( ! Site_Owner_Service::enabled() ) {
			if ( ! empty( $allcaps['manage_options'] ) ) {
				foreach ( array_merge( Template_Workspace_Service::owner_template_caps(), self::client_business_capabilities(), [ 'kiwe_view_analytics','bricks_full_access' ] ) as $cap ) $allcaps[ $cap ] = true;
				$allcaps['kiwe_manage_ai_preferences'] = $allcaps['kiwe_manage_navigation'] = $allcaps['kiwe_manage_context'] = $allcaps['kiwe_manage_team'] = $allcaps['kiwe_manage_guest_applications'] = $allcaps['kiwe_manage_notification_policy'] = $allcaps['kiwe_use_notifications'] = true;
			}
			if ( array_intersect( [ 'author', 'editor', 'shop_manager' ], (array) $user->roles ) ) $allcaps['kiwe_use_notifications'] = true;
			if ( ! empty( $allcaps['read'] ) ) $allcaps['kiwe_use_notifications'] = true;
			return $allcaps;
		}
		if ( Site_Owner_Service::is_owner( (int) $user->ID ) ) {
			$admin = get_role( 'administrator' );
			$allcaps = array_replace( $admin ? $admin->capabilities : [], $allcaps );
			foreach ( array_merge( Template_Workspace_Service::owner_template_caps(), self::client_business_capabilities(), [ 'kiwe_view_analytics','bricks_full_access' ] ) as $cap ) $allcaps[ $cap ] = true;
			foreach ( [ 'administrator', 'create_posts', 'kiwe_manage_ownership', 'kiwe_manage_context', 'kiwe_manage_navigation', 'kiwe_manage_ai_preferences', 'kiwe_manage_team', 'kiwe_manage_guest_applications', 'kiwe_manage_notification_policy', 'kiwe_use_notifications', 'kiwe_view_pages', self::CAP_PAGE_PUBLISH, self::CAP_PAGE_RESTORE ] as $cap ) $allcaps[ $cap ] = true;
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
		foreach ( [ 'administrator', Template_Workspace_Service::ROLE, 'shop_manager', 'editor', 'author', 'contributor', 'customer', 'kiwe_user', 'subscriber' ] as $role ) if ( in_array( $role, $user->roles, true ) ) return $role;
		return 'unassigned';
	}

	public static function capabilities_for( string $role ): array {
		$read = [ 'read', 'kiwe_use_notifications' ];
		$author = [ 'read', 'upload_files', 'edit_posts', 'edit_published_posts', 'publish_posts', 'create_posts', 'delete_posts', 'delete_published_posts' ];
		$editor = array_merge( $author, [ 'edit_others_posts', 'edit_private_posts', 'read_private_posts', 'delete_others_posts', 'delete_private_posts' ] );
		// edit_pages is required by WP's native list; object-level writes remain denied below.
		if ( Template_Workspace_Service::ROLE === $role ) return array_merge( Template_Workspace_Service::designer_caps(), [ 'kiwe_use_notifications' ] );
		if ( 'administrator' === $role ) return array_merge( Template_Workspace_Service::owner_template_caps(), [ 'bricks_full_access','kiwe_view_analytics','delete_pages','delete_others_pages','delete_private_pages','delete_published_pages','edit_others_pages','edit_published_pages','edit_private_pages','publish_pages' ], $editor, self::client_business_capabilities(), [ 'manage_categories', 'moderate_comments', 'list_users', 'edit_users', 'create_users', 'promote_users', 'delete_users', 'remove_users', 'kiwe_manage_context', 'kiwe_manage_navigation', 'kiwe_manage_ai_preferences', 'kiwe_manage_team', 'kiwe_manage_guest_applications', 'kiwe_manage_notification_policy', 'kiwe_use_notifications', 'kiwe_view_pages', 'kiwe_create_page_drafts', 'kiwe_manage_page_indexing', 'kiwe_manage_page_titles', self::CAP_PAGE_PUBLISH, self::CAP_PAGE_RESTORE, 'edit_pages', 'read_private_pages' ] );
		if ( 'editor' === $role ) return array_merge( array_values( array_diff( $editor, [ 'create_posts' ] ) ), [ 'moderate_comments', 'kiwe_use_notifications' ] );
		if ( 'author' === $role ) return array_merge( $author, [ 'kiwe_use_notifications' ] );
		if ( 'contributor' === $role ) return [ 'read', 'kiwe_guest_submit', 'kiwe_use_notifications' ];
		if ( 'shop_manager' === $role && function_exists( 'WC' ) ) return self::shop_manager_capabilities();
		return $read;
	}
	/** Store staff can fulfil orders without global Woo settings or customer-account access. */
	private static function shop_manager_capabilities(): array {
		return [ 'read', 'upload_files', 'edit_products', 'edit_others_products', 'publish_products', 'read_private_products', 'edit_private_products', 'edit_published_products', 'create_products', 'manage_product_terms', 'edit_product_terms', 'assign_product_terms', 'kiwe_use_notifications', 'edit_shop_orders', 'edit_others_shop_orders', 'edit_private_shop_orders', 'edit_published_shop_orders', 'read_private_shop_orders', 'edit_shop_coupons','edit_others_shop_coupons','edit_private_shop_coupons','edit_published_shop_coupons','read_private_shop_coupons','publish_shop_coupons' ];
	}
	private static function client_service_type(): ?object {
		if ( ! function_exists( 'get_post_type_object' ) ) return null;
		$profile = (array) get_option( 'kiwe_seam_design_context_v1', [] );
		$source = (string) ( $profile['services']['sourcePostType'] ?? '' );
		if ( ! $source || in_array( $source, [ 'post','page','attachment','product','product_variation','nav_menu_item','wp_block','wp_template','wp_template_part','bricks_template' ], true ) ) return null;
		$object = get_post_type_object( $source );
		return $object && ! empty( $object->show_ui ) && ( ! empty( $object->public ) || ! empty( $object->publicly_queryable ) ) ? $object : null;
	}
	private static function client_business_capabilities(): array {
		$caps = function_exists( 'WC' ) ? [ 'delete_products','delete_others_products','delete_private_products','delete_published_products','edit_products','edit_others_products','edit_private_products','edit_published_products','read_private_products','publish_products','create_products','manage_product_terms','edit_product_terms','delete_product_terms','assign_product_terms','edit_shop_orders','edit_others_shop_orders','edit_private_shop_orders','edit_published_shop_orders','read_private_shop_orders','edit_shop_coupons','edit_others_shop_coupons','edit_private_shop_coupons','edit_published_shop_coupons','read_private_shop_coupons','publish_shop_coupons' ] : [];
		$service = self::client_service_type();
		if ( ! $service ) return $caps;
		// Only content primitives derived from the configured type may be added.
		// A custom mapping to manage_options or plugin/builder authority is denied.
		$types = (array) $service->capability_type; $singular = $types[0] ?? 'post'; $plural = $types[1] ?? $singular . 's';
		if ( ! preg_match( '/^[a-z0-9_]+$/D', $singular ) || ! preg_match( '/^[a-z0-9_]+$/D', $plural ) || in_array( $singular, [ 'page','user','theme','plugin','file','option','bricks' ], true ) || in_array( $plural, [ 'pages','users','themes','plugins','files','options','bricks' ], true ) ) return $caps;
		$permitted = [ 'edit_'.$singular,'read_'.$singular,'delete_'.$singular,'edit_'.$plural,'edit_others_'.$plural,'edit_private_'.$plural,'edit_published_'.$plural,'read_private_'.$plural,'publish_'.$plural,'create_'.$plural,'delete_'.$plural,'delete_others_'.$plural,'delete_private_'.$plural,'delete_published_'.$plural ];
		foreach ( (array) $service->cap as $value ) if ( in_array( $value, $permitted, true ) ) $caps[] = $value;
		// Taxonomy primitives must belong to a visible taxonomy connected to this
		// service. Never trust a mapping to generic plugin/settings capabilities.
		if ( function_exists( 'get_object_taxonomies' ) ) foreach ( get_object_taxonomies( $service->name, 'objects' ) as $name=>$taxonomy ) {
			if ( empty( $taxonomy->show_ui ) || 'post_format' === $name || ! preg_match( '/^[a-z0-9_-]+$/D', $name ) || preg_match( '/(?:^|_)(?:options?|users?|themes?|plugins?|files?|bricks)(?:_|$)/', $name ) ) continue;
			foreach ( [ 'manage','edit','delete','assign' ] as $action ) {
				$value = $taxonomy->cap->{ $action . '_terms' } ?? '';
				if ( in_array( $value, [ $action . '_' . $name, $action . '_' . $name . '_terms' ], true ) ) $caps[] = $value;
			}
		}
		return array_values( array_unique( $caps ) );
	}

	public function content_boundary( array $caps, string $cap, int $actor, array $args ): array {
		// Store Managers and Web Designers may remove files they uploaded, without
		// gaining WordPress post-deletion or another person's media authority.
		if ( 'delete_post' === $cap && $actor > 0 ) {
			$id = absint( $args[0] ?? 0 );
			if ( 'attachment' === get_post_type( $id ) && $actor === (int) get_post_field( 'post_author', $id ) ) {
				$user = get_userdata( $actor );
				$role = $user ? self::role_for( $user ) : 'unassigned';
				if ( in_array( $role, [ 'shop_manager', Template_Workspace_Service::ROLE ], true ) ) return [ 'upload_files' ];
			}
		}
		if ( ! Site_Owner_Service::enabled() || Site_Owner_Service::is_owner( $actor ) ) return $caps;
		if ( in_array( $cap, [ 'edit_post', 'delete_post', 'edit_page', 'delete_page', 'edit_post_meta', 'add_post_meta', 'delete_post_meta' ], true ) ) {
			$id = absint( $args[0] ?? 0 );
			$type = get_post_type( $id );
			if ( 'revision' === $type ) $type = get_post_type( wp_get_post_parent_id( $id ) );
			$user = get_userdata( $actor );
			$role = $user ? self::role_for( $user ) : 'unassigned';
			$allowed = 'shop_manager' === $role ? [ 'product', 'attachment' ] : [ 'post', 'attachment' ];
			if ( Template_Workspace_Service::builder_actor( $actor ) ) $allowed = Template_Workspace_Service::ROLE === $role ? [ 'page','bricks_template','attachment' ] : array_merge( $allowed,[ 'page','bricks_template' ] );
			if ( in_array( $role, [ 'administrator', 'shop_manager' ], true ) ) {
				// HPOS reserves an ID with a placeholder post. Resolve only a real
				// Woo order; keep Woo's mapped capabilities (including denials).
				if ( 'shop_order_placehold' === $type && function_exists( 'wc_get_order' ) ) {
					$order = wc_get_order( $id );
					if ( $order && in_array( $order->get_type(), [ 'shop_order', 'shop_order_refund' ], true ) ) $type = $order->get_type();
				}
				if ( function_exists( 'WC' ) ) $allowed = array_merge( $allowed, 'administrator' === $role ? [ 'product','product_variation','shop_order','shop_order_refund','shop_coupon' ] : [ 'product_variation','shop_order','shop_coupon' ] );
				if ( 'administrator' === $role ) { $service = self::client_service_type(); if ( $service ) $allowed[] = $service->name; }
			}
			if ( ! in_array( $type, $allowed, true ) ) return [ 'do_not_allow' ];
			$meta = (string) ( $args[1] ?? '' );
			if ( ( str_starts_with( $meta, '_bricks' ) || str_starts_with( $meta, 'bricks_' ) ) && ! ( Template_Workspace_Service::builder_actor( $actor ) && in_array( $type,[ 'page','bricks_template' ],true ) ) ) return [ 'do_not_allow' ];
		}
		return $caps;
	}

	public function post_type_capabilities( array $args, string $post_type ): array {
		if ( 'bricks_template' === $post_type ) {
			$args['capability_type'] = [ 'kiwe_template','kiwe_templates' ]; $args['map_meta_cap'] = true;
			$args['capabilities'] = [ 'create_posts'=>'create_kiwe_templates' ]; return $args;
		}
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
			$allowed = self::shop_manager_capabilities();
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
		if ( Workspace_Admin_Service::prefers_native_dashboard( wp_get_current_user() ) ) return;
		$role = $this->restricted_role();
		if ( '' === $role ) {
			return;
		}

		global $menu;
		$allowed = 'shop_manager' === $role
			? [ 'edit.php?post_type=product' ]
			: [ 'index.php', 'edit.php', 'upload.php', 'profile.php' ];
		if ( Workspace_Admin_Service::uses_workspace( wp_get_current_user() ) ) {
			$allowed[] = Workspace_Admin_Service::PAGE;
			if ( current_user_can( 'upload_files' ) ) $allowed[] = Media_App_Service::PAGE;
			$allowed[] = 'profile.php';
			if ( current_user_can( 'kiwe_use_notifications' ) ) $allowed[] = 'kiwe-notifications';
			if ( current_user_can( 'publish_posts' ) || current_user_can( 'edit_pages' ) || current_user_can( 'edit_products' ) || current_user_can( 'upload_files' ) ) $allowed[] = self::SEO_OBJECT_PAGE;
			if ( current_user_can( 'moderate_comments' ) ) $allowed[] = 'kiwe-feedback';
			if ( current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' ) ) $allowed[] = 'kiwe-store';
			if ( current_user_can( 'kiwe_guest_submit' ) || ( class_exists( Guest_App_Service::class ) && Guest_App_Service::enabled() && ( current_user_can( 'edit_others_posts' ) || current_user_can( 'edit_others_products' ) ) ) ) $allowed[] = 'kiwe-guests';
		}
		if ( Site_Owner_Service::enabled() ) {
			$allowed = 'administrator' === $role ? [ 'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php', 'users.php', 'kiwe-team', 'kiwe-context-library', 'kiwe-feedback', 'kiwe-navigation', 'kiwe-dock-settings', 'kiwe-services', 'kiwe-ai-features', self::SEO_OBJECT_PAGE, 'kiwe-guests', 'kiwe-notifications', 'kiwe-store', 'profile.php' ] : $allowed;
			if ( 'contributor' === $role ) $allowed = [ 'index.php', 'kiwe-guests', 'kiwe-notifications', 'profile.php' ];
			if ( in_array( $role, [ 'author', 'editor', 'shop_manager' ], true ) ) {
				$allowed[] = 'index.php';
				$allowed[] = 'kiwe-notifications';
			}
			if ( 'administrator' === $role ) {
				foreach ( \DSA\Onboarding\Onboarding_Service::section_slugs() as $slug ) $allowed[] = $slug;
				if ( 'dashboard' === ( Site_Owner_Service::policy()['rankMath'] ?? '' ) ) $allowed[] = 'rank-math';
			}
		}
		if ( Workspace_Admin_Service::uses_workspace( wp_get_current_user() ) ) $allowed[] = Workspace_Admin_Service::PAGE;
		if ( current_user_can( 'kiwe_manage_context' ) ) $allowed[] = \DSA\Onboarding\Design_Context_Journey_Service::PAGE;
		if ( class_exists(App_Workspace_Service::class) && App_Workspace_Service::allowed() ) $allowed[] = App_Workspace_Service::PAGE;
		if ( class_exists( Trash_Workspace_Service::class ) && Trash_Workspace_Service::allowed() ) $allowed[] = Trash_Workspace_Service::PAGE;
		if ( class_exists( Post_Workspace_Service::class ) && Post_Workspace_Service::enabled() && current_user_can( 'edit_posts' ) ) $allowed[] = Post_Workspace_Service::PAGE;
		if ( ( current_user_can( 'publish_posts' ) || current_user_can( 'edit_pages' ) || current_user_can( 'edit_products' ) || current_user_can( 'upload_files' ) ) ) $allowed[] = self::SEO_OBJECT_PAGE;
		if ( class_exists( Continuity_App_Service::class ) && Continuity_App_Service::enabled() ) {
			if ( current_user_can( 'kiwe_view_pages' ) ) $allowed[] = 'kiwe-pages';
			if ( current_user_can( 'kiwe_manage_context' ) ) { $allowed[] = 'kiwe-store-plan'; $allowed[] = 'kiwe-service-categories'; }
			if ( current_user_can( 'moderate_comments' ) ) $allowed[] = 'kiwe-feedback';
			if ( ( new Continuity_App_Service() )->can_view_timeline() ) $allowed[] = 'kiwe-timeline';
		}
		if ( Workspace_Admin_Service::uses_workspace( wp_get_current_user() ) && current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_products' ) ) $allowed[] = 'kiwe-product-categories';
		if ( class_exists( Taxonomy_App_Service::class ) && Taxonomy_App_Service::enabled() && ( new Taxonomy_App_Service() )->tag_definitions() ) $allowed[] = Taxonomy_App_Service::TAGS_PAGE;
		if ( Template_Workspace_Service::allowed() ) $allowed[] = Template_Workspace_Service::PAGE;
		if ( Analytics_Workspace_Service::allowed() ) $allowed[] = Analytics_Workspace_Service::PAGE;
		if ( class_exists( Form_Workspace_Service::class ) && Form_Workspace_Service::allowed() ) $allowed[] = Form_Workspace_Service::PAGE;
		foreach ( \DSA\Apps\Required_App_Service::IDS as $app ) if ( \DSA\Apps\Required_App_Service::allowed( $app ) ) $allowed[] = 'kiwe-' . $app . '-app';
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
		// Optional presentation must not replace native editors or their AJAX.
		// Native handlers, capability/object guards and REST policy still authorize work.
		if ( Workspace_Admin_Service::prefers_native_dashboard( wp_get_current_user() ) ) return;
		$role = $this->restricted_role();
		if ( '' !== $role && ( Site_Owner_Service::enabled() || Workspace_Admin_Service::uses_workspace( wp_get_current_user() ) ) ) {
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
		if ( in_array( Template_Workspace_Service::ROLE,$roles,true ) ) return Template_Workspace_Service::ROLE;
		if ( in_array( 'shop_manager', $roles, true ) ) {
			return 'shop_manager';
		}
		if ( in_array( 'editor', $roles, true ) ) {
			return 'editor';
		}
		if ( in_array( 'author', $roles, true ) ) {
			return 'author';
		}
		if ( in_array( 'contributor', $roles, true ) ) return 'contributor';
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
		if ( ! Workspace_Admin_Service::requires_workspace( wp_get_current_user() ) || ! is_array( $order ) ) return $order;
		$priority = array_merge( [ 'index.php', 'edit.php', 'upload.php', 'woocommerce', 'edit.php?post_type=product' ], \DSA\Onboarding\Onboarding_Service::section_slugs(), [ 'edit.php?post_type=page', 'kiwe-team', 'kiwe-context-library', 'kiwe-feedback', 'kiwe-navigation', 'kiwe-dock-settings', 'kiwe-services', 'kiwe-ai-features', 'kiwe-guests', 'kiwe-store', 'kiwe-notifications', 'edit-comments.php', 'users.php', 'profile.php' ] );
		array_unshift( $priority, Workspace_Admin_Service::PAGE );
		return array_merge( array_values( array_intersect( $priority, $order ) ), array_values( array_diff( $order, $priority ) ) );
	}

	public function toolbar( $bar ): void {
		if ( Workspace_Admin_Service::prefers_native_dashboard( wp_get_current_user() ) ) return;
		if ( ! Site_Owner_Service::enabled() || Site_Owner_Service::is_owner() ) return;
		foreach ( [ 'wp-logo', 'updates', 'customize', 'themes', 'widgets', 'menus', 'rank-math', 'hostinger', 'litespeed-menu', 'dsa-cache', 'kiwe-cache', 'new-page', 'new-web-story' ] as $id ) $bar->remove_node( $id );
	}

	public function guard_builder(): void {
		if ( is_user_logged_in() && isset( $_GET['bricks'] ) && ! Template_Workspace_Service::builder_actor() ) $this->deny();
	}

	/** Bricks 2.3.10 caches access from raw user/role caps, not only user_can(). */
	public function enforce_bricks_boundary(): void {
		if ( ! class_exists( '\\Bricks\\Capabilities' ) || ! is_user_logged_in() || Site_Owner_Service::is_owner() ) return;
		\Bricks\Capabilities::$full_access = Template_Workspace_Service::builder_actor();
		\Bricks\Capabilities::$edit_content = false;
		\Bricks\Capabilities::$no_access = ! Template_Workspace_Service::builder_actor();
		\Bricks\Capabilities::$upload_svg = false;
		\Bricks\Capabilities::$execute_code = false;
		\Bricks\Capabilities::$form_submission_access = false;
		\Bricks\Capabilities::$capabilities_set = true;
		// Do not rewrite Bricks roles/options or touch visitor forms/rendering.
	}
	public function bricks_access_column( $value, string $column, int $id ) {
		if ( 'bricks_capabilities' !== $column || ! Site_Owner_Service::enabled() ) return $value;
		return Template_Workspace_Service::builder_actor( $id ) ? 'Builder: Full access (Kiwe role)' : 'Builder: No access (Kiwe policy)';
	}

	private function guard_client_route( string $role ): void {
		if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
		global $pagenow;
		$script = (string) $pagenow;
		$work_roles = [ 'administrator',Template_Workspace_Service::ROLE,'author','editor','contributor','shop_manager' ];
		if ( wp_doing_ajax() ) { $this->guard_ajax( $role ); return; }
		if ( ! in_array( $role, $work_roles, true ) ) { wp_safe_redirect( home_url( '/' ) ); exit; }
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( 'index.php' === $script ) return;
		if ( 'admin-post.php' === $script && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && \DSA\Apps\Store_App_Service::ACTION === ( $_POST['action'] ?? '' ) && \DSA\Apps\Store_App_Service::allowed() ) return;
		if ( 'admin-post.php' === $script && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && \DSA\Apps\SEO_App_Service::ACTION === ( $_POST['action'] ?? '' ) && \DSA\Apps\SEO_App_Service::allowed() ) return; // Same owner as the App REST switch; native handler verifies nonce, origin and stale state.
		if ( class_exists( App_Workspace_Service::class ) && 'admin.php' === $script && App_Workspace_Service::PAGE === $page ) {
			if ( App_Workspace_Service::enabled() && App_Workspace_Service::allowed() ) return;
			$this->deny();
		}
		if ( current_user_can( 'kiwe_manage_context' ) ) {
			if ( 'admin.php' === $script && \DSA\Onboarding\Design_Context_Journey_Service::PAGE === $page ) return;
			if ( 'admin-post.php' === $script && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'kiwe_context_journey' === ( $_POST['action'] ?? '' ) ) return; // The handler checks nonce, current preference version and step authority.
		}
		if ( 'admin.php' === $script && Template_Workspace_Service::PAGE === $page && Template_Workspace_Service::allowed() ) return;
		if ( 'admin.php' === $script && Analytics_Workspace_Service::PAGE === $page && Analytics_Workspace_Service::allowed() ) return;
		if ( class_exists( Form_Workspace_Service::class ) && 'admin.php' === $script && Form_Workspace_Service::PAGE === $page && Form_Workspace_Service::allowed() ) return;
		if ( 'admin.php' === $script && Backup_Workspace_Service::PAGE === $page && Backup_Workspace_Service::allowed() ) return;
		if ( 'admin-post.php' === $script && Backup_Workspace_Service::allowed() && 'kiwe_backup_download' === sanitize_key( $_GET['action'] ?? '' ) ) return;
		if ( 'admin-post.php' === $script && defined( 'KIWE_PORTABLE_CAPTURE_PREVIEW' ) && true === KIWE_PORTABLE_CAPTURE_PREVIEW && class_exists( Portable_Capture_App_Service::class ) && Portable_Capture_App_Service::enabled() && Backup_Workspace_Service::allowed() && Portable_Capture_App_Service::DOWNLOAD === sanitize_key( $_GET['action'] ?? '' ) ) return; // The handler rechecks method, origin, nonce, actor and live capture authority.
		if ( 'admin-post.php' === $script && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && in_array( sanitize_key( $_GET['action'] ?? '' ), [ 'kiwe_content_download','kiwe_media_download','kiwe_design_download' ], true ) ) return; // The download handler rechecks its nonce, actor and native record authority.
		if ( Template_Workspace_Service::ROLE === $role ) {
			if ( 'admin.php' === $script && 'kiwe-pages' === $page && current_user_can( 'kiwe_view_pages' ) ) return;
			if ( in_array( $script,[ 'load-scripts.php','load-styles.php','async-upload.php' ],true ) ) return;
			if ( 'post.php' === $script && in_array( get_post_type( absint( $_REQUEST['post'] ?? $_POST['post_ID'] ?? 0 ) ),[ 'page','bricks_template','attachment' ],true ) ) return;
		}
		if ( 'admin.php' === $script && Workspace_Admin_Service::PAGE === $page && current_user_can( 'read' ) ) return;
		foreach ( \DSA\Apps\Required_App_Service::IDS as $app ) if ( 'admin.php' === $script && 'kiwe-' . $app . '-app' === $page && \DSA\Apps\Required_App_Service::allowed( $app ) ) return;
		if ( 'admin.php' === $script && Media_App_Service::PAGE === $page && current_user_can( 'upload_files' ) ) return;
		if ( 'admin.php' === $script && class_exists( Trash_Workspace_Service::class ) && Trash_Workspace_Service::PAGE === $page && Trash_Workspace_Service::allowed() ) return;
		if ( class_exists( Post_Workspace_Service::class ) && 'admin.php' === $script && Post_Workspace_Service::PAGE === $page && Post_Workspace_Service::enabled() && current_user_can( 'edit_posts' ) ) return;
		if ( class_exists( Post_Workspace_Service::class ) && 'admin.php' === $script && Post_Workspace_Service::PAGE === $page && ! Post_Workspace_Service::enabled() && current_user_can( 'edit_posts' ) ) { wp_safe_redirect( admin_url( 'edit.php' ) ); exit; }
		if ( class_exists( Post_Workspace_Service::class ) && Post_Workspace_Service::enabled() && current_user_can( 'edit_posts' ) && in_array( $script, [ 'edit.php', 'post-new.php', 'post.php' ], true ) && ( 'edit.php' !== $script || '' === $page ) ) {
			$type = sanitize_key( $_REQUEST['post_type'] ?? 'post' );
			if ( 'post.php' === $script ) $type = (string) get_post_type( absint( $_REQUEST['post'] ?? $_POST['post_ID'] ?? 0 ) );
			if ( 'post' === $type && ( 'post-new.php' !== $script || current_user_can( 'create_posts' ) ) ) {
				$id = 'post.php' === $script ? absint( $_REQUEST['post'] ?? $_POST['post_ID'] ?? 0 ) : 0;
				wp_safe_redirect( Post_Workspace_Service::url( $id ) ); exit;
			}
		}
		if ( class_exists( Commerce_App_Service::class ) && Commerce_App_Service::enabled() && 'admin.php' === $script && Commerce_App_Service::PAGE === $page && ( current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' ) ) ) return;
		if ( class_exists( Taxonomy_App_Service::class ) && Taxonomy_App_Service::enabled() && 'admin.php' === $script && Taxonomy_App_Service::PRODUCT_PAGE === $page && current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_products' ) ) return;
		if ( class_exists( Taxonomy_App_Service::class ) && Taxonomy_App_Service::enabled() && 'admin.php' === $script && Taxonomy_App_Service::TAGS_PAGE === $page && ( new Taxonomy_App_Service() )->tag_definitions() ) return;
		if ( 'admin.php' === $script && \DSA\Apps\Library_Service::PAGE === $page && \DSA\Apps\Native_State::can_review() ) return;
		if ( 'admin.php' === $script && 'kiwe-notifications' === $page && current_user_can( 'kiwe_use_notifications' ) ) return;
		if ( class_exists( Taxonomy_App_Service::class ) && Taxonomy_App_Service::enabled() && 'admin.php' === $script && Taxonomy_App_Service::SERVICE_PAGE === $page && ( new Taxonomy_App_Service() )->service_definitions() ) return;
		if ( 'admin.php' === $script && self::SEO_OBJECT_PAGE === $page && ( current_user_can( 'publish_posts' ) || current_user_can( 'edit_pages' ) || current_user_can( 'edit_products' ) || current_user_can( 'upload_files' ) ) ) return;
		if ( class_exists( Continuity_App_Service::class ) && Continuity_App_Service::enabled() && 'admin.php' === $script && 'kiwe-feedback' === $page && current_user_can( 'moderate_comments' ) ) return;
		if ( class_exists( Continuity_App_Service::class ) && 'admin.php' === $script && 'kiwe-timeline' === $page && ( new Continuity_App_Service() )->can_view_timeline() ) return;
		if ( class_exists( Taxonomy_App_Service::class ) && Taxonomy_App_Service::enabled() && 'edit.php' === $script && Taxonomy_App_Service::PAGE === $page && ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_categories' ) ) ) return;
		if ( 'admin.php' === $script && 'kiwe-guests' === $page && ( current_user_can( 'kiwe_manage_guest_applications' ) || current_user_can( 'kiwe_guest_submit' ) || ( class_exists( Guest_App_Service::class ) && Guest_App_Service::enabled() && ( current_user_can( 'edit_others_posts' ) || current_user_can( 'edit_others_products' ) ) ) ) ) return;
		if ( 'admin-post.php' === $script && in_array( sanitize_key( $_POST['action'] ?? '' ), [ 'kiwe_save_notification_preferences', 'kiwe_save_notification_policy', 'kiwe_guest_save_settings', 'kiwe_guest_decide', 'kiwe_guest_submit' ], true ) ) return;
		if ( 'contributor' === $role ) {
			if ( in_array( $script, [ 'profile.php', 'load-scripts.php', 'load-styles.php' ], true ) ) return;
			$this->deny();
		}
		if ( 'administrator' === $role ) {
			$type = sanitize_key( $_REQUEST['post_type'] ?? 'post' );
			if ( 'edit.php' === $script && 'post' === $type && 'kiwe-categories' === $page && current_user_can( 'manage_categories' ) ) return;
			if ( 'admin.php' === $script && Team_Workspace_Service::PAGE === $page && current_user_can( 'kiwe_manage_team' ) ) return;
			if ( 'admin.php' === $script && Context_Workspace_Service::PAGE === $page && current_user_can( 'kiwe_manage_context' ) ) return;
			if ( 'admin.php' === $script && 'kiwe-dock-settings' === $page && current_user_can( 'kiwe_manage_navigation' ) ) return;
			if ( 'admin.php' === $script && Navigation_Workspace_Service::PAGE === $page && current_user_can( 'kiwe_manage_navigation' ) ) return;
			if ( 'admin.php' === $script && 'kiwe-ai-features' === $page && current_user_can( 'kiwe_manage_ai_preferences' ) ) return;
			// Each handler also checks its nonce, payload and object capabilities.
			if ( 'admin-post.php' === $script && in_array( $_POST['action'] ?? '', [ 'kiwe_category_create', 'kiwe_category_rename', 'kiwe_category_move' ], true ) && current_user_can( 'manage_categories' ) ) return;
			if ( 'admin-post.php' === $script && Team_Workspace_Service::ACTION === ( $_POST['action'] ?? '' ) && current_user_can( 'kiwe_manage_team' ) ) return;
			if ( 'admin-post.php' === $script && Context_Workspace_Service::ACTION === ( $_POST['action'] ?? '' ) && current_user_can( 'kiwe_manage_context' ) ) return;
			if ( 'admin-post.php' === $script && Navigation_Workspace_Service::ACTION === ( $_POST['action'] ?? '' ) && current_user_can( 'kiwe_manage_navigation' ) ) return;
			if ( 'admin-post.php' === $script && 'kiwe_ai_preference' === ( $_POST['action'] ?? '' ) && current_user_can( 'kiwe_manage_ai_preferences' ) ) return;
			if ( 'edit.php' === $script && 'page' === $type && in_array( $page, [ '', 'kiwe-page-details' ], true ) ) return;
			if ( 'post-new.php' === $script && 'page' === $type ) { wp_safe_redirect( Page_Workspace_Service::url() ); exit; }
			if ( 'admin-post.php' === $script && in_array( $_POST['action'] ?? '', [ 'kiwe_create_page', 'kiwe_page_indexing', 'kiwe_page_title' ], true ) ) return;
			if ( 'admin.php' === $script && in_array( $page, array_merge( [ 'kiwe-pages','kiwe-store-plan','kiwe-service-categories','kiwe-onboarding' ], \DSA\Onboarding\Onboarding_Service::section_slugs() ), true ) ) return;
			if ( 'admin.php' === $script && 'dashboard' === ( Site_Owner_Service::policy()['rankMath'] ?? '' ) && str_starts_with( $page, 'rank-math' ) ) return;
			if ( 'admin-post.php' === $script && 'kiwe_save_onboarding' === ( $_POST['action'] ?? '' ) ) return;
			if ( ! $page && in_array( $script, [ 'users.php','user-new.php','user-edit.php','edit-comments.php','comment.php','edit-tags.php','term.php' ], true ) ) {
				if ( in_array( $script, [ 'edit-tags.php','term.php' ], true ) && ! in_array( $_REQUEST['taxonomy'] ?? '', [ 'category','post_tag' ], true ) ) $this->deny();
				return;
			}
		}
		if ( in_array( $script, [ 'profile.php','media-upload.php','async-upload.php','load-scripts.php','load-styles.php' ], true ) ) return;
		if ( in_array( $script, [ 'upload.php','media-new.php' ], true ) && current_user_can( 'upload_files' ) && in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) { wp_safe_redirect( Media_App_Service::url() ); exit; }
		$type = sanitize_key( $_REQUEST['post_type'] ?? 'post' );
		if ( 'post.php' === $script ) $type = (string) get_post_type( absint( $_REQUEST['post'] ?? $_POST['post_ID'] ?? 0 ) );
		$expected = 'shop_manager' === $role ? 'product' : 'post';
		if ( 'post.php' === $script && 'attachment' === $type && current_user_can( 'upload_files' ) && in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) { wp_safe_redirect( Media_App_Service::url( absint( $_REQUEST['post'] ?? 0 ) ) ); exit; }
		if ( in_array( $script, [ 'edit.php','post.php','post-new.php' ], true ) && $expected === $type && empty( $_GET['page'] ) ) {
			if ( 'post-new.php' !== $script || current_user_can( 'shop_manager' === $role ? 'create_products' : 'create_posts' ) ) return;
		}
		$this->deny();
	}

	private function guard_ajax( string $role ): void {
		$action = sanitize_key( $_REQUEST['action'] ?? '' );
		// The native media picker deletes through admin-ajax.php?action=delete-post.
		// Permit only a real attachment the actor may delete; WordPress still
		// verifies the per-attachment nonce before removing it.
		if ( 'delete-post' === $action && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$id = absint( $_POST['id'] ?? 0 );
			if ( $id && 'attachment' === get_post_type( $id ) && current_user_can( 'delete_post', $id ) ) return;
		}
		if ( Template_Workspace_Service::builder_actor() && str_starts_with( $action,'bricks_' ) && ! in_array( $action,[ 'bricks_save_settings','bricks_generate_code_signature' ],true ) ) return; // Bricks checks its own nonce, object access and operation.
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
		if ( Template_Workspace_Service::builder_actor() && ( str_starts_with( $route,'/bricks/v1/' ) || preg_match( '#^/wp/v2/(?:pages|bricks_template)(?:/|$)#',$route ) ) ) return $result;
		if ( 'POST' === $method && '/dsa/v1/workspace/activity/seen' === rtrim( $route, '/' ) && current_user_can( 'read' ) ) return $result; // Actor-bound native counters recheck admitted collection, nonce and signed ceiling.
		if ( 'POST' === $method && '/dsa/v1/workspace/app-library' === rtrim( $route, '/' ) && \DSA\Apps\Native_State::can_review() ) return $result; // Native software permissions and exact review/nonce are rechecked by the library.
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/system/(auth|security)/?$#', $route, $system ) && \DSA\Apps\Required_App_Service::allowed( $system[1] ) ) return $result; // Included App retains native authority, nonce, origin and fresh-state checks.
		if ( 'POST' === $method && '/dsa/v1/workspace/apps/store' === rtrim( $route, '/' ) && \DSA\Apps\Store_App_Service::allowed() ) return $result;
		if ( 'POST' === $method && '/dsa/v1/workspace/apps/seo' === rtrim( $route, '/' ) && \DSA\Apps\SEO_App_Service::allowed() ) return $result; // The app owner rechecks nonce, origin, mutation proof and current state.
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/templates(?:/[1-9][0-9]*)?/?$#',$route ) && Template_Workspace_Service::allowed() ) return $result;
		if ( 'POST' === $method && '/dsa/v1/workspace/trash' === rtrim( $route, '/' ) && Trash_Workspace_Service::allowed() ) return $result; // Native owner rechecks type, object capability, nonce, origin and exact review.
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/analytics/(?:review|strategy|setup)/?$#',$route ) && Analytics_Workspace_Service::allowed() ) return $result;
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/backups(?:/(?:preview|restore|remove|request|resolve))?/?$#',$route ) && Backup_Workspace_Service::allowed() ) return $result;
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/site-captures(?:/[a-f0-9]{32}/queue)?/?$#D',$route ) && defined( 'KIWE_PORTABLE_CAPTURE_PREVIEW' ) && true === KIWE_PORTABLE_CAPTURE_PREVIEW && class_exists( Portable_Capture_App_Service::class ) && Portable_Capture_App_Service::enabled() && Backup_Workspace_Service::allowed() ) return $result;
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/site-captures/[a-f0-9]{32}/remove/?$#D',$route ) && defined( 'KIWE_PORTABLE_CAPTURE_PREVIEW' ) && true === KIWE_PORTABLE_CAPTURE_PREVIEW && class_exists( Portable_Capture_App_Service::class ) && Portable_Capture_App_Service::enabled() && current_user_can( 'read' ) ) return $result; // Removal rechecks the private job owner, including expired or revoked attempts.
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/content-archives/(?:export|preview|import|jobs/[a-f0-9]{32}/step)/?$#',$route ) && ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' ) || current_user_can( 'edit_products' ) || ( class_exists( \DSA\Backup\Content_Archive_Service::class ) && ( \DSA\Backup\Content_Archive_Service::can_export_collection( 'services' ) || \DSA\Backup\Content_Archive_Service::can_import( 'service' ) ) ) ) ) return $result;
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/media-archives/(?:export|preview|jobs/[a-f0-9]{32}/step)/?$#', $route ) && current_user_can( 'upload_files' ) ) return $result;
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/design-packages/(?:export|preview|jobs/[a-f0-9]{32}/(?:step|review_settings|apply_settings))/?$#D', $route ) && \DSA\Backup\Bricks_Design_Package_Service::allowed() ) return $result;
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/design-packages/jobs/[a-f0-9]{32}/remove/?$#D', $route ) && current_user_can( 'read' ) ) return $result; // The native handler rechecks nonce, origin and ownership, including after design access is lost.
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/media-archives/jobs/[a-f0-9]{32}/remove/?$#', $route ) && current_user_can( 'read' ) ) return $result; // Native media cleanup rechecks ownership and adopted copies.
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/content-archives/jobs/[a-f0-9]{32}/remove/?$#',$route ) && current_user_can( 'read' ) ) return $result; // The actor-owned private job handler rechecks nonce, origin and any native partial cleanup; committed content is kept.
		// Public reads are kept public. Native REST permission callbacks still decide object access.
		if ( in_array( $method, [ 'GET','HEAD','OPTIONS' ], true ) ) return $result;
		if ( preg_match( '#^/wp/v2/(posts|media|comments|users|categories|tags)(?:/|$)#', $route ) || '/wp/v2/batch' === $route || '/batch/v1' === $route ) return $result;
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/pages/[1-9][0-9]*/name/?$#', $route ) && current_user_can( 'kiwe_manage_page_titles' ) ) return $result;
		if ( 'POST' === $method && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && preg_match( '#^/dsa/v1/workspace/team(?:/[1-9][0-9]*)?/?$#', $route ) && current_user_can( 'kiwe_manage_team' ) ) return $result;
		if ( 'POST' === $method && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && preg_match( '#^/dsa/v1/workspace/user-groups(?:/(?:g-[a-f0-9]{16}|sms|email|whatsapp|app))?/?$#', $route ) && User_Group_Service::allowed() ) return $result;
		if ( defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && preg_match( '#^/dsa/v1/workspace/posts(?:/terms|/[1-9][0-9]*(?:(?:/revisions(?:/[1-9][0-9]*/restore)?)|(?:/autosave(?:/[1-9][0-9]*/discard)?))?)?/?$#', $route ) && current_user_can( 'edit_posts' ) ) return $result;
		if ( 'POST' === $method && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && preg_match( '#^/dsa/v1/workspace/record-controls/(?:post|product|service)/[1-9][0-9]*/?$#', $route ) && ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_products' ) || current_user_can( 'kiwe_manage_context' ) ) ) return $result;
		if ( 'POST' === $method && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && '/dsa/v1/workspace/continuity/timeline' === rtrim( $route, '/' ) && ( current_user_can( 'edit_posts' ) || current_user_can( Page_Publishing_Service::CAP_RESTORE ) ) ) return $result; // The adapter rechecks the exact record, writer, operation, nonce and review proofs.
		if ( 'POST' === $method && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && preg_match( '#^/dsa/v1/workspace/continuity/(pages|library|feedback|story|contact|brand|website-plan|services|store)/?$#', $route, $area ) && ( 'pages' === $area[1] ? current_user_can( 'kiwe_view_pages' ) : ( 'feedback' === $area[1] ? current_user_can( 'moderate_comments' ) : current_user_can( 'kiwe_manage_context' ) ) ) ) return $result;
		if ( 'POST' === $method && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && '/dsa/v1/workspace/identity' === rtrim( $route, '/' ) && current_user_can( 'kiwe_manage_context' ) ) return $result;
		if ( 'POST' === $method && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && preg_match( '#^/dsa/v1/workspace/taxonomies/product_cat(?:/[1-9][0-9]*(?:/move)?)?/?$#', $route ) && current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_product_terms' ) && current_user_can( 'edit_products' ) ) return $result;
		if ( 'POST' === $method && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && preg_match( '#^/dsa/v1/workspace/taxonomies/product_tag(?:/[1-9][0-9]*(?:/move)?)?/?$#', $route ) && current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_product_terms' ) && current_user_can( 'edit_products' ) ) return $result;
		if ( 'POST' === $method && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && preg_match( '#^/dsa/v1/workspace/tag-groups(?:/(?:[1-9][0-9]*|move))?/?$#', $route ) && ( current_user_can( 'manage_categories' ) || current_user_can( 'manage_product_terms' ) ) ) return $result;
		if ( defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && preg_match( '#^/dsa/v1/workspace/taxonomies(?:/[a-z0-9_-]+(?:/[1-9][0-9]*)?(?:/move)?)?/?$#', $route ) && ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_categories' ) ) ) return $result;
		if ( preg_match( '#^/dsa/v1/workspace/dock/?$#D', $route ) && defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && current_user_can( 'kiwe_manage_navigation' ) ) return $result;
		if ( 'POST' === $method && '/dsa/v1/workspace/app' === rtrim( $route, '/' ) && class_exists( App_Workspace_Service::class ) && App_Workspace_Service::enabled() && App_Workspace_Service::allowed() ) return $result; // The App owner rechecks nonce, origin, exact fields and native compare-and-save.
		if ( defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && preg_match( '#^/dsa/v1/workspace/navigation(?:/(?:groups|move|[1-9][0-9]*))?/?$#D', $route ) && current_user_can( 'kiwe_manage_navigation' ) ) return $result;
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/menus/[1-9][0-9]*/items/[1-9][0-9]*/label/?$#', $route ) && current_user_can( 'kiwe_manage_navigation' ) ) return $result;
		if ( 'POST' === $method && preg_match( '#^/dsa/v1/workspace/context/(?:media|testimonial)/[1-9][0-9]*/selection/?$#', $route ) && current_user_can( 'kiwe_manage_context' ) ) return $result;
		if ( defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && 'POST' === $method ) {
			if ( preg_match( '#^/dsa/v1/workspace/seo/object/[1-9][0-9]*/(?:proposal|save|apply|dismiss)/?$#', $route ) && ( current_user_can( 'publish_posts' ) || current_user_can( 'edit_pages' ) || current_user_can( 'edit_products' ) || current_user_can( 'upload_files' ) || current_user_can( 'kiwe_manage_context' ) ) ) return $result;
			if ( preg_match( '#^/dsa/v1/workspace/seo/term/[a-z0-9_]+/[1-9][0-9]*/(?:proposal|save)/?$#', $route ) && ( current_user_can( 'manage_categories' ) || current_user_can( 'manage_product_terms' ) || current_user_can( 'kiwe_manage_context' ) ) ) return $result;
			if ( preg_match( '#^/dsa/v1/workspace/seo/history/(?:start|control|review)/?$#', $route ) && current_user_can( 'kiwe_manage_ai_preferences' ) ) return $result;
			if ( '/dsa/v1/workspace/ai/preference' === rtrim( $route, '/' ) && current_user_can( 'kiwe_manage_ai_preferences' ) ) return $result;
			if ( preg_match( '#^/dsa/v1/workspace/guests/(?:settings|applications/[1-9][0-9]*|submissions(?:/[1-9][0-9]*)?)/?$#', $route ) ) return $result; // Each adapter rechecks the exact operation and object.
			if ( preg_match( '#^/dsa/v1/workspace/notifications/(?:preferences|acknowledge|seen)/?$#', $route ) && current_user_can( 'kiwe_use_notifications' ) ) return $result;
			if ( '/dsa/v1/workspace/notifications/site-emails' === rtrim( $route, '/' ) && current_user_can( 'kiwe_manage_notification_policy' ) && current_user_can( 'moderate_comments' ) ) return $result;
			if ( in_array( rtrim($route,'/'), [ '/dsa/v1/workspace/notifications/campaigns','/dsa/v1/workspace/campaigns' ], true ) && current_user_can( 'kiwe_manage_notification_policy' ) ) return $result;
			if ( '/dsa/v1/workspace/commerce/checkout-policy' === rtrim( $route,'/' ) && current_user_can( 'kiwe_manage_context' ) && ( current_user_can( 'edit_products' ) || current_user_can( 'edit_others_shop_orders' ) ) ) return $result;
			if ( preg_match( '#^/dsa/v1/workspace/commerce/saved-carts/[1-9][0-9]*/?$#D',$route ) && current_user_can( 'edit_others_shop_orders' ) && current_user_can( 'kiwe_manage_notification_policy' ) ) return $result; // The cart owner rechecks consent, suspension, reservation and the exact review proof.
			if ( preg_match( '#^/dsa/v1/workspace/commerce/products(?:/[1-9][0-9]*(?:/(?:inventory|delivery|offer|nutrition|sale|tax|merchandising(?:/choices)?|links(?:/preview)?))?)?/?$#', $route ) && current_user_can( 'edit_products' ) ) return $result;
			if ( preg_match('#^/dsa/v1/workspace/commerce/tax(?:/rates/(?:[1-9][0-9]*(?:/(?:rules|geography))?|create))?$#D',$route) && current_user_can('edit_products') && current_user_can('kiwe_manage_context') ) return $result;
			if ( preg_match( '#^/dsa/v1/workspace/commerce/tax/(?:classes(?:/[a-z0-9][a-z0-9-]*)?|rates/[1-9][0-9]*/delete)/?$#D', $route ) && current_user_can( 'edit_products' ) && current_user_can( 'kiwe_manage_context' ) ) return $result;
			if ( preg_match('#^/dsa/v1/workspace/commerce/shipping/classes(?:/[1-9][0-9]*)?/?$#',$route) && current_user_can('edit_products') && current_user_can('kiwe_manage_context') ) return $result;
			if ( preg_match('#^/dsa/v1/workspace/commerce/attributes(?:/[1-9][0-9]*(?:/values(?:/[1-9][0-9]*)?)?)?/?$#D',$route) && current_user_can('edit_products') && current_user_can('manage_product_terms') && current_user_can('edit_product_terms') ) return $result;
			if ( preg_match('#^/dsa/v1/workspace/commerce/products/[1-9][0-9]*/(?:options|variations/(?:0|[1-9][0-9]*))/?$#D',$route) && current_user_can('edit_products') ) return $result; // The native owner checks this exact parent and operation.
			if ( preg_match('#^/dsa/v1/workspace/commerce/shipping/(?:0|[1-9][0-9]*)/methods/[1-9][0-9]*(?:/(?:rates|classes))?/?$#',$route) && current_user_can('edit_products') && current_user_can('kiwe_manage_context') ) return $result;
			if ( preg_match('#^/dsa/v1/workspace/commerce/shipping/[1-9][0-9]*/settings/?$#',$route) && current_user_can('edit_products') && current_user_can('kiwe_manage_context') ) return $result;
			if ( preg_match('#^/dsa/v1/workspace/commerce/shipping/(?:create|[1-9][0-9]*/methods/create)/?$#',$route) && current_user_can('edit_products') && current_user_can('kiwe_manage_context') ) return $result;
			if ( '/dsa/v1/workspace/commerce/offer-policy' === rtrim( $route, '/' ) && current_user_can( 'edit_products' ) && current_user_can( 'kiwe_manage_context' ) ) return $result;
			if ( in_array(rtrim($route,'/'),['/dsa/v1/workspace/commerce/bestsellers/adopt','/dsa/v1/workspace/commerce/bestsellers/sync'],true) && current_user_can( 'edit_products' ) && current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_product_terms' ) ) return $result;
			if ( preg_match( '#^/dsa/v1/workspace/commerce/coupons/(?:create|[1-9][0-9]*(?:/(?:policy|targets|customers))?)/?$#', $route ) && current_user_can('edit_shop_coupons') && current_user_can('edit_others_shop_coupons') ) return $result;
			if ( preg_match( '#^/dsa/v1/workspace/commerce/orders/[1-9][0-9]*/(?:complete|actions|refunds)/?$#', $route ) && current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_others_shop_orders' ) ) return $result;
		}
		if ( str_starts_with( $route, '/phonekey/v3/' ) ) return $result;
		if ( preg_match( '#^/bricks/v1/(?:load_query_page|load_popup_content|query_result)/?$#', $route ) ) return $result;
		if ( preg_match( '#^/dsa/v1/(?:account|saved-items|permissions|notification-preferences|admin-notifications|push/subscription|metrics/event|runtime/hydrate)(?:/|$)#', $route ) ) return $result;
		if ( 'POST' === $method && '/dsa/v1/app/activity' === rtrim( $route, '/' ) ) return $result; // Functional public lifecycle owner rechecks same-site proof, rate, shape and current native identity.
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
