<?php

namespace DSA\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only, whole-site database inventory.
 *
 * Ownership is evidence, not permission. Heuristic matches are deliberately
 * never exposed as automatic deletion targets.
 */
final class Database_Inventory_Service {
	public function report(): array {
		$tables = $this->tables();
		return [
			'schema'            => 'kiwe.database-inventory.v1',
			'generatedAt'       => gmdate( 'c' ),
			'tables'            => $tables,
			'totalBytes'        => array_sum( array_column( $tables, 'bytes' ) ),
			'ownershipSummary'  => $this->ownership_summary( $tables ),
			'cleanupCandidates' => $this->cleanup_candidates(),
			'autoload'          => $this->autoload_inventory(),
			'guardrails'        => [
				'heuristicOwnershipNeverDeletes' => true,
				'unknownTablesNeverDelete'       => true,
				'coreCommerceIdentityProtected'  => true,
			],
		];
	}

	private function tables(): array {
		global $wpdb;

		$installed = $this->installed_plugins();
		$rows = (array) $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		$tables = [];
		foreach ( $rows as $row ) {
			$name = (string) ( $row['Name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			$owner = $this->classify_owner( $name, $installed );
			$tables[] = [
				'name'       => $name,
				'rows'       => max( 0, (int) ( $row['Rows'] ?? 0 ) ),
				'bytes'      => max( 0, (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ) ),
				'engine'     => sanitize_key( (string) ( $row['Engine'] ?? '' ) ),
				'owner'      => $owner['owner'],
				'ownerState' => $owner['state'],
				'evidence'   => $owner['evidence'],
				'confidence' => $owner['confidence'],
			];
		}

		usort( $tables, static fn( array $a, array $b ): int => $b['bytes'] <=> $a['bytes'] );
		return $tables;
	}

	private function classify_owner( string $table, array $installed ): array {
		global $wpdb;

		$core_tables = array_values( array_unique( array_filter( [
			$wpdb->posts, $wpdb->postmeta, $wpdb->comments, $wpdb->commentmeta,
			$wpdb->terms, $wpdb->termmeta, $wpdb->term_relationships,
			$wpdb->term_taxonomy, $wpdb->options, $wpdb->users, $wpdb->usermeta,
			$wpdb->links,
		] ) ) );
		if ( in_array( $table, $core_tables, true ) ) {
			return [ 'owner' => 'WordPress Core', 'state' => 'active', 'evidence' => 'exact-core-table', 'confidence' => 'proven' ];
		}

		$suffix = str_starts_with( $table, $wpdb->prefix ) ? substr( $table, strlen( $wpdb->prefix ) ) : $table;
		if ( preg_match( '/^(?:dsa|stp|pk)_/', $suffix ) ) {
			return [ 'owner' => 'Kiwe', 'state' => 'active', 'evidence' => 'registered-kiwe-prefix', 'confidence' => 'proven' ];
		}

		if ( preg_match( '/^(?:wc_|woocommerce_|actionscheduler_)/', $suffix ) ) {
			$active = ! empty( $installed['woocommerce']['active'] );
			return [ 'owner' => 'WooCommerce', 'state' => $active ? 'active' : 'inactive', 'evidence' => 'registered-woocommerce-prefix', 'confidence' => 'strong' ];
		}

		$table_token = sanitize_key( strtok( $suffix, '_' ) ?: '' );
		foreach ( $installed as $record ) {
			if ( '' !== $table_token && in_array( $table_token, $record['tokens'], true ) ) {
				return [
					'owner'      => $record['name'],
					'state'      => $record['active'] ? 'active' : 'inactive',
					'evidence'   => 'plugin-slug-prefix-heuristic',
					'confidence' => 'heuristic',
				];
			}
		}

		return [ 'owner' => 'Unknown', 'state' => 'unknown', 'evidence' => 'no-owner-evidence', 'confidence' => 'unknown' ];
	}

	private function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : [];
		$active  = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active = array_values( array_unique( array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ) ) );
		}
		$out = [];
		foreach ( $plugins as $file => $headers ) {
			$slug = sanitize_key( dirname( (string) $file ) );
			if ( '.' === $slug ) {
				$slug = sanitize_key( pathinfo( (string) $file, PATHINFO_FILENAME ) );
			}
			$tokens = array_values( array_unique( array_filter( preg_split( '/[-_]+/', $slug ) ) ) );
			$out[ $slug ] = [
				'name'   => sanitize_text_field( (string) ( $headers['Name'] ?? $slug ) ),
				'active' => in_array( $file, $active, true ),
				'tokens' => $tokens,
			];
		}

		$out['woocommerce'] = $out['woocommerce'] ?? [ 'name' => 'WooCommerce', 'active' => in_array( 'woocommerce/woocommerce.php', $active, true ), 'tokens' => [ 'woocommerce', 'wc' ] ];
		return $out;
	}

	private function ownership_summary( array $tables ): array {
		$summary = [];
		foreach ( $tables as $table ) {
			$key = sanitize_key( $table['ownerState'] );
			$summary[ $key ] = $summary[ $key ] ?? [ 'tables' => 0, 'bytes' => 0 ];
			++$summary[ $key ]['tables'];
			$summary[ $key ]['bytes'] += (int) $table['bytes'];
		}
		return $summary;
	}

	private function cleanup_candidates(): array {
		global $wpdb;

		$queries = [
			'revisions'      => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'",
			'autoDrafts'     => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'",
			'trashedPosts'   => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'",
			'spamComments'   => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'",
			'trashComments'  => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'",
			'orphanPostMeta' => "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL",
		];
		$out = [];
		foreach ( $queries as $key => $sql ) {
			$out[ $key ] = max( 0, (int) $wpdb->get_var( $sql ) );
		}
		$out['expiredTransients'] = $this->expired_transient_count();
		return $out;
	}

	private function expired_transient_count(): int {
		global $wpdb;
		$now = time();
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
			$now
		);
		return max( 0, (int) $wpdb->get_var( $sql ) );
	}

	private function autoload_inventory(): array {
		global $wpdb;
		$row = (array) $wpdb->get_row(
			"SELECT COUNT(*) AS records, COALESCE(SUM(LENGTH(option_value)),0) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')",
			ARRAY_A
		);
		return [
			'records' => max( 0, (int) ( $row['records'] ?? 0 ) ),
			'bytes'   => max( 0, (int) ( $row['bytes'] ?? 0 ) ),
			'budget'  => 800 * 1024,
			'status'  => (int) ( $row['bytes'] ?? 0 ) > 800 * 1024 ? 'review' : 'healthy',
		];
	}
}
