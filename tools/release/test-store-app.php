<?php
declare(strict_types=1);
// Synthetic WordPress boundaries; the real local app and Core indexing policy execute.
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Commerce_App_Service.php';
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Utilities/Origin_Checker.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Task_Workspace_UI.php';
use DSA\Apps\Store_App_Service as App;
class WP_Error { public function __construct(public string $code,public string $message,public array $data=[]){} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
class WP_REST_Request { public function __construct(private string $method='POST',private array $body=[],private array $headers=['x-wp-nonce'=>'wp_rest','x-kiwe-mutation'=>'1']){} public function get_method(){return $this->method;} public function get_json_params(){return $this->body;} public function get_header($k){return $this->headers[strtolower($k)]??'';} }
class WP_REST_Response { public array $headers=[]; public function __construct(public array $data,public int $status=200){} public function header($k,$v){$this->headers[$k]=$v;} }
class AppDB { public string $prefix='wp_fixture_'; public function prepare($sql,...$args){return [$sql,$args];} public function get_var($p){$GLOBALS['locks'][]=$p[0];return str_contains($p[0],'GET_LOCK')&&!$GLOBALS['lease']?0:1;} }
$wpdb=new AppDB();$mode=$argv[1]??'default';$owner=true;$lease=true;$fail=false;$admin=true;$hooks=[];$writes=[];$flushes=0;$events=[];$starts=0;$checks=[];$posts=[];$terms=[];$types=[];
$cleared=[];
$options=['store_settings'=>['tax'=>'unchanged','shipping'=>'unchanged','guest_checkout'=>'yes'],'active_plugins'=>['woocommerce/woocommerce.php'],'orders'=>['completed'=>4],'wishlist'=>[['id'=>5,'type'=>'wishlist']],'saved_metadata'=>['title'=>'Preserved title','robots'=>['noindex']],'provider_settings'=>['title'=>'Rank Math title'],'saved_jobs'=>['queued'=>true]];
if($mode==='off')$options[App::OPTION]='0';
if($mode==='on')$options[App::OPTION]='1';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks'][$label]=true;}
function __($v,$domain=''){return $v;}
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['options'])?$GLOBALS['options'][$k]:$d;}
function update_option($k,$v,$autoload=null){$GLOBALS['writes'][]=[$k,$v,$autoload];if($GLOBALS['fail'])return false;$GLOBALS['options'][$k]=$v;return true;}
function wp_cache_delete($k,$g=''){return true;}
function current_user_can($c,...$a){return $GLOBALS['owner']&&in_array($c,[App::CAPABILITY],true);}
function wp_json_encode($v){return json_encode($v);}
function wp_create_nonce($a){return $a;}function wp_verify_nonce($n,$a){return $n===$a;}
function wp_unslash($v){return $v;}function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_parse_url($v){return parse_url($v);}function home_url($p='/'){return 'https://site.test'.$p;}function site_url($p='/'){return home_url($p);}function admin_url($p){return 'https://site.test/wp-admin/'.$p;}function rest_url($p){return 'https://site.test/wp-json/'.$p;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);}function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}
function add_action($h,$cb,$p=10,$a=1){$GLOBALS['hooks'][$h][]=['callback'=>$cb,'priority'=>$p,'args'=>$a];}function add_filter(...$a){add_action(...$a);}
function do_action($h,...$a){$GLOBALS['events'][]=$h;$list=$GLOBALS['hooks'][$h]??[];usort($list,fn($a,$b)=>$a['priority']<=>$b['priority']);foreach($list as $entry)($entry['callback'])(...array_slice($a,0,$entry['args']));}
function register_rest_route($ns,$path,$args){$GLOBALS['routes'][$path]=$args;}
function is_wp_error($v){return $v instanceof WP_Error;}function flush_rewrite_rules($hard){check($hard===false,'rewrite refresh never writes server configuration');$GLOBALS['flushes']++;}
function is_admin(){return $GLOBALS['admin'];}function wp_doing_ajax(){return false;}function wp_doing_cron(){return false;}
function get_post_meta($id,$k,$single){return $GLOBALS['posts'][$id][$k]??'';}function get_term_meta($id,$k,$single){return $GLOBALS['terms'][$id][$k]??'';}function get_post_type($id){return $GLOBALS['types'][$id]??'post';}
function is_category(){return $GLOBALS['query']==='term';}function is_tag(){return false;}function is_tax(){return false;}function is_singular(){return $GLOBALS['query']==='post';}function get_queried_object_id(){return $GLOBALS['queried']??1;}
function matches(array $q,array $meta):bool { if(isset($q['key'])){$has=array_key_exists($q['key'],$meta);$v=$has?(is_array($meta[$q['key']])?serialize($meta[$q['key']]):(string)$meta[$q['key']]):'';$needle=(string)($q['value']??'');return match($q['compare']??'='){'NOT EXISTS'=>!$has,'!='=>$has&&$v!==$needle,'LIKE'=>$has&&str_contains($v,$needle),'NOT LIKE'=>$has&&!str_contains($v,$needle),'='=>$has&&$v===$needle,default=>throw new RuntimeException('unsupported comparison')};}$results=[];foreach($q as $key=>$child)if(is_array($child))$results[]=matches($child,$meta);return ($q['relation']??'AND')==='OR'?in_array(true,$results,true):!in_array(false,$results,true); }
function wp_clear_scheduled_hook($hook){$GLOBALS['cleared'][]=$hook;return 1;}
try {
 $app=new App();$before=$options;$app->register();
 check(!App::enabled()&&$writes===[],'MU bootstrap cannot assume Woo is already loaded');
 if($mode!=='missing')eval('function WC(){return (object)[];}');
 $enabled=$mode!=='missing'&&$mode!=='off';
 check(App::enabled()===$enabled&&$options===$before,'existing Woo defaults on and missing Woo stays off without a migration write');
 $card=App::card();check($card['enabled']===$enabled&&$card['blocked']===($mode==='missing'),'card exposes actual app and dependency states');
 $owner=false;check(!App::card()['canChange']&&App::card()['expected']===''&&App::card()['endpoint']==='','staff has no activation controls or write receipt');
 check(is_wp_error(App::change(false,App::expected()))&&$options===$before,'unauthorized toggle preserves all state');$owner=true;
 $valid=new WP_REST_Request('POST',['enabled'=>!$enabled,'expected'=>App::expected()]);check($app->permission($valid),'native nonce and explicit same-origin mutation admitted');
 foreach([new WP_REST_Request('GET'),new WP_REST_Request('POST',[],['x-wp-nonce'=>'bad','x-kiwe-mutation'=>'1']),new WP_REST_Request('POST',[],['x-wp-nonce'=>'wp_rest'])]as$request)check(!$app->permission($request),'method nonce or mutation proof denied '.count($checks));
 $_SERVER['HTTP_ORIGIN']='https://elsewhere.test';check(!$app->permission($valid),'cross-origin request denied');unset($_SERVER['HTTP_ORIGIN']);
 foreach([['enabled'=>'1','expected'=>App::expected()],['enabled'=>true,'expected'=>App::expected(),'unknown'=>true],['enabled'=>true]]as$body)check($app->save(new WP_REST_Request('POST',$body))->status===400,'exact boolean/input schema denied '.count($checks));
 $lease=false;check(is_wp_error(App::change(false,App::expected()))&&$options===$before,'busy writer denies without mutation');$lease=true;
 check(is_wp_error(App::change(false,str_repeat('0',64)))&&$options===$before,'stale card cannot replace current state');
 if($mode==='missing')check(is_wp_error(App::change(true,App::expected()))&&$options===$before&&$cleared===[],'missing Woo refuses activation without software installation or data changes');
 else {
  $target=!$enabled;$reply=$app->save(new WP_REST_Request('POST',['enabled'=>$target,'expected'=>App::expected()]));
  check($reply->status===200&&$reply->data['app']['enabled']===$target&&$reply->data['reload']===true&&$reply->headers['Cache-Control']==='no-store, private','toggle confirms private native state and requires a fresh lifecycle');
  $keep=$options;unset($keep[App::OPTION]);$original=$before;unset($original[App::OPTION]);check($keep===$original,'store settings, products, orders, wishlists, SEO and plugin choices remain exact');
  check($target?$cleared===[]:$cleared===['dsa_abandoned_cart_maintenance','dsa_co_purchase_daily_sync','dsa_bestseller_daily_sync'],'only explicit off retires the three Store maintenance schedules');
  check(App::change($enabled,App::expected())===true&&App::enabled()===$enabled,'single native choice can restore original app state');
  $fail=true;$before=$options;$beforeClears=$cleared;check(is_wp_error(App::change(!$enabled,App::expected()))&&$options===$before&&$cleared===$beforeClears,'failed write is not reported as success or allowed to clear schedules');$fail=false;
 }
 check($flushes===0&&!isset($hooks['wp_head'])&&!isset($hooks['woocommerce_add_to_cart']),'control layer does not own SEO output, rewrite or commerce engine hooks');
 echo json_encode(['passed'=>true,'cleanup'=>true,'mode'=>$mode,'checks'=>$checks,'scope'=>'real Store app writer and dependency/permission rules with synthetic WordPress I/O'])."\n";
}catch(Throwable $e){echo json_encode(['passed'=>false,'cleanup'=>true,'mode'=>$mode,'checks'=>$checks,'error'=>$e->getMessage()])."\n";exit(1);}
