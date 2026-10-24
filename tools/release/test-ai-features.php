<?php
declare(strict_types=1);

namespace DSA {
 final class Settings {
  public function defaults(): array {return ['ai'=>[]];}
  public ?array $cached=null;
  public function get($key,$default=[]) {return ($this->cached??$GLOBALS['options']['dsa_settings'])[$key]??$default;}
 }
}
namespace DSA\Onboarding { final class Onboarding_Service {public static function section_slugs(): array {return ['kiwe-business'];}} }
namespace DSA\Utilities {
 final class Atomic_Rate_Limiter {public static int $calls=0;public static function allow(...$args): bool {self::$calls++;return true;}}
}
namespace DSA\AI {
 // No network: the actual broker, policy, workspace and native allowance reader execute below.
 final class AI_Provider_Service {
  public static int $calls=0;
  public function __construct(...$args) {}
  public function status(): array {return ['provider'=>'gemini','model'=>'gemini-3.7-flash','enabled'=>true,'configured'=>true,'keyStatus'=>['status'=>'ok'],'private'=>'synthetic-provider-secret'];}
  public function generate(array $input): array {self::$calls++;$readiness=str_contains((string)($input['system']??''),'provider readiness check');return ['ok'=>true,'called'=>true,'provider'=>'gemini','model'=>'gemini-3.7-flash','output'=>$readiness?'{"ready":true}':'{"score":1,"label":"clean"}','responseMeta'=>['attempts'=>1]];}
 }
}
namespace {
require __DIR__.'/workspace-test-bootstrap.php';
$inc=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';
require $inc.'AI/AI_Feature_Policy.php';
require $inc.'AI/AI_Allowance_Service.php';
require $inc.'AI/AI_Broker_Service.php';
require $inc.'Utilities/Origin_Checker.php';
require $inc.'Access/Task_Workspace_UI.php';
require $inc.'Onboarding/SEO_Context_Service.php';
require $inc.'Admin/Admin.php';
use DSA\AI\AI_Feature_Policy as Policy;
use DSA\AI\AI_Broker_Service as Broker;
use DSA\AI\AI_Provider_Service as Transport;
use DSA\Access\AI_Workspace_Service as Workspace;
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Utilities\Atomic_Rate_Limiter as Limiter;

$option_writes=[];$fail_option=false;$filter=null;$styles=[];$hooks=[];$menu_entry=[];
$users[7]=new WP_User(7,['shop_manager']);
function update_option($key,$value,$autoload=null){$GLOBALS['option_writes'][]=[$key,$value,$autoload];if($GLOBALS['fail_option'])return false;$GLOBALS['options'][$key]=$value;return true;}
function apply_filters($name,$value){return is_callable($GLOBALS['filter'])?($GLOBALS['filter'])($name,$value):$value;}
function esc_attr($s){return esc_html($s);} function esc_attr__($s,$d=''){return esc_attr($s);} function esc_url($s){return esc_attr($s);} function esc_url_raw($s){return (string)$s;}
function wp_nonce_field($nonce){echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';}
function add_action($name,...$args){$GLOBALS['hooks'][]=$name;}
function add_menu_page(...$args){$GLOBALS['menu_entry']=$args;return 'toplevel_page_'.Workspace::PAGE;}
function wp_enqueue_style(...$args){$GLOBALS['styles'][]=$args;}
function wp_json_encode($value){return json_encode($value);}
class WP_REST_Request {public function __construct(private string $method='GET',private array $body=[],private array $headers=['X-WP-Nonce'=>'wp_rest','x-kiwe-mutation'=>'1']){}public function get_method(){return $this->method;}public function get_header($key){return $this->headers[$key]??'';}public function get_json_params(){return $this->body;}}
class WP_REST_Response {public array $headers=[];public function __construct(public array $data,public int $status=200){}public function header($name,$value){$this->headers[$name]=$value;}}
function wp_parse_url($url){return parse_url($url);}function site_url($path='/'){return home_url($path);}
function register_rest_route($namespace,$route,$args){$GLOBALS['rest_routes'][$route]=$args;}
class FeatureReadDB {public string $options='wp_options',$prefix='wp_'; public string $last_error='';public function prepare($sql,...$args){return $sql;}public function get_var($sql){return str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK')?1:null;}}
function do_action($name,...$args){$GLOBALS['app_events'][]=$name;}
$wpdb=new FeatureReadDB();
$policy=new Policy();$workspace=new Workspace();$broker=new Broker(new DSA\Settings());
$options['dsa_settings']=['ai'=>['native_api_key'=>'synthetic-stored-secret','native_base_url'=>'https://private.example.test','sitegraph_ai_enabled'=>true,'studio_enabled'=>true,'allow_native_generation'=>false,'request_allowance'=>['minute'=>2,'day'=>30,'month'=>300]]];
$request=['service'=>'seo','capability'=>'propose_batch','user'=>'Synthetic public content'];
function rendered($workspace): string {ob_start();try{$workspace->render();return ob_get_contents();}finally{ob_end_clean();}}
function submitted($workspace,array $post): bool {$_SERVER['REQUEST_METHOD']='POST';$_POST=$post;try{$workspace->handle();return false;}catch(WorkspaceRedirect $e){return true;}}
function preference($feature,$enabled): array {return ['action'=>Workspace::ACTION,'feature'=>$feature,'enabled'=>$enabled,'_wpnonce'=>Workspace::ACTION.'_'.$feature];}

check(current_user_can(Policy::CAPABILITY)&&!current_user_can('manage_options'),'client can choose AI without gaining technical settings');
check(Policy::choices($options['dsa_settings']['ai'])['seo']===['ownerAllowed'=>false,'clientEnabled'=>false,'enabled'=>false],'older SiteGraph switch does not grant SEO permission or opt-in');
check($broker->request($request)['reason']==='service_disabled'&&Transport::$calls===0&&Limiter::$calls===0&&$option_writes===[],'default-off request stops before rate accounting, audit and transport');
check(is_wp_error($policy->set_preference('seo',true))&&$option_writes===[],'client cannot opt in without owner permission');
$options['dsa_settings']['ai']['seo_enabled']=true;
check(!$broker->status('seo')['profile']['enabled'],'owner permission alone does not opt client in');
$original_settings=$options['dsa_settings'];
check(submitted($workspace,preference('seo','1')),'client POST confirms preference through native option readback');
check(get_option('kiwe_ai_opt_in_seo_v1')==='1'&&$option_writes[0]===['kiwe_ai_opt_in_seo_v1','1',false]&&$options['dsa_settings']===$original_settings,'preference writes only its non-autoloaded native option, preserving credentials and allowance');
$options['dsa_settings']['ai']['sitegraph_ai_enabled']=false;
check($broker->request($request)['called']&&Transport::$calls===1&&!$broker->status('sitegraph')['profile']['enabled'],'SEO reaches transport with independent permission while SiteGraph stays off');
check(!$broker->status('securetrack')['profile']['enabled'],'SEO opt-in does not enable SecureTrack');
$options['dsa_settings']['ai']['securetrack_brief_enabled']=true;
check($policy->set_preference('securetrack',true)===true&&get_option('kiwe_ai_opt_in_seo_v1')==='1','saving SecureTrack preserves existing SEO preference');
check($broker->request(['service'=>'securetrack','capability'=>'classify_security','user'=>'Synthetic hashed signals'])['ok'],'SecureTrack reaches broker transport only after both choices');
$calls=Transport::$calls;$rate=Limiter::$calls;
$options['dsa_settings']['ai']['seo_enabled']=false;
check($broker->request($request)['reason']==='service_disabled'&&Transport::$calls===$calls&&Limiter::$calls===$rate&&get_option('kiwe_ai_opt_in_seo_v1')==='1','owner revocation blocks subsequent requests while retaining client preference');
$filter=static function($name,$profiles){$profiles['seo']['enabled']=true;$profiles['securetrack']['enabled']=true;return $profiles;};
check(!$broker->status('seo')['profile']['enabled'],'extension filter cannot lift revoked built-in permission');
check($policy->set_preference('seo',false)===true&&get_option('kiwe_ai_opt_in_seo_v1')==='0','client can switch off a remembered preference after owner revocation');
$options['dsa_settings']['ai']['seo_enabled']=true;
check(!$broker->status('seo')['profile']['enabled'],'filter cannot bypass an off client choice');
update_option('kiwe_ai_opt_in_seo_v1','1',false);
check($broker->status('seo')['profile']['enabled'],'native option edits are reflected by the broker projection');
$filter=static function($name,$profiles){$profiles['seo']['enabled']=false;return $profiles;};
check(!$broker->status('seo')['profile']['enabled'],'extension can further restrict an allowed feature');
$filter=null;
foreach(['yes',[],new stdClass(),2] as $bad){$options['kiwe_ai_opt_in_seo_v1']=$bad;check(!$broker->status('seo')['profile']['enabled'],'malformed preference fails closed: '.get_debug_type($bad));}
$options['kiwe_ai_opt_in_seo_v1']='0';
$options['dsa_settings']['ai']['seo_enabled']='false';
check(is_wp_error($policy->set_preference('seo',true)),'truthy malformed owner state cannot grant permission');
$options['dsa_settings']['ai']['seo_enabled']=true;
$before=$options;$writes_before=count($option_writes);
foreach([['feature'=>'native_api_key'],['enabled'=>['1']],['enabled'=>'true'],['feature'=>['seo']],['_wpnonce'=>Workspace::ACTION.'_securetrack'],['_wpnonce'=>'']] as $bad){check(denied(fn()=>submitted($workspace,array_replace(preference('seo','1'),$bad))),'invalid or cross-feature POST is denied: '.array_key_first($bad));}
check($options===$before&&count($option_writes)===$writes_before,'invalid submissions cannot write arbitrary settings or preferences');
$_POST=preference('seo','1');$_SERVER['REQUEST_METHOD']='GET';
check(denied(fn()=>$workspace->handle()),'GET cannot mutate preferences');
foreach([3,4,5,6,7] as $id){$actor=$id;check(!current_user_can(Policy::CAPABILITY)&&is_wp_error($policy->set_preference('seo',true))&&denied(fn()=>submitted($workspace,preference('seo','1')))&&denied(fn()=>$workspace->render()),'restricted role denied direct service, handler and render: '.$users[$id]->roles[0]);}
$actor=1;$fail_option=true;
check(is_wp_error($policy->set_preference('seo',true))&&get_option('kiwe_ai_opt_in_seo_v1')==='0','failed native write produces no successful save');
$fail_option=false;
check($policy->set_preference('seo',false)===true,'idempotent native preference is confirmed');
$html=rendered($workspace);
check(str_contains($html,'AI requests are paused in website setup')&&str_contains($html,'0 of 300 requests used'),'client sees global pause and aggregate allowance without a provider call');
check(!str_contains($html,'synthetic-')&&!str_contains($html,'private.example.test')&&!str_contains($html,'Website manager:')&&!str_contains($html,'name="ai['),'client rendering excludes credentials, provider status and technical fields');
$options['dsa_settings']['ai']['allow_native_generation']=true;
check(!str_contains(rendered($workspace),'AI requests are paused in website setup'),'client global pause reads the original allow_native_generation control');
$wpdb->last_error='synthetic storage failure';
check(str_contains(rendered($workspace),'Usage is temporarily unavailable'),'unavailable ledger never displays fabricated zero usage');
$wpdb->last_error='';
$workspace->register();$workspace->menu();$workspace->assets('other_page');
check($styles===[]&&!in_array('admin_post_nopriv_'.Workspace::ACTION,$hooks,true),'no public handler or unrelated-screen assets');
$workspace->assets('toplevel_page_'.Workspace::PAGE);
check(count($styles)===1&&$menu_entry[2]===Policy::CAPABILITY,'one scoped shared stylesheet and dedicated menu capability');
$guard=new ReflectionMethod($access,'guard_client_route');$pagenow='admin.php';$_GET=['page'=>Workspace::PAGE];$_REQUEST=[];
check(!denied(fn()=>$guard->invoke($access,'administrator')),'client route guard admits AI workspace');
$pagenow='admin-post.php';$_POST=preference('seo','0');
check(!denied(fn()=>$guard->invoke($access,'administrator')),'client route guard admits scoped AI action');
$pagenow='admin.php';$_GET=['page'=>'kiwe-ai'];
check(denied(fn()=>$guard->invoke($access,'administrator')),'client route guard still denies technical AI setup');
$actor=2;
check(current_user_can(Policy::CAPABILITY)&&str_contains(rendered($workspace),'Website manager: open AI setup'),'technical owner can use the same client editing home');
$actor=1;$options[Owner::OPTION]['enabled']=false;$users[1]->allcaps=['manage_options'=>true];
check(current_user_can(Policy::CAPABILITY),'native administrator retains AI choices when owner policy is disabled');
$options[Owner::OPTION]['enabled']=true;$users[1]->allcaps=[];
$admin=(new ReflectionClass(DSA\Admin\Admin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($admin,'settings'))->setValue($admin,new DSA\Settings());
$sanitize=new ReflectionMethod($admin,'sanitize_ai_settings');
$current=['seo_enabled'=>true,'sitegraph_ai_enabled'=>true,'native_api_key'=>'synthetic-stored-secret'];
check($sanitize->invoke($admin,[],$current)['seo_enabled'],'unrelated AI partial save preserves SEO permission');
$saved=$sanitize->invoke($admin,['seo_permission_present'=>'1'],$current);
check(!$saved['seo_enabled']&&$saved['sitegraph_ai_enabled']&&$saved['native_api_key']===$current['native_api_key'],'owner checkbox can revoke SEO without changing SiteGraph permission or saved key');
$saved=$sanitize->invoke($admin,['seo_permission_present'=>'1','seo_enabled'=>'1'],[]);
check($saved['seo_enabled']&&!$saved['sitegraph_ai_enabled'],'owner can enable SEO independently using original sanitizer');
check(Transport::$calls===$calls,'rendering and preference changes never trigger provider requests');
$current['token_allowance']=['minute'=>123,'day'=>456,'month'=>789];
$token_saved=$sanitize->invoke($admin,[],$current);
check($token_saved['token_allowance']===$current['token_allowance'],'unrelated owner AI save preserves token ceilings');
$token_saved=$sanitize->invoke($admin,['token_allowance'=>['minute'=>'0','day'=>[]]],$current);
check($token_saved['token_allowance']===['minute'=>0,'day'=>0,'month'=>789],'owner token controls pause malformed or zero values and preserve absent fields');
$html=rendered($workspace);
check(str_contains($html,'token units used or reserved')&&!str_contains($html,'name="ai[token_allowance]'),'client projects token usage without writable budget controls');
define('DSA_OPTION_SETTINGS','dsa_settings');
function wp_cache_delete($key,$group){$GLOBALS['invalidations'][]=$key;}
$options['dsa_settings']['ai']['seo_enabled']=true;$options['kiwe_ai_opt_in_seo_v1']='1';
$cached=new DSA\Settings();$cached->cached=$options['dsa_settings'];$long=new Broker($cached);
$options['dsa_settings']['ai']['seo_enabled']=false;
check($long->status('seo')['profile']['enabled']&&!$long->status('seo',true)['profile']['enabled'],'long-running broker refresh discards cached owner permission');
check(in_array('kiwe_ai_opt_in_seo_v1',$invalidations,true)&&in_array('dsa_settings',$invalidations,true)&&in_array('alloptions',$invalidations,true),'policy refresh invalidates native and feature option caches');
define('KIWE_REACT_WORKSPACE',true);
$workspace->routes();
check(isset($rest_routes['/workspace/ai'],$rest_routes['/workspace/ai/preference'],$rest_routes['/workspace/ai/owner'],$rest_routes['/workspace/ai/provider-check']),'native AI app exposes private read, preference, owner and readiness routes');
$actor=1;$options['dsa_settings']['ai']['allow_native_generation']=false;
$read=new WP_REST_Request();$snapshot=$workspace->read_app($read);
check($snapshot->status===200&&$snapshot->data['schema']==='kiwe.ai-workspace.v1'&&$snapshot->data['globalPaused']===true&&$snapshot->data['ownerSetupUrl']==='','client sees owner gate and allowance without setup link');
check(!str_contains(json_encode($snapshot->data),'synthetic-stored-secret')&&!str_contains(json_encode($snapshot->data),'private.example.test'),'native app response contains no provider credential or URL');
$save=new WP_REST_Request('POST',['feature'=>'seo','enabled'=>false]);
check($workspace->save_app($save)->status===200&&get_option('kiwe_ai_opt_in_seo_v1')==='0','native app saves through the existing feature policy');
$site_view=$workspace->read_app($read)->data;
check($site_view['siteChoices']===['seo'=>true,'ai'=>true],'existing websites retain explicit SEO and AI setup choices');
$provider_calls=Transport::$calls;
check($workspace->save_app(new WP_REST_Request('POST',['feature'=>'site_ai','enabled'=>false,'expected'=>$site_view['siteExpected']]))->status===200&&!Policy::site_choices()['ai']&&Transport::$calls===$provider_calls,'client AI switch pauses all provider work without calling provider');
check($workspace->save_app(new WP_REST_Request('POST',['feature'=>'site_ai','enabled'=>true,'expected'=>$site_view['siteExpected']]))->status===409,'stale website choice cannot overwrite a newer AI setting');
$site_view=$workspace->read_app($read)->data;
check($workspace->save_app(new WP_REST_Request('POST',['feature'=>'site_ai','enabled'=>true,'expected'=>$site_view['siteExpected']]))->status===200&&Policy::site_choices()['ai'],'client can restore AI choice without changing owner settings');
$site_view=$workspace->read_app($read)->data;
check($workspace->save_app(new WP_REST_Request('POST',['feature'=>'site_seo','enabled'=>false,'expected'=>$site_view['siteExpected']]))->status===200&&!Policy::seo_enabled(),'SEO switch pauses Kiwe SEO without deleting saved feature preferences');
$site_view=$workspace->read_app($read)->data;
check($workspace->save_app(new WP_REST_Request('POST',['feature'=>'site_seo','enabled'=>true,'expected'=>$site_view['siteExpected']]))->status===200&&Policy::seo_enabled(),'client can restore Kiwe SEO choice');
$before=$options;$writes_before=count($option_writes);
foreach([['feature'=>'native_api_key','enabled'=>true],['feature'=>'seo','enabled'=>'1'],['feature'=>['seo'],'enabled'=>true]] as $body)check($workspace->save_app(new WP_REST_Request('POST',$body))->status===400,'native app rejects malformed preference');
check($options===$before&&count($option_writes)===$writes_before,'malformed native app mutations preserve settings');
check(!$workspace->app_permission(new WP_REST_Request('POST',['feature'=>'seo','enabled'=>true],['X-WP-Nonce'=>'wrong','x-kiwe-mutation'=>'1'])),'native app denies invalid REST nonce');
check(!$workspace->app_permission(new WP_REST_Request('POST',['feature'=>'seo','enabled'=>true],['X-WP-Nonce'=>'wp_rest'])),'native app requires mutation proof');
$_SERVER['HTTP_ORIGIN']='https://elsewhere.test';check(!$workspace->app_permission($save),'native app denies cross-site origin');unset($_SERVER['HTTP_ORIGIN']);
$actor=6;check(!$workspace->app_permission($read)&&$workspace->read_app($read)->status===403,'editor cannot read owner/client AI settings');
$actor=2;$options['dsa_settings']['ai']['seo_enabled']=true;$owner_view=$workspace->read_app($read)->data;
check($owner_view['ownerSetupUrl']==='https://example.test/wp-admin/admin.php?page=kiwe-ai'&&$owner_view['owner']['keyStored'],'technical owner sees protected provider destination and key-presence only');
check($owner_view['owner']['provider']['id']==='gemini'&&$owner_view['owner']['provider']['configured']&&!str_contains(json_encode($owner_view),'synthetic-provider-secret'),'technical owner sees credential-blind provider readiness');
$before_provider_calls=Transport::$calls;
check($workspace->check_provider_app(new WP_REST_Request('POST',['confirmed'=>false,'expected'=>$owner_view['owner']['expected']]))->status===400&&Transport::$calls===$before_provider_calls,'provider readiness requires explicit confirmation without a call');
$provider_check=$workspace->check_provider_app(new WP_REST_Request('POST',['confirmed'=>true,'expected'=>$owner_view['owner']['expected']]));
check($provider_check->status===200&&Transport::$calls===$before_provider_calls+1&&$provider_check->data['owner']['provider']['lastCheck']['status']==='passed'&&$provider_check->data['owner']['provider']['lastCheck']['attempts']===1,'confirmed readiness sends exactly one provider request and stores a sanitized result');
check(!str_contains(json_encode($provider_check->data),'synthetic-provider-secret')&&!str_contains(json_encode($options['kiwe_ai_provider_check_v1']),'ready'),'readiness response and stored receipt contain no secret or provider body');
$stale_provider=$workspace->check_provider_app(new WP_REST_Request('POST',['confirmed'=>true,'expected'=>str_repeat('0',64)]));
check($stale_provider->status===409&&Transport::$calls===$before_provider_calls+1,'stale readiness confirmation cannot send another request');
check($workspace->save_owner_app(new WP_REST_Request('POST',['key'=>'seoAllowed','value'=>false,'expected'=>$owner_view['owner']['expected']]))->status===200&&$options['dsa_settings']['ai']['seo_enabled']===false,'owner app revokes SEO permission in existing AI settings');
check($workspace->save_owner_app(new WP_REST_Request('POST',['key'=>'securetrackAllowed','value'=>false,'expected'=>$owner_view['owner']['expected']]))->status===409,'owner app rejects a stale settings snapshot');
$owner_view=$workspace->read_app($read)->data;$before=$options['dsa_settings'];
check($workspace->save_owner_app(new WP_REST_Request('POST',['key'=>'requestLimits','value'=>['minute'=>1,'day'=>10,'month'=>100],'expected'=>$owner_view['owner']['expected']]))->status===200&&$options['dsa_settings']['ai']['request_allowance']===['minute'=>1,'day'=>10,'month'=>100]&&$options['dsa_settings']['ai']['native_api_key']===$before['ai']['native_api_key'],'owner allowance updates original settings and preserves key');
$owner_view=$workspace->read_app($read)->data;
check($workspace->save_owner_app(new WP_REST_Request('POST',['key'=>'tokenLimits','value'=>['minute'=>0,'day'=>500,'month'=>5000],'expected'=>$owner_view['owner']['expected']]))->status===200&&$options['dsa_settings']['ai']['token_allowance']['minute']===0,'owner app can pause with existing token-limit sanitizer');
$owner_view=$workspace->read_app($read)->data;$before=$options;
check($workspace->save_owner_app(new WP_REST_Request('POST',['key'=>'native_api_key','value'=>'exfiltrate','expected'=>$owner_view['owner']['expected']]))->status===400&&$options===$before,'owner route rejects arbitrary technical settings');
$actor=1;check($workspace->save_owner_app(new WP_REST_Request('POST',['key'=>'allowNativeGeneration','value'=>true,'expected'=>$owner_view['owner']['expected']]))->status===403,'client cannot mutate owner provider gate');
echo "$count AI feature permission checks passed.\n";
}
