<?php

namespace DSA\Utilities;

use DSA\Diagnostics\Runtime_Profiler;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Atomic_Rate_Limiter {
	private const DB_VERSION = '1';
	private const DB_OPTION = 'dsa_rate_limit_db_version';
	private const CRON_HOOK = 'dsa_rate_limit_cleanup';
	private const CACHE_GROUP = 'dsa_rate_limits';
	private static bool $installed = false;

	public static function register(): void {
		add_action( 'init', [ self::class, 'maybe_install' ], 8 );
		add_action( 'init', [ self::class, 'maybe_schedule' ], 20 );
		add_action( self::CRON_HOOK, [ self::class, 'cleanup' ] );
	}

	public static function maybe_install(): void {
		if ( self::$installed || get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			self::$installed = true;
			return;
		}

		global $wpdb;
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			bucket_hash char(64) NOT NULL,
			counter bigint(20) unsigned NOT NULL DEFAULT 0,
			window_start bigint(20) unsigned NOT NULL DEFAULT 0,
			expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY (bucket_hash),
			KEY expires_at (expires_at)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		if ( self::table_exists() ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
			self::$installed = true;
		}
	}

	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
	}

	public static function allow( string $bucket, int $limit, int $window_seconds = 60 ): bool {
		$limit = max( 1, $limit );
		$window_seconds = max( 1, min( DAY_IN_SECONDS, $window_seconds ) );
		$hash = hash_hmac( 'sha256', substr( $bucket, 0, 500 ), wp_salt( 'nonce' ) );
		$profile = Runtime_Profiler::start();

		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$key = 'bucket_' . $hash;
			wp_cache_add( $key, 0, self::CACHE_GROUP, $window_seconds );
			$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );
			Runtime_Profiler::finish( 'rate_limit.object_cache', $profile, false !== $count );
			return false === $count || (int) $count <= $limit;
		}

		if ( ! self::$installed ) self::maybe_install();
		if ( ! self::$installed ) {
			Runtime_Profiler::finish( 'rate_limit.storage_unavailable', $profile, false );
			return true;
		}

		global $wpdb;
		$table = self::table();
		$now = time();
		$reset_before = $now - $window_seconds;
		$expires = $now + $window_seconds + 60;
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (bucket_hash,counter,window_start,expires_at,updated_at)
			 VALUES (%s,1,%d,%d,%s)
			 ON DUPLICATE KEY UPDATE
			 counter=IF(window_start<=%d,1,counter+1),
			 window_start=IF(window_start<=%d,VALUES(window_start),window_start),
			 expires_at=VALUES(expires_at),updated_at=VALUES(updated_at)",
			$hash, $now, $expires, current_time( 'mysql' ), $reset_before, $reset_before
		);
		$written = false !== $wpdb->query( $sql );
		$count = $written ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT counter FROM {$table} WHERE bucket_hash=%s", $hash ) ) : 0;
		Runtime_Profiler::finish( 'rate_limit.sql', $profile, $written );
		return ! $written || $count <= $limit;
	}

	public static function cleanup(): void {
		global $wpdb;
		if ( ! self::table_exists() ) return;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE expires_at < %d LIMIT 5000', time() ) );
	}

	public static function diagnostics(): array {
		$persistent = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		return [
			'backend' => $persistent ? 'persistent-object-cache' : 'atomic-sql',
			'tableReady' => $persistent || self::table_exists(),
			'cleanupScheduled' => (bool) wp_next_scheduled( self::CRON_HOOK ),
		];
	}

	private static function table_exists(): bool {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsa_rate_limits';
	}
}
