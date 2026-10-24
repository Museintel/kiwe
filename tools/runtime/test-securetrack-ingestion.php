<?php
/** Actual persistence and callback bodies, with counted synthetic WordPress boundaries. */
if ( PHP_SAPI !== 'cli' ) exit( 2 );
define( 'ABSPATH', __DIR__ . '/' );
$case = $argv[1] ?? 'visitor';
$dir = $argv[2] ?? dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Secure';
$core = file_get_contents( $dir . '/securetrack-core.php' );
if ( $case === 'diagnostics-optin' ) define( 'KIWE_SECURETRACK_DIAGNOSTICS', true );
$checks = []; $calls = []; $options = []; $transients = []; $hooks = [];
$cfg = [ 'track_visitors'=>1, 'track_pages'=>1, 'exclude_admin'=>0, 'adaptive_learning'=>0, 'block_brute_force'=>0, 'alert_delivery_policy'=>'actionable' ];
$risk = [ 'score'=>0, 'flag'=>'green', 'reasons'=>'' ];
$ip = (object) [ 'id'=>7, 'status'=>'unknown', 'is_proxy'=>0, 'risk_score'=>0 ];
function check( $name, $ok ) { global $checks; $checks[] = [ 'name'=>$name, 'passed'=>(bool) $ok ]; }
function called( $key ) { global $calls; $calls[$key] = ($calls[$key] ?? 0) + 1; }
function stp_cfg() { global $cfg; return $cfg; }
function current_time( $format ) { return '2026-10-06 00:00:00'; }
function get_current_user_id() { return 0; }
function current_user_can( $cap ) { return false; }
function is_user_logged_in() { return false; }
function is_ssl() { return true; }
function is_feed() { return false; }
function is_robots() { return false; }
function is_trackback() { return false; }
function stp_is_static_request() { return false; }
function stp_scan_url( $url ) { return []; }
function stp_tables_ready() { return true; }
function stp_get_ip() { called('ip-resolution'); return '192.0.2.1'; }
function stp_upsert_ip( $address ) { global $ip; called('ip-upsert'); return $ip; }
function stp_t( $name ) { return 'fixture_stp_' . $name; }
function sanitize_text_field( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function stp_diag( $key, $value=null ) { return DSA\Secure\SecureTrack_Db_Service::diag( $key, $value ); }
function get_option( $key, $default=false ) { global $options; called('option-read'); return $options[$key] ?? $default; }
function update_option( $key, $value, $autoload=null ) { global $options; called('option-write'); $options[$key]=$value; return true; }
function get_transient( $key ) { global $transients; called('transient-read'); return $transients[$key] ?? false; }
function set_transient( $key, $value, $ttl ) { global $transients; called('transient-write'); $transients[$key]=$value; return true; }
function stp_rate_limit( ...$args ) { called('rate'); return true; }
function stp_log( $type, $args=[] ) { return DSA\Secure\SecureTrack_Event_Service::log( $type, $args ); }
function stp_log_page_view( $args=[] ) { return DSA\Secure\SecureTrack_Event_Service::log_page_view( $args ); }
function stp_should_create_session_for_event( $type ) { return in_array($type,['page_view','login_failed'],true); }
function stp_ip_has_nearby_threat( $address ) { return false; }
function stp_user_profile_knows_ip( ...$args ) { return false; }
function stp_behavioral_anomaly( ...$args ) { return []; }
function stp_brain_context( ...$args ) { return []; }
function stp_brain_observe( ...$args ) {}
function stp_ai_queue_maybe( ...$args ) {}
function stp_update_subnet_intel( ...$args ) {}
function stp_attack_graph_update( ...$args ) { return null; }
function stp_detect_chain( ...$args ) { return null; }
function stp_event_hash( $row ) { return hash('sha256',json_encode($row)); }
function stp_risk( $type, $context ) { global $risk; return $risk; }
function stp_alert( ...$args ) {}
function stp_create_alert( ...$args ) {}
function stp_subnet24_cidr( ...$args ) { return '192.0.2.0/24'; }
function do_action( ...$args ) {}
function add_action( $hook, $callback, ...$args ) { global $hooks; $hooks[$hook][]=$callback; }
class IngestionDb {
    public array $queries=[], $inserts=[], $updates=[];
    public int $insert_id=0, $pageCount=0;
    public string $last_error='';
    public ?object $session=null;
    public string $fail='';
    public bool $interleave=false;
    function prepare( $sql, ...$args ) { return $sql; }
    function get_row( $sql ) { $this->queries[]=$sql; called('session-read'); return $this->session ? clone $this->session : null; }
    function get_var( $sql ) { $this->queries[]=$sql; return str_contains($sql,'page_count') ? $this->pageCount : null; }
    function insert( $table, $row ) {
        if(str_ends_with($table,$this->fail)&&$this->fail!==''){$this->insert_id=0;return false;}
        $this->insert_id++; $this->inserts[]=[$table,$row];
        if(str_ends_with($table,'sessions'))$this->session=(object)array_merge(['id'=>$this->insert_id,'flag_status'=>'yellow'],$row);
        return 1;
    }
    function update( $table, $row, $where ) {
        $this->updates[]=[$table,$row];
        if(isset($row['page_count'])){
            if($this->interleave){$this->pageCount++;$this->interleave=false;}
            $this->pageCount=$row['page_count'];
        }
        if(str_ends_with($table,'sessions')&&$this->session)foreach($row as$key=>$value)$this->session->$key=$value;
        return 1;
    }
    function query( $sql ) {
        $this->queries[]=$sql;
        if(str_contains($sql,'page_count=page_count+1')){
            if($this->interleave){$this->pageCount++;$this->interleave=false;}
            $this->pageCount++;
        }
        return 1;
    }
}
$wpdb = new IngestionDb();
function body( $source, $start, $end ) {
    $a=strpos($source,$start); $b=$a===false?false:strpos($source,$end,$a+strlen($start));
    if($a===false||$b===false)throw new RuntimeException('Source boundary missing');
    return substr($source,$a,$b-$a);
}
require $dir . '/SecureTrack_Db_Service.php';
require $dir . '/SecureTrack_Event_Service.php';
eval(body($core,'function stp_session_token()', '//  SECURITY SCANNER'));
if ( $case === 'session' || $case === 'token-only' ) {
    unset($_COOKIE['stp_sess']); $first=stp_session_token();
    check('A first request has one stable event/page/footer token',stp_session_token()===$first&&($_COOKIE['stp_sess']??null)===$first);
    $_COOKIE['stp_sess']=str_repeat('b',64);check('An existing valid cookie is reused',stp_session_token()===str_repeat('b',64));
    if ( $case === 'session' ) {
    $_COOKIE['stp_sess']=['invalid'];$next=stp_session_token();check('Malformed cookie arrays are safely replaced',is_string($next)&&strlen($next)===64&&stp_session_token()===$next);
    $wpdb->session=(object)['id'=>8,'last_activity'=>current_time('mysql'),'flag_status'=>'yellow'];
    stp_upsert_session($ip);check('Same-second activity avoids an unchanged write',!$wpdb->updates);
    $wpdb->session->last_activity='2026-10-05 00:00:00';$row=stp_upsert_session($ip);
    check('Changed activity remains durable with the exact native clock',count($wpdb->updates)===1&&$row->last_activity===current_time('mysql'));
    }
} elseif ( str_starts_with($case,'diagnostics') ) {
    $enabled=$case==='diagnostics-optin';
    for($i=0;$i<1000;$i++)foreach(['last_log_attempt','last_insert','last_db_error','last_failed_event']as$key)stp_diag($key,['synthetic'=>true]);
    check('Default diagnostic calls execute no option or transient operations',$enabled||!$calls);
    if($enabled)check('Opt-in diagnostics coalesce successes and failures',($calls['option-write']??0)===4&&($calls['transient-write']??0)===4);
    $options['stp_diag']=['prior'=>'kept'];check('Previously saved diagnostic data remains readable',stp_diag('prior')==='kept');
} elseif ( $case === 'visitor' ) {
    $_SERVER=['HTTP_HOST'=>'example.test','REQUEST_URI'=>'/article','HTTP_USER_AGENT'=>'Synthetic person'];
    $section=substr($core,strpos($core,'//  VISITOR PAGE TRACKING'));
    eval(body($section,"add_action( 'template_redirect'","add_action( 'xmlrpc_call'"));
    unset($_COOKIE['stp_sess']);$wpdb->interleave=true;$hooks['template_redirect'][0]();
    $events=array_values(array_filter($wpdb->inserts,fn($r)=>str_ends_with($r[0],'events')));
    $pages=array_values(array_filter($wpdb->inserts,fn($r)=>str_ends_with($r[0],'pages')));
    check('Detailed navigation resolves and counts the IP only once',($calls['ip-upsert']??0)===1);
    check('Detailed navigation uses one session lookup',($calls['session-read']??0)===1);
    check('Page and event refer to the same first-request session',count($events)===1&&count($pages)===1&&$events[0][1]['session_id']===$pages[0][1]['session_id']);
    check('An interleaved visit increment survives',$wpdb->pageCount===2);
    check('Ordinary visits create no diagnostic/transient option writes',!($calls['option-write']??0)&&!($calls['transient-write']??0));
    check('Unchanged IP risk does not trigger an update',!array_filter($wpdb->updates,fn($r)=>str_ends_with($r[0],'ips')));
    $calls=[];$wpdb->updates=[];$hooks['template_redirect'][0]();
    check('Same-clock repeat visit performs no session activity rewrite',!array_filter($wpdb->updates,fn($r)=>isset($r[1]['last_activity'])));
} elseif ( $case === 'failures' ) {
    foreach(['events','pages','sessions']as$failed){
        $wpdb=new IngestionDb();$wpdb->fail=$failed;$calls=[];unset($_COOKIE['stp_sess']);
        $id=stp_log_page_view(['url'=>'https://example.test/failure']);
        $pages=array_filter($wpdb->inserts,fn($r)=>str_ends_with($r[0],'pages'));
        check($failed.' failure preserves event/page accounting',$failed==='events'?($id===null&&!$pages&&$wpdb->pageCount===0):($id>0&&!$pages&&$wpdb->pageCount===0));
    }
    $wpdb=new IngestionDb();$cfg['track_visitors']=0;$cfg['track_pages']=1;
    for($i=0;$i<30;$i++)stp_log('login_failed');
    check('Optional tracking off still records every essential failure',count($wpdb->inserts)===30&&!array_filter($wpdb->inserts,fn($r)=>!str_ends_with($r[0],'events')));
    $risk=['score'=>90,'flag'=>'red','reasons'=>'Synthetic threat'];$cfg['track_visitors']=1;
    $wpdb=new IngestionDb();$wpdb->session=(object)['id'=>1,'last_activity'=>current_time('mysql'),'is_bot'=>0,'flag_status'=>'red'];
    stp_log('login_failed');
    check('Changed IP risk is still written',count(array_filter($wpdb->updates,fn($r)=>isset($r[1]['risk_score'])))===1);
    check('Already-red sessions avoid a redundant rewrite',!array_filter($wpdb->updates,fn($r)=>isset($r[1]['flag_status'])));
} else throw new RuntimeException('Unknown case');
$passed=!in_array(false,array_column($checks,'passed'),true);
echo json_encode(['passed'=>$passed,'checks'=>$checks,'calls'=>$calls])."\n";
exit($passed?0:1);
