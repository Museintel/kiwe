<?php
/** Native disposable-site acceptance: process-local controls, no synthetic records or writes. */
if (PHP_SAPI!=='cli' || !defined('KIWE_WORKSPACE_TEST_SITE') || KIWE_WORKSPACE_TEST_SITE!==true || !defined('ABSPATH')) return ['passed'=>false,'cleanup'=>false];
$checks=[];$counts=[];$override=null;$guard=null;$queryCount=0;$writeAttempts=0;
$record=static function($name,$ok)use(&$checks){$checks[]=['name'=>$name,'passed'=>(bool)$ok];};
$original=get_option('stp_settings',[]);$dsaOriginal=get_option('dsa_settings',[]);$uid=get_current_user_id();$server=$_SERVER;
$digest=hash('sha256',serialize($original));
$loadedForFixture=false;
$originalOptions=$wpdb->options;$originalCache=$GLOBALS['wp_object_cache'];$temporaryOptions='kiwe81qa_'.bin2hex(random_bytes(6)).'_options';$temporaryCreated=false;
$findCallback=static function($hook,$needle){
    foreach(($GLOBALS['wp_filter'][$hook]->callbacks??[])as$callbacks)foreach($callbacks as$entry){
        if(!($entry['function'] instanceof Closure))continue;
        $r=new ReflectionFunction($entry['function']);
        if(basename($r->getFileName())!=='securetrack-core.php')continue;
        $lines=file($r->getFileName());$body=implode('',array_slice($lines,$r->getStartLine()-1,$r->getEndLine()-$r->getStartLine()+1));
        if(str_contains($body,$needle))return $entry['function'];
    }
    throw new RuntimeException('Expected native callback unavailable');
};
try{
    if(wp_using_ext_object_cache()||!str_ends_with(str_replace('\\','/',(new ReflectionClass('WP_Object_Cache'))->getFileName()),'/wp-includes/class-wp-object-cache.php'))throw new RuntimeException('Request-local native cache required');
    if($wpdb->query("CREATE TEMPORARY TABLE {$temporaryOptions} LIKE {$originalOptions}")===false)throw new RuntimeException('Temporary options unavailable');
    $temporaryCreated=true;
    if($wpdb->query("INSERT INTO {$temporaryOptions} SELECT * FROM {$originalOptions} WHERE option_name IN ('home','siteurl','stp_settings','dsa_settings')")===false)throw new RuntimeException('Temporary options seed failed');
    $wpdb->options=$temporaryOptions;$GLOBALS['wp_object_cache']=new WP_Object_Cache();
    // The disposable lab normally disables the module. Load its installed code only in this CLI process.
    if(!function_exists('stp_cfg')){require_once DSA_DIR.'includes/Secure/securetrack-core.php';$loadedForFixture=true;}
    $record('Expected candidate installed',DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION);
    $record('PHP 8.2 or newer',PHP_VERSION_ID>=80200);
    $record('Debug remains off',!WP_DEBUG&&!WP_DEBUG_LOG&&!WP_DEBUG_DISPLAY);
    $record('SecureTrack engine remains loaded',function_exists('stp_cfg')&&function_exists('stp_waf_guard'));
    $record('PhoneKey and passkey verification remain available',function_exists('pk_log')&&function_exists('pk_ip')&&function_exists('pk_rest_webauthn_login_verify')&&function_exists('pk_rest_webauthn_register_verify'));
    $record('IP trust functions remain available',function_exists('stp_trust_ip')&&function_exists('stp_ip_status_is_trusted')&&function_exists('stp_user_profile_knows_ip'));
    $visitor=$findCallback('template_redirect','last_template_redirect');$footer=$findCallback('wp_footer','__stpTimeTrack');
    wp_set_current_user(0);
    $_SERVER['REMOTE_ADDR']='192.0.2.250';unset($_SERVER['HTTP_CF_CONNECTING_IP'],$_SERVER['HTTP_X_FORWARDED_FOR'],$_SERVER['HTTP_X_REAL_IP']);
    $flags=['track_visitors'=>0,'track_pages'=>0];
    $override=static function($value)use(&$flags){return array_replace((array)$value,$flags);};
    add_filter('option_stp_settings',$override,PHP_INT_MAX);
    $guard=static function($sql)use(&$queryCount,&$writeAttempts){
        $queryCount++;
        if(preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$sql)){$writeAttempts++;throw new RuntimeException('Unexpected database write');}
        return $sql;
    };
    foreach([[0,0],[0,1],[1,0]]as[$visitors,$pages]){
        $flags=['track_visitors'=>$visitors,'track_pages'=>$pages];stp_cfg(true);
        $record("Native flags take effect ($visitors/$pages)",(int)stp_cfg()['track_visitors']===$visitors&&(int)stp_cfg()['track_pages']===$pages);
        $queryCount=0;add_filter('query',$guard,PHP_INT_MAX);
        if(!$visitors){
            for($i=0;$i<1000;$i++)DSA\Secure\SecureTrack_Event_Service::log('page_view',['url'=>home_url('/synthetic-telemetry')]);
            for($i=0;$i<1000;$i++)$visitor();
            $record("Native disabled visitor/event loops execute zero SQL ($visitors/$pages)",$queryCount===0);
        }
        ob_start();$footer();$html=ob_get_clean();
        $record("Native disabled public timing has no beacon ($visitors/$pages)",!str_contains($html,'__stpTimeTrack'));
        $record("Native disabled public timing executes zero SQL ($visitors/$pages)",$queryCount===0);
        $counts["optional-$visitors-$pages"]=$queryCount;
        remove_filter('query',$guard,PHP_INT_MAX);
    }
    $queryCount=0;add_filter('query',$guard,PHP_INT_MAX);
    $record('SecureTrack and PhoneKey still resolve the native client IP',stp_get_ip()==='192.0.2.250'&&pk_ip()==='192.0.2.250');
    $counts['ip-resolution']=$queryCount;
    $before=$queryCount;$trust=stp_ip_status_is_trusted('192.0.2.250');
    $record('Unknown synthetic IP is not automatically trusted',$trust===false);
    $record('IP trust lookup retains bounded read path',$queryCount-$before<=2);
    $counts['ip-trust']=$queryCount-$before;
    $record('Telemetry acceptance attempted no database writes',$writeAttempts===0);
    remove_filter('query',$guard,PHP_INT_MAX);
}catch(Throwable $e){$record('Native acceptance completed without errors',false);}
finally{
    if($guard)remove_filter('query',$guard,PHP_INT_MAX);
    if($override)remove_filter('option_stp_settings',$override,PHP_INT_MAX);
    $wpdb->options=$originalOptions;$GLOBALS['wp_object_cache']=$originalCache;
    $temporaryRemoved=!$temporaryCreated||$wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temporaryOptions}")!==false;
    $_SERVER=$server;wp_set_current_user($uid);
    $preserved=hash('sha256',serialize(get_option('stp_settings',[])))===$digest&&get_option('dsa_settings',[])===$dsaOriginal;
    $record('Original native settings preserved',$preserved);
    $record('Temporary options removed and original cache restored',$temporaryRemoved&&$GLOBALS['wp_object_cache']===$originalCache);
}
return ['passed'=>$preserved&&$temporaryRemoved&&!in_array(false,array_column($checks,'passed'),true),'cleanup'=>$preserved&&$temporaryRemoved,'checks'=>$checks,'queryCounts'=>$counts,'writeAttempts'=>$writeAttempts,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'secureTrackLoadedOnlyForFixture'=>$loadedForFixture,'scope'=>'Native callbacks after WordPress bootstrap, using temporary native options and request-local cache, with module code loaded only in this CLI process when the lab disables it; not concurrent visitor capacity or a physical passkey login.'];
