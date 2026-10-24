<?php
declare(strict_types=1);
// Synthetic WordPress boundaries; the real local app and Core indexing policy execute.
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/SEO_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Native_Indexing_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Utilities/Origin_Checker.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Task_Workspace_UI.php';
use DSA\Apps\SEO_App_Service as App;
use DSA\Onboarding\Native_Indexing_Service as Privacy;
class WP_Error { public function __construct(public string $code,public string $message,public array $data=[]){} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
class WP_REST_Request { public function __construct(private string $method='POST',private array $body=[],private array $headers=['x-wp-nonce'=>'wp_rest','x-kiwe-mutation'=>'1']){} public function get_method(){return $this->method;} public function get_json_params(){return $this->body;} public function get_header($k){return $this->headers[strtolower($k)]??'';} }
class WP_REST_Response { public array $headers=[]; public function __construct(public array $data,public int $status=200){} public function header($k,$v){$this->headers[$k]=$v;} }
class AppDB { public string $prefix='wp_fixture_'; public function prepare($sql,...$args){return [$sql,$args];} public function get_var($p){$GLOBALS['locks'][]=$p[0];return str_contains($p[0],'GET_LOCK')&&!$GLOBALS['lease']?0:1;} }
$wpdb=new AppDB();$mode=$argv[1]??'on';$owner=true;$lease=true;$fail=false;$admin=true;$hooks=[];$writes=[];$flushes=0;$events=[];$starts=0;$checks=[];$posts=[];$terms=[];$types=[];
$options=['saved_metadata'=>['title'=>'Preserved title','robots'=>['noindex']],'provider_settings'=>['title'=>'Rank Math title'],'saved_jobs'=>['queued'=>true]];
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
try {
 $app=new App();$app->register(function()use(&$starts){$starts++;});
 check($starts===0&&!isset($hooks['wp_head']),'MU bootstrap does not start the engine before ordinary plugins');
 if($mode==='rankmath')define('RANK_MATH_VERSION','fixture-late');
 if($mode==='yoast')define('WPSEO_VERSION','fixture-late');
 $before=$options;do_action('plugins_loaded');do_action('plugins_loaded');
 $external=in_array($mode,['rankmath','yoast'],true);$enabled=!$external&&$mode!=='off';
 check($starts===($enabled?1:0),'engine admission resolves exactly once after late provider detection');
 check(App::enabled()===$enabled&&$options===$before&&$writes===[],'ordinary admission reads only and preserves native state');
 check(isset($hooks['wp_robots'])===(!$external&&!$enabled),'only Core record privacy remains when SEO is off without a provider');
 check(!isset($hooks['rank_math/frontend/robots'])&&!isset($hooks['wp_head']),'app admission does not register provider or SEO output filters itself');
 $card=App::card();check($card['enabled']===$enabled&&($card['url']!=='')===$enabled,'off card has no unavailable engine destination');
 check($card['canChange']&&($card['externalOwner']!=='')===$external,'card reports provider ownership without replacing it');
 $owner=false;check(!App::card()['canChange']&&App::card()['expected']===''&&App::card()['endpoint']==='','staff cannot receive owner mutation controls');$before=$options;check(is_wp_error(App::change(false,App::expected()))&&$options===$before,'capability denial preserves app and metadata');$owner=true;
 $valid=new WP_REST_Request('POST',['enabled'=>!$enabled,'expected'=>App::expected()]);check($app->permission($valid),'same-origin nonce and explicit mutation proof are accepted');
 foreach([new WP_REST_Request('GET'),new WP_REST_Request('POST',[],['x-wp-nonce'=>'bad','x-kiwe-mutation'=>'1']),new WP_REST_Request('POST',[],['x-wp-nonce'=>'wp_rest'])]as$request)check(!$app->permission($request),'method nonce or mutation proof denied '.count($checks));
 $_SERVER['HTTP_ORIGIN']='https://elsewhere.test';check(!$app->permission($valid),'cross-origin app changes are denied');unset($_SERVER['HTTP_ORIGIN']);
 foreach([['enabled'=>'1','expected'=>App::expected()],['enabled'=>true,'expected'=>App::expected(),'unknown'=>true],['enabled'=>true]]as$body)check($app->save(new WP_REST_Request('POST',$body))->status===400,'malformed exact-schema app input denied '.count($checks));
 $before=$options;$lease=false;check(is_wp_error(App::change(false,App::expected()))&&$options===$before,'busy app lock refuses without a write');$lease=true;
 check(is_wp_error(App::change(false,str_repeat('0',64)))&&$options===$before,'stale card cannot overwrite a newer choice');
 if($external){check(is_wp_error(App::change(true,App::expected()))&&$options===$before,'active external owner refuses takeover with every setting preserved');check(str_starts_with(App::card()['status'],'Managed by '),'external provider is explained on the muted card');}
 else {
  $target=!$enabled;$reply=$app->save(new WP_REST_Request('POST',['enabled'=>$target,'expected'=>App::expected()]));
  check($reply->status===200&&$reply->data['app']['enabled']===$target&&$reply->data['reload']===true&&$reply->headers['Cache-Control']==='no-store, private','app save confirms native state and requires fresh lifecycle');
  check($options['saved_metadata']===$before['saved_metadata']&&$options['provider_settings']===$before['provider_settings']&&$options['saved_jobs']===$before['saved_jobs'],'toggle preserves metadata provider values and queued job history');
  check($flushes===0&&$options['kiwe_native_sitemap_rewrite_v1']==='pending','mutation defers rewrite flush beyond the old engine request');
  $app->refresh_rewrites();$app->refresh_rewrites();check($flushes===1&&$options['kiwe_native_sitemap_rewrite_v1']===($target?'2':'core'),'next administrative lifecycle refreshes rewrites exactly once');
  check(App::change($enabled,App::expected())===true&&App::enabled()===$enabled,'app can be restored through its single native option');
  $fail=true;$before=$options;check(is_wp_error(App::change(!$enabled,App::expected()))&&$options===$before,'failed native write cannot be reported as a successful toggle');$fail=false;
 }
 $p=new Privacy();$cases=[[],[Privacy::ROBOTS_META=>['noindex']],[Privacy::ROBOTS_META=>['index'],Privacy::VISIBILITY_META=>'secondary'],[Privacy::VISIBILITY_META=>'secondary'],[Privacy::ROBOTS_META=>['index','noindex']],[Privacy::ROBOTS_META=>'noindex'],[Privacy::ROBOTS_META=>'index',Privacy::VISIBILITY_META=>'secondary']];
 foreach($cases as$i=>$meta){$posts[1]=$meta;$terms[1]=$meta;$types[1]='page';$noindex=Privacy::noindex(1);check(matches($p->posts_query([], 'page')['meta_query'],$meta)===!$noindex,'native post query matches explicit privacy and precedence '.$i);check(matches($p->terms_query([], 'category')['meta_query'],$meta)===!Privacy::noindex(1,true),'native term query matches explicit privacy and precedence '.$i);}
 $existing=['relation'=>'OR',['key'=>'other','value'=>'retain','compare'=>'='],['key'=>'other','compare'=>'NOT EXISTS']];$q=$p->posts_query(['meta_query'=>$existing],'post');check($q['meta_query'][0]===$existing&&!matches($q['meta_query'],[Privacy::ROBOTS_META=>['noindex']]),'privacy constrains rather than replaces other native query filters');
 $posts[1]=[Privacy::ROBOTS_META=>['index']];$query='post';check($p->robots(['noindex'=>true])===['noindex'=>true],'explicit index cannot lift WordPress native privacy');$posts[1]=[Privacy::ROBOTS_META=>['noindex']];check($p->robots(['index'=>true])===['noindex'=>true],'Core explicit noindex removes a competing index directive');
 echo json_encode(['passed'=>true,'cleanup'=>true,'mode'=>$mode,'scope'=>'real app admission and privacy policy with synthetic native I/O; actual WordPress qualification remains separate','checks'=>$checks],JSON_UNESCAPED_SLASHES)."\n";
} catch(Throwable $e){echo json_encode(['passed'=>false,'cleanup'=>true,'mode'=>$mode,'checks'=>$checks,'error'=>$e->getMessage()])."\n";exit(1);}
