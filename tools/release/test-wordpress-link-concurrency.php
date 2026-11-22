<?php
/** Native installed acceptance only; the CLI owner passes its exact private root and helper. */
function kiwe_links_concurrent_submission( array $config, string $mode ): array {
	if ( ! in_array( $mode, [ 'normal','plain' ], true ) || '8.0.0-rc.470' !== DSA_VERSION ) throw new RuntimeException( 'Unexpected concurrency target' );
	$path = $config['private'] . '/concurrent-' . $mode . '.json';
	$_SERVER['REMOTE_ADDR'] = 'normal' === $mode ? '192.0.2.93' : '192.0.2.94';
	$input = json_decode( (string) file_get_contents( $path ), true, 32, JSON_THROW_ON_ERROR );
	wp_set_current_user( 0 ); remove_all_actions( 'kiwe_link_enquiry_saved' );
	add_action( 'kiwe_link_enquiry_saved', static function ( $id ) use ( $config,$mode ): void { if ( false === file_put_contents( $config['private'] . '/signals-' . $mode, (string) $id . "\n", FILE_APPEND | LOCK_EX ) ) throw new RuntimeException( 'Signal fixture unavailable' ); } );
	$deadline = microtime( true ) + 10;
	while ( ! is_file( $config['private'] . '/go-' . $mode ) ) { if ( microtime( true ) > $deadline ) throw new RuntimeException( 'Concurrency barrier expired' ); usleep( 10000 ); }
	$r = new WP_REST_Request( 'POST', '/dsa/v1/links/enquiry' ); $r->set_header( 'Content-Type','application/json' ); $r->set_header( 'X-Kiwe-Mutation','1' ); $r->set_header( 'X-WP-Nonce',wp_create_nonce( 'wp_rest' ) ); $r->set_body( wp_json_encode( $input ) );
	$result = ( new DSA\Link_Hub\Enquiry_Controller() )->submit( $r );
	return [ 'status'=>$result->get_status(), 'saved'=>true === ( $result->get_data()['saved'] ?? false ), 'sent'=>true === ( $result->get_data()['handoff']['sent'] ?? false ) ];
}

