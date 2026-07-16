<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/SecureTrack_Admin_Data_Service.php';

final class SecureTrack_Admin_Service {
	public static function dsa_owns_admin(): bool {
		return defined( 'DSA_OWNS_SECURETRACK_ADMIN' ) && DSA_OWNS_SECURETRACK_ADMIN;
	}

	public static function register_core_menus(): void {
		if ( self::dsa_owns_admin() ) {
			return;
		}

		$counts = self::menu_counts();
		$alerts_label = 'Alerts' . ( $counts['open_alerts'] ? ' <span class="awaiting-mod">' . number_format_i18n( $counts['open_alerts'] ) . '</span>' : '' );
		$protections_label = 'Protections' . ( $counts['protections_24h'] ? ' <span class="stp-green-count">' . number_format_i18n( $counts['protections_24h'] ) . '</span>' : '' );

		add_submenu_page( 'kiwe', 'Kiwe Secure', 'Secure', 'manage_options', 'stp', 'stp_pg_events' );
		add_submenu_page( 'kiwe', 'Alerts', $alerts_label, 'manage_options', 'stp-alerts', 'stp_pg_alerts' );
		add_submenu_page( 'kiwe', 'Protections', $protections_label, 'manage_options', 'stp-protections', 'stp_pg_protections' );
		add_submenu_page( 'kiwe', 'Site Brain', 'Site Brain', 'manage_options', 'stp-brain', 'stp_pg_brain' );
		add_submenu_page( 'kiwe', 'IP Reputation', 'IP Reputation', 'manage_options', 'stp-ips', 'stp_pg_ips' );
		add_submenu_page( 'kiwe', 'Subnet Intel', 'Subnet Intel', 'manage_options', 'stp-subnets', 'stp_pg_subnets' );
		add_submenu_page( 'kiwe', 'Chain Link', 'Chain Link', 'manage_options', 'stp-chain', 'stp_pg_chain' );
		add_submenu_page( 'kiwe', 'File Scanner', 'File Scanner', 'manage_options', 'stp-filescan', 'stp_pg_filescan' );
		add_submenu_page( 'kiwe', 'Sessions', 'Sessions', 'manage_options', 'stp-sessions', 'stp_pg_sessions' );
		add_submenu_page( 'kiwe', 'User Profiles', 'User Profiles', 'manage_options', 'stp-profiles', 'stp_pg_profiles' );
		add_submenu_page( 'kiwe', 'Settings', 'Settings', 'manage_options', 'stp-settings', 'stp_pg_settings' );
	}

	public static function register_extended_menus(): void {
		if ( self::dsa_owns_admin() ) {
			return;
		}

		add_submenu_page( 'kiwe', 'Live Monitor', 'Live Monitor', 'manage_options', 'stp-live', 'stp_pg_live' );
		add_submenu_page( 'kiwe', 'Analytics', 'Analytics', 'manage_options', 'stp-analytics', 'stp_pg_analytics' );
		add_submenu_page( 'kiwe', 'Auth Security', 'Auth Security', 'manage_options', 'stp-auth', 'stp_pg_auth' );
	}

	public static function enqueue_core_assets( $hook ): void {
		if ( ! self::is_securetrack_admin_hook( $hook ) ) {
			return;
		}

		wp_add_inline_style( 'wp-admin', stp_admin_css() );
		wp_localize_script(
			'jquery',
			'stpCfg',
			[
				'nonce'   => wp_create_nonce( 'stp_nonce' ),
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
			]
		);
		wp_add_inline_script( 'jquery', stp_admin_js() );
	}

	public static function enqueue_extended_assets( $hook ): void {
		if ( ! self::is_securetrack_admin_hook( $hook ) ) {
			return;
		}

		wp_add_inline_style( 'wp-admin', stp_ext_css() );
		wp_add_inline_script( 'jquery', stp_ext_js() );
	}

	public static function render_menu_badge_css(): void {
		echo '<style>#adminmenu .stp-green-count{display:inline-block;vertical-align:top;box-sizing:border-box;margin:1px 0 -1px 4px;padding:0 5px;min-width:18px;height:18px;border-radius:9px;background:#10b981;color:#fff;font-size:11px;line-height:18px;text-align:center;font-weight:700}</style>';
	}

	private static function menu_counts(): array {
		return SecureTrack_Admin_Data_Service::menu_counts();
	}

	private static function is_securetrack_admin_hook( $hook ): bool {
		$hook = (string) $hook;
		return strpos( $hook, 'stp' ) !== false || strpos( $hook, 'kiwe-secure' ) !== false;
	}
}
