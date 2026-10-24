<?php
/** Actual event service and extracted native callbacks; synthetic WP/SQL boundaries. */
if ( PHP_SAPI !== 'cli' ) exit( 2 );
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__, 2 );
$core = file_get_contents( $root . '/wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php' );
$case = $argv[1] ?? 'events';
$cfg = [ 'track_visitors' => 0, 'track_pages' => 0, 'track_admin_activity' => 0, 'exclude_admin' => 0, 'honeypot_enabled' => 1 ];
$calls = []; $hooks = []; $checks = []; $admin = false; $nonce_valid = true;
function count_call( $key ) { global $calls; $calls[$key] = ( $calls[$key] ?? 0 ) + 1; }
function check( $name, $ok ) { global $checks; $checks[] = [ 'name' => $name, 'passed' => (bool) $ok ]; }
function stp_cfg() { global $cfg; return $cfg; }
function current_user_can( $cap ) { global $admin; return $admin; }
function is_user_logged_in() { global $admin; return $admin; }
function get_current_user_id() { return is_user_logged_in() ? 7 : 0; }
function current_time( $format ) { return '2026-09-10 00:00:00'; }
function stp_diag( $key, $value = null ) { count_call( 'diagnostic' ); return $value; }
class EventBoundary extends RuntimeException {}
function stp_tables_ready() { count_call( 'tables' ); throw new EventBoundary(); }
function add_action( $name, $callback, ...$args ) { global $hooks; $hooks[$name][] = $callback; }
function stp_is_static_request() { return false; }
function is_feed() { return false; }
function is_robots() { return false; }
function is_trackback() { return false; }
function is_ssl() { return true; }
function stp_scan_url( $url ) { count_call( 'scan' ); return []; }
function get_transient( $key ) { count_call( 'transient_read' ); return 0; }
function set_transient( $key, $value, $ttl ) { count_call( 'transient_write' ); return true; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function stp_honeypot_path() { return '/synthetic-honeypot'; }
function esc_url( $value ) { return $value; }
function esc_url_raw( $value ) { return $value; }
function esc_js( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function sanitize_key( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_create_nonce( $action ) { count_call( 'nonce_create' ); return 'synthetic'; }
function wp_verify_nonce( $nonce, $action ) { global $nonce_valid; count_call( 'nonce_check' ); return $nonce_valid; }
function stp_session_token() { count_call( 'session' ); return str_repeat( 'a', 64 ); }
function stp_get_ip() { count_call( 'ip' ); return '192.0.2.1'; }
function stp_rate_limit( ...$args ) { count_call( 'rate' ); return true; }
function pk_hmac( $value ) { return hash( 'sha256', $value ); }
function pk_ip() { return '192.0.2.1'; }
function pk_json( $value ) { return json_encode( $value ); }
function pk_now() { return '2026-09-10 00:00:00'; }
function pk_request_url() { return home_url( '/?kiwe-auth=1' ); }
function pk_t( $table ) { return 'synthetic_pk_' . $table; }
function stp_t( $table ) { return 'synthetic_stp_' . $table; }
function stp_log( $type, $args = [] ) { count_call( $type ); return DSA\Secure\SecureTrack_Event_Service::log( $type, $args ); }
function stp_log_page_view( $args = [] ) { count_call( 'page_view' ); return DSA\Secure\SecureTrack_Event_Service::log_page_view( $args ); }
class TelemetryDb {
    public array $inserts = [];
    function prepare( $sql, ...$args ) { return $sql; }
    function get_var( $sql ) { count_call( 'sql' ); return 0; }
    function insert( $table, $row ) { count_call( 'sql' ); $this->inserts[] = [ $table, $row ]; return 1; }
}
$wpdb = new TelemetryDb();
function source_between( $source, $start, $end ) {
    $from = strpos( $source, $start ); $to = strpos( $source, $end, $from === false ? 0 : $from + strlen( $start ) );
    if ( $from === false || $to === false ) throw new RuntimeException( 'Source boundary missing' );
    return substr( $source, $from, $to - $from );
}
require $root . '/wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Event_Service.php';
if ( $case === 'events' ) {
    for ( $i = 0; $i < 4000; $i++ ) { try { DSA\Secure\SecureTrack_Event_Service::log( 'page_view', [ 'url' => home_url( '/article' ), 'extra' => [ 'ref' => '' ] ] ); } catch ( EventBoundary $e ) {} }
    check( '4000 suppressed ordinary visits never reach SQL/IP/session/diagnostic boundaries', !$calls );
    $cfg['track_visitors'] = 1; $cfg['exclude_admin'] = 1; $admin = true; $calls = [];
    try { DSA\Secure\SecureTrack_Event_Service::log( 'page_view', [ 'url' => home_url() ] ); } catch ( EventBoundary $e ) {}
    check( 'Excluded administrator ordinary page skips before database work', !$calls );
    $cfg['track_visitors'] = 0;
    foreach ( [ 'pk_auth', 'pk_auth_fail', 'login_success', 'login_failed', 'logout', 'waf_block', 'protection_block', 'behavior_signal', 'break_glass_access', 'post_action', 'setting_change' ] as $type ) {
        $calls = []; try { DSA\Secure\SecureTrack_Event_Service::log( $type ); } catch ( EventBoundary $e ) {}
        check( $type . ' still reaches the original audit path with visitor tracking off', ( $calls['tables'] ?? 0 ) === 1 );
    }
    foreach ( [ [ 'sub' => 'author_archive_blocked', 'admin_probe' => true ], [ 'sqli' => true ], [ 'future_security_signal' => true ] ] as $args ) {
        $calls = []; try { DSA\Secure\SecureTrack_Event_Service::log( 'page_view', $args ); } catch ( EventBoundary $e ) {}
        check( 'Security-labelled page event remains auditable for excluded administrator', ( $calls['tables'] ?? 0 ) === 1 );
    }
    $cfg['track_visitors'] = 1; $admin = false; $calls = [];
    try { DSA\Secure\SecureTrack_Event_Service::log( 'page_view', [ 'url' => home_url() ] ); } catch ( EventBoundary $e ) {}
    check( 'Opted-in ordinary page still reaches event persistence', ( $calls['tables'] ?? 0 ) === 1 );
} elseif ( $case === 'visitor' ) {
    $section = substr( $core, strpos( $core, '//  VISITOR PAGE TRACKING' ) );
    eval( source_between( $section, "add_action( 'template_redirect'", "add_action( 'xmlrpc_call'" ) );
    for ( $i = 0; $i < 4000; $i++ ) $hooks['template_redirect'][0]();
    check( '4000 disabled visitor callbacks do no telemetry work', !$calls );
    $cfg['track_visitors'] = 1; $cfg['exclude_admin'] = 1; $admin = true; $calls = [];
    try { $hooks['template_redirect'][0](); } catch ( EventBoundary $e ) {}
    check( 'Excluded admin callback does no telemetry work', !$calls );
} elseif ( $case === 'footer' ) {
    $section = substr( $core, strpos( $core, '//  FRONTEND JS' ) );
    eval( source_between( $section, "add_action( 'wp_footer'", "add_action( 'admin_footer'" ) );
    foreach ( [ [0,0], [0,1], [1,0], [1,1] ] as [$visitors,$pages] ) {
        $cfg['track_visitors'] = $visitors; $cfg['track_pages'] = $pages; $calls = [];
        ob_start(); $hooks['wp_footer'][0](); $html = ob_get_clean(); $enabled = $visitors && $pages;
        check( "Public timing requires both existing switches ($visitors/$pages)", str_contains( $html, '__stpTimeTrack' ) === (bool) $enabled );
        check( "Honeypot remains present ($visitors/$pages)", str_contains( $html, '/synthetic-honeypot' ) );
        if ( !$enabled ) check( "No diagnostic/session/nonce work with timing off ($visitors/$pages)", !$calls );
    }
    $cfg['exclude_admin'] = 1; $admin = true; $calls = [];
    ob_start(); $hooks['wp_footer'][0](); $html = ob_get_clean();
    check( 'Excluded admin has no public timing beacon or tracking side effects', !str_contains( $html, '__stpTimeTrack' ) && !$calls );
} elseif ( $case === 'phonekey' ) {
    $phonekey = file_get_contents( $root . '/wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php' );
    eval( source_between( $phonekey, 'function pk_log(', 'function pk_rate_limit(' ) );
    foreach ( ['passkey_success','passkey_failure','otp_failure','totp_failure','recovery_blocked'] as $event ) {
        $calls = []; $wpdb->inserts = [];
        try { pk_log( $event, 7 ); } catch ( EventBoundary $e ) {}
        $type = $event === 'passkey_success' ? 'pk_auth' : 'pk_auth_fail';
        check( "$event retains native Key activity and SecureTrack authentication audit", count( $wpdb->inserts ) === 1 && $wpdb->inserts[0][1]['event'] === $event && ( $calls[$type] ?? 0 ) === 1 && ( $calls['tables'] ?? 0 ) === 1 );
    }
} elseif ( str_starts_with( $case, 'exit-' ) ) {
    eval( source_between( $core, 'function stp_ajax_exit()', 'function stp_ajax_behavior()' ) );
    $cfg['track_pages'] = $case === 'exit-off' ? 0 : 1;
    $cfg['track_visitors'] = in_array( $case, ['exit-visitor-off','exit-admin'], true ) ? 0 : 1;
    $cfg['track_admin_activity'] = 1;
    $admin = in_array( $case, ['exit-admin','exit-admin-off','exit-excluded-admin'], true );
    if ( $case === 'exit-admin-off' ) $cfg['track_admin_activity'] = 0;
    if ( $case === 'exit-excluded-admin' ) $cfg['exclude_admin'] = 1;
    $nonce_valid = $case !== 'exit-invalid-nonce';
    $_POST = ['k'=>str_repeat('a',64),'n'=>'synthetic','s'=>15,'u'=>in_array($case,['exit-admin','exit-admin-off'],true)?admin_url('edit.php'):home_url('/article')];
    register_shutdown_function( function () use ( $case ) {
        global $calls;
        $enabled = in_array( $case, ['exit-enabled','exit-admin'], true );
        $expected = $enabled ? ['nonce_check'=>1,'ip'=>1,'rate'=>1,'sql'=>1] : ( $case === 'exit-invalid-nonce' ? ['nonce_check'=>1] : [] );
        check( $case . ' retains only allowed work and returns 204', $calls === $expected && http_response_code() === 204 );
        finish();
    } );
    stp_ajax_exit();
    throw new RuntimeException( 'AJAX failed to terminate' );
} else { throw new RuntimeException( 'Unknown test case' ); }
function finish() {
    global $checks;
    $passed = !array_filter( $checks, fn( $c ) => !$c['passed'] );
    echo json_encode( ['passed'=>$passed,'checks'=>$checks] ) . "\n";
    if ( !$passed ) exit( 1 );
}
finish();
