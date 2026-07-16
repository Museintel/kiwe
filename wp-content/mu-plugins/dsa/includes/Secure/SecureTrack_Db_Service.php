<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecureTrack_Db_Service {
	private const TABLES = [ 'ips', 'sessions', 'events', 'profiles', 'pages', 'alerts', 'subnets', 'brain', 'ai_queue', 'rate_limits' ];

	public static function table_name( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'stp_' . $name;
	}

	public static function safe_table_name( $table ): string {
		global $wpdb;

		$table = (string) $table;
		$prefix = $wpdb->prefix . 'stp_';

		return ( strpos( $table, $prefix ) === 0 && preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) ? $table : '';
	}

	public static function table_exists( string $name ): bool {
		global $wpdb;

		$table = self::table_name( $name );

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	public static function column_exists( string $table_name, string $column ): bool {
		global $wpdb;

		$table = self::table_name( $table_name );
		if ( ! self::is_known_table( $table_name ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $column ) ) {
			return false;
		}

		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
	}

	public static function index_exists( string $table_name, string $index ): bool {
		global $wpdb;

		$table = self::table_name( $table_name );
		if ( ! self::is_known_table( $table_name ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $index ) ) {
			return false;
		}

		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name=%s", $index ) );
	}

	public static function safe_schema_fragment( $sql, string $kind = 'column' ) {
		$sql = trim( (string) $sql );
		if ( $sql === '' || preg_match( '/;|--|\/\*|\*\/|#|\b(?:drop|truncate|delete|insert|update|replace|create)\b/i', $sql ) ) {
			return false;
		}
		if ( ! preg_match( "/^[a-zA-Z0-9_(),'\s.-]+$/", $sql ) ) {
			return false;
		}
		if ( $kind === 'index' && ! preg_match( '/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+[a-zA-Z0-9_]+\s*\([a-zA-Z0-9_,\s()]+\)$/i', $sql ) ) {
			return false;
		}

		return true;
	}

	public static function ensure_column( string $table_name, string $column, $definition ): void {
		global $wpdb;

		if ( ! self::is_known_table( $table_name ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $column ) || ! self::safe_schema_fragment( $definition, 'column' ) ) {
			return;
		}

		$table = self::table_name( $table_name );
		if ( self::table_exists( $table_name ) && ! self::column_exists( $table_name, $column ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
		}
	}

	public static function ensure_index( string $table_name, string $index, $definition ): void {
		global $wpdb;

		if ( ! self::is_known_table( $table_name ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $index ) || ! self::safe_schema_fragment( $definition, 'index' ) ) {
			return;
		}

		$table = self::table_name( $table_name );
		if ( self::table_exists( $table_name ) && ! self::index_exists( $table_name, $index ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD {$definition}" );
		}
	}

	public static function tables_ready( bool $refresh = false ): bool {
		static $ready = null;

		if ( ! $refresh && $ready !== null ) {
			return $ready;
		}

		if ( ! $refresh && get_transient( 'stp_tables_ready_v' . STP_VER ) === 'yes' ) {
			$ready = true;
			return true;
		}

		foreach ( self::TABLES as $table ) {
			if ( ! self::table_exists( $table ) ) {
				$ready = false;
				return false;
			}
		}

		foreach ( self::required_columns() as $table => $columns ) {
			foreach ( $columns as $column ) {
				if ( ! self::column_exists( $table, $column ) ) {
					$ready = false;
					return false;
				}
			}
		}

		set_transient( 'stp_tables_ready_v' . STP_VER, 'yes', HOUR_IN_SECONDS );
		$ready = true;

		return true;
	}

	public static function repair_database(): bool {
		delete_transient( 'stp_tables_ready_v' . STP_VER );
		stp_create_tables();
		stp_migrate_schema();
		stp_schedule_crons();
		update_option( 'stp_db_version', STP_VER );
		self::diag( 'last_db_error', '' );

		return self::tables_ready( true );
	}

	public static function maybe_install(): void {
		if ( get_option( 'stp_db_version' ) !== STP_VER || ! self::tables_ready() ) {
			if ( get_transient( 'stp_install_lock' ) ) {
				return;
			}

			set_transient( 'stp_install_lock', 1, 60 );
			try {
				stp_install();
			} finally {
				delete_transient( 'stp_install_lock' );
			}
		}

		stp_upgrade_alert_severity();
	}

	public static function install(): void {
		stp_create_tables();
		stp_schedule_crons();
		if ( ! get_option( 'stp_settings' ) ) {
			add_option( 'stp_settings', stp_cfg() );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'stp_cron_cleanup' );
		wp_clear_scheduled_hook( 'stp_cron_geo' );
		wp_clear_scheduled_hook( 'stp_cron_ai_queue' );
	}

	public static function diag( string $key, $value = null ) {
		$immediate = in_array( $key, [ 'last_db_error', 'last_failed_event' ], true );
		if ( $value !== null && ! $immediate ) {
			$transient_key = 'stp_diag_' . md5( $key );
			if ( get_transient( $transient_key ) ) {
				return $value;
			}
			set_transient( $transient_key, 1, 60 );
		}

		$diag = (array) get_option( 'stp_diag', [] );
		if ( $value === null ) {
			return $diag[ $key ] ?? null;
		}

		$diag[ $key ] = is_scalar( $value ) ? $value : wp_json_encode( $value );
		$diag['updated_at'] = current_time( 'mysql' );
		update_option( 'stp_diag', $diag, false );

		return $value;
	}

	public static function known_tables(): array {
		return self::TABLES;
	}

	private static function is_known_table( string $table_name ): bool {
		return in_array( $table_name, self::TABLES, true );
	}

	private static function required_columns(): array {
		return [
			'sessions'    => [ 'session_token', 'ip_id', 'started_at', 'last_activity' ],
			'events'      => [ 'ip_id', 'event_type', 'risk_score', 'created_at', 'hash_current' ],
			'pages'       => [ 'session_id', 'url', 'visited_at' ],
			'ips'         => [ 'ip_address', 'risk_score', 'last_seen' ],
			'alerts'      => [ 'alert_time', 'chain_type', 'severity', 'title', 'is_resolved' ],
			'subnets'     => [ 'subnet', 'threat_level', 'threat_score', 'is_banned' ],
			'brain'       => [ 'feature_key', 'feature_type', 'good_count', 'risk_count', 'confidence' ],
			'ai_queue'    => [ 'event_id', 'provider', 'local_score', 'status', 'compact_context' ],
			'rate_limits' => [ 'bucket_hash', 'bucket_id', 'counter', 'window_start', 'expires_at' ],
		];
	}
}
