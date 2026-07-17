<?php

namespace DSA\Admin;

use DSA\AI\Site_Graph_Service;
use DSA\AI\Binding_Plan_Validator;
use DSA\AI\Apply_Plan_Preparer;
use DSA\Commerce\Linked_Products_Service;
use DSA\Commerce\Store_Analytics_Service;
use DSA\Commerce\Abandoned_Cart_Service;
use DSA\Communications\Email_Service;
use DSA\Design\Seam_Token_Service;
use DSA\Diagnostics\Production_Readiness_Service;
use DSA\Modules\Module_Registry;
use DSA\Notifications\Notification_Campaign_Service;
use DSA\Notifications\Notification_Preference_Service;
use DSA\Security\Secret_Store;
use DSA\Saved\Saved_Items_Service;
use DSA\Search\Search_Service;
use DSA\Settings;
use DSA\WP7\Native_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {
	private const HTMX_VERSION = '2.0.10';
	private const ALPINE_VERSION = '3.15.12';

	private $settings;
	private $modules;
	private $native;
	private $readiness;
	private $store_analytics;
	private $linked_products;
	private $email;
	private $abandoned_carts;
	private $notification_preferences;
	private $notification_campaigns;
	private $saved_items;
	private ?Search_Service $search;

	public function __construct( Settings $settings, Module_Registry $modules, Native_Service $native, Production_Readiness_Service $readiness, ?Store_Analytics_Service $store_analytics = null, ?Linked_Products_Service $linked_products = null, ?Email_Service $email = null, ?Abandoned_Cart_Service $abandoned_carts = null, ?Notification_Preference_Service $notification_preferences = null, ?Notification_Campaign_Service $notification_campaigns = null, ?Saved_Items_Service $saved_items = null, ?Search_Service $search = null ) {
		$this->settings        = $settings;
		$this->modules         = $modules;
		$this->native          = $native;
		$this->readiness       = $readiness;
		$this->store_analytics = $store_analytics;
		$this->linked_products = $linked_products;
		$this->email            = $email;
		$this->abandoned_carts  = $abandoned_carts;
		$this->notification_preferences = $notification_preferences;
		$this->notification_campaigns = $notification_campaigns;
		$this->saved_items = $saved_items;
		$this->search = $search;
	}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'redirect_legacy_tokens_page' ] );
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_dsa_save_settings', [ $this, 'save_settings' ] );
		add_action( 'admin_post_dsa_export_profile', [ $this, 'export_profile' ] );
		add_action( 'admin_post_dsa_import_profile', [ $this, 'import_profile' ] );
		add_action( 'admin_post_dsa_linked_bulk_cross_sells', [ $this, 'handle_linked_bulk_cross_sells' ] );
		add_action( 'admin_post_dsa_linked_clear_cross_sells', [ $this, 'handle_linked_clear_cross_sells' ] );
		add_action( 'admin_post_dsa_linked_rerun_mapping', [ $this, 'handle_linked_rerun_mapping' ] );
		add_action( 'admin_post_dsa_linked_delete_mapping', [ $this, 'handle_linked_delete_mapping' ] );
		add_action( 'admin_post_dsa_linked_bulk_upsells', [ $this, 'handle_linked_bulk_upsells' ] );
		add_action( 'admin_post_dsa_linked_sync_co_purchase', [ $this, 'handle_linked_sync_co_purchase' ] );
		add_action( 'admin_post_dsa_store_analytics_purge', [ $this, 'handle_store_analytics_purge' ] );
		add_action( 'admin_post_dsa_email_test', [ $this, 'handle_email_test' ] );
		add_action( 'admin_post_dsa_abandoned_cart_reminder', [ $this, 'handle_abandoned_cart_reminder' ] );
		add_action( 'admin_post_dsa_send_notification_campaign', [ $this, 'handle_notification_campaign' ] );
		add_action( 'admin_post_dsa_export_bricks_tokens', [ $this, 'export_bricks_tokens' ] );
		add_action( 'admin_post_dsa_apply_bricks_tokens', [ $this, 'apply_bricks_tokens' ] );
		add_action( 'admin_post_dsa_export_site_graph', [ $this, 'export_site_graph' ] );
		add_action( 'admin_post_dsa_validate_binding_plan', [ $this, 'validate_binding_plan' ] );
		add_action( 'admin_post_dsa_download_apply_plan', [ $this, 'download_apply_plan' ] );
		add_action( 'admin_post_dsa_clear_search_cache', [ $this, 'clear_search_cache' ] );
		add_action( 'admin_post_dsa_save_menu_settings', [ $this, 'save_menu_settings' ] );
		add_action( 'admin_post_dsa_save_dock_settings', [ $this, 'save_dock_settings' ] );
		add_action( 'admin_post_dsa_developer_clear_runtime', [ $this, 'handle_developer_clear_runtime' ] );
		add_action( 'admin_post_dsa_developer_reset_settings', [ $this, 'handle_developer_reset_settings' ] );
		add_action( 'wp_ajax_dsa_search_menu_targets', [ $this, 'search_menu_targets' ] );
		add_action( 'wp_ajax_dsa_developer_package_proof', [ $this, 'ajax_developer_package_proof' ] );
	}

	public function menu(): void {
		add_menu_page(
			__( 'Kiwe App', 'dsa' ),
			__( 'Kiwe', 'dsa' ),
			'manage_options',
			'kiwe',
			[ $this, 'render_app_page' ],
			'dashicons-screenoptions',
			58
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Auth', 'dsa' ),
			__( 'Auth', 'dsa' ),
			$this->auth_capability(),
			'kiwe-auth',
			[ $this, 'render_auth_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe App', 'dsa' ),
			__( 'App', 'dsa' ),
			'manage_options',
			'kiwe-app',
			[ $this, 'render_app_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Haptic', 'dsa' ),
			__( 'Haptic', 'dsa' ),
			'manage_options',
			'kiwe-haptic',
			[ $this, 'render_haptic_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Framework', 'dsa' ),
			__( 'Framework', 'dsa' ),
			'manage_options',
			'kiwe-framework',
			[ $this, 'render_framework_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Developer', 'dsa' ),
			__( 'Developer', 'dsa' ),
			'manage_options',
			'kiwe-developer',
			[ $this, 'render_developer_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Dock', 'dsa' ),
			__( 'Dock', 'dsa' ),
			'manage_options',
			'kiwe-dock',
			[ $this, 'render_dock_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Theme', 'dsa' ),
			__( 'Theme', 'dsa' ),
			'manage_options',
			'kiwe-theme',
			[ $this, 'render_theme_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Games', 'dsa' ),
			__( 'Games', 'dsa' ),
			'manage_options',
			'kiwe-games',
			[ $this, 'render_games_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Links', 'dsa' ),
			__( 'Links', 'dsa' ),
			'manage_options',
			'kiwe-links',
			[ $this, 'render_links_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Search', 'dsa' ),
			__( 'Search', 'dsa' ),
			'manage_options',
			'kiwe-search',
			[ $this, 'render_search_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Menu', 'dsa' ),
			__( 'Menu', 'dsa' ),
			'manage_options',
			'kiwe-menu',
			[ $this, 'render_menu_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Secure', 'dsa' ),
			__( 'Secure', 'dsa' ),
			'manage_options',
			'kiwe-secure',
			[ $this, 'render_secure_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Email', 'dsa' ),
			__( 'Email', 'dsa' ),
			'manage_options',
			'kiwe-email',
			[ $this, 'render_email_page' ]
		);

		add_submenu_page(
			'kiwe',
			__( 'Kiwe Analytics', 'dsa' ),
			__( 'Analytics', 'dsa' ),
			'manage_options',
			'kiwe-analytics',
			[ $this, 'render_store_analytics_page' ]
		);

		if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) {
			add_submenu_page(
				'kiwe',
				__( 'Kiwe WooCommerce', 'dsa' ),
				__( 'WooCommerce', 'dsa' ),
				'manage_options',
				'kiwe-woocommerce',
				[ $this, 'render_woocommerce_page' ]
			);

			add_submenu_page(
				'kiwe',
				__( 'Kiwe Abandoned Cart', 'dsa' ),
				__( 'Abandoned Cart', 'dsa' ),
				'manage_options',
				'kiwe-abandoned-cart',
				[ $this, 'render_abandoned_cart_page' ]
			);
		}

		if ( function_exists( 'bricks_is_builder' ) || class_exists( '\Bricks\Woocommerce_Mini_Cart' ) || defined( 'BRICKS_VERSION' ) ) {
			add_submenu_page(
				'kiwe',
				__( 'Kiwe Bricks', 'dsa' ),
				__( 'Bricks', 'dsa' ),
				'manage_options',
				'kiwe-bricks',
				[ $this, 'render_bricks_page' ]
			);
		}

		remove_submenu_page( 'kiwe', 'kiwe' );
	}

	public function redirect_legacy_tokens_page(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		if ( 'kiwe-tokens' !== $page ) {
			return;
		}

		$args = [ 'page' => 'kiwe-framework' ];
		foreach ( [ 'settings-updated', 'tokens-exported' ] as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_key( (string) wp_unslash( $_GET[ $key ] ) );
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'toplevel_page_kiwe', 'kiwe_page_kiwe-auth', 'kiwe_page_kiwe-app', 'kiwe_page_kiwe-framework', 'kiwe_page_kiwe-tokens', 'kiwe_page_kiwe-developer', 'kiwe_page_kiwe-dock', 'kiwe_page_kiwe-theme', 'kiwe_page_kiwe-games', 'kiwe_page_kiwe-links', 'kiwe_page_kiwe-search', 'kiwe_page_kiwe-menu', 'kiwe_page_kiwe-secure', 'kiwe_page_kiwe-email', 'kiwe_page_kiwe-woocommerce', 'kiwe_page_kiwe-analytics', 'kiwe_page_kiwe-abandoned-cart', 'kiwe_page_kiwe-bricks' ], true ) ) {
			return;
		}

		wp_enqueue_style( 'dsa-admin', DSA_URL . 'assets/css/admin.css', [], DSA_VERSION );
		wp_enqueue_script( 'dsa-admin', DSA_URL . 'assets/js/admin.js', [], DSA_VERSION, true );
		$enhancements = wp_parse_args( $this->settings->get( 'enhancements', [] ), $this->settings->defaults()['enhancements'] );
		if ( 'kiwe_page_kiwe-developer' === $hook && ! empty( $enhancements['enabled'] ) && ! empty( $enhancements['htmx'] ) ) {
			wp_enqueue_script(
				'dsa-htmx',
				DSA_URL . 'assets/vendor/htmx/htmx.min.js',
				[],
				self::HTMX_VERSION,
				true
			);
		}
		if ( 'kiwe_page_kiwe-developer' === $hook && ! empty( $enhancements['enabled'] ) && ! empty( $enhancements['alpine'] ) ) {
			wp_enqueue_script(
				'dsa-alpine',
				DSA_URL . 'assets/vendor/alpine/alpine.min.js',
				[],
				self::ALPINE_VERSION,
				true
			);
			wp_script_add_data( 'dsa-alpine', 'defer', true );
		}
		wp_localize_script(
			'dsa-admin',
			'DSA_ADMIN_DATA',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'restUrl' => esc_url_raw( rest_url( 'dsa/v1' ) ),
				'nonce'   => wp_create_nonce( 'dsa_admin_search' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	public function handle_linked_bulk_cross_sells(): void {
		$this->assert_store_action( 'dsa_linked_bulk_cross_sells' );
		$service = $this->linked_products ?: new Linked_Products_Service( $this->settings );
		$result = $service->run_bulk_cross_sells(
			[
				'source_type'  => sanitize_key( wp_unslash( $_POST['source_type'] ?? 'all' ) ),
				'source_cat'   => absint( $_POST['source_cat'] ?? 0 ),
				'target_cats'  => isset( $_POST['target_cats'] ) && is_array( $_POST['target_cats'] ) ? array_map( 'absint', wp_unslash( $_POST['target_cats'] ) ) : [],
				'own_category' => ! empty( $_POST['own_category'] ),
				'mode'         => sanitize_key( wp_unslash( $_POST['mode'] ?? 'merge' ) ),
			]
		);
		$this->redirect_store_manager( 'assign', [ 'ran' => 'cross_sells', 'updated' => (int) $result['updated'], 'products' => (int) $result['products'] ] );
	}

	public function handle_linked_clear_cross_sells(): void {
		$this->assert_store_action( 'dsa_linked_clear_cross_sells' );
		$service = $this->linked_products ?: new Linked_Products_Service( $this->settings );
		$result = $service->clear_cross_sells(
			[
				'source_type' => sanitize_key( wp_unslash( $_POST['source_type'] ?? 'all' ) ),
				'source_cat'  => absint( $_POST['source_cat'] ?? 0 ),
			]
		);
		$this->redirect_store_manager( 'clear', [ 'ran' => 'clear_cross_sells', 'updated' => (int) $result['updated'], 'products' => (int) $result['products'] ] );
	}

	public function handle_linked_rerun_mapping(): void {
		$this->assert_store_action( 'dsa_linked_rerun_mapping' );
		$service = $this->linked_products ?: new Linked_Products_Service( $this->settings );
		$result = $service->rerun_saved_cross_sell_mapping( absint( $_GET['mapping'] ?? 0 ) );
		$this->redirect_store_manager( 'memory', [ 'ran' => 'rerun_mapping', 'updated' => (int) $result['updated'], 'products' => (int) $result['products'] ] );
	}

	public function handle_linked_delete_mapping(): void {
		$this->assert_store_action( 'dsa_linked_delete_mapping' );
		$service = $this->linked_products ?: new Linked_Products_Service( $this->settings );
		$service->delete_saved_cross_sell_mapping( absint( $_GET['mapping'] ?? 0 ) );
		$this->redirect_store_manager( 'memory', [ 'deleted' => 1 ] );
	}

	public function handle_linked_bulk_upsells(): void {
		$this->assert_store_action( 'dsa_linked_bulk_upsells' );
		$service = $this->linked_products ?: new Linked_Products_Service( $this->settings );
		$result = $service->run_bulk_co_purchase_upsells(
			[
				'source_type' => sanitize_key( wp_unslash( $_POST['source_type'] ?? 'all' ) ),
				'source_cat'  => absint( $_POST['source_cat'] ?? 0 ),
				'depth'       => absint( $_POST['depth'] ?? 5 ),
				'mode'        => sanitize_key( wp_unslash( $_POST['mode'] ?? 'merge' ) ),
			]
		);
		$this->redirect_store_manager( 'bulk-upsells', [ 'ran' => 'bulk_upsells', 'updated' => (int) $result['updated'], 'products' => (int) $result['products'] ] );
	}

	public function handle_linked_sync_co_purchase(): void {
		$this->assert_store_action( 'dsa_linked_sync_co_purchase' );
		$service = $this->linked_products ?: new Linked_Products_Service( $this->settings );
		$settings = $this->settings->all();
		$commerce = is_array( $settings['commerce'] ?? null ) ? $settings['commerce'] : [];
		$result = $service->run_bulk_co_purchase_upsells(
			[
				'source_type' => 'all',
				'depth'       => absint( $commerce['co_purchase_daily_sync_depth'] ?? 5 ),
				'mode'        => sanitize_key( $commerce['co_purchase_daily_sync_mode'] ?? 'merge' ),
			]
		);
		update_option( 'dsa_co_purchase_last_sync', current_time( 'mysql' ), false );
		$this->redirect_store_manager( 'bulk-upsells', [ 'ran' => 'co_purchase_sync', 'updated' => (int) $result['updated'], 'products' => (int) $result['products'] ] );
	}

	public function handle_store_analytics_purge(): void {
		$this->assert_store_action( 'dsa_store_analytics_purge' );
		$service = $this->store_analytics ?: new Store_Analytics_Service( $this->settings );
		$deleted = $service->purge_events_older_than( absint( $_POST['days'] ?? 30 ) );
		$this->redirect_store_manager( 'cart-events', [ 'purged' => $deleted ] );
	}

	public function handle_email_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dsa' ) );
		}

		check_admin_referer( 'dsa_email_test' );
		$recipient = sanitize_email( wp_unslash( $_POST['recipient'] ?? '' ) );
		$result = $this->email
			? $this->email->send( $recipient, sprintf( __( 'Kiwe email test from %s', 'dsa' ), get_bloginfo( 'name' ) ), __( 'Kiwe successfully reached the WordPress mail transport. Confirm receipt and spam placement before enabling customer reminders.', 'dsa' ) )
			: new \WP_Error( 'dsa_email_missing', __( 'Kiwe Email is unavailable.', 'dsa' ) );
		$sent = ! is_wp_error( $result );
		$message = $sent ? __( 'Test email handed to the configured transport.', 'dsa' ) : $result->get_error_message();

		update_option( 'dsa_email_last_test', [ 'recipient_hash' => hash_hmac( 'sha256', strtolower( $recipient ), wp_salt( 'auth' ) ), 'success' => $sent, 'message' => $message, 'time' => current_time( 'mysql' ) ], false );
		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-email', 'tab' => 'diagnostics', 'email-test' => $sent ? 'sent' : 'failed', 'message' => $message ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_abandoned_cart_reminder(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dsa' ) );
		}

		$cart_id = absint( $_POST['cart_id'] ?? 0 );
		check_admin_referer( 'dsa_abandoned_cart_reminder_' . $cart_id );
		$channel = sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) );
		$result = $this->abandoned_carts
			? $this->abandoned_carts->send_reminder( $cart_id, $channel )
			: new \WP_Error( 'dsa_abandoned_missing', __( 'The abandoned-cart service is unavailable.', 'dsa' ) );
		$sent = ! is_wp_error( $result );
		$message = $sent ? sprintf( __( '%s reminder sent.', 'dsa' ), ucfirst( $channel ) ) : $result->get_error_message();
		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-abandoned-cart', 'tab' => 'reminders', 'reminder' => $sent ? 'sent' : 'failed', 'message' => $message ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page(): void {
		$settings = $this->settings->all();
		$dock_module_labels = $this->dock_module_labels();
		$native   = $this->native->summary();
		$visual   = wp_parse_args( $settings['visual_effects'] ?? [], $this->settings->all()['visual_effects'] );
		$dock     = wp_parse_args( $settings['dock'] ?? [], $this->settings->all()['dock'] );
		$link_hub = wp_parse_args( $settings['link_hub'] ?? [], $this->settings->all()['link_hub'] );
		$theme    = wp_parse_args( $settings['dsa_theme'] ?? [], $this->settings->all()['dsa_theme'] );
		$games    = wp_parse_args( $settings['games'] ?? [], $this->settings->all()['games'] );
		$schema_geo = wp_parse_args( $settings['schema_geo'] ?? [], $this->settings->all()['schema_geo'] );
		$metrics = wp_parse_args( $settings['metrics'] ?? [], $this->settings->all()['metrics'] );
		$permissions = wp_parse_args( $settings['permissions'] ?? [], $this->settings->all()['permissions'] );
		$diagnostics = wp_parse_args( $settings['diagnostics'] ?? [], $this->settings->all()['diagnostics'] );
		$readiness = $this->readiness->report();
		$readiness_label = ! empty( $readiness['counts']['critical'] )
			? __( 'Critical blockers', 'dsa' )
			: ( $readiness['ready'] ? __( 'Controlled launch ready', 'dsa' ) : __( 'Production proof required', 'dsa' ) );
		$link_categories = get_categories(
			[
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);
		$dock_enabled = array_replace(
			array_fill_keys( array_keys( $dock_module_labels ), true ),
			isset( $dock['enabled_items'] ) && is_array( $dock['enabled_items'] ) ? $dock['enabled_items'] : []
		);
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe Surface', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'The Kiwe Surface shell owns the persistent Appsite layer. Presentation, dock, app, search, menu, commerce, and developer gates are managed in their dedicated sections.', 'dsa' ); ?></p>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Surface settings saved.', 'dsa' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['profile-imported'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Appsite profile imported and sanitized.', 'dsa' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['profile-error'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $this->profile_error_message( sanitize_key( wp_unslash( $_GET['profile-error'] ) ) ) ); ?></p>
				</div>
			<?php endif; ?>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Current Build', 'dsa' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Plugin Version', 'dsa' ); ?></th>
							<td><?php echo esc_html( DSA_VERSION ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Surface Enabled', 'dsa' ); ?></th>
							<td><?php echo ! empty( $settings['enabled'] ) ? esc_html__( 'Yes', 'dsa' ) : esc_html__( 'No', 'dsa' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Route Continuity', 'dsa' ); ?></th>
							<td><?php esc_html_e( 'Full-document navigation with controlled transition Surface. Experimental fragment/morph renderers are Developer-gated.', 'dsa' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Registered Modules', 'dsa' ); ?></th>
							<td><?php echo esc_html( implode( ', ', array_keys( $this->modules->all() ) ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Native Adapters', 'dsa' ); ?></th>
							<td><?php echo esc_html( implode( ', ', $native['available'] ?: [ __( 'Fallback mode', 'dsa' ) ] ) ); ?></td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Production Readiness', 'dsa' ); ?></h2>
				<div class="dsa-readiness-summary dsa-readiness-summary--<?php echo $readiness['ready'] ? 'ready' : 'blocked'; ?>">
					<div>
						<strong><?php echo esc_html( $readiness_label ); ?></strong>
						<p><?php echo esc_html( $readiness['summary'] ); ?></p>
					</div>
					<div class="dsa-readiness-score">
						<span><?php echo esc_html( (string) $readiness['score'] ); ?></span>
						<small><?php esc_html_e( 'readiness', 'dsa' ); ?></small>
					</div>
				</div>
				<div class="dsa-readiness-counts">
					<span class="dsa-readiness-count dsa-readiness-count--critical"><?php echo esc_html( (string) $readiness['counts']['critical'] ); ?> <?php esc_html_e( 'critical', 'dsa' ); ?></span>
					<span class="dsa-readiness-count dsa-readiness-count--warning"><?php echo esc_html( (string) $readiness['counts']['warning'] ); ?> <?php esc_html_e( 'warnings', 'dsa' ); ?></span>
					<span class="dsa-readiness-count dsa-readiness-count--pass"><?php echo esc_html( (string) $readiness['counts']['pass'] ); ?> <?php esc_html_e( 'passing', 'dsa' ); ?></span>
				</div>
				<table class="widefat striped dsa-readiness-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Check', 'dsa' ); ?></th>
							<th><?php esc_html_e( 'Status', 'dsa' ); ?></th>
							<th><?php esc_html_e( 'What DSA Sees', 'dsa' ); ?></th>
							<th><?php esc_html_e( 'Production Action', 'dsa' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $readiness['checks'] as $check ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $check['label'] ); ?></strong><br><small><code><?php echo esc_html( $check['group'] . ':' . $check['id'] ); ?></code></small></td>
								<td><span class="dsa-readiness-status dsa-readiness-status--<?php echo esc_attr( $check['status'] ); ?>"><?php echo esc_html( $check['status'] ); ?></span></td>
								<td><?php echo esc_html( $check['detail'] ); ?></td>
								<td><?php echo esc_html( $check['action'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php echo esc_html( sprintf( __( 'Generated %s. Readiness is shown only inside this admin screen.', 'dsa' ), $readiness['generated'] ) ); ?></p>
			</section>

			<?php $apex = ( new \DSA\Diagnostics\Apex_Acceptance_Service( $this->settings ) )->report(); ?>
			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'APEX Acceptance Profile', 'dsa' ); ?></h2>
				<p><strong><?php esc_html_e( 'Architecture: complete.', 'dsa' ); ?></strong> <?php esc_html_e( 'Broad production certification remains blocked by the live matrices listed below.', 'dsa' ); ?></p>
				<p><code><?php echo esc_html( rest_url( 'dsa/v1/apex-profile' ) ); ?></code></p>
				<div class="dsa-lpm-summary">
					<div class="dsa-lpm-stat"><span><?php esc_html_e( 'Packaged CSS', 'dsa' ); ?></span><strong><?php echo esc_html( (string) $apex['assetBudgetEvidence']['packagedCssBytes'] ); ?></strong><small><?php esc_html_e( 'bytes', 'dsa' ); ?></small></div>
					<div class="dsa-lpm-stat"><span><?php esc_html_e( 'Packaged JS', 'dsa' ); ?></span><strong><?php echo esc_html( (string) $apex['assetBudgetEvidence']['packagedJsBytes'] ); ?></strong><small><?php esc_html_e( 'bytes', 'dsa' ); ?></small></div>
					<div class="dsa-lpm-stat"><span><?php esc_html_e( 'HTML edge policy', 'dsa' ); ?></span><strong><?php esc_html_e( 'Origin', 'dsa' ); ?></strong><small><?php esc_html_e( 'until public/private shell split', 'dsa' ); ?></small></div>
				</div>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Matrix', 'dsa' ); ?></th><th><?php esc_html_e( 'Code', 'dsa' ); ?></th><th><?php esc_html_e( 'Proof', 'dsa' ); ?></th><th><?php esc_html_e( 'Still Required', 'dsa' ); ?></th></tr></thead>
					<tbody><?php foreach ( $apex['matrix'] as $matrix ) : ?><tr>
						<td><strong><?php echo esc_html( $matrix['label'] ); ?></strong></td>
						<td><?php echo esc_html( $matrix['code'] ); ?></td>
						<td><?php echo esc_html( $matrix['proof'] ); ?></td>
						<td><?php echo esc_html( $matrix['remaining'] ); ?></td>
					</tr><?php endforeach; ?></tbody>
				</table>
				<p class="description"><?php esc_html_e( 'X-Kiwe-Runtime-Profile, X-Kiwe-Document-Profile, and X-Kiwe-Edge-Policy classify frontend responses without granting an edge permission to cache personalized HTML.', 'dsa' ); ?></p>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'WP Native Adapter Layer', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Feature-detected WordPress native APIs. DSA uses these as adapters only; the Surface shell keeps its REST/PHP fallback when they are missing.', 'dsa' ); ?></p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Adapter', 'dsa' ); ?></th>
							<th><?php esc_html_e( 'Status', 'dsa' ); ?></th>
							<th><?php esc_html_e( 'Fallback Contract', 'dsa' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $native['features'] as $feature ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $feature['label'] ); ?></strong><br><small><code><?php echo esc_html( $feature['id'] ); ?></code></small></td>
								<td><?php echo ! empty( $feature['available'] ) ? esc_html__( 'Available', 'dsa' ) : esc_html__( 'Fallback', 'dsa' ); ?></td>
								<td><?php echo esc_html( $feature['fallback'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Appsite Profiles', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Export or import the DSA Appsite configuration as a portable JSON profile. This is the profile contract before any future marketplace layer.', 'dsa' ); ?></p>
				<div class="dsa-admin-profile-grid">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dsa-admin-profile-card">
						<input type="hidden" name="action" value="dsa_export_profile">
						<?php wp_nonce_field( 'dsa_export_profile' ); ?>
						<h3><?php esc_html_e( 'Export Profile', 'dsa' ); ?></h3>
						<p><?php esc_html_e( 'Downloads current Surface, App, Dock, Links, Game, Theme, and Schema/GEO settings. No users, orders, tokens, logs, or secrets are exported.', 'dsa' ); ?></p>
						<?php submit_button( __( 'Download JSON Profile', 'dsa' ), 'secondary', 'submit', false ); ?>
					</form>

					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dsa-admin-profile-card">
						<input type="hidden" name="action" value="dsa_import_profile">
						<?php wp_nonce_field( 'dsa_import_profile' ); ?>
						<h3><?php esc_html_e( 'Import Profile', 'dsa' ); ?></h3>
						<p><?php esc_html_e( 'Imports only recognized DSA profile settings and sanitizes every field before saving. Unknown keys are ignored.', 'dsa' ); ?></p>
						<input type="file" name="dsa_profile_file" accept="application/json,.json" required>
						<?php submit_button( __( 'Import JSON Profile', 'dsa' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Interstice Metrics', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Privacy-light aggregate proof for the between-pages surface. No visitor URLs, IP addresses, names, phones, emails, or personal timelines are stored in this v1 layer.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>
					<div class="dsa-admin-metrics-settings">
						<label><input type="checkbox" name="metrics[enabled]" value="1" <?php checked( ! empty( $metrics['enabled'] ) ); ?>> <?php esc_html_e( 'Collect aggregate DSA metrics', 'dsa' ); ?></label>
						<label><span><?php esc_html_e( 'Retention days', 'dsa' ); ?></span><input class="small-text" type="number" min="1" max="90" name="metrics[retention_days]" value="<?php echo esc_attr( (string) ( $metrics['retention_days'] ?? 14 ) ); ?>"></label>
						<?php submit_button( __( 'Save Metrics', 'dsa' ), 'secondary', 'submit', false ); ?>
					</div>
				</form>
				<div class="dsa-admin-metrics" data-dsa-metrics-summary>
					<p><?php esc_html_e( 'Loading metrics summary...', 'dsa' ); ?></p>
				</div>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Permission Journey', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Earned permission asks for PWA install and future browser permissions. Kiwe asks after trust moments, never during protected checkout/account flows, and never repeatedly in one session.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Journey Guard', 'dsa' ); ?></th>
								<td class="dsa-admin-permission-grid">
									<label><input type="checkbox" name="permissions[enabled]" value="1" <?php checked( ! empty( $permissions['enabled'] ) ); ?>> <?php esc_html_e( 'Enable Permission Journey Manager', 'dsa' ); ?></label>
									<label><span><?php esc_html_e( 'Cooldown hours', 'dsa' ); ?></span><input type="number" min="1" max="720" name="permissions[cooldown_hours]" value="<?php echo esc_attr( (string) ( $permissions['cooldown_hours'] ?? 24 ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Retention days', 'dsa' ); ?></span><input type="number" min="1" max="90" name="permissions[retention_days]" value="<?php echo esc_attr( (string) ( $permissions['retention_days'] ?? 30 ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Asks per session', 'dsa' ); ?></span><input type="number" min="1" max="5" name="permissions[max_asks_per_session]" value="<?php echo esc_attr( (string) ( $permissions['max_asks_per_session'] ?? 1 ) ); ?>"></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'PWA Install Ask', 'dsa' ); ?></th>
								<td class="dsa-admin-permission-grid">
									<label><input type="checkbox" name="permissions[pwa_enabled]" value="1" <?php checked( ! empty( $permissions['pwa_enabled'] ) ); ?>> <?php esc_html_e( 'Gate app install through earned trust', 'dsa' ); ?></label>
									<label><input type="checkbox" name="permissions[offline_editorial_enabled]" value="1" <?php checked( ! empty( $permissions['offline_editorial_enabled'] ) ); ?>> <?php esc_html_e( 'Cache proven public WordPress posts and pages for offline reading', 'dsa' ); ?></label>
									<p class="description"><?php esc_html_e( 'Off by default for the S17 pilot. Bricks, WooCommerce, account, checkout, forms, shortcodes, and personalized documents remain network-only.', 'dsa' ); ?></p>
									<label><span><?php esc_html_e( 'Title', 'dsa' ); ?></span><input type="text" name="permissions[pwa_title]" value="<?php echo esc_attr( (string) ( $permissions['pwa_title'] ?? 'Install this appsite?' ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Message', 'dsa' ); ?></span><input type="text" name="permissions[pwa_message]" value="<?php echo esc_attr( (string) ( $permissions['pwa_message'] ?? 'Kiwe will open your browser install prompt now.' ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Home views', 'dsa' ); ?></span><input type="number" min="0" max="20" name="permissions[pwa_min_home_views]" value="<?php echo esc_attr( (string) ( $permissions['pwa_min_home_views'] ?? 1 ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Dock opens', 'dsa' ); ?></span><input type="number" min="0" max="20" name="permissions[pwa_min_dock_opens]" value="<?php echo esc_attr( (string) ( $permissions['pwa_min_dock_opens'] ?? 1 ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Transitions', 'dsa' ); ?></span><input type="number" min="0" max="50" name="permissions[pwa_min_transition_completes]" value="<?php echo esc_attr( (string) ( $permissions['pwa_min_transition_completes'] ?? 1 ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Games completed', 'dsa' ); ?></span><input type="number" min="0" max="20" name="permissions[pwa_min_game_completes]" value="<?php echo esc_attr( (string) ( $permissions['pwa_min_game_completes'] ?? 0 ) ); ?>"></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Browser Notifications', 'dsa' ); ?></th>
								<td class="dsa-admin-permission-grid">
									<label><input type="checkbox" name="permissions[notifications_enabled]" value="1" <?php checked( ! empty( $permissions['notifications_enabled'] ) ); ?>> <?php esc_html_e( 'Enable explicit browser notification requests', 'dsa' ); ?></label>
									<label><input type="checkbox" name="permissions[notification_preferences_enabled]" value="1" <?php checked( ! empty( $permissions['notification_preferences_enabled'] ) ); ?>> <?php esc_html_e( 'Enable Personalize your Appsite journey', 'dsa' ); ?></label>
									<label><input type="checkbox" name="permissions[notification_order_prompt_enabled]" value="1" <?php checked( ! empty( $permissions['notification_order_prompt_enabled'] ) ); ?>> <?php esc_html_e( 'Offer order-status notifications after checkout', 'dsa' ); ?></label>
									<label><span><?php esc_html_e( 'Title', 'dsa' ); ?></span><input type="text" name="permissions[notifications_title]" value="<?php echo esc_attr( (string) ( $permissions['notifications_title'] ?? 'Turn on browser notifications?' ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Message', 'dsa' ); ?></span><input type="text" name="permissions[notifications_message]" value="<?php echo esc_attr( (string) ( $permissions['notifications_message'] ?? 'Get useful order, account, and store updates when you choose.' ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Unavailable product button', 'dsa' ); ?></span><input type="text" name="permissions[notification_cta_label]" value="<?php echo esc_attr( (string) ( $permissions['notification_cta_label'] ?? 'Notify me' ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Button color', 'dsa' ); ?></span><select name="permissions[notification_cta_color]"><option value="active" <?php selected( $permissions['notification_cta_color'] ?? 'active', 'active' ); ?>><?php esc_html_e( 'Active color', 'dsa' ); ?></option><option value="hover" <?php selected( $permissions['notification_cta_color'] ?? 'active', 'hover' ); ?>><?php esc_html_e( 'Hover color', 'dsa' ); ?></option></select></label>
									<label><span><?php esc_html_e( 'AI popup duration', 'dsa' ); ?></span><input type="number" min="2" max="15" step="0.5" name="permissions[ai_popup_duration_seconds]" value="<?php echo esc_attr( (string) ( (int) ( $permissions['ai_popup_duration_ms'] ?? 3200 ) / 1000 ) ); ?>"> <?php esc_html_e( 'seconds', 'dsa' ); ?></label>
									<p class="description"><code>data-kiwe-notifications</code> <?php esc_html_e( 'can be added to any clickable element. Kiwe only asks after that visitor gesture.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Future Permissions', 'dsa' ); ?></th>
								<td class="dsa-admin__checks">
									<label><input type="checkbox" name="permissions[location_enabled]" value="1" <?php checked( ! empty( $permissions['location_enabled'] ) ); ?>> <?php esc_html_e( 'Prepare location journey', 'dsa' ); ?></label>
									<label><input type="checkbox" name="permissions[camera_enabled]" value="1" <?php checked( ! empty( $permissions['camera_enabled'] ) ); ?>> <?php esc_html_e( 'Prepare camera/scanner journey', 'dsa' ); ?></label>
									<p class="description"><?php esc_html_e( 'Location and camera stay as roadmap gates until their dedicated flows are implemented.', 'dsa' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Permission Journey', 'dsa' ), 'secondary' ); ?>
				</form>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Core Settings', 'dsa' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Surface Shell', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
										<?php esc_html_e( 'Render the persistent Surface dock on the public front end.', 'dsa' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Settings ownership', 'dsa' ); ?></th>
								<td>
									<p class="description"><?php esc_html_e( 'Surface only controls whether the Appsite shell renders. Presentation, dock geometry, navigation, app install, diagnostics, and search each live in their own Kiwe section.', 'dsa' ); ?></p>
									<p>
										<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-theme' ) ); ?>"><?php esc_html_e( 'Theme', 'dsa' ); ?></a>
										<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-dock' ) ); ?>"><?php esc_html_e( 'Dock', 'dsa' ); ?></a>
										<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-menu' ) ); ?>"><?php esc_html_e( 'Menu', 'dsa' ); ?></a>
										<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-search' ) ); ?>"><?php esc_html_e( 'Search', 'dsa' ); ?></a>
										<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-app' ) ); ?>"><?php esc_html_e( 'App', 'dsa' ); ?></a>
										<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-developer' ) ); ?>"><?php esc_html_e( 'Developer', 'dsa' ); ?></a>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Settings', 'dsa' ) ); ?>
				</form>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Schema/GEO Engine', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'High-confidence structured data only. DSA starts with Woo Product/Offer, Breadcrumb, and conservative WebPage hints from the registry.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Schema Output', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="schema_geo[enabled]" value="1" <?php checked( ! empty( $schema_geo['enabled'] ) ); ?>>
										<?php esc_html_e( 'Render DSA JSON-LD when high-confidence data is available.', 'dsa' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Schema Types', 'dsa' ); ?></th>
								<td class="dsa-admin__checks">
									<label><input type="checkbox" name="schema_geo[woo_product]" value="1" <?php checked( ! empty( $schema_geo['woo_product'] ) ); ?>> <?php esc_html_e( 'WooCommerce Product + Offer schema', 'dsa' ); ?></label>
									<label><input type="checkbox" name="schema_geo[breadcrumb]" value="1" <?php checked( ! empty( $schema_geo['breadcrumb'] ) ); ?>> <?php esc_html_e( 'BreadcrumbList schema', 'dsa' ); ?></label>
									<label><input type="checkbox" name="schema_geo[webpage]" value="1" <?php checked( ! empty( $schema_geo['webpage'] ) ); ?>> <?php esc_html_e( 'WebPage / Article schema', 'dsa' ); ?></label>
									<label><input type="checkbox" name="schema_geo[registry_hints]" value="1" <?php checked( ! empty( $schema_geo['registry_hints'] ) ); ?>> <?php esc_html_e( 'Registry-derived GEO hints for strong headings and images', 'dsa' ); ?></label>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Schema/GEO', 'dsa' ) ); ?>
				</form>
			</section>

			<?php if ( false ) : // Dock controls moved to Kiwe > Dock. Keep old markup out of production UI. ?>
			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'PhoneKey Dock', 'dsa' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="dsa-dock-desktop"><?php esc_html_e( 'Desktop Orientation', 'dsa' ); ?></label>
								</th>
								<td>
									<select id="dsa-dock-desktop" name="dock[desktop_orientation]">
										<option value="vertical" <?php selected( $dock['desktop_orientation'], 'vertical' ); ?>><?php esc_html_e( 'Vertical', 'dsa' ); ?></option>
										<option value="horizontal" <?php selected( $dock['desktop_orientation'], 'horizontal' ); ?>><?php esc_html_e( 'Horizontal', 'dsa' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-dock-mobile"><?php esc_html_e( 'Mobile Orientation', 'dsa' ); ?></label>
								</th>
								<td>
									<select id="dsa-dock-mobile" name="dock[mobile_orientation]">
										<option value="horizontal" <?php selected( $dock['mobile_orientation'], 'horizontal' ); ?>><?php esc_html_e( 'Horizontal', 'dsa' ); ?></option>
										<option value="vertical" <?php selected( $dock['mobile_orientation'], 'vertical' ); ?>><?php esc_html_e( 'Vertical', 'dsa' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Desktop Dock Placement', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label><span><?php esc_html_e( 'When vertical', 'dsa' ); ?></span><select name="dock[desktop_vertical_position]"><option value="center" <?php selected( $dock['desktop_vertical_position'] ?? 'center', 'center' ); ?>><?php esc_html_e( 'Center', 'dsa' ); ?></option><option value="bottom" <?php selected( $dock['desktop_vertical_position'] ?? 'center', 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'dsa' ); ?></option></select></label>
									<label><span><?php esc_html_e( 'When horizontal', 'dsa' ); ?></span><select name="dock[desktop_horizontal_position]"><option value="left" <?php selected( $dock['desktop_horizontal_position'] ?? 'right', 'left' ); ?>><?php esc_html_e( 'Left', 'dsa' ); ?></option><option value="center" <?php selected( $dock['desktop_horizontal_position'] ?? 'right', 'center' ); ?>><?php esc_html_e( 'Center', 'dsa' ); ?></option><option value="right" <?php selected( $dock['desktop_horizontal_position'] ?? 'right', 'right' ); ?>><?php esc_html_e( 'Right', 'dsa' ); ?></option></select></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Dock Appearance', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label><span><?php esc_html_e( 'Shape', 'dsa' ); ?></span><select name="dock[shape]"><option value="pill" <?php selected( $dock_shape, 'pill' ); ?>><?php esc_html_e( 'Pill', 'dsa' ); ?></option><option value="box" <?php selected( $dock_shape, 'box' ); ?>><?php esc_html_e( 'Rounded box', 'dsa' ); ?></option><option value="square" <?php selected( $dock_shape, 'square' ); ?>><?php esc_html_e( 'Square / no radius', 'dsa' ); ?></option></select></label>
									<label><span><?php esc_html_e( 'Background', 'dsa' ); ?></span><select name="dock[material]"><option value="glass" <?php selected( $dock['material'] ?? 'glass', 'glass' ); ?>><?php esc_html_e( 'Glass', 'dsa' ); ?></option><option value="solid" <?php selected( $dock['material'] ?? 'glass', 'solid' ); ?>><?php esc_html_e( 'Solid', 'dsa' ); ?></option></select></label>
									<p class="description"><?php esc_html_e( 'Dock shape and material are independent from the DSA screen background.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Mobile Dock Placement', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label><span><?php esc_html_e( 'When vertical', 'dsa' ); ?></span><select name="dock[mobile_vertical_position]"><option value="center" <?php selected( $dock['mobile_vertical_position'] ?? 'bottom', 'center' ); ?>><?php esc_html_e( 'Center', 'dsa' ); ?></option><option value="bottom" <?php selected( $dock['mobile_vertical_position'] ?? 'bottom', 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'dsa' ); ?></option></select></label>
									<label><span><?php esc_html_e( 'Horizontal alignment', 'dsa' ); ?></span><select name="dock[mobile_horizontal_position]"><option value="left" <?php selected( $dock['mobile_horizontal_position'] ?? 'right', 'left' ); ?>><?php esc_html_e( 'Left', 'dsa' ); ?></option><option value="center" <?php selected( $dock['mobile_horizontal_position'] ?? 'right', 'center' ); ?>><?php esc_html_e( 'Center', 'dsa' ); ?></option><option value="right" <?php selected( $dock['mobile_horizontal_position'] ?? 'right', 'right' ); ?>><?php esc_html_e( 'Right', 'dsa' ); ?></option></select></label>
									<label><span><?php esc_html_e( 'Horizontal height', 'dsa' ); ?></span><select name="dock[mobile_horizontal_vertical_position]"><option value="center" <?php selected( $dock['mobile_horizontal_vertical_position'] ?? 'bottom', 'center' ); ?>><?php esc_html_e( 'Screen center', 'dsa' ); ?></option><option value="bottom" <?php selected( $dock['mobile_horizontal_vertical_position'] ?? 'bottom', 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'dsa' ); ?></option></select></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'DSA Theme Colors', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label><span><?php esc_html_e( 'Active', 'dsa' ); ?></span><input type="color" name="dsa_theme[active_color]" value="<?php echo esc_attr( (string) ( $theme['active_color'] ?? '#8f8f98' ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Hover', 'dsa' ); ?></span><input type="color" name="dsa_theme[hover_color]" value="<?php echo esc_attr( (string) ( $theme['hover_color'] ?? '#24c6a1' ) ); ?>"></label>
									<label><span><?php esc_html_e( 'Hero text', 'dsa' ); ?></span><input type="text" name="dsa_theme[hero_text_color]" value="<?php echo esc_attr( (string) ( $theme['hero_text_color'] ?? 'rgba(20,24,34,0.18)' ) ); ?>" placeholder="rgba(20,24,34,0.18)"></label>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-phonekey-visibility"><?php esc_html_e( 'PhoneKey Visibility', 'dsa' ); ?></label>
								</th>
								<td>
									<select id="dsa-phonekey-visibility" name="dock[phonekey_visibility]">
										<option value="all" <?php selected( $dock['phonekey_visibility'] ?? 'all', 'all' ); ?>><?php esc_html_e( 'All visitors and users', 'dsa' ); ?></option>
										<option value="visitors" <?php selected( $dock['phonekey_visibility'] ?? 'all', 'visitors' ); ?>><?php esc_html_e( 'Visitors only', 'dsa' ); ?></option>
										<option value="users" <?php selected( $dock['phonekey_visibility'] ?? 'all', 'users' ); ?>><?php esc_html_e( 'Logged-in users only', 'dsa' ); ?></option>
										<option value="customers" <?php selected( $dock['phonekey_visibility'] ?? 'all', 'customers' ); ?>><?php esc_html_e( 'WooCommerce customers only', 'dsa' ); ?></option>
										<option value="admins" <?php selected( $dock['phonekey_visibility'] ?? 'all', 'admins' ); ?>><?php esc_html_e( 'Admins only', 'dsa' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Controls when the Profile/Auth icon appears in the Kiwe dock.', 'dsa' ); ?></p>
								</td>
							</tr>
							<?php if ( false ) : // Menu ownership moved to Kiwe > Menu; retained only as migration-readable markup. ?>
							<tr>
								<th scope="row">
									<label for="dsa-menu-label"><?php esc_html_e( 'Menu Label', 'dsa' ); ?></label>
								</th>
								<td>
									<input id="dsa-menu-label" class="regular-text" type="text" name="dock[menu_label]" value="<?php echo esc_attr( (string) $dock['menu_label'] ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-menu-heading"><?php esc_html_e( 'Menu Panel Heading Tag', 'dsa' ); ?></label>
								</th>
								<td>
									<select id="dsa-menu-heading" name="dock[menu_heading_tag]">
										<?php foreach ( [ 'span', 'p', 'h1', 'h2', 'h3', 'h4' ] as $tag ) : ?>
											<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $dock['menu_heading_tag'], $tag ); ?>><?php echo esc_html( strtoupper( $tag ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-menu-nav-id"><?php esc_html_e( 'WordPress Menu Source', 'dsa' ); ?></label>
								</th>
								<td>
									<select id="dsa-menu-nav-id" name="dock[menu_nav_id]">
										<option value="0" <?php selected( (int) ( $dock['menu_nav_id'] ?? 0 ), 0 ); ?>><?php esc_html_e( 'Use custom DSA menu items below', 'dsa' ); ?></option>
										<?php foreach ( $this->nav_menu_options() as $menu_id => $menu_name ) : ?>
											<option value="<?php echo esc_attr( (string) $menu_id ); ?>" <?php selected( (int) ( $dock['menu_nav_id'] ?? 0 ), (int) $menu_id ); ?>><?php echo esc_html( $menu_name ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Select a menu created in Appearance > Menus or Customizer > Menus. Custom rows below remain available as a fallback or for DSA-only links.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Admin Dashboard Link', 'dsa' ); ?></th>
								<td>
									<label><input type="checkbox" name="dock[admin_dashboard_link_enabled]" value="1" <?php checked( ! empty( $dock['admin_dashboard_link_enabled'] ) ); ?>> <?php esc_html_e( 'Show a compact Dashboard link in Menu DSA for administrators', 'dsa' ); ?></label>
									<p class="description"><?php esc_html_e( 'The WordPress frontend admin bar is hidden. This utility link is never sent to visitors or non-administrative users.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-menu-target-search"><?php esc_html_e( 'Menu Targets', 'dsa' ); ?></label>
								</th>
								<td>
									<div class="dsa-admin-builder" data-dsa-menu-builder>
										<input id="dsa-menu-target-search" class="regular-text dsa-admin-search" type="search" placeholder="<?php echo esc_attr__( 'Search posts, pages, categories...', 'dsa' ); ?>" autocomplete="off" data-dsa-menu-search>
										<div class="dsa-admin-search-results" data-dsa-menu-results hidden></div>
										<div class="dsa-admin-rows" data-dsa-menu-items>
											<?php foreach ( $this->menu_items_for_admin( $dock ) as $index => $item ) : ?>
												<div class="dsa-admin-row" data-dsa-menu-row>
													<label><span><?php esc_html_e( 'Title', 'dsa' ); ?></span><input type="text" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][title]" value="<?php echo esc_attr( $item['title'] ); ?>" placeholder="<?php echo esc_attr__( 'Title', 'dsa' ); ?>"></label>
													<label><span><?php esc_html_e( 'URL', 'dsa' ); ?></span><input type="url" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][url]" value="<?php echo esc_url( $item['url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"></label>
													<label><span><?php esc_html_e( 'Type', 'dsa' ); ?></span><input type="text" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][type]" value="<?php echo esc_attr( $item['type'] ); ?>" placeholder="<?php echo esc_attr__( 'Type', 'dsa' ); ?>"></label>
													<label><span><?php esc_html_e( 'Image URL', 'dsa' ); ?></span><input type="url" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][image]" value="<?php echo esc_url( $item['image'] ?? '' ); ?>" placeholder="<?php echo esc_attr__( 'Optional rounded image', 'dsa' ); ?>"></label>
													<input type="hidden" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][object_id]" value="<?php echo esc_attr( (string) ( $item['object_id'] ?? 0 ) ); ?>">
													<input type="hidden" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][object_type]" value="<?php echo esc_attr( (string) ( $item['object_type'] ?? '' ) ); ?>">
													<button class="button dsa-admin-remove" type="button" data-dsa-remove-row><?php esc_html_e( 'Remove', 'dsa' ); ?></button>
												</div>
											<?php endforeach; ?>
										</div>
										<button class="button dsa-admin-add" type="button" data-dsa-add-menu-row><?php esc_html_e( '+ Add custom link', 'dsa' ); ?></button>
									</div>
									<p class="description"><?php esc_html_e( 'Search, click a result, and it appears as a bold Surface menu link. Custom URLs are still supported.', 'dsa' ); ?></p>
								</td>
							</tr>
							<?php endif; ?>
							<tr><th scope="row"><?php esc_html_e( 'Menu configuration', 'dsa' ); ?></th><td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-menu' ) ); ?>"><?php esc_html_e( 'Open Kiwe Menu', 'dsa' ); ?></a><p class="description"><?php esc_html_e( 'Navigation sources, custom targets, dashboard utility, and contextual headings are governed in Kiwe > Menu.', 'dsa' ); ?></p></td></tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save PhoneKey Dock', 'dsa' ) ); ?>
				</form>
			</section>
			<?php endif; ?>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Games', 'dsa' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Surface Trigger', 'dsa' ); ?></th>
								<td class="dsa-admin-game-bonuses">
									<label><input type="checkbox" name="games[surface_enabled]" value="1" <?php checked( ! empty( $games['surface_enabled'] ) ); ?>> <?php esc_html_e( 'Enable scheduled game Surface', 'dsa' ); ?></label>
									<label><input type="checkbox" name="games[show_on_page_load]" value="1" <?php checked( ! empty( $games['show_on_page_load'] ) ); ?>> <?php esc_html_e( 'Show on matching page load', 'dsa' ); ?></label>
									<div class="dsa-admin-game-row">
										<label><span><?php esc_html_e( 'Path contains', 'dsa' ); ?></span><input type="text" name="games[trigger_path]" value="<?php echo esc_attr( (string) ( $games['trigger_path'] ?? '/shop' ) ); ?>" placeholder="/shop"></label>
										<label><span><?php esc_html_e( 'Game', 'dsa' ); ?></span><select name="games[trigger_game]"><option value="dino" <?php selected( $games['trigger_game'] ?? 'dino', 'dino' ); ?>><?php esc_html_e( 'Dinosaur Jump', 'dsa' ); ?></option><option value="star" <?php selected( $games['trigger_game'] ?? 'dino', 'star' ); ?>><?php esc_html_e( 'Star Shooter', 'dsa' ); ?></option></select></label>
										<label><span><?php esc_html_e( 'Auto-close ms', 'dsa' ); ?></span><input type="number" min="0" max="60000" name="games[duration_ms]" value="<?php echo esc_attr( (string) ( $games['duration_ms'] ?? 0 ) ); ?>" placeholder="0"></label>
									</div>
									<div class="dsa-admin-game-row">
										<label><span><?php esc_html_e( 'Start title', 'dsa' ); ?></span><input type="text" name="games[start_title]" value="<?php echo esc_attr( (string) ( $games['start_title'] ?? 'Are You Game! for discount??' ) ); ?>"></label>
										<label><span><?php esc_html_e( 'Desktop text', 'dsa' ); ?></span><input type="text" name="games[start_text]" value="<?php echo esc_attr( (string) ( $games['start_text'] ?? 'Press any key to start' ) ); ?>"></label>
										<label><span><?php esc_html_e( 'Mobile text', 'dsa' ); ?></span><input type="text" name="games[mobile_start_text]" value="<?php echo esc_attr( (string) ( $games['mobile_start_text'] ?? 'Touch to start' ) ); ?>"></label>
									</div>
									<p class="description"><?php esc_html_e( 'Example: set Path contains to /shop to show Dinosaur Jump on the shop page.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Win Bonuses', 'dsa' ); ?></th>
								<td class="dsa-admin-game-bonuses">
									<label><input type="checkbox" name="games[rewards_enabled]" value="1" <?php checked( ! empty( $games['rewards_enabled'] ) ); ?>> <?php esc_html_e( 'Enable server-verified rewards', 'dsa' ); ?></label>
									<label><input type="checkbox" name="games[coupon_enabled]" value="1" <?php checked( ! empty( $games['coupon_enabled'] ) ); ?>> <?php esc_html_e( 'Generate WooCommerce coupon codes', 'dsa' ); ?></label>
									<div class="dsa-admin-game-row">
										<label><span><?php esc_html_e( 'Daily attempts', 'dsa' ); ?></span><input type="number" min="1" max="10" name="games[max_attempts_per_day]" value="<?php echo esc_attr( (string) ( $games['max_attempts_per_day'] ?? 3 ) ); ?>"></label>
										<label><span><?php esc_html_e( 'Coupon expiry minutes', 'dsa' ); ?></span><input type="number" min="5" max="1440" name="games[coupon_expiry_minutes]" value="<?php echo esc_attr( (string) ( $games['coupon_expiry_minutes'] ?? 20 ) ); ?>"></label>
										<label><span><?php esc_html_e( 'Daily coupon budget', 'dsa' ); ?></span><input type="number" min="1" max="100000" name="games[daily_coupon_budget]" value="<?php echo esc_attr( (string) ( $games['daily_coupon_budget'] ?? 100 ) ); ?>"></label>
										<label><span><?php esc_html_e( 'Minimum play ms', 'dsa' ); ?></span><input type="number" min="1000" max="30000" name="games[min_play_ms]" value="<?php echo esc_attr( (string) ( $games['min_play_ms'] ?? 4000 ) ); ?>"></label>
									</div>
									<?php for ( $i = 0; $i < 3; $i++ ) : $bonus = $games['bonuses'][ $i ] ?? []; ?>
										<div class="dsa-admin-game-row">
											<label><span><?php esc_html_e( 'Label', 'dsa' ); ?></span><input type="text" name="games[bonuses][<?php echo esc_attr( (string) $i ); ?>][label]" value="<?php echo esc_attr( (string) ( $bonus['label'] ?? '' ) ); ?>"></label>
											<label><span><?php esc_html_e( 'Discount %', 'dsa' ); ?></span><input type="number" min="0" max="100" name="games[bonuses][<?php echo esc_attr( (string) $i ); ?>][discount]" value="<?php echo esc_attr( (string) ( $bonus['discount'] ?? 0 ) ); ?>"></label>
											<label><span><?php esc_html_e( 'Retry text', 'dsa' ); ?></span><input type="text" name="games[retry_texts][<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $games['retry_texts'][ $i ] ?? '' ) ); ?>"></label>
										</div>
									<?php endfor; ?>
									<p class="description"><?php esc_html_e( 'Rewards are verified through REST before a coupon is issued. Leave coupon generation off while testing a live store.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Celebration', 'dsa' ); ?></th>
								<td>
									<label><input type="checkbox" name="games[confetti_enabled]" value="1" <?php checked( ! empty( $games['confetti_enabled'] ) ); ?>> <?php esc_html_e( 'Confetti blast after final/max reward', 'dsa' ); ?></label>
									<select name="dsa_theme[confetti_color_source]">
										<option value="hero" <?php selected( $theme['confetti_color_source'] ?? 'hero', 'hero' ); ?>><?php esc_html_e( 'Hero grey', 'dsa' ); ?></option>
										<option value="active" <?php selected( $theme['confetti_color_source'] ?? 'hero', 'active' ); ?>><?php esc_html_e( 'Active color', 'dsa' ); ?></option>
										<option value="hover" <?php selected( $theme['confetti_color_source'] ?? 'hero', 'hover' ); ?>><?php esc_html_e( 'Hover color', 'dsa' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Confetti inherits DSA hero/active/hover color rules.', 'dsa' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Games', 'dsa' ) ); ?>
				</form>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Links Hub', 'dsa' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="dsa-link-score"><?php esc_html_e( 'Site Score', 'dsa' ); ?></label></th>
								<td>
									<input id="dsa-link-score" class="small-text" type="number" min="0" max="100" name="link_hub[site_score]" value="<?php echo esc_attr( (string) ( $link_hub['site_score'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Optional', 'dsa' ); ?>">
									<span><?php esc_html_e( 'Optional. When blank, no score badge is rendered in the Links Surface.', 'dsa' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Social Links', 'dsa' ); ?></th>
								<td class="dsa-admin-socials">
									<?php foreach ( $this->social_link_labels() as $id => $label ) : ?>
										<label>
											<span><?php echo esc_html( $label ); ?></span>
											<input type="url" name="link_hub[social_links][<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_url( $link_hub['social_links'][ $id ] ?? '' ); ?>" placeholder="https://">
										</label>
									<?php endforeach; ?>
									<p class="description"><?php esc_html_e( 'Only saved social URLs appear in the visitor/admin Links Surface.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="dsa-shop-label"><?php esc_html_e( 'Shop Button', 'dsa' ); ?></label></th>
								<td class="dsa-admin-inline-fields">
									<input id="dsa-shop-label" type="text" name="link_hub[shop_label]" value="<?php echo esc_attr( (string) ( $link_hub['shop_label'] ?? 'Shop' ) ); ?>" placeholder="<?php echo esc_attr__( 'Shop', 'dsa' ); ?>">
									<input type="url" name="link_hub[shop_url]" value="<?php echo esc_url( $link_hub['shop_url'] ?? '' ); ?>" placeholder="<?php echo esc_attr__( 'Leave blank for WooCommerce shop URL', 'dsa' ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="dsa-posts-title"><?php esc_html_e( 'Posts Section', 'dsa' ); ?></label></th>
								<td class="dsa-admin-inline-fields">
									<input id="dsa-posts-title" type="text" name="link_hub[posts_title]" value="<?php echo esc_attr( (string) ( $link_hub['posts_title'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Blank uses category name or Latest Posts', 'dsa' ); ?>">
									<select name="link_hub[posts_category]">
										<option value="0"><?php esc_html_e( 'First available category', 'dsa' ); ?></option>
										<?php foreach ( $link_categories as $category ) : ?>
											<option value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php selected( (int) ( $link_hub['posts_category'] ?? 0 ), (int) $category->term_id ); ?>>
												<?php echo esc_html( $category->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'The Links Surface shows latest posts from this category. Blank title uses the category name.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Trust Labels', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<input type="text" name="link_hub[ssl_provider]" value="<?php echo esc_attr( (string) ( $link_hub['ssl_provider'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'SSL provider, e.g. Hostinger', 'dsa' ); ?>">
									<input type="text" name="link_hub[payment_provider]" value="<?php echo esc_attr( (string) ( $link_hub['payment_provider'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Payment provider fallback', 'dsa' ); ?>">
									<p class="description"><?php esc_html_e( 'Payment uses installed WooCommerce gateways first. Use the fallback when Kiwe cannot detect one.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="dsa-review-source"><?php esc_html_e( 'Review Source', 'dsa' ); ?></label></th>
								<td>
									<select id="dsa-review-source" name="link_hub[review_source]">
										<option value="manual" <?php selected( $link_hub['review_source'] ?? 'manual', 'manual' ); ?>><?php esc_html_e( 'Manual testimonials', 'dsa' ); ?></option>
										<option value="google" <?php selected( $link_hub['review_source'] ?? 'manual', 'google' ); ?>><?php esc_html_e( 'Google Places reviews', 'dsa' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Google reviews need a Places API key and Place ID. Manual testimonials are used as fallback.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Google Reviews API', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<input type="text" name="link_hub[google_place_id]" value="<?php echo esc_attr( (string) ( $link_hub['google_place_id'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Google Place ID', 'dsa' ); ?>">
									<input type="password" name="link_hub[google_api_key]" value="<?php echo esc_attr( (string) ( $link_hub['google_api_key'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Places API key', 'dsa' ); ?>" autocomplete="new-password">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="dsa-testimonials"><?php esc_html_e( 'Manual Testimonials', 'dsa' ); ?></label></th>
								<td>
									<textarea id="dsa-testimonials" class="large-text" rows="5" name="link_hub[testimonials]" placeholder="<?php echo esc_attr__( 'One testimonial per line', 'dsa' ); ?>"><?php echo esc_textarea( (string) ( $link_hub['testimonials'] ?? '' ) ); ?></textarea>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Links Hub', 'dsa' ) ); ?>
				</form>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Classic Visual Effects', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Transition copy, blur, and loader behavior for the Classic Surface. Sheet placement, sheet backdrop, dock material, and theme colors are owned by Kiwe > Theme and Kiwe > Dock.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="dsa-blur-type"><?php esc_html_e( 'Blur Type', 'dsa' ); ?></label>
								</th>
								<td>
									<select id="dsa-blur-type" name="visual_effects[blur_type]">
										<option value="none" <?php selected( $visual['blur_type'], 'none' ); ?>><?php esc_html_e( 'None', 'dsa' ); ?></option>
										<option value="gaussian" <?php selected( $visual['blur_type'], 'gaussian' ); ?>><?php esc_html_e( 'Gaussian Blur', 'dsa' ); ?></option>
										<option value="frosted" <?php selected( $visual['blur_type'], 'frosted' ); ?>><?php esc_html_e( 'Frosted Depth', 'dsa' ); ?></option>
										<option value="dim" <?php selected( $visual['blur_type'], 'dim' ); ?>><?php esc_html_e( 'Dim Only', 'dsa' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-blur-strength"><?php esc_html_e( 'Blur Strength', 'dsa' ); ?></label>
								</th>
								<td>
									<input id="dsa-blur-strength" class="small-text" type="number" name="visual_effects[blur_strength]" min="0" max="24" value="<?php echo esc_attr( (string) $visual['blur_strength'] ); ?>">
									<span><?php esc_html_e( 'px', 'dsa' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-glass-intensity"><?php esc_html_e( 'Glass Intensity', 'dsa' ); ?></label>
								</th>
								<td>
									<select id="dsa-glass-intensity" name="visual_effects[glass_intensity]">
										<option value="low" <?php selected( $visual['glass_intensity'], 'low' ); ?>><?php esc_html_e( 'Low', 'dsa' ); ?></option>
										<option value="medium" <?php selected( $visual['glass_intensity'], 'medium' ); ?>><?php esc_html_e( 'Medium', 'dsa' ); ?></option>
										<option value="high" <?php selected( $visual['glass_intensity'], 'high' ); ?>><?php esc_html_e( 'High', 'dsa' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-loader-type"><?php esc_html_e( 'Loading Experience', 'dsa' ); ?></label>
								</th>
								<td>
									<select id="dsa-loader-type" name="visual_effects[loader_type]">
										<option value="none" <?php selected( $visual['loader_type'], 'none' ); ?>><?php esc_html_e( 'None', 'dsa' ); ?></option>
										<option value="orb-chase" <?php selected( $visual['loader_type'], 'orb-chase' ); ?>><?php esc_html_e( 'Orb Chase Mini Game', 'dsa' ); ?></option>
										<option value="pulse" <?php selected( $visual['loader_type'], 'pulse' ); ?>><?php esc_html_e( 'Pulse Loader', 'dsa' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="dsa-screen-material"><?php esc_html_e( 'DSA Screen Background', 'dsa' ); ?></label></th>
									<td><select id="dsa-screen-material" name="visual_effects[screen_material]"><option value="glass" <?php selected( $visual['screen_material'] ?? 'glass', 'glass' ); ?>><?php esc_html_e( 'Glass', 'dsa' ); ?></option><option value="solid" <?php selected( $visual['screen_material'] ?? 'glass', 'solid' ); ?>><?php esc_html_e( 'Solid', 'dsa' ); ?></option></select><p class="description"><?php esc_html_e( 'Classic DSA screens only. Sheet background and backdrop are configured in Kiwe > Theme; dock background is configured in Kiwe > Dock.', 'dsa' ); ?></p></td>
								</tr>
								<tr>
									<th scope="row"><label for="dsa-screen-animation"><?php esc_html_e( 'DSA Screen Motion', 'dsa' ); ?></label></th>
									<td><select id="dsa-screen-animation" name="visual_effects[screen_animation]"><?php foreach ( [ 'bottom' => __( 'Slide from bottom', 'dsa' ), 'top' => __( 'Slide from top', 'dsa' ), 'left' => __( 'Slide from left', 'dsa' ), 'right' => __( 'Slide from right', 'dsa' ) ] as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $visual['screen_animation'] ?? 'bottom', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'The screen exits through the same edge. Reduced-motion preferences are respected.', 'dsa' ); ?></p></td>
								</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Show Effects', 'dsa' ); ?></th>
								<td class="dsa-admin__checks">
									<label><input type="checkbox" name="visual_effects[show_on_overlay_open]" value="1" <?php checked( ! empty( $visual['show_on_overlay_open'] ) ); ?>> <?php esc_html_e( 'When a Surface overlay opens', 'dsa' ); ?></label>
									<label><input type="checkbox" name="visual_effects[show_on_navigation]" value="1" <?php checked( ! empty( $visual['show_on_navigation'] ) ); ?>> <?php esc_html_e( 'During fragment navigation', 'dsa' ); ?></label>
									<label><input type="checkbox" name="visual_effects[show_on_page_out]" value="1" <?php checked( ! empty( $visual['show_on_page_out'] ) ); ?>> <?php esc_html_e( 'On page out', 'dsa' ); ?></label>
									<label><input type="checkbox" name="visual_effects[show_on_page_in]" value="1" <?php checked( ! empty( $visual['show_on_page_in'] ) ); ?>> <?php esc_html_e( 'On page in', 'dsa' ); ?></label>
									<label><input type="checkbox" name="visual_effects[editorial_view_transitions]" value="1" <?php checked( ! empty( $visual['editorial_view_transitions'] ) ); ?>> <?php esc_html_e( 'Native cross-document transitions for approved editorial links', 'dsa' ); ?></label>
									<label><input type="checkbox" name="visual_effects[editorial_morph_navigation]" value="1" <?php checked( ! empty( $visual['editorial_morph_navigation'] ) ); ?>> <?php esc_html_e( 'Experimental controlled morphing for proven static editorial routes', 'dsa' ); ?></label>
									<p class="description"><?php esc_html_e( 'Off by default. Any builder, form, script, asset, nonce, or lifecycle uncertainty falls back to a normal full-document navigation.', 'dsa' ); ?></p>
									<p class="description"><code>await DSA.runEditorialSafetyMatrix()</code> <?php esc_html_e( 'runs the non-mutating S16 matrix for editorial links discovered on the current page plus intentional protected-route failures. Use DSA.getEditorialSafetyOutcomes() to inspect the bounded session evidence.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-min-loader"><?php esc_html_e( 'Minimum Game Time', 'dsa' ); ?></label>
								</th>
								<td>
									<input id="dsa-min-loader" class="small-text" type="number" name="visual_effects[min_loader_ms]" min="0" max="10000" step="100" value="<?php echo esc_attr( (string) $visual['min_loader_ms'] ); ?>">
									<span><?php esc_html_e( 'ms', 'dsa' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-artificial-delay"><?php esc_html_e( 'Artificial Delay', 'dsa' ); ?></label>
								</th>
								<td>
									<input id="dsa-artificial-delay" class="small-text" type="number" name="visual_effects[artificial_delay_ms]" min="0" max="5000" step="100" value="<?php echo esc_attr( (string) $visual['artificial_delay_ms'] ); ?>">
									<span><?php esc_html_e( 'ms added before content swap. Use sparingly.', 'dsa' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'First Load Preloader', 'dsa' ); ?></th>
								<td class="dsa-admin-socials">
									<label><span><?php esc_html_e( 'Enabled', 'dsa' ); ?></span><input type="checkbox" name="visual_effects[initial_preloader_enabled]" value="1" <?php checked( ! empty( $visual['initial_preloader_enabled'] ) ); ?>></label>
									<p class="description"><?php esc_html_e( 'Shows the first DSA screen before the page is revealed. Tagline, site title, welcome copy, and app buttons are managed in Kiwe > App.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Transition Messages', 'dsa' ); ?></th>
								<td>
									<div class="dsa-admin-message-options">
										<label>
											<?php esc_html_e( 'Mode', 'dsa' ); ?>
											<select name="visual_effects[transition_message_mode]">
												<option value="random" <?php selected( $visual['transition_message_mode'] ?? 'random', 'random' ); ?>><?php esc_html_e( 'Randomize', 'dsa' ); ?></option>
												<option value="fixed" <?php selected( $visual['transition_message_mode'] ?? 'random', 'fixed' ); ?>><?php esc_html_e( 'Fixed message', 'dsa' ); ?></option>
											</select>
										</label>
										<label>
											<?php esc_html_e( 'Fixed #', 'dsa' ); ?>
											<input class="small-text" type="number" name="visual_effects[transition_message_index]" min="1" max="20" value="<?php echo esc_attr( (string) ( (int) ( $visual['transition_message_index'] ?? 0 ) + 1 ) ); ?>">
										</label>
										<label>
											<?php esc_html_e( 'Title', 'dsa' ); ?>
											<select name="visual_effects[transition_title_position]">
												<option value="above" <?php selected( $visual['transition_title_position'] ?? 'above', 'above' ); ?>><?php esc_html_e( 'Above message', 'dsa' ); ?></option>
												<option value="below" <?php selected( $visual['transition_title_position'] ?? 'above', 'below' ); ?>><?php esc_html_e( 'Below message', 'dsa' ); ?></option>
											</select>
										</label>
									</div>
									<div class="dsa-admin-rows" data-dsa-message-items>
										<?php foreach ( $this->transition_messages_for_admin( $visual ) as $index => $message ) : ?>
											<div class="dsa-admin-row dsa-admin-row--message" data-dsa-message-row>
												<label><span><?php esc_html_e( 'Title', 'dsa' ); ?></span><input type="text" name="visual_effects[transition_messages][<?php echo esc_attr( (string) $index ); ?>][title]" value="<?php echo esc_attr( $message['title'] ); ?>" placeholder="<?php echo esc_attr__( 'Title, e.g. Did you know', 'dsa' ); ?>"></label>
												<label><span><?php esc_html_e( 'Message', 'dsa' ); ?></span><textarea name="visual_effects[transition_messages][<?php echo esc_attr( (string) $index ); ?>][message]" rows="3" placeholder="<?php echo esc_attr__( 'Message', 'dsa' ); ?>"><?php echo esc_textarea( $message['message'] ); ?></textarea></label>
												<button class="button dsa-admin-remove" type="button" data-dsa-remove-row><?php esc_html_e( 'Remove', 'dsa' ); ?></button>
											</div>
										<?php endforeach; ?>
									</div>
									<button class="button dsa-admin-add" type="button" data-dsa-add-message-row><?php esc_html_e( '+ Add transition message', 'dsa' ); ?></button>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Visual Effects', 'dsa' ) ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	public function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage Kiwe settings.', 'dsa' ),
				esc_html__( 'Permission denied', 'dsa' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'dsa_save_settings' );

		$current = $this->settings->all();
		$update  = [
			'enabled'             => isset( $_POST['enabled'] ) ? ! empty( $_POST['enabled'] ) : ! empty( $current['enabled'] ),
			'style'               => $this->sanitize_style_settings( $_POST['style'] ?? null, $current['style'] ?? [] ),
			'fragment_navigation' => false,
			'surface_width'       => isset( $_POST['surface_width'] )
				? max( 48, min( 220, absint( wp_unslash( $_POST['surface_width'] ) ) ) )
				: (int) $current['surface_width'],
			'visual_effects'      => $this->sanitize_visual_effects( $_POST['visual_effects'] ?? null, $current['visual_effects'] ),
			'diagnostics'         => $this->sanitize_diagnostics_settings( $_POST['diagnostics'] ?? null, $current['diagnostics'] ),
			'enhancements'        => $this->sanitize_enhancement_settings( $_POST['enhancements'] ?? null, $current['enhancements'] ?? [] ),
			'dock'                => $this->sanitize_dock_settings( $_POST['dock'] ?? null, $current['dock'] ),
			'dsa_theme'           => $this->sanitize_dsa_theme( $_POST['dsa_theme'] ?? null, $current['dsa_theme'] ),
			'schema_geo'          => $this->sanitize_schema_geo_settings( $_POST['schema_geo'] ?? null, $current['schema_geo'] ),
			'metrics'             => $this->sanitize_metrics_settings( $_POST['metrics'] ?? null, $current['metrics'] ),
			'permissions'         => $this->sanitize_permissions_settings( $_POST['permissions'] ?? null, $current['permissions'] ),
			'protected_flow'      => $this->sanitize_protected_flow_settings( $_POST['protected_flow'] ?? null, $current['protected_flow'] ),
			'secure'              => $this->sanitize_secure_settings( $_POST['secure'] ?? null, $current['secure'] ),
			'email'               => $this->sanitize_email_settings( $_POST['email'] ?? null, $current['email'] ),
			'abandoned_cart'      => $this->sanitize_abandoned_cart_settings( $_POST['abandoned_cart'] ?? null, $current['abandoned_cart'] ),
			'commerce'            => $this->sanitize_commerce_settings( $_POST['commerce'] ?? null, $current['commerce'] ),
			'bricks'              => $this->sanitize_bricks_settings( $_POST['bricks'] ?? null, $current['bricks'] ),
			'haptic'             => $this->sanitize_haptic_settings( $_POST['haptic'] ?? null, $current['haptic'] ),
			'games'               => $this->sanitize_games_settings( $_POST['games'] ?? null, $current['games'] ),
			'link_hub'            => $this->sanitize_link_hub_settings( $_POST['link_hub'] ?? null, $current['link_hub'] ),
			'app'                 => $this->sanitize_app_settings( $_POST['app'] ?? null, $current['app'] ),
			'search'              => $this->sanitize_search_settings( $_POST['search'] ?? null, $current['search'] ?? [] ),
		];

		$this->settings->update( $update );

		$redirect_page = sanitize_key( wp_unslash( $_POST['_dsa_redirect'] ?? 'kiwe' ) );
		if ( 'kiwe-style' === $redirect_page ) {
			$redirect_page = 'kiwe-theme';
		}
		if ( 'kiwe-tokens' === $redirect_page ) {
			$redirect_page = 'kiwe-framework';
		}
		if ( ! in_array( $redirect_page, [ 'kiwe', 'kiwe-app', 'kiwe-haptic', 'kiwe-framework', 'kiwe-dock', 'kiwe-theme', 'kiwe-games', 'kiwe-links', 'kiwe-search', 'kiwe-menu', 'kiwe-secure', 'kiwe-email', 'kiwe-woocommerce', 'kiwe-analytics', 'kiwe-abandoned-cart', 'kiwe-bricks', 'kiwe-developer' ], true ) ) {
			$redirect_page = 'kiwe';
		}

		wp_safe_redirect( add_query_arg( 'settings-updated', '1', admin_url( 'admin.php?page=' . $redirect_page ) ) );
		exit;
	}

	public function handle_developer_clear_runtime(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dsa' ), esc_html__( 'Permission denied', 'dsa' ), [ 'response' => 403 ] );
		}

		check_admin_referer( 'dsa_developer_clear_runtime' );
		$deleted = $this->clear_runtime_cache_records();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'            => 'kiwe-developer',
					'runtime-cleared' => '1',
					'deleted'         => $deleted,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_developer_reset_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dsa' ), esc_html__( 'Permission denied', 'dsa' ), [ 'response' => 403 ] );
		}

		check_admin_referer( 'dsa_developer_reset_settings' );

		if ( '1' !== (string) ( $_POST['confirm_reset'] ?? '' ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-developer', 'settings-reset' => 'cancelled' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		update_option( DSA_OPTION_SETTINGS, $this->settings->defaults(), false );
		delete_option( DSA_OPTION_MANIFEST );
		$this->clear_runtime_cache_records();

		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-developer', 'settings-reset' => '1', 'runtime-cleared' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}
	public function export_profile(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to export Appsite profiles.', 'dsa' ),
				esc_html__( 'Permission denied', 'dsa' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'dsa_export_profile' );

		$payload = $this->profile_payload();
		$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! $json ) {
			wp_safe_redirect( add_query_arg( 'profile-error', 'encode', admin_url( 'admin.php?page=kiwe' ) ) );
			exit;
		}

		$site = sanitize_title( get_bloginfo( 'name' ) ?: 'appsite' );
		$file = sprintf( 'kiwe-appsite-profile-%s-%s.json', $site, gmdate( 'Ymd-His' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function export_bricks_tokens(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to export the Kiwe Framework.', 'dsa' ),
				esc_html__( 'Permission denied', 'dsa' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'dsa_export_bricks_tokens' );

		$json = wp_json_encode( Seam_Token_Service::export_for_bricks( $this->universal_tokens() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! $json ) {
			wp_safe_redirect( add_query_arg( 'settings-updated', '0', admin_url( 'admin.php?page=kiwe-framework' ) ) );
			exit;
		}

		$site = sanitize_title( get_bloginfo( 'name' ) ?: 'appsite' );
		$file = sprintf( 'kiwe-seam-bricks-tokens-%s-%s.json', $site, gmdate( 'Ymd-His' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function export_site_graph(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to export the Kiwe Site Graph.', 'dsa' ),
				esc_html__( 'Permission denied', 'dsa' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'dsa_export_site_graph' );

		$raw_sample_limit = isset( $_POST['sampleLimit'] ) ? sanitize_text_field( wp_unslash( $_POST['sampleLimit'] ) ) : '8';
		$sample_limit     = absint( $raw_sample_limit );
		$sample_limit     = max( 0, min( 24, $sample_limit ) );
		$service          = new Site_Graph_Service( $this->settings, $this->modules );
		$payload          = $service->graph( [ 'sampleLimit' => $sample_limit ] );
		$json             = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! $json ) {
			wp_safe_redirect( add_query_arg( 'site-graph-exported', 'encode-error', admin_url( 'admin.php?page=kiwe-framework' ) ) );
			exit;
		}

		$site = sanitize_title( get_bloginfo( 'name' ) ?: 'appsite' );
		$file = sprintf( 'kiwe-site-graph-%s-%s.json', $site, gmdate( 'Ymd-His' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function validate_binding_plan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to validate Kiwe binding plans.', 'dsa' ),
				esc_html__( 'Permission denied', 'dsa' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'dsa_validate_binding_plan' );

		$file = $_FILES['dsa_binding_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			$this->redirect_binding_plan_error( 'missing' );
		}

		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$this->redirect_binding_plan_error( 'missing' );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > 512 * 1024 ) {
			$this->redirect_binding_plan_error( 'size' );
		}

		$name = sanitize_file_name( $file['name'] ?? '' );
		if ( ! preg_match( '/\.json$/i', $name ) ) {
			$this->redirect_binding_plan_error( 'type' );
		}

		$raw = file_get_contents( (string) $file['tmp_name'] );
		if ( false === $raw || '' === trim( $raw ) ) {
			$this->redirect_binding_plan_error( 'empty' );
		}

		$binding = json_decode( $raw, true );
		if ( ! is_array( $binding ) ) {
			$this->redirect_binding_plan_error( 'json' );
		}

		$raw_sample_limit = isset( $_POST['sampleLimit'] ) ? sanitize_text_field( wp_unslash( $_POST['sampleLimit'] ) ) : '8';
		$sample_limit     = max( 0, min( 24, absint( $raw_sample_limit ) ) );
		$site_graph       = ( new Site_Graph_Service( $this->settings, $this->modules ) )->graph( [ 'sampleLimit' => $sample_limit ] );
		$report           = ( new Binding_Plan_Validator() )->validate( $binding, $site_graph );
		$apply_plan       = ( new Apply_Plan_Preparer() )->prepare( $binding, $site_graph, $report );
		$key              = md5( get_current_user_id() . '|' . microtime( true ) . '|' . wp_rand() );

		set_transient(
			'dsa_binding_report_' . $key,
			[
				'userId'      => get_current_user_id(),
				'createdAt'   => gmdate( 'c' ),
				'fileName'    => $name,
				'sampleLimit' => $sample_limit,
				'siteName'    => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'report'      => $report,
				'applyPlan'   => $apply_plan,
			],
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( 'binding-report', $key, admin_url( 'admin.php?page=kiwe-framework' ) ) );
		exit;
	}

	public function download_apply_plan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to download Kiwe apply plans.', 'dsa' ),
				esc_html__( 'Permission denied', 'dsa' ),
				[ 'response' => 403 ]
			);
		}

		$key = isset( $_POST['bindingReport'] ) ? sanitize_key( (string) wp_unslash( $_POST['bindingReport'] ) ) : '';
		if ( '' === $key ) {
			wp_die(
				esc_html__( 'Apply plan report key is missing.', 'dsa' ),
				esc_html__( 'Missing report', 'dsa' ),
				[ 'response' => 400 ]
			);
		}

		check_admin_referer( 'dsa_download_apply_plan_' . $key );

		$payload = get_transient( 'dsa_binding_report_' . $key );
		if ( ! is_array( $payload ) || (int) ( $payload['userId'] ?? 0 ) !== get_current_user_id() ) {
			wp_die(
				esc_html__( 'Apply plan report expired or is not available for this admin user.', 'dsa' ),
				esc_html__( 'Apply plan unavailable', 'dsa' ),
				[ 'response' => 404 ]
			);
		}

		$apply_plan = isset( $payload['applyPlan'] ) && is_array( $payload['applyPlan'] ) ? $payload['applyPlan'] : [];
		if ( [] === $apply_plan ) {
			wp_die(
				esc_html__( 'This binding report does not contain a dry-run apply plan.', 'dsa' ),
				esc_html__( 'Apply plan unavailable', 'dsa' ),
				[ 'response' => 404 ]
			);
		}

		$json = wp_json_encode( $apply_plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! $json ) {
			wp_die(
				esc_html__( 'Could not encode the dry-run apply plan.', 'dsa' ),
				esc_html__( 'Apply plan export failed', 'dsa' ),
				[ 'response' => 500 ]
			);
		}

		$site = sanitize_title( (string) ( $payload['siteName'] ?? get_bloginfo( 'name' ) ?: 'appsite' ) );
		$file = sprintf( 'kiwe-apply-plan-%s-%s.json', $site ?: 'appsite', gmdate( 'Ymd-His' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function apply_bricks_tokens(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to push the Kiwe Framework to Bricks.', 'dsa' ),
				esc_html__( 'Permission denied', 'dsa' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'dsa_apply_bricks_tokens' );

		if ( ! defined( 'BRICKS_DB_GLOBAL_VARIABLES' ) || ! defined( 'BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES' ) ) {
			wp_safe_redirect( add_query_arg( 'tokens-exported', 'no-bricks', admin_url( 'admin.php?page=kiwe-framework' ) ) );
			exit;
		}

		$export             = Seam_Token_Service::export_for_bricks( $this->universal_tokens() );
		$kiwe_variables     = isset( $export['variables'] ) && is_array( $export['variables'] ) ? $export['variables'] : [];
		$kiwe_categories    = isset( $export['categories'] ) && is_array( $export['categories'] ) ? $export['categories'] : [];
		$kiwe_palette       = isset( $export['colorPalette'] ) && is_array( $export['colorPalette'] ) ? $export['colorPalette'] : [];
		$kiwe_classes       = isset( $export['classes'] ) && is_array( $export['classes'] ) ? $export['classes'] : [];
		$kiwe_class_categories = isset( $export['classCategories'] ) && is_array( $export['classCategories'] ) ? $export['classCategories'] : [];
		$current_variables  = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
		$current_categories = get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
		$current_palette    = defined( 'BRICKS_DB_COLOR_PALETTE' ) ? get_option( BRICKS_DB_COLOR_PALETTE, [] ) : [];
		$current_classes    = defined( 'BRICKS_DB_GLOBAL_CLASSES' ) ? get_option( BRICKS_DB_GLOBAL_CLASSES, [] ) : [];
		$current_class_categories = defined( 'BRICKS_DB_GLOBAL_CLASSES_CATEGORIES' ) ? get_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, [] ) : [];
		$current_variables  = is_array( $current_variables ) ? $current_variables : [];
		$current_categories = is_array( $current_categories ) ? $current_categories : [];
		$current_palette    = is_array( $current_palette ) ? $current_palette : [];
		$current_classes    = is_array( $current_classes ) ? $current_classes : [];
		$current_class_categories = is_array( $current_class_categories ) ? $current_class_categories : [];

		update_option(
			'dsa_bricks_tokens_backup',
			[
				'createdAt'  => gmdate( 'c' ),
				'userId'     => get_current_user_id(),
				'version'    => DSA_VERSION,
				'variables'  => $current_variables,
				'categories' => $current_categories,
				'palette'    => $current_palette,
				'classes'    => $current_classes,
				'classCategories' => $current_class_categories,
			],
			false
		);

		$merged_variables = array_values(
			array_filter(
				$current_variables,
				static function ( $variable ): bool {
					if ( ! is_array( $variable ) ) {
						return false;
					}

					$name   = isset( $variable['name'] ) ? (string) $variable['name'] : '';
					$source = isset( $variable['source'] ) ? (string) $variable['source'] : '';

					return 'kiwe-universal' !== $source && ! str_starts_with( $name, 'kiwe-' );
				}
			)
		);

		$merged_categories = array_values(
			array_filter(
				$current_categories,
				static function ( $category ): bool {
					if ( ! is_array( $category ) ) {
						return false;
					}

					$name   = isset( $category['name'] ) ? (string) $category['name'] : '';
					$source = isset( $category['source'] ) ? (string) $category['source'] : '';

					return 'kiwe-universal' !== $source && ! str_starts_with( $name, 'Kiwe ' );
				}
			)
		);

		$merged_palette = array_values(
			array_filter(
				$current_palette,
				static function ( $palette ): bool {
					if ( ! is_array( $palette ) ) {
						return false;
					}

					$name   = isset( $palette['name'] ) ? (string) $palette['name'] : '';
					$source = isset( $palette['source'] ) ? (string) $palette['source'] : '';

					return 'kiwe-universal' !== $source && 'Kiwe Universal' !== $name;
				}
			)
		);
		$kiwe_class_names = array_flip(
			array_filter(
				array_map(
					static function ( $class ): string {
						return is_array( $class ) ? (string) ( $class['name'] ?? '' ) : '';
					},
					$kiwe_classes
				)
			)
		);
		$merged_classes = array_values(
			array_filter(
				$current_classes,
				static function ( $class ) use ( $kiwe_class_names ): bool {
					if ( ! is_array( $class ) ) {
						return false;
					}

					$name   = isset( $class['name'] ) ? (string) $class['name'] : '';
					$source = isset( $class['source'] ) ? (string) $class['source'] : '';

					return 'kiwe-seam' !== $source && ! isset( $kiwe_class_names[ $name ] );
				}
			)
		);
		$merged_class_categories = array_values(
			array_filter(
				$current_class_categories,
				static function ( $category ): bool {
					if ( ! is_array( $category ) ) {
						return false;
					}

					$name   = isset( $category['name'] ) ? (string) $category['name'] : '';
					$source = isset( $category['source'] ) ? (string) $category['source'] : '';

					return 'kiwe-seam' !== $source && ! str_starts_with( $name, 'Kiwe Seam ' );
				}
			)
		);

		$merged_variables  = array_merge( $merged_variables, $kiwe_variables );
		$merged_categories = array_merge( $merged_categories, $kiwe_categories );
		$merged_palette    = array_merge( $merged_palette, $kiwe_palette );
		$merged_classes    = array_merge( $merged_classes, $kiwe_classes );
		$merged_class_categories = array_merge( $merged_class_categories, $kiwe_class_categories );

		update_option( BRICKS_DB_GLOBAL_VARIABLES, $merged_variables, false );
		update_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, $merged_categories, false );
		if ( defined( 'BRICKS_DB_COLOR_PALETTE' ) ) {
			update_option( BRICKS_DB_COLOR_PALETTE, $merged_palette, false );
		}
		if ( defined( 'BRICKS_DB_GLOBAL_CLASSES' ) ) {
			if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'save_global_classes_in_db' ) ) {
				\Bricks\Helpers::save_global_classes_in_db( $merged_classes );
			} else {
				update_option( BRICKS_DB_GLOBAL_CLASSES, $merged_classes, false );
			}
		}
		if ( defined( 'BRICKS_DB_GLOBAL_CLASSES_CATEGORIES' ) ) {
			update_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, $merged_class_categories, false );
		}

		if ( class_exists( '\Bricks\Assets_Global_Variables' ) && class_exists( '\Bricks\Assets' ) && ! empty( \Bricks\Assets::$css_dir ) ) {
			\Bricks\Assets_Global_Variables::generate_css_file( $merged_variables );
		}
		if ( class_exists( '\Bricks\Assets_Color_Palettes' ) && class_exists( '\Bricks\Assets' ) && ! empty( \Bricks\Assets::$css_dir ) ) {
			\Bricks\Assets_Color_Palettes::generate_css_file( $merged_palette );
		}

		wp_safe_redirect( add_query_arg( 'tokens-exported', 'bricks', admin_url( 'admin.php?page=kiwe-framework' ) ) );
		exit;
	}

	public function import_profile(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to import Appsite profiles.', 'dsa' ),
				esc_html__( 'Permission denied', 'dsa' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'dsa_import_profile' );

		$file = $_FILES['dsa_profile_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			$this->redirect_profile_error( 'missing' );
		}

		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$this->redirect_profile_error( 'missing' );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > 1024 * 1024 ) {
			$this->redirect_profile_error( 'size' );
		}

		$name = sanitize_file_name( $file['name'] ?? '' );

		if ( ! preg_match( '/\.json$/i', $name ) ) {
			$this->redirect_profile_error( 'type' );
		}

		$raw = file_get_contents( (string) $file['tmp_name'] );

		if ( false === $raw || '' === trim( $raw ) ) {
			$this->redirect_profile_error( 'empty' );
		}

		$payload = json_decode( $raw, true );

		if ( ! is_array( $payload ) ) {
			$this->redirect_profile_error( 'json' );
		}

		$settings = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : $payload;
		$next     = $this->sanitize_profile_settings( $settings, $this->settings->all() );

		$this->settings->update( $next );

		wp_safe_redirect( add_query_arg( 'profile-imported', '1', admin_url( 'admin.php?page=kiwe' ) ) );
		exit;
	}

	public function search_menu_targets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dsa' ) ], 403 );
		}

		check_ajax_referer( 'dsa_admin_search', 'nonce' );

		$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );

		if ( strlen( $query ) < 2 ) {
			wp_send_json_success( [] );
		}

		wp_send_json_success( $this->menu_targets( $query ) );
	}

	public function render_auth_page(): void {
		if ( ! function_exists( 'pk_admin_page' ) ) {
			?>
			<div class="wrap dsa-admin">
				<h1><?php esc_html_e( 'Kiwe Auth', 'dsa' ); ?></h1>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Kiwe Auth core is not loaded yet. Re-upload the PhoneKey integration files and refresh this page.', 'dsa' ); ?></p>
				</div>
			</div>
			<?php
			return;
		}

		pk_admin_page();
	}

	public function render_app_page(): void {
		$settings = $this->settings->all();
		$app      = wp_parse_args( $settings['app'] ?? [], $this->settings->defaults()['app'] );
		$permissions = wp_parse_args( $settings['permissions'] ?? [], $this->settings->defaults()['permissions'] );
		$visual = wp_parse_args( $settings['visual_effects'] ?? [], $this->settings->defaults()['visual_effects'] );
		$tab      = sanitize_key( wp_unslash( $_GET['tab'] ?? 'settings' ) );
		if ( 'developer' === $tab ) {
			wp_safe_redirect( add_query_arg( 'page', 'kiwe-developer', admin_url( 'admin.php' ) ) );
			exit;
		}
		$tab      = in_array( $tab, [ 'settings', 'adoption', 'audience' ], true ) ? $tab : 'settings';
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe App', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Control the Appsite home, low-friction installation, permission journeys, and app adoption across stores, publications, communities, and service websites.', 'dsa' ); ?></p>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'App settings saved.', 'dsa' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_app_tabs( $tab ); ?>

			<?php if ( 'adoption' === $tab ) : ?>
				<?php $this->render_app_adoption( $this->store_analytics ?: new Store_Analytics_Service( $this->settings ) ); ?>
			</div>
				<?php return; ?>
			<?php elseif ( 'audience' === $tab ) : ?>
				<?php $this->render_app_audience(); ?>
			</div>
				<?php return; ?>
			<?php endif; ?>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'First Screen Copy', 'dsa' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<input type="hidden" name="_dsa_redirect" value="kiwe-app">
					<input type="hidden" name="enabled" value="0">
					<input type="hidden" name="visual_effects[_app_present]" value="1">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Appsite shell', 'dsa' ); ?></th>
								<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> <?php esc_html_e( 'Render the Kiwe Appsite shell and its configured navigation.', 'dsa' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'First-session Home', 'dsa' ); ?></th>
								<td><input type="hidden" name="visual_effects[initial_preloader_enabled]" value="0"><label><input type="checkbox" name="visual_effects[initial_preloader_enabled]" value="1" <?php checked( ! empty( $visual['initial_preloader_enabled'] ) ); ?>> <?php esc_html_e( 'Show the Home DSA once per browsing session before the site is revealed.', 'dsa' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Preloader Text Source', 'dsa' ); ?></th>
								<td>
									<p class="description"><?php esc_html_e( 'The small title uses the WordPress tagline. The hero uses the WordPress site title and always takes the DSA active color.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="dsa-app-welcome"><?php esc_html_e( 'Welcome Message', 'dsa' ); ?></label>
								</th>
								<td>
									<input id="dsa-app-welcome" class="regular-text" type="text" name="app[welcome_message]" value="<?php echo esc_attr( (string) ( $app['welcome_message'] ?? 'Welcome to Our Appsite' ) ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="dsa-app-pwa-pitch"><?php esc_html_e( 'App Invitation', 'dsa' ); ?></label></th>
								<td><input id="dsa-app-pwa-pitch" class="regular-text" type="text" name="app[pwa_pitch]" value="<?php echo esc_attr( (string) ( $app['pwa_pitch'] ?? 'Try our app. No app store required.' ) ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Install Readiness', 'dsa' ); ?></th>
								<td>
									<?php if ( get_site_icon_url( 512 ) ) : ?>
										<strong><?php esc_html_e( 'WordPress Site Icon is available.', 'dsa' ); ?></strong>
										<p class="description"><?php esc_html_e( 'Kiwe publishes 192px and 512px manifest icons. The browser still decides whether and when to expose its native install prompt.', 'dsa' ); ?></p>
									<?php else : ?>
										<strong><?php esc_html_e( 'Set a square WordPress Site Icon before production.', 'dsa' ); ?></strong>
										<p class="description"><?php esc_html_e( 'A theme logo fallback may not satisfy browser installability checks. Use a clear square image of at least 512 by 512 pixels.', 'dsa' ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Platform App Badges', 'dsa' ); ?></h2>
					<p><?php esc_html_e( 'Blank links use the PWA install experience. Add a native store link only when a real published app exists.', 'dsa' ); ?></p>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'iPhone / iPad', 'dsa' ); ?></th>
								<td><input class="regular-text" type="url" name="app[ios_url]" value="<?php echo esc_url( (string) ( $app['ios_url'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Blank uses Add to Home Screen guidance', 'dsa' ); ?>"></td>
							</tr>
							<?php foreach ( [ 'playstore' => __( 'Google Play URL', 'dsa' ), 'android' => __( 'Direct Android URL', 'dsa' ) ] as $id => $fallback_label ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $fallback_label ); ?></th>
									<td>
										<input class="regular-text" type="url" name="app[<?php echo esc_attr( $id ); ?>_url]" value="<?php echo esc_url( (string) ( $app[ $id . '_url' ] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Optional', 'dsa' ); ?>">
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Appsite Events', 'dsa' ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Idle Home Screen', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label>
										<span><?php esc_html_e( 'Enabled', 'dsa' ); ?></span>
										<input type="checkbox" name="app[idle_enabled]" value="1" <?php checked( ! empty( $app['idle_enabled'] ) ); ?>>
									</label>
									<label>
										<span><?php esc_html_e( 'Delay seconds', 'dsa' ); ?></span>
										<input class="small-text" type="number" min="10" max="1800" step="5" name="app[idle_delay_seconds]" value="<?php echo esc_attr( (string) max( 10, (int) round( (int) ( $app['idle_delay_ms'] ?? 60000 ) / 1000 ) ) ); ?>">
									</label>
									<p class="description"><?php esc_html_e( 'Calls the Appsite home DSA screen after the visitor has been idle. Example: 60 seconds.', 'dsa' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Kiwe App', 'dsa' ) ); ?>
				</form>
			</section>

			<?php $this->render_app_permission_settings( $permissions ); ?>
		</div>
		<?php
	}

	private function render_app_permission_settings( array $permissions ): void {
		?>
		<section class="dsa-admin__panel">
			<h2><?php esc_html_e( 'PWA and Permission Journeys', 'dsa' ); ?></h2>
			<p><?php esc_html_e( 'App installation and browser notifications are separate earned journeys. Kiwe never asks repeatedly in one session or during protected checkout and account flows.', 'dsa' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dsa_save_settings">
				<input type="hidden" name="_dsa_redirect" value="kiwe-app">
				<input type="hidden" name="permissions[_present]" value="1">
				<input type="hidden" name="permissions[location_enabled]" value="<?php echo ! empty( $permissions['location_enabled'] ) ? '1' : '0'; ?>">
				<input type="hidden" name="permissions[camera_enabled]" value="<?php echo ! empty( $permissions['camera_enabled'] ) ? '1' : '0'; ?>">
				<?php wp_nonce_field( 'dsa_save_settings' ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e( 'Journey guard', 'dsa' ); ?></th><td class="dsa-admin-permission-grid">
						<label><input type="checkbox" name="permissions[enabled]" value="1" <?php checked( ! empty( $permissions['enabled'] ) ); ?>> <?php esc_html_e( 'Enable Permission Journey Manager', 'dsa' ); ?></label>
						<label><span><?php esc_html_e( 'Cooldown hours', 'dsa' ); ?></span><input type="number" min="1" max="720" name="permissions[cooldown_hours]" value="<?php echo esc_attr( (string) ( $permissions['cooldown_hours'] ?? 24 ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Retention days', 'dsa' ); ?></span><input type="number" min="1" max="90" name="permissions[retention_days]" value="<?php echo esc_attr( (string) ( $permissions['retention_days'] ?? 30 ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Asks per session', 'dsa' ); ?></span><input type="number" min="1" max="5" name="permissions[max_asks_per_session]" value="<?php echo esc_attr( (string) ( $permissions['max_asks_per_session'] ?? 1 ) ); ?>"></label>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'PWA install', 'dsa' ); ?></th><td class="dsa-admin-permission-grid">
						<label><input type="checkbox" name="permissions[pwa_enabled]" value="1" <?php checked( ! empty( $permissions['pwa_enabled'] ) ); ?>> <?php esc_html_e( 'Gate app install through earned trust', 'dsa' ); ?></label>
						<label><input type="checkbox" name="permissions[offline_editorial_enabled]" value="1" <?php checked( ! empty( $permissions['offline_editorial_enabled'] ) ); ?>> <?php esc_html_e( 'Enable the S17 public editorial offline pilot', 'dsa' ); ?></label>
						<label><span><?php esc_html_e( 'Title', 'dsa' ); ?></span><input type="text" name="permissions[pwa_title]" value="<?php echo esc_attr( (string) ( $permissions['pwa_title'] ?? '' ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Message', 'dsa' ); ?></span><input type="text" name="permissions[pwa_message]" value="<?php echo esc_attr( (string) ( $permissions['pwa_message'] ?? '' ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Home views', 'dsa' ); ?></span><input type="number" min="0" max="20" name="permissions[pwa_min_home_views]" value="<?php echo esc_attr( (string) ( $permissions['pwa_min_home_views'] ?? 1 ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Dock opens', 'dsa' ); ?></span><input type="number" min="0" max="20" name="permissions[pwa_min_dock_opens]" value="<?php echo esc_attr( (string) ( $permissions['pwa_min_dock_opens'] ?? 1 ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Transitions', 'dsa' ); ?></span><input type="number" min="0" max="50" name="permissions[pwa_min_transition_completes]" value="<?php echo esc_attr( (string) ( $permissions['pwa_min_transition_completes'] ?? 1 ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Games completed', 'dsa' ); ?></span><input type="number" min="0" max="20" name="permissions[pwa_min_game_completes]" value="<?php echo esc_attr( (string) ( $permissions['pwa_min_game_completes'] ?? 0 ) ); ?>"></label>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Browser notifications', 'dsa' ); ?></th><td class="dsa-admin-permission-grid">
						<label><input type="checkbox" name="permissions[notifications_enabled]" value="1" <?php checked( ! empty( $permissions['notifications_enabled'] ) ); ?>> <?php esc_html_e( 'Enable explicit browser notification requests', 'dsa' ); ?></label>
						<label><input type="checkbox" name="permissions[notification_preferences_enabled]" value="1" <?php checked( ! empty( $permissions['notification_preferences_enabled'] ) ); ?>> <?php esc_html_e( 'Enable Personalize your Appsite', 'dsa' ); ?></label>
						<label><input type="checkbox" name="permissions[notification_order_prompt_enabled]" value="1" <?php checked( ! empty( $permissions['notification_order_prompt_enabled'] ) ); ?>> <?php esc_html_e( 'Offer order-status notifications after checkout', 'dsa' ); ?></label>
						<label><span><?php esc_html_e( 'Title', 'dsa' ); ?></span><input type="text" name="permissions[notifications_title]" value="<?php echo esc_attr( (string) ( $permissions['notifications_title'] ?? '' ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Message', 'dsa' ); ?></span><input type="text" name="permissions[notifications_message]" value="<?php echo esc_attr( (string) ( $permissions['notifications_message'] ?? '' ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Unavailable product button', 'dsa' ); ?></span><input type="text" name="permissions[notification_cta_label]" value="<?php echo esc_attr( (string) ( $permissions['notification_cta_label'] ?? 'Notify me' ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Button color', 'dsa' ); ?></span><select name="permissions[notification_cta_color]"><option value="active" <?php selected( $permissions['notification_cta_color'] ?? 'active', 'active' ); ?>><?php esc_html_e( 'Active color', 'dsa' ); ?></option><option value="hover" <?php selected( $permissions['notification_cta_color'] ?? 'active', 'hover' ); ?>><?php esc_html_e( 'Hover color', 'dsa' ); ?></option></select></label>
						<label><span><?php esc_html_e( 'AI popup seconds', 'dsa' ); ?></span><input type="number" min="2" max="15" step="0.5" name="permissions[ai_popup_duration_seconds]" value="<?php echo esc_attr( (string) ( (int) ( $permissions['ai_popup_duration_ms'] ?? 3200 ) / 1000 ) ); ?>"></label>
					</td></tr>
				</tbody></table>
				<?php submit_button( __( 'Save PWA and permissions', 'dsa' ) ); ?>
			</form>
		</section>
		<?php
	}

	public function render_games_page(): void {
		$settings = $this->settings->all();
		$games = wp_parse_args( $settings['games'] ?? [], $this->settings->defaults()['games'] );
		$theme = wp_parse_args( $settings['dsa_theme'] ?? [], $this->settings->defaults()['dsa_theme'] );
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe Games', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Configure when games appear, how attempts are verified, and which WooCommerce rewards may be issued.', 'dsa' ); ?></p>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Game settings saved.', 'dsa' ); ?></p></div><?php endif; ?>
			<section class="dsa-admin__panel"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dsa_save_settings"><input type="hidden" name="_dsa_redirect" value="kiwe-games">
				<?php wp_nonce_field( 'dsa_save_settings' ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e( 'Game Surface', 'dsa' ); ?></th><td class="dsa-admin-game-bonuses">
						<label><input type="checkbox" name="games[surface_enabled]" value="1" <?php checked( ! empty( $games['surface_enabled'] ) ); ?>> <?php esc_html_e( 'Enable scheduled game Surface', 'dsa' ); ?></label>
						<label><input type="checkbox" name="games[show_on_page_load]" value="1" <?php checked( ! empty( $games['show_on_page_load'] ) ); ?>> <?php esc_html_e( 'Show on matching page load', 'dsa' ); ?></label>
						<div class="dsa-admin-game-row">
							<label><span><?php esc_html_e( 'Path contains', 'dsa' ); ?></span><input type="text" name="games[trigger_path]" value="<?php echo esc_attr( (string) ( $games['trigger_path'] ?? '/shop' ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Game', 'dsa' ); ?></span><select name="games[trigger_game]"><option value="dino" <?php selected( $games['trigger_game'] ?? 'dino', 'dino' ); ?>><?php esc_html_e( 'Dinosaur Jump', 'dsa' ); ?></option><option value="star" <?php selected( $games['trigger_game'] ?? 'dino', 'star' ); ?>><?php esc_html_e( 'Star Shooter', 'dsa' ); ?></option></select></label>
							<label><span><?php esc_html_e( 'Auto-close ms', 'dsa' ); ?></span><input type="number" min="0" max="60000" name="games[duration_ms]" value="<?php echo esc_attr( (string) ( $games['duration_ms'] ?? 0 ) ); ?>"></label>
						</div>
						<div class="dsa-admin-game-row">
							<label><span><?php esc_html_e( 'Start title', 'dsa' ); ?></span><input type="text" name="games[start_title]" value="<?php echo esc_attr( (string) ( $games['start_title'] ?? '' ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Desktop text', 'dsa' ); ?></span><input type="text" name="games[start_text]" value="<?php echo esc_attr( (string) ( $games['start_text'] ?? '' ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Mobile text', 'dsa' ); ?></span><input type="text" name="games[mobile_start_text]" value="<?php echo esc_attr( (string) ( $games['mobile_start_text'] ?? '' ) ); ?>"></label>
						</div>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Verified rewards', 'dsa' ); ?></th><td class="dsa-admin-game-bonuses">
						<label><input type="checkbox" name="games[rewards_enabled]" value="1" <?php checked( ! empty( $games['rewards_enabled'] ) ); ?>> <?php esc_html_e( 'Enable server-verified rewards', 'dsa' ); ?></label>
						<label><input type="checkbox" name="games[coupon_enabled]" value="1" <?php checked( ! empty( $games['coupon_enabled'] ) ); ?>> <?php esc_html_e( 'Generate WooCommerce coupons', 'dsa' ); ?></label>
						<div class="dsa-admin-game-row">
							<label><span><?php esc_html_e( 'Daily attempts', 'dsa' ); ?></span><input type="number" min="1" max="10" name="games[max_attempts_per_day]" value="<?php echo esc_attr( (string) ( $games['max_attempts_per_day'] ?? 3 ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Coupon expiry minutes', 'dsa' ); ?></span><input type="number" min="5" max="1440" name="games[coupon_expiry_minutes]" value="<?php echo esc_attr( (string) ( $games['coupon_expiry_minutes'] ?? 20 ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Daily coupon budget', 'dsa' ); ?></span><input type="number" min="1" max="100000" name="games[daily_coupon_budget]" value="<?php echo esc_attr( (string) ( $games['daily_coupon_budget'] ?? 100 ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Minimum play ms', 'dsa' ); ?></span><input type="number" min="1000" max="30000" name="games[min_play_ms]" value="<?php echo esc_attr( (string) ( $games['min_play_ms'] ?? 4000 ) ); ?>"></label>
						</div>
						<?php for ( $i = 0; $i < 3; $i++ ) : $bonus = $games['bonuses'][ $i ] ?? []; ?><div class="dsa-admin-game-row">
							<label><span><?php esc_html_e( 'Label', 'dsa' ); ?></span><input type="text" name="games[bonuses][<?php echo esc_attr( (string) $i ); ?>][label]" value="<?php echo esc_attr( (string) ( $bonus['label'] ?? '' ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Discount %', 'dsa' ); ?></span><input type="number" min="0" max="100" name="games[bonuses][<?php echo esc_attr( (string) $i ); ?>][discount]" value="<?php echo esc_attr( (string) ( $bonus['discount'] ?? 0 ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Retry text', 'dsa' ); ?></span><input type="text" name="games[retry_texts][<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $games['retry_texts'][ $i ] ?? '' ) ); ?>"></label>
						</div><?php endfor; ?>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Celebration', 'dsa' ); ?></th><td><label><input type="checkbox" name="games[confetti_enabled]" value="1" <?php checked( ! empty( $games['confetti_enabled'] ) ); ?>> <?php esc_html_e( 'Confetti after the maximum reward', 'dsa' ); ?></label> <select name="dsa_theme[confetti_color_source]"><option value="hero" <?php selected( $theme['confetti_color_source'] ?? 'hero', 'hero' ); ?>><?php esc_html_e( 'Hero color', 'dsa' ); ?></option><option value="active" <?php selected( $theme['confetti_color_source'] ?? 'hero', 'active' ); ?>><?php esc_html_e( 'Active color', 'dsa' ); ?></option><option value="hover" <?php selected( $theme['confetti_color_source'] ?? 'hero', 'hover' ); ?>><?php esc_html_e( 'Hover color', 'dsa' ); ?></option></select></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Save Games', 'dsa' ) ); ?>
			</form></section>
		</div>
		<?php
	}

	public function render_links_page(): void {
		$settings = $this->settings->all();
		$link_hub = wp_parse_args( $settings['link_hub'] ?? [], $this->settings->defaults()['link_hub'] );
		$categories = get_categories( [ 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe Links', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Configure the Links DSA identity, social destinations, commerce actions, editorial rail, trust providers, and testimonial source.', 'dsa' ); ?></p>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Links settings saved.', 'dsa' ); ?></p></div><?php endif; ?>
			<section class="dsa-admin__panel"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dsa_save_settings"><input type="hidden" name="_dsa_redirect" value="kiwe-links">
				<?php wp_nonce_field( 'dsa_save_settings' ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e( 'Site score', 'dsa' ); ?></th><td><input class="small-text" type="number" min="0" max="100" name="link_hub[site_score]" value="<?php echo esc_attr( (string) ( $link_hub['site_score'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Optional', 'dsa' ); ?>"><p class="description"><?php esc_html_e( 'Leave blank to hide the score badge on the frontend.', 'dsa' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Social links', 'dsa' ); ?></th><td class="dsa-admin-link-grid"><?php foreach ( $this->social_link_labels() as $id => $label ) : ?><label><span><?php echo esc_html( $label ); ?></span><input type="url" name="link_hub[social_links][<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_url( (string) ( $link_hub['social_links'][ $id ] ?? '' ) ); ?>" placeholder="https://"></label><?php endforeach; ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Shop action', 'dsa' ); ?></th><td class="dsa-admin-inline-fields"><input type="text" name="link_hub[shop_label]" value="<?php echo esc_attr( (string) ( $link_hub['shop_label'] ?? 'Shop' ) ); ?>"><input class="regular-text" type="url" name="link_hub[shop_url]" value="<?php echo esc_url( (string) ( $link_hub['shop_url'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Blank uses WooCommerce shop', 'dsa' ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Editorial rail', 'dsa' ); ?></th><td class="dsa-admin-inline-fields"><input type="text" name="link_hub[posts_title]" value="<?php echo esc_attr( (string) ( $link_hub['posts_title'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Category name or Latest Posts', 'dsa' ); ?>"><select name="link_hub[posts_category]"><option value="0"><?php esc_html_e( 'First available category', 'dsa' ); ?></option><?php foreach ( $categories as $category ) : ?><option value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php selected( (int) ( $link_hub['posts_category'] ?? 0 ), (int) $category->term_id ); ?>><?php echo esc_html( $category->name ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Trust providers', 'dsa' ); ?></th><td class="dsa-admin-inline-fields"><input type="text" name="link_hub[ssl_provider]" value="<?php echo esc_attr( (string) ( $link_hub['ssl_provider'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'SSL provider', 'dsa' ); ?>"><input type="text" name="link_hub[payment_provider]" value="<?php echo esc_attr( (string) ( $link_hub['payment_provider'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Payment provider fallback', 'dsa' ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Reviews', 'dsa' ); ?></th><td class="dsa-admin-inline-fields"><select name="link_hub[review_source]"><option value="manual" <?php selected( $link_hub['review_source'] ?? 'manual', 'manual' ); ?>><?php esc_html_e( 'Manual testimonials', 'dsa' ); ?></option><option value="google" <?php selected( $link_hub['review_source'] ?? 'manual', 'google' ); ?>><?php esc_html_e( 'Google Places', 'dsa' ); ?></option></select><input type="text" name="link_hub[google_place_id]" value="<?php echo esc_attr( (string) ( $link_hub['google_place_id'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Google Place ID', 'dsa' ); ?>"><input type="password" name="link_hub[google_api_key]" value="" placeholder="<?php echo esc_attr__( 'Leave blank to keep saved API key', 'dsa' ); ?>" autocomplete="new-password"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Testimonials', 'dsa' ); ?></th><td><textarea class="large-text" rows="6" name="link_hub[testimonials]" placeholder="<?php echo esc_attr__( 'One testimonial per line', 'dsa' ); ?>"><?php echo esc_textarea( (string) ( $link_hub['testimonials'] ?? '' ) ); ?></textarea></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Save Links', 'dsa' ) ); ?>
			</form></section>
		</div>
		<?php
	}

	public function render_haptic_page(): void {
		$settings = $this->settings->all();
		$defaults = $this->settings->defaults()['haptic'];
		$haptic = wp_parse_args( $settings['haptic'] ?? [], $defaults );
		$events = wp_parse_args( $haptic['events'] ?? [], $defaults['events'] );
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe Haptic', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'One feedback contract for the Kiwe Surface, WooCommerce controls, and Bricks buttons. Browser and device support still decides whether vibration or audio can play.', 'dsa' ); ?></p>
			<section class="dsa-admin__panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<input type="hidden" name="_dsa_redirect" value="kiwe-haptic">
					<input type="hidden" name="haptic[_present]" value="1">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>
					<table class="form-table" role="presentation"><tbody>
						<tr><th scope="row"><?php esc_html_e( 'Feedback', 'dsa' ); ?></th><td class="dsa-admin__checks">
							<label><input type="checkbox" name="haptic[enabled]" value="1" <?php checked( ! empty( $haptic['enabled'] ) ); ?>> <?php esc_html_e( 'Enable Kiwe feedback', 'dsa' ); ?></label>
							<label><input type="checkbox" name="haptic[vibration_enabled]" value="1" <?php checked( ! empty( $haptic['vibration_enabled'] ) ); ?>> <?php esc_html_e( 'Vibration when supported', 'dsa' ); ?></label>
							<label><input type="checkbox" name="haptic[sound_enabled]" value="1" <?php checked( ! empty( $haptic['sound_enabled'] ) ); ?>> <?php esc_html_e( 'Sound when browser audio policy allows it', 'dsa' ); ?></label>
						</td></tr>
						<tr><th scope="row"><label for="kiwe-haptic-context"><?php esc_html_e( 'Where', 'dsa' ); ?></label></th><td><select id="kiwe-haptic-context" name="haptic[context]">
							<option value="both" <?php selected( $haptic['context'] ?? 'both', 'both' ); ?>><?php esc_html_e( 'Website and installed Appsite', 'dsa' ); ?></option>
							<option value="website" <?php selected( $haptic['context'] ?? 'both', 'website' ); ?>><?php esc_html_e( 'Website only', 'dsa' ); ?></option>
							<option value="appsite" <?php selected( $haptic['context'] ?? 'both', 'appsite' ); ?>><?php esc_html_e( 'Installed Appsite only', 'dsa' ); ?></option>
						</select></td></tr>
						<tr><th scope="row"><label for="kiwe-haptic-sound"><?php esc_html_e( 'Sound', 'dsa' ); ?></label></th><td><select id="kiwe-haptic-sound" name="haptic[sound_profile]">
							<option value="soft" <?php selected( $haptic['sound_profile'] ?? 'soft', 'soft' ); ?>><?php esc_html_e( 'Soft', 'dsa' ); ?></option>
							<option value="bright" <?php selected( $haptic['sound_profile'] ?? 'soft', 'bright' ); ?>><?php esc_html_e( 'Bright', 'dsa' ); ?></option>
							<option value="pop" <?php selected( $haptic['sound_profile'] ?? 'soft', 'pop' ); ?>><?php esc_html_e( 'Pop', 'dsa' ); ?></option>
							<option value="bell" <?php selected( $haptic['sound_profile'] ?? 'soft', 'bell' ); ?>><?php esc_html_e( 'Bell', 'dsa' ); ?></option>
						</select><p class="description"><?php esc_html_e( 'The selection changes tone while each event keeps its own rhythm.', 'dsa' ); ?></p></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Events', 'dsa' ); ?></th><td class="dsa-admin__checks">
							<label><input type="checkbox" name="haptic[events][buttons]" value="1" <?php checked( ! empty( $events['buttons'] ) ); ?>> <?php esc_html_e( 'Buttons, including Bricks buttons', 'dsa' ); ?></label>
							<label><input type="checkbox" name="haptic[events][quantity]" value="1" <?php checked( ! empty( $events['quantity'] ) ); ?>> <?php esc_html_e( 'Cart quantity controls', 'dsa' ); ?></label>
							<label><input type="checkbox" name="haptic[events][swipe_back]" value="1" <?php checked( ! empty( $events['swipe_back'] ) ); ?>> <?php esc_html_e( 'Back gesture closing a DSA screen', 'dsa' ); ?></label>
							<label><input type="checkbox" name="haptic[events][notifications]" value="1" <?php checked( ! empty( $events['notifications'] ) ); ?>> <?php esc_html_e( 'AI and Push notifications', 'dsa' ); ?></label>
						</td></tr>
					</tbody></table>
					<?php submit_button( __( 'Save Haptic Settings', 'dsa' ) ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	private function universal_tokens(): array {
		$settings = $this->settings->all();
		$theme = is_array( $settings['dsa_theme'] ?? null ) ? $settings['dsa_theme'] : [];
		$visual = is_array( $settings['visual_effects'] ?? null ) ? $settings['visual_effects'] : [];
		$overrides = [
			'color-brand' => (string) ( $theme['active_color'] ?? '#d6006f' ),
			'color-accent' => (string) ( $theme['hover_color'] ?? '#24c6a1' ),
			'color-hero'   => (string) ( $theme['hero_text_color'] ?? 'rgba(20,24,34,0.18)' ),
			'glass-blur'   => max( 0, min( 24, absint( $visual['blur_strength'] ?? 10 ) ) ) . 'px',
		];

		return Seam_Token_Service::tokens_with_overrides( $overrides );
	}

	public function render_framework_page(): void {
		$items    = $this->universal_tokens();
		$counts   = Seam_Token_Service::counts( $items );
		$class_export = Seam_Token_Service::framework_classes_for_bricks();
		$class_count  = isset( $class_export['classes'] ) && is_array( $class_export['classes'] ) ? count( $class_export['classes'] ) : 0;
		$binding_report = $this->framework_binding_report();
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe Framework', 'dsa' ); ?></h1>
			<?php if ( isset( $_GET['tokens-exported'] ) ) : ?>
				<?php $export_status = sanitize_key( (string) wp_unslash( $_GET['tokens-exported'] ) ); ?>
				<?php if ( 'bricks' === $export_status ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Kiwe Framework was pushed to Bricks as additive kiwe-* variables, the Kiwe Universal color palette, and the neutral Seam Class Vocabulary. Existing non-Kiwe Bricks variables, classes, and palettes were left untouched.', 'dsa' ); ?></p></div>
				<?php elseif ( 'no-bricks' === $export_status ) : ?>
					<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Bricks framework storage was not available. Use the JSON download and import it after Bricks is active.', 'dsa' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( isset( $_GET['site-graph-exported'] ) && 'encode-error' === sanitize_key( (string) wp_unslash( $_GET['site-graph-exported'] ) ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Kiwe could not encode the Site Graph JSON. Try a smaller sample size and check server logs if it continues.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['binding-plan'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $this->binding_plan_error_message( sanitize_key( (string) wp_unslash( $_GET['binding-plan'] ) ) ) ); ?></p></div>
			<?php endif; ?>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Kiwe Framework for builders', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Kiwe ships one universal framework vocabulary. The DSA Surface consumes these same kiwe-* variables and Seam classes, and web designers can push the active framework into Bricks as additive variables, a Kiwe Universal color palette, and a searchable neutral Seam Class Vocabulary. Existing Bricks token sets and classes are not overwritten.', 'dsa' ); ?></p>
				<p class="description"><?php esc_html_e( 'SEAM informs the framework: fluid type, spacing, radius, scene density, motion, semantic colors, layout geometry, neutral component naming, and variant grammar. The class vocabulary is searchable naming infrastructure for Bricks styling, not a starter visual recipe kit. Legacy dsa-* variables remain compatibility aliases only; Kiwe Framework is the public contract.', 'dsa' ); ?></p>
				<div class="dsa-admin-token-summary">
					<div><strong><?php echo esc_html( (string) count( $items ) ); ?></strong><span><?php esc_html_e( 'framework tokens', 'dsa' ); ?></span></div>
					<div><strong><?php echo esc_html( (string) $class_count ); ?></strong><span><?php esc_html_e( 'Bricks classes', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Built in', 'dsa' ); ?></strong><span><?php esc_html_e( 'source', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Additive', 'dsa' ); ?></strong><span><?php esc_html_e( 'Bricks framework push', 'dsa' ); ?></span></div>
				</div>
				<?php if ( [] !== $counts ) : ?>
					<p class="dsa-admin-token-chips">
						<?php foreach ( $counts as $type => $count ) : ?>
							<span><?php echo esc_html( $type . ': ' . $count ); ?></span>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_apply_bricks_tokens">
					<?php wp_nonce_field( 'dsa_apply_bricks_tokens' ); ?>
					<?php submit_button( __( 'Push Kiwe Framework to Bricks', 'dsa' ), 'primary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_export_bricks_tokens">
					<?php wp_nonce_field( 'dsa_export_bricks_tokens' ); ?>
					<?php submit_button( __( 'Download Bricks Framework JSON', 'dsa' ), 'secondary', 'submit', false ); ?>
				</form>

				<section class="dsa-admin__panel" style="margin-top: 1rem;">
					<h2><?php esc_html_e( 'AI connector and Site Graph', 'dsa' ); ?></h2>
					<p><?php esc_html_e( 'Use this when an AI or web developer needs to turn an approved Seam/Kiwe design into Bricks query loops, dynamic data, and Kiwe AppShell launchers for this exact WordPress site. The Site Graph is admin-only, read-only, and contains no secrets, visitor state, orders, payment data, or credentials.', 'dsa' ); ?></p>
					<div class="dsa-admin-token-summary">
						<div><strong><?php esc_html_e( 'Read-only', 'dsa' ); ?></strong><span><?php esc_html_e( 'Site Graph', 'dsa' ); ?></span></div>
						<div><strong><?php esc_html_e( 'v1', 'dsa' ); ?></strong><span><?php esc_html_e( 'schema', 'dsa' ); ?></span></div>
						<div><strong><?php esc_html_e( 'Admin', 'dsa' ); ?></strong><span><?php esc_html_e( 'access', 'dsa' ); ?></span></div>
						<div><strong><?php esc_html_e( 'No write', 'dsa' ); ?></strong><span><?php esc_html_e( 'authority', 'dsa' ); ?></span></div>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dsa_export_site_graph">
						<?php wp_nonce_field( 'dsa_export_site_graph' ); ?>
						<p class="dsa-admin-inline-fields">
							<label>
								<span><?php esc_html_e( 'Samples per content type', 'dsa' ); ?></span>
								<select name="sampleLimit">
									<?php foreach ( [ 0, 4, 8, 16, 24 ] as $limit ) : ?>
										<option value="<?php echo esc_attr( (string) $limit ); ?>" <?php selected( 8, $limit ); ?>><?php echo esc_html( (string) $limit ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<?php submit_button( __( 'Download Site Graph JSON', 'dsa' ), 'secondary', 'submit', false ); ?>
						</p>
					</form>
					<h3><?php esc_html_e( 'Validate AI binding plan', 'dsa' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Upload an AI-produced bricks-bindings/kiwe-bindings.json file. Kiwe validates it against this site\'s current Site Graph and shows a report only; it does not save Bricks data or apply changes.', 'dsa' ); ?></p>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dsa_validate_binding_plan">
						<?php wp_nonce_field( 'dsa_validate_binding_plan' ); ?>
						<p class="dsa-admin-inline-fields">
							<label>
								<span><?php esc_html_e( 'Binding JSON', 'dsa' ); ?></span>
								<input type="file" name="dsa_binding_file" accept="application/json,.json" required>
							</label>
							<label>
								<span><?php esc_html_e( 'Site Graph sample size', 'dsa' ); ?></span>
								<select name="sampleLimit">
									<?php foreach ( [ 0, 4, 8, 16, 24 ] as $limit ) : ?>
										<option value="<?php echo esc_attr( (string) $limit ); ?>" <?php selected( 8, $limit ); ?>><?php echo esc_html( (string) $limit ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<?php submit_button( __( 'Validate Binding Plan', 'dsa' ), 'secondary', 'submit', false ); ?>
						</p>
					</form>
					<?php $this->render_binding_plan_report( $binding_report ); ?>
					<p class="description"><?php esc_html_e( 'REST endpoint for tool clients:', 'dsa' ); ?> <code><?php echo esc_html( rest_url( 'dsa/v1/site-graph?sampleLimit=8' ) ); ?></code></p>
					<p class="description"><?php esc_html_e( 'Safe AI sequence: generate/audit handoff, add bricks-bindings/ with the Site Graph, run validate-bindings, then prepare a dry-run apply plan. Do not let browser AI claim it saved Bricks or WordPress.', 'dsa' ); ?></p>
					<ul class="ul-disc">
						<li><code>https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md</code></li>
						<li><code>https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/dynamic-lite.md</code></li>
					</ul>
				</section>
				<div class="dsa-admin-token-grid">
					<?php foreach ( $items as $token ) : ?>
						<?php $slider = Seam_Token_Service::slider_value( is_array( $token ) ? $token : [] ); ?>
						<article class="dsa-admin-token-card">
							<header>
								<code><?php echo esc_html( (string) ( $token['cssVar'] ?? '' ) ); ?></code>
								<span><?php echo esc_html( (string) ( $token['type'] ?? 'project' ) ); ?></span>
							</header>
							<strong><?php echo esc_html( (string) ( $token['value'] ?? '' ) ); ?></strong>
							<?php if ( ! empty( $token['description'] ) ) : ?>
								<p><?php echo esc_html( (string) $token['description'] ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $token['seamAlias'] ) ) : ?>
								<small><?php echo esc_html( sprintf( __( 'SEAM reference: %s', 'dsa' ), (string) $token['seamAlias'] ) ); ?></small>
							<?php endif; ?>
							<?php if ( 'color' === ( $token['type'] ?? '' ) && preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) ( $token['value'] ?? '' ) ) ) : ?>
								<i style="background: <?php echo esc_attr( (string) $token['value'] ); ?>"></i>
							<?php endif; ?>
							<?php if ( is_array( $slider ) ) : ?>
								<input type="range" min="0" max="<?php echo esc_attr( (string) $slider['max'] ); ?>" value="<?php echo esc_attr( (string) $slider['value'] ); ?>" disabled>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
		</div>
		<?php
	}

	private function render_binding_plan_report( array $payload ): void {
		if ( [] === $payload ) {
			return;
		}

		$report   = isset( $payload['report'] ) && is_array( $payload['report'] ) ? $payload['report'] : [];
		$summary  = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : [];
		$counts   = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : [];
		$findings = isset( $report['findings'] ) && is_array( $report['findings'] ) ? $report['findings'] : [];
		$apply_plan = isset( $payload['applyPlan'] ) && is_array( $payload['applyPlan'] ) ? $payload['applyPlan'] : [];
		$report_key = isset( $payload['key'] ) ? sanitize_key( (string) $payload['key'] ) : '';
		$ok       = ! empty( $report['ok'] );
		?>
		<div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>" style="margin: 1rem 0;">
			<p>
				<strong><?php echo $ok ? esc_html__( 'Binding plan passed runtime validation.', 'dsa' ) : esc_html__( 'Binding plan needs fixes before any apply path.', 'dsa' ); ?></strong>
				<?php echo esc_html( sprintf( __( 'File: %1$s. Site Graph samples: %2$d.', 'dsa' ), (string) ( $payload['fileName'] ?? 'kiwe-bindings.json' ), (int) ( $payload['sampleLimit'] ?? 0 ) ) ); ?>
			</p>
		</div>
		<div class="dsa-admin-token-summary">
			<div><strong><?php echo esc_html( (string) ( $summary['queries'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'queries', 'dsa' ); ?></span></div>
			<div><strong><?php echo esc_html( (string) ( $summary['dynamicFields'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'dynamic fields', 'dsa' ); ?></span></div>
			<div><strong><?php echo esc_html( (string) ( $summary['launchers'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'launchers', 'dsa' ); ?></span></div>
			<div><strong><?php echo esc_html( (string) ( $summary['menuContext'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'menu context', 'dsa' ); ?></span></div>
			<div><strong><?php echo esc_html( (string) ( $counts['fail'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'failures', 'dsa' ); ?></span></div>
			<div><strong><?php echo esc_html( (string) ( $counts['warn'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'warnings', 'dsa' ); ?></span></div>
		</div>
		<?php if ( [] !== $findings ) : ?>
			<ul class="ul-disc">
				<?php foreach ( $findings as $finding ) : ?>
					<?php
					if ( ! is_array( $finding ) ) {
						continue;
					}
					$level   = sanitize_key( (string) ( $finding['level'] ?? 'info' ) );
					$message = (string) ( $finding['message'] ?? '' );
					if ( '' === $message ) {
						continue;
					}
					?>
					<li><strong><?php echo esc_html( strtoupper( $level ) ); ?>:</strong> <?php echo esc_html( $message ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No validator findings were reported. This still does not mutate WordPress or Bricks; review the dry-run apply plan below before any future adapter path.', 'dsa' ); ?></p>
		<?php endif; ?>
		<?php
		$this->render_apply_plan_preview( $apply_plan, $report_key );
	}

	private function render_apply_plan_preview( array $apply_plan, string $report_key = '' ): void {
		if ( [] === $apply_plan ) {
			return;
		}

		$capabilities  = isset( $apply_plan['siteCapabilities'] ) && is_array( $apply_plan['siteCapabilities'] ) ? $apply_plan['siteCapabilities'] : [];
		$preflight     = isset( $apply_plan['preflight'] ) && is_array( $apply_plan['preflight'] ) ? $apply_plan['preflight'] : [];
		$operations    = isset( $apply_plan['operations'] ) && is_array( $apply_plan['operations'] ) ? $apply_plan['operations'] : [];
		$manual_review = isset( $apply_plan['manualReview'] ) && is_array( $apply_plan['manualReview'] ) ? $apply_plan['manualReview'] : [];
		$operation_count = count( $operations );
		$review_count    = count( $manual_review );
		$visible_operations = array_slice( $operations, 0, 20 );
		$hidden_operations  = max( 0, $operation_count - count( $visible_operations ) );
		?>
		<div class="dsa-lpm-card" style="margin-top: 1rem;">
			<h3><?php esc_html_e( 'Dry-run apply plan', 'dsa' ); ?></h3>
			<p class="description"><?php esc_html_e( 'This preview translates the validated binding file into the operations a future trusted Kiwe/Bricks adapter would review. It is non-mutating and does not save WordPress, WooCommerce, or Bricks content.', 'dsa' ); ?></p>
			<?php if ( '' !== $report_key ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0 0 1rem;">
					<input type="hidden" name="action" value="dsa_download_apply_plan">
					<input type="hidden" name="bindingReport" value="<?php echo esc_attr( $report_key ); ?>">
					<?php wp_nonce_field( 'dsa_download_apply_plan_' . $report_key ); ?>
					<button class="button button-secondary" type="submit"><?php esc_html_e( 'Download dry-run apply plan JSON', 'dsa' ); ?></button>
				</form>
			<?php endif; ?>
			<div class="dsa-admin-token-summary">
				<div><strong><?php echo esc_html( (string) $operation_count ); ?></strong><span><?php esc_html_e( 'planned operations', 'dsa' ); ?></span></div>
				<div><strong><?php echo esc_html( (string) $review_count ); ?></strong><span><?php esc_html_e( 'review items', 'dsa' ); ?></span></div>
				<div><strong><?php echo ! empty( $capabilities['bricksActive'] ) ? esc_html__( 'Yes', 'dsa' ) : esc_html__( 'No', 'dsa' ); ?></strong><span><?php esc_html_e( 'Bricks active', 'dsa' ); ?></span></div>
				<div><strong><?php echo ! empty( $capabilities['trustedAdapterLikelyAvailable'] ) ? esc_html__( 'Likely', 'dsa' ) : esc_html__( 'Manual', 'dsa' ); ?></strong><span><?php esc_html_e( 'adapter path', 'dsa' ); ?></span></div>
				<div><strong><?php echo ! empty( $capabilities['htmlCssToBricksAvailable'] ) ? esc_html__( 'Yes', 'dsa' ) : esc_html__( 'No', 'dsa' ); ?></strong><span><?php esc_html_e( 'HTML/CSS import', 'dsa' ); ?></span></div>
				<div><strong><?php esc_html_e( 'Dry run', 'dsa' ); ?></strong><span><?php esc_html_e( 'save mode', 'dsa' ); ?></span></div>
			</div>

			<?php if ( [] !== $preflight ) : ?>
				<h4><?php esc_html_e( 'Preflight gates', 'dsa' ); ?></h4>
				<ul class="ul-disc">
					<?php foreach ( $preflight as $checkpoint ) : ?>
						<?php
						if ( ! is_array( $checkpoint ) ) {
							continue;
						}
						$label   = (string) ( $checkpoint['label'] ?? $checkpoint['id'] ?? '' );
						$status  = (string) ( $checkpoint['status'] ?? '' );
						$details = (string) ( $checkpoint['details'] ?? '' );
						if ( '' === $label ) {
							continue;
						}
						?>
						<li><strong><?php echo esc_html( $label ); ?></strong> <code><?php echo esc_html( $status ); ?></code><?php echo '' !== $details ? ' - ' . esc_html( $details ) : ''; ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( [] !== $visible_operations ) : ?>
				<h4><?php esc_html_e( 'Prepared operations', 'dsa' ); ?></h4>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Type', 'dsa' ); ?></th><th><?php esc_html_e( 'Label', 'dsa' ); ?></th><th><?php esc_html_e( 'Target', 'dsa' ); ?></th><th><?php esc_html_e( 'Status', 'dsa' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $visible_operations as $operation ) : ?>
						<?php
						if ( ! is_array( $operation ) ) {
							continue;
						}
						$type     = (string) ( $operation['type'] ?? '' );
						$label    = (string) ( $operation['label'] ?? $operation['id'] ?? '' );
						$selector = (string) ( $operation['selector'] ?? $operation['module'] ?? $operation['tag'] ?? '' );
						$status   = (string) ( $operation['status'] ?? '' );
						?>
						<tr>
							<td><code><?php echo esc_html( $type ); ?></code></td>
							<td><?php echo esc_html( $label ); ?></td>
							<td><?php echo esc_html( $selector ); ?></td>
							<td><?php echo esc_html( $status ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $hidden_operations > 0 ) : ?>
					<p class="description"><?php echo esc_html( sprintf( __( 'Showing first 20 operations; %d more are present in the dry-run plan.', 'dsa' ), $hidden_operations ) ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No prepared operations were produced from this binding plan.', 'dsa' ); ?></p>
			<?php endif; ?>

			<?php if ( [] !== $manual_review ) : ?>
				<h4><?php esc_html_e( 'Manual review queue', 'dsa' ); ?></h4>
				<ul class="ul-disc">
					<?php foreach ( array_slice( $manual_review, 0, 10 ) as $review_item ) : ?>
						<?php
						if ( ! is_array( $review_item ) ) {
							continue;
						}
						$message = (string) ( $review_item['message'] ?? '' );
						$source  = (string) ( $review_item['source'] ?? '' );
						if ( '' === $message ) {
							continue;
						}
						?>
						<li><strong><?php echo esc_html( $source ); ?>:</strong> <?php echo esc_html( $message ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_app_tabs( string $active ): void {
		$tabs = [
			'settings'  => __( 'Home & Install', 'dsa' ),
			'adoption'  => __( 'App Adoption', 'dsa' ),
			'audience'  => __( 'Notification Audience', 'dsa' ),
		];
		echo '<nav class="dsa-lpm-tabs" aria-label="' . esc_attr__( 'Kiwe App sections', 'dsa' ) . '">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a class="dsa-lpm-tab %1$s" href="%2$s">%3$s</a>',
				$key === $active ? 'is-active' : '',
				esc_url( add_query_arg( [ 'page' => 'kiwe-app', 'tab' => $key ], admin_url( 'admin.php' ) ) ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	public function handle_notification_campaign(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.', 'dsa' ) );
		check_admin_referer( 'dsa_send_notification_campaign' );
		if ( ! $this->notification_campaigns ) wp_die( esc_html__( 'Notification campaign service is unavailable.', 'dsa' ) );
		$result = $this->notification_campaigns->send(
			sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ),
			sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) )
		);
		set_transient( 'dsa_notification_campaign_notice_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-app', 'tab' => 'audience' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private function render_app_audience(): void {
		if ( ! $this->notification_preferences ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Notification audience service is unavailable.', 'dsa' ) . '</p></div>';
			return;
		}
		$summary = $this->notification_preferences->audience_summary();
		if ( $this->store_analytics ) {
			$adoption = $this->store_analytics->adoption_summary( 0 );
			$summary['appUsers'] = (int) ( $adoption['appUsers'] ?? $summary['appUsers'] );
			$summary['appAnonymous'] = (int) ( $adoption['appAnonymous'] ?? $summary['appAnonymous'] );
		}
		$notice = get_transient( 'dsa_notification_campaign_notice_' . get_current_user_id() );
		delete_transient( 'dsa_notification_campaign_notice_' . get_current_user_id() );
		$channel_labels = [ 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'app' => 'Browser notification' ];
		$push_summary = $this->notification_campaigns ? $this->notification_campaigns->push_summary() : [ 'ready' => false, 'devices' => 0, 'users' => 0, 'anonymous' => 0 ];
		$summary['registeredChannels']['app'] = (int) ( $push_summary['devices'] ?? 0 );
		?>
		<section class="dsa-lpm-stack">
			<?php if ( is_array( $notice ) ) : ?><div class="notice <?php echo ! empty( $notice['ok'] ) ? 'notice-success' : 'notice-warning'; ?>"><p><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></p></div><?php endif; ?>
			<h2><?php esc_html_e( 'App identity', 'dsa' ); ?></h2>
			<div class="dsa-lpm-summary">
				<?php foreach ( [
					'appUsers' => [ __( 'App + user', 'dsa' ), __( 'Standalone app devices resolved to a WordPress or PhoneKey user', 'dsa' ) ],
					'appAnonymous' => [ __( 'App, not yet a user', 'dsa' ), __( 'Standalone app devices still waiting for PhoneKey welcome', 'dsa' ) ],
					'registered' => [ __( 'Registered audience', 'dsa' ), __( 'Preference records linked to users across app and web', 'dsa' ) ],
					'phonekeyVerified' => [ __( 'PhoneKey verified', 'dsa' ), __( 'Audience records linked to verified PhoneKey accounts', 'dsa' ) ],
				] as $key => $card ) : ?>
					<div class="dsa-lpm-stat"><span><?php echo esc_html( $card[0] ); ?></span><strong><?php echo esc_html( (string) ( $summary[ $key ] ?? 0 ) ); ?></strong><small><?php echo esc_html( $card[1] ); ?></small></div>
				<?php endforeach; ?>
			</div>

			<div class="dsa-lpm-card">
				<h2><?php esc_html_e( 'Send a notification', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Only registered users who explicitly selected the channel are addressed. Email, WhatsApp, and SMS require their configured provider and a usable account contact.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_send_notification_campaign">
					<?php wp_nonce_field( 'dsa_send_notification_campaign' ); ?>
					<p><label><strong><?php esc_html_e( 'Title', 'dsa' ); ?></strong><br><input class="large-text" name="subject" maxlength="160" required></label></p>
					<p><label><strong><?php esc_html_e( 'Message', 'dsa' ); ?></strong><br><textarea class="large-text" name="message" rows="5" required></textarea></label></p>
					<p class="dsa-admin-inline-fields">
					<?php foreach ( $channel_labels as $channel => $label ) : $count = (int) ( $summary['registeredChannels'][ $channel ] ?? 0 ); ?>
						<button class="button button-primary" type="submit" name="channel" value="<?php echo esc_attr( $channel ); ?>" <?php disabled( 0 === $count || ( 'app' === $channel && empty( $push_summary['ready'] ) ) ); ?>><?php echo esc_html( sprintf( __( 'Send as %1$s (%2$d)', 'dsa' ), $label, $count ) ); ?></button>
					<?php endforeach; ?>
					</p>
					<p class="description"><?php echo esc_html( sprintf( __( 'Browser push: %1$d active device(s), %2$d registered user(s), %3$d anonymous device(s). VAPID delivery is %4$s.', 'dsa' ), (int) ( $push_summary['devices'] ?? 0 ), (int) ( $push_summary['users'] ?? 0 ), (int) ( $push_summary['anonymous'] ?? 0 ), ! empty( $push_summary['ready'] ) ? __( 'ready', 'dsa' ) : __( 'unavailable because OpenSSL P-256 support is missing', 'dsa' ) ) ); ?></p>
					<?php $push_health = is_array( $push_summary['health'] ?? null ) ? $push_summary['health'] : []; ?>
					<p class="description"><?php echo esc_html( sprintf( __( 'Cleanup cron: %1$s. Last accepted push: %2$s. Expired subscriptions: %3$d. Devices awaiting cryptographic re-enrollment: %4$d.', 'dsa' ), ! empty( $push_health['cronScheduled'] ) ? __( 'scheduled', 'dsa' ) : __( 'missing', 'dsa' ), (string) ( ( $push_health['lastSuccess'] ?? '' ) ?: __( 'none recorded', 'dsa' ) ), (int) ( $push_health['expired'] ?? 0 ), (int) ( $push_health['reenrollRequired'] ?? 0 ) ) ); ?></p>
					<?php $secret_health = is_array( $push_health['secretStore'] ?? null ) ? $push_health['secretStore'] : []; ?>
					<p class="description"><?php echo esc_html( sprintf( __( 'Secret store v%1$d: %2$s. Active key ID: %3$s. Recovery keys configured: %4$d.', 'dsa' ), (int) ( $secret_health['version'] ?? 0 ), ! empty( $secret_health['ready'] ) ? __( 'ready', 'dsa' ) : __( 'unavailable', 'dsa' ), (string) ( ( $secret_health['keyId'] ?? '' ) ?: __( 'none', 'dsa' ) ), (int) ( $secret_health['previousKeys'] ?? 0 ) ) ); ?></p>
				</form>
			</div>

			<div class="dsa-lpm-card">
				<h2><?php esc_html_e( 'Channel and topic adoption', 'dsa' ); ?></h2>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Preference', 'dsa' ); ?></th><th><?php esc_html_e( 'All devices', 'dsa' ); ?></th><th><?php esc_html_e( 'Addressable users/devices', 'dsa' ); ?></th></tr></thead><tbody>
				<?php foreach ( $channel_labels as $channel => $label ) : ?><tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( (string) ( $summary['channels'][ $channel ] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) ( $summary['registeredChannels'][ $channel ] ?? 0 ) ); ?></td></tr><?php endforeach; ?>
				<?php foreach ( (array) ( $summary['topics'] ?? [] ) as $topic => $count ) : ?><tr><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $topic ) ) ); ?></td><td><?php echo esc_html( (string) $count ); ?></td><td><?php echo esc_html( (string) ( $summary['registeredTopics'][ $topic ] ?? 0 ) ); ?></td></tr><?php endforeach; ?>
				</tbody></table>
			</div>

			<div class="dsa-lpm-card">
				<h2><?php esc_html_e( 'Preference ledger', 'dsa' ); ?></h2>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Visitor', 'dsa' ); ?></th><th><?php esc_html_e( 'Identity', 'dsa' ); ?></th><th><?php esc_html_e( 'App', 'dsa' ); ?></th><th><?php esc_html_e( 'Channels', 'dsa' ); ?></th><th><?php esc_html_e( 'Topics', 'dsa' ); ?></th><th><?php esc_html_e( 'Selected scope', 'dsa' ); ?></th><th><?php esc_html_e( 'Updated', 'dsa' ); ?></th></tr></thead><tbody>
				<?php if ( empty( $summary['rows'] ) ) : ?><tr><td colspan="7"><?php esc_html_e( 'No notification preferences have been saved yet.', 'dsa' ); ?></td></tr><?php endif; ?>
				<?php foreach ( (array) ( $summary['rows'] ?? [] ) as $row ) : ?><tr>
					<td><code><?php echo esc_html( (string) ( $row['visitor'] ?? '' ) ); ?></code></td>
					<td><?php echo esc_html( ! empty( $row['userId'] ) ? ( (string) ( $row['userName'] ?: 'User #' . $row['userId'] ) ) : __( 'Anonymous', 'dsa' ) ); ?><?php if ( ! empty( $row['phonekeyVerified'] ) ) : ?> <small><?php esc_html_e( 'PhoneKey verified', 'dsa' ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( ! empty( $row['isApp'] ) ? __( 'Standalone', 'dsa' ) : __( 'Browser', 'dsa' ) ); ?></td>
					<td><?php echo esc_html( implode( ', ', (array) ( $row['channels'] ?? [] ) ) ?: '-' ); ?></td>
					<td><?php echo esc_html( implode( ', ', (array) ( $row['topics'] ?? [] ) ) ?: '-' ); ?></td>
					<td><?php echo esc_html( sprintf( 'Products: %s | Product categories: %s | Post categories: %s', implode( ', ', (array) ( $row['productIds'] ?? [] ) ) ?: '-', implode( ', ', (array) ( $row['productCategories'] ?? [] ) ) ?: '-', implode( ', ', (array) ( $row['postCategories'] ?? [] ) ) ?: '-' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['updatedAt'] ?? '' ) ); ?></td>
				</tr><?php endforeach; ?>
				</tbody></table>
			</div>
		</section>
		<?php
	}

	private function render_app_developer_reference(): void {
		?>
		<section class="dsa-admin__panel">
			<h2><?php esc_html_e( 'Browser Notification Trigger', 'dsa' ); ?></h2>
			<p><?php esc_html_e( 'Add the attribute to a real button, link, or Bricks element. Kiwe requests browser permission only after that explicit visitor click and never during a protected flow.', 'dsa' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Attribute', 'dsa' ); ?></th><th><?php esc_html_e( 'Purpose', 'dsa' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>data-kiwe-notifications</code></td><td><?php esc_html_e( 'Starts the browser-notification permission journey.', 'dsa' ); ?></td></tr>
					<tr><td><code>data-kiwe-notification-status-target="#notification-status"</code></td><td><?php esc_html_e( 'Optional selector for an on-page element that receives the current permission message.', 'dsa' ); ?></td></tr>
					<tr><td><code>data-kiwe-save="wishlist"</code></td><td><?php esc_html_e( 'Explicitly saves the item in Wishlist. Use this for WooCommerce product controls.', 'dsa' ); ?></td></tr>
					<tr><td><code>data-kiwe-save="bookmark"</code></td><td><?php esc_html_e( 'Explicitly saves the item in Bookmarks, including when the linked object is a product.', 'dsa' ); ?></td></tr>
					<tr><td><code>data-kiwe-save="auto"</code></td><td><?php esc_html_e( 'Convenience mode only: infers Wishlist from a product context and Bookmark elsewhere. Explicit attributes are recommended in loops.', 'dsa' ); ?></td></tr>
					<tr><td><code>data-kiwe-save-id="{post_id}"</code></td><td><?php esc_html_e( 'Recommended in Bricks query loops so Kiwe receives the loop product/post ID.', 'dsa' ); ?></td></tr>
					<tr><td><code>data-kiwe-save-title="{post_title}"</code></td><td><?php esc_html_e( 'Optional loop title override. Kiwe otherwise reads the nearest card heading.', 'dsa' ); ?></td></tr>
					<tr><td><code>data-kiwe-save-url="{post_url}"</code></td><td><?php esc_html_e( 'Optional loop URL override. Kiwe otherwise reads the nearest card link.', 'dsa' ); ?></td></tr>
					<tr><td><code>data-kiwe-save-image="..."</code></td><td><?php esc_html_e( 'Optional image URL. Kiwe otherwise reads the nearest card image.', 'dsa' ); ?></td></tr>
				</tbody>
			</table>
			<h3><?php esc_html_e( 'Example', 'dsa' ); ?></h3>
			<pre><code>&lt;button data-kiwe-notifications data-kiwe-notification-status-target="#notification-status"&gt;Turn on notifications&lt;/button&gt;
&lt;p id="notification-status" aria-live="polite"&gt;&lt;/p&gt;</code></pre>
			<h3><?php esc_html_e( 'Bricks Saved button', 'dsa' ); ?></h3>
			<pre><code>&lt;button data-kiwe-save="wishlist" data-kiwe-save-id="{post_id}" data-kiwe-save-title="{post_title}" data-kiwe-save-url="{post_url}"&gt;Wishlist&lt;/button&gt;
&lt;button data-kiwe-save="bookmark" data-kiwe-save-id="{post_id}" data-kiwe-save-title="{post_title}" data-kiwe-save-url="{post_url}"&gt;Bookmark&lt;/button&gt;</code></pre>
			<p class="description"><?php esc_html_e( 'Journey One (PWA install) and Journey Two (offline push permission) are separate. This attribute starts Journey Two only.', 'dsa' ); ?></p>
		</section>
		<?php
	}

	public function render_attributes_page(): void {
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe Attributes', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Builder-neutral attributes for Bricks, block themes, and custom HTML. These contracts open Kiwe journeys without coupling a page to one builder.', 'dsa' ); ?></p>
			<?php $this->render_app_developer_reference(); ?>
		</div>
		<?php
	}

	public function ajax_developer_package_proof(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'dsa' ), '', [ 'response' => 403 ] );
		}

		check_ajax_referer( 'dsa_developer_package_proof' );

		\DSA\Runtime\Package_Manifest::clear_cached_proof();
		$this->render_package_proof_fragment(
			\DSA\Runtime\Package_Manifest::verify(),
			defined( 'KIWE_MU_LOADER_VERSION' ) ? (string) KIWE_MU_LOADER_VERSION : ''
		);
		wp_die();
	}

	private function render_package_proof_fragment( array $package_proof, string $loader_version ): void {
		?>
		<table class="widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Package version', 'dsa' ); ?></th>
					<td><code><?php echo esc_html( DSA_VERSION ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Loader version', 'dsa' ); ?></th>
					<td><code><?php echo esc_html( $loader_version ?: '-' ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Package manifest', 'dsa' ); ?></th>
					<td>
						<strong><?php echo ! empty( $package_proof['valid'] ) ? esc_html__( 'Runnable', 'dsa' ) : esc_html__( 'Missing required files', 'dsa' ); ?></strong>
						<?php echo esc_html( sprintf( __( '(%d files)', 'dsa' ), (int) ( $package_proof['file_count'] ?? 0 ) ) ); ?>
						<?php if ( ! empty( $package_proof['valid'] ) && empty( $package_proof['complete'] ) ) : ?>
							<br><span class="description"><?php echo esc_html( sprintf( __( 'Manifest drift detected: %1$d missing non-critical files, %2$d changed files. Kiwe continues because required runtime files are present.', 'dsa' ), count( (array) ( $package_proof['missing'] ?? [] ) ), count( (array) ( $package_proof['changed'] ?? [] ) ) ) ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $package_proof['blocking_missing'] ) ) : ?>
							<br><code><?php echo esc_html( implode( ', ', array_slice( (array) $package_proof['blocking_missing'], 0, 8 ) ) ); ?></code>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Checked', 'dsa' ); ?></th>
					<td><?php echo ! empty( $package_proof['checked_at'] ) ? esc_html( wp_date( 'Y-m-d H:i:s', (int) $package_proof['checked_at'] ) ) : esc_html__( 'Not checked', 'dsa' ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public function render_developer_page(): void {
		$runtime_cleared = isset( $_GET['runtime-cleared'] ) && '1' === sanitize_key( wp_unslash( $_GET['runtime-cleared'] ) );
		$settings_reset  = sanitize_key( wp_unslash( $_GET['settings-reset'] ?? '' ) );
		$settings        = $this->settings->all();
		$diagnostics     = wp_parse_args( $settings['diagnostics'] ?? [], $this->settings->defaults()['diagnostics'] );
		$enhancements    = wp_parse_args( $settings['enhancements'] ?? [], $this->settings->defaults()['enhancements'] );
		$readiness       = $this->readiness->report();
		$package_proof   = \DSA\Runtime\Package_Manifest::verify();
		$loader_version  = defined( 'KIWE_MU_LOADER_VERSION' ) ? (string) KIWE_MU_LOADER_VERSION : '';
		?>
		<div class="wrap dsa-admin" data-dsa-developer-tools data-auto-clear-browser="<?php echo $runtime_cleared ? '1' : '0'; ?>">
			<h1><?php esc_html_e( 'Kiwe Developer', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Deployment recovery and portable configuration tools. Runtime cleanup never deletes orders, users, PhoneKey credentials, analytics, or SecureTrack records.', 'dsa' ); ?></p>

			<?php if ( $runtime_cleared ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Server-side Kiwe runtime caches were cleared. This browser is now removing old Kiwe service workers and cached shell files.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '1' === $settings_reset ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Kiwe configuration was reset to defaults. Site content and customer data were preserved.', 'dsa' ); ?></p></div>
			<?php elseif ( 'cancelled' === $settings_reset ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Settings reset was cancelled because confirmation was not checked.', 'dsa' ); ?></p></div>
			<?php endif; ?>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Installed build', 'dsa' ); ?></h2>
				<div
					id="dsa-package-proof"
					<?php if ( ! empty( $enhancements['enabled'] ) && ! empty( $enhancements['htmx'] ) ) : ?>
					hx-get="<?php echo esc_url( add_query_arg( [ 'action' => 'dsa_developer_package_proof', '_ajax_nonce' => wp_create_nonce( 'dsa_developer_package_proof' ) ], admin_url( 'admin-ajax.php' ) ) ); ?>"
					hx-trigger="click from:#dsa-refresh-package-proof"
					hx-target="this"
					hx-swap="innerHTML"
					<?php endif; ?>
				>
					<?php $this->render_package_proof_fragment( $package_proof, $loader_version ); ?>
				</div>
				<?php if ( ! empty( $enhancements['enabled'] ) && ! empty( $enhancements['htmx'] ) ) : ?>
				<p>
					<button type="button" class="button" id="dsa-refresh-package-proof"><?php esc_html_e( 'Refresh package proof', 'dsa' ); ?></button>
				</p>
				<?php else : ?>
				<p class="description"><?php esc_html_e( 'Live package-proof refresh is available when the controlled htmx enhancement gate is enabled. The static proof above is still server-rendered.', 'dsa' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Runtime recovery', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Use after replacing the MU plugin or when the browser still loads an older Kiwe version. This clears Kiwe transients, unregisters this browser’s Kiwe service worker, and removes Kiwe Cache Storage and local runtime keys.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_developer_clear_runtime">
					<?php wp_nonce_field( 'dsa_developer_clear_runtime' ); ?>
					<?php submit_button( __( 'Clear Kiwe runtime caches', 'dsa' ), 'primary', 'submit', false ); ?>
					<button type="button" class="button" data-dsa-clear-browser><?php esc_html_e( 'Clear this browser only', 'dsa' ); ?></button>
				</form>
				<p data-dsa-developer-status aria-live="polite"></p>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Diagnostics', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Production stays quiet by default. Enable these only while testing a site or investigating a deployment issue.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<input type="hidden" name="_dsa_redirect" value="kiwe-developer">
					<input type="hidden" name="diagnostics[_present]" value="1">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>
					<label><input type="checkbox" name="diagnostics[enabled]" value="1" <?php checked( ! empty( $diagnostics['enabled'] ) ); ?>> <?php esc_html_e( 'Enable diagnostics controls', 'dsa' ); ?></label><br>
					<label><input type="checkbox" name="diagnostics[frontend_debug]" value="1" <?php checked( ! empty( $diagnostics['frontend_debug'] ) ); ?>> <?php esc_html_e( 'Expose debug state to the Surface runtime', 'dsa' ); ?></label><br>
					<label><input type="checkbox" name="diagnostics[console_logs]" value="1" <?php checked( ! empty( $diagnostics['console_logs'] ) ); ?>> <?php esc_html_e( 'Write Kiwe Surface logs to the browser console', 'dsa' ); ?></label><br>
					<label><input type="checkbox" name="diagnostics[performance_profile]" value="1" <?php checked( ! empty( $diagnostics['performance_profile'] ) ); ?>> <?php esc_html_e( 'Write observe-only runtime performance profiles to the debug log', 'dsa' ); ?></label><br>
					<label><input type="checkbox" name="diagnostics[asset_manifest]" value="1" <?php checked( ! empty( $diagnostics['asset_manifest'] ) ); ?>> <?php esc_html_e( 'Write observe-only asset ownership manifests to the debug log', 'dsa' ); ?></label><br>
					<label><input type="checkbox" name="diagnostics[asset_build_pilot]" value="1" <?php checked( ! empty( $diagnostics['asset_build_pilot'] ) ); ?>> <?php esc_html_e( 'Enable the content-addressed asset build pilot', 'dsa' ); ?></label><br>
					<label><input type="checkbox" name="diagnostics[asset_build_apply]" value="1" <?php checked( ! empty( $diagnostics['asset_build_apply'] ) ); ?>> <?php esc_html_e( 'Serve the validated generated Kiwe stylesheet', 'dsa' ); ?></label><br>
					<label><input type="checkbox" name="diagnostics[asset_build_hints]" value="1" <?php checked( ! empty( $diagnostics['asset_build_hints'] ) ); ?>> <?php esc_html_e( 'Emit validated same-origin preload hints', 'dsa' ); ?></label>
					<p class="description"><?php esc_html_e( 'Browser console traces only run when diagnostics, frontend debug, and console logs are enabled here. Keep them off on production sites unless actively investigating.', 'dsa' ); ?></p>
					<?php submit_button( __( 'Save diagnostics', 'dsa' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>

			<section
				class="dsa-admin__panel"
				x-data="<?php echo esc_attr( wp_json_encode( [ 'enabled' => ! empty( $enhancements['enabled'] ), 'htmx' => ! empty( $enhancements['htmx'] ), 'alpine' => ! empty( $enhancements['alpine'] ) ] ) ); ?>"
			>
				<h2><?php esc_html_e( 'Controlled web-app enhancements', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Developer-gated htmx and Alpine foundation. These libraries are local packaged assets only; they must not own PhoneKey, checkout/payment authority, service-worker policy, cart reconciliation, navigation history, focus trapping, or the core Surface lifecycle.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<input type="hidden" name="_dsa_redirect" value="kiwe-developer">
					<input type="hidden" name="enhancements[_present]" value="1">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>
					<label><input type="checkbox" name="enhancements[enabled]" value="1" x-model="enabled" <?php checked( ! empty( $enhancements['enabled'] ) ); ?>> <?php esc_html_e( 'Enable controlled web-app enhancement gates', 'dsa' ); ?></label><br>
					<label><input type="checkbox" name="enhancements[htmx]" value="1" x-model="htmx" :disabled="!enabled" <?php checked( ! empty( $enhancements['htmx'] ) ); ?>> <?php esc_html_e( 'Load local htmx 2.0.10 for server-owned fragment pilots', 'dsa' ); ?></label><br>
					<label><input type="checkbox" name="enhancements[alpine]" value="1" x-model="alpine" :disabled="!enabled" <?php checked( ! empty( $enhancements['alpine'] ) ); ?>> <?php esc_html_e( 'Load local Alpine 3.15.12 for isolated local-widget pilots', 'dsa' ); ?></label>
					<p class="description"><?php esc_html_e( 'Default off. Batch 1 adds only the reversible foundation; Batch 2 and Batch 3 introduce specific pilot surfaces after contracts are in place.', 'dsa' ); ?></p>
					<p class="description" x-show="enabled && (htmx || alpine)"><?php esc_html_e( 'Pilot mode selected. WordPress still owns persistence; these checkboxes only preview the local widget state before save.', 'dsa' ); ?></p>
					<?php submit_button( __( 'Save enhancement gates', 'dsa' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>

			<?php $asset_build = \DSA\Delivery\Asset_Build_Service::status(); ?>
			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'S18 Asset Build', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Developer-gated content-addressed asset delivery proof. The generated stylesheet remains a pilot until cache, CDN, rollback, and live-host evidence are complete.', 'dsa' ); ?></p>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'dsa' ); ?></th>
							<td><?php echo esc_html( (string) $asset_build['state'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Build', 'dsa' ); ?></th>
							<td><code><?php echo esc_html( $asset_build['buildId'] ?: '-' ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Payload', 'dsa' ); ?></th>
							<td><?php echo esc_html( sprintf( __( '%1$d CSS bytes, %2$d font hints, %3$d media hints.', 'dsa' ), (int) $asset_build['bytes'], (int) $asset_build['fonts'], (int) $asset_build['media'] ) ); ?></td>
						</tr>
					</tbody>
				</table>
				<?php if ( $asset_build['message'] ) : ?><p class="description"><?php echo esc_html( $asset_build['message'] ); ?></p><?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_queue_asset_build">
					<?php wp_nonce_field( 'dsa_queue_asset_build' ); ?>
					<?php submit_button( __( 'Queue S18 Build', 'dsa' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Architecture status', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'These are developer-owned gates, not production settings. They stay visible here so unfinished architecture is explicit without confusing site owners.', 'dsa' ); ?></p>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Legacy fragment navigation', 'dsa' ); ?></th>
							<td><strong><?php esc_html_e( 'Removed and hard disabled', 'dsa' ); ?></strong><br><?php esc_html_e( 'The original partial-page renderer was deleted after audit. Production navigation is full-document plus the transition Surface. The separate S13-S16 controlled editorial morph pipeline is implemented behind a Developer gate but remains off until its live compatibility matrix passes.', 'dsa' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Surface width fallback', 'dsa' ); ?></th>
							<td><strong><?php echo esc_html( (string) (int) ( $settings['surface_width'] ?? 72 ) ); ?>px</strong><br><?php esc_html_e( 'Retained only as a legacy Phantom Viewport fallback for old integrations. Responsive Geometry Engine tokens are the production source of layout truth.', 'dsa' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Generated asset pilot', 'dsa' ); ?></th>
							<td><strong><?php echo ! empty( $diagnostics['asset_build_pilot'] ) ? esc_html__( 'Enabled for testing', 'dsa' ) : esc_html__( 'Off', 'dsa' ); ?></strong><br><?php esc_html_e( 'S18 content-addressed asset delivery remains a diagnostics-controlled pilot until release evidence proves it across cache/CDN hosts.', 'dsa' ); ?></td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Production readiness', 'dsa' ); ?></h2>
				<p><strong><?php echo esc_html( (string) $readiness['score'] ); ?></strong> <?php echo esc_html( (string) $readiness['summary'] ); ?></p>
				<p><?php echo esc_html( sprintf( __( '%1$d critical, %2$d warnings, %3$d passing.', 'dsa' ), (int) $readiness['counts']['critical'], (int) $readiness['counts']['warning'], (int) $readiness['counts']['pass'] ) ); ?></p>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Check', 'dsa' ); ?></th><th><?php esc_html_e( 'Status', 'dsa' ); ?></th><th><?php esc_html_e( 'Action', 'dsa' ); ?></th></tr></thead><tbody>
				<?php foreach ( $readiness['checks'] as $check ) : ?><tr><td><?php echo esc_html( (string) $check['label'] ); ?></td><td><?php echo esc_html( (string) $check['status'] ); ?></td><td><?php echo esc_html( (string) $check['action'] ); ?></td></tr><?php endforeach; ?>
				</tbody></table>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Builder attributes', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Builder-neutral HTML attributes for Bricks, block themes, and custom markup now live with the developer contracts that define them.', 'dsa' ); ?></p>
				<?php $this->render_app_developer_reference(); ?>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Export DSA settings', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Downloads the portable Appsite profile. Secrets, users, orders, login credentials, and logs are excluded.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_export_profile">
					<?php wp_nonce_field( 'dsa_export_profile' ); ?>
					<?php submit_button( __( 'Export DSA settings', 'dsa' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Reset configuration', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Returns Kiwe settings to defaults. This does not remove WordPress content, WooCommerce data, user accounts, PhoneKey credentials, analytics tables, or SecureTrack records.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dsa-reset-settings>
					<input type="hidden" name="action" value="dsa_developer_reset_settings">
					<?php wp_nonce_field( 'dsa_developer_reset_settings' ); ?>
					<label><input type="checkbox" name="confirm_reset" value="1" required> <?php esc_html_e( 'I understand that Kiwe configuration will return to defaults.', 'dsa' ); ?></label>
					<?php submit_button( __( 'Reset DSA settings', 'dsa' ), 'delete', 'submit', false ); ?>
				</form>
			</section>
		</div>
		<?php
	}
	public function render_dock_page(): void {
		$settings = $this->settings->all();
		$dock = wp_parse_args( $settings['dock'] ?? [], $this->settings->defaults()['dock'] );
		$dock_shape = sanitize_key( (string) ( $dock['shape'] ?? 'pill' ) );
		$dock_shape = 'rounded' === $dock_shape ? 'pill' : $dock_shape;
		$dock_shape = in_array( $dock_shape, [ 'pill', 'box', 'square' ], true ) ? $dock_shape : 'pill';
		$custom_items = $this->dock_custom_items_for_admin( $dock );
		$labels = array_merge( $this->dock_module_labels(), $this->dock_custom_item_labels( $custom_items ) );
		$order = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $dock['item_order'] ?? [] ) ), static fn( string $id ): bool => isset( $labels[ $id ] ) ) ) );
		foreach ( array_keys( $labels ) as $id ) if ( ! in_array( $id, $order, true ) ) $order[] = $id;
		$enabled = array_replace( array_fill_keys( array_keys( $labels ), true ), (array) ( $dock['enabled_items'] ?? [] ) );
		$focus_item = sanitize_key( (string) ( $dock['focus_item'] ?? 'ai' ) );
		if ( ! isset( $labels[ $focus_item ] ) ) {
			$focus_item = isset( $labels['ai'] ) ? 'ai' : ( array_key_first( $labels ) ?: '' );
		}
		$dock_profiles = [
			'desktop' => [ 'label' => __( 'Desktop', 'dsa' ), 'orientation' => 'auto', 'vertical_position' => 'center', 'horizontal_position' => 'right', 'horizontal_vertical_position' => 'bottom' ],
			'tablet'  => [ 'label' => __( 'Tablet', 'dsa' ), 'orientation' => 'auto', 'vertical_position' => 'center', 'horizontal_position' => 'center', 'horizontal_vertical_position' => 'bottom' ],
			'mobile'  => [ 'label' => __( 'Phone', 'dsa' ), 'orientation' => 'auto', 'vertical_position' => 'bottom', 'horizontal_position' => 'center', 'horizontal_vertical_position' => 'bottom' ],
		];
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe Dock', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Choose which registered Surface destinations appear in the dock and drag them into their visual order. Hidden destinations remain available to approved launchers such as Bricks header icons.', 'dsa' ); ?></p>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Dock settings saved.', 'dsa' ); ?></p></div><?php endif; ?>
			<section class="dsa-admin__panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_dock_settings">
					<?php wp_nonce_field( 'dsa_save_dock_settings' ); ?>
					<ul class="dsa-dock-order" data-dsa-dock-order>
						<?php foreach ( $order as $id ) : ?>
							<li class="dsa-dock-order__item" draggable="true" data-dsa-dock-item="<?php echo esc_attr( $id ); ?>">
								<span class="dashicons dashicons-move" aria-hidden="true"></span>
								<input type="hidden" name="dock[item_order][]" value="<?php echo esc_attr( $id ); ?>">
								<label><input type="checkbox" name="dock[enabled_items][<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( ! empty( $enabled[ $id ] ) ); ?>> <strong><?php echo esc_html( $labels[ $id ] ); ?></strong></label>
								<label><input type="radio" name="dock[focus_item]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $focus_item, $id ); ?>> <?php esc_html_e( 'Focus', 'dsa' ); ?></label>
								<code><?php echo esc_html( $id ); ?></code>
							</li>
						<?php endforeach; ?>
					</ul>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Custom dock links', 'dsa' ); ?></th>
								<td>
									<div class="dsa-admin-builder" data-dsa-dock-link-builder>
										<div class="dsa-admin-rows" data-dsa-dock-links>
											<?php foreach ( $custom_items as $index => $item ) : ?>
												<div class="dsa-admin-row" data-dsa-dock-link-row>
													<input type="hidden" name="dock[custom_items][<?php echo esc_attr( (string) $index ); ?>][id]" value="<?php echo esc_attr( $item['id'] ); ?>">
													<input type="hidden" name="dock[custom_items][<?php echo esc_attr( (string) $index ); ?>][enabled]" value="1">
													<label><span><?php esc_html_e( 'Label', 'dsa' ); ?></span><input type="text" name="dock[custom_items][<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( $item['label'] ); ?>" placeholder="<?php echo esc_attr__( 'Home', 'dsa' ); ?>"></label>
													<label><span><?php esc_html_e( 'URL', 'dsa' ); ?></span><input type="url" name="dock[custom_items][<?php echo esc_attr( (string) $index ); ?>][url]" value="<?php echo esc_url( $item['url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"></label>
													<label><span><?php esc_html_e( 'Lucide icon', 'dsa' ); ?></span><input type="text" name="dock[custom_items][<?php echo esc_attr( (string) $index ); ?>][icon]" value="<?php echo esc_attr( $item['icon'] ); ?>" placeholder="home"></label>
													<button class="button dsa-admin-remove" type="button" data-dsa-remove-row><?php esc_html_e( 'Remove', 'dsa' ); ?></button>
												</div>
											<?php endforeach; ?>
										</div>
										<button class="button dsa-admin-add" type="button" data-dsa-add-dock-link><?php esc_html_e( '+ Add dock link', 'dsa' ); ?></button>
										<p class="description"><?php esc_html_e( 'Use for safe navigation items such as Home. Custom dock links navigate by URL; they do not create new DSA screens or duplicate cart/search/profile authority.', 'dsa' ); ?></p>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Context rail', 'dsa' ); ?></th>
								<td>
									<input type="hidden" name="dock[context_rail_enabled]" value="0">
									<label><input type="checkbox" name="dock[context_rail_enabled]" value="1" <?php checked( ! empty( $dock['context_rail_enabled'] ) ); ?>> <?php esc_html_e( 'Enable experimental dock context rail', 'dsa' ); ?></label>
									<p class="description"><?php esc_html_e( 'Context rails are off by default and only run in Legacy Classic, so panel controls stay inside their own screen for Sheets and Kiwe 2027. Dock visual presentation now lives in Kiwe > Theme.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Visibility', 'dsa' ); ?></th>
								<td>
									<label><input type="checkbox" name="dock[hide_frontend_admin_bar]" value="1" <?php checked( ! empty( $dock['hide_frontend_admin_bar'] ) ); ?>> <?php esc_html_e( 'Hide the WordPress admin bar only while the Kiwe frontend shell is active', 'dsa' ); ?></label>
									<p><label><span><?php esc_html_e( 'Profile/Auth icon', 'dsa' ); ?></span> <select name="dock[phonekey_visibility]"><option value="all" <?php selected( $dock['phonekey_visibility'] ?? 'all', 'all' ); ?>><?php esc_html_e( 'All visitors and users', 'dsa' ); ?></option><option value="visitors" <?php selected( $dock['phonekey_visibility'] ?? 'all', 'visitors' ); ?>><?php esc_html_e( 'Visitors only', 'dsa' ); ?></option><option value="users" <?php selected( $dock['phonekey_visibility'] ?? 'all', 'users' ); ?>><?php esc_html_e( 'Logged-in users only', 'dsa' ); ?></option><option value="customers" <?php selected( $dock['phonekey_visibility'] ?? 'all', 'customers' ); ?>><?php esc_html_e( 'WooCommerce customers only', 'dsa' ); ?></option><option value="admins" <?php selected( $dock['phonekey_visibility'] ?? 'all', 'admins' ); ?>><?php esc_html_e( 'Admins only', 'dsa' ); ?></option></select></label></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Related settings', 'dsa' ); ?></th>
								<td>
									<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-theme' ) ); ?>"><?php esc_html_e( 'Theme colors and sheets', 'dsa' ); ?></a>
									<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-menu' ) ); ?>"><?php esc_html_e( 'Menu content', 'dsa' ); ?></a>
									<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-bricks' ) ); ?>"><?php esc_html_e( 'Bricks launchers', 'dsa' ); ?></a>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Save Dock', 'dsa' ) ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	public function render_theme_page(): void {
		$current = $this->settings->get( 'style', [] );
		$current = is_array( $current ) ? wp_parse_args( $current, $this->settings->defaults()['style'] ) : $this->settings->defaults()['style'];
		$visual_profile_raw = sanitize_key( (string) ( $current['visual_profile'] ?? 'legacy' ) );
		$visual_profile = in_array( $visual_profile_raw, [ 'prototype', 'kiwe2027', 'kiwe-2027' ], true ) ? 'kiwe2027' : 'legacy';
		$mode = in_array( $current['mode'] ?? 'classic', [ 'classic', 'sheet' ], true ) ? (string) $current['mode'] : 'classic';
		$sheet_position = in_array( $current['sheet_position'] ?? 'bottom', [ 'bottom', 'right', 'left' ], true ) ? (string) $current['sheet_position'] : 'bottom';
		$sheet_animation = in_array( $current['sheet_animation'] ?? 'slide', [ 'slide', 'soft', 'snap' ], true ) ? (string) $current['sheet_animation'] : 'slide';
		$sheet_backdrop = in_array( $current['sheet_backdrop'] ?? 'blur', [ 'blur', 'fade', 'none' ], true ) ? (string) $current['sheet_backdrop'] : 'blur';
		$sheet_spacing = in_array( $current['sheet_spacing'] ?? 'edge', [ 'edge', 'inset' ], true ) ? (string) $current['sheet_spacing'] : 'edge';
		$sheet_origin = in_array( $current['sheet_origin'] ?? 'bottom', [ 'bottom', 'above_dock' ], true ) ? (string) $current['sheet_origin'] : 'bottom';
		$sheet_duration = max( 120, min( 900, (int) ( $current['sheet_duration_ms'] ?? 320 ) ) );
		$sheet_max_height = max( 45, min( 96, (int) ( $current['sheet_max_height'] ?? 82 ) ) );
		$sheet_width_percent = max( 50, min( 90, (int) ( $current['sheet_width_percent'] ?? 78 ) ) );
		$screen_heading_tag = in_array( $current['screen_heading_tag'] ?? 'h2', [ 'h1', 'h2', 'h3', 'h4', 'p', 'span' ], true ) ? (string) $current['screen_heading_tag'] : 'h2';
		$all = $this->settings->all();
		$theme = wp_parse_args( $all['dsa_theme'] ?? [], $this->settings->defaults()['dsa_theme'] );
		$visual = wp_parse_args( $all['visual_effects'] ?? [], $this->settings->defaults()['visual_effects'] );
		$dock = wp_parse_args( $all['dock'] ?? [], $this->settings->defaults()['dock'] );
		$dock_shape = sanitize_key( (string) ( $dock['shape'] ?? 'pill' ) );
		$dock_shape = 'rounded' === $dock_shape ? 'pill' : $dock_shape;
		$dock_shape = in_array( $dock_shape, [ 'pill', 'box', 'square' ], true ) ? $dock_shape : 'pill';
		$dock_profiles = [
			'desktop' => [ 'label' => __( 'Desktop', 'dsa' ), 'orientation' => 'auto', 'vertical_position' => 'center', 'horizontal_position' => 'right', 'horizontal_vertical_position' => 'bottom' ],
			'tablet'  => [ 'label' => __( 'Tablet', 'dsa' ), 'orientation' => 'auto', 'vertical_position' => 'center', 'horizontal_position' => 'center', 'horizontal_vertical_position' => 'bottom' ],
			'mobile'  => [ 'label' => __( 'Phone', 'dsa' ), 'orientation' => 'auto', 'vertical_position' => 'bottom', 'horizontal_position' => 'center', 'horizontal_vertical_position' => 'bottom' ],
		];
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe Theme', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Choose how Kiwe presents the same Surface modules. Theme changes presentation only; data, REST, PhoneKey, cart, search, AI, and module contracts remain unchanged.', 'dsa' ); ?></p>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Theme saved.', 'dsa' ); ?></p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dsa_save_settings' ); ?>
				<input type="hidden" name="action" value="dsa_save_settings">
				<input type="hidden" name="_dsa_redirect" value="kiwe-theme">
				<input type="hidden" name="visual_effects[_classic_present]" value="1">
				<input type="hidden" name="visual_effects[_transition_present]" value="1">
				<h2><?php esc_html_e( 'Visual profile', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Legacy preserves the ultra-light baseline. Kiwe 2027 is the built-in app UI track and can evolve without replacing Legacy.', 'dsa' ); ?></p>
				<div class="dsa-theme-options">
					<label class="dsa-theme-option dsa-theme-option--classic">
						<input type="radio" name="style[visual_profile]" value="legacy" <?php checked( $visual_profile, 'legacy' ); ?>>
						<span class="dsa-theme-option__preview" aria-hidden="true"><i></i><i></i><i></i><b></b></span>
						<strong><?php esc_html_e( 'Legacy UI', 'dsa' ); ?></strong>
						<small><?php esc_html_e( 'The preserved baseline for existing sites. This remains the safe default and lightest built-in theme.', 'dsa' ); ?></small>
					</label>
					<label class="dsa-theme-option dsa-theme-option--sheet">
						<input type="radio" name="style[visual_profile]" value="kiwe2027" <?php checked( $visual_profile, 'kiwe2027' ); ?>>
						<span class="dsa-theme-option__preview" aria-hidden="true"><i></i><b></b></span>
						<strong><?php esc_html_e( 'Kiwe 2027', 'dsa' ); ?></strong>
						<small><?php esc_html_e( 'Modern app UI using the same token, geometry, dock, Bricks, cart, search, links, and AI contracts. Context rails stay disabled in this profile.', 'dsa' ); ?></small>
					</label>
				</div>
				<h2><?php esc_html_e( 'Dock presentation', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Theme owns how the registered dock is presented. Kiwe > Dock still owns which modules appear and their drag order.', 'dsa' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Dock style', 'dsa' ); ?></th>
							<td>
								<select name="dock[presentation]">
									<option value="dock" <?php selected( $dock['presentation'] ?? 'dock', 'dock' ); ?>><?php esc_html_e( 'Compact dock', 'dsa' ); ?></option>
									<option value="navbar" <?php selected( $dock['presentation'] ?? 'dock', 'navbar' ); ?>><?php esc_html_e( 'Navigation bar', 'dsa' ); ?></option>
								</select>
								<input type="hidden" name="dock[split_style]" value="0">
								<label><input type="checkbox" name="dock[split_style]" value="1" <?php checked( ! empty( $dock['split_style'] ) ); ?>> <?php esc_html_e( 'Split compact dock around the AI/action button', 'dsa' ); ?></label>
								<p class="description"><?php esc_html_e( 'Compact dock may be full/unsplit or split around the emphasized action. Split style never applies to Navigation bar. Navigation bar attaches to the chosen viewport edge with zero outer gap.', 'dsa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Dock material', 'dsa' ); ?></th>
							<td class="dsa-admin-inline-fields">
								<label><span><?php esc_html_e( 'Shape', 'dsa' ); ?></span><select name="dock[shape]"><option value="pill" <?php selected( $dock_shape, 'pill' ); ?>><?php esc_html_e( 'Pill', 'dsa' ); ?></option><option value="box" <?php selected( $dock_shape, 'box' ); ?>><?php esc_html_e( 'Rounded box', 'dsa' ); ?></option><option value="square" <?php selected( $dock_shape, 'square' ); ?>><?php esc_html_e( 'Square / no radius', 'dsa' ); ?></option></select></label>
								<label><span><?php esc_html_e( 'Background', 'dsa' ); ?></span><select name="dock[material]"><option value="glass" <?php selected( $dock['material'] ?? 'glass', 'glass' ); ?>><?php esc_html_e( 'Glass', 'dsa' ); ?></option><option value="solid" <?php selected( $dock['material'] ?? 'glass', 'solid' ); ?>><?php esc_html_e( 'Solid', 'dsa' ); ?></option></select></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Responsive dock placement', 'dsa' ); ?></th>
							<td>
								<p class="description"><?php esc_html_e( 'Each viewport profile chooses an orientation and anchor. Auto lets the Geometry Engine choose horizontal for narrow or portrait spaces and vertical for wider landscape spaces.', 'dsa' ); ?></p>
								<div class="dsa-admin-device-grid">
									<?php foreach ( $dock_profiles as $profile => $profile_defaults ) : ?>
										<fieldset class="dsa-admin-device-card">
											<legend><?php echo esc_html( $profile_defaults['label'] ); ?></legend>
											<div class="dsa-admin-inline-fields">
												<label><span><?php esc_html_e( 'Orientation', 'dsa' ); ?></span><select name="dock[<?php echo esc_attr( $profile ); ?>_orientation]"><option value="auto" <?php selected( $dock[ $profile . '_orientation' ] ?? $profile_defaults['orientation'], 'auto' ); ?>><?php esc_html_e( 'Auto', 'dsa' ); ?></option><option value="horizontal" <?php selected( $dock[ $profile . '_orientation' ] ?? $profile_defaults['orientation'], 'horizontal' ); ?>><?php esc_html_e( 'Horizontal', 'dsa' ); ?></option><option value="vertical" <?php selected( $dock[ $profile . '_orientation' ] ?? $profile_defaults['orientation'], 'vertical' ); ?>><?php esc_html_e( 'Vertical', 'dsa' ); ?></option></select></label>
												<label><span><?php esc_html_e( 'Horizontal alignment', 'dsa' ); ?></span><select name="dock[<?php echo esc_attr( $profile ); ?>_horizontal_position]"><option value="left" <?php selected( $dock[ $profile . '_horizontal_position' ] ?? $profile_defaults['horizontal_position'], 'left' ); ?>><?php esc_html_e( 'Left', 'dsa' ); ?></option><option value="center" <?php selected( $dock[ $profile . '_horizontal_position' ] ?? $profile_defaults['horizontal_position'], 'center' ); ?>><?php esc_html_e( 'Center', 'dsa' ); ?></option><option value="right" <?php selected( $dock[ $profile . '_horizontal_position' ] ?? $profile_defaults['horizontal_position'], 'right' ); ?>><?php esc_html_e( 'Right', 'dsa' ); ?></option></select></label>
												<label><span><?php esc_html_e( 'Horizontal position', 'dsa' ); ?></span><select name="dock[<?php echo esc_attr( $profile ); ?>_horizontal_vertical_position]"><option value="center" <?php selected( $dock[ $profile . '_horizontal_vertical_position' ] ?? $profile_defaults['horizontal_vertical_position'], 'center' ); ?>><?php esc_html_e( 'Screen center', 'dsa' ); ?></option><option value="bottom" <?php selected( $dock[ $profile . '_horizontal_vertical_position' ] ?? $profile_defaults['horizontal_vertical_position'], 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'dsa' ); ?></option></select></label>
												<label><span><?php esc_html_e( 'Vertical position', 'dsa' ); ?></span><select name="dock[<?php echo esc_attr( $profile ); ?>_vertical_position]"><option value="center" <?php selected( $dock[ $profile . '_vertical_position' ] ?? $profile_defaults['vertical_position'], 'center' ); ?>><?php esc_html_e( 'Center', 'dsa' ); ?></option><option value="bottom" <?php selected( $dock[ $profile . '_vertical_position' ] ?? $profile_defaults['vertical_position'], 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'dsa' ); ?></option></select></label>
												<label><span><?php esc_html_e( 'Vertical bar edge', 'dsa' ); ?></span><select name="dock[<?php echo esc_attr( $profile ); ?>_vertical_edge]"><option value="left" <?php selected( $dock[ $profile . '_vertical_edge' ] ?? 'right', 'left' ); ?>><?php esc_html_e( 'Left', 'dsa' ); ?></option><option value="right" <?php selected( $dock[ $profile . '_vertical_edge' ] ?? 'right', 'right' ); ?>><?php esc_html_e( 'Right', 'dsa' ); ?></option></select></label>
												<label><span><?php esc_html_e( 'Horizontal bar edge', 'dsa' ); ?></span><select name="dock[<?php echo esc_attr( $profile ); ?>_horizontal_edge]"><option value="top" <?php selected( $dock[ $profile . '_horizontal_edge' ] ?? 'bottom', 'top' ); ?>><?php esc_html_e( 'Top', 'dsa' ); ?></option><option value="bottom" <?php selected( $dock[ $profile . '_horizontal_edge' ] ?? 'bottom', 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'dsa' ); ?></option></select></label>
											</div>
										</fieldset>
									<?php endforeach; ?>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
				<h2><?php esc_html_e( 'Screen behavior', 'dsa' ); ?></h2>
				<div class="dsa-theme-options">
					<label class="dsa-theme-option dsa-theme-option--classic">
						<input type="radio" name="style[mode]" value="classic" <?php checked( $mode, 'classic' ); ?>>
						<span class="dsa-theme-option__preview" aria-hidden="true"><i></i><i></i><i></i><b></b></span>
						<strong><?php esc_html_e( 'Classic Surface', 'dsa' ); ?></strong>
						<small><?php esc_html_e( 'The current DSA app shell: full-screen Surface panels, dock modules, and the locked Kiwe 1.0 visual contract.', 'dsa' ); ?></small>
					</label>
					<label class="dsa-theme-option dsa-theme-option--sheet">
						<input type="radio" name="style[mode]" value="sheet" <?php checked( $mode, 'sheet' ); ?>>
						<span class="dsa-theme-option__preview" aria-hidden="true"><i></i><b></b></span>
						<strong><?php esc_html_e( 'Sheets', 'dsa' ); ?></strong>
						<small><?php esc_html_e( 'Open the same modules as bottom, right, or left sheets with a handle, backdrop, max-height, and sheet animation.', 'dsa' ); ?></small>
					</label>
				</div>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="dsa-theme-screen-heading"><?php esc_html_e( 'Screen title tag', 'dsa' ); ?></label></th>
							<td><select id="dsa-theme-screen-heading" name="style[screen_heading_tag]"><?php foreach ( [ 'h1', 'h2', 'h3', 'h4', 'p', 'span' ] as $tag ) : ?><option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $screen_heading_tag, $tag ); ?>><?php echo esc_html( strtoupper( $tag ) ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Semantic tag for top-level DSA screen titles such as Cart, Search, Profile, Menu, Links, Saved, AI, and notification surfaces. Theme CSS keeps the visual size independent from this semantic choice.', 'dsa' ); ?></p></td>
						</tr>
						<tr data-dsa-theme-controls="classic">
							<th scope="row"><?php esc_html_e( 'Classic Surface material', 'dsa' ); ?></th>
							<td class="dsa-admin-inline-fields">
								<label><span><?php esc_html_e( 'Backdrop', 'dsa' ); ?></span><select name="visual_effects[blur_type]"><option value="none" <?php selected( $visual['blur_type'], 'none' ); ?>><?php esc_html_e( 'None', 'dsa' ); ?></option><option value="gaussian" <?php selected( $visual['blur_type'], 'gaussian' ); ?>><?php esc_html_e( 'Gaussian', 'dsa' ); ?></option><option value="frosted" <?php selected( $visual['blur_type'], 'frosted' ); ?>><?php esc_html_e( 'Frosted depth', 'dsa' ); ?></option><option value="dim" <?php selected( $visual['blur_type'], 'dim' ); ?>><?php esc_html_e( 'Dim', 'dsa' ); ?></option></select></label>
								<label><span><?php esc_html_e( 'Blur strength', 'dsa' ); ?></span><input type="number" min="0" max="24" name="visual_effects[blur_strength]" value="<?php echo esc_attr( (string) $visual['blur_strength'] ); ?>"></label>
								<label><span><?php esc_html_e( 'Glass intensity', 'dsa' ); ?></span><select name="visual_effects[glass_intensity]"><option value="low" <?php selected( $visual['glass_intensity'], 'low' ); ?>><?php esc_html_e( 'Low', 'dsa' ); ?></option><option value="medium" <?php selected( $visual['glass_intensity'], 'medium' ); ?>><?php esc_html_e( 'Medium', 'dsa' ); ?></option><option value="high" <?php selected( $visual['glass_intensity'], 'high' ); ?>><?php esc_html_e( 'High', 'dsa' ); ?></option></select></label>
								<label><span><?php esc_html_e( 'Panel', 'dsa' ); ?></span><select name="visual_effects[screen_material]"><option value="glass" <?php selected( $visual['screen_material'], 'glass' ); ?>><?php esc_html_e( 'Glass', 'dsa' ); ?></option><option value="solid" <?php selected( $visual['screen_material'], 'solid' ); ?>><?php esc_html_e( 'Solid', 'dsa' ); ?></option></select></label>
								<label><span><?php esc_html_e( 'Panel motion', 'dsa' ); ?></span><select name="visual_effects[screen_animation]"><option value="bottom" <?php selected( $visual['screen_animation'], 'bottom' ); ?>><?php esc_html_e( 'From bottom', 'dsa' ); ?></option><option value="top" <?php selected( $visual['screen_animation'], 'top' ); ?>><?php esc_html_e( 'From top', 'dsa' ); ?></option><option value="left" <?php selected( $visual['screen_animation'], 'left' ); ?>><?php esc_html_e( 'From left', 'dsa' ); ?></option><option value="right" <?php selected( $visual['screen_animation'], 'right' ); ?>><?php esc_html_e( 'From right', 'dsa' ); ?></option></select></label>
								<label><span><?php esc_html_e( 'Loader', 'dsa' ); ?></span><select name="visual_effects[loader_type]"><option value="none" <?php selected( $visual['loader_type'], 'none' ); ?>><?php esc_html_e( 'None', 'dsa' ); ?></option><option value="orb-chase" <?php selected( $visual['loader_type'], 'orb-chase' ); ?>><?php esc_html_e( 'Orb chase', 'dsa' ); ?></option><option value="pulse" <?php selected( $visual['loader_type'], 'pulse' ); ?>><?php esc_html_e( 'Pulse', 'dsa' ); ?></option></select></label>
								<p class="description"><?php esc_html_e( 'These controls apply only to Classic Surface. Sheet presentation never reads them.', 'dsa' ); ?></p>
							</td>
						</tr>
						<tr data-dsa-theme-controls="sheet">
							<th scope="row"><label for="dsa-sheet-position"><?php esc_html_e( 'Sheet placement', 'dsa' ); ?></label></th>
							<td><select id="dsa-sheet-position" name="style[sheet_position]">
								<option value="bottom" <?php selected( $sheet_position, 'bottom' ); ?>><?php esc_html_e( 'Bottom sheet', 'dsa' ); ?></option>
								<option value="right" <?php selected( $sheet_position, 'right' ); ?>><?php esc_html_e( 'Right sheet', 'dsa' ); ?></option>
								<option value="left" <?php selected( $sheet_position, 'left' ); ?>><?php esc_html_e( 'Left sheet', 'dsa' ); ?></option>
							</select></td>
						</tr>
						<tr data-dsa-theme-controls="sheet">
							<th scope="row"><label for="dsa-sheet-animation"><?php esc_html_e( 'Sheet animation', 'dsa' ); ?></label></th>
							<td><select id="dsa-sheet-animation" name="style[sheet_animation]">
								<option value="slide" <?php selected( $sheet_animation, 'slide' ); ?>><?php esc_html_e( 'Slide', 'dsa' ); ?></option>
								<option value="soft" <?php selected( $sheet_animation, 'soft' ); ?>><?php esc_html_e( 'Soft fade + slide', 'dsa' ); ?></option>
								<option value="snap" <?php selected( $sheet_animation, 'snap' ); ?>><?php esc_html_e( 'Snap', 'dsa' ); ?></option>
							</select></td>
						</tr>
						<tr data-dsa-theme-controls="sheet">
							<th scope="row"><label for="dsa-sheet-backdrop"><?php esc_html_e( 'Background treatment', 'dsa' ); ?></label></th>
							<td><select id="dsa-sheet-backdrop" name="style[sheet_backdrop]">
								<option value="blur" <?php selected( $sheet_backdrop, 'blur' ); ?>><?php esc_html_e( 'Soft blur', 'dsa' ); ?></option>
								<option value="fade" <?php selected( $sheet_backdrop, 'fade' ); ?>><?php esc_html_e( 'Fade only', 'dsa' ); ?></option>
								<option value="none" <?php selected( $sheet_backdrop, 'none' ); ?>><?php esc_html_e( 'None', 'dsa' ); ?></option>
							</select><p class="description"><?php esc_html_e( 'The underlying website remains visible. Blur is intentionally light to avoid mobile jank.', 'dsa' ); ?></p></td>
						</tr>
						<tr data-dsa-theme-controls="sheet">
							<th scope="row"><label for="dsa-sheet-spacing"><?php esc_html_e( 'Sheet space around', 'dsa' ); ?></label></th>
							<td><select id="dsa-sheet-spacing" name="style[sheet_spacing]">
								<option value="edge" <?php selected( $sheet_spacing, 'edge' ); ?>><?php esc_html_e( 'Edge-to-edge', 'dsa' ); ?></option>
								<option value="inset" <?php selected( $sheet_spacing, 'inset' ); ?>><?php esc_html_e( 'Inset / space around', 'dsa' ); ?></option>
							</select><p class="description"><?php esc_html_e( 'Inset sheets leave visible website space around the panel, matching the Kiwe 2027 floating app-card look.', 'dsa' ); ?></p></td>
						</tr>
						<tr data-dsa-theme-controls="sheet">
							<th scope="row"><label for="dsa-sheet-origin"><?php esc_html_e( 'Sheet starts from', 'dsa' ); ?></label></th>
							<td><select id="dsa-sheet-origin" name="style[sheet_origin]">
								<option value="bottom" <?php selected( $sheet_origin, 'bottom' ); ?>><?php esc_html_e( 'Screen bottom', 'dsa' ); ?></option>
								<option value="above_dock" <?php selected( $sheet_origin, 'above_dock' ); ?>><?php esc_html_e( 'Above dock', 'dsa' ); ?></option>
							</select><p class="description"><?php esc_html_e( 'Above dock keeps the bottom dock visible below the sheet instead of letting the sheet grow behind it.', 'dsa' ); ?></p></td>
						</tr>
						<tr data-dsa-theme-controls="sheet">
							<th scope="row"><label for="dsa-sheet-width-percent"><?php esc_html_e( 'Inset sheet width', 'dsa' ); ?></label></th>
							<td><input id="dsa-sheet-width-percent" type="number" min="50" max="90" name="style[sheet_width_percent]" value="<?php echo esc_attr( (string) $sheet_width_percent ); ?>"> <span class="description"><?php esc_html_e( 'viewport width percent, used when Sheet space around is Inset.', 'dsa' ); ?></span></td>
						</tr>
						<tr data-dsa-theme-controls="sheet">
							<th scope="row"><label for="dsa-sheet-duration"><?php esc_html_e( 'Animation length', 'dsa' ); ?></label></th>
							<td><input id="dsa-sheet-duration" type="number" min="120" max="900" step="20" name="style[sheet_duration_ms]" value="<?php echo esc_attr( (string) $sheet_duration ); ?>"> <span class="description"><?php esc_html_e( 'ms', 'dsa' ); ?></span></td>
						</tr>
						<tr data-dsa-theme-controls="sheet">
							<th scope="row"><label for="dsa-sheet-max-height"><?php esc_html_e( 'Bottom sheet max-height', 'dsa' ); ?></label></th>
							<td><input id="dsa-sheet-max-height" type="number" min="45" max="96" name="style[sheet_max_height]" value="<?php echo esc_attr( (string) $sheet_max_height ); ?>"> <span class="description"><?php esc_html_e( 'viewport height percent', 'dsa' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Transition Surface', 'dsa' ); ?></th>
							<td>
								<label><input type="checkbox" name="visual_effects[show_on_navigation]" value="1" <?php checked( ! empty( $visual['show_on_navigation'] ) ); ?>> <?php esc_html_e( 'Show during approved navigation', 'dsa' ); ?></label><br>
								<label><input type="checkbox" name="visual_effects[show_on_page_out]" value="1" <?php checked( ! empty( $visual['show_on_page_out'] ) ); ?>> <?php esc_html_e( 'Show on page out', 'dsa' ); ?></label><br>
								<label><input type="checkbox" name="visual_effects[show_on_page_in]" value="1" <?php checked( ! empty( $visual['show_on_page_in'] ) ); ?>> <?php esc_html_e( 'Show on page in', 'dsa' ); ?></label><br>
								<label><input type="checkbox" name="visual_effects[show_on_overlay_open]" value="1" <?php checked( ! empty( $visual['show_on_overlay_open'] ) ); ?>> <?php esc_html_e( 'Animate when a module opens', 'dsa' ); ?></label><br>
								<label><span><?php esc_html_e( 'Minimum visible time', 'dsa' ); ?></span> <input type="number" min="0" max="10000" name="visual_effects[min_loader_ms]" value="<?php echo esc_attr( (string) $visual['min_loader_ms'] ); ?>"> ms</label>
								<label><span><?php esc_html_e( 'Artificial delay', 'dsa' ); ?></span> <input type="number" min="0" max="5000" name="visual_effects[artificial_delay_ms]" value="<?php echo esc_attr( (string) $visual['artificial_delay_ms'] ); ?>"> ms</label>
								<input type="hidden" name="visual_effects[editorial_view_transitions]" value="<?php echo ! empty( $visual['editorial_view_transitions'] ) ? '1' : '0'; ?>">
								<input type="hidden" name="visual_effects[editorial_morph_navigation]" value="<?php echo ! empty( $visual['editorial_morph_navigation'] ) ? '1' : '0'; ?>">
								<input type="hidden" name="visual_effects[transition_message_mode]" value="<?php echo esc_attr( (string) $visual['transition_message_mode'] ); ?>">
								<input type="hidden" name="visual_effects[transition_message_index]" value="<?php echo esc_attr( (string) ( (int) $visual['transition_message_index'] + 1 ) ); ?>">
								<input type="hidden" name="visual_effects[transition_title_position]" value="<?php echo esc_attr( (string) $visual['transition_title_position'] ); ?>">
								<?php foreach ( (array) $visual['transition_messages'] as $index => $message ) : ?><input type="hidden" name="visual_effects[transition_messages][<?php echo esc_attr( (string) $index ); ?>][title]" value="<?php echo esc_attr( (string) ( $message['title'] ?? '' ) ); ?>"><input type="hidden" name="visual_effects[transition_messages][<?php echo esc_attr( (string) $index ); ?>][message]" value="<?php echo esc_attr( (string) ( $message['message'] ?? '' ) ); ?>"><?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Transition behavior is shared infrastructure. Message editing will remain here after the old Surface page is removed.', 'dsa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Surface colors', 'dsa' ); ?></th>
							<td class="dsa-admin-inline-fields">
								<label><span><?php esc_html_e( 'Active', 'dsa' ); ?></span><input type="color" name="dsa_theme[active_color]" value="<?php echo esc_attr( (string) ( $theme['active_color'] ?? '#8f8f98' ) ); ?>"></label>
								<label><span><?php esc_html_e( 'Hover', 'dsa' ); ?></span><input type="color" name="dsa_theme[hover_color]" value="<?php echo esc_attr( (string) ( $theme['hover_color'] ?? '#24c6a1' ) ); ?>"></label>
								<label><span><?php esc_html_e( 'Hero text', 'dsa' ); ?></span><input type="text" name="dsa_theme[hero_text_color]" value="<?php echo esc_attr( (string) ( $theme['hero_text_color'] ?? 'rgba(20,24,34,0.18)' ) ); ?>" placeholder="rgba(20,24,34,0.18)"></label>
								<p class="description"><?php esc_html_e( 'These are the DSA runtime colors used by Classic and Sheet themes. Universal Kiwe framework tokens stay governed in Kiwe > Framework.', 'dsa' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Theme', 'dsa' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save_dock_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to manage Dock settings.', 'dsa' ), esc_html__( 'Permission denied', 'dsa' ), [ 'response' => 403 ] );
		check_admin_referer( 'dsa_save_dock_settings' );
		$settings = $this->settings->all();
		$current = (array) ( $settings['dock'] ?? [] );
		$input = isset( $_POST['dock'] ) && is_array( $_POST['dock'] ) ? wp_unslash( $_POST['dock'] ) : [];
		$settings['dock'] = $this->sanitize_dock_settings( $input, $current );
		$this->settings->update( $settings );
		wp_safe_redirect( add_query_arg( 'settings-updated', '1', admin_url( 'admin.php?page=kiwe-dock' ) ) );
		exit;
	}

	public function render_search_page(): void {
		$defaults = $this->settings->defaults()['search'];
		$config   = wp_parse_args( $this->settings->all()['search'] ?? [], $defaults );
		$families = wp_parse_args( is_array( $config['families'] ?? null ) ? $config['families'] : [], $defaults['families'] );
		$custom_taxonomies = implode( ', ', array_filter( array_map( 'sanitize_key', (array) ( $config['custom_taxonomies'] ?? [] ) ) ) );
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Search', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Govern the Search Surface, its page context, Bricks query bridge, quick actions, and progressive alphabet index.', 'dsa' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dsa_save_settings' ); ?>
				<input type="hidden" name="action" value="dsa_save_settings">
				<input type="hidden" name="_dsa_redirect" value="kiwe-search">
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e( 'Search families', 'dsa' ); ?></th><td>
						<label><input type="checkbox" name="search[families][products]" value="1" <?php checked( ! empty( $families['products'] ) ); ?>> <?php esc_html_e( 'Products when WooCommerce is active', 'dsa' ); ?></label><br>
						<label><input type="checkbox" name="search[families][posts]" value="1" <?php checked( ! empty( $families['posts'] ) ); ?>> <?php esc_html_e( 'Posts', 'dsa' ); ?></label><br>
						<label><input type="checkbox" name="search[families][authors]" value="1" <?php checked( ! empty( $families['authors'] ) ); ?>> <?php esc_html_e( 'Authors', 'dsa' ); ?></label>
					</td></tr>
					<tr><th scope="row"><label for="dsa-search-custom-taxonomies"><?php esc_html_e( 'Custom category filters', 'dsa' ); ?></label></th><td><input id="dsa-search-custom-taxonomies" class="regular-text" name="search[custom_taxonomies]" value="<?php echo esc_attr( $custom_taxonomies ); ?>" placeholder="category, product_cat"><p class="description"><?php esc_html_e( 'Comma-separated public taxonomy slugs. Kiwe adds a Categories filter and searches matching terms without changing Products, Posts, or Authors.', 'dsa' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Context awareness', 'dsa' ); ?></th><td><label><input type="checkbox" name="search[context_aware]" value="1" <?php checked( ! empty( $config['context_aware'] ) ); ?>> <?php esc_html_e( 'Preselect Products, Posts, or Authors from the current archive/template', 'dsa' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Progressive alphabet', 'dsa' ); ?></th><td><label><input type="checkbox" name="search[alphabet_enabled]" value="1" <?php checked( ! empty( $config['alphabet_enabled'] ) ); ?>> <?php esc_html_e( 'Show only available title initials and drill from A to Aa, Ab, Ac…', 'dsa' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Product quick add', 'dsa' ); ?></th><td><label><input type="checkbox" name="search[product_add_enabled]" value="1" <?php checked( ! empty( $config['product_add_enabled'] ) ); ?>> <?php esc_html_e( 'Show a + action on purchasable product result cards', 'dsa' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Bricks Filter Search', 'dsa' ); ?></th><td><label><input type="checkbox" name="search[bricks_bridge_enabled]" value="1" <?php checked( ! empty( $config['bricks_bridge_enabled'] ) ); ?>> <?php esc_html_e( 'Synchronize DSA terms with native Bricks Live search / Filter - Search queries', 'dsa' ); ?></label><p class="description"><?php esc_html_e( 'In the Bricks editor, target the intended query with a Filter - Search element and enable Use as DSA Search bridge. Kiwe reads the query ID generated by Bricks; no site-specific ID is entered here. Bricks Live Search remains optional.', 'dsa' ); ?></p></td></tr>
					<tr><th scope="row"><label for="dsa-search-limit"><?php esc_html_e( 'Results per family', 'dsa' ); ?></label></th><td><input id="dsa-search-limit" type="number" min="1" max="12" name="search[result_limit]" value="<?php echo esc_attr( (string) absint( $config['result_limit'] ) ); ?>"></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Save Search Settings', 'dsa' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dsa-admin__panel">
				<?php wp_nonce_field( 'dsa_clear_search_cache' ); ?>
				<input type="hidden" name="action" value="dsa_clear_search_cache">
				<h2><?php esc_html_e( 'Search cache', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Advance the Search cache generation after catalog, integration, or test changes. Existing entries expire naturally and are no longer read.', 'dsa' ); ?></p>
				<?php submit_button( __( 'Clear Search Cache', 'dsa' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function render_menu_page(): void {
		$dock       = wp_parse_args( $this->settings->all()['dock'] ?? [], $this->settings->defaults()['dock'] );
		$nav_ids    = array_values( array_filter( array_map( 'absint', (array) ( $dock['menu_nav_ids'] ?? [] ) ) ) );
		if ( ! $nav_ids && ! empty( $dock['menu_nav_id'] ) ) {
			$nav_ids[] = absint( $dock['menu_nav_id'] );
		}
		$locations  = wp_parse_args( is_array( $dock['menu_context_locations'] ?? null ) ? $dock['menu_context_locations'] : [], $this->settings->defaults()['dock']['menu_context_locations'] );
		$page_ids   = array_values( array_filter( array_map( 'absint', (array) ( $dock['menu_context_page_ids'] ?? [] ) ) ) );
		$levels     = array_values( array_intersect( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], (array) ( $dock['menu_context_heading_levels'] ?? [ 'h1', 'h2', 'h3' ] ) ) );
		$pages      = get_pages( [ 'post_status' => 'publish', 'sort_column' => 'post_title', 'sort_order' => 'ASC' ] );
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Menu', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Combine durable site navigation with route-aware headings from the current page. Menu is a client of the Kiwe Context Engine.', 'dsa' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dsa_save_menu_settings' ); ?>
				<input type="hidden" name="action" value="dsa_save_menu_settings">
				<section class="dsa-admin__panel">
					<h2><?php esc_html_e( 'Menu presentation', 'dsa' ); ?></h2>
					<table class="form-table" role="presentation"><tbody>
						<tr><th scope="row"><label for="dsa-menu-label"><?php esc_html_e( 'Menu label', 'dsa' ); ?></label></th><td><input id="dsa-menu-label" class="regular-text" type="text" name="dock[menu_label]" value="<?php echo esc_attr( (string) $dock['menu_label'] ); ?>"></td></tr>
						<tr><th scope="row"><label for="dsa-menu-heading"><?php esc_html_e( 'Heading tag', 'dsa' ); ?></label></th><td><select id="dsa-menu-heading" name="dock[menu_heading_tag]"><?php foreach ( [ 'span', 'p', 'h1', 'h2', 'h3', 'h4' ] as $tag ) : ?><option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $dock['menu_heading_tag'], $tag ); ?>><?php echo esc_html( strtoupper( $tag ) ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Administrator utility', 'dsa' ); ?></th><td><label><input type="checkbox" name="dock[admin_dashboard_link_enabled]" value="1" <?php checked( ! empty( $dock['admin_dashboard_link_enabled'] ) ); ?>> <?php esc_html_e( 'Show the compact Dashboard link to administrators', 'dsa' ); ?></label></td></tr>
					</tbody></table>
				</section>

				<section class="dsa-admin__panel">
					<h2><?php esc_html_e( 'WordPress menus', 'dsa' ); ?></h2>
					<p><?php esc_html_e( 'Select one or more menus created under Appearance or the Customizer. Their generated IDs are resolved by WordPress on each site.', 'dsa' ); ?></p>
					<div class="dsa-admin__checks"><?php foreach ( $this->nav_menu_options() as $menu_id => $menu_name ) : ?><label><input type="checkbox" name="dock[menu_nav_ids][]" value="<?php echo esc_attr( (string) $menu_id ); ?>" <?php checked( in_array( (int) $menu_id, $nav_ids, true ) ); ?>> <?php echo esc_html( $menu_name ); ?></label><?php endforeach; ?></div>
				</section>

				<section class="dsa-admin__panel">
					<h2><?php esc_html_e( 'Custom menu targets', 'dsa' ); ?></h2>
					<div class="dsa-admin-builder" data-dsa-menu-builder>
						<input class="regular-text dsa-admin-search" type="search" placeholder="<?php echo esc_attr__( 'Search posts, pages, categories...', 'dsa' ); ?>" autocomplete="off" data-dsa-menu-search>
						<div class="dsa-admin-search-results" data-dsa-menu-results hidden></div>
						<div class="dsa-admin-rows" data-dsa-menu-items>
							<?php foreach ( $this->menu_items_for_admin( $dock ) as $index => $item ) : ?>
								<div class="dsa-admin-row" data-dsa-menu-row>
									<label><span><?php esc_html_e( 'Title', 'dsa' ); ?></span><input type="text" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][title]" value="<?php echo esc_attr( $item['title'] ); ?>"></label>
									<label><span><?php esc_html_e( 'URL', 'dsa' ); ?></span><input type="url" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][url]" value="<?php echo esc_url( $item['url'] ); ?>"></label>
									<label><span><?php esc_html_e( 'Type', 'dsa' ); ?></span><input type="text" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][type]" value="<?php echo esc_attr( $item['type'] ); ?>"></label>
									<label><span><?php esc_html_e( 'Image URL', 'dsa' ); ?></span><input type="url" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][image]" value="<?php echo esc_url( $item['image'] ?? '' ); ?>"></label>
									<input type="hidden" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][object_id]" value="<?php echo esc_attr( (string) ( $item['object_id'] ?? 0 ) ); ?>"><input type="hidden" name="dock[menu_items][<?php echo esc_attr( (string) $index ); ?>][object_type]" value="<?php echo esc_attr( (string) ( $item['object_type'] ?? '' ) ); ?>">
									<button class="button dsa-admin-remove" type="button" data-dsa-remove-row><?php esc_html_e( 'Remove', 'dsa' ); ?></button>
								</div>
							<?php endforeach; ?>
						</div>
						<button class="button dsa-admin-add" type="button" data-dsa-add-menu-row><?php esc_html_e( '+ Add custom link', 'dsa' ); ?></button>
					</div>
				</section>

				<section class="dsa-admin__panel">
					<h2><?php esc_html_e( 'Contextual table of contents', 'dsa' ); ?></h2>
					<table class="form-table" role="presentation"><tbody>
						<tr><th scope="row"><?php esc_html_e( 'Context Engine', 'dsa' ); ?></th><td><label><input type="checkbox" name="dock[menu_context_enabled]" value="1" <?php checked( ! empty( $dock['menu_context_enabled'] ) ); ?>> <?php esc_html_e( 'Show headings from the current page inside Menu DSA', 'dsa' ); ?></label></td></tr>
						<tr><th scope="row"><label for="dsa-menu-context-title"><?php esc_html_e( 'Section title', 'dsa' ); ?></label></th><td><input id="dsa-menu-context-title" class="regular-text" type="text" name="dock[menu_context_title]" value="<?php echo esc_attr( (string) $dock['menu_context_title'] ); ?>"></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Heading levels', 'dsa' ); ?></th><td class="dsa-admin__checks"><?php foreach ( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] as $level ) : ?><label><input type="checkbox" name="dock[menu_context_heading_levels][]" value="<?php echo esc_attr( $level ); ?>" <?php checked( in_array( $level, $levels, true ) ); ?>> <?php echo esc_html( strtoupper( $level ) ); ?></label><?php endforeach; ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Routes', 'dsa' ); ?></th><td class="dsa-admin__checks"><?php foreach ( [ 'everywhere' => __( 'Everywhere', 'dsa' ), 'single_post' => __( 'Single posts', 'dsa' ), 'single_product' => __( 'Single products', 'dsa' ), 'front_page' => __( 'Home / front page', 'dsa' ), 'selected_pages' => __( 'Selected pages', 'dsa' ) ] as $key => $route_label ) : ?><label><input type="checkbox" name="dock[menu_context_locations][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $locations[ $key ] ) ); ?>> <?php echo esc_html( $route_label ); ?></label><?php endforeach; ?></td></tr>
						<tr><th scope="row"><label for="dsa-menu-context-pages"><?php esc_html_e( 'Selected pages', 'dsa' ); ?></label></th><td><select id="dsa-menu-context-pages" name="dock[menu_context_page_ids][]" multiple size="8" class="regular-text"><?php foreach ( $pages as $page ) : ?><option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( in_array( (int) $page->ID, $page_ids, true ) ); ?>><?php echo esc_html( (string) $page->post_title ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Use Ctrl/Cmd to select more than one page.', 'dsa' ); ?></p></td></tr>
					</tbody></table>
				</section>
				<?php submit_button( __( 'Save Menu', 'dsa' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save_menu_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Menu settings.', 'dsa' ), esc_html__( 'Permission denied', 'dsa' ), [ 'response' => 403 ] );
		}
		check_admin_referer( 'dsa_save_menu_settings' );
		$settings = $this->settings->all();
		$dock     = $this->sanitize_menu_settings( $_POST['dock'] ?? [], $settings['dock'] ?? [] );
		$settings['dock'] = array_replace( $settings['dock'] ?? [], $dock );
		$this->settings->update( $settings );
		wp_safe_redirect( add_query_arg( 'settings-updated', '1', admin_url( 'admin.php?page=kiwe-menu' ) ) );
		exit;
	}

	public function clear_search_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to clear Search caches.', 'dsa' ), esc_html__( 'Permission denied', 'dsa' ), [ 'response' => 403 ] );
		}

		check_admin_referer( 'dsa_clear_search_cache' );
		if ( $this->search ) {
			$this->search->invalidate();
		}

		wp_safe_redirect( add_query_arg( 'search-cache-cleared', '1', admin_url( 'admin.php?page=kiwe-search' ) ) );
		exit;
	}

	public function render_secure_page(): void {
		$settings = $this->settings->all();
		$secure   = wp_parse_args( $settings['secure'] ?? [], $this->settings->defaults()['secure'] );
		$roles    = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : [];
		$loaded   = function_exists( 'stp_cfg' );
		$ip_resolution = class_exists( '\\DSA\\Secure\\SecureTrack_Ip_Service' ) ? \DSA\Secure\SecureTrack_Ip_Service::resolution_details() : [];
		$tabs     = $this->secure_tabs();
		$active   = sanitize_key( wp_unslash( $_GET['tab'] ?? 'events' ) );

		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'events';
		}

		?>
		<div class="wrap dsa-admin dsa-secure-admin">
			<h1><?php esc_html_e( 'Kiwe Secure', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'SecureTrack is controlled by Kiwe. Keep it off until you are ready to test protections, then enable modules intentionally.', 'dsa' ); ?></p>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Secure settings saved.', 'dsa' ); ?></p>
				</div>
			<?php endif; ?>

			<section class="dsa-admin__panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>
					<input type="hidden" name="action" value="dsa_save_settings">
					<input type="hidden" name="_dsa_redirect" value="kiwe-secure">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'SecureTrack Engine', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="secure[enabled]" value="1" <?php checked( ! empty( $secure['enabled'] ) ); ?>>
										<?php esc_html_e( 'Enable bundled SecureTrack', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Off by default. Save, then reload this page to load or unload the SecureTrack engine.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Role-Based Auto Logout', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label>
										<input type="checkbox" name="secure[auto_logout_enabled]" value="1" <?php checked( ! empty( $secure['auto_logout_enabled'] ) ); ?>>
										<?php esc_html_e( 'Enable idle auto logout', 'dsa' ); ?>
									</label>
									<label>
										<span><?php esc_html_e( 'Minutes', 'dsa' ); ?></span>
										<input class="small-text" type="number" min="1" max="1440" name="secure[auto_logout_minutes]" value="<?php echo esc_attr( (string) ( $secure['auto_logout_minutes'] ?? 30 ) ); ?>">
									</label>
									<div class="dsa-admin__checks">
										<?php foreach ( $roles as $slug => $label ) : ?>
											<label><input type="checkbox" name="secure[auto_logout_roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) ( $secure['auto_logout_roles'] ?? [] ), true ) ); ?>> <?php echo esc_html( translate_user_role( $label ) ); ?></label>
										<?php endforeach; ?>
									</div>
									<p class="description"><?php esc_html_e( 'Disabled by default. If enabled with no selected roles, no one is auto-logged-out.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="dsa-trusted-proxy-cidrs"><?php esc_html_e( 'Trusted Proxy CIDRs', 'dsa' ); ?></label></th>
								<td>
									<textarea id="dsa-trusted-proxy-cidrs" class="large-text code" rows="5" name="secure[trusted_proxy_cidrs]" placeholder="203.0.113.0/24"><?php echo esc_textarea( (string) ( $secure['trusted_proxy_cidrs'] ?? '' ) ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Optional, one IPv4/IPv6 CIDR per line. Add only proxy ranges confirmed by your host. Cloudflare ranges are built in; untrusted forwarded headers are ignored.', 'dsa' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Save Kiwe Secure', 'dsa' ) ); ?>
				</form>
			</section>
			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Request Identity', 'dsa' ); ?></h2>
				<p><?php echo esc_html( sprintf( __( 'SecureTrack resolves this request as %1$s from %2$s. Direct peer: %3$s.', 'dsa' ), (string) ( $ip_resolution['resolved'] ?? '' ), sanitize_key( (string) ( $ip_resolution['source'] ?? 'remote_addr' ) ), (string) ( $ip_resolution['remote'] ?? '' ) ) ); ?></p>
				<?php if ( ! empty( $ip_resolution['forwarded_ignored'] ) ) : ?><p class="description"><?php esc_html_e( 'Forwarded headers were present but ignored because the direct peer is not trusted. Add only host-confirmed proxy CIDRs above.', 'dsa' ); ?></p><?php endif; ?>
			</section>

			<?php if ( ! $loaded ) : ?>
				<section class="dsa-admin__panel">
					<h2><?php esc_html_e( 'SecureTrack is off', 'dsa' ); ?></h2>
					<p><?php esc_html_e( 'Enable the engine above and reload to access Secure tabs. This keeps a broken security module from taking down wp-admin.', 'dsa' ); ?></p>
				</section>
			<?php else : ?>
				<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Kiwe Secure sections', 'dsa' ); ?>">
					<?php foreach ( $tabs as $id => $tab ) : ?>
						<a class="nav-tab <?php echo $active === $id ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => 'kiwe-secure', 'tab' => $id ], admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $tab['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>
				<section class="dsa-admin__panel dsa-secure-admin__tab">
					<?php $this->render_secure_tab( $tabs[ $active ]['callback'] ); ?>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_email_page(): void {
		$config = wp_parse_args( $this->settings->all()['email'] ?? [], $this->settings->defaults()['email'] );
		$smtp = wp_parse_args( $config['smtp'] ?? [], $this->settings->defaults()['email']['smtp'] );
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'settings' ) );
		$tab = in_array( $tab, [ 'settings', 'diagnostics' ], true ) ? $tab : 'settings';
		$diagnostics = $this->email ? $this->email->diagnostics() : [];
		?>
		<div class="wrap dsa-admin dsa-store-manager">
			<h1><?php esc_html_e( 'Kiwe Email', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'WordPress provides wp_mail. Kiwe configures and tests the delivery path your host or SMTP provider uses for PhoneKey and cart recovery messages.', 'dsa' ); ?></p>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Email settings saved.', 'dsa' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['email-test'] ) ) : ?>
				<div class="notice <?php echo 'sent' === $_GET['email-test'] ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['message'] ?? ( 'sent' === $_GET['email-test'] ? __( 'Test email handed to the mail transport.', 'dsa' ) : __( 'Test email failed.', 'dsa' ) ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php $this->render_simple_admin_tabs( 'kiwe-email', [ 'settings' => __( 'Settings', 'dsa' ), 'diagnostics' => __( 'Diagnostics', 'dsa' ) ], $tab ); ?>

			<?php if ( 'settings' === $tab ) : ?>
				<section class="dsa-admin__panel">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dsa_save_settings"><input type="hidden" name="_dsa_redirect" value="kiwe-email">
						<?php wp_nonce_field( 'dsa_save_settings' ); ?>
						<table class="form-table" role="presentation"><tbody>
							<tr><th scope="row"><?php esc_html_e( 'Email Delivery', 'dsa' ); ?></th><td><label><input type="checkbox" name="email[enabled]" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>> <?php esc_html_e( 'Enable Kiwe email delivery', 'dsa' ); ?></label></td></tr>
							<tr><th scope="row"><label for="dsa-email-transport"><?php esc_html_e( 'Transport', 'dsa' ); ?></label></th><td><select id="dsa-email-transport" name="email[transport]"><option value="wordpress" <?php selected( $config['transport'], 'wordpress' ); ?>><?php esc_html_e( 'WordPress / host mail', 'dsa' ); ?></option><option value="smtp" <?php selected( $config['transport'], 'smtp' ); ?>><?php esc_html_e( 'SMTP', 'dsa' ); ?></option></select><p class="description"><?php esc_html_e( 'Use WordPress/host mail when Hostinger or another mail plugin already handles delivery.', 'dsa' ); ?></p></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Sender', 'dsa' ); ?></th><td><input class="regular-text" type="text" name="email[from_name]" value="<?php echo esc_attr( $config['from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"> <input class="regular-text" type="email" name="email[from_email]" value="<?php echo esc_attr( $config['from_email'] ); ?>" placeholder="name@example.com"></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'SMTP Server', 'dsa' ); ?></th><td><input class="regular-text" type="text" name="email[smtp][host]" value="<?php echo esc_attr( $smtp['host'] ); ?>" placeholder="smtp.example.com"> <input class="small-text" type="number" min="1" max="65535" name="email[smtp][port]" value="<?php echo esc_attr( (string) $smtp['port'] ); ?>"> <select name="email[smtp][encryption]"><option value="tls" <?php selected( $smtp['encryption'], 'tls' ); ?>>TLS</option><option value="ssl" <?php selected( $smtp['encryption'], 'ssl' ); ?>>SSL</option><option value="none" <?php selected( $smtp['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'dsa' ); ?></option></select></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'SMTP Authentication', 'dsa' ); ?></th><td><label><input type="checkbox" name="email[smtp][auth]" value="1" <?php checked( ! empty( $smtp['auth'] ) ); ?>> <?php esc_html_e( 'Authenticate with the SMTP server', 'dsa' ); ?></label><p><input class="regular-text" type="text" autocomplete="off" name="email[smtp][username]" value="<?php echo esc_attr( $smtp['username'] ); ?>" placeholder="<?php esc_attr_e( 'Username', 'dsa' ); ?>"> <input class="regular-text" type="password" autocomplete="new-password" name="email[smtp][password]" value="" placeholder="<?php echo esc_attr( ! empty( $smtp['password'] ) ? __( 'Saved securely; leave blank to keep', 'dsa' ) : __( 'Password', 'dsa' ) ); ?>"></p></td></tr>
						</tbody></table>
						<?php submit_button( __( 'Save Email Settings', 'dsa' ) ); ?>
					</form>
				</section>
			<?php else : ?>
				<section class="dsa-admin__panel">
					<h2><?php esc_html_e( 'Delivery Test', 'dsa' ); ?></h2>
					<p><?php echo esc_html( sprintf( __( 'Transport: %s', 'dsa' ), $diagnostics['transport'] ?? 'wordpress' ) ); ?> <?php if ( 'smtp' === ( $diagnostics['transport'] ?? '' ) ) : ?><?php echo esc_html( ! empty( $diagnostics['smtp_ready'] ) ? __( 'SMTP configuration is complete.', 'dsa' ) : __( 'SMTP configuration is incomplete.', 'dsa' ) ); ?><?php endif; ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dsa_email_test"><?php wp_nonce_field( 'dsa_email_test' ); ?><input class="regular-text" type="email" required name="recipient" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"><button class="button button-primary" type="submit"><?php esc_html_e( 'Send Test Email', 'dsa' ); ?></button></form>
					<?php if ( ! empty( $diagnostics['last_failure']['message'] ) ) : ?><p><strong><?php esc_html_e( 'Last transport error:', 'dsa' ); ?></strong> <?php echo esc_html( $diagnostics['last_failure']['message'] ); ?></p><?php endif; ?>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_abandoned_cart_page(): void {
		if ( ! $this->abandoned_carts ) {
			wp_die( esc_html__( 'The Kiwe abandoned-cart service is unavailable.', 'dsa' ) );
		}

		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'analytics' ) );
		$tabs = [ 'analytics' => __( 'Analytics', 'dsa' ), 'reminders' => __( 'Reminders', 'dsa' ), 'settings' => __( 'Settings & Channels', 'dsa' ), 'delivery' => __( 'Delivery Log', 'dsa' ) ];
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'analytics';
		$config = $this->abandoned_carts->config();
		?>
		<div class="wrap dsa-admin dsa-store-manager">
			<h1><?php esc_html_e( 'Kiwe Abandoned Cart', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Follow a cart from anonymous activity to a PhoneKey or Woo account, then recover it through a deliberate admin reminder. Raw IP addresses are never stored.', 'dsa' ); ?></p>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Abandoned-cart settings saved.', 'dsa' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['reminder'] ) ) : ?><div class="notice <?php echo 'sent' === $_GET['reminder'] ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['message'] ?? '' ) ) ); ?></p></div><?php endif; ?>
			<?php $this->render_simple_admin_tabs( 'kiwe-abandoned-cart', $tabs, $tab ); ?>

			<?php if ( 'analytics' === $tab ) : ?>
				<?php $days = absint( $_GET['days'] ?? 30 ); $days = in_array( $days, [ 1, 7, 30, 90, 0 ], true ) ? $days : 30; $summary = $this->abandoned_carts->analytics_summary( $days ); ?>
				<form class="dsa-lpm-toolbar" method="get"><input type="hidden" name="page" value="kiwe-abandoned-cart"><input type="hidden" name="tab" value="analytics"><select name="days"><option value="1" <?php selected( $days, 1 ); ?>><?php esc_html_e( 'Today', 'dsa' ); ?></option><option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( '7 days', 'dsa' ); ?></option><option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( '30 days', 'dsa' ); ?></option><option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( '90 days', 'dsa' ); ?></option><option value="0" <?php selected( $days, 0 ); ?>><?php esc_html_e( 'All time', 'dsa' ); ?></option></select><button class="button" type="submit"><?php esc_html_e( 'Apply', 'dsa' ); ?></button></form>
				<div class="dsa-lpm-summary">
					<?php foreach ( [ 'carts' => __( 'Tracked carts', 'dsa' ), 'identified' => __( 'Identified', 'dsa' ), 'abandoned' => __( 'Abandoned', 'dsa' ), 'reminded' => __( 'Reminded', 'dsa' ), 'recovered' => __( 'Recovered', 'dsa' ), 'converted' => __( 'Converted', 'dsa' ) ] as $key => $label ) : ?><div class="dsa-lpm-stat"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( (string) $summary[ $key ] ); ?></strong></div><?php endforeach; ?>
					<div class="dsa-lpm-stat"><span><?php esc_html_e( 'Recovered revenue', 'dsa' ); ?></span><strong><?php echo function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $summary['recovered_revenue'] ) ) : esc_html( (string) $summary['recovered_revenue'] ); ?></strong></div>
				</div>
				<section class="dsa-admin__panel"><h2><?php esc_html_e( 'Identity Journey', 'dsa' ); ?></h2><p><?php esc_html_e( 'Anonymous activity begins with salted visitor and Woo-session hashes. When PhoneKey or WordPress identifies the same browser, the open cart is linked to that user ID. Contact details are resolved only when an authorized admin sends a reminder.', 'dsa' ); ?></p></section>
			<?php elseif ( 'reminders' === $tab ) : ?>
				<?php $rows = $this->abandoned_carts->reminder_rows( 'abandoned', 150 ); ?>
				<section class="dsa-admin__panel"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Cart', 'dsa' ); ?></th><th><?php esc_html_e( 'Identity', 'dsa' ); ?></th><th><?php esc_html_e( 'Last activity', 'dsa' ); ?></th><th><?php esc_html_e( 'Reminders', 'dsa' ); ?></th><th><?php esc_html_e( 'Actions', 'dsa' ); ?></th></tr></thead><tbody>
				<?php if ( ! $rows ) : ?><tr><td colspan="5"><?php esc_html_e( 'No abandoned carts are ready for a reminder.', 'dsa' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $rows as $row ) : ?><tr><td><strong>#<?php echo esc_html( (string) $row['id'] ); ?> · <?php echo esc_html( $row['display_total'] ); ?></strong><br><?php echo esc_html( implode( ', ', array_slice( $row['item_names'], 0, 4 ) ) ); ?></td><td><?php echo $row['user_id'] ? esc_html( sprintf( __( 'User #%d', 'dsa' ), $row['user_id'] ) ) : esc_html__( 'Anonymous', 'dsa' ); ?><br><?php foreach ( $row['contact_channels'] as $contact ) : ?><?php if ( ! empty( $contact['masked'] ) ) : ?><span><?php echo esc_html( $contact['masked'] ); ?></span> <?php endif; ?><?php endforeach; ?></td><td><?php echo esc_html( $row['last_activity_at'] ); ?></td><td><?php echo esc_html( (string) $row['reminder_count'] ); ?><?php if ( ! empty( $row['last_reminder_at'] ) ) : ?><br><small><?php echo esc_html( $row['last_reminder_at'] ); ?></small><?php endif; ?></td><td>
					<?php foreach ( [ 'email' => __( 'Email', 'dsa' ), 'sms' => __( 'SMS', 'dsa' ), 'whatsapp' => __( 'WhatsApp', 'dsa' ) ] as $channel => $label ) : ?><?php if ( ! empty( $row[ 'can_' . $channel ] ) ) : ?><form style="display:inline-block;margin-right:4px" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dsa_abandoned_cart_reminder"><input type="hidden" name="cart_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>"><input type="hidden" name="channel" value="<?php echo esc_attr( $channel ); ?>"><?php wp_nonce_field( 'dsa_abandoned_cart_reminder_' . $row['id'] ); ?><button class="button" type="submit"><?php echo esc_html( $label ); ?></button></form><?php endif; ?><?php endforeach; ?>
					<?php if ( empty( $row['can_email'] ) && empty( $row['can_sms'] ) && empty( $row['can_whatsapp'] ) ) : ?><em><?php esc_html_e( 'No configured contact channel', 'dsa' ); ?></em><?php endif; ?>
				</td></tr><?php endforeach; ?></tbody></table></section>
			<?php elseif ( 'settings' === $tab ) : ?>
				<section class="dsa-admin__panel"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dsa_save_settings"><input type="hidden" name="_dsa_redirect" value="kiwe-abandoned-cart"><?php wp_nonce_field( 'dsa_save_settings' ); ?>
				<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Tracking', 'dsa' ); ?></th><td><label><input type="checkbox" name="abandoned_cart[enabled]" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>> <?php esc_html_e( 'Track privacy-safe cart state', 'dsa' ); ?></label><br><label><input type="checkbox" name="abandoned_cart[manual_reminders_enabled]" value="1" <?php checked( ! empty( $config['manual_reminders_enabled'] ) ); ?>> <?php esc_html_e( 'Allow authorized admins to send manual reminders', 'dsa' ); ?></label></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Timing', 'dsa' ); ?></th><td><label><?php esc_html_e( 'Abandoned after', 'dsa' ); ?> <input class="small-text" type="number" min="15" max="43200" name="abandoned_cart[inactivity_minutes]" value="<?php echo esc_attr( (string) $config['inactivity_minutes'] ); ?>"> <?php esc_html_e( 'minutes', 'dsa' ); ?></label> <label><?php esc_html_e( 'Unchanged-cart heartbeat', 'dsa' ); ?> <input class="small-text" type="number" min="1" max="60" name="abandoned_cart[heartbeat_minutes]" value="<?php echo esc_attr( (string) ( $config['heartbeat_minutes'] ?? 5 ) ); ?>"> <?php esc_html_e( 'minutes', 'dsa' ); ?></label> <label><?php esc_html_e( 'Cooldown', 'dsa' ); ?> <input class="small-text" type="number" min="1" max="720" name="abandoned_cart[cooldown_hours]" value="<?php echo esc_attr( (string) $config['cooldown_hours'] ); ?>"> <?php esc_html_e( 'hours', 'dsa' ); ?></label> <label><?php esc_html_e( 'Maximum', 'dsa' ); ?> <input class="small-text" type="number" min="1" max="10" name="abandoned_cart[max_reminders]" value="<?php echo esc_attr( (string) $config['max_reminders'] ); ?>"></label><p class="description"><?php esc_html_e( 'Unchanged carts write only at this heartbeat. Quantity, product, identity, checkout, clear, and conversion changes still write immediately.', 'dsa' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Email Copy', 'dsa' ); ?></th><td><input class="large-text" type="text" name="abandoned_cart[email_subject]" value="<?php echo esc_attr( $config['email_subject'] ); ?>"><textarea class="large-text" rows="5" name="abandoned_cart[email_message]"><?php echo esc_textarea( $config['email_message'] ); ?></textarea><p class="description"><?php esc_html_e( 'Tokens: {site_name}, {item_count}, {cart_total}, {recovery_url}', 'dsa' ); ?></p></td></tr>
				<?php foreach ( [ 'sms' => 'SMS', 'whatsapp' => 'WhatsApp' ] as $channel => $label ) : $channel_config = $config['channels'][ $channel ] ?? []; ?><tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><label><input type="checkbox" name="abandoned_cart[channels][<?php echo esc_attr( $channel ); ?>][enabled]" value="1" <?php checked( ! empty( $channel_config['enabled'] ) ); ?>> <?php esc_html_e( 'Enable generic webhook adapter', 'dsa' ); ?></label><p><input class="large-text" type="url" name="abandoned_cart[channels][<?php echo esc_attr( $channel ); ?>][webhook_url]" value="<?php echo esc_attr( $channel_config['webhook_url'] ?? '' ); ?>" placeholder="https://provider.example/send"></p><p><input class="regular-text" type="password" autocomplete="new-password" name="abandoned_cart[channels][<?php echo esc_attr( $channel ); ?>][api_token]" value="" placeholder="<?php echo esc_attr( ! empty( $channel_config['api_token'] ) ? __( 'Token saved; leave blank to keep', 'dsa' ) : __( 'Bearer API token', 'dsa' ) ); ?>"> <input class="regular-text" type="text" name="abandoned_cart[channels][<?php echo esc_attr( $channel ); ?>][sender]" value="<?php echo esc_attr( $channel_config['sender'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Sender ID or number', 'dsa' ); ?>"></p><textarea class="large-text" rows="3" name="abandoned_cart[<?php echo esc_attr( $channel ); ?>_message]"><?php echo esc_textarea( $config[ $channel . '_message' ] ?? '' ); ?></textarea></td></tr><?php endforeach; ?>
				</tbody></table><?php submit_button( __( 'Save Abandoned Cart Settings', 'dsa' ) ); ?></form></section>
			<?php else : ?>
				<?php $logs = $this->abandoned_carts->delivery_logs( 150 ); ?><section class="dsa-admin__panel"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Time', 'dsa' ); ?></th><th><?php esc_html_e( 'Cart', 'dsa' ); ?></th><th><?php esc_html_e( 'Channel', 'dsa' ); ?></th><th><?php esc_html_e( 'Status', 'dsa' ); ?></th><th><?php esc_html_e( 'Result', 'dsa' ); ?></th></tr></thead><tbody><?php if ( ! $logs ) : ?><tr><td colspan="5"><?php esc_html_e( 'No reminder deliveries recorded yet.', 'dsa' ); ?></td></tr><?php endif; ?><?php foreach ( $logs as $log ) : ?><tr><td><?php echo esc_html( $log['created_at'] ); ?></td><td>#<?php echo esc_html( (string) $log['cart_id'] ); ?></td><td><?php echo esc_html( ucfirst( $log['channel'] ) ); ?></td><td><?php echo esc_html( ucfirst( $log['status'] ) ); ?></td><td><?php echo esc_html( $log['message'] ); ?></td></tr><?php endforeach; ?></tbody></table></section>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_woocommerce_page(): void {
		$settings = $this->settings->all();
		$commerce = wp_parse_args( $settings['commerce'] ?? [], $this->settings->defaults()['commerce'] );
		$protected_flow = wp_parse_args( $settings['protected_flow'] ?? [], $this->settings->defaults()['protected_flow'] );
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe WooCommerce', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Controls DSA-native cart behavior and commerce surface features.', 'dsa' ); ?></p>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'WooCommerce settings saved.', 'dsa' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['bestseller-synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Bestseller categories synced.', 'dsa' ); ?></p>
				</div>
			<?php endif; ?>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Cart and Checkout Surface', 'dsa' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<input type="hidden" name="_dsa_redirect" value="kiwe-woocommerce">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'DSA Cart', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="commerce[cart_surface_enabled]" value="1" <?php checked( ! empty( $commerce['cart_surface_enabled'] ) ); ?>>
										<?php esc_html_e( 'Use the DSA dock cart as the primary cart surface', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Turn this off when the site owner wants to keep a builder or theme native mini-cart instead.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Quantity Controls', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="commerce[cart_quantity_controls]" value="1" <?php checked( ! empty( $commerce['cart_quantity_controls'] ) ); ?>>
										<?php esc_html_e( 'Allow plus/minus quantity updates inside the DSA cart', 'dsa' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'DSA Checkout', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="commerce[checkout_surface_enabled]" value="1" <?php checked( ! empty( $commerce['checkout_surface_enabled'] ) ); ?>>
										<?php esc_html_e( 'Collect WooCommerce checkout details inside the DSA Surface before the Place order page', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'On by default. WooCommerce remains the final validator and order creator; the checkout page keeps payment methods and Place order.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Commerce Badges', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="commerce[cart_badges_enabled]" value="1" <?php checked( ! empty( $commerce['cart_badges_enabled'] ) ); ?>>
										<?php esc_html_e( 'Enable stock urgency badges in DSA cart and supported builder carts', 'dsa' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Stock Urgency Badge', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label>
										<span><?php esc_html_e( 'Alert below', 'dsa' ); ?></span>
										<input class="small-text" type="number" min="1" max="999" name="commerce[stock_badge_alert_threshold]" value="<?php echo esc_attr( (string) ( $commerce['stock_badge_alert_threshold'] ?? 10 ) ); ?>">
									</label>
									<label>
										<span><?php esc_html_e( 'Urgent below', 'dsa' ); ?></span>
										<input class="small-text" type="number" min="1" max="999" name="commerce[stock_badge_urgent_threshold]" value="<?php echo esc_attr( (string) ( $commerce['stock_badge_urgent_threshold'] ?? 3 ) ); ?>">
									</label>
									<label>
										<span><?php esc_html_e( 'Alert text', 'dsa' ); ?></span>
										<input type="text" name="commerce[stock_badge_alert_text]" value="<?php echo esc_attr( (string) ( $commerce['stock_badge_alert_text'] ?? 'Only %d left' ) ); ?>">
									</label>
									<label>
										<span><?php esc_html_e( 'Urgent text', 'dsa' ); ?></span>
										<input type="text" name="commerce[stock_badge_urgent_text]" value="<?php echo esc_attr( (string) ( $commerce['stock_badge_urgent_text'] ?? 'Almost gone: %d left' ) ); ?>">
									</label>
									<p class="description"><?php esc_html_e( 'Use %d where the live stock number should appear. DSA cart uses Surface colors; Bricks native carts can style badges in the Bricks editor.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Cart Intelligence', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="commerce[cross_sells_enabled]" value="1" <?php checked( ! empty( $commerce['cross_sells_enabled'] ) ); ?>>
										<?php esc_html_e( 'Use WooCommerce cross-sells as DSA recommendation source', 'dsa' ); ?>
									</label><br>
									<label>
										<input type="checkbox" name="commerce[upsell_banner_enabled]" value="1" <?php checked( ! empty( $commerce['upsell_banner_enabled'] ) ); ?>>
										<?php esc_html_e( 'Show explicit Kiwe cart upsell offers from product-linked upsell IDs', 'dsa' ); ?>
									</label><br>
									<label>
										<input type="checkbox" name="commerce[cart_upsell_discounts_enabled]" value="1" <?php checked( ! empty( $commerce['cart_upsell_discounts_enabled'] ) ); ?>>
										<?php esc_html_e( 'Apply validated cart upsell discounts as WooCommerce fees', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Offers are view-first and never block navigation or auto-add products to cart. Discount math is server-side and only applies after a visitor explicitly adds the linked offer.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Linked Products Intelligence', 'dsa' ); ?></th>
								<td class="dsa-admin__checks">
									<label><input type="checkbox" name="commerce[linked_products_enabled]" value="1" <?php checked( ! empty( $commerce['linked_products_enabled'] ) ); ?>> <?php esc_html_e( 'Enable Kiwe linked-products service', 'dsa' ); ?></label>
									<label><input type="checkbox" name="commerce[commerce_recommendations_enabled]" value="1" <?php checked( ! empty( $commerce['commerce_recommendations_enabled'] ) ); ?>> <?php esc_html_e( 'Expose recommendations to DSA surfaces', 'dsa' ); ?></label>
									<label><input type="checkbox" name="commerce[cross_sells_product_panel_enabled]" value="1" <?php checked( ! empty( $commerce['cross_sells_product_panel_enabled'] ) ); ?>> <?php esc_html_e( 'Add category-to-cross-sell helper in Woo product editor', 'dsa' ); ?></label>
									<p class="description"><?php esc_html_e( 'This absorbs the old cross-sell manager as a native Woo helper. Product data remains standard WooCommerce cross-sells, so Bricks native upsell/cross-sell elements continue to work.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Frequently Bought Together', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label>
										<input type="checkbox" name="commerce[fbt_enabled]" value="1" <?php checked( ! empty( $commerce['fbt_enabled'] ) ); ?>>
										<?php esc_html_e( 'Show FBT rail in DSA cart and supported Bricks mini-carts', 'dsa' ); ?>
									</label>
									<label>
										<span><?php esc_html_e( 'Title', 'dsa' ); ?></span>
										<input type="text" name="commerce[fbt_title]" value="<?php echo esc_attr( (string) ( $commerce['fbt_title'] ?? 'Frequently Bought Together' ) ); ?>">
									</label>
									<label>
										<span><?php esc_html_e( 'Max cards', 'dsa' ); ?></span>
										<input class="small-text" type="number" min="1" max="12" name="commerce[fbt_max_products]" value="<?php echo esc_attr( (string) ( $commerce['fbt_max_products'] ?? 6 ) ); ?>">
									</label>
									<label>
										<input type="checkbox" name="commerce[fbt_show_out_of_stock]" value="1" <?php checked( ! empty( $commerce['fbt_show_out_of_stock'] ) ); ?>>
										<?php esc_html_e( 'Show out-of-stock products as view-only cards', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Source priority: cart cross-sells, co-purchase history, then bestseller fallback if enabled. Add buttons are explicit visitor actions.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Add To Cart Experience', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label>
										<span><?php esc_html_e( 'Store button mode', 'dsa' ); ?></span>
										<select name="commerce[add_to_cart_mode]">
											<option value="default" <?php selected( $commerce['add_to_cart_mode'] ?? 'default', 'default' ); ?>><?php esc_html_e( 'Woo/theme default', 'dsa' ); ?></option>
											<option value="plus_only" <?php selected( $commerce['add_to_cart_mode'] ?? 'default', 'plus_only' ); ?>><?php esc_html_e( 'Plus button only', 'dsa' ); ?></option>
											<option value="quantity" <?php selected( $commerce['add_to_cart_mode'] ?? 'default', 'quantity' ); ?>><?php esc_html_e( 'Always show quantity controls', 'dsa' ); ?></option>
											<option value="replace" <?php selected( $commerce['add_to_cart_mode'] ?? 'default', 'replace' ); ?>><?php esc_html_e( 'Replace button after first add', 'dsa' ); ?></option>
										</select>
									</label>
									<label><input type="checkbox" name="commerce[first_cart_confetti_enabled]" value="1" <?php checked( ! empty( $commerce['first_cart_confetti_enabled'] ) ); ?>> <?php esc_html_e( 'Celebrate only the empty-cart to first-item transition', 'dsa' ); ?></label>
									<p class="description"><?php esc_html_e( 'Global mode uses Kiwe active/hover colors and applies to simple AJAX product buttons. Bricks designers can instead use the element-level controls under Kiwe > Bricks.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Co-Purchase Upsell Sync', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label>
										<input type="checkbox" name="commerce[co_purchase_daily_sync_enabled]" value="1" <?php checked( ! empty( $commerce['co_purchase_daily_sync_enabled'] ) ); ?>>
										<?php esc_html_e( 'Daily merge co-purchased products into Woo upsells', 'dsa' ); ?>
									</label>
									<label>
										<span><?php esc_html_e( 'Depth', 'dsa' ); ?></span>
										<input class="small-text" type="number" min="1" max="50" name="commerce[co_purchase_daily_sync_depth]" value="<?php echo esc_attr( (string) ( $commerce['co_purchase_daily_sync_depth'] ?? 5 ) ); ?>">
									</label>
									<label>
										<span><?php esc_html_e( 'Mode', 'dsa' ); ?></span>
										<select name="commerce[co_purchase_daily_sync_mode]">
											<option value="merge" <?php selected( $commerce['co_purchase_daily_sync_mode'] ?? 'merge', 'merge' ); ?>><?php esc_html_e( 'Merge', 'dsa' ); ?></option>
											<option value="replace" <?php selected( $commerce['co_purchase_daily_sync_mode'] ?? 'merge', 'replace' ); ?>><?php esc_html_e( 'Replace', 'dsa' ); ?></option>
										</select>
									</label>
									<p class="description"><?php esc_html_e( 'Restores the old co-purchased upsell automation as an opt-in Kiwe service. Uses WooCommerce analytics lookup tables and standard Woo upsell IDs.', 'dsa' ); ?></p>
									<p>
										<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsa_linked_sync_co_purchase' ), 'dsa_linked_sync_co_purchase' ) ); ?>"><?php esc_html_e( 'Sync co-purchased upsells now', 'dsa' ); ?></a>
										<?php if ( get_option( 'dsa_co_purchase_last_sync' ) ) : ?>
											<span class="description"><?php echo esc_html( sprintf( __( 'Last sync: %s', 'dsa' ), get_option( 'dsa_co_purchase_last_sync' ) ) ); ?></span>
										<?php endif; ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Bestseller Categories', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<label>
										<input type="checkbox" name="commerce[bestseller_enabled]" value="1" <?php checked( ! empty( $commerce['bestseller_enabled'] ) ); ?>>
										<?php esc_html_e( 'Maintain Bestseller product categories', 'dsa' ); ?>
									</label>
									<label>
										<span><?php esc_html_e( 'Limit', 'dsa' ); ?></span>
										<input class="small-text" type="number" min="3" max="100" name="commerce[bestseller_limit]" value="<?php echo esc_attr( (string) ( $commerce['bestseller_limit'] ?? 20 ) ); ?>">
									</label>
									<label>
										<span><?php esc_html_e( 'Parent label', 'dsa' ); ?></span>
										<input type="text" name="commerce[bestseller_parent_label]" value="<?php echo esc_attr( (string) ( $commerce['bestseller_parent_label'] ?? 'Bestseller' ) ); ?>">
									</label>
									<label>
										<span><?php esc_html_e( 'Parent slug', 'dsa' ); ?></span>
										<input type="text" name="commerce[bestseller_parent_slug]" value="<?php echo esc_attr( (string) ( $commerce['bestseller_parent_slug'] ?? 'bestseller' ) ); ?>">
									</label>
									<label>
										<input type="checkbox" name="commerce[bestseller_sync_on_order]" value="1" <?php checked( ! empty( $commerce['bestseller_sync_on_order'] ) ); ?>>
										<?php esc_html_e( 'Refresh bestseller cache when orders complete or process', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Uses WooCommerce analytics lookup tables when available, then falls back to product total sales. Sync creates Week, Month, and Year child categories.', 'dsa' ); ?></p>
									<p>
										<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dsa_bestseller_sync' ), 'dsa_bestseller_sync' ) ); ?>"><?php esc_html_e( 'Sync bestsellers now', 'dsa' ); ?></a>
										<?php if ( get_option( 'dsa_bestseller_last_sync' ) ) : ?>
											<span class="description"><?php echo esc_html( sprintf( __( 'Last sync: %s', 'dsa' ), get_option( 'dsa_bestseller_last_sync' ) ) ); ?></span>
										<?php endif; ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'COD Reputation Gate', 'dsa' ); ?></th>
								<td class="dsa-admin-inline-fields">
									<?php $cod = wp_parse_args( is_array( $commerce['cod_gate'] ?? null ) ? $commerce['cod_gate'] : [], $this->settings->defaults()['commerce']['cod_gate'] ); ?>
									<label>
										<input type="checkbox" name="commerce[cod_gate][enabled]" value="1" <?php checked( ! empty( $cod['enabled'] ) ); ?>>
										<?php esc_html_e( 'Verify phone by OTP at Place Order for cash on delivery', 'dsa' ); ?>
									</label>
									<label>
										<span><?php esc_html_e( 'Strikes to block COD', 'dsa' ); ?></span>
										<input class="small-text" type="number" min="1" max="10" name="commerce[cod_gate][strikes_to_block]" value="<?php echo esc_attr( (string) ( $cod['strikes_to_block'] ?? 1 ) ); ?>">
									</label>
									<label>
										<span><?php esc_html_e( 'Skip OTP after N completed', 'dsa' ); ?></span>
										<input class="small-text" type="number" min="0" max="50" name="commerce[cod_gate][trusted_skip_after_completed]" value="<?php echo esc_attr( (string) ( $cod['trusted_skip_after_completed'] ?? 1 ) ); ?>">
									</label>
									<label>
										<span><?php esc_html_e( 'Regain COD after a strike', 'dsa' ); ?></span>
										<select name="commerce[cod_gate][regain]">
											<option value="prepaid_success" <?php selected( $cod['regain'] ?? 'prepaid_success', 'prepaid_success' ); ?>><?php esc_html_e( 'After one completed prepaid order', 'dsa' ); ?></option>
											<option value="never" <?php selected( $cod['regain'] ?? 'prepaid_success', 'never' ); ?>><?php esc_html_e( 'Never (admin clears manually)', 'dsa' ); ?></option>
										</select>
									</label>
									<label>
										<span><?php esc_html_e( 'Blocked message', 'dsa' ); ?></span>
										<input type="text" name="commerce[cod_gate][block_message]" value="<?php echo esc_attr( (string) ( $cod['block_message'] ?? '' ) ); ?>">
									</label>
									<label>
										<input type="checkbox" name="commerce[cod_gate][allow_unverified_on_failure]" value="1" <?php checked( ! empty( $cod['allow_unverified_on_failure'] ) ); ?>>
										<?php esc_html_e( 'Never block the sale if OTP cannot be sent; flag the order for phone follow-up', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Completed orders build COD reputation; cancelled COD orders add a strike. Trusted customers skip OTP. Verification is server-side on classic checkout and Store API checkout.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Protected Flow Rail', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="protected_flow[rail_enabled]" value="1" <?php checked( ! empty( $protected_flow['rail_enabled'] ) ); ?>>
										<?php esc_html_e( 'Show the protected navigation trust rail on cart, checkout, and account routes', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Off by default. Route safety still stays conservative without showing this visitor-facing box.', 'dsa' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Kiwe WooCommerce', 'dsa' ) ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	public function render_store_analytics_page(): void {
		$store = $this->store_analytics ?: new Store_Analytics_Service( $this->settings );
		$linked = $this->linked_products ?: new Linked_Products_Service( $this->settings );
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'funnel' ) );
		$has_woo = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
		$tabs = [
			'funnel'       => __( 'Funnel', 'dsa' ),
			'search'       => __( 'Search', 'dsa' ),
			'saved'        => __( 'Saved', 'dsa' ),
		];
		if ( $has_woo ) {
			$tabs += [
			'assign'       => __( 'Assign', 'dsa' ),
			'clear'        => __( 'Clear', 'dsa' ),
			'memory'       => __( 'Memory', 'dsa' ),
			'bulk-upsells' => __( 'Bulk Assign', 'dsa' ),
			'upsells'      => __( 'Current Upsells', 'dsa' ),
			'co-purchase'  => __( 'Co-Purchase Analytics', 'dsa' ),
			'cart-events'  => __( 'Cart Events', 'dsa' ),
			'bestseller'   => __( 'Bestseller', 'dsa' ),
			];
		}

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'funnel';
		}

		$categories = $has_woo ? $linked->product_categories() : [];
		?>
		<div class="wrap dsa-admin dsa-store-manager">
			<h1><?php esc_html_e( 'Analytics', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Measure visitor, identity, app, and conversion signals. WooCommerce-linked products and cart intelligence appear here only when WooCommerce is active.', 'dsa' ); ?></p>

			<?php $this->render_store_manager_notice(); ?>
			<?php $this->render_store_manager_tabs( $tabs, $tab ); ?>

			<?php
			switch ( $tab ) {
				case 'funnel':
					$this->render_store_tab_funnel( $store );
					break;
				case 'saved':
					$this->render_store_tab_saved();
					break;
				case 'search':
					$this->render_store_tab_search( $store );
					break;
				case 'clear':
					$this->render_store_tab_clear( $categories );
					break;
				case 'memory':
					$this->render_store_tab_memory( $linked );
					break;
				case 'bulk-upsells':
					$this->render_store_tab_bulk_upsells( $categories );
					break;
				case 'upsells':
					$this->render_store_tab_current_upsells( $linked );
					break;
				case 'co-purchase':
					$this->render_store_tab_co_purchase( $store );
					break;
				case 'cart-events':
					$this->render_store_tab_cart_events( $store );
					break;
				case 'bestseller':
					$this->render_store_tab_bestseller( $store );
					break;
				default:
					$this->render_store_tab_funnel( $store );
					break;
			}
			?>
		</div>
		<?php
	}

	private function render_store_manager_notice(): void {
		if ( isset( $_GET['ran'] ) ) {
			$updated = absint( $_GET['updated'] ?? 0 );
			$products = absint( $_GET['products'] ?? 0 );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Store action complete: %1$d of %2$d products updated.', 'dsa' ), $updated, $products ) ) . '</p></div>';
		}

		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved mapping deleted.', 'dsa' ) . '</p></div>';
		}

		if ( isset( $_GET['purged'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d cart event rows purged.', 'dsa' ), absint( $_GET['purged'] ) ) ) . '</p></div>';
		}

		if ( isset( $_GET['cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Analytics events cleared.', 'dsa' ) . '</p></div>';
		}
	}

	private function render_store_manager_tabs( array $tabs, string $active ): void {
		echo '<nav class="dsa-lpm-tabs" aria-label="' . esc_attr__( 'Linked products sections', 'dsa' ) . '">';

		foreach ( $tabs as $key => $label ) {
			$url = add_query_arg( [ 'page' => 'kiwe-analytics', 'tab' => $key ], admin_url( 'admin.php' ) );
			printf(
				'<a class="dsa-lpm-tab %1$s" href="%2$s">%3$s</a>',
				$key === $active ? 'is-active' : '',
				esc_url( $url ),
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	private function render_store_tab_funnel( Store_Analytics_Service $store ): void {
		$days = absint( $_GET['days'] ?? 30 );
		$days = in_array( $days, [ 0, 1, 7, 30, 90 ], true ) ? $days : 30;
		$funnel = $store->funnel_summary( $days );
		?>
		<section class="dsa-lpm-stack">
			<form class="dsa-lpm-toolbar" method="get">
				<input type="hidden" name="page" value="kiwe-analytics">
				<input type="hidden" name="tab" value="funnel">
				<label>
					<?php esc_html_e( 'Time frame', 'dsa' ); ?>
					<select name="days">
						<option value="1" <?php selected( $days, 1 ); ?>><?php esc_html_e( 'Today', 'dsa' ); ?></option>
						<option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'dsa' ); ?></option>
						<option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( 'Last 30 days', 'dsa' ); ?></option>
						<option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( 'Last 90 days', 'dsa' ); ?></option>
						<option value="0" <?php selected( $days, 0 ); ?>><?php esc_html_e( 'All time', 'dsa' ); ?></option>
					</select>
				</label>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply', 'dsa' ); ?></button>
			</form>

			<div class="dsa-lpm-summary">
				<?php
				$cards = [
					'visitors'          => [ __( 'Visitors', 'dsa' ), __( 'Unique hashed IP anchors', 'dsa' ) ],
					'users'             => [ __( 'Users', 'dsa' ), __( 'Logged-in or registered', 'dsa' ) ],
					'identified'        => [ __( 'Identified', 'dsa' ), __( 'Hashed PhoneKey/Woo contact', 'dsa' ) ],
					'cart_visitors'     => [ __( 'Added to cart', 'dsa' ), sprintf( __( '%s%% of visitors', 'dsa' ), $funnel['cart_rate'] ) ],
					'checkout_visitors' => [ __( 'Reached checkout', 'dsa' ), sprintf( __( '%s%% of carts', 'dsa' ), $funnel['checkout_rate'] ) ],
					'purchase_visitors' => [ __( 'Purchased', 'dsa' ), sprintf( __( '%s%% of checkout visitors', 'dsa' ), $funnel['purchase_rate'] ) ],
					'abandoned_carts'   => [ __( 'Abandoned carts', 'dsa' ), sprintf( __( '%s%% of carts', 'dsa' ), $funnel['abandon_rate'] ) ],
					'orders'            => [ __( 'Orders', 'dsa' ), sprintf( __( 'Revenue %s', 'dsa' ), $funnel['revenue'] ) ],
				];
				?>
				<?php foreach ( $cards as $key => $card ) : ?>
					<div class="dsa-lpm-stat">
						<span><?php echo esc_html( $card[0] ); ?></span>
						<strong><?php echo esc_html( (string) ( $funnel[ $key ] ?? 0 ) ); ?></strong>
						<small><?php echo esc_html( $card[1] ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="dsa-lpm-card">
				<h2><?php esc_html_e( 'Funnel Definition', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Visitors are counted through salted IP hashes, not raw IP addresses. Analytics keeps broad funnel math; Kiwe Abandoned Cart uses its configured inactivity threshold and a separate recoverable-cart ledger.', 'dsa' ); ?></p>
				<p><?php esc_html_e( 'Identified visitors have a hashed PhoneKey, Woo customer, billing phone, or billing email anchor. Raw contact details are resolved only for an authorized manual reminder.', 'dsa' ); ?></p>
				<p><?php esc_html_e( 'Funnel totals begin collecting after this analytics schema is installed; older cart-event rows remain available but cannot be retroactively assigned an IP or contact hash.', 'dsa' ); ?></p>
			</div>
		</section>
		<?php
	}

	private function render_store_tab_search( Store_Analytics_Service $store ): void {
		$days = absint( $_GET['days'] ?? 30 );
		$days = in_array( $days, [ 0, 1, 7, 30, 90 ], true ) ? $days : 30;
		$rows = $store->search_event_rows( $days, 150 );
		?>
		<section class="dsa-lpm-stack">
			<form class="dsa-lpm-toolbar" method="get">
				<input type="hidden" name="page" value="kiwe-analytics"><input type="hidden" name="tab" value="search">
				<label><?php esc_html_e( 'Time frame', 'dsa' ); ?> <select name="days"><option value="1" <?php selected( $days, 1 ); ?>><?php esc_html_e( 'Today', 'dsa' ); ?></option><option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( '7 days', 'dsa' ); ?></option><option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( '30 days', 'dsa' ); ?></option><option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( '90 days', 'dsa' ); ?></option><option value="0" <?php selected( $days, 0 ); ?>><?php esc_html_e( 'All time', 'dsa' ); ?></option></select></label>
				<button class="button"><?php esc_html_e( 'Apply', 'dsa' ); ?></button>
			</form>
			<div class="dsa-admin-card"><h2><?php esc_html_e( 'Search demand', 'dsa' ); ?></h2><p><?php esc_html_e( 'Privacy-light counts of terms and alphabet paths searched in the DSA Surface, grouped by Product, Post, Author, or All.', 'dsa' ); ?></p>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Term', 'dsa' ); ?></th><th><?php esc_html_e( 'Family', 'dsa' ); ?></th><th><?php esc_html_e( 'Journey', 'dsa' ); ?></th><th><?php esc_html_e( 'Searches', 'dsa' ); ?></th><th><?php esc_html_e( 'Visitors', 'dsa' ); ?></th><th><?php esc_html_e( 'Users', 'dsa' ); ?></th><th><?php esc_html_e( 'Last searched', 'dsa' ); ?></th></tr></thead><tbody>
			<?php if ( ! $rows ) : ?><tr><td colspan="7"><?php esc_html_e( 'No Search activity has been recorded for this period.', 'dsa' ); ?></td></tr><?php else : foreach ( $rows as $row ) : ?><tr><td><strong><?php echo esc_html( (string) $row['term'] ); ?></strong></td><td><?php echo esc_html( ucfirst( (string) $row['family'] ) ); ?></td><td><?php echo esc_html( 'alphabet' === (string) $row['context'] ? __( 'Alphabet', 'dsa' ) : __( 'Typed', 'dsa' ) ); ?></td><td><?php echo esc_html( (string) absint( $row['searches'] ) ); ?></td><td><?php echo esc_html( (string) absint( $row['visitors'] ) ); ?></td><td><?php echo esc_html( (string) absint( $row['users'] ) ); ?></td><td><?php echo esc_html( (string) $row['last_searched'] ); ?></td></tr><?php endforeach; endif; ?>
			</tbody></table></div>
		</section>
		<?php
	}

	private function render_store_tab_saved(): void {
		if ( ! $this->saved_items ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Saved analytics is unavailable.', 'dsa' ) . '</p></div>';
			return;
		}

		$snapshot = $this->saved_items->admin_snapshot();
		$totals = is_array( $snapshot['totals'] ?? null ) ? $snapshot['totals'] : [];
		$selected_user_id = absint( $_GET['saved_user'] ?? 0 );
		$selected_user = $selected_user_id ? get_userdata( $selected_user_id ) : null;
		$selected_items = $selected_user ? $this->saved_items->items_for_user( $selected_user_id ) : [];
		?>
		<section class="dsa-lpm-stack">
			<div class="dsa-lpm-summary">
				<?php foreach ( [
					[ __( 'Users with saved items', 'dsa' ), absint( $totals['users'] ?? 0 ) ],
					[ __( 'Wishlist users', 'dsa' ), absint( $totals['wishlist_users'] ?? 0 ) ],
					[ __( 'Bookmark users', 'dsa' ), absint( $totals['bookmark_users'] ?? 0 ) ],
					[ __( 'Distinct saved objects', 'dsa' ), absint( $totals['objects'] ?? 0 ) ],
				] as $card ) : ?>
					<div class="dsa-lpm-stat"><span><?php echo esc_html( $card[0] ); ?></span><strong><?php echo esc_html( (string) $card[1] ); ?></strong></div>
				<?php endforeach; ?>
			</div>

			<?php if ( $selected_user ) : ?>
				<div class="dsa-lpm-card">
					<h2><?php echo esc_html( sprintf( __( 'Saved by %s', 'dsa' ), $selected_user->display_name ?: $selected_user->user_login ) ); ?></h2>
					<p><a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'kiwe-analytics', 'tab' => 'saved' ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Back to all saved analytics', 'dsa' ); ?></a></p>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Item', 'dsa' ); ?></th><th><?php esc_html_e( 'Type', 'dsa' ); ?></th><th><?php esc_html_e( 'Saved', 'dsa' ); ?></th></tr></thead><tbody>
					<?php foreach ( $selected_items as $item ) : ?><tr><td><a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a></td><td><?php echo esc_html( ucfirst( $item['type'] ) ); ?></td><td><?php echo esc_html( ! empty( $item['savedAt'] ) ? wp_date( 'Y-m-d H:i', (int) $item['savedAt'] ) : '' ); ?></td></tr><?php endforeach; ?>
					<?php if ( ! $selected_items ) : ?><tr><td colspan="3"><?php esc_html_e( 'This user has no saved items.', 'dsa' ); ?></td></tr><?php endif; ?>
					</tbody></table>
				</div>
			<?php endif; ?>

			<div class="dsa-lpm-card">
				<h2><?php esc_html_e( 'Most saved products and posts', 'dsa' ); ?></h2>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Item', 'dsa' ); ?></th><th><?php esc_html_e( 'Type', 'dsa' ); ?></th><th><?php esc_html_e( 'Users', 'dsa' ); ?></th></tr></thead><tbody>
				<?php foreach ( (array) ( $snapshot['objects'] ?? [] ) as $item ) : ?><tr><td><a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a></td><td><?php echo esc_html( ucfirst( $item['type'] ) ); ?></td><td><?php echo esc_html( (string) absint( $item['user_count'] ) ); ?></td></tr><?php endforeach; ?>
				<?php if ( empty( $snapshot['objects'] ) ) : ?><tr><td colspan="3"><?php esc_html_e( 'No registered-user saved items yet.', 'dsa' ); ?></td></tr><?php endif; ?>
				</tbody></table>
			</div>

			<div class="dsa-lpm-card">
				<h2><?php esc_html_e( 'Users and their saved collections', 'dsa' ); ?></h2>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'User', 'dsa' ); ?></th><th><?php esc_html_e( 'Wishlist', 'dsa' ); ?></th><th><?php esc_html_e( 'Bookmarks', 'dsa' ); ?></th><th></th></tr></thead><tbody>
				<?php foreach ( (array) ( $snapshot['users'] ?? [] ) as $user ) : ?><tr><td><?php echo esc_html( $user['name'] ); ?><br><small><?php echo esc_html( $user['email'] ); ?></small></td><td><?php echo esc_html( (string) absint( $user['wishlist'] ) ); ?></td><td><?php echo esc_html( (string) absint( $user['bookmark'] ) ); ?></td><td><a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'kiwe-analytics', 'tab' => 'saved', 'saved_user' => absint( $user['id'] ) ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View saved items', 'dsa' ); ?></a></td></tr><?php endforeach; ?>
				<?php if ( empty( $snapshot['users'] ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No registered users have saved anything yet.', 'dsa' ); ?></td></tr><?php endif; ?>
				</tbody></table>
			</div>
			<p class="description"><?php esc_html_e( 'Object and user drill-downs include registered users only. Anonymous saves remain privacy-light aggregate events and are not reconstructed into personal collections.', 'dsa' ); ?></p>
		</section>
		<?php
	}

	private function render_app_adoption( Store_Analytics_Service $store ): void {
		$days = absint( $_GET['days'] ?? 30 );
		$days = in_array( $days, [ 0, 1, 7, 30, 90 ], true ) ? $days : 30;
		$summary = $store->adoption_summary( $days );
		$rows = isset( $summary['rows'] ) && is_array( $summary['rows'] ) ? $summary['rows'] : [];
		?>
		<section class="dsa-lpm-stack">
			<form class="dsa-lpm-toolbar" method="get">
				<input type="hidden" name="page" value="kiwe-app">
				<input type="hidden" name="tab" value="adoption">
				<label>
					<?php esc_html_e( 'Time frame', 'dsa' ); ?>
					<select name="days">
						<option value="1" <?php selected( $days, 1 ); ?>><?php esc_html_e( 'Today', 'dsa' ); ?></option>
						<option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'dsa' ); ?></option>
						<option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( 'Last 30 days', 'dsa' ); ?></option>
						<option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( 'Last 90 days', 'dsa' ); ?></option>
						<option value="0" <?php selected( $days, 0 ); ?>><?php esc_html_e( 'All time', 'dsa' ); ?></option>
					</select>
				</label>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply', 'dsa' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Journey One — PWA Install', 'dsa' ); ?></h2>
			<p><?php esc_html_e( 'Home-screen installation is independent from notification permission. Android uses the native browser prompt; iOS uses Add to Home Screen.', 'dsa' ); ?></p>
			<p class="description"><?php esc_html_e( 'From Kiwe 0.4.54 onward, install intent means a platform-badge tap and native prompt acceptance is reported separately. Earlier test events used install intent for prompt acceptance.', 'dsa' ); ?></p>
			<div class="dsa-lpm-summary">
				<?php
				$cards = [
					'installIntent'        => [ __( 'Install intent', 'dsa' ), __( 'Unique visitors who tapped an Android or iOS install badge', 'dsa' ) ],
					'primerOk'             => [ __( 'Primer acknowledged', 'dsa' ), __( 'Unique Android visitors who tapped OK before the browser prompt', 'dsa' ) ],
					'promptAccepted'       => [ __( 'Browser prompt accepted', 'dsa' ), __( 'Unique visitors accepting the native browser prompt', 'dsa' ) ],
					'installDismissed'     => [ __( 'Prompt dismissed', 'dsa' ), __( 'Unique visitors declining or closing the browser prompt', 'dsa' ) ],
					'confirmedInstalls'    => [ __( 'Confirmed app users', 'dsa' ), __( 'Installed event or standalone launch', 'dsa' ) ],
					'standaloneLaunches'   => [ __( 'Standalone launches', 'dsa' ), __( 'Opened from a Home Screen app icon', 'dsa' ) ],
					'appUsers'             => [ __( 'App + user', 'dsa' ), __( 'Confirmed app visitors resolved to an account', 'dsa' ) ],
					'appAnonymous'         => [ __( 'App, not yet a user', 'dsa' ), __( 'Confirmed app visitors awaiting PhoneKey welcome', 'dsa' ) ],
				];
				?>
				<?php foreach ( $cards as $key => $card ) : ?>
					<div class="dsa-lpm-stat">
						<span><?php echo esc_html( $card[0] ); ?></span>
						<strong><?php echo esc_html( (string) ( $summary[ $key ] ?? 0 ) ); ?></strong>
						<small><?php echo esc_html( $card[1] ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Journey Two — Offline Push Notifications', 'dsa' ); ?></h2>
			<p><?php esc_html_e( 'This journey is separate from installation. Android may grant it in the browser; iOS requires the PWA to be installed and opened first.', 'dsa' ); ?></p>
			<p class="description"><?php esc_html_e( 'Kiwe stores encrypted PushSubscription endpoints and uses its site-specific VAPID key to deliver remote notifications. Browser and operating-system notification settings remain authoritative.', 'dsa' ); ?></p>
			<div class="dsa-lpm-summary">
				<?php foreach ( [
					'preferencesSaved'     => [ __( 'Preferences saved', 'dsa' ), __( 'Unique visitors choosing topics and channels', 'dsa' ) ],
					'notificationsEnabled' => [ __( 'Notifications enabled', 'dsa' ), __( 'Unique visitors granting browser permission', 'dsa' ) ],
					'notificationsDenied'  => [ __( 'Notifications denied', 'dsa' ), __( 'Unique visitors declining browser permission', 'dsa' ) ],
				] as $key => $card ) : ?>
					<div class="dsa-lpm-stat">
						<span><?php echo esc_html( $card[0] ); ?></span>
						<strong><?php echo esc_html( (string) ( $summary[ $key ] ?? 0 ) ); ?></strong>
						<small><?php echo esc_html( $card[1] ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="dsa-lpm-card">
				<h2><?php esc_html_e( 'Visitor adoption ledger', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Visitors are keyed by a salted IP hash. Raw IP addresses are never retained. When the same visitor later signs in through PhoneKey or WordPress, the row resolves to that user; shared networks can still merge multiple people into one approximate visitor.', 'dsa' ); ?></p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Visitor', 'dsa' ); ?></th><th><?php esc_html_e( 'Identity', 'dsa' ); ?></th><th><?php esc_html_e( 'App', 'dsa' ); ?></th><th><?php esc_html_e( 'Notifications', 'dsa' ); ?></th><th><?php esc_html_e( 'Platform', 'dsa' ); ?></th><th><?php esc_html_e( 'Last event', 'dsa' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No app adoption events in this time frame.', 'dsa' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $events = isset( $row['events'] ) && is_array( $row['events'] ) ? $row['events'] : []; ?>
							<?php $identity_name = ! empty( $row['userName'] ) ? (string) $row['userName'] : sprintf( __( 'User #%d', 'dsa' ), (int) ( $row['userId'] ?? 0 ) ); ?>
							<?php
							$app_state = '-';
							if ( in_array( 'pwa_installed', $events, true ) || in_array( 'pwa_standalone', $events, true ) ) {
								$app_state = __( 'Confirmed', 'dsa' );
							} elseif ( in_array( 'pwa_prompt_accepted', $events, true ) ) {
								$app_state = __( 'Prompt accepted', 'dsa' );
							} elseif ( in_array( 'pwa_install_dismissed', $events, true ) ) {
								$app_state = __( 'Prompt dismissed', 'dsa' );
							} elseif ( in_array( 'pwa_primer_ok', $events, true ) ) {
								$app_state = __( 'Primer acknowledged', 'dsa' );
							} elseif ( in_array( 'pwa_install_intent', $events, true ) ) {
								$app_state = __( 'Install intent', 'dsa' );
							}
							?>
							<tr>
								<td><code><?php echo esc_html( (string) ( $row['visitor'] ?? '' ) ); ?></code></td>
								<td>
									<?php if ( ! empty( $row['userId'] ) ) : ?>
										<a href="<?php echo esc_url( (string) ( $row['userEditUrl'] ?? '' ) ); ?>"><?php echo esc_html( $identity_name ); ?></a>
										<?php if ( ! empty( $row['phonekeyVerified'] ) ) : ?><small><?php esc_html_e( 'PhoneKey verified', 'dsa' ); ?></small><?php endif; ?>
									<?php else : ?>
										<?php esc_html_e( 'Anonymous visitor', 'dsa' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $app_state ); ?></td>
								<td><?php echo esc_html( in_array( 'notification_granted', $events, true ) ? __( 'Enabled', 'dsa' ) : ( in_array( 'notification_denied', $events, true ) ? __( 'Denied', 'dsa' ) : '-' ) ); ?></td>
								<td><?php echo esc_html( implode( ', ', (array) ( $row['contexts'] ?? [] ) ) ?: '-' ); ?></td>
								<td><?php echo esc_html( (string) ( $row['lastEventAt'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	private function render_store_tab_assign( array $categories ): void {
		?>
		<section class="dsa-lpm-grid dsa-lpm-grid--three">
			<form class="dsa-lpm-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dsa_linked_bulk_cross_sells">
				<?php wp_nonce_field( 'dsa_linked_bulk_cross_sells' ); ?>
				<div class="dsa-lpm-card">
					<h2><span>1</span><?php esc_html_e( 'Source Products', 'dsa' ); ?></h2>
					<p><?php esc_html_e( 'Which products receive cross-sells?', 'dsa' ); ?></p>
					<?php $this->render_store_source_fields( $categories ); ?>
				</div>
				<div class="dsa-lpm-card">
					<h2><span>2</span><?php esc_html_e( 'Cross-sell From', 'dsa' ); ?></h2>
					<p><?php esc_html_e( 'Products from these categories become the cross-sells.', 'dsa' ); ?></p>
					<label class="dsa-lpm-check"><input type="checkbox" name="own_category" value="1"> <?php esc_html_e( "Each product's own category", 'dsa' ); ?></label>
					<div class="dsa-lpm-category-list"><?php $this->render_store_category_checks( $categories, 'target_cats' ); ?></div>
				</div>
				<div class="dsa-lpm-card">
					<h2><span>3</span><?php esc_html_e( 'Mode & Run', 'dsa' ); ?></h2>
					<label><input type="radio" name="mode" value="merge" checked> <strong><?php esc_html_e( 'Merge', 'dsa' ); ?></strong><small><?php esc_html_e( 'Add to existing.', 'dsa' ); ?></small></label>
					<label><input type="radio" name="mode" value="replace"> <strong><?php esc_html_e( 'Replace', 'dsa' ); ?></strong><small><?php esc_html_e( 'Clear first, then set.', 'dsa' ); ?></small></label>
					<button class="button button-primary button-hero" type="submit"><?php esc_html_e( 'Run Bulk Assignment', 'dsa' ); ?></button>
				</div>
			</form>
		</section>
		<?php
	}

	private function render_store_tab_clear( array $categories ): void {
		?>
		<section class="dsa-lpm-grid">
			<form class="dsa-lpm-card dsa-lpm-card--danger" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dsa_linked_clear_cross_sells">
				<?php wp_nonce_field( 'dsa_linked_clear_cross_sells' ); ?>
				<h2><?php esc_html_e( 'Clear All Cross-sells', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Removes WooCommerce cross-sells from the selected products. Consider a backup first.', 'dsa' ); ?></p>
				<?php $this->render_store_source_fields( $categories ); ?>
				<button class="button button-danger" type="submit"><?php esc_html_e( 'Clear Cross-sells', 'dsa' ); ?></button>
			</form>
		</section>
		<?php
	}

	private function render_store_tab_memory( Linked_Products_Service $linked ): void {
		$mappings = $linked->saved_cross_sell_mappings();
		?>
		<section class="dsa-lpm-stack">
			<p><?php esc_html_e( "Every rule you've run is listed below. Re-run applies it again; delete removes the memory record only.", 'dsa' ); ?></p>
			<?php if ( empty( $mappings ) ) : ?>
				<div class="dsa-lpm-card"><p><?php esc_html_e( 'No saved cross-sell mappings yet.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $mappings as $mapping ) : ?>
				<div class="dsa-lpm-memory-card">
					<div>
						<span class="dsa-lpm-pill"><?php echo esc_html( $mapping['source_label'] ); ?></span>
						<span class="dsa-lpm-arrow">&rarr;</span>
						<span class="dsa-lpm-pill dsa-lpm-pill--outline"><?php echo esc_html( $mapping['target_label'] ); ?></span>
						<p><code><?php echo esc_html( $mapping['mode'] ); ?></code> <?php echo esc_html( $mapping['last_run'] ? sprintf( __( 'Last run: %s', 'dsa' ), $mapping['last_run'] ) : __( 'Not run yet', 'dsa' ) ); ?></p>
					</div>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'dsa_linked_rerun_mapping', 'mapping' => (int) $mapping['index'] ], admin_url( 'admin-post.php' ) ), 'dsa_linked_rerun_mapping' ) ); ?>"><?php esc_html_e( 'Re-run', 'dsa' ); ?></a>
						<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'dsa_linked_delete_mapping', 'mapping' => (int) $mapping['index'] ], admin_url( 'admin-post.php' ) ), 'dsa_linked_delete_mapping' ) ); ?>"><?php esc_html_e( 'Delete', 'dsa' ); ?></a>
					</p>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	private function render_store_tab_bulk_upsells( array $categories ): void {
		?>
		<section class="dsa-lpm-grid dsa-lpm-grid--three">
			<form class="dsa-lpm-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dsa_linked_bulk_upsells">
				<?php wp_nonce_field( 'dsa_linked_bulk_upsells' ); ?>
				<div class="dsa-lpm-card">
					<h2><span>1</span><?php esc_html_e( 'Source Products', 'dsa' ); ?></h2>
					<?php $this->render_store_source_fields( $categories ); ?>
				</div>
				<div class="dsa-lpm-card">
					<h2><span>2</span><?php esc_html_e( 'Co-purchase Depth', 'dsa' ); ?></h2>
					<label><input type="radio" name="depth" value="5" checked> <?php esc_html_e( 'Top 5', 'dsa' ); ?></label>
					<label><input type="radio" name="depth" value="10"> <?php esc_html_e( 'Top 10', 'dsa' ); ?></label>
					<label><input type="radio" name="depth" value="20"> <?php esc_html_e( 'Top 20', 'dsa' ); ?></label>
				</div>
				<div class="dsa-lpm-card">
					<h2><span>3</span><?php esc_html_e( 'Mode & Run', 'dsa' ); ?></h2>
					<label><input type="radio" name="mode" value="merge" checked> <strong><?php esc_html_e( 'Merge', 'dsa' ); ?></strong><small><?php esc_html_e( 'Add to existing upsells.', 'dsa' ); ?></small></label>
					<label><input type="radio" name="mode" value="replace"> <strong><?php esc_html_e( 'Replace', 'dsa' ); ?></strong><small><?php esc_html_e( 'Clear first, then set.', 'dsa' ); ?></small></label>
					<button class="button button-primary button-hero" type="submit"><?php esc_html_e( 'Run Upsell Assignment', 'dsa' ); ?></button>
				</div>
			</form>
		</section>
		<?php
	}

	private function render_store_tab_current_upsells( Linked_Products_Service $linked ): void {
		$rows = $linked->current_upsell_rows( 120 );
		?>
		<section class="dsa-lpm-stack">
			<p><?php esc_html_e( 'Live view of products that currently have WooCommerce upsells assigned. Products with zero upsells are hidden.', 'dsa' ); ?></p>
			<?php if ( empty( $rows ) ) : ?>
				<div class="dsa-lpm-card"><p><?php esc_html_e( 'No WooCommerce upsells found yet.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<div class="dsa-lpm-memory-card">
					<div>
						<a class="dsa-lpm-product-link" href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
						<p><?php echo esc_html( sprintf( _n( '%d upsell', '%d upsells', (int) $row['count'], 'dsa' ), (int) $row['count'] ) ); ?></p>
					</div>
					<div class="dsa-lpm-pill-row">
						<?php foreach ( $row['upsells'] as $upsell ) : ?>
							<span class="dsa-lpm-pill dsa-lpm-pill--outline"><?php echo esc_html( $upsell ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	private function render_store_tab_co_purchase( Store_Analytics_Service $store ): void {
		$limit = max( 5, min( 250, absint( $_GET['limit'] ?? 25 ) ) );
		$rows = $store->co_purchase_product_summary_rows( $limit );
		$pair_rows = $store->co_purchase_rows( min( 100, $limit ) );
		?>
		<section class="dsa-lpm-stack">
			<p><?php esc_html_e( 'Products ranked by how often they appear in completed or processing orders, and how often they appear with another product.', 'dsa' ); ?></p>
			<form class="dsa-lpm-toolbar" method="get">
				<input type="hidden" name="page" value="kiwe-analytics">
				<input type="hidden" name="tab" value="co-purchase">
				<label><?php esc_html_e( 'Show top', 'dsa' ); ?> <select name="limit"><option value="25" <?php selected( $limit, 25 ); ?>>25</option><option value="50" <?php selected( $limit, 50 ); ?>>50</option><option value="100" <?php selected( $limit, 100 ); ?>>100</option><option value="250" <?php selected( $limit, 250 ); ?>>250</option></select></label>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Load Analytics', 'dsa' ); ?></button>
			</form>
			<table class="widefat striped dsa-sortable-table">
				<thead><tr><th><?php esc_html_e( 'Product', 'dsa' ); ?></th><th><?php esc_html_e( 'n orders', 'dsa' ); ?></th><th><?php esc_html_e( 'y bundles', 'dsa' ); ?></th><th><?php esc_html_e( 'Min bundle', 'dsa' ); ?></th><th><?php esc_html_e( 'Avg bundle', 'dsa' ); ?></th><th><?php esc_html_e( 'Max bundle', 'dsa' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No co-purchase analytics found yet.', 'dsa' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr><td><a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td><td><span class="dsa-lpm-count"><?php echo esc_html( (string) $row['total_orders'] ); ?></span></td><td><span class="dsa-lpm-count dsa-lpm-count--green"><?php echo esc_html( (string) $row['bundle_orders'] ); ?></span></td><td><?php echo esc_html( $row['min_cost'] ?: '-' ); ?></td><td><?php echo esc_html( $row['avg_cost'] ?: '-' ); ?></td><td><?php echo esc_html( $row['max_cost'] ?: '-' ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Bought With Pairs', 'dsa' ); ?></h2>
			<table class="widefat striped dsa-sortable-table">
				<thead><tr><th><?php esc_html_e( 'Base product', 'dsa' ); ?></th><th><?php esc_html_e( 'Bought with', 'dsa' ); ?></th><th><?php esc_html_e( 'Orders', 'dsa' ); ?></th><th><?php esc_html_e( 'Avg order', 'dsa' ); ?></th><th><?php esc_html_e( 'Range', 'dsa' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $pair_rows ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No bought-with pair data found yet.', 'dsa' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $pair_rows as $row ) : ?>
						<tr><td><?php echo esc_html( $row['base_title'] ); ?></td><td><?php echo esc_html( $row['pair_title'] ); ?></td><td><?php echo esc_html( (string) $row['orders'] ); ?></td><td><?php echo esc_html( $row['avg_order_total'] ); ?></td><td><?php echo esc_html( $row['min_order_total'] . ' - ' . $row['max_order_total'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private function render_store_tab_cart_events( Store_Analytics_Service $store ): void {
		$summary = $store->summary();
		$days = absint( $_GET['days'] ?? 0 );
		$limit = max( 10, min( 250, absint( $_GET['limit'] ?? 50 ) ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$rows = $store->cart_event_rows( $days, $limit, $search );
		$product_rows = $store->product_event_rows( min( 100, $limit ) );
		?>
		<section class="dsa-lpm-stack">
			<div class="dsa-lpm-summary">
				<?php foreach ( [ 'today' => __( 'Today', 'dsa' ), 'week' => __( 'Last 7 days', 'dsa' ), 'month' => __( 'Last 30 days', 'dsa' ), 'all' => __( 'All time', 'dsa' ) ] as $key => $label ) : ?>
					<?php $card = $summary['cards'][ $key ] ?? []; ?>
					<div class="dsa-lpm-stat">
						<span><?php echo esc_html( $label ); ?></span>
						<strong><?php echo esc_html( (string) ( $card['add_events'] ?? 0 ) ); ?></strong>
						<small><?php echo esc_html( sprintf( __( '%d units added', 'dsa' ), (int) ( $card['quantity'] ?? 0 ) ) ); ?></small>
						<small><?php echo esc_html( sprintf( __( '%1$d updates, %2$d removals, %3$d claimed bonuses', 'dsa' ), (int) ( $card['update_events'] ?? 0 ), (int) ( $card['remove_events'] ?? 0 ), (int) ( $card['claim_events'] ?? 0 ) ) ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>
			<?php foreach ( $summary['notes'] as $note ) : ?><p class="description"><?php echo esc_html( $note ); ?></p><?php endforeach; ?>
			<form class="dsa-lpm-toolbar" method="get">
				<input type="hidden" name="page" value="kiwe-analytics"><input type="hidden" name="tab" value="cart-events">
				<select name="days"><option value="0" <?php selected( $days, 0 ); ?>><?php esc_html_e( 'All time', 'dsa' ); ?></option><option value="1" <?php selected( $days, 1 ); ?>><?php esc_html_e( 'Today', 'dsa' ); ?></option><option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( '7 days', 'dsa' ); ?></option><option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( '30 days', 'dsa' ); ?></option></select>
				<select name="limit"><option value="50" <?php selected( $limit, 50 ); ?>>50</option><option value="100" <?php selected( $limit, 100 ); ?>>100</option><option value="250" <?php selected( $limit, 250 ); ?>>250</option></select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Filter by product name...', 'dsa' ); ?>">
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Load', 'dsa' ); ?></button>
			</form>
			<div class="dsa-lpm-card dsa-lpm-card--danger">
				<h2><?php esc_html_e( 'Clear Tracking Data', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Removing records is permanent. This data powers cart intelligence and future abandoned cart features.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_store_analytics_purge">
					<?php wp_nonce_field( 'dsa_store_analytics_purge' ); ?>
					<select name="days"><option value="30"><?php esc_html_e( 'Older than 30 days', 'dsa' ); ?></option><option value="90"><?php esc_html_e( 'Older than 90 days', 'dsa' ); ?></option><option value="365"><?php esc_html_e( 'Older than 1 year', 'dsa' ); ?></option></select>
					<button class="button button-link-delete" type="submit"><?php esc_html_e( 'Clear', 'dsa' ); ?></button>
				</form>
			</div>
			<h2><?php esc_html_e( 'Product Cart Tracking', 'dsa' ); ?></h2>
			<table class="widefat striped dsa-sortable-table">
				<thead><tr><th><?php esc_html_e( 'Product', 'dsa' ); ?></th><th><?php esc_html_e( 'Adds', 'dsa' ); ?></th><th><?php esc_html_e( 'Qty', 'dsa' ); ?></th><th><?php esc_html_e( 'Customers', 'dsa' ); ?></th><th><?php esc_html_e( 'Logged-in', 'dsa' ); ?></th><th><?php esc_html_e( 'Last added', 'dsa' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $product_rows ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No product cart tracking found.', 'dsa' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $product_rows as $row ) : ?>
						<tr><td><a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td><td><?php echo esc_html( (string) $row['add_events'] ); ?></td><td><?php echo esc_html( (string) $row['total_qty'] ); ?></td><td><?php echo esc_html( (string) $row['unique_customers'] ); ?></td><td><?php echo esc_html( (string) $row['logged_in_users'] ); ?></td><td><?php echo esc_html( $row['last_added'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Cart Event Log', 'dsa' ); ?></h2>
			<table class="widefat striped dsa-sortable-table">
				<thead><tr><th><?php esc_html_e( 'Event', 'dsa' ); ?></th><th><?php esc_html_e( 'Product', 'dsa' ); ?></th><th><?php esc_html_e( 'Qty', 'dsa' ); ?></th><th><?php esc_html_e( 'Source', 'dsa' ); ?></th><th><?php esc_html_e( 'Customer', 'dsa' ); ?></th><th><?php esc_html_e( 'When', 'dsa' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No cart events found.', 'dsa' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr><td><code><?php echo esc_html( $row['event_type'] ); ?></code></td><td><a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td><td><?php echo esc_html( (string) $row['quantity'] ); ?></td><td><?php echo esc_html( $row['source'] . ( $row['context'] ? ' / ' . $row['context'] : '' ) ); ?></td><td><?php echo esc_html( $row['phonekey_verified'] ? __( 'PhoneKey verified', 'dsa' ) : ( $row['user_id'] ? __( 'Logged-in', 'dsa' ) : $row['customer_hash'] ) ); ?></td><td><?php echo esc_html( $row['created_at'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private function render_store_tab_bestseller( Store_Analytics_Service $store ): void {
		$bestseller = $store->bestseller_status();
		?>
		<section class="dsa-lpm-card">
			<h2><?php esc_html_e( 'Bestseller Status', 'dsa' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( 'Status: %1$s. Last sync: %2$s', 'dsa' ), ! empty( $bestseller['enabled'] ) ? __( 'enabled', 'dsa' ) : __( 'disabled', 'dsa' ), $bestseller['last_sync'] ?: __( 'never', 'dsa' ) ) ); ?></p>
			<table class="widefat striped dsa-sortable-table"><thead><tr><th><?php esc_html_e( 'Term', 'dsa' ); ?></th><th><?php esc_html_e( 'Slug', 'dsa' ); ?></th><th><?php esc_html_e( 'Products', 'dsa' ); ?></th></tr></thead><tbody>
				<?php foreach ( $bestseller['rows'] as $row ) : ?><tr><td><?php echo esc_html( $row['label'] ); ?></td><td><code><?php echo esc_html( $row['slug'] ); ?></code></td><td><?php echo esc_html( (string) $row['count'] ); ?></td></tr><?php endforeach; ?>
			</tbody></table>
		</section>
		<?php
	}

	private function render_store_source_fields( array $categories ): void {
		?>
		<label><input type="radio" name="source_type" value="all" checked> <?php esc_html_e( 'All published products', 'dsa' ); ?></label>
		<label><input type="radio" name="source_type" value="category"> <?php esc_html_e( 'Specific category', 'dsa' ); ?></label>
		<select name="source_cat">
			<option value="0"><?php esc_html_e( 'Choose category', 'dsa' ); ?></option>
			<?php foreach ( $categories as $category ) : ?>
				<option value="<?php echo esc_attr( (string) $category['id'] ); ?>"><?php echo esc_html( $category['name'] . ' (' . $category['count'] . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function render_store_category_checks( array $categories, string $name ): void {
		if ( empty( $categories ) ) {
			echo '<p>' . esc_html__( 'No product categories found.', 'dsa' ) . '</p>';
			return;
		}

		foreach ( $categories as $category ) {
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$d"> <span>%3$s</span><small>%4$d</small></label>',
				esc_attr( $name ),
				(int) $category['id'],
				esc_html( $category['name'] ),
				(int) $category['count']
			);
		}
	}

	public function render_bricks_page(): void {
		$settings = $this->settings->all();
		$bricks = wp_parse_args( $settings['bricks'] ?? [], $this->settings->defaults()['bricks'] );
		$has_woo = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe Bricks', 'dsa' ); ?></h1>
			<p><?php esc_html_e( 'Optional compatibility controls for Bricks-based WooCommerce builds.', 'dsa' ); ?></p>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Bricks settings saved.', 'dsa' ); ?></p>
				</div>
			<?php endif; ?>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Mini Cart Adapter', 'dsa' ); ?></h2>
				<?php if ( ! $has_woo ) : ?>
					<p><?php esc_html_e( 'WooCommerce is not active, so Woo-specific Bricks cart options are hidden at runtime.', 'dsa' ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_settings">
					<input type="hidden" name="_dsa_redirect" value="kiwe-bricks">
					<?php wp_nonce_field( 'dsa_save_settings' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Verified Target', 'dsa' ); ?></th>
								<td>
									<p><?php esc_html_e( 'Checked against the Bricks 2.3.7 mini-cart contract and source-reviewed against Bricks 2.4 beta. Bricks 2.4 adds AI abilities and MCP surfaces that Kiwe can use for future dynamic binding workflows.', 'dsa' ); ?></p>
									<p class="description"><?php esc_html_e( 'The old snippet is treated as feature source material. DSA does not paste legacy Bricks AJAX or discount code into the MU plugin.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Bricks Adapter', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="bricks[mini_cart_adapter_enabled]" value="1" <?php checked( ! empty( $bricks['mini_cart_adapter_enabled'] ) ); ?> <?php disabled( ! $has_woo ); ?>>
										<?php esc_html_e( 'Allow Kiwe to enhance Bricks mini-cart compatibility when WooCommerce is active', 'dsa' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Native Bricks Cart', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="bricks[prefer_bricks_native_cart]" value="1" <?php checked( ! empty( $bricks['prefer_bricks_native_cart'] ) ); ?> <?php disabled( ! $has_woo ); ?>>
										<?php esc_html_e( 'Prefer Bricks native mini-cart when the DSA cart surface is disabled', 'dsa' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Quantity Stepper', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="bricks[quantity_stepper_enabled]" value="1" <?php checked( ! empty( $bricks['quantity_stepper_enabled'] ) ); ?> <?php disabled( ! $has_woo ); ?>>
										<?php esc_html_e( 'Expose Bricks mini-cart quantity stepper controls in the Bricks editor', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Styling is controlled by Bricks element controls, not by DSA active/hover colors.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Stock Badge Controls', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="bricks[stock_badge_enabled]" value="1" <?php checked( ! empty( $bricks['stock_badge_enabled'] ) ); ?> <?php disabled( ! $has_woo ); ?>>
										<?php esc_html_e( 'Expose Bricks mini-cart stock badge style controls in the Bricks editor', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Badge thresholds and text live in Kiwe > WooCommerce so DSA cart and Bricks native mini-cart share the same commerce behavior.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Add To Cart Enhancer', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="bricks[add_to_cart_enhancer_enabled]" value="1" <?php checked( ! empty( $bricks['add_to_cart_enhancer_enabled'] ) ); ?> <?php disabled( ! $has_woo ); ?>>
										<?php esc_html_e( 'Expose Bricks product add-to-cart behavior and quantity styling controls', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Verified against the Bricks product add-to-cart contract. Visual styling stays in Bricks.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'DSA Icon Launchers', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="bricks[dsa_icon_launcher_enabled]" value="1" <?php checked( ! empty( $bricks['dsa_icon_launcher_enabled'] ) ); ?>>
										<?php esc_html_e( 'Expose registered DSA destinations on the Bricks Icon element', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Select an Icon element, then choose Open DSA screen in its Content controls. This works independently of whether that destination is visible in the dock.', 'dsa' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Kiwe Dynamic Tags', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="bricks[dynamic_tags_enabled]" value="1" <?php checked( ! empty( $bricks['dynamic_tags_enabled'] ) ); ?>>
									<?php esc_html_e( 'Expose Kiwe site identity, Woo store settings, product weight, and saved menus in Bricks', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Tags appear in the Kiwe group. Bricks already exposes site title and tagline; Kiwe exposes full-size logo assets plus public WooCommerce store address/location settings. Store phone and email are configured in Settings > General.', 'dsa' ); ?></p>
								<p class="description"><code>{kiwe_site_logo}</code> <code>{kiwe_site_logo_inverse}</code> <code>{kiwe_store_address_1}</code> <code>{kiwe_store_address_2}</code> <code>{kiwe_store_city}</code> <code>{kiwe_store_country}</code> <code>{kiwe_store_state}</code> <code>{kiwe_store_postcode}</code> <code>{kiwe_store_phone}</code> <code>{kiwe_store_email}</code> <code>{kiwe_selling_locations}</code> <code>{kiwe_shipping_locations}</code> <code>{woo_product_weight}</code></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Linked Products Controls', 'dsa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="bricks[linked_products_controls_enabled]" value="1" <?php checked( ! empty( $bricks['linked_products_controls_enabled'] ) ); ?> <?php disabled( ! $has_woo ); ?>>
										<?php esc_html_e( 'Expose Kiwe FBT and recommendation controls in Bricks', 'dsa' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Verified against Bricks `woocommerce-mini-cart` and `product-upsells`. Bricks owns card styling; Kiwe supplies safe cart recommendations and source presets.', 'dsa' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Kiwe Bricks', 'dsa' ) ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	private function render_simple_admin_tabs( string $page, array $tabs, string $active ): void {
		echo '<nav class="dsa-lpm-tabs" aria-label="' . esc_attr__( 'Kiwe settings sections', 'dsa' ) . '">';

		foreach ( $tabs as $key => $label ) {
			$url = add_query_arg( [ 'page' => sanitize_key( $page ), 'tab' => sanitize_key( $key ) ], admin_url( 'admin.php' ) );
			printf(
				'<a class="dsa-lpm-tab %1$s" href="%2$s">%3$s</a>',
				$key === $active ? 'is-active' : '',
				esc_url( $url ),
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	private function assert_store_action( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dsa' ) );
		}

		check_admin_referer( $nonce_action );
	}

	private function redirect_store_manager( string $tab, array $args = [] ): void {
		$args = array_merge(
			[
				'page' => 'kiwe-analytics',
				'tab'  => sanitize_key( $tab ),
			],
			$args
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function clear_runtime_cache_records(): int {
		global $wpdb;

		$deleted  = 0;
		$prefixes = [ '_transient_dsa_', '_transient_timeout_dsa_', '_transient_kiwe_', '_transient_timeout_kiwe_' ];

		foreach ( $prefixes as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			if ( is_int( $count ) && $count > 0 ) {
				$deleted += $count;
			}
		}

		update_option( 'dsa_runtime_cache_epoch', time(), false );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return $deleted;
	}
	private function auth_capability(): string {
		return function_exists( 'pk_admin_cap' ) ? pk_admin_cap() : 'manage_options';
	}

	private function secure_tabs(): array {
		return [
			'events'      => [ 'label' => __( 'Events', 'dsa' ), 'callback' => 'stp_pg_events' ],
			'alerts'      => [ 'label' => __( 'Alerts', 'dsa' ), 'callback' => 'stp_pg_alerts' ],
			'protections' => [ 'label' => __( 'Protections', 'dsa' ), 'callback' => 'stp_pg_protections' ],
			'brain'       => [ 'label' => __( 'Site Brain', 'dsa' ), 'callback' => 'stp_pg_brain' ],
			'ips'         => [ 'label' => __( 'IP Reputation', 'dsa' ), 'callback' => 'stp_pg_ips' ],
			'subnets'     => [ 'label' => __( 'Subnet Intel', 'dsa' ), 'callback' => 'stp_pg_subnets' ],
			'chain'       => [ 'label' => __( 'Chain Link', 'dsa' ), 'callback' => 'stp_pg_chain' ],
			'files'       => [ 'label' => __( 'File Scanner', 'dsa' ), 'callback' => 'stp_pg_filescan' ],
			'sessions'    => [ 'label' => __( 'Sessions', 'dsa' ), 'callback' => 'stp_pg_sessions' ],
			'profiles'    => [ 'label' => __( 'User Profiles', 'dsa' ), 'callback' => 'stp_pg_profiles' ],
			'live'        => [ 'label' => __( 'Live Monitor', 'dsa' ), 'callback' => 'stp_pg_live' ],
			'analytics'   => [ 'label' => __( 'Analytics', 'dsa' ), 'callback' => 'stp_pg_analytics' ],
			'auth'        => [ 'label' => __( 'Auth Security', 'dsa' ), 'callback' => 'stp_pg_auth' ],
			'settings'    => [ 'label' => __( 'Settings', 'dsa' ), 'callback' => 'stp_pg_settings' ],
		];
	}

	private function render_secure_tab( string $callback ): void {
		if ( ! function_exists( $callback ) ) {
			echo '<p>' . esc_html__( 'This SecureTrack section is not available in the current runtime.', 'dsa' ) . '</p>';
			return;
		}

		call_user_func( $callback );
	}

	private function profile_payload(): array {
		$settings = $this->settings->all();

		if ( isset( $settings['link_hub'] ) && is_array( $settings['link_hub'] ) ) {
			unset( $settings['link_hub']['google_api_key'] );
		}

		return [
			'type'          => 'kiwe-appsite-profile',
			'schemaVersion' => 1,
			'pluginVersion' => DSA_VERSION,
			'exportedAt'    => gmdate( 'c' ),
			'source'        => [
				'siteName' => get_bloginfo( 'name' ),
				'siteUrl'  => home_url( '/' ),
			],
			'settings'      => [
				'enabled'             => ! empty( $settings['enabled'] ),
				'style'               => $settings['style'] ?? $this->settings->defaults()['style'],
				'position'            => sanitize_key( $settings['position'] ?? 'right-center' ),
				'surface_width'       => (int) ( $settings['surface_width'] ?? 72 ),
				'surface_bottom'      => (int) ( $settings['surface_bottom'] ?? 24 ),
				'fragment_navigation' => false,
				'diagnostics'         => $settings['diagnostics'] ?? [],
				'enhancements'        => $settings['enhancements'] ?? [],
				'dock'                => $settings['dock'] ?? [],
				'visual_effects'      => $settings['visual_effects'] ?? [],
				'app'                 => $settings['app'] ?? [],
				'dsa_theme'           => $settings['dsa_theme'] ?? [],
				'schema_geo'          => $settings['schema_geo'] ?? [],
				'metrics'             => $settings['metrics'] ?? [],
				'permissions'         => $settings['permissions'] ?? [],
				'protected_flow'      => $settings['protected_flow'] ?? [],
				'secure'              => $settings['secure'] ?? [],
				'commerce'            => $settings['commerce'] ?? [],
				'bricks'              => $settings['bricks'] ?? [],
				'haptic'             => $settings['haptic'] ?? [],
				'games'               => $settings['games'] ?? [],
				'link_hub'            => $settings['link_hub'] ?? [],
				'search'              => $settings['search'] ?? [],
			],
		];
	}

	private function sanitize_profile_settings( array $settings, array $current ): array {
		$input = $settings;
		$next  = $current;

		if ( array_key_exists( 'enabled', $input ) ) {
			$next['enabled'] = ! empty( $input['enabled'] );
		}

		if ( array_key_exists( 'position', $input ) ) {
			$position = sanitize_key( $input['position'] );
			$next['position'] = in_array( $position, [ 'right-center', 'right-bottom', 'left-center', 'left-bottom' ], true ) ? $position : 'right-center';
		}

		if ( array_key_exists( 'surface_width', $input ) ) {
			$next['surface_width'] = max( 48, min( 220, absint( $input['surface_width'] ) ) );
		}

		if ( array_key_exists( 'surface_bottom', $input ) ) {
			$next['surface_bottom'] = max( 0, min( 220, absint( $input['surface_bottom'] ) ) );
		}

		if ( array_key_exists( 'fragment_navigation', $input ) ) {
			$next['fragment_navigation'] = false;
		}

		if ( isset( $input['style'] ) && is_array( $input['style'] ) ) {
			$next['style'] = $this->sanitize_style_settings( $input['style'], $current['style'] ?? [] );
		}

		if ( isset( $input['visual_effects'] ) && is_array( $input['visual_effects'] ) ) {
			$next['visual_effects'] = $this->sanitize_visual_effects( $input['visual_effects'], $current['visual_effects'] );
		}

		if ( isset( $input['diagnostics'] ) && is_array( $input['diagnostics'] ) ) {
			$next['diagnostics'] = $this->sanitize_diagnostics_settings( $input['diagnostics'], $current['diagnostics'] );
		}

		if ( isset( $input['enhancements'] ) && is_array( $input['enhancements'] ) ) {
			$next['enhancements'] = $this->sanitize_enhancement_settings( $input['enhancements'], $current['enhancements'] ?? [] );
		}

		if ( isset( $input['dock'] ) && is_array( $input['dock'] ) ) {
			$next['dock'] = $this->sanitize_dock_settings( $input['dock'], $current['dock'] );
		}

		if ( isset( $input['dsa_theme'] ) && is_array( $input['dsa_theme'] ) ) {
			$next['dsa_theme'] = $this->sanitize_dsa_theme( $input['dsa_theme'], $current['dsa_theme'] );
		}

		if ( isset( $input['schema_geo'] ) && is_array( $input['schema_geo'] ) ) {
			$next['schema_geo'] = $this->sanitize_schema_geo_settings( $input['schema_geo'], $current['schema_geo'] );
		}

		if ( isset( $input['metrics'] ) && is_array( $input['metrics'] ) ) {
			$next['metrics'] = $this->sanitize_metrics_settings( $input['metrics'], $current['metrics'] );
		}

		if ( isset( $input['permissions'] ) && is_array( $input['permissions'] ) ) {
			$next['permissions'] = $this->sanitize_permissions_settings( $input['permissions'], $current['permissions'] );
		}

		if ( isset( $input['protected_flow'] ) && is_array( $input['protected_flow'] ) ) {
			$next['protected_flow'] = $this->sanitize_protected_flow_settings( $input['protected_flow'], $current['protected_flow'] );
		}

		if ( isset( $input['secure'] ) && is_array( $input['secure'] ) ) {
			$next['secure'] = $this->sanitize_secure_settings( $input['secure'], $current['secure'] );
		}

		if ( isset( $input['commerce'] ) && is_array( $input['commerce'] ) ) {
			$next['commerce'] = $this->sanitize_commerce_settings( $input['commerce'], $current['commerce'] );
		}

		if ( isset( $input['bricks'] ) && is_array( $input['bricks'] ) ) {
			$next['bricks'] = $this->sanitize_bricks_settings( $input['bricks'], $current['bricks'] );
		}

		if ( isset( $input['haptic'] ) && is_array( $input['haptic'] ) ) {
			$next['haptic'] = $this->sanitize_haptic_settings( $input['haptic'], $current['haptic'] );
		}

		if ( isset( $input['games'] ) && is_array( $input['games'] ) ) {
			$next['games'] = $this->sanitize_games_settings( $input['games'], $current['games'] );
		}

		if ( isset( $input['link_hub'] ) && is_array( $input['link_hub'] ) ) {
			$link_hub = $input['link_hub'];

			if ( empty( $link_hub['google_api_key'] ) && ! empty( $current['link_hub']['google_api_key'] ) ) {
				$link_hub['google_api_key'] = $current['link_hub']['google_api_key'];
			}

			$next['link_hub'] = $this->sanitize_link_hub_settings( $link_hub, $current['link_hub'] );
		}

		if ( isset( $input['app'] ) && is_array( $input['app'] ) ) {
			$next['app'] = $this->sanitize_app_settings( $input['app'], $current['app'] );
		}

		if ( isset( $input['search'] ) && is_array( $input['search'] ) ) {
			$next['search'] = $this->sanitize_search_settings( $input['search'], $current['search'] ?? [] );
		}

		return $next;
	}

	private function redirect_profile_error( string $code ): void {
		wp_safe_redirect( add_query_arg( 'profile-error', sanitize_key( $code ), admin_url( 'admin.php?page=kiwe' ) ) );
		exit;
	}

	private function redirect_binding_plan_error( string $code ): void {
		wp_safe_redirect( add_query_arg( 'binding-plan', sanitize_key( $code ), admin_url( 'admin.php?page=kiwe-framework' ) ) );
		exit;
	}

	private function binding_plan_error_message( string $code ): string {
		$messages = [
			'missing' => __( 'Choose a kiwe-bindings.json file to validate.', 'dsa' ),
			'size'    => __( 'Binding plan file is too large. Use a JSON file under 512 KB.', 'dsa' ),
			'type'    => __( 'Binding plan intake only accepts .json files.', 'dsa' ),
			'empty'   => __( 'Binding plan file was empty.', 'dsa' ),
			'json'    => __( 'Binding plan file was not valid JSON.', 'dsa' ),
		];

		return $messages[ $code ] ?? __( 'Binding plan could not be processed.', 'dsa' );
	}

	private function framework_binding_report(): array {
		$key = isset( $_GET['binding-report'] ) ? sanitize_key( (string) wp_unslash( $_GET['binding-report'] ) ) : '';
		if ( '' === $key ) {
			return [];
		}

		$payload = get_transient( 'dsa_binding_report_' . $key );
		if ( ! is_array( $payload ) || (int) ( $payload['userId'] ?? 0 ) !== get_current_user_id() ) {
			return [];
		}

		$payload['key'] = $key;

		return $payload;
	}

	private function profile_error_message( string $code ): string {
		$messages = [
			'missing' => __( 'Choose a JSON profile file to import.', 'dsa' ),
			'size'    => __( 'Profile file is too large. Use a JSON file under 1 MB.', 'dsa' ),
			'type'    => __( 'Profile import only accepts .json files.', 'dsa' ),
			'empty'   => __( 'Profile file was empty.', 'dsa' ),
			'json'    => __( 'Profile file was not valid JSON.', 'dsa' ),
			'encode'  => __( 'Could not encode the current Appsite profile.', 'dsa' ),
		];

		return $messages[ $code ] ?? __( 'Appsite profile could not be processed.', 'dsa' );
	}

	private function sanitize_style_settings( $input, array $current ): array {
		$defaults = $this->settings->defaults()['style'];
		if ( ! is_array( $input ) ) {
			return wp_parse_args( $current, $defaults );
		}

		$input = wp_unslash( $input );
		$visual_profile = sanitize_key( (string) ( $input['visual_profile'] ?? ( $current['visual_profile'] ?? 'legacy' ) ) );
		$mode = sanitize_key( (string) ( $input['mode'] ?? ( $current['mode'] ?? 'classic' ) ) );
		$sheet_position = sanitize_key( (string) ( $input['sheet_position'] ?? ( $current['sheet_position'] ?? 'bottom' ) ) );
		$sheet_animation = sanitize_key( (string) ( $input['sheet_animation'] ?? ( $current['sheet_animation'] ?? 'slide' ) ) );
		$sheet_backdrop = sanitize_key( (string) ( $input['sheet_backdrop'] ?? ( $current['sheet_backdrop'] ?? 'blur' ) ) );
		$sheet_spacing = sanitize_key( (string) ( $input['sheet_spacing'] ?? ( $current['sheet_spacing'] ?? 'edge' ) ) );
		$sheet_origin = sanitize_key( (string) ( $input['sheet_origin'] ?? ( $current['sheet_origin'] ?? 'bottom' ) ) );
		$screen_heading_tag = sanitize_key( (string) ( $input['screen_heading_tag'] ?? ( $current['screen_heading_tag'] ?? 'h2' ) ) );
		return [
			'visual_profile'    => in_array( $visual_profile, [ 'prototype', 'kiwe2027', 'kiwe-2027' ], true ) ? 'kiwe2027' : 'legacy',
			'mode'              => in_array( $mode, [ 'classic', 'sheet' ], true ) ? $mode : 'classic',
			'sheet_position'    => in_array( $sheet_position, [ 'bottom', 'right', 'left' ], true ) ? $sheet_position : 'bottom',
			'sheet_animation'   => in_array( $sheet_animation, [ 'slide', 'soft', 'snap' ], true ) ? $sheet_animation : 'slide',
			'sheet_backdrop'    => in_array( $sheet_backdrop, [ 'blur', 'fade', 'none' ], true ) ? $sheet_backdrop : 'blur',
			'sheet_duration_ms' => max( 120, min( 900, absint( $input['sheet_duration_ms'] ?? ( $current['sheet_duration_ms'] ?? 320 ) ) ) ),
			'sheet_max_height'  => max( 45, min( 96, absint( $input['sheet_max_height'] ?? ( $current['sheet_max_height'] ?? 82 ) ) ) ),
			'sheet_spacing'     => in_array( $sheet_spacing, [ 'edge', 'inset' ], true ) ? $sheet_spacing : 'edge',
			'sheet_origin'      => in_array( $sheet_origin, [ 'bottom', 'above_dock' ], true ) ? $sheet_origin : 'bottom',
			'sheet_width_percent' => max( 50, min( 90, absint( $input['sheet_width_percent'] ?? ( $current['sheet_width_percent'] ?? 78 ) ) ) ),
			'screen_heading_tag' => in_array( $screen_heading_tag, [ 'h1', 'h2', 'h3', 'h4', 'p', 'span' ], true ) ? $screen_heading_tag : 'h2',
		];
	}

	private function sanitize_visual_effects( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$next = $current;
		$legacy = empty( $input['_classic_present'] ) && empty( $input['_transition_present'] ) && empty( $input['_app_present'] );

		if ( $legacy || ! empty( $input['_classic_present'] ) ) {
			$next['blur_type'] = in_array( $input['blur_type'] ?? '', [ 'none', 'gaussian', 'frosted', 'dim' ], true ) ? sanitize_key( $input['blur_type'] ) : ( $current['blur_type'] ?? 'gaussian' );
			$next['blur_strength'] = max( 0, min( 24, absint( $input['blur_strength'] ?? ( $current['blur_strength'] ?? 10 ) ) ) );
			$next['glass_intensity'] = in_array( $input['glass_intensity'] ?? '', [ 'low', 'medium', 'high' ], true ) ? sanitize_key( $input['glass_intensity'] ) : ( $current['glass_intensity'] ?? 'medium' );
			$next['screen_material'] = in_array( $input['screen_material'] ?? '', [ 'glass', 'solid' ], true ) ? sanitize_key( $input['screen_material'] ) : ( $current['screen_material'] ?? 'glass' );
			$next['screen_animation'] = in_array( $input['screen_animation'] ?? '', [ 'bottom', 'top', 'left', 'right' ], true ) ? sanitize_key( $input['screen_animation'] ) : ( $current['screen_animation'] ?? 'bottom' );
			$next['loader_type'] = in_array( $input['loader_type'] ?? '', [ 'none', 'orb-chase', 'pulse' ], true ) ? sanitize_key( $input['loader_type'] ) : ( $current['loader_type'] ?? 'orb-chase' );
		}

		if ( $legacy || ! empty( $input['_transition_present'] ) ) {
			$next['show_on_overlay_open'] = ! empty( $input['show_on_overlay_open'] );
			$next['show_on_navigation'] = ! empty( $input['show_on_navigation'] );
			$next['show_on_page_in'] = ! empty( $input['show_on_page_in'] );
			$next['show_on_page_out'] = ! empty( $input['show_on_page_out'] );
			$next['editorial_view_transitions'] = ! empty( $input['editorial_view_transitions'] );
			$next['editorial_morph_navigation'] = ! empty( $input['editorial_morph_navigation'] );
			$next['min_loader_ms'] = max( 0, min( 10000, absint( $input['min_loader_ms'] ?? ( $current['min_loader_ms'] ?? 700 ) ) ) );
			$next['artificial_delay_ms'] = max( 0, min( 5000, absint( $input['artificial_delay_ms'] ?? ( $current['artificial_delay_ms'] ?? 0 ) ) ) );
			$next['transition_message_mode'] = in_array( $input['transition_message_mode'] ?? '', [ 'random', 'fixed' ], true ) ? sanitize_key( $input['transition_message_mode'] ) : ( $current['transition_message_mode'] ?? 'random' );
			$next['transition_message_index'] = max( 0, min( 19, absint( $input['transition_message_index'] ?? ( ( $current['transition_message_index'] ?? 0 ) + 1 ) ) - 1 ) );
			$next['transition_title_position'] = in_array( $input['transition_title_position'] ?? '', [ 'above', 'below' ], true ) ? sanitize_key( $input['transition_title_position'] ) : ( $current['transition_title_position'] ?? 'above' );
			$next['transition_messages'] = isset( $input['transition_messages'] ) ? $this->sanitize_transition_messages( $input['transition_messages'] ) : (array) ( $current['transition_messages'] ?? [] );
		}

		if ( $legacy || ! empty( $input['_app_present'] ) ) {
			$next['initial_preloader_enabled'] = ! empty( $input['initial_preloader_enabled'] );
		}

		return $next;
	}

	private function sanitize_diagnostics_settings( $input, array $current ): array {
		$next = wp_parse_args(
			is_array( $current ) ? $current : [],
			[
				'enabled'             => false,
				'frontend_debug'      => false,
				'console_logs'        => false,
				'performance_profile' => false,
				'asset_manifest'      => false,
				'asset_build_pilot'   => false,
				'asset_build_apply'   => false,
				'asset_build_hints'   => false,
			]
		);

		if ( ! is_array( $input ) ) {
			return $next;
		}

		$input                  = wp_unslash( $input );
		$next['enabled']        = ! empty( $input['enabled'] );
		$next['frontend_debug'] = $next['enabled'] && ! empty( $input['frontend_debug'] );
		$next['console_logs']   = $next['frontend_debug'] && ! empty( $input['console_logs'] );
		$next['performance_profile'] = $next['enabled'] && ! empty( $input['performance_profile'] );
		$next['asset_manifest']      = $next['enabled'] && ! empty( $input['asset_manifest'] );
		$next['asset_build_pilot']   = $next['enabled'] && ! empty( $input['asset_build_pilot'] );
		$next['asset_build_apply']   = $next['asset_build_pilot'] && ! empty( $input['asset_build_apply'] );
		$next['asset_build_hints']   = $next['asset_build_pilot'] && ! empty( $input['asset_build_hints'] );

		return $next;
	}

	private function sanitize_enhancement_settings( $input, array $current ): array {
		$next = wp_parse_args(
			is_array( $current ) ? $current : [],
			[
				'enabled' => false,
				'htmx'    => false,
				'alpine'  => false,
			]
		);

		if ( ! is_array( $input ) ) {
			return $next;
		}

		$input = wp_unslash( $input );
		$next['enabled'] = ! empty( $input['enabled'] );
		$next['htmx']    = $next['enabled'] && ! empty( $input['htmx'] );
		$next['alpine']  = $next['enabled'] && ! empty( $input['alpine'] );

		return $next;
	}

	private function sanitize_dock_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$enabled = (array) ( $current['enabled_items'] ?? [] );
		$custom_items = isset( $input['custom_items'] ) ? $this->sanitize_dock_custom_items( $input['custom_items'] ) : $this->dock_custom_items_for_admin( $current );
		$allowed_labels = array_merge( $this->dock_module_labels(), $this->dock_custom_item_labels( $custom_items ) );
		$order = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $input['item_order'] ?? ( $current['item_order'] ?? [] ) ) ) ) ) );

		if ( isset( $input['enabled_items'] ) && is_array( $input['enabled_items'] ) ) {
			foreach ( array_keys( $allowed_labels ) as $id ) {
				$enabled[ $id ] = ! empty( $input['enabled_items'][ $id ] );
			}
		}

		foreach ( $custom_items as $item ) {
			if ( ! isset( $enabled[ $item['id'] ] ) ) {
				$enabled[ $item['id'] ] = ! empty( $item['enabled'] );
			}
		}

		$enabled = array_replace(
			array_fill_keys( array_keys( $allowed_labels ), true ),
			array_intersect_key( $enabled, $allowed_labels )
		);

		$order = array_values( array_filter( $order, static fn( string $id ): bool => isset( $allowed_labels[ $id ] ) ) );
		foreach ( array_keys( $allowed_labels ) as $id ) {
			if ( ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}

		$focus_item = sanitize_key( (string) ( $input['focus_item'] ?? ( $current['focus_item'] ?? 'ai' ) ) );
		if ( ! isset( $allowed_labels[ $focus_item ] ) ) {
			$focus_item = isset( $allowed_labels['ai'] ) ? 'ai' : ( array_key_first( $allowed_labels ) ?: '' );
		}

		$heading = sanitize_key( $input['menu_heading_tag'] ?? ( $current['menu_heading_tag'] ?? 'span' ) );

		if ( ! in_array( $heading, [ 'span', 'p', 'h1', 'h2', 'h3', 'h4' ], true ) ) {
			$heading = 'span';
		}

		$shape = sanitize_key( (string) ( $input['shape'] ?? ( $current['shape'] ?? 'pill' ) ) );
		$shape = 'rounded' === $shape ? 'pill' : $shape;
		$shape = in_array( $shape, [ 'pill', 'box', 'square' ], true ) ? $shape : 'pill';

		return [
			'style'               => 'phonekey',
			'presentation'        => in_array( $input['presentation'] ?? ( $current['presentation'] ?? 'dock' ), [ 'dock', 'navbar' ], true ) ? sanitize_key( $input['presentation'] ?? $current['presentation'] ) : 'dock',
			'shape'               => $shape,
			'material'            => in_array( $input['material'] ?? ( $current['material'] ?? 'glass' ), [ 'glass', 'solid' ], true ) ? sanitize_key( $input['material'] ?? $current['material'] ) : 'glass',
			'fill_axis'           => 'navbar' === ( $input['presentation'] ?? ( $current['presentation'] ?? 'dock' ) ),
			'context_rail_enabled' => array_key_exists( 'context_rail_enabled', $input ) ? ! empty( $input['context_rail_enabled'] ) : ! empty( $current['context_rail_enabled'] ),
			'split_style'         => array_key_exists( 'split_style', $input ) ? ! empty( $input['split_style'] ) : ! empty( $current['split_style'] ),
			'focus_item'          => $focus_item,
			'desktop_orientation' => in_array( $input['desktop_orientation'] ?? ( $current['desktop_orientation'] ?? 'auto' ), [ 'auto', 'vertical', 'horizontal' ], true ) ? sanitize_key( $input['desktop_orientation'] ?? $current['desktop_orientation'] ) : 'auto',
			'tablet_orientation'  => in_array( $input['tablet_orientation'] ?? ( $current['tablet_orientation'] ?? 'auto' ), [ 'auto', 'vertical', 'horizontal' ], true ) ? sanitize_key( $input['tablet_orientation'] ?? $current['tablet_orientation'] ) : 'auto',
			'mobile_orientation'  => in_array( $input['mobile_orientation'] ?? ( $current['mobile_orientation'] ?? 'auto' ), [ 'auto', 'vertical', 'horizontal' ], true ) ? sanitize_key( $input['mobile_orientation'] ?? $current['mobile_orientation'] ) : 'auto',
			'desktop_vertical_edge' => in_array( $input['desktop_vertical_edge'] ?? ( $current['desktop_vertical_edge'] ?? 'right' ), [ 'left', 'right' ], true ) ? sanitize_key( $input['desktop_vertical_edge'] ?? $current['desktop_vertical_edge'] ) : 'right',
			'desktop_horizontal_edge' => in_array( $input['desktop_horizontal_edge'] ?? ( $current['desktop_horizontal_edge'] ?? 'bottom' ), [ 'top', 'bottom' ], true ) ? sanitize_key( $input['desktop_horizontal_edge'] ?? $current['desktop_horizontal_edge'] ) : 'bottom',
			'tablet_vertical_edge' => in_array( $input['tablet_vertical_edge'] ?? ( $current['tablet_vertical_edge'] ?? 'right' ), [ 'left', 'right' ], true ) ? sanitize_key( $input['tablet_vertical_edge'] ?? $current['tablet_vertical_edge'] ) : 'right',
			'tablet_horizontal_edge' => in_array( $input['tablet_horizontal_edge'] ?? ( $current['tablet_horizontal_edge'] ?? 'bottom' ), [ 'top', 'bottom' ], true ) ? sanitize_key( $input['tablet_horizontal_edge'] ?? $current['tablet_horizontal_edge'] ) : 'bottom',
			'mobile_vertical_edge' => in_array( $input['mobile_vertical_edge'] ?? ( $current['mobile_vertical_edge'] ?? 'right' ), [ 'left', 'right' ], true ) ? sanitize_key( $input['mobile_vertical_edge'] ?? $current['mobile_vertical_edge'] ) : 'right',
			'mobile_horizontal_edge' => in_array( $input['mobile_horizontal_edge'] ?? ( $current['mobile_horizontal_edge'] ?? 'bottom' ), [ 'top', 'bottom' ], true ) ? sanitize_key( $input['mobile_horizontal_edge'] ?? $current['mobile_horizontal_edge'] ) : 'bottom',
			'desktop_vertical_position'   => in_array( $input['desktop_vertical_position'] ?? ( $current['desktop_vertical_position'] ?? 'center' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['desktop_vertical_position'] ?? $current['desktop_vertical_position'] ) : 'center',
			'desktop_horizontal_position' => in_array( $input['desktop_horizontal_position'] ?? ( $current['desktop_horizontal_position'] ?? 'right' ), [ 'left', 'center', 'right' ], true ) ? sanitize_key( $input['desktop_horizontal_position'] ?? $current['desktop_horizontal_position'] ) : 'right',
			'desktop_horizontal_vertical_position' => in_array( $input['desktop_horizontal_vertical_position'] ?? ( $current['desktop_horizontal_vertical_position'] ?? 'bottom' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['desktop_horizontal_vertical_position'] ?? $current['desktop_horizontal_vertical_position'] ) : 'bottom',
			'tablet_vertical_position'    => in_array( $input['tablet_vertical_position'] ?? ( $current['tablet_vertical_position'] ?? 'center' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['tablet_vertical_position'] ?? $current['tablet_vertical_position'] ) : 'center',
			'tablet_horizontal_position'  => in_array( $input['tablet_horizontal_position'] ?? ( $current['tablet_horizontal_position'] ?? 'center' ), [ 'left', 'center', 'right' ], true ) ? sanitize_key( $input['tablet_horizontal_position'] ?? $current['tablet_horizontal_position'] ) : 'center',
			'tablet_horizontal_vertical_position' => in_array( $input['tablet_horizontal_vertical_position'] ?? ( $current['tablet_horizontal_vertical_position'] ?? 'bottom' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['tablet_horizontal_vertical_position'] ?? $current['tablet_horizontal_vertical_position'] ) : 'bottom',
			'mobile_vertical_position'    => in_array( $input['mobile_vertical_position'] ?? ( $current['mobile_vertical_position'] ?? 'bottom' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['mobile_vertical_position'] ?? $current['mobile_vertical_position'] ) : 'bottom',
			'mobile_horizontal_position'  => in_array( $input['mobile_horizontal_position'] ?? ( $current['mobile_horizontal_position'] ?? 'right' ), [ 'left', 'center', 'right' ], true ) ? sanitize_key( $input['mobile_horizontal_position'] ?? $current['mobile_horizontal_position'] ) : 'right',
			'mobile_horizontal_vertical_position' => in_array( $input['mobile_horizontal_vertical_position'] ?? ( $current['mobile_horizontal_vertical_position'] ?? 'bottom' ), [ 'center', 'bottom' ], true ) ? sanitize_key( $input['mobile_horizontal_vertical_position'] ?? $current['mobile_horizontal_vertical_position'] ) : 'bottom',
			'mobile_breakpoint'   => 640,
			'tablet_breakpoint'   => 1024,
			'enabled_items'       => $enabled,
			'item_order'          => $order,
			'custom_items'        => $custom_items,
			'menu_label'          => sanitize_text_field( $input['menu_label'] ?? ( $current['menu_label'] ?? 'Menu' ) ),
			'menu_url'            => esc_url_raw( $input['menu_url'] ?? ( $current['menu_url'] ?? '' ) ),
			'menu_nav_id'         => absint( $input['menu_nav_id'] ?? ( $current['menu_nav_id'] ?? 0 ) ),
			'menu_nav_ids'        => isset( $input['menu_nav_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $input['menu_nav_ids'] ) ) ) : (array) ( $current['menu_nav_ids'] ?? [] ),
			'menu_heading_tag'    => $heading,
			'menu_items'          => isset( $input['menu_items'] ) ? $this->sanitize_menu_items( $input['menu_items'] ) : (array) ( $current['menu_items'] ?? [] ),
			'menu_context_enabled' => array_key_exists( 'menu_context_enabled', $input ) ? ! empty( $input['menu_context_enabled'] ) : ! empty( $current['menu_context_enabled'] ),
			'menu_context_title' => sanitize_text_field( $input['menu_context_title'] ?? ( $current['menu_context_title'] ?? 'Table of contents' ) ),
			'menu_context_locations' => isset( $input['menu_context_locations'] ) ? $this->sanitize_menu_locations( $input['menu_context_locations'] ) : (array) ( $current['menu_context_locations'] ?? [] ),
			'menu_context_page_ids' => isset( $input['menu_context_page_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $input['menu_context_page_ids'] ) ) ) : (array) ( $current['menu_context_page_ids'] ?? [] ),
			'menu_context_heading_levels' => isset( $input['menu_context_heading_levels'] ) ? $this->sanitize_heading_levels( $input['menu_context_heading_levels'] ) : (array) ( $current['menu_context_heading_levels'] ?? [ 'h1', 'h2', 'h3' ] ),
			'admin_dashboard_link_enabled' => array_key_exists( 'admin_dashboard_link_enabled', $input ) ? ! empty( $input['admin_dashboard_link_enabled'] ) : ! empty( $current['admin_dashboard_link_enabled'] ),
			'phonekey_visibility' => in_array( $input['phonekey_visibility'] ?? ( $current['phonekey_visibility'] ?? 'all' ), [ 'all', 'visitors', 'users', 'customers', 'admins' ], true ) ? sanitize_key( $input['phonekey_visibility'] ?? $current['phonekey_visibility'] ) : 'all',
			'hide_frontend_admin_bar' => array_key_exists( 'hide_frontend_admin_bar', $input ) ? ! empty( $input['hide_frontend_admin_bar'] ) : ! empty( $current['hide_frontend_admin_bar'] ),
		];
	}

	private function sanitize_menu_settings( $input, array $current ): array {
		$input = is_array( $input ) ? wp_unslash( $input ) : [];
		$heading = sanitize_key( $input['menu_heading_tag'] ?? ( $current['menu_heading_tag'] ?? 'span' ) );
		if ( ! in_array( $heading, [ 'span', 'p', 'h1', 'h2', 'h3', 'h4' ], true ) ) {
			$heading = 'span';
		}
		$nav_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['menu_nav_ids'] ?? [] ) ) ) ) );
		$nav_ids = array_values( array_filter( $nav_ids, static fn( int $id ): bool => is_nav_menu( $id ) ) );

		return [
			'menu_label'          => sanitize_text_field( $input['menu_label'] ?? 'Menu' ),
			'menu_heading_tag'    => $heading,
			'menu_nav_ids'        => $nav_ids,
			'menu_nav_id'         => absint( $nav_ids[0] ?? 0 ),
			'menu_items'          => $this->sanitize_menu_items( $input['menu_items'] ?? [] ),
			'admin_dashboard_link_enabled' => ! empty( $input['admin_dashboard_link_enabled'] ),
			'menu_context_enabled' => ! empty( $input['menu_context_enabled'] ),
			'menu_context_title'   => sanitize_text_field( $input['menu_context_title'] ?? 'Table of contents' ),
			'menu_context_locations' => $this->sanitize_menu_locations( $input['menu_context_locations'] ?? [] ),
			'menu_context_page_ids' => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['menu_context_page_ids'] ?? [] ) ) ) ) ),
			'menu_context_heading_levels' => $this->sanitize_heading_levels( $input['menu_context_heading_levels'] ?? [ 'h1', 'h2', 'h3' ] ),
		];
	}

	private function sanitize_menu_locations( $locations ): array {
		$locations = is_array( $locations ) ? $locations : [];
		return [
			'everywhere'     => ! empty( $locations['everywhere'] ),
			'single_post'    => ! empty( $locations['single_post'] ),
			'single_product' => ! empty( $locations['single_product'] ),
			'front_page'     => ! empty( $locations['front_page'] ),
			'selected_pages' => ! empty( $locations['selected_pages'] ),
		];

	}

	private function sanitize_heading_levels( $levels ): array {
		$levels = array_values( array_intersect( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], array_map( 'sanitize_key', (array) $levels ) ) );
		return $levels ?: [ 'h1', 'h2', 'h3' ];
	}

	private function sanitize_haptic_settings( $input, array $current ): array {
		$defaults = $this->settings->defaults()['haptic'];
		$next = wp_parse_args( is_array( $current ) ? $current : [], $defaults );
		$next['events'] = wp_parse_args( is_array( $next['events'] ?? null ) ? $next['events'] : [], $defaults['events'] );

		if ( ! is_array( $input ) ) {
		return $next;
	}

		$input = wp_unslash( $input );
		$events = is_array( $input['events'] ?? null ) ? $input['events'] : [];

		return [
			'enabled'           => ! empty( $input['enabled'] ),
			'vibration_enabled' => ! empty( $input['vibration_enabled'] ),
			'sound_enabled'     => ! empty( $input['sound_enabled'] ),
			'sound_profile'     => in_array( $input['sound_profile'] ?? '', [ 'soft', 'bright', 'pop', 'bell' ], true ) ? sanitize_key( $input['sound_profile'] ) : 'soft',
			'context'           => in_array( $input['context'] ?? '', [ 'website', 'appsite', 'both' ], true ) ? sanitize_key( $input['context'] ) : 'both',
			'events'            => [
				'buttons'       => ! empty( $events['buttons'] ),
				'quantity'      => ! empty( $events['quantity'] ),
				'swipe_back'    => ! empty( $events['swipe_back'] ),
				'notifications' => ! empty( $events['notifications'] ),
			],
		];
	}

	private function sanitize_search_settings( $input, array $current ): array {
		$defaults = $this->settings->defaults()['search'];
		$next     = wp_parse_args( is_array( $current ) ? $current : [], $defaults );

		// Other Kiwe settings forms do not submit Search. Absence means preserve,
		// while an actual Search form remains free to submit unchecked boxes.
		if ( ! is_array( $input ) ) {
			return $next;
		}

		$input    = wp_unslash( $input );
		$families = is_array( $input['families'] ?? null ) ? $input['families'] : [];

		$next['configuration_version'] = 2;
		$next['context_aware']         = ! empty( $input['context_aware'] );
		$next['alphabet_enabled']      = ! empty( $input['alphabet_enabled'] );
		$next['product_add_enabled']   = ! empty( $input['product_add_enabled'] );
		$next['bricks_bridge_enabled'] = ! empty( $input['bricks_bridge_enabled'] );
		$next['result_limit']          = max( 1, min( 12, absint( $input['result_limit'] ?? $defaults['result_limit'] ) ) );
		$next['families']              = [
			'products' => ! empty( $families['products'] ),
			'posts'    => ! empty( $families['posts'] ),
			'authors'  => ! empty( $families['authors'] ),
		];
		$custom_taxonomies = is_array( $input['custom_taxonomies'] ?? null )
			? $input['custom_taxonomies']
			: preg_split( '/[\s,]+/', (string) ( $input['custom_taxonomies'] ?? '' ) );
		$next['custom_taxonomies'] = array_values(
			array_slice(
				array_unique(
					array_filter(
						array_map( 'sanitize_key', (array) $custom_taxonomies ),
						static function ( string $taxonomy ): bool {
							if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
								return false;
							}
							$object = get_taxonomy( $taxonomy );
							return $object && ! empty( $object->public );
						}
					)
				),
				0,
				8
			)
		);

		if ( ! array_filter( $next['families'] ) ) {
			$next['families']['posts'] = true;
		}

		return $next;
	}

	private function dock_module_labels(): array {
		$labels = [];

		foreach ( $this->modules->public_modules() as $id => $module ) {
			if ( empty( $module['mode'] ) || ! in_array( $module['mode'], [ 'dock', 'action' ], true ) ) {
				continue;
			}

			$labels[ sanitize_key( $id ) ] = sanitize_text_field( $module['label'] ?? $id );
		}

		return $labels;
	}

	private function dock_custom_item_labels( array $items ): array {
		$labels = [];

		foreach ( $items as $item ) {
			$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}

			$labels[ $id ] = sanitize_text_field( (string) ( $item['label'] ?? $id ) );
		}

		return $labels;
	}

	private function dock_custom_items_for_admin( array $dock ): array {
		$items = isset( $dock['custom_items'] ) && is_array( $dock['custom_items'] ) ? $dock['custom_items'] : [];
		$out   = [];

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$url   = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			if ( '' === $label || '' === $url ) {
				continue;
			}

			$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id || 0 !== strpos( $id, 'link-' ) ) {
				$id = 'link-' . sanitize_title( $label );
			}
			if ( '' === $id || 'link-' === $id ) {
				$id = 'link-custom-' . ( (int) $index + 1 );
			}

			$out[] = [
				'id'      => $id,
				'label'   => $label,
				'url'     => $url,
				'icon'    => sanitize_key( (string) ( $item['icon'] ?? 'home' ) ) ?: 'home',
				'enabled' => ! empty( $item['enabled'] ),
			];
		}

		return array_slice( $out, 0, 12 );
	}

	private function sanitize_dock_custom_items( $items ): array {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$out  = [];
		$used = [];

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$url   = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			$icon  = sanitize_key( (string) ( $item['icon'] ?? 'home' ) ) ?: 'home';
			$id    = sanitize_key( (string) ( $item['id'] ?? '' ) );

			if ( '' === $label || '' === $url ) {
				continue;
			}

			if ( '' === $id || 0 !== strpos( $id, 'link-' ) ) {
				$id = 'link-' . sanitize_title( $label );
			}
			if ( '' === $id || 'link-' === $id ) {
				$id = 'link-custom-' . ( (int) $index + 1 );
			}

			$base = $id;
			$suffix = 2;
			while ( isset( $used[ $id ] ) ) {
				$id = $base . '-' . $suffix;
				$suffix++;
			}
			$used[ $id ] = true;

			$out[] = [
				'id'      => $id,
				'label'   => $label,
				'url'     => $url,
				'icon'    => $icon,
				'enabled' => true,
			];
		}

		return array_slice( $out, 0, 12 );
	}

	private function sanitize_dsa_theme( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$active = sanitize_hex_color( $input['active_color'] ?? ( $current['active_color'] ?? '' ) );
		$hover = sanitize_hex_color( $input['hover_color'] ?? ( $current['hover_color'] ?? '' ) );
		$hero = trim( (string) ( $input['hero_text_color'] ?? ( $current['hero_text_color'] ?? '' ) ) );

		if ( ! preg_match( '/^(#[0-9a-f]{3,6}|rgba?\([^)]+\))$/i', $hero ) ) {
			$hero = 'rgba(20,24,34,0.18)';
		}

		return [
			'active_color'          => $active ?: '#8f8f98',
			'hover_color'           => $hover ?: '#24c6a1',
			'hero_text_color'       => $hero,
			'confetti_color_source' => in_array( $input['confetti_color_source'] ?? ( $current['confetti_color_source'] ?? '' ), [ 'hero', 'active', 'hover' ], true ) ? sanitize_key( $input['confetti_color_source'] ?? $current['confetti_color_source'] ) : 'hero',
		];
	}

	private function sanitize_games_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$bonuses = [];
		$retry = [];

		for ( $i = 0; $i < 3; $i++ ) {
			$bonus = isset( $input['bonuses'][ $i ] ) && is_array( $input['bonuses'][ $i ] ) ? $input['bonuses'][ $i ] : [];
			$bonuses[] = [
				'label'    => sanitize_text_field( $bonus['label'] ?? sprintf( 'Attempt %d high score', $i + 1 ) ),
				'discount' => max( 0, min( 100, absint( $bonus['discount'] ?? 0 ) ) ),
			];
			$retry[] = sanitize_text_field( $input['retry_texts'][ $i ] ?? '' );
		}

		return [
			'surface_enabled'  => ! empty( $input['surface_enabled'] ),
			'show_on_page_load' => ! empty( $input['show_on_page_load'] ),
			'trigger_path'     => sanitize_text_field( $input['trigger_path'] ?? '/shop' ),
			'trigger_game'     => in_array( $input['trigger_game'] ?? 'dino', [ 'dino', 'star' ], true ) ? sanitize_key( $input['trigger_game'] ) : 'dino',
			'start_title'      => sanitize_text_field( $input['start_title'] ?? 'Are You Game! for discount??' ),
			'start_text'       => sanitize_text_field( $input['start_text'] ?? 'Press any key to start' ),
			'mobile_start_text' => sanitize_text_field( $input['mobile_start_text'] ?? 'Touch to start' ),
			'duration_ms'      => max( 0, min( 60000, absint( $input['duration_ms'] ?? 0 ) ) ),
			'confetti_enabled' => ! empty( $input['confetti_enabled'] ),
			'rewards_enabled'  => ! empty( $input['rewards_enabled'] ),
			'coupon_enabled'   => ! empty( $input['coupon_enabled'] ),
			'max_attempts_per_day' => max( 1, min( 10, absint( $input['max_attempts_per_day'] ?? 3 ) ) ),
			'coupon_expiry_minutes' => max( 5, min( 1440, absint( $input['coupon_expiry_minutes'] ?? 20 ) ) ),
			'daily_coupon_budget' => max( 1, min( 100000, absint( $input['daily_coupon_budget'] ?? 100 ) ) ),
			'min_play_ms'      => max( 1000, min( 30000, absint( $input['min_play_ms'] ?? 4000 ) ) ),
			'max_play_ms'      => max( 15000, min( 300000, absint( $input['max_play_ms'] ?? 180000 ) ) ),
			'max_score'        => max( 100, min( 100000, absint( $input['max_score'] ?? 10000 ) ) ),
			'bonuses'          => $bonuses,
			'retry_texts'      => $retry,
		];
	}

	private function sanitize_schema_geo_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );

		return [
			'enabled'        => ! empty( $input['enabled'] ),
			'woo_product'    => ! empty( $input['woo_product'] ),
			'breadcrumb'     => ! empty( $input['breadcrumb'] ),
			'webpage'        => ! empty( $input['webpage'] ),
			'registry_hints' => ! empty( $input['registry_hints'] ),
		];
	}

	private function sanitize_metrics_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );

		return [
			'enabled'        => ! empty( $input['enabled'] ),
			'retention_days' => max( 1, min( 90, absint( $input['retention_days'] ?? 14 ) ) ),
		];
	}

	private function sanitize_permissions_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );

		return [
			'enabled'                      => ! empty( $input['enabled'] ),
			'retention_days'               => max( 1, min( 90, absint( $input['retention_days'] ?? 30 ) ) ),
			'cooldown_hours'               => max( 1, min( 720, absint( $input['cooldown_hours'] ?? 24 ) ) ),
			'max_asks_per_session'         => max( 1, min( 5, absint( $input['max_asks_per_session'] ?? 1 ) ) ),
			'pwa_enabled'                  => ! empty( $input['pwa_enabled'] ),
			'offline_editorial_enabled'   => ! empty( $input['offline_editorial_enabled'] ),
			'pwa_min_home_views'           => max( 0, min( 20, absint( $input['pwa_min_home_views'] ?? 1 ) ) ),
			'pwa_min_dock_opens'           => max( 0, min( 20, absint( $input['pwa_min_dock_opens'] ?? 1 ) ) ),
			'pwa_min_transition_completes' => max( 0, min( 50, absint( $input['pwa_min_transition_completes'] ?? 1 ) ) ),
			'pwa_min_game_completes'       => max( 0, min( 20, absint( $input['pwa_min_game_completes'] ?? 0 ) ) ),
			'pwa_title'                    => sanitize_text_field( $input['pwa_title'] ?? 'Install this appsite?' ),
			'pwa_message'                  => sanitize_text_field( $input['pwa_message'] ?? 'Kiwe will open your browser install prompt now.' ),
			'notifications_enabled'        => ! empty( $input['notifications_enabled'] ),
			'notifications_title'          => sanitize_text_field( $input['notifications_title'] ?? 'Turn on browser notifications?' ),
			'notifications_message'        => sanitize_text_field( $input['notifications_message'] ?? 'Get useful order, account, and store updates when you choose.' ),
			'notification_preferences_enabled' => ! empty( $input['notification_preferences_enabled'] ),
			'notification_order_prompt_enabled' => ! empty( $input['notification_order_prompt_enabled'] ),
			'notification_cta_label'       => sanitize_text_field( $input['notification_cta_label'] ?? 'Notify me' ),
			'notification_cta_color'       => 'hover' === sanitize_key( $input['notification_cta_color'] ?? 'active' ) ? 'hover' : 'active',
			'ai_popup_duration_ms'         => max( 2000, min( 15000, (int) round( (float) ( $input['ai_popup_duration_seconds'] ?? 3.2 ) * 1000 ) ) ),
			'location_enabled'             => ! empty( $input['location_enabled'] ),
			'camera_enabled'               => ! empty( $input['camera_enabled'] ),
		];
	}

	private function sanitize_protected_flow_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );

		return [
			'rail_enabled' => ! empty( $input['rail_enabled'] ),
		];
	}

	private function sanitize_secure_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$roles = isset( $input['auto_logout_roles'] ) && is_array( $input['auto_logout_roles'] ) ? $input['auto_logout_roles'] : [];
		$valid_roles = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->get_names() ) : [];
		$roles = array_values(
			array_intersect(
				array_map( 'sanitize_key', $roles ),
				$valid_roles
			)
		);

		return [
			'enabled'             => ! empty( $input['enabled'] ),
			'auto_logout_enabled' => ! empty( $input['auto_logout_enabled'] ),
			'auto_logout_minutes' => max( 1, min( 1440, absint( $input['auto_logout_minutes'] ?? ( $current['auto_logout_minutes'] ?? 30 ) ) ) ),
			'auto_logout_roles'   => $roles,
			'trusted_proxy_cidrs' => $this->sanitize_cidr_lines( $input['trusted_proxy_cidrs'] ?? ( $current['trusted_proxy_cidrs'] ?? '' ) ),
		];
	}

	private function sanitize_cidr_lines( $value ): string {
		$lines = preg_split( '/[\r\n,]+/', (string) $value ) ?: [];
		$valid = [];
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) continue;
			$parts = explode( '/', $line, 2 );
			$ip = filter_var( $parts[0], FILTER_VALIDATE_IP );
			if ( false === $ip ) continue;
			$is_ipv6 = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
			$max = $is_ipv6 ? 128 : 32;
			$minimum = $is_ipv6 ? 32 : 8;
			$bits = isset( $parts[1] ) ? (int) $parts[1] : $max;
			if ( $bits < $minimum || $bits > $max ) continue;
			$valid[] = $ip . '/' . $bits;
		}
		return implode( "\n", array_values( array_unique( $valid ) ) );
	}

	private function sanitize_email_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$smtp_input = is_array( $input['smtp'] ?? null ) ? $input['smtp'] : [];
		$smtp_current = is_array( $current['smtp'] ?? null ) ? $current['smtp'] : [];
		$password = trim( (string) ( $smtp_input['password'] ?? '' ) );
		$stored_password = (string) ( $smtp_current['password'] ?? '' );

		if ( '' !== $password ) {
			$encrypted = Secret_Store::encrypt( $password );
			$stored_password = '' !== $encrypted ? $encrypted : $stored_password;
		}

		$transport = sanitize_key( (string) ( $input['transport'] ?? ( $current['transport'] ?? 'wordpress' ) ) );
		$encryption = sanitize_key( (string) ( $smtp_input['encryption'] ?? ( $smtp_current['encryption'] ?? 'tls' ) ) );

		return [
			'enabled'    => ! empty( $input['enabled'] ),
			'transport'  => in_array( $transport, [ 'wordpress', 'smtp' ], true ) ? $transport : 'wordpress',
			'from_name'  => sanitize_text_field( (string) ( $input['from_name'] ?? '' ) ),
			'from_email' => sanitize_email( (string) ( $input['from_email'] ?? '' ) ),
			'smtp'       => [
				'host'       => sanitize_text_field( (string) ( $smtp_input['host'] ?? '' ) ),
				'port'       => max( 1, min( 65535, absint( $smtp_input['port'] ?? ( $smtp_current['port'] ?? 587 ) ) ) ),
				'encryption' => in_array( $encryption, [ 'tls', 'ssl', 'none' ], true ) ? $encryption : 'tls',
				'auth'       => ! empty( $smtp_input['auth'] ),
				'username'   => sanitize_text_field( (string) ( $smtp_input['username'] ?? '' ) ),
				'password'   => $stored_password,
			],
		];
	}

	private function sanitize_abandoned_cart_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$current_channels = is_array( $current['channels'] ?? null ) ? $current['channels'] : [];
		$channel_input = is_array( $input['channels'] ?? null ) ? $input['channels'] : [];
		$channels = [];

		foreach ( [ 'sms', 'whatsapp' ] as $channel ) {
			$posted = is_array( $channel_input[ $channel ] ?? null ) ? $channel_input[ $channel ] : [];
			$existing = is_array( $current_channels[ $channel ] ?? null ) ? $current_channels[ $channel ] : [];
			$token = trim( (string) ( $posted['api_token'] ?? '' ) );
			$stored_token = (string) ( $existing['api_token'] ?? '' );

			if ( '' !== $token ) {
				$encrypted = Secret_Store::encrypt( $token );
				$stored_token = '' !== $encrypted ? $encrypted : $stored_token;
			}

			$url = esc_url_raw( (string) ( $posted['webhook_url'] ?? '' ) );
			$channels[ $channel ] = [
				'enabled'     => ! empty( $posted['enabled'] ),
				'webhook_url' => wp_http_validate_url( $url ) ? $url : '',
				'api_token'   => $stored_token,
				'sender'      => sanitize_text_field( (string) ( $posted['sender'] ?? '' ) ),
			];
		}

		return [
			'enabled'                  => ! empty( $input['enabled'] ),
			'manual_reminders_enabled' => ! empty( $input['manual_reminders_enabled'] ),
			'inactivity_minutes'       => max( 15, min( 43200, absint( $input['inactivity_minutes'] ?? ( $current['inactivity_minutes'] ?? 60 ) ) ) ),
			'heartbeat_minutes'        => max( 1, min( 60, absint( $input['heartbeat_minutes'] ?? ( $current['heartbeat_minutes'] ?? 5 ) ) ) ),
			'cooldown_hours'           => max( 1, min( 720, absint( $input['cooldown_hours'] ?? ( $current['cooldown_hours'] ?? 24 ) ) ) ),
			'max_reminders'            => max( 1, min( 10, absint( $input['max_reminders'] ?? ( $current['max_reminders'] ?? 3 ) ) ) ),
			'recovery_link_days'       => max( 1, min( 30, absint( $input['recovery_link_days'] ?? ( $current['recovery_link_days'] ?? 7 ) ) ) ),
			'retention_days'           => max( 7, min( 730, absint( $input['retention_days'] ?? ( $current['retention_days'] ?? 90 ) ) ) ),
			'email_subject'             => sanitize_text_field( (string) ( $input['email_subject'] ?? ( $current['email_subject'] ?? '' ) ) ),
			'email_message'             => sanitize_textarea_field( (string) ( $input['email_message'] ?? ( $current['email_message'] ?? '' ) ) ),
			'sms_message'               => sanitize_textarea_field( (string) ( $input['sms_message'] ?? ( $current['sms_message'] ?? '' ) ) ),
			'whatsapp_message'          => sanitize_textarea_field( (string) ( $input['whatsapp_message'] ?? ( $current['whatsapp_message'] ?? '' ) ) ),
			'channels'                  => $channels,
		];
	}

	private function sanitize_commerce_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$alert_threshold = max( 1, min( 999, absint( $input['stock_badge_alert_threshold'] ?? ( $current['stock_badge_alert_threshold'] ?? 10 ) ) ) );
		$urgent_threshold = max( 1, min( $alert_threshold, absint( $input['stock_badge_urgent_threshold'] ?? ( $current['stock_badge_urgent_threshold'] ?? 3 ) ) ) );

		return [
			'cart_surface_enabled' => ! empty( $input['cart_surface_enabled'] ),
			'checkout_surface_enabled' => ! empty( $input['checkout_surface_enabled'] ),
			'cart_quantity_controls' => ! empty( $input['cart_quantity_controls'] ),
			'cart_badges_enabled' => ! empty( $input['cart_badges_enabled'] ),
			'stock_badge_alert_threshold' => $alert_threshold,
			'stock_badge_alert_text' => sanitize_text_field( $input['stock_badge_alert_text'] ?? ( $current['stock_badge_alert_text'] ?? 'Only %d left' ) ),
			'stock_badge_urgent_threshold' => $urgent_threshold,
			'stock_badge_urgent_text' => sanitize_text_field( $input['stock_badge_urgent_text'] ?? ( $current['stock_badge_urgent_text'] ?? 'Almost gone: %d left' ) ),
			'cross_sells_enabled' => ! empty( $input['cross_sells_enabled'] ),
			'upsell_banner_enabled' => ! empty( $input['upsell_banner_enabled'] ),
			'cart_upsell_discounts_enabled' => ! empty( $input['cart_upsell_discounts_enabled'] ),
			'linked_products_enabled' => ! empty( $input['linked_products_enabled'] ),
			'cross_sells_product_panel_enabled' => ! empty( $input['cross_sells_product_panel_enabled'] ),
			'commerce_recommendations_enabled' => ! empty( $input['commerce_recommendations_enabled'] ),
			'fbt_enabled' => ! empty( $input['fbt_enabled'] ),
			'fbt_title' => sanitize_text_field( $input['fbt_title'] ?? ( $current['fbt_title'] ?? 'Frequently Bought Together' ) ),
			'fbt_max_products' => max( 1, min( 12, absint( $input['fbt_max_products'] ?? ( $current['fbt_max_products'] ?? 6 ) ) ) ),
			'fbt_show_out_of_stock' => ! empty( $input['fbt_show_out_of_stock'] ),
			'add_to_cart_mode' => in_array( sanitize_key( $input['add_to_cart_mode'] ?? ( $current['add_to_cart_mode'] ?? 'default' ) ), [ 'default', 'plus_only', 'quantity', 'replace' ], true ) ? sanitize_key( $input['add_to_cart_mode'] ?? ( $current['add_to_cart_mode'] ?? 'default' ) ) : 'default',
			'first_cart_confetti_enabled' => ! empty( $input['first_cart_confetti_enabled'] ),
			'co_purchase_daily_sync_enabled' => ! empty( $input['co_purchase_daily_sync_enabled'] ),
			'co_purchase_daily_sync_depth' => max( 1, min( 50, absint( $input['co_purchase_daily_sync_depth'] ?? ( $current['co_purchase_daily_sync_depth'] ?? 5 ) ) ) ),
			'co_purchase_daily_sync_mode' => 'replace' === sanitize_key( $input['co_purchase_daily_sync_mode'] ?? ( $current['co_purchase_daily_sync_mode'] ?? 'merge' ) ) ? 'replace' : 'merge',
			'bestseller_enabled' => ! empty( $input['bestseller_enabled'] ),
			'bestseller_limit' => max( 3, min( 100, absint( $input['bestseller_limit'] ?? ( $current['bestseller_limit'] ?? 20 ) ) ) ),
			'bestseller_sync_on_order' => ! empty( $input['bestseller_sync_on_order'] ),
			'bestseller_parent_label' => sanitize_text_field( $input['bestseller_parent_label'] ?? ( $current['bestseller_parent_label'] ?? 'Bestseller' ) ),
			'bestseller_parent_slug' => sanitize_title( $input['bestseller_parent_slug'] ?? ( $current['bestseller_parent_slug'] ?? 'bestseller' ) ),
			'cod_gate' => $this->sanitize_cod_gate_settings( $input['cod_gate'] ?? null, is_array( $current['cod_gate'] ?? null ) ? $current['cod_gate'] : [] ),
		];
	}

	private function sanitize_cod_gate_settings( $input, array $current ): array {
		$input = is_array( $input ) ? wp_unslash( $input ) : [];
		$regain = sanitize_key( $input['regain'] ?? ( $current['regain'] ?? 'prepaid_success' ) );

		return [
			'enabled'                      => ! empty( $input['enabled'] ),
			'strikes_to_block'             => max( 1, min( 10, absint( $input['strikes_to_block'] ?? ( $current['strikes_to_block'] ?? 1 ) ) ) ),
			'trusted_skip_after_completed' => max( 0, min( 50, absint( $input['trusted_skip_after_completed'] ?? ( $current['trusted_skip_after_completed'] ?? 1 ) ) ) ),
			'regain'                       => in_array( $regain, [ 'prepaid_success', 'never' ], true ) ? $regain : 'prepaid_success',
			'block_message'                => sanitize_text_field( $input['block_message'] ?? ( $current['block_message'] ?? 'Cash on delivery is not available for this order. Please choose a prepaid payment method.' ) ),
			'allow_unverified_on_failure'  => ! empty( $input['allow_unverified_on_failure'] ),
		];
	}

	private function sanitize_bricks_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );

		return [
			'mini_cart_adapter_enabled' => ! empty( $input['mini_cart_adapter_enabled'] ),
			'add_to_cart_enhancer_enabled' => ! empty( $input['add_to_cart_enhancer_enabled'] ),
			'dynamic_tags_enabled' => ! empty( $input['dynamic_tags_enabled'] ),
			'dsa_icon_launcher_enabled' => ! empty( $input['dsa_icon_launcher_enabled'] ),
			'linked_products_controls_enabled' => ! empty( $input['linked_products_controls_enabled'] ),
			'prefer_bricks_native_cart' => ! empty( $input['prefer_bricks_native_cart'] ),
			'quantity_stepper_enabled'  => ! empty( $input['quantity_stepper_enabled'] ),
			'stock_badge_enabled'       => ! empty( $input['stock_badge_enabled'] ),
			'verified_version'          => '2.4-beta-source-reviewed',
		];
	}

	private function sanitize_link_hub_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$socials = [];

		foreach ( array_keys( $this->social_link_labels() ) as $id ) {
			$socials[ $id ] = esc_url_raw( $input['social_links'][ $id ] ?? '' );
		}

		$review_source = sanitize_key( $input['review_source'] ?? 'manual' );

		return [
			'site_score'      => '' === trim( (string) ( $input['site_score'] ?? '' ) ) ? '' : max( 0, min( 100, absint( $input['site_score'] ) ) ),
			'shop_label'      => sanitize_text_field( $input['shop_label'] ?? 'Shop' ),
			'shop_url'        => esc_url_raw( $input['shop_url'] ?? '' ),
			'posts_title'     => sanitize_text_field( $input['posts_title'] ?? '' ),
			'posts_category'  => absint( $input['posts_category'] ?? 0 ),
			'ssl_provider'    => sanitize_text_field( $input['ssl_provider'] ?? '' ),
			'payment_provider' => sanitize_text_field( $input['payment_provider'] ?? '' ),
			'review_source'   => in_array( $review_source, [ 'manual', 'google' ], true ) ? $review_source : 'manual',
			'google_place_id' => sanitize_text_field( $input['google_place_id'] ?? '' ),
			'google_api_key'  => '' !== trim( (string) ( $input['google_api_key'] ?? '' ) ) ? sanitize_text_field( $input['google_api_key'] ) : (string) ( $current['google_api_key'] ?? '' ),
			'testimonials'    => sanitize_textarea_field( $input['testimonials'] ?? '' ),
			'social_links'    => $socials,
		];
	}

	private function sanitize_app_settings( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$input = wp_unslash( $input );
		$labels = isset( $input['button_labels'] ) && is_array( $input['button_labels'] ) ? $input['button_labels'] : [];

		return [
			'welcome_message' => sanitize_text_field( $input['welcome_message'] ?? 'Welcome to Our Appsite' ),
			'pwa_pitch'       => sanitize_text_field( $input['pwa_pitch'] ?? 'Try our app. No app store required.' ),
			'ios_url'         => esc_url_raw( $input['ios_url'] ?? '' ),
			'playstore_url'   => esc_url_raw( $input['playstore_url'] ?? '' ),
			'android_url'     => esc_url_raw( $input['android_url'] ?? '' ),
			'pc_url'          => esc_url_raw( $input['pc_url'] ?? '' ),
			'idle_enabled'    => ! empty( $input['idle_enabled'] ),
			'idle_delay_ms'   => max( 10000, min( 1800000, absint( $input['idle_delay_seconds'] ?? 60 ) * 1000 ) ),
			'button_labels'   => [
				'playstore' => sanitize_text_field( $labels['playstore'] ?? 'Play Store' ),
				'android'   => sanitize_text_field( $labels['android'] ?? 'Android' ),
				'pc'        => sanitize_text_field( $labels['pc'] ?? 'PC' ),
			],
		];
	}

	private function social_link_labels(): array {
		return [
			'facebook'  => 'Facebook',
			'instagram' => 'Instagram',
			'x'         => 'X',
			'youtube'   => 'YouTube',
			'pinterest' => 'Pinterest',
			'linkedin'  => 'LinkedIn',
		];
	}

	private function sanitize_menu_items( $items ): array {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$output = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title = sanitize_text_field( $item['title'] ?? '' );
			$url   = esc_url_raw( $item['url'] ?? '' );
			$type  = sanitize_text_field( $item['type'] ?? '' );
			$image = esc_url_raw( $item['image'] ?? '' );
			$object_id = absint( $item['object_id'] ?? 0 );
			$object_type = sanitize_key( $item['object_type'] ?? '' );

			if ( '' === $title || '' === $url ) {
				continue;
			}

			$output[] = [
				'title' => $title,
				'url'   => $url,
				'type'  => $type,
				'image' => $image,
				'object_id'   => $object_id,
				'object_type' => $object_type,
			];
		}

		return array_slice( $output, 0, 20 );
	}

	private function sanitize_transition_messages( $messages ): array {
		if ( ! is_array( $messages ) ) {
			return [];
		}

		$output = [];

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$title = sanitize_text_field( $message['title'] ?? '' );
			$body  = sanitize_textarea_field( $message['message'] ?? '' );

			if ( '' === $title && '' === $body ) {
				continue;
			}

			$output[] = [
				'title'   => $title,
				'message' => $body,
			];
		}

		if ( empty( $output ) ) {
			$output[] = [
				'title'   => 'Did you know',
				'message' => 'Kiwe keeps the Surface dock available while the next page loads.',
			];
		}

		return array_slice( $output, 0, 20 );
	}

	private function menu_items_for_admin( array $dock ): array {
		$items = isset( $dock['menu_items'] ) && is_array( $dock['menu_items'] ) ? $dock['menu_items'] : [];

		if ( empty( $items ) && ! empty( $dock['menu_url'] ) ) {
			$items[] = [
				'title' => $dock['menu_label'] ?? 'Menu',
				'url'   => $dock['menu_url'],
				'type'  => 'Link',
				'image' => '',
			];
		}

		if ( empty( $items ) ) {
			$items[] = [
				'title' => '',
				'url'   => '',
				'type'  => '',
				'image' => '',
			];
		}

		return $items;
	}

	private function nav_menu_options(): array {
		$menus = wp_get_nav_menus();
		$options = [];

		foreach ( is_array( $menus ) ? $menus : [] as $menu ) {
			if ( ! isset( $menu->term_id, $menu->name ) ) {
				continue;
			}

			$options[ (int) $menu->term_id ] = sanitize_text_field( (string) $menu->name );
		}

		return $options;
	}

	private function transition_messages_for_admin( array $visual ): array {
		$messages = isset( $visual['transition_messages'] ) && is_array( $visual['transition_messages'] ) ? $visual['transition_messages'] : [];

		if ( empty( $messages ) ) {
			$messages[] = [
				'title'   => 'Did you know',
				'message' => 'Kiwe keeps the Surface dock available while the next page loads.',
			];
		}

		return $messages;
	}

	private function menu_targets( string $query ): array {
		global $wpdb;

		$targets = [];
		$seen    = [];

		$add_target = static function ( string $title, string $url, string $type, string $image = '', int $object_id = 0, string $object_type = '' ) use ( &$targets, &$seen ): void {
			if ( '' === $title || '' === $url || isset( $seen[ $url ] ) ) {
				return;
			}

			$seen[ $url ] = true;
			$targets[]    = [
				'title'       => $title,
				'url'         => $url,
				'type'        => $type,
				'image'       => esc_url_raw( $image ),
				'object_id'   => $object_id,
				'object_type' => sanitize_key( $object_type ),
			];
		};

		if ( false !== stripos( 'home', $query ) || false !== stripos( home_url( '/' ), $query ) ) {
			$add_target( __( 'Home', 'dsa' ), home_url( '/' ), __( 'Navigation', 'dsa' ), '', (int) get_option( 'page_on_front' ), 'front_page' );
		}

		$like        = '%' . $wpdb->esc_like( $query ) . '%';
		$prefix_like = $wpdb->esc_like( $query ) . '%';
		$post_types  = get_post_types( [ 'public' => true ], 'objects' );
		unset( $post_types['attachment'] );

		if ( ! empty( $post_types ) ) {
			$post_type_keys = array_keys( $post_types );
			$placeholders   = implode( ', ', array_fill( 0, count( $post_type_keys ), '%s' ) );
			$params         = array_merge( $post_type_keys, [ $like, $like, $prefix_like, $prefix_like ] );
			$sql            = "
				SELECT ID, post_type, post_title
				FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				AND post_type IN ({$placeholders})
				AND (post_title LIKE %s OR post_name LIKE %s)
				ORDER BY CASE WHEN post_title LIKE %s OR post_name LIKE %s THEN 0 ELSE 1 END, post_title ASC
				LIMIT 14
			";
			$posts          = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

			foreach ( $posts as $post ) {
				$type_label = $post_types[ $post->post_type ]->labels->singular_name ?? $post->post_type;
				$title      = get_the_title( (int) $post->ID );

				$add_target( $title ?: __( '(no title)', 'dsa' ), get_permalink( (int) $post->ID ), $type_label, get_the_post_thumbnail_url( (int) $post->ID, 'medium' ) ?: '', (int) $post->ID, (string) $post->post_type );
			}
		}

		$taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );

		if ( ! empty( $taxonomies ) ) {
			$taxonomy_keys = array_keys( $taxonomies );
			$placeholders  = implode( ', ', array_fill( 0, count( $taxonomy_keys ), '%s' ) );
			$params        = array_merge( $taxonomy_keys, [ $like, $like, $prefix_like, $prefix_like ] );
			$sql           = "
				SELECT t.term_id, t.name, tt.taxonomy
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy IN ({$placeholders})
				AND (t.name LIKE %s OR t.slug LIKE %s)
				ORDER BY CASE WHEN t.name LIKE %s OR t.slug LIKE %s THEN 0 ELSE 1 END, t.name ASC
				LIMIT 14
			";
			$terms         = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

			foreach ( $terms as $term ) {
				$url = get_term_link( (int) $term->term_id, $term->taxonomy );

				if ( is_wp_error( $url ) ) {
					continue;
				}

				$type_label = $taxonomies[ $term->taxonomy ]->labels->singular_name ?? $term->taxonomy;
				$image_id = (int) get_term_meta( (int) $term->term_id, 'thumbnail_id', true );
				$image = $image_id ? ( wp_get_attachment_image_url( $image_id, 'medium' ) ?: '' ) : '';
				$add_target( $term->name, $url, $type_label, $image, (int) $term->term_id, (string) $term->taxonomy );
			}
		}

		return array_slice( $targets, 0, 20 );
	}
}
