<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecureTrack_Admin_Data_Service {
	public static function menu_counts(): array {
		return [
			'open_alerts'     => self::open_alerts_count(),
			'protections_24h' => self::protections_count_24h(),
		];
	}

	public static function events_overview_counts(): array {
		global $wpdb;

		$today = current_time( 'Y-m-d' );

		return [
			'today'   => stp_table_exists( 'events' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . stp_t( 'events' ) . ' WHERE DATE(created_at)=%s', $today ) ) : 0,
			'red'     => stp_table_exists( 'events' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . stp_t( 'events' ) . ' WHERE DATE(created_at)=%s AND flag_status=%s', $today, 'red' ) ) : 0,
			'yellow'  => stp_table_exists( 'events' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . stp_t( 'events' ) . ' WHERE DATE(created_at)=%s AND flag_status=%s', $today, 'yellow' ) ) : 0,
			'blocked' => stp_table_exists( 'ips' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'ips' ) . " WHERE status='blocked'" ) : 0,
			'active'  => stp_table_exists( 'sessions' ) ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . stp_t( 'sessions' ) . ' WHERE last_activity > DATE_SUB(NOW(),INTERVAL 30 MINUTE)' ) : 0,
			'fails24' => stp_table_exists( 'events' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'events' ) . " WHERE event_type='login_failed' AND created_at > DATE_SUB(NOW(),INTERVAL 24 HOUR)" ) : 0,
			'total'   => stp_table_exists( 'events' ) ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . stp_t( 'events' ) ) : 0,
			'trusted' => stp_table_exists( 'ips' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'ips' ) . " WHERE status='trusted'" ) : 0,
		];
	}

	public static function open_alerts_count(): int {
		global $wpdb;

		return stp_table_exists( 'alerts' )
			? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . stp_t( 'alerts' ) . ' WHERE is_resolved=0' )
			: 0;
	}

	public static function protections_count_24h(): int {
		global $wpdb;

		if ( ! stp_table_exists( 'events' ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . stp_t( 'events' ) . "
			WHERE (
				event_type IN ('protection_block','waf_block','rest_abuse')
				OR event_sub IN ('author_archive_blocked','user_enumeration_blocked','honeypot_hit','attack_graph_preemptive_limit','login_country_policy','endpoint_rate_limit')
			)
			AND created_at > DATE_SUB(NOW(),INTERVAL 24 HOUR)"
		);
	}
}
