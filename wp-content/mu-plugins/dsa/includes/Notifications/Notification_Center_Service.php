<?php

namespace DSA\Notifications;

use DSA\Secure\SecureTrack_Settings_Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One role-aware WordPress menu over Kiwe's existing preference authority. */
final class Notification_Center_Service {
	private Notification_Preference_Service $preferences;

	public function __construct( Notification_Preference_Service $preferences ) {
		$this->preferences = $preferences;
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 32 );
		add_action( 'admin_post_kiwe_save_notification_preferences', [ $this, 'save' ] );
		add_action( 'admin_post_kiwe_save_notification_policy', [ $this, 'save_security_policy' ] );
	}

	public function menu(): void {
		if ( is_user_logged_in() ) {
			add_menu_page( __( 'Notifications', 'dsa' ), __( 'Notifications', 'dsa' ), 'kiwe_use_notifications', 'kiwe-notifications', [ $this, 'render' ], 'dashicons-bell', 32 );
		}
	}

	public function save(): void {
		if ( ! current_user_can( 'kiwe_use_notifications' ) ) {
			wp_die( esc_html__( 'Notification access is not available.', 'dsa' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'kiwe_save_notification_preferences' );
		$this->preferences->save(
			[
				'visitorId'        => 'admin:user:' . get_current_user_id(),
				'topics'           => array_map( 'sanitize_key', (array) ( $_POST['topics'] ?? [] ) ),
				'channels'         => array_map( 'sanitize_key', (array) ( $_POST['channels'] ?? [] ) ),
				'browserPermission'=> sanitize_key( wp_unslash( $_POST['browser_permission'] ?? 'default' ) ),
				'context'          => 'wordpress_admin',
			]
		);
		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-notifications', 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'kiwe_use_notifications' ) ) {
			wp_die( esc_html__( 'Notification access is not available.', 'dsa' ), '', [ 'response' => 403 ] );
		}
		$config = $this->preferences->public_config();
		$stored = $this->preferences->preferences( 'admin:user:' . get_current_user_id() );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Notifications', 'dsa' ); ?></h1><p><?php esc_html_e( 'Choose only the events relevant to your work. Kiwe uses the same preferences in WordPress, the AppShell and connected delivery channels.', 'dsa' ); ?></p>
		<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Notification preferences saved.', 'dsa' ); ?></p></div><?php endif; ?>
		<?php if ( empty( $config['enabled'] ) ) : ?><div class="notice notice-warning"><p><?php esc_html_e( 'Personal notifications are currently disabled by the site owner. Your choices can be reviewed here, but delivery remains off.', 'dsa' ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_save_notification_preferences"><?php wp_nonce_field( 'kiwe_save_notification_preferences' ); ?>
		<h2><?php esc_html_e( 'Events', 'dsa' ); ?></h2><fieldset><?php foreach ( (array) ( $config['topics'] ?? [] ) as $topic ) : ?><p><label><input type="checkbox" name="topics[]" value="<?php echo esc_attr( $topic['id'] ); ?>" <?php checked( in_array( $topic['id'], (array) ( $stored['topics'] ?? [] ), true ) ); ?>> <strong><?php echo esc_html( $topic['label'] ); ?></strong> — <?php echo esc_html( $topic['description'] ); ?></label></p><?php endforeach; ?></fieldset>
		<h2><?php esc_html_e( 'Delivery channels', 'dsa' ); ?></h2><fieldset><?php foreach ( (array) ( $config['channels'] ?? [] ) as $channel ) : $available = ! empty( $channel['available'] ); ?><p><label><input type="checkbox" name="channels[]" value="<?php echo esc_attr( $channel['id'] ); ?>" <?php checked( in_array( $channel['id'], (array) ( $stored['channels'] ?? [] ), true ) ); ?> <?php disabled( ! $available ); ?>> <strong><?php echo esc_html( $channel['label'] ); ?></strong> — <?php echo esc_html( $channel['description'] ); ?><?php if ( ! $available ) : ?> <em><?php esc_html_e( '(not configured)', 'dsa' ); ?></em><?php endif; ?></label></p><?php endforeach; ?></fieldset>
		<?php submit_button( __( 'Save my notifications', 'dsa' ) ); ?></form>
		<?php if ( current_user_can( 'kiwe_manage_notification_policy' ) ) : $security = SecureTrack_Settings_Policy::notification_policy(); ?><hr><h2><?php esc_html_e( 'SecureTrack alert policy', 'dsa' ); ?></h2><p><?php esc_html_e( 'Security keeps its own severity authority. Select SecureTrack incidents under Events and choose Email, WhatsApp or SMS above; Kiwe uses the same shared gateways as every other notification.', 'dsa' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_save_notification_policy"><?php wp_nonce_field( 'kiwe_save_notification_policy' ); ?><table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Incident notifications', 'dsa' ); ?></th><td><label><input type="checkbox" name="alert_on_red" value="1" <?php checked( ! empty( $security['alert_on_red'] ) ); ?>> <?php esc_html_e( 'Generate escalated SecureTrack notifications', 'dsa' ); ?></label></td></tr><tr><th><?php esc_html_e( 'Severity', 'dsa' ); ?></th><td><select name="alert_delivery_policy"><option value="actionable" <?php selected( $security['alert_delivery_policy'] ?? 'actionable', 'actionable' ); ?>><?php esc_html_e( 'Critical/actionable only', 'dsa' ); ?></option><option value="yellow_and_actionable" <?php selected( $security['alert_delivery_policy'] ?? 'actionable', 'yellow_and_actionable' ); ?>><?php esc_html_e( 'Yellow and critical/actionable', 'dsa' ); ?></option></select></td></tr><tr><th><?php esc_html_e( 'Flood control', 'dsa' ); ?></th><td><label><?php esc_html_e( 'Repeat window (minutes)', 'dsa' ); ?> <input type="number" name="alert_repeat_window_mins" min="1" max="1440" value="<?php echo esc_attr( (string) ( $security['alert_repeat_window_mins'] ?? 15 ) ); ?>"></label> <label><?php esc_html_e( 'Hourly limit', 'dsa' ); ?> <input type="number" name="alert_hourly_limit" min="1" max="100" value="<?php echo esc_attr( (string) ( $security['alert_hourly_limit'] ?? 8 ) ); ?>"></label></td></tr></table><?php submit_button( __( 'Save security notification policy', 'dsa' ), 'secondary' ); ?></form>
		<h2><?php esc_html_e( 'System notification authorities', 'dsa' ); ?></h2><p><?php esc_html_e( 'WooCommerce, PWA, Key.kiwe and editorial events feed this preference center. Unavailable gateways remain disabled instead of silently accepting a setting that cannot deliver.', 'dsa' ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	public function save_security_policy(): void {
		if ( ! current_user_can( 'kiwe_manage_notification_policy' ) ) wp_die( esc_html__( 'Notification policy access is not available.', 'dsa' ), '', [ 'response' => 403 ] );
		check_admin_referer( 'kiwe_save_notification_policy' );
		$mode = sanitize_key( wp_unslash( $_POST['alert_delivery_policy'] ?? 'actionable' ) );
		SecureTrack_Settings_Policy::update_notification_policy( [ 'alert_on_red' => ! empty( $_POST['alert_on_red'] ), 'alert_delivery_policy' => $mode, 'alert_repeat_window_mins' => (int) ( $_POST['alert_repeat_window_mins'] ?? 15 ), 'alert_hourly_limit' => (int) ( $_POST['alert_hourly_limit'] ?? 8 ) ] );
		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-notifications', 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
