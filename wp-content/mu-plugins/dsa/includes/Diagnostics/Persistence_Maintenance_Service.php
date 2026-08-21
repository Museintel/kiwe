<?php

namespace DSA\Diagnostics;

use DSA\Runtime\Package_Manifest;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only persistence inventory plus narrowly scoped maintenance actions.
 *
 * It never touches WordPress/WooCommerce content, users, orders, active
 * PhoneKey credentials/factors/devices, notification consent, or current
 * Kiwe/SecureTrack/PhoneKey table schemas.
 */
final class Persistence_Maintenance_Service {
	private const TABLE_SUFFIXES = [
		'dsa' => [
			'dsa_store_events',
			'dsa_abandoned_carts',
			'dsa_abandoned_cart_reminders',
			'dsa_rate_limits',
			'dsa_push_subscriptions',
			'dsa_notification_preferences',
		],
		'stp' => [
			'stp_ips',
			'stp_sessions',
			'stp_events',
			'stp_profiles',
			'stp_pages',
			'stp_alerts',
			'stp_subnets',
			'stp_brain',
			'stp_ai_queue',
			'stp_rate_limits',
		],
		'pk' => [
			'pk_challenges',
			'pk_credentials',
			'pk_factors',
			'pk_trusted_devices',
			'pk_visits',
			'pk_activity',
		],
	];

	private const CRON_HOOKS = [
		'secure'          => [ 'stp_cron_cleanup', 'stp_cron_geo', 'stp_cron_ai_queue' ],
		'abandoned_cart'  => [ 'dsa_abandoned_cart_maintenance' ],
		'store_analytics' => [ 'dsa_store_analytics_cleanup' ],
		'linked_products' => [ 'dsa_co_purchase_daily_sync', 'dsa_bestseller_daily_sync' ],
		'push'            => [ 'dsa_push_cleanup', 'dsa_push_delivery_batch', 'dsa_admin_notification_event' ],
	];

	public function __construct( private Settings $settings ) {}

	public function report(): array {
		$tables = $this->table_inventory();
		return [
			'schema'         => 'kiwe.persistence-inventory.v1',
			'generatedAt'    => gmdate( 'c' ),
			'tables'         => $tables,
			'options'        => $this->option_inventory(),
			'meta'           => $this->meta_inventory(),
			'cron'           => $this->cron_inventory(),
			'packageFiles'   => $this->package_file_inventory(),
			'guardrails'     => [
				'databaseRowsAreNotInodes'       => true,
				'ordersUsersContentPreserved'    => true,
				'phoneKeyIdentityPreserved'      => true,
				'currentTablesNeverAutoDropped'  => true,
			],
		];
	}

	public function run_safe_maintenance(): array {
		return [
			'expiredTransients' => $this->delete_expired_transients(),
			'expiredRows'       => $this->delete_expired_operational_rows(),
			'clearedCronEvents' => $this->clear_disabled_feature_crons(),
		];
	}

	public function drop_legacy_tables(): array {
		global $wpdb;

		$dropped = [];
		$failed  = [];
		foreach ( $this->table_inventory()['legacy'] as $record ) {
			$table = (string) ( $record['name'] ?? '' );
			if ( ! $this->is_owned_table_name( $table ) || $this->is_current_table( $table ) ) {
				continue;
			}

			if ( false === $wpdb->query( "DROP TABLE `{$table}`" ) ) {
				$failed[] = $table;
			} else {
				$dropped[] = $table;
			}
		}

		return [ 'dropped' => $dropped, 'failed' => $failed ];
	}

