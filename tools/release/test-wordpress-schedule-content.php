<?php
/**
 * Disposable-host acceptance for structured Pages and real future-post cron.
 *
 * The CLI launcher must load WordPress, define KIWE_WORKSPACE_TEST_SITE and pass
 * an absolute private state path outside public_html. Never deploy this file
 * as a public WordPress endpoint or include it in the MU package.
 */
if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || true !== KIWE_WORKSPACE_TEST_SITE ) exit( 2 );

$phase = $argv[1] ?? '';
$state_file = defined( 'KIWE_SCHEDULE_STATE_FILE' ) ? KIWE_SCHEDULE_STATE_FILE : '';
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $phase, [ 'prepare', 'check', 'cleanup' ], true )
	|| 'plum-giraffe-539524.hostingersite.com' !== $host
	|| ! is_string( $state_file ) || ! str_starts_with( $state_file, '/home/u406734797/domains/plum-giraffe-539524.hostingersite.com/kiwe-private-' )
	|| ! str_ends_with( $state_file, '/schedule-state.json' ) ) exit( 3 );

$read = static function () use ( $state_file ): array {
	$data = is_file( $state_file ) ? json_decode( (string) file_get_contents( $state_file ), true ) : [];
	return is_array( $data ) ? $data : [];
};
$write = static function ( array $state ) use ( $state_file ): void {
	$dir = dirname( $state_file );
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) throw new RuntimeException( 'private_state_directory' );
	if ( false === file_put_contents( $state_file, wp_json_encode( $state ), LOCK_EX ) ) throw new RuntimeException( 'private_state_write' );
};
$remove = static function ( array $state ): bool {
	foreach ( (array) ( $state['ids'] ?? [] ) as $id ) {
		$id = absint( $id );
		if ( $id && get_post( $id ) ) wp_delete_post( $id, true );
	}
	foreach ( (array) ( $state['ids'] ?? [] ) as $id ) if ( get_post( absint( $id ) ) ) return false;
	return true;
};
$emit = static function ( array $result ): void { echo wp_json_encode( $result ) . PHP_EOL; };

if ( 'cleanup' === $phase ) {
	$state = $read();
	$clean = $remove( $state );
	if ( $clean && is_file( $state_file ) ) unlink( $state_file );
	$emit( [ 'phase'=>'cleanup', 'passed'=>$clean, 'recordsRemoved'=>$clean ] );
	return;
}
if ( 'check' === $phase ) {
	$state = $read();
	if ( ! empty( $state['final'] ) ) { $emit( $state['final'] ); return; }
	if ( empty( $state['ready'] ) ) { $emit( [ 'phase'=>'check', 'passed'=>false, 'pending'=>true, 'reason'=>'No prepared fixture' ] ); return; }
	$now = time();
	$statuses = [];
	foreach ( (array) $state['scheduled'] as $type=>$id ) $statuses[$type] = get_post_status( absint( $id ) );
	$eventsGone = true;
	foreach ( (array) $state['scheduled'] as $id ) $eventsGone = $eventsGone && ! wp_next_scheduled( 'publish_future_post', [ absint( $id ) ] );
	$passed = $now >= (int) $state['due'] && ! in_array( false, $statuses, true ) && count( array_filter( $statuses, static fn( $status ) => 'publish' === $status ) ) === 2 && $eventsGone;
	$expired = $now > (int) $state['due'] + 180;
	$result = [ 'phase'=>'check', 'passed'=>$passed, 'pending'=>! $passed && ! $expired, 'now'=>$now, 'due'=>(int) $state['due'], 'statuses'=>$statuses, 'eventsGone'=>$eventsGone, 'structuredPageChecks'=>$state['structuredPageChecks'] ?? [], 'debugOff'=>! WP_DEBUG && ! WP_DEBUG_LOG && ! WP_DEBUG_DISPLAY ];
	if ( $passed || $expired ) {
		$result['cleanup'] = $remove( $state );
		$result['passed'] = $result['passed'] && $result['cleanup'] && $result['debugOff'];
		$state['final'] = $result;
		$write( $state );
	} elseif ( $now >= (int) $state['due'] ) $result['reason'] = 'Waiting for a due WordPress cron run';
	$emit( $result );
	return;
}

