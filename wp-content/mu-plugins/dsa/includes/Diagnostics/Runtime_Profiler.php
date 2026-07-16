<?php

namespace DSA\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Runtime_Profiler {
	private static array $samples = [];
	private static array $marks = [];
	private static bool $shutdown_registered = false;
	private static ?bool $configured_enabled = null;
	private static int $request_started = 0;
	private static int $request_query_count = 0;

	public static function configure( bool $enabled ): void {
		$constant_enabled = ( defined( 'DSA_PROFILE_RUNTIME' ) && true === DSA_PROFILE_RUNTIME )
			|| ( defined( 'DSA_PROFILE_CACHE' ) && true === DSA_PROFILE_CACHE );
		self::$configured_enabled = $enabled || $constant_enabled;

		if ( self::$configured_enabled ) {
			self::$request_started = self::$request_started ?: hrtime( true );
			self::$request_query_count = function_exists( 'get_num_queries' ) ? get_num_queries() : 0;
			self::register_shutdown();
		}
	}

	public static function start( string $operation = '' ): int {
		if ( ! self::enabled() ) {
			return 0;
		}

		self::$request_started = self::$request_started ?: hrtime( true );
		self::register_shutdown();

		return hrtime( true );
	}

	public static function finish( string $operation, int $started, ?bool $hit = null ): void {
		if ( 0 === $started || ! self::enabled() ) {
			return;
		}

		$operation = sanitize_key( str_replace( '.', '_', $operation ) );
		$elapsed   = max( 0, hrtime( true ) - $started ) / 1000000;
		$sample    = self::$samples[ $operation ] ?? [
			'count'    => 0,
			'hits'     => 0,
			'misses'   => 0,
			'total_ms' => 0.0,
			'max_ms'   => 0.0,
		];

		$sample['count']++;
		$sample['total_ms'] += $elapsed;
		$sample['max_ms'] = max( $sample['max_ms'], $elapsed );

		if ( true === $hit ) {
			$sample['hits']++;
		} elseif ( false === $hit ) {
			$sample['misses']++;
		}

		self::$samples[ $operation ] = $sample;
	}

	public static function mark( string $name, array $context = [] ): void {
		if ( ! self::enabled() ) {
			return;
		}

		self::$request_started = self::$request_started ?: hrtime( true );
		self::register_shutdown();

		$clean = [];
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
				$clean[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			}
		}

		self::$marks[] = [
			'name'       => sanitize_key( str_replace( '.', '_', $name ) ),
			'at_ms'      => self::$request_started > 0 ? round( max( 0, hrtime( true ) - self::$request_started ) / 1000000, 3 ) : 0.0,
			'attributes' => $clean,
		];
	}

	public static function flush(): void {
		if ( ! self::enabled() || ( [] === self::$samples && [] === self::$marks ) ) {
			return;
		}

		foreach ( self::$samples as &$sample ) {
			$sample['total_ms'] = round( $sample['total_ms'], 3 );
			$sample['max_ms']   = round( $sample['max_ms'], 3 );
			$sample['avg_ms']   = $sample['count'] > 0 ? round( $sample['total_ms'] / $sample['count'], 3 ) : 0.0;
		}
		unset( $sample );

		$context = [
			'profile' => self::profile_mode(),
			'cache'   => self::cache_summary(),
			'request' => self::request_summary(),
			'queries' => self::query_summary(),
			'marks'   => self::$marks,
			'samples' => self::$samples,
		];

		if ( function_exists( 'kiwe_mu_debug_log' ) ) {
			\kiwe_mu_debug_log( 'Runtime performance profile', $context );
			return;
		}

		error_log( '[Kiwe profile] ' . wp_json_encode( $context ) );
	}

	private static function profile_mode(): string {
		if ( defined( 'DSA_PROFILE_CACHE' ) && true === DSA_PROFILE_CACHE
			&& ( ! defined( 'DSA_PROFILE_RUNTIME' ) || true !== DSA_PROFILE_RUNTIME ) ) {
			return 'cache-comparison';
		}

		return 'runtime-and-cache';
	}

	private static function cache_summary(): array {
		$persistent = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		$dropin     = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/object-cache.php' : '';

		return [
			'backend'          => $persistent ? 'persistent-object-cache' : 'database-options-transients',
			'persistent'       => $persistent,
			'wp_cache_enabled' => defined( 'WP_CACHE' ) && WP_CACHE,
			'dropin_present'   => '' !== $dropin && file_exists( $dropin ),
			'dropin_name'      => '' !== $dropin && file_exists( $dropin ) ? basename( $dropin ) : '',
		];
	}

	private static function enabled(): bool {
		if ( null !== self::$configured_enabled ) {
			return self::$configured_enabled;
		}

		if ( defined( 'DSA_PROFILE_RUNTIME' ) && true === DSA_PROFILE_RUNTIME ) {
			return true;
		}

		return defined( 'DSA_PROFILE_CACHE' ) && true === DSA_PROFILE_CACHE;
	}

	private static function register_shutdown(): void {
		if ( self::$shutdown_registered ) {
			return;
		}

		self::$shutdown_registered = true;
		register_shutdown_function( [ self::class, 'flush' ] );
	}

	private static function request_summary(): array {
		$path = '';

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		}

		return [
			'method'      => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			'path'        => $path ? sanitize_text_field( $path ) : '',
			'is_admin'    => is_admin(),
			'is_rest'     => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'duration_ms' => self::$request_started > 0 ? round( max( 0, hrtime( true ) - self::$request_started ) / 1000000, 3 ) : 0.0,
			'peak_memory_mb' => round( memory_get_peak_usage( true ) / 1048576, 3 ),
		];
	}

	private static function query_summary(): array {
		global $wpdb;

		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES || ! isset( $wpdb->queries ) || ! is_array( $wpdb->queries ) ) {
			return [
				'enabled' => false,
				'count'   => function_exists( 'get_num_queries' ) ? max( 0, get_num_queries() - self::$request_query_count ) : 0,
			];
		}

		$total = 0.0;
		foreach ( $wpdb->queries as $query ) {
			if ( isset( $query[1] ) && is_numeric( $query[1] ) ) {
				$total += (float) $query[1];
			}
		}

		return [
			'enabled'  => true,
			'count'    => count( $wpdb->queries ),
			'total_ms' => round( $total * 1000, 3 ),
		];
	}
}
