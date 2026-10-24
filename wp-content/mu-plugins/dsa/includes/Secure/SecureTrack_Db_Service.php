<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecureTrack_Db_Service {
	private const TABLES = [ 'ips', 'sessions', 'events', 'profiles', 'pages', 'alerts', 'subnets', 'brain', 'ai_queue', 'rate_limits' ];
	public const REPORT_TABLES = [ 'report_days', 'report_sessions', 'report_activity', 'report_state', 'lifecycle_state' ];
	private static array $table_existence = [];
	private static array $readiness = [];
	private static bool $maintenance = false;
	private const MAINTENANCE_OPTION = 'stp_schema_maintenance';

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
		if ( ! self::is_known_table( $name ) ) return false;
		if ( array_key_exists( $table, self::$table_existence ) ) return self::$table_existence[ $table ];

		self::$table_existence[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;

		return self::$table_existence[ $table ];
	}

	public static function column_exists( string $table_name, string $column ): bool {
		global $wpdb;

		$table = self::table_name( $table_name );
		if ( ! self::is_known_table( $table_name ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $column ) ) {
			return false;
		}

		return $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $wpdb->esc_like( $column ) ) ) === $column;
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
		if ( ! self::schema_write_allowed() ) return;

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
		if ( ! self::schema_write_allowed() ) return;

		if ( ! self::is_known_table( $table_name ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $index ) || ! self::safe_schema_fragment( $definition, 'index' ) ) {
			return;
		}

		$table = self::table_name( $table_name );
		if ( self::table_exists( $table_name ) && ! self::index_exists( $table_name, $index ) && ! self::equivalent_index_exists( $table_name, (string) $definition ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD {$definition}" );
		}
	}

	public static function equivalent_index_exists( string $name, string $definition ): bool {
		global $wpdb;
		if ( ! self::is_known_table( $name ) || ! self::safe_schema_fragment( $definition, 'index' ) ) return false;
		if ( ! preg_match( '/\(([a-zA-Z0-9_,\s]+)\)$/', $definition, $match ) ) return false;
		$wanted = array_map( 'trim', explode( ',', $match[1] ) );
		$groups = [];
		$invalid = [];
		foreach ( (array) $wpdb->get_results( 'SHOW INDEX FROM ' . self::table_name( $name ) ) as $row ) {
			if ( $row->Sub_part !== null || strtoupper( (string) $row->Index_type ) !== 'BTREE'
				|| ( stripos( $definition, 'UNIQUE' ) === 0 && (int) $row->Non_unique !== 0 ) ) $invalid[ $row->Key_name ] = true;
			$groups[ $row->Key_name ][ (int) $row->Seq_in_index ] = $row->Column_name;
		}
		foreach ( $groups as $key => $columns ) { ksort( $columns ); if ( empty( $invalid[ $key ] ) && array_values( $columns ) === $wanted ) return true; }
		return false;
	}

	public static function tables_ready( bool $refresh = false ): bool {
		global $wpdb;
		$key = $wpdb->prefix;
		if ( $refresh ) { self::$table_existence = []; unset( self::$readiness[ $key ] ); }
		if ( ! $refresh && array_key_exists( $key, self::$readiness ) ) return self::$readiness[ $key ];
		if ( ! $refresh && get_transient( 'stp_tables_unavailable_v' . STP_VER ) ) return self::$readiness[ $key ] = false;

		if ( ! $refresh && get_transient( 'stp_tables_ready_v' . STP_VER ) === 'yes' ) {
			return self::$readiness[ $key ] = true;
		}

		foreach ( self::TABLES as $table ) {
			if ( ! self::table_exists( $table ) ) {
				self::$readiness[ $key ] = false;
				set_transient( 'stp_tables_unavailable_v' . STP_VER, 1, 60 );
				return false;
			}
		}

		foreach ( self::required_columns() as $table => $columns ) {
			foreach ( $columns as $column ) {
				if ( ! self::column_exists( $table, $column ) ) {
					self::$readiness[ $key ] = false;
					set_transient( 'stp_tables_unavailable_v' . STP_VER, 1, 60 );
					return false;
				}
			}
		}

		set_transient( 'stp_tables_ready_v' . STP_VER, 'yes', HOUR_IN_SECONDS );
		delete_transient( 'stp_tables_unavailable_v' . STP_VER );
		self::$readiness[ $key ] = true;

		return true;
	}

	public static function repair_database(): bool {
		global $wpdb;
		if ( self::$maintenance || ! self::maintenance_authorized() ) return false;
		// Connection-scoped lock has no timeout expiry while DDL is still executing.
		$lock = 'kiwe-stp-' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 48 );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) !== '1' ) return false;
		$guard = null;
		try {
			$previous = (array) get_option( self::MAINTENANCE_OPTION, [] );
			if ( (int) ( $previous['started'] ?? 0 ) > time() - 900 ) return false;
			$attempt = [ 'status' => 'running', 'started' => time(), 'version' => STP_VER ];
			update_option( self::MAINTENANCE_OPTION, $attempt, false );
			if ( get_option( self::MAINTENANCE_OPTION ) !== $attempt || $wpdb->last_error ) return false;
			delete_transient( 'stp_tables_ready_v' . STP_VER );
			delete_transient( 'stp_tables_unavailable_v' . STP_VER );
			self::$table_existence = [];
			self::$readiness = [];
			self::$maintenance = true;
			// Stop after failed writes. dbDelta intentionally probes nonexistent tables with DESCRIBE.
			$guard = static function ( $sql ) use ( $wpdb, $lock ) {
				static $checking = false;
				static $previous_write = false;
				if ( $checking ) return $sql;
				if ( $previous_write && $wpdb->last_error ) throw new \RuntimeException( 'SecureTrack schema query failed.' );
				$previous_write = (bool) preg_match( '/^\s*(?:ALTER|CREATE|INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql );
				if ( preg_match( '/^\s*(?:ALTER|CREATE)\s+TABLE\b/i', $sql ) ) {
					$checking = true;
					try { $owns = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock ) ); }
					finally { $checking = false; }
					if ( (string) $owns !== '1' ) throw new \RuntimeException( 'SecureTrack schema lock was lost.' );
				}
				return $sql;
			};
			add_filter( 'query', $guard, PHP_INT_MAX );
			stp_create_tables();
			if ( $wpdb->last_error || ! self::tables_ready( true ) ) throw new \RuntimeException( 'SecureTrack schema verification failed.' );
			stp_schedule_crons();
			if ( ! get_option( 'stp_settings' ) ) add_option( 'stp_settings', stp_cfg() );
			stp_upgrade_alert_severity();
			update_option( 'stp_db_version', STP_VER );
			if ( $wpdb->last_error || get_option( 'stp_db_version' ) !== STP_VER ) throw new \RuntimeException( 'SecureTrack schema version was not confirmed.' );
			remove_filter( 'query', $guard, PHP_INT_MAX );
			$guard = null;
			update_option( self::MAINTENANCE_OPTION, array_merge( $attempt, [ 'status' => 'complete' ] ), false );
			return ( get_option( self::MAINTENANCE_OPTION )['status'] ?? '' ) === 'complete';
		} catch ( \Throwable $error ) {
			if ( $guard ) remove_filter( 'query', $guard, PHP_INT_MAX );
			$guard = null;
			update_option( self::MAINTENANCE_OPTION, array_merge( $attempt ?? [], [ 'status' => 'failed' ] ), false );
			delete_transient( 'stp_tables_ready_v' . STP_VER );
			self::$readiness = [];
			return false;
		} finally {
			if ( $guard ) remove_filter( 'query', $guard, PHP_INT_MAX );
			self::$maintenance = false;
			self::$table_existence = [];
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function maybe_install(): void {
		// Compatibility entry point: visitor/admin/cron bootstrap must never run schema work.
	}

	public static function install(): void {
		self::repair_database();
	}

	public static function schema_write_allowed(): bool {
		return self::$maintenance;
	}

	private static function maintenance_authorized(): bool {
		if ( class_exists( \DSA\Access\Analytics_Reporting_Setup_Service::class, false ) && \DSA\Access\Analytics_Reporting_Setup_Service::maintenance_authorized() ) return true;
		if ( defined( 'WP_CLI' ) && WP_CLI && defined( 'KIWE_SECURE_SCHEMA_MAINTENANCE' ) && KIWE_SECURE_SCHEMA_MAINTENANCE === true ) return true;
		return is_admin() && ! wp_doing_ajax() && current_user_can( 'manage_options' )
			&& ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST'
			&& ( $_POST['stp_do'] ?? '' ) === 'repairdb'
			&& is_string( $_POST['_wpnonce'] ?? null )
			&& (bool) wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'stp_do' );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'stp_cron_cleanup' );
		wp_clear_scheduled_hook( 'stp_cron_geo' );
		wp_clear_scheduled_hook( 'stp_cron_ai_queue' );
		wp_clear_scheduled_hook( 'stp_cron_ai_queue_priority' );
		wp_clear_scheduled_hook( 'stp_cron_reports' );
	}

	public static function diag( string $key, $value = null ) {
		if ( $value !== null && ! self::$maintenance ) {
			// Diagnostics are support data, never the security ledger. Ordinary requests
			// must not create option/transient rows for successful or failed telemetry.
			if ( ! defined( 'KIWE_SECURETRACK_DIAGNOSTICS' ) || KIWE_SECURETRACK_DIAGNOSTICS !== true ) return $value;
			static $seen = [];
			if ( isset( $seen[ $key ] ) ) return $value;
			$seen[ $key ] = true;
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
		return array_merge( self::TABLES, self::REPORT_TABLES );
	}

	private static function is_known_table( string $table_name ): bool {
		return in_array( $table_name, self::known_tables(), true );
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
