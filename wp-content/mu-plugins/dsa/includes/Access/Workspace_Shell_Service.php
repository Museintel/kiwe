<?php
namespace DSA\Access;

use DSA\Onboarding\Onboarding_Service;
use DSA\Site\Site_Identity_Service;
use DSA\Design\Seam_Token_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Opt-in presentation bridge. Existing registered page callbacks own all task forms. */
final class Workspace_Shell_Service {
	private static bool $rendering = false;
	public static function is_rendering(): bool { return self::$rendering; }

	public function register(): void {
		// No public hook, route, rewrite, database work or automatic rollout.
		if ( defined( 'KIWE_CLIENT_SHELL' ) && true === KIWE_CLIENT_SHELL ) add_action( 'admin_menu', [ $this, 'pages' ], PHP_INT_MAX );
	}

	public static function eligible( string $page ): bool {
		if ( ! defined( 'KIWE_CLIENT_SHELL' ) || true !== KIWE_CLIENT_SHELL || ! is_admin() || is_network_admin() || is_user_admin() || ! is_user_logged_in() || ! current_user_can( 'read' ) || ! Workspace_Admin_Service::uses_workspace( wp_get_current_user() ) ) return false;
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', [ 'GET', 'HEAD' ], true ) || 'admin.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) return false;
		if ( Workspace_Admin_Service::PAGE === $page ) return true;
		return current_user_can( 'kiwe_manage_context' ) && in_array( $page, array_merge( Onboarding_Service::section_slugs(), [ \DSA\Onboarding\Design_Context_Journey_Service::PAGE ] ), true );
	}

	public function pages(): void {
		$page = is_string( $_GET['page'] ?? null ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! self::eligible( $page ) ) return;
		$hook = get_plugin_page_hookname( $page, '' );
		// WordPress must already have registered the permitted page callback.
		if ( ! $hook || false === has_action( $hook ) ) return;
		add_action( 'load-' . $hook, function () use ( $page, $hook ): void {
			if ( ! self::eligible( $page ) ) return;
			$this->render( $page, $hook );
			exit;
		}, PHP_INT_MAX );
	}

	public function render( string $page, string $hook ): void {
		if ( ! self::eligible( $page ) ) wp_die( esc_html__( 'This workspace is unavailable for your account.', 'dsa' ), '', [ 'response'=>403 ] );
		self::$rendering = true;
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) return;
		$home = Workspace_Admin_Service::PAGE === $page;
		// Home is a task catalog, not a native form: do not run global admin asset loaders.
		if ( $home ) { Workspace_Notice_UI::enqueue(); wp_enqueue_style( 'dashicons' ); }
		else { do_action( 'admin_enqueue_scripts', $hook ); wp_enqueue_style( 'common' ); wp_enqueue_style( 'forms' ); wp_enqueue_style( 'buttons' ); wp_enqueue_style( 'dashicons' ); }
		wp_enqueue_style( 'kiwe-client-shell', DSA_URL . 'assets/css/workspace-shell.css', $home ? [ 'dashicons', 'kiwe-workspace-notices' ] : [ 'common', 'forms', 'buttons' ], DSA_VERSION );
		wp_enqueue_style( 'kiwe-context-journey', DSA_URL . 'assets/css/context-journey.css', [ 'kiwe-client-shell' ], DSA_VERSION );
		wp_add_inline_style( 'kiwe-client-shell', Seam_Token_Service::workspace_layout_stylesheet() );
		if ( $home ) wp_enqueue_style( 'kiwe-workspace-tiles', DSA_URL . 'assets/css/workspace-tiles.css', [ 'kiwe-client-shell' ], DSA_VERSION );
		wp_enqueue_script( 'kiwe-client-shell', DSA_URL . 'assets/js/workspace-shell.js', $home ? [ 'wp-element' ] : [], DSA_VERSION, true );
		$admin_notices = Workspace_Notice_UI::capture_admin_notices();
		$notice_count = Workspace_Notice_UI::notice_count( $admin_notices );
		$inbox_count = \DSA\Notifications\Admin_Event_Notification_Service::unread_count();
		$bell_count = $inbox_count;
		wp_localize_script( 'kiwe-client-shell', 'KIWE_CLIENT_SHELL_DATA', [
			'tasks'=>$home ? Workspace_Activity_Service::tasks( ( new Workspace_Admin_Service() )->task_catalog() ) : [],
			'activityEndpoint'=>rest_url( 'dsa/v1/workspace/activity' ), 'nonce'=>wp_create_nonce( 'wp_rest' ),
			'home'=>$home, 'greeting'=>__( 'Make yourself at home.', 'dsa' ),
			'intro'=>__( 'Your ideas. Your people. Your next big thing.', 'dsa' ),
			'eyebrow'=>__( 'A space for your business', 'dsa' ), 'tasksLabel'=>__( 'Business tasks', 'dsa' ),
			'viewerId'=>get_current_user_id(), 'noticeCount'=>$notice_count,'inboxUnreadCount'=>$inbox_count,
		] );
		$brand = Site_Identity_Service::workspace_brand();
		if ( current_user_can( 'kiwe_manage_context' ) ) $brand['identityUrl'] = \DSA\Onboarding\Design_Context_Journey_Service::url();
		$name = $brand['siteName']; $tagline = $brand['siteTagline']; $logo_url = $brand['siteLogo']; $identity_url = $brand['identityUrl'];
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html( $name ); ?></title>
		<script>var ajaxurl=<?php echo wp_json_encode( admin_url( 'admin-ajax.php', 'relative' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
		<?php wp_site_icon(); if ( $home ) { wp_print_styles( [ 'kiwe-workspace-tiles', 'kiwe-context-journey' ] ); } else { do_action( 'admin_print_styles-' . $hook ); do_action( 'admin_print_styles' ); do_action( 'admin_print_scripts-' . $hook ); do_action( 'admin_print_scripts' ); } ?>
		</head><body class="wp-core-ui kiwe-client-shell"><a class="kiwe-shell-skip" href="#kiwe-shell-main"><?php esc_html_e( 'Skip to workspace', 'dsa' ); ?></a>
		<div class="kiwe-context-chrome"><header class="kiwe-shell-header"><a class="kiwe-shell-wordmark" href="<?php echo esc_url( Workspace_Admin_Service::url() ); ?>" aria-label="<?php echo esc_attr( $name . ' home' ); ?>"><?php if ( $logo_url ) echo '<img src="' . esc_url( $logo_url ) . '" alt="" class="kiwe-shell-logo">'; else echo '<strong>' . esc_html( $name ) . '</strong>'; ?></a>
		<div class="kiwe-shell-business"><?php if ( '' !== trim( $tagline ) ) : ?><span class="kiwe-shell-tagline"><?php echo esc_html( $tagline ); ?></span><?php endif; ?><?php if ( $identity_url ) : ?><a class="kiwe-shell-identity-edit" href="<?php echo esc_url( $identity_url ); ?>" aria-label="<?php esc_attr_e( 'Edit website identity', 'dsa' ); ?>" title="<?php esc_attr_e( 'Edit website identity', 'dsa' ); ?>"><svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></a><?php endif; ?></div>
		<nav aria-label="<?php esc_attr_e( 'Account and website', 'dsa' ); ?>"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'View website', 'dsa' ); ?> ↗</a><?php if ( current_user_can( 'kiwe_use_notifications' ) ) : ?><a class="kiwe-shell-bell" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-notifications' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Notifications, %d new updates', 'dsa' ), $inbox_count ) ); ?>" title="<?php esc_attr_e( 'Notifications', 'dsa' ); ?>"><span class="dashicons dashicons-bell" aria-hidden="true"></span><?php if ( $bell_count ) : ?><span class="kiwe-shell-bell-count" aria-hidden="true"><?php echo esc_html( $bell_count ); ?></span><?php endif; ?></a><?php endif; ?><a class="kiwe-shell-account" href="<?php echo esc_url( home_url( '/?kiwe-screen=profile' ) ); ?>"><?php esc_html_e( 'Account', 'dsa' ); ?></a></nav></header><?php \DSA\Onboarding\Design_Context_Journey_Service::progress_markup(); ?></div>
		<main id="kiwe-shell-main" tabindex="-1"><?php if ( ! $home ) : ?><a class="kiwe-shell-back" href="<?php echo esc_url( Workspace_Admin_Service::url() ); ?>">← <?php esc_html_e( 'Home', 'dsa' ); ?></a><?php endif; ?>
		<?php if ( $home ) : ?><div id="kiwe-shell-home"><h1><?php esc_html_e( 'Your workspace', 'dsa' ); ?></h1><nav class="kiwe-shell-tiles" aria-label="<?php esc_attr_e( 'Business tasks', 'dsa' ); ?>"><?php foreach ( ( new Workspace_Admin_Service() )->task_catalog() as $task ) : if ( isset( $task['app'] ) ) { \DSA\Apps\App_Card_Service::render( $task ); continue; } ?><a class="kiwe-shell-tile" href="<?php echo esc_url( $task['url'] ); ?>"><strong><?php echo esc_html( $task['label'] ); ?></strong><span><?php echo esc_html( $task['description'] ); ?></span></a><?php endforeach; ?></nav></div><?php else : do_action( $hook ); endif; ?>
		</main><?php if ( $home ) { wp_print_scripts( [ 'wp-element', 'kiwe-client-shell' ] ); } else { do_action( 'admin_footer' ); do_action( 'admin_print_footer_scripts' ); do_action( 'admin_footer-' . $hook ); } ?></body></html><?php
	}
}