$state = $read();
if ( ! empty( $state['ready'] ) ) { $emit( [ 'phase'=>'prepare', 'passed'=>true, 'alreadyPrepared'=>true, 'due'=>$state['due'] ] ); return; }
if ( ! empty( $state['final'] ) ) { $emit( [ 'phase'=>'prepare', 'passed'=>true, 'alreadyCompleted'=>true ] ); return; }

$admins = get_users( [ 'role'=>'administrator', 'number'=>1, 'fields'=>'ID' ] );
$admin = class_exists( 'DSA\Access\Site_Owner_Service' ) ? absint( DSA\Access\Site_Owner_Service::owner_id() ) : 0;
if ( ! $admin ) $admin = absint( $admins[0] ?? 0 );
if ( ! $admin ) throw new RuntimeException( 'No fixture administrator' );
wp_set_current_user( $admin );
$prefix = 'kiwe148qa_' . bin2hex( random_bytes( 4 ) );
$ids = [];
$checks = [];
$assert = static function ( bool $condition, string $label ) use ( &$checks ): void {
	$checks[] = [ 'name'=>$label, 'passed'=>$condition ];
	if ( ! $condition ) throw new RuntimeException( $label );
};
add_filter( 'pre_wp_mail', static fn() => false );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'kiwe_qa_network', 'Fixture outbound request blocked' ) );
try {
	$body = '<!-- wp:paragraph --><p>Structured content stays in WordPress.</p><!-- /wp:paragraph -->' . "\n" . '[gallery ids=""]';
	$page = wp_insert_post( [ 'post_type'=>'page', 'post_status'=>'draft', 'post_title'=>$prefix . ' Page', 'post_content'=>$body, 'post_author'=>$admin ], true );
	if ( is_wp_error( $page ) || ! $page ) throw new RuntimeException( 'Create structured page' );
	$ids[] = (int) $page;
	$bricks = [ [ 'id'=>'fixture01', 'name'=>'section', 'children'=>[] ] ];
	// The builder forbids ordinary post-meta writes outside its editor. Seed
	// this synthetic record directly, then exercise only Kiwe's public native
	// page writers; never use direct SQL in the product mutation path.
	global $wpdb;
	$seeded = $wpdb->insert( $wpdb->postmeta, [ 'post_id'=>$page, 'meta_key'=>'_bricks_page_content_2', 'meta_value'=>maybe_serialize( $bricks ) ], [ '%d', '%s', '%s' ] );
	if ( 1 !== $seeded ) throw new RuntimeException( 'Seed synthetic builder metadata' );
	wp_cache_delete( $page, 'post_meta' );
	$preserved = static fn() => (string) get_post( $page )->post_content === $body && get_post_meta( $page, '_bricks_page_content_2', true ) === $bricks;
	$assert( has_blocks( $body ) && $preserved(), 'Block, shortcode and Bricks metadata fixture intact: blocks=' . ( has_blocks( $body ) ? 'yes' : 'no' ) . ', body=' . ( (string) get_post( $page )->post_content === $body ? 'exact' : 'changed' ) . ', bricks=' . ( get_post_meta( $page, '_bricks_page_content_2', true ) === $bricks ? 'exact' : 'changed' ) );
	$pages = new DSA\Access\Page_Workspace_Service();
	$assert( ! is_wp_error( $pages->rename_page( $page, [ 'page_title'=>$prefix . ' Renamed', 'expected_title'=>$prefix . ' Page' ] ) ) && $preserved(), 'Native rename preserves structured page content and builder metadata' );
	$assert( ! is_wp_error( $pages->save_indexing( $page, 'index' ) ) && $preserved(), 'Native indexing preserves structured page content and builder metadata' );
	$publishing = new DSA\Access\Page_Publishing_Service();
	$local = wp_date( 'Y-m-d\TH:i', time() + 20 * MINUTE_IN_SECONDS );
	$scheduled = $publishing->save( $page, [ 'expected'=>DSA\Access\Page_Publishing_Service::fingerprint( get_post( $page ) ), 'scheduledLocal'=>$local, 'status'=>'future' ] );
	$assert( ! is_wp_error( $scheduled ) && 'future' === get_post_status( $page ) && $preserved(), 'Native schedule preserves structured page content and builder metadata' );
	$published = $publishing->save( $page, [ 'expected'=>DSA\Access\Page_Publishing_Service::fingerprint( get_post( $page ) ), 'scheduledLocal'=>'', 'status'=>'publish' ] );
	$assert( ! is_wp_error( $published ) && 'publish' === get_post_status( $page ) && $preserved(), 'Native publish-now preserves structured page content and builder metadata' );
	$revision_body = '<!-- wp:heading --><h2>Earlier structured copy</h2><!-- /wp:heading -->' . "\n" . '[gallery ids=""]';
	$revision = wp_insert_post( [ 'post_type'=>'revision', 'post_status'=>'inherit', 'post_parent'=>$page, 'post_author'=>$admin, 'post_title'=>$prefix . ' Earlier', 'post_content'=>$revision_body ], true );
	if ( is_wp_error( $revision ) || ! $revision ) throw new RuntimeException( 'Create structured revision' );
	$ids[] = (int) $revision;
	$versions = $publishing->revisions( $page );
	$row = null;
	foreach ( (array) ( $versions['revisions'] ?? [] ) as $candidate ) if ( (int) $candidate['id'] === (int) $revision ) $row = $candidate;
	$assert( is_array( $row ) && ! isset( $row['content'] ), 'Structured saved version stays bounded and private' );
	$restored = $publishing->restore( $page, (int) $revision, [ 'expected'=>$versions['expected'], 'revisionExpected'=>$row['expected'] ] );
	$assert( ! is_wp_error( $restored ) && get_post( $page )->post_content === $revision_body && get_post_meta( $page, '_bricks_page_content_2', true ) === $bricks, 'Explicit version restore replaces block body while preserving Bricks metadata' );
	wp_delete_post( $page, true );
	$ids = [];

	$due = time() + 105;
	$date = wp_date( 'Y-m-d H:i:s', $due );
	$gmt = gmdate( 'Y-m-d H:i:s', $due );
	$scheduled_ids = [];
	foreach ( [ 'post', 'page' ] as $type ) {
		$id = wp_insert_post( [ 'post_type'=>$type, 'post_status'=>'future', 'post_title'=>$prefix . ' Cron ' . $type, 'post_content'=>'<p>Synthetic scheduled publication proof.</p>', 'post_author'=>$admin, 'edit_date'=>true, 'post_date'=>$date, 'post_date_gmt'=>$gmt ], true );
		if ( is_wp_error( $id ) || ! $id ) throw new RuntimeException( 'Create scheduled ' . $type );
		$ids[] = (int) $id;
		$scheduled_ids[$type] = (int) $id;
		$assert( 'future' === get_post_status( $id ) && (int) wp_next_scheduled( 'publish_future_post', [ (int) $id ] ) === $due, 'Native ' . $type . ' has exact scheduled event' );
	}
	$write( [ 'ready'=>true, 'ids'=>$ids, 'scheduled'=>$scheduled_ids, 'due'=>$due, 'structuredPageChecks'=>$checks ] );
	$emit( [ 'phase'=>'prepare', 'passed'=>true, 'due'=>$due, 'scheduledTypes'=>array_keys( $scheduled_ids ), 'structuredPageChecks'=>$checks ] );
} catch ( Throwable $error ) {
	foreach ( $ids as $id ) if ( get_post( $id ) ) wp_delete_post( $id, true );
	$emit( [ 'phase'=>'prepare', 'passed'=>false, 'error'=>$error->getMessage(), 'cleanup'=>! array_filter( $ids, 'get_post' ), 'structuredPageChecks'=>$checks ] );
}
