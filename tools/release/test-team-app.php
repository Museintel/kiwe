<?php
declare(strict_types=1);
require __DIR__.'/workspace-test-bootstrap.php';
if(!function_exists('WC')){function WC(){return (object)[];}}

define('KIWE_REACT_WORKSPACE', !in_array('--disabled',$argv,true));
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Task_Workspace_UI.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Team_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Utilities/Origin_Checker.php';
function wp_json_encode($v){return json_encode($v);}
function wp_roles(){return (object)['roles'=>['administrator'=>['name'=>'Administrator'],'subscriber'=>['name'=>'Subscriber'],'customer'=>['name'=>'Customer'],'author'=>['name'=>'Author'],'editor'=>['name'=>'Editor'],'shop_manager'=>['name'=>'Shop manager'],'kiwe_super_admin'=>['name'=>'Technical owner']]];}
function count_users($strategy='time',$site_id=null){$roles=[];foreach($GLOBALS['users'] as $person)foreach($person->roles as $role)$roles[$role]=($roles[$role]??0)+1;return ['avail_roles'=>$roles,'total_users'=>count($GLOBALS['users'])];}
function translate_user_role($v){return $v;}
function site_url($p=''){return home_url($p);}
function wp_parse_url($v){return parse_url($v);}
function esc_url_raw($v,$protocols=null){return preg_match('~^https?://~i',(string)$v)?(string)$v:'';}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function sanitize_email($v){return filter_var((string)$v,FILTER_VALIDATE_EMAIL)?(string)$v:'';}
function sanitize_user($v,$strict=false){return preg_replace('/[^a-z0-9_.-]/i','',(string)$v);}
function get_locale(){return 'en_US';}
function add_action($name,$callback,...$args){$GLOBALS['team_hooks'][$name]=$callback;}
function register_rest_route($ns,$path,$definition){$GLOBALS['team_routes'][$path]=$definition;}
class WP_REST_Request {
 public function __construct(private string $method='GET',private array $url=[],private array $body=[],private array $query=[],public array $headers=['X-WP-Nonce'=>'wp_rest','X-Kiwe-Mutation'=>'1']){}
 public function get_method(){return $this->method;}
 public function get_route(){return '/dsa/v1/workspace/team'.(isset($this->url['id'])?'/'.$this->url['id']:'');}
 public function get_url_params(){return $this->url;}
 public function get_json_params(){return $this->body;}
 public function get_query_params(){return $this->query;}
 public function get_header($key){return array_change_key_case($this->headers)[strtolower($key)]??'';}
}
class WP_REST_Response {
 public array $headers=[];
 public function __construct(public array $data,public int $status){}
 public function header($k,$v){$this->headers[$k]=$v;}
}
use DSA\Access\Team_App_Service as App;
use DSA\Access\Team_Workspace_Service as Team;
$app=new App();$app->register();$app->routes();
if(!KIWE_REACT_WORKSPACE){
 check(empty($team_hooks)&&empty($team_routes),'disabled app registers no hook or route');
 $before=$writes;check($app->listing(new WP_REST_Request())->status===403&&$writes===$before,'disabled direct callback refuses access without work');
 check(is_wp_error($access->guard_rest(null,null,new WP_REST_Request('POST',['id'=>3]))),'disabled app does not widen client REST guard');
 exit;
}
check(count($team_routes)===2&&isset($team_hooks['load-toplevel_page_kiwe-team']),'only existing Teams document and two route shapes register');
foreach($team_routes as $definition)foreach(isset($definition['methods'])?[$definition]:$definition as $route)check(is_callable($route['permission_callback']),'every operation has a permission callback');
$query_users=[$users[2],$users[3],$users[4],$users[5],$users[6]];
$usermeta[3]=['kiwe_public_team_member'=>'1','kiwe_team_title'=>'Studio lead','kiwe_team_order'=>'2','billing_address_1'=>'PRIVATE'];
$list=$app->listing(new WP_REST_Request('GET',[],[],['team_page'=>2,'number'=>100000]));
check($list->status===200&&$list->headers['Cache-Control']==='no-store, private','private successful list');
check($list->data['accountPolicy']['canCreate']&&$list->data['accountPolicy']['canChangeRoles'],'account lifecycle capability is explicit in the private list');
check(array_column($list->data['people'],'id')===[3,6]&&$user_query['number']===13&&!$user_query['count_total'],'native bounded listing excludes owner and ineligible users');
$person=$list->data['people'][0];
check(array_keys($person)===['id','name','roles','selected','title','order','profile','account','expected','security']&&!isset($person['password'])&&!isset($person['meta'])&&$person['profile']['woocommerce']['billing']['address_1']==='PRIVATE'&&$person['security']['joinSource']==='WordPress or import'&&in_array($person['security']['verification'],['unavailable','partial'],true),'explicit card allowlist retains private commerce and bounded verification state without arbitrary metadata');
check(array_keys($person['account'])===['canChangeRole','canChangeEmail','canSendReset','canSetPassword','canVerify','canSuspend','canRemove','removalMode','role','expected','identityExpected']&&$person['account']['canChangeRole']&&$person['account']['canChangeEmail']&&$person['account']['canSendReset']&&$person['account']['canSetPassword']&&$person['account']['canSuspend']&&$person['account']['canRemove']&&$person['account']['removalMode']==='single_site'&&$person['account']['role']==='author'&&$person['account']['expected']===Team::role_fingerprint(3)&&$person['account']['identityExpected']===Team::identity_fingerprint(3),'card exposes bounded native account controls without credentials');
$identityBefore=Team::identity_fingerprint(3);$passwordBefore=$users[3]->user_pass;$users[3]->user_pass='synthetic-native-hash';
check(Team::identity_fingerprint(3)!==$identityBefore&&!str_contains(Team::identity_fingerprint(3),'synthetic-native-hash'),'a password change invalidates the opaque account edit proof');
$users[3]->user_pass=$passwordBefore;
check(array_keys($person['profile'])===['wordpress','kiwe','woocommerce']&&array_keys($person['profile']['wordpress'])===['firstName','lastName','nickname','email','website','bio','locale','username','registered']&&array_keys($person['profile']['kiwe'])===['teamTitle','teamMember','teamOrder','founderTitle','linkedin','facebook','avatarUrl']&&array_keys($person['profile']['woocommerce'])===['billing','shipping']&&count($person['profile']['woocommerce']['billing'])===11&&$person['profile']['kiwe']['avatarUrl']===''&&$person['profile']['wordpress']['email']==='person3@example.test','people profile is explicit WordPress, Kiwe and WooCommerce projection with no credential or plugin metadata');
check($person['roles'][0]['label']==='Author'&&$person['roles'][0]['source']==='WordPress'&&$person['expected']===Team::fingerprint(3),'role labels and fingerprint belong to returned native card');
$valid=fn()=>['expected'=>Team::fingerprint(3),'team_member'=>'1','team_title'=>'Creative lead','team_order'=>'4'];
$post=fn($body,$id=3,$headers=['X-WP-Nonce'=>'wp_rest','X-Kiwe-Mutation'=>'1'])=>new WP_REST_Request('POST',['id'=>$id],$body,[],$headers);
$request=$post($valid()+['id'=>2,'role'=>'administrator','user_email'=>'forged@example.test','billing_address_1'=>'overwritten']);
check($access->guard_rest(null,null,$request)===null,'exact team mutation can pass client guard');
$saved=$app->save($request);
check($saved->status===200&&$saved->data['title']==='Creative lead'&&$saved->data['expected']===Team::fingerprint(3),'save reuses native writer and returns observed values');
check($usermeta[3]['billing_address_1']==='PRIVATE'&&$users[3]->roles===['author']&&!isset($usermeta[2]),'forged body identity and non-team fields cannot write');
check($app->save($request)->status===409,'stale fingerprint rejected');
$unchanged=count($writes);$saved=$app->save($post($valid()));check($saved->status===200&&count($writes)===$unchanged,'unchanged mutation performs no redundant metadata write');
foreach([2,4,5,999] as $id){$result=$app->save($post($valid(),$id));check($result->status===403,'protected target denied: '.$id);}
$denied=['edit_user:3'];check($app->read(new WP_REST_Request('GET',['id'=>3]))->status===403,'read checks object capability');$denied=[];
$multisite=true;$outside_blog=[3];check($app->save($post($valid()))->status===403,'cross-blog mutation denied');$multisite=false;$outside_blog=[];
foreach([0,3,5,6] as $roleActor){$actor=$roleActor;check($app->listing(new WP_REST_Request())->status===403&&$app->save($post($valid()))->status===403,'anonymous or lower-role list and mutation denied: '.$roleActor);}$actor=1;
foreach([[],['X-WP-Nonce'=>'bad','X-Kiwe-Mutation'=>'1'],['X-WP-Nonce'=>'wp_rest']] as $headers)check($app->save($post($valid(),3,$headers))->status===403,'missing or invalid request proof rejected');
check($app->listing(new WP_REST_Request('GET',[],[],[],[]))->status===403,'private reads require REST nonce');
$_SERVER['HTTP_ORIGIN']='https://other.example.test';check($app->save($post($valid()))->status===403&&$app->listing(new WP_REST_Request())->status===403,'cross-origin read and mutation denied');unset($_SERVER['HTTP_ORIGIN']);
check($app->save(new WP_REST_Request('GET',['id'=>3],$valid()))->status===403,'GET cannot write');
foreach([['expected'=>null],['team_order'=>'10000'],['team_title'=>['nested']],['team_member'=>true]] as $invalid)check($app->save($post(array_replace($valid(),$invalid)))->status>=400,'invalid native write input denied: '.array_key_first($invalid));
$failed_meta='kiwe_team_order';$body=$valid();$body['team_order']='5';$body['team_title']='Partially saved';check($app->save($post($body))->status===500,'partial native write failure is not successful');$failed_meta='';
$after_native_meta_write=function($id,$key){if($key==='kiwe_team_order')$GLOBALS['usermeta'][$id]['kiwe_team_title']='Concurrent title';};
$body=$valid();$body['team_order']='7';check($app->save($post($body))->status===409,'interleaved writer yields conflict without refreshed success fingerprint');unset($after_native_meta_write);
echo "$count team app boundary checks passed.\n";
