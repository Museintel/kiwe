<?php
/** Included inside the existing isolated, self-cleaning native content fixture. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || true !== KIWE_WORKSPACE_TEST_SITE ) exit( 2 );
( static function() use ( $assert, $users, $filter ): void {
	$actor = get_current_user_id(); wp_set_current_user( $users['client'] );
	$catalog = \DSA\Backup\Unit_Catalog_Service::snapshot();
	$units = array_column( $catalog['units'], null, 'id' );
	$assert( $catalog['schema'] === 'kiwe.backup-coverage.v1' && ! $catalog['issues'] && count( $catalog['contentUnits'] ) === 5, 'native coverage includes exactly the accepted collection entrances' );
	$config = \DSA\Access\Content_Archive_App_Service::config();
	foreach ( $catalog['contentUnits'] as $unit ) $assert( $unit['canExport'] === $config['canExportArchiveCollections'][$unit['scope']] && $unit['canImport'] === $config['canImportArchives'][$unit['singleScope']], 'native coverage and shared task permissions agree for ' . $unit['id'] );
	$assert( $units['wordpress.posts']['restorePolicy'] === 'new_drafts' && $units['wordpress.settings']['restorePolicy'] === 'reviewed_settings', 'native catalog preserves distinct draft and settings restore policies' );
	$assert( $units['wordpress.media']['canRestore'] === ( DSA\Backup\Media_Archive_Service::can_use() && class_exists( '\ZipArchive' ) ) && ! $units['woocommerce.operations']['canRestore'] && ! $units['kiwe.operations']['canCapture'], 'native coverage distinguishes bounded media copies from transactional and operational gaps' );
	$request = new WP_REST_Request( 'GET', '/dsa/v1/workspace/backups' ); $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$response = rest_do_request( $request ); $data = $response->get_data();
	$assert( $response->get_status() === 200 && $data['coverage'] === $catalog, 'protected native Backups response projects the catalog' );
	$denied = new WP_REST_Request( 'GET', '/dsa/v1/workspace/backups' );
	$assert( rest_do_request( $denied )->get_status() === 403, 'coverage inventory denies missing native nonce' );
	$declaration = [ 'schema'=>'kiwe.backup-unit-declaration.v1','id'=>'synthetic.future','label'=>'Synthetic future module','owner'=>'Synthetic extension','version'=>'synthetic.future.v1','description'=>'Future module-owned data.','dependsOn'=>['wordpress.posts'] ];
	$extend = static fn()=>[ $declaration ]; $filter( 'kiwe_backup_unit_declarations', $extend );
	$extended = \DSA\Backup\Unit_Catalog_Service::snapshot(); $unit = array_column( $extended['units'], null, 'id' )['synthetic.future'];
	$assert( $unit['status'] === 'pending' && ! $unit['canCapture'] && ! $unit['canRestore'] && count( $extended['contentUnits'] ) === 5, 'native extension declaration remains pending without an export entrance' );
	$assert( ! \DSA\Backup\Content_Archive_Service::can_import( 'synthetic.future' ) && ! \DSA\Backup\Content_Archive_Service::can_export_collection( 'synthetic.future' ), 'declared module cannot bypass native archive allowlist' );
	remove_filter( 'kiwe_backup_unit_declarations', $extend, PHP_INT_MAX );
	$override = $declaration; $override['id'] = 'wordpress.posts';
	$replace = static fn()=>[ $override ]; $filter( 'kiwe_backup_unit_declarations', $replace );
	$attempt = \DSA\Backup\Unit_Catalog_Service::snapshot();
	$assert( array_column( $attempt['units'], null, 'id' )['wordpress.posts'] === $units['wordpress.posts'] && in_array( 'duplicate_unit', $attempt['issues'], true ), 'native unit cannot be replaced by extension declaration' );
	remove_filter( 'kiwe_backup_unit_declarations', $replace, PHP_INT_MAX );
	$throw = static function() { throw new RuntimeException( 'Private synthetic declaration error' ); }; $filter( 'kiwe_backup_unit_declarations', $throw );
	$broken = \DSA\Backup\Unit_Catalog_Service::snapshot();
	$assert( $broken['contentUnits'] === $catalog['contentUnits'] && $broken['issues'] === ['declaration_hook_failed'] && ! str_contains( wp_json_encode( $broken ), 'Private synthetic' ), 'failing extension preserves native tasks without leaking its error' );
	remove_filter( 'kiwe_backup_unit_declarations', $throw, PHP_INT_MAX );
	$denseDeclarations = [];
	for ( $i = 0; $i < \DSA\Backup\Unit_Catalog_Service::MAX_EXTENSIONS; $i++ ) {
		$next = $declaration; $next['id'] = 'synthetic.node' . $i; $next['dependsOn'] = [];
		for ( $j = $i + 1; $j < min( \DSA\Backup\Unit_Catalog_Service::MAX_EXTENSIONS, $i + 13 ); $j++ ) $next['dependsOn'][] = 'synthetic.node' . $j;
		$denseDeclarations[] = $next;
	}
	$denseFilter = static function() use ( &$denseDeclarations ) { return $denseDeclarations; }; $filter( 'kiwe_backup_unit_declarations', $denseFilter );
	$started = hrtime( true ); $dense = \DSA\Backup\Unit_Catalog_Service::snapshot(); $elapsedMs = ( hrtime( true ) - $started ) / 1e6;
	$assert( count( $dense['units'] ) === count( $catalog['units'] ) + 24 && ! $dense['issues'] && $dense['contentUnits'] === $catalog['contentUnits'] && $elapsedMs < 1000, 'native overlapping 24-module dependency graph retains native actions within one second' );
	$denseDeclarations[23]['dependsOn'] = [ 'missing.native' ];
	$assert( count( \DSA\Backup\Unit_Catalog_Service::snapshot()['units'] ) === count( $catalog['units'] ), 'native missing shared dependency rejects every dependant on a fresh projection' );
	$denseDeclarations[23]['dependsOn'] = [ 'synthetic.node0' ];
	$assert( count( \DSA\Backup\Unit_Catalog_Service::snapshot()['units'] ) === count( $catalog['units'] ), 'native shared cycle rejects every dependant without reusing earlier valid states' );
	remove_filter( 'kiwe_backup_unit_declarations', $denseFilter, PHP_INT_MAX );
	foreach ( [ 'author','visitor' ] as $name ) {
		wp_set_current_user( $users[$name] ); $request = new WP_REST_Request( 'GET', '/dsa/v1/workspace/backups' ); $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$assert( rest_do_request( $request )->get_status() === 403, $name . ' cannot acquire coverage inventory access from declarations' );
		$projected = \DSA\Backup\Unit_Catalog_Service::snapshot(); $projectedUnits = array_column( $projected['units'], null, 'id' );
		$assert( ! $projectedUnits['wordpress.settings']['canCapture'] && $projectedUnits['wordpress.posts']['canRestore'] === \DSA\Backup\Content_Archive_Service::can_import( 'post' ), $name . ' projection rechecks current native owner and record authority' );
	}
	wp_set_current_user( $actor );
} )();
