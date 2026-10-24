<?php
declare(strict_types=1);
// Disposable native fixture only; never installed in a customer package.
// The one-use operator supplies a private, expiring spec and separate database.
if (!isset($kiwe_profile_spec) || !is_array($kiwe_profile_spec)) { http_response_code(404); exit; }
$spec = $kiwe_profile_spec;
$prime = PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--prime';
if (!$prime && (time() > $spec['expires'] || !hash_equals($spec['token'], (string)($_SERVER['HTTP_X_KIWE_RESOURCE_TEST'] ?? '')))) { http_response_code(404); exit; }
$started = hrtime(true);
$counts = ['queries'=>0,'writes'=>0,'ddl'=>0,'http'=>0,'mail'=>0,'secure'=>0,'search'=>0,'options'=>0,'other'=>0,'sqlErrors'=>0];
$trace = !empty($_SERVER['HTTP_X_KIWE_RESOURCE_TRACE']);
$queries = [];
$failed = null;
$prefix = $spec['prefix'];
define('ABSPATH', $spec['root'].'/');
define('WPMU_PLUGIN_DIR', $spec['mu']);
define('WPMU_PLUGIN_URL', $spec['origin'].'/wp-content/mu-plugins');
define('DISABLE_WP_CRON', true);
define('DONOTCACHEPAGE', true);
define('WP_USE_THEMES', true);
require_once ABSPATH.'wp-includes/plugin.php';
add_filter('pre_http_request', static function() use (&$counts) { $counts['http']++; return new WP_Error('resource_fixture_network_closed','Fixture blocks external requests.'); }, PHP_INT_MAX);
add_filter('pre_wp_mail', static function() use (&$counts) { $counts['mail']++; return false; }, PHP_INT_MAX);
add_filter('redirect_canonical', '__return_false', PHP_INT_MAX);
add_filter('query', static function($sql) use (&$counts,$prefix,$trace,&$queries) {
    global $wpdb;
    if (isset($wpdb) && !empty($wpdb->last_error)) $counts['sqlErrors']++;
    $counts['queries']++;
    $kind = preg_match('/\bstp_/', $sql) ? 'secure' : (preg_match('/kiwe_search_/', $sql) ? 'search' : (str_contains($sql, $prefix.'options') ? 'options' : 'other'));
    $counts[$kind]++;
    $write = preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql);
    if ($write) {
        if (!preg_match('/^\s*(?:(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO|UPDATE|DELETE\s+FROM|(?:CREATE|ALTER|DROP|TRUNCATE)\s+(?:TEMPORARY\s+)?TABLE)\s+`?([A-Za-z0-9_]+)/i',$sql,$m) || !str_starts_with($m[1],$prefix)) throw new RuntimeException('Fixture refuses writes outside its exact prefix');
        $counts['writes']++;
        if (preg_match('/^\s*(?:CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql)) $counts['ddl']++;
    }
    if ($trace && count($queries) < 200) {
        $table = preg_match('/\b(?:FROM|INTO|UPDATE|TABLE)\s+`?([A-Za-z0-9_]+)/i',$sql,$m) ? $m[1] : 'session';
        $option = preg_match('/\boption_name\s*=\s*\x27([A-Za-z_][A-Za-z0-9_]{0,95})\x27/i',$sql,$m) ? $m[1] : null;
        $owner = 'WordPress/Bricks';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,25) as $frame) {
            $file = (string)($frame['file'] ?? '');
            if (str_contains($file,'/candidate/dsa/')) { $owner = basename($file).':'.($frame['line']??0); break; }
        }
        $queries[] = ['type'=>strtoupper(strtok(ltrim($sql),' ')),'table'=>$table,'option'=>$option,'owner'=>$owner];
    }
    return $sql;
}, PHP_INT_MAX-10);
$_GET = ['p'=>$spec['post']];
$_REQUEST = $_GET;
$_SERVER['REQUEST_URI'] = '/?p='.$spec['post'];
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_HOST'] = parse_url($spec['origin'], PHP_URL_HOST);
$_SERVER['HTTP_USER_AGENT'] = 'KiweNativeResourceFixture/1.0';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '192.0.2.230';
if ($prime) $_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
try {
    require $spec['config'];
    if ($prime) {
        // This is a PHP CLI maintenance driver, not a WP-CLI command runner.
        // Declare maintenance authority after native theme bootstrap so themes
        // do not attempt to register commands against an absent WP-CLI binary.
        define('WP_CLI', true);
        define('KIWE_SECURE_SCHEMA_MAINTENANCE', true);
        if ($spec['profile'] !== 'off') {
            if (!defined('DSA_VERSION') || DSA_VERSION !== $spec['version']) throw new RuntimeException('Frozen candidate did not boot');
            if (!DSA\Secure\SecureTrack_Db_Service::repair_database()) throw new RuntimeException('Owned security schema preparation failed');
        }
    } else {
        // wp-config for this clone is already loaded. Requiring wp-load.php
        // would load the original site's different configuration a second time.
        $wp_did_header = true;
        wp();
        require ABSPATH.'wp-includes/template-loader.php';
    }
} catch (Throwable $error) { $failed = get_class($error).':'.$error->getMessage(); }
$body = ob_get_clean();
global $wpdb;
if (isset($wpdb) && !empty($wpdb->last_error)) $counts['sqlErrors']++;
$data = ['profile'=>$spec['profile'],'version'=>defined('DSA_VERSION')?DSA_VERSION:'off','ms'=>round((hrtime(true)-$started)/1000000,3),'peak'=>memory_get_peak_usage(true),'counts'=>$counts,'bytes'=>strlen($body),'content'=>str_contains($body,'Kiwe resource acceptance fixture'),'failure'=>$failed];
if ($trace) $data['queryTrace'] = $queries;
if ($prime) { echo json_encode($data, JSON_UNESCAPED_SLASHES).PHP_EOL; exit($failed?1:0); }
header('Cache-Control: private, no-store');
if ($trace) { header('Content-Type: application/json'); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit($failed?1:0); }
header('X-Kiwe-Resource: '.base64_encode(json_encode($data,JSON_UNESCAPED_SLASHES)));
if ($failed) { http_response_code(500); echo 'Native resource fixture refused.'; } else { echo $body; }
