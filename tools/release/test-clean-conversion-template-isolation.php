<?php

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'BRICKS_DB_TEMPLATE_SLUG', 'bricks_template' );
	define( 'DSA_OPTION_SETTINGS', 'dsa_settings' );

	$GLOBALS['kiwe_test_options'] = [
		'dsa_settings' => [],
		'bricks_global_classes' => [ [ 'id' => 'legacy' ] ],
	];
	$GLOBALS['kiwe_test_filters'] = [];
	$GLOBALS['kiwe_cache_writes'] = [];

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['kiwe_test_options'] ) ? $GLOBALS['kiwe_test_options'][ $name ] : $default;
	}
	function update_option( $name, $value ) {
		$GLOBALS['kiwe_test_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['kiwe_test_options'][ $name ] );
		return true;
	}
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
	}
	function sanitize_text_field( $value ) {
		return (string) $value;
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function get_current_user_id() {
		return 7;
	}
	function get_posts( $args ) {
		if ( 'bricks_template' !== ( $args['post_type'] ?? '' ) || 'publish' !== ( $args['post_status'] ?? '' ) ) {
			throw new \RuntimeException( 'Unexpected template snapshot query.' );
		}
		return [ 11, 22, 11, 0 ];
	}
	function add_filter( $hook, $callback ) {
		$GLOBALS['kiwe_test_filters'][ $hook ][] = $callback;
		return true;
	}
	function apply_filters( $hook, $value ) {
		foreach ( $GLOBALS['kiwe_test_filters'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value );
		}
		return $value;
	}
	function wp_cache_set( $key, $value, $group ) {
		$GLOBALS['kiwe_cache_writes'][] = compact( 'key', 'value', 'group' );
		return true;
	}
	function wp_next_scheduled() {
		return false;
	}
	function wp_schedule_single_event() {
		return true;
	}
}

namespace Bricks {
	final class Setup {}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Bricks/Clean_Conversion_Test_Service.php';

	$service = new \DSA\Bricks\Clean_Conversion_Test_Service();
	$result  = $service->begin( 'raw' );
	$status  = $service->status();
	if ( 2 !== $status['isolated_templates'] ) {
		throw new \RuntimeException( 'Expected two unique published templates in the snapshot.' );
	}

	\DSA\Bricks\Clean_Conversion_Test_Service::register_runtime_isolation();
	$args = apply_filters(
		'bricks/database/get_all_templates_by_type_args',
		[ 'post_type' => 'bricks_template', 'post__not_in' => [ 33 ] ]
	);
	if ( [ 33, 11, 22 ] !== $args['post__not_in'] ) {
		throw new \RuntimeException( 'The active-template exclusion did not preserve and merge query exclusions.' );
	}
	if ( [] !== get_option( 'bricks_global_classes' ) ) {
		throw new \RuntimeException( 'Global classes were not isolated.' );
	}

	$service->restore();
	if ( [ [ 'id' => 'legacy' ] ] !== get_option( 'bricks_global_classes' ) ) {
		throw new \RuntimeException( 'The exact global class snapshot was not restored.' );
	}
	if ( $service->status()['active'] ) {
		throw new \RuntimeException( 'The clean-run snapshot remained active after restore.' );
	}
	if ( count( $GLOBALS['kiwe_cache_writes'] ) < 2 ) {
		throw new \RuntimeException( 'Template cache was not invalidated for begin and restore.' );
	}

	echo "clean conversion template isolation: ok\n";
}