function kiwe_links_concurrency_tests( callable $check, callable $ledger, string $run, string $mode, array $config ): array {
	global $wpdb;
	wp_set_current_user( 0 ); $root = $config['private']; $paths = [];
	$body = [ 'channel'=>'email','ticket'=>DSA\Link_Hub\Enquiry_Service::ticket( 'email' ),'name'=>'Synthetic concurrent enquiry','contact'=>'kiwe-concurrent-' . $run . '@example.invalid','message'=>'Two overlapping native requests share one admitted intent.','subjectId'=>0,'website'=>'' ];
	$input = DSA\Link_Hub\Enquiry_Service::input( $body ); if ( is_wp_error( $input ) ) throw new RuntimeException( 'Concurrent input rejected' );
	$key = $input['key']; $ledger( 'concurrent-intent-' . $mode, [ 'key'=>$key ] ); $children = [];
	$ip_before = $_SERVER['REMOTE_ADDR'] ?? null;
	$ip = 'normal' === $mode ? '192.0.2.93' : '192.0.2.94'; $_SERVER['REMOTE_ADDR'] = $ip;
	$rate_hash = hash_hmac( 'sha256','link-enquiry|' . get_current_blog_id() . '|submit|' . $ip,wp_salt( 'nonce' ) );
	$owned_rate = false;
	try {
		$found = false; wp_cache_get( 'bucket_' . $rate_hash,'dsa_rate_limits',false,$found );
		$check( ! $found && ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}dsa_rate_limits WHERE bucket_hash=%s",$rate_hash ) ) && ! $wpdb->last_error,'Reserved concurrency IP has no pre-existing native or cached rate bucket' ); $owned_rate = true;
		$file = $root . '/concurrent-' . $mode . '.json'; $paths[] = $file; $h = fopen( $file,'x' ); if ( ! $h ) throw new RuntimeException( 'Fresh concurrency input required' ); fwrite( $h,wp_json_encode( $body ) ); fclose( $h ); chmod( $file,0600 );
		foreach ( [ 'a','b' ] as $label ) {
			$pipes = []; $child = proc_open( [ PHP_BINARY,$config['root'] . '/' . $config['helper'],'concurrent-' . $mode . '-' . $label ], [ 0=>[ 'pipe','r' ],1=>[ 'pipe','w' ],2=>[ 'pipe','w' ] ],$pipes,$config['root'] );
			if ( ! is_resource( $child ) ) throw new RuntimeException( 'Concurrent native child unavailable' ); fclose( $pipes[0] ); $children[] = [ $child,$pipes ];
		}
		$barrier = $root . '/go-' . $mode; $paths[] = $barrier; if ( false === file_put_contents( $barrier,'go',LOCK_EX ) ) throw new RuntimeException( 'Barrier unavailable' ); chmod( $barrier,0600 );
		$results = [];
		foreach ( $children as [ $child,$pipes ] ) { $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $child ); if ( $stderr || 0 !== $exit ) throw new RuntimeException( 'Concurrent child failed; retained operator receipt' ); $results[] = json_decode( $stdout,true,16,JSON_THROW_ON_ERROR ); }
		$children = [];
		$check( count( array_filter( $results,static fn( $row ) => 200 === $row['status'] && $row['saved'] && ! $row['sent'] ) ) >= 1, 'At least one overlapping native request confirms the non-sending receipt' );
		foreach ( $results as $row ) $check( in_array( $row['status'],[ 200,503 ],true ), 'Overlap resolves as a receipt or bounded retryable contention' );
		$retry = kiwe_links_concurrent_submission( $config,$mode ); $check( 200 === $retry['status'] && $retry['saved'] && ! $retry['sent'], 'A fresh native retry recovers the original receipt' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_name=%s AND post_author=0",DSA\Link_Hub\Enquiry_Store::TYPE,'kwe-' . $key ) );
		$check( ! $wpdb->last_error && 1 === count( $ids ), 'Actual parallel WordPress/MySQL requests create exactly one private enquiry' );
		$signals = $root . '/signals-' . $mode; $paths[] = $signals; $lines = is_file( $signals ) ? file( $signals,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : [];
		$check( 1 === count( $lines ) && (int) $lines[0] === (int) $ids[0], 'Only the newly saved record emits one source signal across overlap and retry' );
		return [ 'requests'=>$results,'retry'=>$retry,'nativeConcurrency'=>true ];
	} finally {
		foreach ( $children as [ $child,$pipes ] ) { if ( is_resource( $child ) ) proc_terminate( $child ); foreach ( [ 1,2 ] as $pipe ) if ( is_resource( $pipes[$pipe] ) ) fclose( $pipes[$pipe] ); if ( is_resource( $child ) ) proc_close( $child ); }
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_name=%s AND post_author=0",DSA\Link_Hub\Enquiry_Store::TYPE,'kwe-' . $key ) );
		foreach ( $ids as $id ) if ( is_wp_error( DSA\Link_Hub\Enquiry_Store::remove( (int) $id ) ) ) throw new RuntimeException( 'Concurrent fixture retirement failed' );
		foreach ( array_unique( array_merge( $paths,[ $root . '/signals-' . $mode ] ) ) as $path ) if ( is_file( $path ) && ( is_link( $path ) || ! unlink( $path ) ) ) throw new RuntimeException( 'Owned concurrency input retirement failed' );
		if ( $owned_rate ) { $wpdb->delete( $wpdb->prefix . 'dsa_rate_limits',[ 'bucket_hash'=>$rate_hash ] ); wp_cache_delete( 'bucket_' . $rate_hash,'dsa_rate_limits' ); }
		if ( null === $ip_before ) unset( $_SERVER['REMOTE_ADDR'] ); else $_SERVER['REMOTE_ADDR'] = $ip_before;
		$ledger( 'concurrent-cleanup-' . $mode,[ 'passed'=>true ] );
	}
}
