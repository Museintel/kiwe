<?php

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'BRICKS_DB_TEMPLATE_SLUG', 'bricks_template' );
	define( 'DSA_OPTION_SETTINGS', 'dsa_settings' );

	$GLOBALS['kiwe_test_options'] = [
		'dsa_settings' => [
			'ai' => [ 'api_key' => 'fixture-old-secret' ],
			'diagnostics' => [ 'raw_convert_test_mode' => false ],
			'bricks' => [ 'add_to_cart_enhancer_enabled' => true ],
		],
		'bricks_global_classes' => [ [ 'id' => 'legacy' ] ],
	];
	$GLOBALS['kiwe_test_filters'] = [];
	$GLOBALS['kiwe_cache_writes'] = [];
	$GLOBALS['kiwe_option_reads'] = [];
	$GLOBALS['kiwe_option_write_count'] = 0;
	$GLOBALS['kiwe_test_actions'] = [];

	function get_option( $name, $default = false ) {
		$GLOBALS['kiwe_option_reads'][] = $name;
		return array_key_exists( $name, $GLOBALS['kiwe_test_options'] ) ? $GLOBALS['kiwe_test_options'][ $name ] : $default;
	}
	function update_option( $name, $value ) {
		$exists = array_key_exists( $name, $GLOBALS['kiwe_test_options'] );
		$old = get_option( $name );
		$value = apply_filters( 'pre_update_option_' . $name, $value, $old );
		if ( ! $exists ) $value = apply_filters( 'pre_add_option_' . $name, $value );
		if ( $exists && $value === $old ) return false;
		if ( $name === ( $GLOBALS['kiwe_fail_option_write'] ?? '' ) ) return false;
		$GLOBALS['kiwe_test_options'][ $name ] = $value;
		$GLOBALS['kiwe_option_write_count']++;
		do_action( $exists ? 'updated_option' : 'added_option', ... ( $exists ? [ $name, $old, $value ] : [ $name, $value ] ) );
		return true;
	}
	function delete_option( $name ) {
		do_action( 'delete_option', $name );
		if ( $name === ( $GLOBALS['kiwe_fail_option_delete'] ?? '' ) ) return false;
		unset( $GLOBALS['kiwe_test_options'][ $name ] );
		$GLOBALS['kiwe_option_write_count']++;
		do_action( 'deleted_option', $name );
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
		if ( ! in_array( $callback, $GLOBALS['kiwe_test_filters'][ $hook ] ?? [], true ) ) $GLOBALS['kiwe_test_filters'][ $hook ][] = $callback;
		return true;
	}
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['kiwe_test_filters'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
	function add_action( $hook, $callback ) {
		if ( ! in_array( $callback, $GLOBALS['kiwe_test_actions'][ $hook ] ?? [], true ) ) $GLOBALS['kiwe_test_actions'][ $hook ][] = $callback;
	}
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['kiwe_test_actions'][ $hook ] ?? [] as $callback ) $callback( ...$args );
	}
	function is_admin() { return false; }
	function wp_salt( $scheme ) { return 'synthetic-clean-runtime-salt-' . $scheme; }
	function wp_cache_set( $key, $value, $group ) {
		$GLOBALS['kiwe_cache_writes'][] = compact( 'key', 'value', 'group' );
		return true;
	}
	function wp_cache_get( $key, $group ) { return false; }
	function wp_cache_delete( $key, $group ) { return true; }
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
		'bricks/database/bricks_get_all_templates_by_type_args',
		[ 'post_type' => 'bricks_template', 'post__not_in' => [ 33 ] ]
	);
	if ( [ 33, 11, 22 ] !== $args['post__not_in'] ) {
		throw new \RuntimeException( 'The active-template exclusion did not preserve and merge query exclusions.' );
	}
	if ( [] !== get_option( 'bricks_global_classes' ) ) {
		throw new \RuntimeException( 'Global classes were not isolated.' );
	}

	$snapshot = get_option( 'dsa_clean_conversion_test_snapshot_v1' );
	if ( str_contains( serialize( $snapshot ), 'fixture-old-secret' ) || isset( $snapshot['options']['dsa_settings'] ) ) {
		throw new \RuntimeException( 'The clean-run snapshot captured unrelated service credentials.' );
	}
	$current_settings = get_option( 'dsa_settings' );
	$current_settings['ai']['api_key'] = 'fixture-rotated-secret';
	$current_settings['diagnostics']['new_unrelated_flag'] = true;
	$current_settings['bricks']['new_unrelated_control'] = 'keep';
	update_option( 'dsa_settings', $current_settings );
	$service->restore();
	$restored_settings = get_option( 'dsa_settings' );
	if ( 'fixture-rotated-secret' !== $restored_settings['ai']['api_key']
		|| true !== $restored_settings['diagnostics']['new_unrelated_flag']
		|| 'keep' !== $restored_settings['bricks']['new_unrelated_control']
		|| false !== $restored_settings['diagnostics']['raw_convert_test_mode']
		|| true !== $restored_settings['bricks']['add_to_cart_enhancer_enabled']
		|| array_key_exists( 'console_logs', $restored_settings['diagnostics'] ) ) {
		throw new \RuntimeException( 'Clean-run restore changed unrelated settings or failed to restore owned flags exactly.' );
	}
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
	echo "clean conversion credential and concurrent-setting isolation: ok\n";

	foreach ( [ 'raw', 'woo_native', 'woo_kiwe' ] as $profile ) {
		unset( $GLOBALS['kiwe_test_options']['dsa_settings'] );
		$service->begin( $profile );
		$service->restore();
		if ( array_key_exists( 'dsa_settings', $GLOBALS['kiwe_test_options'] ) ) {
			throw new \RuntimeException( "{$profile}: an originally absent settings option was not restored to absence." );
		}
		echo "{$profile}: absent settings round-trip: ok\n";
	}
	update_option( 'dsa_settings', [ 'diagnostics' => [], 'bricks' => [] ] );
	$service->begin( 'woo_native' );
	$snapshot = get_option( 'dsa_clean_conversion_test_snapshot_v1' );
	$snapshot['options']['dsa_settings'] = [ 'exists' => true, 'value' => [ 'ai' => [ 'api_key' => 'fixture-unwanted-secret' ] ] ];
	$snapshot['options']['unrelated_option'] = [ 'exists' => true, 'value' => 'unwanted' ];
	unset( $snapshot['hash'] );
	$snapshot['hash'] = hash( 'sha256', serialize( $snapshot ) );
	update_option( 'dsa_clean_conversion_test_snapshot_v1', $snapshot );
	$service->restore();
	if ( [ 'diagnostics' => [], 'bricks' => [] ] !== get_option( 'dsa_settings' ) || false !== get_option( 'unrelated_option' ) ) {
		throw new \RuntimeException( 'Settings/option allowlists did not preserve empty groups and exclude unrelated snapshot fields.' );
	}
	echo "empty groups and restore option allowlist: ok\n";
	$service->begin( 'raw' );
	$snapshot = get_option( 'dsa_clean_conversion_test_snapshot_v1' );
	$snapshot['profile'] = 'tampered';
	update_option( 'dsa_clean_conversion_test_snapshot_v1', $snapshot );
	$before = $GLOBALS['kiwe_test_options'];
	try {
		$service->restore();
		throw new \RuntimeException( 'Tampered snapshot was accepted.' );
	} catch ( \RuntimeException $error ) {
		if ( ! str_contains( $error->getMessage(), 'damaged' ) || $before !== $GLOBALS['kiwe_test_options'] ) {
			throw $error;
		}
	}
	echo "tampered snapshot leaves all options unchanged: ok\n";

	$master_key = 'dsa_clean_conversion_test_snapshot_v1';
	$runtime_key = 'dsa_clean_conversion_runtime_v1';
	delete_option( $master_key );
	$large = [ 'schema' => 'kiwe.clean-conversion-test.v1', 'excluded_template_ids' => [ 231, 242 ], 'options' => [ 'synthetic_restore_blob' => str_repeat( 'x', 2 * 1024 * 1024 ) ] ];
	$large['hash'] = hash( 'sha256', serialize( $large ) );
	update_option( $master_key, $large );
	$projection = get_option( $runtime_key );
	if ( strlen( serialize( $projection ) ) > 600 || $large !== get_option( $master_key ) ) throw new \RuntimeException( 'Projection replaced the full restoration source or retained its payload.' );
	$GLOBALS['kiwe_option_reads'] = [];
	$GLOBALS['kiwe_test_filters']['bricks/database/bricks_get_all_templates_by_type_args'] = [];
	$writes = $GLOBALS['kiwe_option_write_count'];
	\DSA\Bricks\Clean_Conversion_Test_Service::register_runtime_isolation();
	if ( in_array( $master_key, $GLOBALS['kiwe_option_reads'], true ) || $writes !== $GLOBALS['kiwe_option_write_count'] ) throw new \RuntimeException( 'A valid runtime projection reads the restoration blob or writes during rendering.' );
	if ( [ 33, 231, 242 ] !== apply_filters( 'bricks/database/bricks_get_all_templates_by_type_args', [ 'post__not_in' => [ 33 ] ] )['post__not_in'] ) throw new \RuntimeException( 'Projected exclusions differ from the source.' );
	echo "large restoration source stays separate; projected rendering reads no blob and writes nothing: ok\n";

	$next = $large;
	unset( $next['hash'] );
	$next['excluded_template_ids'] = [ 303 ];
	$next['hash'] = hash( 'sha256', serialize( $next ) );
	$GLOBALS['kiwe_fail_option_delete'] = $runtime_key;
	if ( update_option( $master_key, $next ) || get_option( $master_key ) !== $large || get_option( $runtime_key ) !== $projection ) throw new \RuntimeException( 'A failed cache invalidation allowed the source to change.' );
	try { delete_option( $master_key ); throw new \RuntimeException( 'Source deletion accepted failed invalidation.' ); }
	catch ( \RuntimeException $error ) { if ( ! str_contains( $error->getMessage(), 'could not be invalidated' ) || get_option( $master_key ) !== $large ) throw $error; }
	unset( $GLOBALS['kiwe_fail_option_delete'] );
	echo "failed invalidation refuses snapshot update and deletion: ok\n";

	$GLOBALS['kiwe_fail_option_write'] = $runtime_key;
	update_option( $master_key, $next );
	if ( false !== get_option( $runtime_key ) || get_option( $master_key ) !== $next ) throw new \RuntimeException( 'Failed projection write retained stale authority or lost the source.' );
	$GLOBALS['kiwe_test_filters']['bricks/database/bricks_get_all_templates_by_type_args'] = [];
	\DSA\Bricks\Clean_Conversion_Test_Service::register_runtime_isolation();
	if ( [ 303 ] !== apply_filters( 'bricks/database/bricks_get_all_templates_by_type_args', [] )['post__not_in'] ) throw new \RuntimeException( 'Missing projection failed to fall back to the newer source.' );
	unset( $GLOBALS['kiwe_fail_option_write'] );
	\DSA\Bricks\Clean_Conversion_Test_Service::refresh_runtime_projection();
	$runtime = get_option( $runtime_key );
	$runtime['excluded_template_ids'] = [ 999 ];
	update_option( $runtime_key, $runtime );
	$GLOBALS['kiwe_test_filters']['bricks/database/bricks_get_all_templates_by_type_args'] = [];
	\DSA\Bricks\Clean_Conversion_Test_Service::register_runtime_isolation();
	if ( [ 303 ] !== apply_filters( 'bricks/database/bricks_get_all_templates_by_type_args', [] )['post__not_in'] ) throw new \RuntimeException( 'An invalid projection signature became template authority.' );
	delete_option( $master_key );
	$GLOBALS['kiwe_test_filters']['bricks/database/bricks_get_all_templates_by_type_args'] = [];
	$GLOBALS['kiwe_option_reads'] = [];
	\DSA\Bricks\Clean_Conversion_Test_Service::register_runtime_isolation();
	if ( in_array( $master_key, $GLOBALS['kiwe_option_reads'], true ) || [] !== apply_filters( 'bricks/database/bricks_get_all_templates_by_type_args', [] ) ) throw new \RuntimeException( 'Deleted snapshot still contributes exclusions or blob reads.' );
	echo "failed cache write falls back; signature tampering and deletion retain source authority: ok\n";
}
