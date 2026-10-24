<?php

namespace DSA\Access;

use DSA\SEO\SEO_Refinement_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role-aware, server-rendered wp-admin shell for client teams.
 *
 * WordPress remains the data and capability authority. This service changes
 * presentation and the dashboard only, and never adds a front-end runtime.
 */
final class Workspace_Admin_Service {
	public const PAGE = 'kiwe-workspace';
	private const WORK_ROLES = [ 'administrator', 'kiwe_web_designer', 'editor', 'author', 'contributor', 'shop_manager' ];
	private bool $store_hooks_registered = false;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 20 );
		add_action( 'admin_init', [ $this, 'redirect_dashboard' ], 0 );
		add_filter( 'login_redirect', [ $this, 'login_redirect' ], PHP_INT_MAX, 3 );
		if ( function_exists( 'WC' ) ) $this->register_store_hooks();
		else add_action( 'plugins_loaded', [ $this, 'register_store_hooks' ], 30 );
		add_filter( 'kiwe_workspace_login_destination', [ $this, 'login_destination' ], PHP_INT_MAX, 2 );
		add_action( 'admin_footer', [ $this, 'navigation' ] );
		add_action( 'admin_menu', [ $this, 'menu_labels' ], PHP_INT_MAX );
		add_action( 'wp_dashboard_setup', [ $this, 'dashboard' ], PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ], PHP_INT_MAX );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_filter( 'admin_footer_text', [ $this, 'footer' ], PHP_INT_MAX );
		add_filter( 'update_footer', [ $this, 'version_footer' ], PHP_INT_MAX );
		add_filter( 'screen_options_show_screen', [ $this, 'screen_options' ], PHP_INT_MAX, 2 );
		add_filter( 'get_user_option_screen_layout_dashboard', [ $this, 'dashboard_columns' ] );
	}
	public function register_store_hooks(): void {
		if ( $this->store_hooks_registered || ! \DSA\Apps\Store_App_Service::enabled() ) return;
		$this->store_hooks_registered = true;
		add_filter( 'woocommerce_login_redirect', [ $this, 'login_destination' ], PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_prevent_admin_access', [ $this, 'commerce_admin_access' ], PHP_INT_MAX );
	}

	/** Presentation policy only: does not grant capabilities or create a session. */
	public static function uses_workspace( $user ): bool {
		if ( ! $user instanceof \WP_User || $user->ID < 1 || ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) ) {
			return false;
		}
		if ( self::uses_native_admin( $user ) ) return true;
		$role = WordPress_Role_Access_Service::role_for( $user );
		// Home is presentation, including before an owner policy is configured.
		// Native capability checks still govern each task and technical setting.
		return in_array( $role, self::WORK_ROLES, true );
	}

	/** Technical access remains native; Home admission must not remove it. */
	public static function uses_native_admin( $user ): bool {
		return $user instanceof \WP_User && $user->ID > 0 && ( Site_Owner_Service::is_owner( (int) $user->ID ) || ( is_multisite() && is_super_admin( $user->ID ) ) );
	}

	public static function url(): string { return admin_url( 'admin.php?page=' . self::PAGE ); }

	private function active(): bool {
		$user = wp_get_current_user();
		return is_admin() && is_user_logged_in() && current_user_can( 'read' ) && self::uses_workspace( $user ) && ( ! self::uses_native_admin( $user ) || self::PAGE === ( $_GET['page'] ?? '' ) );
	}

	public function menu(): void {
		if ( ! is_admin() || ! is_user_logged_in() || ! current_user_can( 'read' ) || ! self::uses_workspace( wp_get_current_user() ) ) return;
		add_menu_page( __( 'Kiwe Workspace', 'dsa' ), __( 'Home', 'dsa' ), 'read', self::PAGE, [ $this, 'render_dashboard' ], 'dashicons-admin-home', 2 );
		if ( ! self::uses_native_admin( wp_get_current_user() ) ) remove_menu_page( 'index.php' );
	}

	public function login_destination( $destination, $user ): string {
		return self::uses_workspace( $user ) ? self::url() : (string) $destination;
	}

	public function login_redirect( $destination, $requested, $user ): string {
		return $this->login_destination( $destination, $user );
	}

	/** Woo's customer redirect must not eject product staff or approved Guests. */
	public function commerce_admin_access( bool $prevent ): bool {
		return self::uses_workspace( wp_get_current_user() ) && current_user_can( 'read' ) ? false : $prevent;
	}

	public function redirect_dashboard(): void {
		// Never redirect a write, AJAX, cron or REST request into an HTML page.
		if ( ! is_admin() || is_network_admin() || is_user_admin() || ! is_user_logged_in() || ! current_user_can( 'read' ) || ! self::uses_workspace( wp_get_current_user() ) || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', [ 'GET', 'HEAD' ], true ) || 'index.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) return;
		// Only existing technical owners can opt into WordPress's original dashboard.
		if ( self::uses_native_admin( wp_get_current_user() ) && '1' === ( $_GET['kiwe-native-dashboard'] ?? '' ) ) return;
		wp_safe_redirect( self::url() );
		exit;
	}

	public function menu_labels(): void {
		if ( ! $this->active() ) return;

		global $menu, $submenu;
		if ( ! is_array( $menu ) ) return;
		foreach ( $menu as &$item ) {
			if ( isset( $item[2] ) && 'index.php' === $item[2] ) {
				$item[0] = __( 'Home', 'dsa' );
				break;
			}
		}
		unset( $item );

		if ( isset( $submenu['index.php'][0][0] ) ) {
			$submenu['index.php'][0][0] = __( 'Home', 'dsa' );
		}
	}

	public function dashboard(): void {
		if ( ! $this->active() ) return;

		global $wp_meta_boxes;
		$wp_meta_boxes['dashboard'] = [];
		// WordPress skips untitled meta boxes before invoking their callback.
		wp_add_dashboard_widget( 'kiwe-workspace-home', __( 'Your workspace', 'dsa' ), [ $this, 'render_dashboard' ] );
	}

	public function assets(): void {
		if ( class_exists( Workspace_Shell_Service::class ) && Workspace_Shell_Service::is_rendering() ) return;
		if ( ! $this->active() ) return;
		Workspace_Design_Service::enqueue();
		wp_enqueue_style( 'kiwe-workspace-admin', DSA_URL . 'assets/css/workspace-admin.css', [ 'kiwe-app-primitives' ], DSA_VERSION );
	}

	public function body_class( string $classes ): string {
		if ( ! $this->active() ) return $classes;
		$role = sanitize_html_class( WordPress_Role_Access_Service::role_for( wp_get_current_user() ) );
		if ( 'admin.php' === ( $GLOBALS['pagenow'] ?? '' ) && self::PAGE === ( $_GET['page'] ?? '' ) ) $classes .= ' kiwe-workspace-home';
		return trim( $classes . ' kiwe-workspace-admin kiwe-workspace-role-' . $role );
	}

	public function footer( string $text ): string {
		return $this->active() ? esc_html__( 'Kiwe Workspace · Powered by WordPress', 'dsa' ) : $text;
	}

	public function version_footer( string $text ): string {
		return $this->active() ? esc_html( 'Kiwe ' . DSA_VERSION ) : $text;
	}

	public function screen_options( bool $show, $screen ): bool {
		return $this->active() && isset( $screen->id ) && 'dashboard' === $screen->id ? false : $show;
	}

	public function dashboard_columns( $columns ): int {
		return $this->active() ? 1 : max( 1, absint( $columns ) );
	}

	public function render_dashboard(): void {
		if ( ! $this->active() ) return;
		$user = wp_get_current_user(); $role = self::uses_native_admin( $user ) ? 'administrator' : WordPress_Role_Access_Service::role_for( $user );
		$site_name = (string) get_bloginfo( 'name' ); $quick = $this->quick_actions( $role );
		// Only actors who can inspect other authors' content receive site-wide counters.
		$counts = current_user_can( 'edit_others_posts' ) ? wp_count_posts( 'post', 'readable' ) : null;
		$comments = current_user_can( 'moderate_comments' ) ? wp_count_comments() : null;
		?>
		<div class="kiwe-workspace" data-kiwe-workspace>
			<header class="kiwe-workspace__hero">
				<div><p class="kiwe-workspace__eyebrow"><?php echo esc_html( $site_name ); ?></p>
				<h1><?php echo esc_html( sprintf( __( 'Good %1$s, %2$s', 'dsa' ), $this->day_part(), $user->first_name ?: $user->display_name ) ); ?></h1>
				<p><?php esc_html_e( 'What would you like to take care of today?', 'dsa' ); ?></p></div>
				<a class="kiwe-workspace__visit" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'View website', 'dsa' ); ?><span aria-hidden="true"> ↗</span></a>
			</header>
			<div class="kiwe-workspace__overview">
				<?php if ( current_user_can( 'kiwe_manage_context' ) ) : ?>
				<section class="kiwe-app-card kiwe-workspace__business">
					<p class="kiwe-workspace__eyebrow"><?php esc_html_e( 'Your business, your website', 'dsa' ); ?></p>
					<h2><?php echo esc_html( $site_name ); ?></h2><p><?php echo esc_html( get_bloginfo( 'description' ) ?: __( 'Start with your name, logo and a few words about your business.', 'dsa' ) ); ?></p>
					<a class="kiwe-app-link" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-identity' ) ); ?>"><?php esc_html_e( 'Update business details', 'dsa' ); ?> <span aria-hidden="true">↗</span></a>
				</section>
				<?php endif; ?>
				<?php if ( $counts ) : ?>
				<section class="kiwe-app-card kiwe-workspace__publishing"><p class="kiwe-workspace__eyebrow"><?php esc_html_e( 'Content at a glance', 'dsa' ); ?></p><h2><?php esc_html_e( 'Keep things moving', 'dsa' ); ?></h2>
					<div class="kiwe-workspace__metrics">
					<?php foreach ( [ 'draft'=>__( 'Drafts', 'dsa' ), 'pending'=>__( 'Awaiting review', 'dsa' ), 'publish'=>__( 'Published', 'dsa' ) ] as $status=>$label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'post_status', $status, admin_url( 'edit.php' ) ) ); ?>"><strong><?php echo esc_html( number_format_i18n( absint( $counts->$status ?? 0 ) ) ); ?></strong><span><?php echo esc_html( $label ); ?></span></a>
					<?php endforeach; ?></div>
					<?php if ( $comments ) : ?><a class="kiwe-workspace__review-link" href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>"><?php echo esc_html( sprintf( __( '%s comments to review', 'dsa' ), number_format_i18n( absint( $comments->moderated ?? 0 ) ) ) ); ?></a><?php endif; ?>
				</section>
				<?php endif; ?>
			</div>
			<section class="kiwe-workspace__quick" id="kiwe-workspace-tasks" aria-labelledby="kiwe-task-heading">
				<div class="kiwe-workspace__section-heading"><div><p class="kiwe-workspace__eyebrow"><?php echo esc_html( $this->role_label( $role ) ); ?></p><h2 id="kiwe-task-heading"><?php esc_html_e( 'Your workspace', 'dsa' ); ?></h2></div></div>
				<div class="kiwe-workspace__actions">
				<?php foreach ( $quick as $action ) : ?>
				<a class="kiwe-app-card" href="<?php echo esc_url( $action['url'] ); ?>"><span class="dashicons <?php echo esc_attr( $action['icon'] ); ?>" aria-hidden="true"></span><strong><?php echo esc_html( $action['label'] ); ?></strong><small><?php echo esc_html( $action['description'] ); ?></small><span class="kiwe-workspace__arrow" aria-hidden="true">↗</span></a>
				<?php endforeach; ?></div>
			</section>
		</div>
		<?php
	}

	/** Ordinary links keep native deep links/history and remain useful without JavaScript. */
	public function navigation(): void {
		if ( class_exists( Workspace_Shell_Service::class ) && Workspace_Shell_Service::is_rendering() ) return;
		if ( ! $this->active() ) return;
		$items = [ [ 'label'=>__( 'Home', 'dsa' ), 'url'=>self::url(), 'icon'=>'dashicons-admin-home' ] ];
		if ( current_user_can( 'kiwe_manage_context' ) ) $items[] = [ 'label'=>__( 'Website', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-identity' ), 'icon'=>'dashicons-admin-site-alt3' ];
		elseif ( \DSA\Apps\Store_App_Service::enabled() && current_user_can( 'edit_products' ) ) $items[] = [ 'label'=>__( 'Store', 'dsa' ), 'url'=>class_exists( Commerce_App_Service::class ) && Commerce_App_Service::enabled() ? Commerce_App_Service::url() : admin_url( 'edit.php?post_type=product' ), 'icon'=>'dashicons-products' ];
		if ( current_user_can( 'edit_posts' ) ) $items[] = [ 'label'=>__( 'Posts', 'dsa' ), 'url'=>self::posting_url(), 'icon'=>'dashicons-admin-post' ];
		elseif ( current_user_can( 'kiwe_guest_submit' ) ) $items[] = [ 'label'=>__( 'Guest', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-guests' ), 'icon'=>'dashicons-welcome-write-blog' ];
		if ( current_user_can( 'kiwe_use_notifications' ) ) $items[] = [ 'label'=>__( 'Alerts', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-notifications' ), 'icon'=>'dashicons-bell' ];
		$items[] = [ 'label'=>__( 'All tasks', 'dsa' ), 'url'=>self::url() . '#kiwe-workspace-tasks', 'icon'=>'dashicons-grid-view' ];
		$current = basename( (string) ( $GLOBALS['pagenow'] ?? '' ) );
		$page = sanitize_key( is_string( $_GET['page'] ?? '' ) ? $_GET['page'] ?? '' : '' );
		echo '<nav class="kiwe-workspace__dock" aria-label="' . esc_attr__( 'Workspace navigation', 'dsa' ) . '">';
		foreach ( $items as $item ) {
			$target = wp_parse_url( $item['url'] ); parse_str( $target['query'] ?? '', $query );
			$selected = empty( $target['fragment'] ) && basename( $target['path'] ) === $current && ( $query['page'] ?? '' ) === $page && ( $query['post_type'] ?? '' ) === ( $_GET['post_type'] ?? '' );
			echo '<a href="' . esc_url( $item['url'] ) . '"' . ( $selected ? ' aria-current="page"' : '' ) . '><span class="dashicons ' . esc_attr( $item['icon'] ) . '" aria-hidden="true"></span><span>' . esc_html( $item['label'] ) . '</span></a>';
		}
		echo '</nav>';
	}

	private function quick_actions( string $role, bool $bounded = true ): array {
		$actions = [];
		if ( Template_Workspace_Service::ROLE === $role ) return [ [ 'label'=>__( 'Pages','dsa' ),'description'=>__( 'Work on page layouts','dsa' ),'url'=>Page_Workspace_Service::url(),'icon'=>'dashicons-admin-page' ] ];
		if ( 'contributor' === $role ) {
			if ( current_user_can( 'kiwe_guest_submit' ) ) $actions[] = [ 'label'=>__( 'Guests', 'dsa' ), 'description'=>__( 'Prepare a contribution', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-guests' ), 'icon'=>'dashicons-welcome-write-blog' ];
			if ( current_user_can( 'kiwe_use_notifications' ) ) $actions[] = [ 'label'=>__( 'Notifications', 'dsa' ), 'description'=>__( 'Choose your alerts', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-notifications' ), 'icon'=>'dashicons-bell' ];
			$actions[] = [ 'label'=>__( 'Profile', 'dsa' ), 'description'=>__( 'Manage your account', 'dsa' ), 'url'=>home_url( '/?kiwe-screen=profile' ), 'icon'=>'dashicons-admin-users' ];
			return $actions;
		}
		if ( 'shop_manager' === $role ) {
			// Shop Managers have no post-category authority, so retain their direct product entrance.
			if ( class_exists( Taxonomy_App_Service::class ) && Taxonomy_App_Service::enabled() && current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_products' ) ) $actions[] = [ 'label'=>__( 'Categories', 'dsa' ), 'description'=>__( 'Organize product categories and search visibility', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-product-categories' ), 'icon'=>'dashicons-category' ];
			if ( \DSA\Apps\Store_App_Service::enabled() && current_user_can( 'create_products' ) ) $actions[] = [ 'label'=>__( 'New product', 'dsa' ), 'description'=>__( 'Add a product', 'dsa' ), 'url'=>Commerce_App_Service::enabled() ? Commerce_App_Service::url() . '#new-product' : admin_url( 'post-new.php?post_type=product' ), 'icon'=>'dashicons-plus' ];
			if ( class_exists( Guest_App_Service::class ) && Guest_App_Service::enabled() && current_user_can( 'edit_others_products' ) ) $actions[] = [ 'label'=>__( 'Guests', 'dsa' ), 'description'=>__( 'Review product proposals', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-guests' ), 'icon'=>'dashicons-yes-alt' ];
			if ( \DSA\Apps\Store_App_Service::enabled() && function_exists( 'wc_get_products' ) && current_user_can( 'edit_products' ) ) $actions[] = [ 'label'=>__( 'Products', 'dsa' ), 'description'=>__( 'Manage products and pricing', 'dsa' ), 'url'=>Commerce_App_Service::enabled() ? Commerce_App_Service::url( 'products' ) : admin_url( 'edit.php?post_type=product' ), 'icon'=>'dashicons-products' ];
			if ( \DSA\Apps\Store_App_Service::enabled() && function_exists( 'wc_get_orders' ) && current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_others_shop_orders' ) ) $actions[] = [ 'label'=>__( 'Orders', 'dsa' ), 'description'=>__( 'Review and fulfil orders', 'dsa' ), 'url'=>Commerce_App_Service::enabled() ? Commerce_App_Service::url( 'orders' ) : admin_url( 'edit.php?post_type=shop_order' ), 'icon'=>'dashicons-cart' ];
			if ( \DSA\Apps\SEO_App_Service::enabled() && current_user_can( 'edit_products' ) ) $actions[] = [ 'label'=>__( 'SEO assistant', 'dsa' ), 'description'=>__( 'Review one product at a time', 'dsa' ), 'url'=>\DSA\Apps\SEO_App_Service::url(), 'icon'=>'dashicons-search' ];
			if ( current_user_can( 'kiwe_use_notifications' ) ) $actions[] = [ 'label'=>__( 'Notifications', 'dsa' ), 'description'=>__( 'Choose your alerts', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-notifications' ), 'icon'=>'dashicons-bell' ];
			return $actions;
		}
		if ( 'editor' === $role && class_exists( Guest_App_Service::class ) && Guest_App_Service::enabled() && current_user_can( 'edit_others_posts' ) ) $actions[] = [ 'label'=>__( 'Guests', 'dsa' ), 'description'=>__( 'Review guest contributions', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-guests' ), 'icon'=>'dashicons-yes-alt' ];
		// Native posting belongs to clients as well as the editorial team.
		if ( current_user_can( 'edit_posts' ) ) {
			if ( current_user_can( 'create_posts' ) ) $actions[] = [ 'label'=>__( 'New post', 'dsa' ), 'description'=>__( 'Write an article or update', 'dsa' ), 'url'=>self::posting_create_url(), 'icon'=>'dashicons-welcome-write-blog' ];
			$actions[] = [ 'label'=>__( 'Posts', 'dsa' ), 'description'=>current_user_can( 'edit_others_posts' ) ? __( 'Manage articles, drafts and submissions', 'dsa' ) : __( 'Manage your articles and drafts', 'dsa' ), 'url'=>self::posting_url(), 'icon'=>'dashicons-admin-post' ];
			if ( current_user_can( 'edit_others_posts' ) && current_user_can( 'publish_posts' ) ) $actions[] = [ 'label'=>__( 'Review submissions', 'dsa' ), 'description'=>__( 'Open posts awaiting approval', 'dsa' ), 'url'=>class_exists( Post_Workspace_Service::class ) && Post_Workspace_Service::enabled() ? add_query_arg( 'status', 'pending', Post_Workspace_Service::url() ) : admin_url( 'edit.php?post_status=pending' ), 'icon'=>'dashicons-yes-alt' ];
		}
		if ( current_user_can( 'moderate_comments' ) && class_exists( Continuity_App_Service::class ) && Continuity_App_Service::enabled() ) $actions[] = [ 'label'=>__( 'Feedback', 'dsa' ), 'description'=>current_user_can( 'kiwe_manage_context' ) ? __( 'Moderate comments and choose testimonials', 'dsa' ) : __( 'Moderate comments and reviews', 'dsa' ), 'url'=>Continuity_App_Service::feedback_url(), 'icon'=>'dashicons-testimonial' ];
		if ( 'administrator' === $role ) {
			if ( \DSA\Apps\Store_App_Service::enabled() && function_exists( 'wc_get_products' ) && current_user_can( 'edit_products' ) ) $actions[] = [ 'label'=>__( 'Products', 'dsa' ), 'description'=>__( 'Manage products and pricing', 'dsa' ), 'url'=>Commerce_App_Service::enabled() ? Commerce_App_Service::url( 'products' ) : admin_url( 'edit.php?post_type=product' ), 'icon'=>'dashicons-products' ];
			if ( \DSA\Apps\Store_App_Service::enabled() && function_exists( 'wc_get_orders' ) && current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_others_shop_orders' ) ) $actions[] = [ 'label'=>__( 'Orders', 'dsa' ), 'description'=>__( 'Review and fulfil orders', 'dsa' ), 'url'=>Commerce_App_Service::enabled() ? Commerce_App_Service::url( 'orders' ) : admin_url( 'edit.php?post_type=shop_order' ), 'icon'=>'dashicons-cart' ];
			if ( current_user_can( 'kiwe_manage_context' ) ) $actions[] = [ 'label'=>__( 'Media', 'dsa' ), 'description'=>__( 'Manage files and choose Design Context', 'dsa' ), 'url'=>Context_Workspace_Service::url(), 'icon'=>'dashicons-images-alt2' ];
			if ( current_user_can( 'kiwe_manage_context' ) && \DSA\Onboarding\Design_Context_Journey_Service::feature_enabled( 'services' ) ) $actions[] = [ 'label'=>__( 'Services', 'dsa' ), 'description'=>__( 'Add and manage your services', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-services' ), 'icon'=>'dashicons-networking' ];
			if ( current_user_can( 'kiwe_manage_ai_preferences' ) ) $actions[] = [ 'label'=>__( 'AI', 'dsa' ), 'description'=>__( 'Choose where AI may help', 'dsa' ), 'url'=>AI_Workspace_Service::url(), 'icon'=>'dashicons-lightbulb' ];
			if ( \DSA\Apps\SEO_App_Service::enabled() && ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' ) || current_user_can( 'edit_products' ) || current_user_can( 'upload_files' ) ) ) $actions[] = [ 'label'=>__( 'SEO assistant', 'dsa' ), 'description'=>__( 'Review posts, pages, products or media', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-seo-assistant' ), 'icon'=>'dashicons-search' ];
			if ( current_user_can( 'kiwe_manage_context' ) ) $actions[] = [ 'label'=>__( 'Business details', 'dsa' ), 'description'=>__( 'Update your website identity', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-identity' ), 'icon'=>'dashicons-store' ];
			if ( current_user_can( 'manage_categories' ) || current_user_can( 'edit_posts' ) ) $actions[] = [ 'label'=>__( 'Categories', 'dsa' ), 'description'=>__( 'Organize posts, products, services and menu categories', 'dsa' ), 'url'=>Category_Workspace_Service::url(), 'icon'=>'dashicons-category' ];
			if ( current_user_can( 'kiwe_manage_team' ) && \DSA\Onboarding\Design_Context_Journey_Service::feature_enabled( 'team' ) ) $actions[] = [ 'label'=>__( 'Team', 'dsa' ), 'description'=>__( 'Choose who appears on your site', 'dsa' ), 'url'=>Team_Workspace_Service::url(), 'icon'=>'dashicons-groups' ];
			if ( current_user_can( 'kiwe_view_pages' ) ) $actions[] = [ 'label'=>__( 'Pages', 'dsa' ), 'description'=>__( 'Change page names and manage page briefs', 'dsa' ), 'url'=>class_exists( Continuity_App_Service::class ) && Continuity_App_Service::enabled() ? Continuity_App_Service::url() : admin_url( 'edit.php?post_type=page' ), 'icon'=>'dashicons-admin-page' ];
			if ( current_user_can( 'kiwe_manage_guest_applications' ) ) $actions[] = [ 'label'=>__( 'Guests', 'dsa' ), 'description'=>__( 'Review applications and submissions', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-guests' ), 'icon'=>'dashicons-welcome-write-blog' ];
			if ( current_user_can( 'kiwe_use_notifications' ) ) $actions[] = [ 'label'=>__( 'Notifications', 'dsa' ), 'description'=>__( 'Choose your alerts', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-notifications' ), 'icon'=>'dashicons-bell' ];
			return $actions;
		}

		if ( current_user_can( 'upload_files' ) ) $actions[] = [ 'label'=>__( 'Media', 'dsa' ), 'description'=>__( 'Upload, edit or remove files you can manage', 'dsa' ), 'url'=>Media_App_Service::url(), 'icon'=>'dashicons-format-image' ];
		if ( \DSA\Apps\SEO_App_Service::enabled() && ( current_user_can( 'publish_posts' ) || current_user_can( 'edit_pages' ) || current_user_can( 'upload_files' ) ) ) $actions[] = [ 'label'=>__( 'SEO assistant', 'dsa' ), 'description'=>__( 'Review your content one item at a time', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-seo-assistant' ), 'icon'=>'dashicons-search' ];
		if ( current_user_can( 'kiwe_create_page_drafts' ) ) $actions[] = [ 'label'=>__( 'Add page', 'dsa' ), 'description'=>__( 'Create a page brief', 'dsa' ), 'url'=>Page_Workspace_Service::url(), 'icon'=>'dashicons-admin-page' ];
		if ( current_user_can( 'kiwe_use_notifications' ) ) $actions[] = [ 'label'=>__( 'Notifications', 'dsa' ), 'description'=>__( 'Choose your alerts', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-notifications' ), 'icon'=>'dashicons-bell' ];
		return $bounded ? array_slice( $actions, 0, 4 ) : $actions;
	}

	/** The new Home presents the same permitted destinations as the existing workspace. */
	public function task_catalog(): array {
		if ( ! self::uses_workspace( wp_get_current_user() ) || ! current_user_can( 'read' ) ) return [];
		// The legacy dashboard shortcut strip remains bounded. React Home is the
		// user's complete permitted workspace, so its catalog must not silently
		// hide later actions such as Notifications or Upload media.
		$role = self::uses_native_admin( wp_get_current_user() ) ? 'administrator' : WordPress_Role_Access_Service::role_for( wp_get_current_user() );
		$tasks = $this->quick_actions( $role, false );
		if ( self::uses_native_admin( wp_get_current_user() ) ) $tasks[] = [ 'label'=>__( 'WordPress dashboard', 'dsa' ), 'description'=>__( 'Technical settings and plugins', 'dsa' ), 'url'=>admin_url( 'index.php?kiwe-native-dashboard=1' ), 'icon'=>'dashicons-admin-tools' ];
		if ( class_exists( Post_Workspace_Service::class ) && Post_Workspace_Service::enabled() ) $tasks = array_values( array_filter( $tasks, static fn( $task ) => ! in_array( $task['label'], [ __( 'New post', 'dsa' ), __( 'Review submissions', 'dsa' ) ], true ) ) );
		// Identity's persistent subnavigation owns its related Design Context
		// areas. Home needs one clear entrance, not duplicate tiles for each tab.
		$context = []; $organize = []; $apps = []; $analytics = [];
		if ( Template_Workspace_Service::allowed() ) $tasks[] = [ 'label'=>__( 'Templates','dsa' ),'description'=>__( 'Headers, footers and native Bricks templates','dsa' ),'url'=>Template_Workspace_Service::url(),'icon'=>'dashicons-layout' ];
		if ( Analytics_Workspace_Service::allowed() ) $analytics[] = [ 'label'=>__( 'Analytics','dsa' ),'description'=>__( 'Understand your website and business results','dsa' ),'url'=>Analytics_Workspace_Service::url(),'icon'=>'dashicons-chart-area','group'=>'analytics','groupLabel'=>__( 'Analytics','dsa' ) ];
		if ( current_user_can( 'kiwe_manage_context' ) ) {
			$context = [
				[ 'label'=>__( 'Website & Design Context', 'dsa' ), 'description'=>__( 'Identity, story, contact, style and plan', 'dsa' ), 'url'=>\DSA\Onboarding\Design_Context_Journey_Service::url(), 'icon'=>'dashicons-id' ],
			];
		}
		if ( Backup_Workspace_Service::allowed() ) $organize[] = [ 'label'=>__( 'Backups', 'dsa' ), 'description'=>__( 'Save and review recovery copies', 'dsa' ), 'url'=>Backup_Workspace_Service::url(), 'icon'=>'dashicons-database-export' ];
		if ( current_user_can( 'kiwe_manage_navigation' ) && class_exists( Navigation_Workspace_Service::class ) && class_exists( Navigation_App_Service::class ) && Navigation_App_Service::enabled() ) {
			$organize[] = [ 'label'=>__( 'Navigation', 'dsa' ), 'description'=>__( 'Arrange visitor menu categories and links', 'dsa' ), 'url'=>Navigation_Workspace_Service::url(), 'icon'=>'dashicons-menu' ];
		}
		if ( current_user_can( 'kiwe_manage_guest_applications' ) && class_exists( Guest_App_Service::class ) && Guest_App_Service::enabled() ) {
			$context[] = [ 'label'=>__( 'Guests', 'dsa' ), 'description'=>__( 'Choose which guest contributions to accept', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-guests&area=setup' ), 'icon'=>'dashicons-admin-settings' ];
		}
		if ( current_user_can( 'kiwe_manage_team' ) && current_user_can( 'list_users' ) && current_user_can( 'edit_users' ) ) {
			$tasks[] = [ 'label'=>__( 'Users', 'dsa' ), 'description'=>__( 'Review new accounts, verification and recent activity', 'dsa' ), 'url'=>Team_Workspace_Service::url( [ 'team_view'=>'accounts' ] ), 'icon'=>'dashicons-admin-users' ];
		}
		if ( \DSA\Apps\Store_App_Service::allowed() || ( \DSA\Apps\Store_App_Service::enabled() && ( current_user_can( 'edit_products' ) || ( current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_others_shop_orders' ) ) ) ) ) {
			$apps[] = [ 'label'=>__( 'Store', 'dsa' ), 'description'=>__( 'Products, delivery, taxes, offers and orders', 'dsa' ), 'url'=>\DSA\Apps\Store_App_Service::home_url(), 'icon'=>'dashicons-store', 'app'=>\DSA\Apps\Store_App_Service::card() ];
		}

		// The direct identity tile above replaces the legacy catch-all action.
		$tasks = array_values( array_filter( $tasks, static fn( $task ) => false === strpos( (string) ( $task['url'] ?? '' ), 'page=kiwe-identity' ) ) );
		// A single capability-filtered task catalog feeds both tabs. Notifications
		// keep their bell entrance and never consume a Home tile.
		$tasks = array_values( array_filter( $tasks, static fn( $task ) => false === strpos( (string) ( $task['url'] ?? '' ), 'page=kiwe-notifications' ) ) );
		// App owns SEO activation; legacy shortcuts must not leave a second SEO entrance.
		$tasks = array_values( array_filter( $tasks, static fn( $task ) => false === strpos( (string) ( $task['url'] ?? '' ), 'page=kiwe-seo-assistant' ) ) );
		if ( \DSA\Apps\SEO_App_Service::allowed() || ( \DSA\Apps\SEO_App_Service::enabled() && ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' ) || current_user_can( 'edit_products' ) || current_user_can( 'upload_files' ) ) ) ) {
			$apps[] = [ 'label'=>__( 'SEO', 'dsa' ), 'description'=>__( 'Search details, sitemaps and SEO reviews', 'dsa' ), 'url'=>\DSA\Apps\SEO_App_Service::home_url(), 'icon'=>'dashicons-search', 'app'=>\DSA\Apps\SEO_App_Service::card() ];
		}
		foreach ( $tasks as $task ) if ( in_array( $task['label'], [ __( 'Categories', 'dsa' ), __( 'Product categories', 'dsa' ) ], true ) ) {
			$organize[] = [ ...$task, 'label'=>__( 'Categories', 'dsa' ) ];
			break;
		}
		$tasks = array_values( array_filter( $tasks, static fn( $task ) => ! in_array( $task['label'], [ __( 'Categories', 'dsa' ), __( 'Product categories', 'dsa' ) ], true ) ) );
		if ( class_exists( Taxonomy_App_Service::class ) && Taxonomy_App_Service::enabled() && ( new Taxonomy_App_Service() )->tag_definitions() ) {
			$organize[] = [ 'label'=>__( 'Tags', 'dsa' ), 'description'=>__( 'Manage post and product tags and their groups', 'dsa' ), 'url'=>admin_url( 'admin.php?page=' . Taxonomy_App_Service::TAGS_PAGE ), 'icon'=>'dashicons-tag' ];
		}
		if ( class_exists( Continuity_App_Service::class ) && Continuity_App_Service::enabled() && ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' ) || current_user_can( 'edit_products' ) ) ) {
			$organize[] = [ 'label'=>__( 'Revisions', 'dsa' ), 'description'=>__( 'Review saved changes and recovery copies', 'dsa' ), 'url'=>Continuity_App_Service::timeline_url(), 'icon'=>'dashicons-backup' ];
		}
		if ( class_exists( Trash_Workspace_Service::class ) && Trash_Workspace_Service::allowed() ) $organize[] = [ 'label'=>__( 'Trash', 'dsa' ),'description'=>__( 'Restore records or review permanent deletion', 'dsa' ),'url'=>Trash_Workspace_Service::url(),'icon'=>'dashicons-trash' ];
		foreach ( $tasks as &$task ) {
			if ( __( 'Guests', 'dsa' ) !== $task['label'] ) continue;
			if ( 'contributor' === $role ) {
				$task['label'] = __( 'My contributions', 'dsa' );
				$task['url'] = admin_url( 'admin.php?page=kiwe-guests&area=mine' );
			} else {
				$task['url'] = admin_url( 'admin.php?page=kiwe-guests&area=daily' );
			}
		}
		unset( $task );
		$work_labels = [ __( 'Pages', 'dsa' ), __( 'Templates','dsa' ), __( 'Posts', 'dsa' ), __( 'Products', 'dsa' ), __( 'Media', 'dsa' ), __( 'Services', 'dsa' ), __( 'Team', 'dsa' ), __( 'My contributions', 'dsa' ), __( 'New product', 'dsa' ), __( 'New post', 'dsa' ), __( 'Add page', 'dsa' ) ];
		$daily_labels = [ __( 'Orders', 'dsa' ), __( 'Saved carts', 'dsa' ), __( 'Forms', 'dsa' ), __( 'Users', 'dsa' ), __( 'Feedback', 'dsa' ), __( 'Guests', 'dsa' ), __( 'Campaigns', 'dsa' ) ];
		if(class_exists(App_Workspace_Service::class)&&App_Workspace_Service::enabled()&&App_Workspace_Service::allowed())$context[]=['label'=>__('App','dsa'),'description'=>__('Home, Dock, appearance, transitions and installation','dsa'),'url'=>App_Workspace_Service::url(),'icon'=>'dashicons-smartphone'];
		if(class_exists(App_Workspace_Service::class)&&App_Workspace_Service::enabled()&&App_Workspace_Service::audience_allowed()){$daily_labels[]=__('App','dsa');$tasks[]=['label'=>__('App','dsa'),'description'=>__('Review app activity and notification readiness','dsa'),'url'=>App_Workspace_Service::url('activity'),'icon'=>'dashicons-smartphone'];}
		if ( class_exists( Form_Workspace_Service::class ) && Form_Workspace_Service::allowed() ) $tasks[] = [ 'label'=>__( 'Forms', 'dsa' ), 'description'=>__( 'Read messages submitted through your website forms', 'dsa' ), 'url'=>Form_Workspace_Service::url(), 'icon'=>'dashicons-feedback' ];
		if ( \DSA\Apps\Store_App_Service::enabled() && function_exists( 'wc_get_products' ) && class_exists( Commerce_App_Service::class ) && Commerce_App_Service::enabled() && current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_others_shop_orders' ) ) $tasks[] = [ 'label'=>__( 'Saved carts', 'dsa' ), 'description'=>__( 'Review shoppers who left a cart waiting', 'dsa' ), 'url'=>Commerce_App_Service::url( 'saved-carts' ), 'icon'=>'dashicons-cart' ];
		if ( current_user_can('kiwe_manage_notification_policy') && class_exists(User_Group_Service::class) && class_exists(Notification_App_Service::class) && User_Group_Service::allowed() && Notification_App_Service::enabled() ) $tasks[]=[ 'label'=>__('Campaigns','dsa'),'description'=>__('Reach your opted-in audiences','dsa'),'url'=>admin_url('admin.php?page=kiwe-notifications&view=campaigns'),'icon'=>'dashicons-megaphone' ];
		foreach ( $tasks as &$task ) {
			$task['group'] = in_array( $task['label'], $daily_labels, true ) ? 'daily' : ( in_array( $task['label'], $work_labels, true ) ? 'work' : 'setup' );
			$task['groupLabel'] = 'work' === $task['group'] ? __( 'Work', 'dsa' ) : ( 'daily' === $task['group'] ? __( 'Daily', 'dsa' ) : __( 'Setup', 'dsa' ) );
		}
		unset( $task );
		foreach ( $context as &$task ) { $task['group'] = 'setup'; $task['groupLabel'] = __( 'Setup', 'dsa' ); }
		unset( $task );
		foreach ( $organize as &$task ) { $task['group'] = 'organize'; $task['groupLabel'] = __( 'Organize', 'dsa' ); }
		unset( $task );
		foreach ( $apps as &$task ) { $task['group'] = 'apps'; $task['groupLabel'] = __( 'App', 'dsa' ); }
		unset( $task );
		$work = array_values( array_filter( $tasks, static fn( $task ) => 'work' === $task['group'] ) );
		$daily = array_values( array_filter( $tasks, static fn( $task ) => 'daily' === $task['group'] ) );
		$work_order = array_flip( $work_labels ); $daily_order = array_flip( $daily_labels );
		usort( $work, static fn( $a, $b ) => ( $work_order[ $a['label'] ] ?? 99 ) <=> ( $work_order[ $b['label'] ] ?? 99 ) );
		usort( $daily, static fn( $a, $b ) => ( $daily_order[ $a['label'] ] ?? 99 ) <=> ( $daily_order[ $b['label'] ] ?? 99 ) );
		return array_values( array_merge( $work, $daily, $context, array_filter( $tasks, static fn( $task ) => 'setup' === $task['group'] ), $organize, $apps, $analytics ) );
	}

	private function day_part(): string {
		$hour = absint( wp_date( 'G' ) );
		if ( $hour < 12 ) return __( 'morning', 'dsa' );
		if ( $hour < 17 ) return __( 'afternoon', 'dsa' );
		return __( 'evening', 'dsa' );
	}

	private static function posting_url(): string {
		return class_exists( Post_Workspace_Service::class ) && Post_Workspace_Service::enabled() ? Post_Workspace_Service::url() : admin_url( 'edit.php' );
	}

	private static function posting_create_url(): string {
		return class_exists( Post_Workspace_Service::class ) && Post_Workspace_Service::enabled() ? add_query_arg( 'task', 'new', Post_Workspace_Service::url() ) : admin_url( 'post-new.php' );
	}

	private function role_label( string $role ): string {
		return match ( $role ) {
			'administrator' => __( 'Administrator workspace', 'dsa' ),
			'shop_manager'  => __( 'Shop manager workspace', 'dsa' ),
			'editor'        => __( 'Editor workspace', 'dsa' ),
			'author'        => __( 'Author workspace', 'dsa' ),
			'contributor'   => __( 'Guest workspace', 'dsa' ),
			default         => __( 'Workspace', 'dsa' ),
		};
	}
}
