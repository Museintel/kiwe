<?php

namespace DSA\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cache_Maintenance_Service {
	private const SCOPES = [ 'all', 'desktop', 'tablet', 'mobile' ];

	public function report(): array {
		$adapters = $this->page_cache_adapters();
		return [
			'schema'       => 'kiwe.cache-capabilities.v1',
			'objectCache'  => [
				'persistent'    => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
				'dropIn'        => defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/object-cache.php' ),
				'flushAvailable'=> function_exists( 'wp_cache_flush' ),
				'scopes'        => [ 'all' ],
			],
			'pageCache'    => [
				'wpCacheFlag' => defined( 'WP_CACHE' ) && WP_CACHE,
				'dropIn'      => defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/advanced-cache.php' ),
				'adapters'    => array_map( [ $this, 'public_adapter_record' ], $adapters ),
				'scopes'      => array_values( array_unique( array_merge( ...array_map( static fn( array $adapter ): array => $adapter['scopes'], $adapters ) ) ) ),
			],
			'kiweRuntime' => [
				'epoch'  => (int) get_option( 'dsa_runtime_cache_epoch', 0 ),
				'scopes' => [ 'all' ],
			],
			'notes' => [
				'deviceScopeRequiresAdapter' => true,
				'remoteBrowserCachesCannotBeDirectlyDeleted' => true,
			],
		];
	}

	public function purge( string $scope, array $layers ): array {
		$scope = in_array( $scope, self::SCOPES, true ) ? $scope : 'all';
		$layers = array_values( array_intersect( [ 'kiwe_runtime', 'expired_transients', 'object_cache', 'page_cache' ], array_map( 'sanitize_key', $layers ) ) );
		$result = [ 'scope' => $scope, 'completed' => [], 'skipped' => [], 'failed' => [] ];

		foreach ( $layers as $layer ) {
			if ( 'page_cache' === $layer ) {
				$this->purge_page_cache( $scope, $result );
				continue;
			}
			if ( 'all' !== $scope ) {
				$result['skipped'][ $layer ] = 'This native WordPress layer has no safe device-specific namespace.';
				continue;
			}

			if ( 'kiwe_runtime' === $layer ) {
				$result['completed'][ $layer ] = $this->clear_kiwe_runtime();
			} elseif ( 'expired_transients' === $layer ) {
				$result['completed'][ $layer ] = $this->delete_expired_transients();
			} elseif ( 'object_cache' === $layer ) {
				$result['completed'][ $layer ] = function_exists( 'wp_cache_flush' ) && wp_cache_flush();
			}
		}

		return $result;
	}

	private function page_cache_adapters(): array {
		$adapters = [];
		if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
			$adapters[] = [
				'id'     => 'litespeed',
				'label'  => 'LiteSpeed Cache',
				'scopes' => [ 'all' ],
				'purge'  => static function (): bool {
					do_action( 'litespeed_purge_all' );
					return true;
				},
			];
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$adapters[] = [
				'id'     => 'wp-rocket',
				'label'  => 'WP Rocket',
				'scopes' => [ 'all' ],
				'purge'  => static function (): bool {
					rocket_clean_domain();
					return true;
				},
			];
		}
		$adapters = apply_filters( 'dsa_database_cache_adapters', $adapters );
		$out = [];
		foreach ( is_array( $adapters ) ? $adapters : [] as $adapter ) {
			if ( ! is_array( $adapter ) || empty( $adapter['id'] ) || empty( $adapter['label'] ) || ! is_callable( $adapter['purge'] ?? null ) ) {
				continue;
			}
			$scopes = array_values( array_intersect( self::SCOPES, array_map( 'sanitize_key', (array) ( $adapter['scopes'] ?? [ 'all' ] ) ) ) );
			$out[] = [
				'id'     => sanitize_key( $adapter['id'] ),
				'label'  => sanitize_text_field( $adapter['label'] ),
				'scopes' => $scopes ?: [ 'all' ],
				'purge'  => $adapter['purge'],
			];
		}
		return $out;
	}

	private function public_adapter_record( array $adapter ): array {
		return [ 'id' => $adapter['id'], 'label' => $adapter['label'], 'scopes' => $adapter['scopes'] ];
	}

	private function purge_page_cache( string $scope, array &$result ): void {
		$matched = false;
		foreach ( $this->page_cache_adapters() as $adapter ) {
			if ( ! in_array( $scope, $adapter['scopes'], true ) ) {
				continue;
			}
			$matched = true;
			try {
				$result['completed'][ 'page_cache:' . $adapter['id'] ] = (bool) call_user_func( $adapter['purge'], $scope );
			} catch ( \Throwable $error ) {
				$result['failed'][ 'page_cache:' . $adapter['id'] ] = $error->getMessage();
			}
		}
		if ( ! $matched ) {
			$result['skipped']['page_cache'] = 'No evidence-backed page-cache adapter supports this scope.';
		}
	}

	private function clear_kiwe_runtime(): int {
		global $wpdb;

		$deleted = 0;
		foreach ( [ '_transient_dsa_', '_transient_timeout_dsa_', '_transient_kiwe_', '_transient_timeout_kiwe_' ] as $prefix ) {
			$count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
			$deleted += is_int( $count ) ? max( 0, $count ) : 0;
		}
		update_option( 'dsa_runtime_cache_epoch', time(), false );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $deleted;
	}

	private function delete_expired_transients(): int {
		global $wpdb;
		$before = $this->expired_transient_count();
		if ( function_exists( 'delete_expired_transients' ) ) {
			delete_expired_transients( true );
		}
		return max( 0, $before - $this->expired_transient_count() );
	}

	private function expired_transient_count(): int {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
			time()
		);
		return max( 0, (int) $wpdb->get_var( $sql ) );
	}
}
