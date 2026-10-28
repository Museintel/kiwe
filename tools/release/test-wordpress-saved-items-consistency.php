<?php
/** Real WordPress/MariaDB, exclusively inside the established native shadow fixture. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_SEO_NATIVE_TEST' ) || true !== KIWE_SEO_NATIVE_TEST || empty( $GLOBALS['kiwe_seo_test_tables'] ) ) throw new RuntimeException( 'Owned native saved fixture required.' );

function kiwe_pt5_native_run(): array {
	global $wpdb;
	$out = [ 'passed'=>false, 'cleanup'=>false, 'checks'=>[], 'observations'=>[] ];
	$check = static function( $ok, string $label ) use ( &$out ): void { $out['checks'][$label] = (bool) $ok; if ( ! $ok ) throw new RuntimeException( $label ); };
	$filters = []; $rival = null;
	try {
		$check( home_url() === 'https://plum-giraffe-539524.hostingersite.com', 'Native saved fixture is confined to the authorized lab' );
		$settings = get_option( DSA_OPTION_SETTINGS ); $context = get_option( 'kiwe_seam_design_context_v1' );
		// The shared historical fixture uses an obsolete policy name. Disable the
		// actual owner policy only in its disposable options table before seeding roles.
		update_option( DSA\Access\Site_Owner_Service::OPTION, [ 'enabled'=>false, 'ownerId'=>0 ], false );
		// Route only this derived cache to an owned temporary table, including explicit DDL.
		$from = $wpdb->prefix . 'dsa_saved_report_v2'; $to = str_replace( 'options', 'saved', $wpdb->options );
		$route = static fn( $sql ) => preg_replace( '/(?<![A-Za-z0-9_])' . preg_quote( $from, '/' ) . '(?![A-Za-z0-9_])/', $to, str_replace( 'CREATE TABLE IF NOT EXISTS ' . $from, 'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $from, $sql ) );
		add_filter( 'query', $route, 900000 ); $filters[] = [ 'query', $route, 900000 ];
		$original_guard = $GLOBALS['kiwe_seo_test_write_guard']; remove_filter( 'query', $original_guard, PHP_INT_MAX );
		$guard = static function( $sql ) use ( $to, $original_guard ) { if ( preg_match( '/^\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|CREATE\s+TEMPORARY\s+TABLE\s+IF\s+NOT\s+EXISTS|DROP\s+TEMPORARY\s+TABLE)\s+`?' . preg_quote( $to, '/' ) . '`?(?![A-Za-z0-9_])/i', $sql ) ) return $sql; return $original_guard( $sql ); };
		add_filter( 'query', $guard, PHP_INT_MAX ); $filters[] = [ 'query', $guard, PHP_INT_MAX ];
		$GLOBALS['kiwe_seo_test_shadows'][] = $to;
		// No provider, enrollment or account mail is involved in seeding these native records.
		$ids = [];
		for ( $i = 0; $i < 351; ++$i ) {
			if ( ! $wpdb->insert( $wpdb->users, [ 'user_login'=>'pt5-' . $i, 'user_pass'=>'not-an-authenticatable-password-hash', 'user_email'=>'pt5-' . $i . '@example.invalid', 'display_name'=>'Synthetic saved reader ' . $i, 'user_registered'=>current_time( 'mysql', true ) ] ) ) throw new RuntimeException( 'Synthetic user unavailable.' );
			$ids[] = (int) $wpdb->insert_id;
		}
		$admin = $ids[0]; $uid = $ids[1];
		update_user_meta( $admin, $wpdb->prefix . 'capabilities', [ 'administrator'=>true ] );
		update_user_meta( $uid, $wpdb->prefix . 'capabilities', [ 'subscriber'=>true ] );
		wp_set_current_user( $uid ); $saved = new DSA\Saved\Saved_Items_Service(); $saved->register();
		$item = static fn( string $name ): array => [ 'title'=>$name, 'url'=>'https://example.invalid/pt5/' . rawurlencode( $name ), 'savedAt'=>10 ];
		$a = $item( 'A' ); $b = $item( 'B' );
		$check( $saved->mutate( 'add', $a )['ok'], 'Actual native absent-row saved mutation is confirmed' );
		$pause = true;
		$barrier = static function( $sql ) use ( &$pause, $wpdb ) { if ( $pause && Fiber::getCurrent() && str_starts_with( $sql, 'UPDATE ' . $wpdb->usermeta . ' ' ) ) { $pause = false; Fiber::suspend( 'saved-write' ); } return $sql; };
		add_filter( 'query', $barrier, 50 ); $filters[] = [ 'query', $barrier, 50 ];
		$fiber = new Fiber( static fn() => $saved->mutate( 'add', $b ) );
		$check( $fiber->start() === 'saved-write', 'Actual native saved write reaches the concurrent SQL barrier' );
		$busy = $saved->mutate( 'remove', $a ); $fiber->resume(); remove_filter( 'query', $barrier, 50 );
		$check( ! $busy['ok'] && $busy['status'] === 409 && $fiber->getReturn()['ok'], 'Overlapping native save is nonblocking and never falsely acknowledged' );
		$check( $saved->mutate( 'remove', $a )['ok'] && array_column( $saved->current_items(), 'title' ) === [ 'B' ], 'A later device applies its removal against the confirmed native list' );
		$before = serialize( get_user_meta( $uid, 'dsa_saved_items', true ) );
		$failed = static fn( $sql ) => str_starts_with( $sql, 'UPDATE ' . $GLOBALS['wpdb']->usermeta . ' ' ) ? 'UPDATE ' . $GLOBALS['wpdb']->usermeta . ' SET pt5_nonexistent_column=1 WHERE user_id=0' : $sql;
		add_filter( 'query', $failed, 50 ); $filters[] = [ 'query', $failed, 50 ]; $suppress = $wpdb->suppress_errors( true );
		$result = $saved->mutate( 'add', $a ); $wpdb->suppress_errors( $suppress ); remove_filter( 'query', $failed, 50 );
		$check( ! $result['ok'] && $result['status'] === 503 && serialize( get_user_meta( $uid, 'dsa_saved_items', true ) ) === $before, 'Actual native SQL failure preserves the list and reports failure' );
		$claim = DSA\Saved\Saved_DB_Session::claim( 'user:' . $uid ); $owner = $claim['owner'];
		preg_match( "/IS_USED_LOCK\('([^']+)'\)/", $owner->fence(), $match ); $lock_name = $match[1];
		$rival = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $rival->suppress_errors( true );
		$check( (string) $rival->get_var( $rival->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) ) === '0', 'Independent native database session cannot take an active user lock' );
		$native_before = DSA\Saved\Saved_Items_State::read( $uid );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		$check( (string) $rival->get_var( $rival->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) ) === '1', 'Independent native database session obtains released ownership' );
		$r = DSA\Saved\Saved_Items_State::write( $uid, $native_before, [ $a ], $owner ); $owner->release();
		$check( $r['status'] === 503 && serialize( get_user_meta( $uid, 'dsa_saved_items', true ) ) === $before, 'Actual native session fence rejects the stale writer' );
		$check( (string) $rival->get_var( $rival->prepare( 'SELECT IS_USED_LOCK(%s)=CONNECTION_ID()', $lock_name ) ) === '1', 'Late native release leaves a successor lock intact' );
		$rival->get_var( $rival->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); $rival->close(); $rival = null;
		wp_set_current_user( 0 ); $n = $wpdb->num_queries; $result = $saved->mutate( 'add', $a );
		$check( ! $result['ok'] && $result['status'] === 401 && $wpdb->num_queries === $n, 'Native guest refusal has no SQL side effects' );
		wp_set_current_user( $admin ); $report = new DSA\Saved\Saved_Report_Index();
		$check( $report->snapshot()['status'] === 'needs_preparation', 'Private report is honest before explicit cache preparation' );
		wp_set_current_user( $uid ); $check( ! ( new DSA\Saved\Saved_Report_Index() )->prepare(), 'Subscriber cannot create private report schema' );
		wp_set_current_user( $admin ); $check( $report->prepare(), 'Explicit native preparation creates only the isolated disposable cache' );
		$reference_users = []; $reference_objects = [];
		foreach ( array_slice( $ids, 1 ) as $i => $user_id ) {
			$items = [];
			for ( $j = 0; $j < 20; ++$j ) {
				$number = ( $i * 13 + $j * 7 ) % 401;
				$row = $item( sprintf( 'Object %03d', $number ) ); $row['savedAt'] = 100 + $i;
				$items[] = $row; $key = DSA\Saved\Saved_Items_Service::item_key( $row );
				if ( ! isset( $reference_objects[$key] ) ) $reference_objects[$key] = [ 'key'=>$key, 'id'=>0, 'type'=>'bookmark', 'title'=>$row['title'], 'url'=>$row['url'], 'user_count'=>0 ];
				++$reference_objects[$key]['user_count'];
			}
			update_user_meta( $user_id, 'dsa_saved_items', $items );
			$reference_users[] = [ 'id'=>$user_id, 'latest'=>100 + $i ];
		}
		$raw_hash = hash( 'sha256', serialize( $wpdb->get_results( "SELECT * FROM {$wpdb->usermeta} WHERE meta_key='dsa_saved_items' ORDER BY umeta_id", ARRAY_A ) ) );
		$passes = 0; $max_units = 0; $max_seconds = 0.0; $max_queries = 0; $started = microtime( true );
		$diagnostic = null;
		$capture = static function( $error, $sql_error ) use ( &$diagnostic ): void { $diagnostic = [ 'class'=>get_class( $error ), 'message'=>$error->getMessage(), 'sqlError'=>$sql_error, 'frames'=>array_map( static fn( $f ) => [ 'line'=>$f['line'] ?? 0, 'function'=>$f['function'] ?? '' ], array_slice( $error->getTrace(), 0, 5 ) ) ]; };
		add_action( 'dsa_saved_report_failed', $capture, 10, 2 ); $filters[] = [ 'dsa_saved_report_failed', $capture, 10 ];
		do {
			$start = microtime( true ); $queries = $wpdb->num_queries; $r = $report->step( 0.25 );
			++$passes; $max_units = max( $max_units, (int) ( $r['units'] ?? 0 ) ); $max_queries = max( $max_queries, $wpdb->num_queries - $queries ); $max_seconds = max( $max_seconds, microtime( true ) - $start );
			if ( $r['status'] === 'unavailable' ) { $out['reportDiagnostic'] = $diagnostic; throw new RuntimeException( 'Native bounded report unavailable.' ); }
			if ( $passes > 500 ) throw new RuntimeException( 'Native bounded report failed to converge.' );
		} while ( $r['status'] !== 'ready' );
		$actual = $report->snapshot();
		$check( $actual['status'] === 'ready' && $actual['totals'] === [ 'users'=>350, 'wishlist_users'=>0, 'bookmark_users'=>350, 'objects'=>401 ], 'Completed native report has exact full-history totals beyond its display limit' );
		usort( $reference_objects, static fn( $a, $b ) => ( $b['user_count'] <=> $a['user_count'] ) ?: strcasecmp( $a['title'], $b['title'] ) ?: strcmp( $a['key'], $b['key'] ) );
		usort( $reference_users, static fn( $a, $b ) => ( $b['latest'] <=> $a['latest'] ) ?: ( $a['id'] <=> $b['id'] ) );
		$check( $actual['objects'] === array_slice( $reference_objects, 0, 250 ), 'Actual native object counts and all tie rankings match a full reference' );
		$check( array_column( $actual['users'], 'id' ) === array_column( array_slice( $reference_users, 0, 250 ), 'id' ), 'Native report selects the correct 250 profiles before hydration' );
		$check( $passes > 1 && $max_units <= 20, 'Native aggregation resumes across strictly bounded work units' );
		$check( $raw_hash === hash( 'sha256', serialize( $wpdb->get_results( "SELECT * FROM {$wpdb->usermeta} WHERE meta_key='dsa_saved_items' ORDER BY umeta_id", ARRAY_A ) ) ), 'Native report build never changes canonical saved rows' );
		wp_set_current_user( $uid ); $check( $saved->mutate( 'add', $item( 'New arrival' ) )['ok'], 'Ordinary native save works with a prepared report' );
		wp_set_current_user( $admin );
		$check( ( new DSA\Saved\Saved_Report_Index() )->step( 0.01 )['status'] === 'updating', 'Changed native saved state is not presented as a complete cached report' );
		for ( $i = 0; $i < 100; ++$i ) { $r = $report->step( 0.25 ); if ( $r['status'] === 'ready' ) break; }
		$check( $r['status'] === 'ready' && $report->snapshot()['totals']['objects'] === 402, 'Native incremental refresh catches changes without discarding historical totals' );
		$complete = static function( $report ) use ( &$out, &$diagnostic ): array {
			for ( $i = 0; $i < 500; ++$i ) { $result = $report->step( 0.25 ); if ( $result['status'] !== 'updating' ) break; }
			if ( $result['status'] !== 'ready' ) { $out['additionalReport'] = [ 'result'=>$result, 'passes'=>$i + 1, 'diagnostic'=>$diagnostic ]; throw new RuntimeException( 'Additional native report did not complete.' ); }
			return $report->snapshot();
		};
		// An independent actor with report access has no permission to edit private posts.
		$limited = $ids[2]; update_user_meta( $limited, $wpdb->prefix . 'capabilities', [ 'administrator'=>true ] ); clean_user_cache( $limited );
		$check( (bool) $wpdb->insert( $wpdb->posts, [ 'post_type'=>'post', 'post_status'=>'private', 'post_author'=>$admin, 'post_title'=>'Synthetic private saved article', 'post_content'=>'Synthetic only', 'post_password'=>'', 'post_date'=>current_time( 'mysql' ), 'post_date_gmt'=>current_time( 'mysql', true ), 'post_modified'=>current_time( 'mysql' ), 'post_modified_gmt'=>current_time( 'mysql', true ) ] ), 'Native private article created only in the owned fixture' );
		$post_id = (int) $wpdb->insert_id; $list = get_user_meta( $uid, 'dsa_saved_items', true ); $list[] = [ 'id'=>$post_id, 'type'=>'bookmark', 'savedAt'=>9 ]; update_user_meta( $uid, 'dsa_saved_items', $list );
		$restrict_article = static function( $caps, $cap, $actor, $args ) use ( $limited, $post_id ) { return (int) $actor === $limited && in_array( $cap, [ 'edit_post', 'read_post' ], true ) && (int) ( $args[0] ?? 0 ) === $post_id ? [ 'do_not_allow' ] : $caps; };
		add_filter( 'map_meta_cap', $restrict_article, PHP_INT_MAX, 4 ); $filters[] = [ 'map_meta_cap', $restrict_article, PHP_INT_MAX ];
		$check( $complete( $report )['totals']['objects'] === 403, 'Native full Administrator report retains authorized private saved content' );
		wp_set_current_user( $limited ); $check( current_user_can( 'manage_options' ) && ! current_user_can( 'read_post', $post_id ), 'Second native report actor has report access and an explicit per-record denial' ); $limited_report = new DSA\Saved\Saved_Report_Index(); $private_view = $complete( $limited_report );
		$check( $private_view['totals']['objects'] === 402 && ! in_array( $post_id, array_column( $private_view['objects'], 'id' ), true ), 'Cached report titles and totals never cross actor content permissions' );
		$wpdb->update( $wpdb->posts, [ 'post_status'=>'publish' ], [ 'ID'=>$post_id ] ); clean_post_cache( $post_id ); wp_after_insert_post( $post_id, true, null );
		$check( $complete( $limited_report )['totals']['objects'] === 403, 'Native post lifecycle invalidation restores newly readable saved content' );
		$wpdb->update( $wpdb->posts, [ 'post_status'=>'trash' ], [ 'ID'=>$post_id ] ); clean_post_cache( $post_id ); wp_after_insert_post( $post_id, true, null );
		$check( $complete( $limited_report )['totals']['objects'] === 402, 'Native post lifecycle invalidation removes inaccessible saved content from counts' );
		$wpdb->update( $wpdb->posts, [ 'post_status'=>'publish' ], [ 'ID'=>$post_id ] ); clean_post_cache( $post_id ); wp_after_insert_post( $post_id, true, null ); $complete( $limited_report );
		// External native metadata writers can change state without Kiwe's user lock.
		$list = get_user_meta( $uid, 'dsa_saved_items', true ); $list[] = $item( 'Before concurrent build' ); update_user_meta( $uid, 'dsa_saved_items', $list );
		$pause = true;
		$changed_during_read = static function( $sql ) use ( &$pause, $wpdb, $uid ) { if ( $pause && Fiber::getCurrent() && str_starts_with( $sql, 'SELECT umeta_id,' ) && str_contains( $sql, 'user_id=' . $uid . ' ' ) ) { $pause = false; Fiber::suspend( 'report-read' ); } return $sql; };
		add_filter( 'query', $changed_during_read, 50 ); $filters[] = [ 'query', $changed_during_read, 50 ];
		$fiber = new Fiber( static fn() => $limited_report->step( 1.0 ) ); $check( $fiber->start() === 'report-read', 'Native report reaches a source-change interleaving barrier' );
		$list[] = $item( 'During concurrent build' ); update_user_meta( $uid, 'dsa_saved_items', $list ); $fiber->resume(); remove_filter( 'query', $changed_during_read, 50 );
		$check( $fiber->getReturn()['status'] === 'updating' && $complete( $limited_report )['totals']['objects'] === 405, 'Native aggregation retries a changed source without acknowledging stale totals' );
		$duplicate = add_user_meta( $uid, 'dsa_saved_items', [ $item( 'Noncanonical duplicate' ) ], false );
		$duplicate_raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE umeta_id=%d", $duplicate ) );
		$check( $complete( $limited_report )['totals']['objects'] === 405, 'Legacy duplicate metadata does not inflate the native single-value saved report' );
		wp_set_current_user( $uid ); $check( $saved->mutate( 'add', $item( 'Canonical edit' ) )['ok'], 'Native saved mutation works alongside an existing duplicate metadata row' );
		$check( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE umeta_id=%d", $duplicate ) ) === $duplicate_raw, 'Guarded native mutation preserves the exact unrelated duplicate row' );
		wp_set_current_user( $limited ); $check( $complete( $limited_report )['totals']['objects'] === 406, 'Incremental totals remain exact after canonical-row editing' );
		$state = DSA\Saved\Saved_Items_State::read( $uid );
		$wpdb->update( $wpdb->usermeta, [ 'meta_value'=>'malformed synthetic state' ], [ 'umeta_id'=>$state['id'] ] ); wp_cache_delete( $uid, 'user_meta' ); DSA\Saved\Saved_Report_Journal::mark_user( $uid );
		$malformed = $limited_report->step( 1.0 );
		$check( $malformed['status'] === 'unavailable' && ! isset( $malformed['totals'] ), 'Malformed native history is explicit unavailable state rather than false zero totals' );
		$wpdb->update( $wpdb->usermeta, [ 'meta_value'=>$state['raw'] ], [ 'umeta_id'=>$state['id'] ] ); wp_cache_delete( $uid, 'user_meta' ); DSA\Saved\Saved_Report_Journal::mark_user( $uid );
		$check( $complete( $limited_report )['totals']['objects'] === 406, 'Restored canonical source resumes the existing private report exactly' );
		// Retention touches only one obsolete scope and at most 500 derived rows per pass.
		$old_scope = hash( 'sha256', 'pt5-expired-scope' ); $old_time = time() - 8 * DAY_IN_SECONDS;
		$wpdb->insert( $to, [ 'scope'=>$old_scope, 'kind'=>0, 'owner'=>0, 'object_key'=>'', 'rank_value'=>$old_time, 'data'=>'{}' ] );
		for ( $i = 1; $i <= 700; ++$i ) $wpdb->insert( $to, [ 'scope'=>$old_scope, 'kind'=>4, 'owner'=>$i, 'object_key'=>'', 'rank_value'=>0, 'data'=>'[]' ] );
		$limited_report->step( 0.1 ); $remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$to} WHERE scope=%s", $old_scope ) );
		$check( $remaining === 201, 'Private retention removes at most 500 rows from one expired actor scope' );
		$limited_report->step( 0.1 ); $check( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$to} WHERE scope=%s", $old_scope ) ) === 0, 'A later private pass retires the remaining disposable cache without a permanent cleanup job' );
		$check( $limited_report->snapshot()['totals']['objects'] === 406, 'Cache retirement preserves the current actor report and all saved source rows' );
		$check( get_option( DSA_OPTION_SETTINGS ) === $settings && get_option( 'kiwe_seam_design_context_v1' ) === $context, 'Saved reliability work preserves native settings and complete Design Context' );
		$out['observations'] = [ 'users'=>350, 'savedRows'=>7000, 'distinctObjects'=>401, 'passes'=>$passes, 'maxUnits'=>$max_units, 'maxQueriesPerPass'=>$max_queries, 'maxSecondsPerPass'=>$max_seconds, 'totalBuildSeconds'=>microtime( true ) - $started, 'peakMemoryBytes'=>memory_get_peak_usage( true ), 'scope'=>'Synthetic isolated native tables; not production concurrency capacity or browser timing' ];
		$out['passed'] = true;
	} catch ( Throwable $error ) { $out['error'] = $error->getMessage(); $out['frames'] = array_map( static fn( $f ) => [ 'file'=>basename( $f['file'] ?? '' ), 'line'=>$f['line'] ?? 0, 'function'=>$f['function'] ?? '' ], array_slice( $error->getTrace(), 0, 5 ) ); }
	finally {
		if ( $rival ) $rival->close();
		try { do_action( 'shutdown' ); } catch ( Throwable $error ) { $out['shutdownError'] = $error->getMessage(); $out['passed'] = false; }
		remove_all_actions( 'shutdown' );
		try { kiwe_seo_test_cleanup( $out ); } catch ( Throwable $error ) { $out['cleanup'] = false; $out['cleanupError'] = $error->getMessage(); }
		foreach ( $filters as [ $hook, $callback, $priority ] ) remove_filter( $hook, $callback, $priority );
	}
	$out['passed'] = $out['passed'] && $out['cleanup'] && ! in_array( false, $out['checks'], true );
	$out['checkCount'] = count( $out['checks'] );
	return $out;
}
