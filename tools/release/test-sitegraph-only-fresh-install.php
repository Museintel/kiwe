<?php

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DSA_OPTION_SETTINGS', 'dsa_settings' );

	$GLOBALS['kiwe_test_options'] = [];

	function get_option( string $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['kiwe_test_options'] )
			? $GLOBALS['kiwe_test_options'][ $name ]
			: $default;
	}

	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['kiwe_test_options'][ $name ] = $value;
		return true;
	}
}

namespace DSA\Diagnostics {
	final class Runtime_Profiler {
		public static function start() {
			return null;
		}

		public static function finish( string $name, $profile, bool $cached = false ): void {}
	}
}

namespace {
	require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Settings.php';

	function kiwe_true_boolean_paths( array $value, string $prefix = '' ): array {
		$paths = [];
		foreach ( $value as $key => $item ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( true === $item ) {
				$paths[] = $path;
			} elseif ( is_array( $item ) ) {
				$paths = array_merge( $paths, kiwe_true_boolean_paths( $item, $path ) );
			}
		}
		return $paths;
	}

	$mode = $argv[1] ?? 'fresh';
	if ( 'existing' === $mode ) {
		$GLOBALS['kiwe_test_options']['dsa_settings'] = [
			'enabled' => true,
			'secure'  => [ 'enabled' => true ],
		];
	}

	$settings = new \DSA\Settings();
	$settings->run_migrations();
	$resolved = $settings->all();

	if ( 'existing' === $mode ) {
		if ( empty( $resolved['enabled'] ) || empty( $resolved['secure']['enabled'] ) ) {
			fwrite( STDERR, 'Existing Kiwe settings were silently disabled by the fresh-install profile.' . PHP_EOL );
			exit( 1 );
		}
		if ( false !== get_option( 'dsa_install_profile', false ) ) {
			fwrite( STDERR, 'Existing Kiwe installation was incorrectly marked as a fresh install.' . PHP_EOL );
			exit( 1 );
		}
		echo "PASS existing-install settings preservation\n";
		exit( 0 );
	}

	$truthy   = kiwe_true_boolean_paths( $resolved );

	if ( [ 'site_graph.enabled' ] !== $truthy ) {
		fwrite( STDERR, 'Fresh Kiwe install enabled unexpected boolean settings: ' . json_encode( $truthy ) . PHP_EOL );
		exit( 1 );
	}

	if ( 'sitegraph_only_v1' !== get_option( 'dsa_install_profile' ) ) {
		fwrite( STDERR, 'Fresh Kiwe install did not persist the SiteGraph-only profile marker.' . PHP_EOL );
		exit( 1 );
	}

	if ( ! empty( $resolved['enabled'] )
		|| ! empty( $resolved['secure']['enabled'] )
		|| ! empty( array_filter( $resolved['dock']['enabled_items'] ?? [] ) )
		|| ! empty( $resolved['tokens']['enabled'] )
		|| ! empty( $resolved['bricks']['dynamic_tags_enabled'] )
	) {
		fwrite( STDERR, 'Fresh Kiwe install violated the fail-closed runtime contract.' . PHP_EOL );
		exit( 1 );
	}

	echo "PASS SiteGraph-only fresh-install profile\n";
}
