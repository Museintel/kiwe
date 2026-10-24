<?php
// Disposable WordPress only. No persistent configuration changes or outbound work.
if (PHP_SAPI !== 'cli' || !defined('KIWE_WORKSPACE_TEST_SITE') || KIWE_WORKSPACE_TEST_SITE !== true || !defined('ABSPATH')) return ['passed'=>false,'cleanup'=>false];
$checks = []; $ids = []; $original = get_option(DSA_OPTION_SETTINGS, []);
$record = static function ($name, $passed) use (&$checks) { $checks[] = ['name'=>$name,'passed'=>(bool)$passed]; };
$deny = static fn() => false;
$mail = static fn() => true;
$http = static fn() => new WP_Error('synthetic_outbound_disabled');
$transition = $GLOBALS['wp_filter']['transition_post_status'] ?? null;
remove_all_actions('transition_post_status');
add_filter('pre_schedule_event', $deny, PHP_INT_MAX);
add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
add_filter('pre_http_request', $http, PHP_INT_MAX);
$override = null;
try {
    $record('Expected candidate installed', DSA_VERSION === KIWE_WORKSPACE_EXPECTED_VERSION);
    $record('All debug flags remain off', !WP_DEBUG && !WP_DEBUG_LOG && !WP_DEBUG_DISPLAY);
    foreach ([true,false] as $enabled) foreach ([true,false] as $pwa) foreach ([true,false] as $sw) {
        $override = static function ($value) use ($enabled,$pwa,$sw) { $value['enabled']=$enabled; $value['permissions']['pwa_enabled']=$pwa; $value['service_worker']=$sw; return $value; };
        add_filter('option_'.DSA_OPTION_SETTINGS, $override, PHP_INT_MAX);
        $service = new DSA\PWA\PWA_Service(new DSA\Settings()); $config = $service->public_config();
        $record('PWA flags '.(int)$enabled.(int)$pwa.(int)$sw, $config['enabled']===$enabled && $config['serviceWorkerEnabled']===$enabled && (bool)$config['serviceWorkerUrl']===$enabled);
        $record('Stable native scope '.(int)$enabled.(int)$pwa.(int)$sw, $config['cachePrefix']==='kiwe-pwa-v2-'.substr(hash('sha256',home_url('/')),0,20).'-' && $config['scope']===trailingslashit(wp_parse_url(home_url('/'),PHP_URL_PATH)));
        remove_filter('option_'.DSA_OPTION_SETTINGS,$override,PHP_INT_MAX); $override=null;
    }
    $registry = new DSA\Element_Registry(new DSA\Settings());
    $editorial = new DSA\Runtime\Editorial_Fragment_Service($registry);
    foreach (['public','draft','password'] as $kind) {
        $id = wp_insert_post(['post_type'=>'post','post_title'=>'Kiwe RC77 native '.$kind,'post_content'=>'<p>Synthetic offline boundary.</p>','post_status'=>$kind==='draft'?'draft':'publish','post_password'=>$kind==='password'?'synthetic-only':''],true);
        if (is_wp_error($id) || !$id) throw new RuntimeException('Synthetic post creation failed'); $ids[]=$id;
        // Simulate an already-unlocked password without a real customer's cookie.
        add_filter('post_password_required',$deny,PHP_INT_MAX);
        $result = $editorial->offline_document(get_permalink($id));
        remove_filter('post_password_required',$deny,PHP_INT_MAX);
        $record('Offline content boundary '.$kind, $kind==='public' ? !empty($result['offlineReady']) : empty($result['offlineReady']));
    }
} catch (Throwable $error) { $record('Native fixture completed', false); }
finally {
    if ($override) remove_filter('option_'.DSA_OPTION_SETTINGS,$override,PHP_INT_MAX);
    remove_filter('post_password_required',$deny,PHP_INT_MAX);
    foreach ($ids as $id) wp_delete_post($id,true);
    $cleanup = !array_filter($ids,static fn($id)=>get_post($id));
    $record('Native settings preserved', get_option(DSA_OPTION_SETTINGS,[])===$original);
    if ($transition) $GLOBALS['wp_filter']['transition_post_status']=$transition;
    remove_filter('pre_schedule_event',$deny,PHP_INT_MAX); remove_filter('pre_wp_mail',$mail,PHP_INT_MAX); remove_filter('pre_http_request',$http,PHP_INT_MAX);
}
return ['passed'=>$cleanup&&!in_array(false,array_column($checks,'passed'),true),'cleanup'=>$cleanup,'checks'=>$checks];