	public function remove_unexpected_package_files(): array {
		$removed = [];
		$failed  = [];
		$root    = wp_normalize_path( DSA_DIR );

		foreach ( Package_Manifest::unexpected_files() as $record ) {
			$relative = (string) ( $record['path'] ?? '' );
			if ( ! $this->safe_relative_path( $relative ) ) {
				$failed[] = $relative;
				continue;
			}

			$path = wp_normalize_path( DSA_DIR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
			if ( ! str_starts_with( $path, trailingslashit( $root ) ) || ( ! is_file( $path ) && ! is_link( $path ) ) ) {
				$failed[] = $relative;
				continue;
			}

			if ( @unlink( $path ) ) {
				$removed[] = $relative;
			} else {
				$failed[] = $relative;
			}
		}

		$this->remove_empty_package_directories();
		Package_Manifest::clear_cached_proof();
		return [ 'removed' => $removed, 'failed' => $failed ];
	}

	private function table_inventory(): array {
		global $wpdb;

		$patterns = [
			$wpdb->esc_like( $wpdb->prefix . 'dsa_' ) . '%',
			$wpdb->esc_like( $wpdb->prefix . 'stp_' ) . '%',
			$wpdb->esc_like( $wpdb->prefix . 'pk_' ) . '%',
		];
		$sql = $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name LIKE %s OR Name LIKE %s OR Name LIKE %s', $patterns );
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
		$current = [];
		$legacy  = [];

		foreach ( $rows as $row ) {
			$name = (string) ( $row['Name'] ?? '' );
			if ( ! $this->is_owned_table_name( $name ) ) {
				continue;
			}
			$record = [
				'name'  => $name,
				'rows'  => max( 0, (int) ( $row['Rows'] ?? 0 ) ),
				'bytes' => max( 0, (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ) ),
			];
			if ( $this->is_current_table( $name ) ) {
				$current[] = $record;
			} else {
				$legacy[] = $record;
			}
		}

		return [
			'current'      => $current,
			'legacy'       => $legacy,
			'currentBytes' => array_sum( array_column( $current, 'bytes' ) ),
			'legacyBytes'  => array_sum( array_column( $legacy, 'bytes' ) ),
		];
	}

	private function option_inventory(): array {
		global $wpdb;

		$patterns = [];
		foreach ( [ 'dsa_', 'kiwe_', 'stp_', 'pk_', '_transient_dsa_', '_transient_timeout_dsa_', '_transient_kiwe_', '_transient_timeout_kiwe_', '_transient_stp_', '_transient_timeout_stp_', '_transient_pk_', '_transient_timeout_pk_' ] as $prefix ) {
			$patterns[] = $wpdb->esc_like( $prefix ) . '%';
		}
		$where = implode( ' OR ', array_fill( 0, count( $patterns ), 'option_name LIKE %s' ) );
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) AS records, COALESCE(SUM(LENGTH(option_value)),0) AS bytes, COALESCE(SUM(CASE WHEN autoload IN ('yes','on','auto-on','auto') THEN LENGTH(option_value) ELSE 0 END),0) AS autoload_bytes FROM {$wpdb->options} WHERE {$where}",
			$patterns
		);
		$row = (array) $wpdb->get_row( $sql, ARRAY_A );
		return [
			'records'       => max( 0, (int) ( $row['records'] ?? 0 ) ),
			'bytes'         => max( 0, (int) ( $row['bytes'] ?? 0 ) ),
			'autoloadBytes' => max( 0, (int) ( $row['autoload_bytes'] ?? 0 ) ),
		];
	}

	private function meta_inventory(): array {
		global $wpdb;

		$out = [];
		foreach ( [ 'post' => [ $wpdb->postmeta, 'meta_key', 'meta_value' ], 'user' => [ $wpdb->usermeta, 'meta_key', 'meta_value' ] ] as $kind => $definition ) {
			[ $table, $key, $value ] = $definition;
			$patterns = array_map( static fn( string $prefix ): string => $wpdb->esc_like( $prefix ) . '%', [ 'dsa_', 'kiwe_', 'stp_', 'pk_' ] );
			$sql = $wpdb->prepare( "SELECT COUNT(*) AS records, COALESCE(SUM(LENGTH({$value})),0) AS bytes FROM {$table} WHERE {$key} LIKE %s OR {$key} LIKE %s OR {$key} LIKE %s OR {$key} LIKE %s", $patterns );
			$row = (array) $wpdb->get_row( $sql, ARRAY_A );
			$out[ $kind ] = [ 'records' => max( 0, (int) ( $row['records'] ?? 0 ) ), 'bytes' => max( 0, (int) ( $row['bytes'] ?? 0 ) ) ];
		}
		return $out;
	}

	private function cron_inventory(): array {
		$cron = _get_cron_array();
		$counts = [];
		foreach ( is_array( $cron ) ? $cron : [] as $hooks ) {
			foreach ( array_keys( is_array( $hooks ) ? $hooks : [] ) as $hook ) {
				if ( preg_match( '/^(?:dsa|kiwe|stp|pk)_/', (string) $hook ) ) {
					$counts[ $hook ] = ( $counts[ $hook ] ?? 0 ) + 1;
				}
			}
		}
		ksort( $counts );
		return [ 'events' => array_sum( $counts ), 'hooks' => $counts ];
	}

	private function package_file_inventory(): array {
		$unexpected = Package_Manifest::unexpected_files();
		$residue    = $this->mu_plugin_residue_inventory();
		return [
			'unexpected'     => $unexpected,
			'count'          => count( $unexpected ),
			'bytes'          => array_sum( array_column( $unexpected, 'bytes' ) ),
			'muPluginResidue' => $residue,
		];
	}

	/**
	 * Report possible old top-level MU-plugin copies without deleting them.
	 * A filename match is not sufficient ownership proof, so these remain a
	 * manual hosting/file-manager decision.
	 */
	private function mu_plugin_residue_inventory(): array {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ! is_dir( WPMU_PLUGIN_DIR ) ) {
			return [ 'entries' => [], 'inodes' => 0, 'bytes' => 0 ];
		}

		$entries = [];
		$inodes = 0;
		$bytes = 0;
		$root = new \DirectoryIterator( WPMU_PLUGIN_DIR );
		foreach ( $root as $entry ) {
			if ( $entry->isDot() ) {
				continue;
			}

			$name = $entry->getFilename();
			if ( in_array( $name, [ 'dsa', 'dsa.php' ], true ) || ! preg_match( '/(?:dsa|kiwe|securetrack|phonekey)/i', $name ) ) {
				continue;
			}

			$entry_inodes = 0;
			$entry_bytes  = 0;
			if ( $entry->isDir() && ! $entry->isLink() ) {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $entry->getPathname(), \FilesystemIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::SELF_FIRST
				);
				foreach ( $iterator as $child ) {
					++$entry_inodes;
					if ( $child->isFile() && ! $child->isLink() ) {
						$entry_bytes += max( 0, (int) $child->getSize() );
					}
				}
			} else {
				$entry_inodes = 1;
				$entry_bytes  = $entry->isFile() && ! $entry->isLink() ? max( 0, (int) $entry->getSize() ) : 0;
			}

			$entries[] = [ 'name' => $name, 'inodes' => $entry_inodes, 'bytes' => $entry_bytes ];
			$inodes += $entry_inodes;
			$bytes += $entry_bytes;
		}

		return [ 'entries' => $entries, 'inodes' => $inodes, 'bytes' => $bytes ];
	}

	private function delete_expired_transients(): int {
		global $wpdb;

		$prefixes = [
			'_transient_timeout_dsa_', '_transient_timeout_kiwe_', '_transient_timeout_stp_', '_transient_timeout_pk_',
		];
		$patterns = array_map( static fn( string $prefix ): string => $wpdb->esc_like( $prefix ) . '%', $prefixes );
		$where = implode( ' OR ', array_fill( 0, count( $patterns ), 'option_name LIKE %s' ) );
		$patterns[] = time();
		$sql = $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE ({$where}) AND CAST(option_value AS UNSIGNED) < %d LIMIT 5000", $patterns );
		$names = (array) $wpdb->get_col( $sql );
		$deleted = 0;
		foreach ( $names as $timeout_name ) {
			$timeout_name = (string) $timeout_name;
			$value_name = str_replace( '_transient_timeout_', '_transient_', $timeout_name );
			$deleted += delete_option( $timeout_name ) ? 1 : 0;
			$deleted += delete_option( $value_name ) ? 1 : 0;
		}
		return $deleted;
	}

	private function delete_expired_operational_rows(): array {
		global $wpdb;

		$queries = [
			$wpdb->prefix . 'dsa_rate_limits' => $wpdb->prepare( 'DELETE FROM `' . $wpdb->prefix . 'dsa_rate_limits` WHERE expires_at < %d LIMIT 5000', time() ),
			$wpdb->prefix . 'stp_rate_limits' => $wpdb->prepare( 'DELETE FROM `' . $wpdb->prefix . 'stp_rate_limits` WHERE expires_at < %d LIMIT 5000', time() ),
			$wpdb->prefix . 'pk_challenges' => $wpdb->prepare( 'DELETE FROM `' . $wpdb->prefix . 'pk_challenges` WHERE expires_at < %s OR used = 1 LIMIT 5000', current_time( 'mysql' ) ),
			$wpdb->prefix . 'pk_trusted_devices' => $wpdb->prepare( 'DELETE FROM `' . $wpdb->prefix . 'pk_trusted_devices` WHERE expires_at < %s LIMIT 5000', current_time( 'mysql' ) ),
		];
		$deleted = [];
		foreach ( $queries as $table => $sql ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			$count = $wpdb->query( $sql );
			$deleted[ $table ] = is_int( $count ) ? max( 0, $count ) : 0;
		}
		return $deleted;
	}

	private function clear_disabled_feature_crons(): array {
		$settings = $this->settings->all();
		$disabled = [
			'secure'          => empty( $settings['secure']['enabled'] ),
			'abandoned_cart'  => empty( $settings['abandoned_cart']['enabled'] ),
			'store_analytics' => empty( $settings['metrics']['enabled'] ),
			'linked_products' => empty( $settings['commerce']['linked_products_enabled'] ),
			'push'            => empty( $settings['permissions']['enabled'] ) || empty( $settings['permissions']['notifications_enabled'] ),
		];
		$cleared = [];
		foreach ( $disabled as $family => $is_disabled ) {
			if ( ! $is_disabled ) {
				continue;
			}
			foreach ( self::CRON_HOOKS[ $family ] as $hook ) {
				$count = wp_clear_scheduled_hook( $hook );
				if ( is_int( $count ) && $count > 0 ) {
					$cleared[ $hook ] = $count;
				}
			}
		}
		return $cleared;
	}

	private function current_table_names(): array {
		global $wpdb;
		$names = [];
		foreach ( self::TABLE_SUFFIXES as $suffixes ) {
			foreach ( $suffixes as $suffix ) {
				$names[] = $wpdb->prefix . $suffix;
			}
		}
		return $names;
	}

	private function is_current_table( string $table ): bool {
		return in_array( $table, $this->current_table_names(), true );
	}

	private function is_owned_table_name( string $table ): bool {
		global $wpdb;
		return (bool) preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . '(?:dsa|stp|pk)_[A-Za-z0-9_]+$/', $table );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $this->is_owned_table_name( $table ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	private function safe_relative_path( string $relative ): bool {
		return '' !== $relative
			&& ! str_contains( $relative, '..' )
			&& ! str_starts_with( $relative, '/' )
			&& ! preg_match( '/[\x00-\x1F]/', $relative );
	}

	private function remove_empty_package_directories(): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( DSA_DIR, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				@rmdir( $entry->getPathname() );
			}
		}
	}
}
