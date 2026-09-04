<?php

namespace DSA\Access;

use DSA\PhoneKey\PhoneKey_Bridge;

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
	private const WORK_ROLES = [ 'administrator', 'editor', 'author', 'contributor', 'shop_manager' ];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu_labels' ], PHP_INT_MAX );
		add_action( 'wp_dashboard_setup', [ $this, 'dashboard' ], PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ], PHP_INT_MAX );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_filter( 'admin_footer_text', [ $this, 'footer' ], PHP_INT_MAX );
		add_filter( 'update_footer', [ $this, 'version_footer' ], PHP_INT_MAX );
		add_filter( 'screen_options_show_screen', [ $this, 'screen_options' ], PHP_INT_MAX, 2 );
		add_filter( 'get_user_option_screen_layout_dashboard', [ $this, 'dashboard_columns' ] );
	}

	private function active(): bool {
		if ( ! is_admin() || ! is_user_logged_in() || ! Site_Owner_Service::enabled() || Site_Owner_Service::is_owner() ) {
			return false;
		}

		return in_array( WordPress_Role_Access_Service::role_for( wp_get_current_user() ), self::WORK_ROLES, true );
	}

	public function menu_labels(): void {
		if ( ! $this->active() ) return;

		global $menu, $submenu;
		foreach ( (array) $menu as &$item ) {
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
		wp_add_dashboard_widget( 'kiwe-workspace-home', '', [ $this, 'render_dashboard' ] );
	}

	public function assets(): void {
		if ( ! $this->active() ) return;
		wp_enqueue_style( 'kiwe-workspace-admin', DSA_URL . 'assets/css/workspace-admin.css', [], DSA_VERSION );
	}

	public function body_class( string $classes ): string {
		if ( ! $this->active() ) return $classes;
		$role = sanitize_html_class( WordPress_Role_Access_Service::role_for( wp_get_current_user() ) );
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

		$user       = wp_get_current_user();
		$role       = WordPress_Role_Access_Service::role_for( $user );
		$post_count = wp_count_posts( 'post' );
		$drafts     = absint( $post_count->draft ?? 0 );
		$pending    = absint( $post_count->pending ?? 0 );
		$published  = absint( $post_count->publish ?? 0 );
		$comments   = wp_count_comments();
		$categories = get_terms( [ 'taxonomy'=>'category', 'hide_empty'=>false, 'number'=>4, 'orderby'=>'count', 'order'=>'DESC' ] );
		$categories = is_wp_error( $categories ) ? [] : $categories;
		$settings   = (array) get_option( DSA_OPTION_SETTINGS, [] );
		$email_test = (array) get_option( 'dsa_email_last_test', [] );
		$email_on   = ! empty( $settings['email']['enabled'] ) && ! empty( $email_test['success'] );
		$key_on     = ! empty( $settings['phonekey']['enabled'] ) && PhoneKey_Bridge::provider_ready();
		$tagline    = (string) get_bloginfo( 'description' );
		$site_name  = (string) get_bloginfo( 'name' );
		$first_name = $user->first_name ?: $user->display_name;
		$quick      = $this->quick_actions( $role );
		?>
		<div class="kiwe-workspace" data-kiwe-workspace>
			<header class="kiwe-workspace__hero">
				<div>
					<p class="kiwe-workspace__eyebrow"><?php echo esc_html( strtoupper( $site_name ) . ' ' . __( 'Workspace', 'dsa' ) ); ?></p>
					<h1><?php echo esc_html( sprintf( __( 'Good %1$s, %2$s', 'dsa' ), $this->day_part(), $first_name ) ); ?></h1>
					<p><?php esc_html_e( 'Everything you need to publish and manage the site.', 'dsa' ); ?></p>
				</div>
				<div class="kiwe-workspace__status" aria-label="<?php esc_attr_e( 'Service status', 'dsa' ); ?>">
					<?php $this->status( __( 'Site healthy', 'dsa' ), true ); ?>
					<?php $this->status( __( 'Email active', 'dsa' ), $email_on ); ?>
					<?php $this->status( __( 'Key.kiwe connected', 'dsa' ), $key_on ); ?>
				</div>
			</header>

			<section class="kiwe-workspace__panel kiwe-workspace__quick">
				<div class="kiwe-workspace__section-heading"><h2><?php esc_html_e( 'Quick actions', 'dsa' ); ?></h2><span><?php echo esc_html( $this->role_label( $role ) ); ?></span></div>
				<div class="kiwe-workspace__actions">
					<?php foreach ( $quick as $action ) : ?>
						<a href="<?php echo esc_url( $action['url'] ); ?>"><span class="dashicons <?php echo esc_attr( $action['icon'] ); ?>" aria-hidden="true"></span><strong><?php echo esc_html( $action['label'] ); ?></strong><small><?php echo esc_html( $action['description'] ); ?></small></a>
					<?php endforeach; ?>
				</div>
			</section>

			<div class="kiwe-workspace__grid">
				<section class="kiwe-workspace__panel">
					<div class="kiwe-workspace__section-heading"><h2><?php esc_html_e( 'Publishing', 'dsa' ); ?></h2><a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"><?php esc_html_e( 'View content', 'dsa' ); ?></a></div>
					<div class="kiwe-workspace__metrics">
						<div><strong><?php echo esc_html( (string) $drafts ); ?></strong><span><?php esc_html_e( 'Drafts', 'dsa' ); ?></span></div>
						<div><strong><?php echo esc_html( (string) $pending ); ?></strong><span><?php esc_html_e( 'Pending review', 'dsa' ); ?></span></div>
						<div><strong><?php echo esc_html( (string) $published ); ?></strong><span><?php esc_html_e( 'Published', 'dsa' ); ?></span></div>
					</div>
				</section>

				<section class="kiwe-workspace__panel">
					<div class="kiwe-workspace__section-heading"><h2><?php esc_html_e( 'Website identity', 'dsa' ); ?></h2><?php if ( current_user_can( 'kiwe_manage_context' ) ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-identity' ) ); ?>"><?php esc_html_e( 'Edit', 'dsa' ); ?></a><?php endif; ?></div>
					<div class="kiwe-workspace__identity">
						<div class="kiwe-workspace__site-mark"><?php echo get_custom_logo() ? wp_kses_post( get_custom_logo() ) : esc_html( substr( $site_name, 0, 1 ) ); ?></div>
						<div><strong><?php echo esc_html( $site_name ); ?></strong><span><?php echo esc_html( $tagline ?: __( 'Add a site tagline', 'dsa' ) ); ?></span></div>
					</div>
				</section>

				<?php if ( 'shop_manager' !== $role ) : ?>
				<section class="kiwe-workspace__panel">
					<div class="kiwe-workspace__section-heading"><h2><?php esc_html_e( 'Categories', 'dsa' ); ?></h2><?php if ( current_user_can( 'manage_categories' ) ) : ?><a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=category' ) ); ?>"><?php esc_html_e( 'Manage', 'dsa' ); ?></a><?php endif; ?></div>
					<ul class="kiwe-workspace__list">
						<?php if ( $categories ) : foreach ( $categories as $category ) : ?><li><span><?php echo esc_html( $category->name ); ?></span><strong><?php echo esc_html( sprintf( _n( '%s post', '%s posts', (int) $category->count, 'dsa' ), number_format_i18n( (int) $category->count ) ) ); ?></strong></li><?php endforeach; else : ?><li><span><?php esc_html_e( 'No categories yet', 'dsa' ); ?></span></li><?php endif; ?>
					</ul>
				</section>
				<?php endif; ?>

				<section class="kiwe-workspace__panel kiwe-workspace__protection">
					<div class="kiwe-workspace__section-heading"><h2><?php esc_html_e( 'SecureTrack', 'dsa' ); ?></h2></div>
					<div class="kiwe-workspace__protected"><span class="dashicons dashicons-shield" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Protected', 'dsa' ); ?></strong><span><?php esc_html_e( 'No action required', 'dsa' ); ?></span></div></div>
					<p><?php echo esc_html( sprintf( _n( '%s comment awaiting review.', '%s comments awaiting review.', absint( $comments->moderated ?? 0 ), 'dsa' ), number_format_i18n( absint( $comments->moderated ?? 0 ) ) ) ); ?></p>
				</section>
			</div>
		</div>
		<?php
	}

	private function quick_actions( string $role ): array {
		$actions = [];
		if ( 'contributor' === $role ) {
			return [
				[ 'label'=>__( 'Guest workspace', 'dsa' ), 'description'=>__( 'Prepare a contribution', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-guests' ), 'icon'=>'dashicons-welcome-write-blog' ],
				[ 'label'=>__( 'Notifications', 'dsa' ), 'description'=>__( 'Choose your alerts', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-notifications' ), 'icon'=>'dashicons-bell' ],
				[ 'label'=>__( 'Profile', 'dsa' ), 'description'=>__( 'Manage your account', 'dsa' ), 'url'=>admin_url( 'profile.php' ), 'icon'=>'dashicons-admin-users' ],
			];
		}
		if ( 'shop_manager' === $role ) {
			if ( current_user_can( 'create_products' ) ) $actions[] = [ 'label'=>__( 'New product', 'dsa' ), 'description'=>__( 'Create a product', 'dsa' ), 'url'=>admin_url( 'post-new.php?post_type=product' ), 'icon'=>'dashicons-products' ];
			$actions[] = [ 'label'=>__( 'Products', 'dsa' ), 'description'=>__( 'Manage the catalogue', 'dsa' ), 'url'=>admin_url( 'edit.php?post_type=product' ), 'icon'=>'dashicons-cart' ];
			$actions[] = [ 'label'=>__( 'Notifications', 'dsa' ), 'description'=>__( 'Choose your alerts', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-notifications' ), 'icon'=>'dashicons-bell' ];
			return $actions;
		}

		if ( current_user_can( 'create_posts' ) ) $actions[] = [ 'label'=>__( 'New story', 'dsa' ), 'description'=>__( 'Start a new article', 'dsa' ), 'url'=>admin_url( 'post-new.php' ), 'icon'=>'dashicons-welcome-write-blog' ];
		$actions[] = [ 'label'=>__( 'Posts', 'dsa' ), 'description'=>__( 'Review your content', 'dsa' ), 'url'=>admin_url( 'edit.php' ), 'icon'=>'dashicons-admin-post' ];
		if ( current_user_can( 'upload_files' ) ) $actions[] = [ 'label'=>__( 'Upload media', 'dsa' ), 'description'=>__( 'Add images or files', 'dsa' ), 'url'=>admin_url( 'media-new.php' ), 'icon'=>'dashicons-format-image' ];
		if ( current_user_can( 'kiwe_create_page_drafts' ) ) $actions[] = [ 'label'=>__( 'Add page', 'dsa' ), 'description'=>__( 'Create a page brief', 'dsa' ), 'url'=>Page_Workspace_Service::url(), 'icon'=>'dashicons-admin-page' ];
		if ( current_user_can( 'kiwe_use_notifications' ) ) $actions[] = [ 'label'=>__( 'Notifications', 'dsa' ), 'description'=>__( 'Choose your alerts', 'dsa' ), 'url'=>admin_url( 'admin.php?page=kiwe-notifications' ), 'icon'=>'dashicons-bell' ];
		return array_slice( $actions, 0, 4 );
	}

	private function status( string $label, bool $active ): void {
		echo '<span class="kiwe-workspace__status-pill' . ( $active ? ' is-active' : '' ) . '"><i aria-hidden="true"></i>' . esc_html( $label ) . '</span>';
	}

	private function day_part(): string {
		$hour = absint( wp_date( 'G' ) );
		if ( $hour < 12 ) return __( 'morning', 'dsa' );
		if ( $hour < 17 ) return __( 'afternoon', 'dsa' );
		return __( 'evening', 'dsa' );
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
