<?php
declare(strict_types=1);
// Executable policy tests: WordPress I/O is stubbed, actual Kiwe services execute.
define('ABSPATH', __DIR__.'/');
$options=[];$users=[];$usermeta=[];$actor=1;$multisite=false;$network=[];$commerce=false;$verified=[];$fail_store=false;
class WP_Error { public function __construct(public string $code, public string $message='',public array $data=[]){} }
class Denied extends RuntimeException {}
class Redirected extends RuntimeException {}
class WP_User {
 public array $caps=[]; public array $allcaps=[];
	public string $user_pass='test-password'; public string $user_login;
 public function __construct(public int $ID,public array $roles){$this->user_login='user'.$ID;}
 public function set_role($role){if($GLOBALS['owner']->protect_membership(null,$this->ID,'wp_capabilities',[$role=>true])===false)return;$this->roles=$role?[$role]:[];}
 public function add_role($role){$this->roles=array_values(array_unique([...$this->roles,$role]));}
 public function remove_role($role){$this->roles=array_values(array_diff($this->roles,[$role]));}
 public function has_cap($cap){return !empty($GLOBALS['access']->client_capabilities($this->allcaps,[],[],$this)[$cap]);}
}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,$a=null){if($k===DSA\Access\Site_Owner_Service::OPTION && $GLOBALS['fail_store']){$GLOBALS['fail_store']=false;return false;}$GLOBALS['options'][$k]=$v;return true;}
function add_option($k,$v,...$args){if(array_key_exists($k,$GLOBALS['options']))return false;$GLOBALS['options'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
function get_network_option($n,$k,$d=[]){return get_option($k,$d);}
function update_network_option($n,$k,$v){return update_option($k,$v);}
function is_multisite(){return $GLOBALS['multisite'];}
function get_current_blog_id(){return 1;} function get_main_site_id(){return 1;}
function get_current_user_id(){return $GLOBALS['actor'];}
function wp_get_current_user(){return get_userdata(get_current_user_id());}
function get_userdata($id){return $GLOBALS['users'][$id]??false;}
function get_user_meta($id,$key,$single=true){return $GLOBALS['usermeta'][$id][$key]??'';}
function update_user_meta($id,$key,$value){$GLOBALS['usermeta'][$id][$key]=$value;return true;}
function get_user_by($field,$login){if(strtolower($field)==='id')return get_userdata((int)$login);foreach($GLOBALS['users'] as $user)if($user->user_login===$login)return $user;return false;}
function get_role($role){return (object)['capabilities'=>['read'=>true,'manage_options'=>true,'edit_posts'=>true,'install_plugins'=>true,'delete_users'=>true]];}
function absint($v){return abs((int)$v);} function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function sanitize_user($v,$strict=false){return (string)$v;} function wp_unslash($v){return $v;}
function is_user_logged_in(){return get_current_user_id()>0;}
function get_post_type($id){return $GLOBALS['test_posts'][$id]->post_type??[10=>'post',11=>'page',12=>'bricks_template',13=>'product',14=>'attachment',15=>'revision'][$id]??false;}
function wp_get_post_parent_id($id){return 11;}
function current_user_can($cap,...$args){$u=wp_get_current_user();if(!$u)return false;$caps=$GLOBALS['access']->client_capabilities(['manage_options'=>in_array('administrator',$u->roles),'edit_posts'=>true,'rank_math_general'=>true],[],[],$u);if($cap==='edit_post')return get_post_type($args[0])==='post'&&!empty($caps['edit_posts']);return !empty($caps[$cap]);}
function check_admin_referer($key){if(($_POST['_wpnonce']??'')!==$key)throw new Denied('nonce');}
function wp_check_password($pw,$hash,$id=0){return $pw===$hash;}
function wp_die($msg,$title='', $args=[]){throw new Denied($msg);}
function esc_html__($s,$domain=''){return $s;} function __($s,$domain=''){return $s;}
function admin_url($path){return 'https://site.test/wp-admin/'.$path;}
function wp_safe_redirect($url){throw new Redirected($url);}
function wp_doing_cron(){return false;} function wp_doing_ajax(){return false;}
function wp_send_json_error($data,$status){throw new Denied((string)$status);}
function home_url($path){return 'https://site.test'.$path;}
function pk_account_verified($id){return !empty($GLOBALS['verified'][$id]);}
function get_super_admins(){return $GLOBALS['network'];}
function grant_super_admin($id){$GLOBALS['network'][]=get_userdata($id)->user_login;return true;}
function update_site_option($k,$v){$GLOBALS['network']=$v;return true;}
function is_super_admin($id=0){return in_array(get_userdata($id?:get_current_user_id())->user_login,get_super_admins(),true);}
eval('namespace DSA\Onboarding; class Design_Context_Profile_Service { const PAGE_VISIBILITY_META="_kiwe_search_visibility"; function commerce_available(){return $GLOBALS["commerce"];} } class Onboarding_Service { static function section_slugs(){return ["kiwe-identity","kiwe-story"];}}');
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Guest_Contribution_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Site_Owner_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/PhoneKey/PhoneKey_Bridge.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/WordPress_Role_Access_Service.php';
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\WordPress_Role_Access_Service as Access;
$owner=new Owner();$access=new Access();$users=[1=>new WP_User(1,['administrator']),2=>new WP_User(2,['administrator']),3=>new WP_User(3,['subscriber']),4=>new WP_User(4,['author']),5=>new WP_User(5,['kiwe_user'])];
$n=0;function check($ok,$name){if(!$ok)throw new RuntimeException('FAIL '.$name);$GLOBALS['n']++;echo 'PASS '.$name."\n";}
function denies($fn){try{$fn();return false;}catch(Denied $e){return true;}}
function activate($login){$_POST=['_wpnonce'=>'kiwe_access_owner','confirm_owner'=>1,'owner_login'=>$login,'owner_password'=>'test-password'];try{$GLOBALS['owner']->handle_owner();}catch(Redirected $e){return true;}return false;}
check(!Owner::enabled(),'installation does not silently activate restrictions');
check(denies(fn()=>activate('user2')),'initial setup cannot silently take over another administrator');
check(activate('user1')&&Owner::owner_id()===1&&$users[1]->roles===[Owner::ROLE],'initial owner activation');
check(!isset($options['kiwe_owner_transfer_lock']),'activation releases atomic lock');
check(current_user_can('install_plugins')&&current_user_can('administrator'),'owner retains native and plugin full-access checks');
check(!Owner::is_owner(0),'explicit anonymous ID never inherits the current owner');
foreach(['delete_user','remove_user','promote_user'] as $cap)check($owner->protect_owner(['delete_users'],$cap,1,[1])===['do_not_allow'],'owner protected: '.$cap);
check(denies(fn()=>$owner->guard_delete(1)),'low-level deletion cannot remove the owner');
check($owner->protect_membership(null,1,'wp_capabilities',[])===false,'direct owner role removal blocked');
check($owner->protect_membership(null,2,'wp_capabilities',[Owner::ROLE=>true])===false,'second owner role injection blocked');
check($owner->protect_membership(null,5,'wp_capabilities',['kiwe_user'=>true,'contributor'=>true])===false,'unapproved Guest role injection blocked');
$usermeta[5][DSA\Access\Guest_Contribution_Service::META]=['status'=>'approved','intents'=>['post']];
check($owner->protect_membership(null,5,'wp_capabilities',['kiwe_user'=>true,'contributor'=>true])===null,'approved Guest role transition allowed');
$users[5]->add_role('contributor');
$actor=2;
check($owner->protect_owner(['edit_users'],'edit_user',2,[1])===['do_not_allow'],'client cannot alter owner credentials');
check($owner->protect_membership(null,2,'wp_capabilities',['manage_options'=>true])===false,'client cannot add raw technical capabilities');
foreach(['manage_options','install_plugins','edit_theme_options','delete_pages','bricks_full_access','rank_math_general','kiwe_manage_ownership'] as $cap)check(!current_user_can($cap),'client denied '.$cap);
foreach(['publish_posts','upload_files','list_users','edit_users','promote_users','delete_users','moderate_comments','kiwe_manage_context','kiwe_manage_team','rank_math_onpage_general','edit_pages','kiwe_create_page_drafts','kiwe_manage_page_indexing'] as $cap)check(current_user_can($cap),'client allowed '.$cap);
foreach([11,12,15] as $id)check($access->content_boundary(['edit_posts'],'edit_post',2,[$id])===['do_not_allow'],'read-only page/template/revision mutation denied '.$id);
check($access->content_boundary(['edit_posts'],'edit_post_meta',2,[10,'_bricks_page_content_2'])===['do_not_allow'],'builder metadata mutation denied');
check($access->content_boundary(['edit_posts'],'edit_post',2,[10])===['edit_posts'],'post editing preserved');
foreach(['subscriber','kiwe_user','customer','unknown'] as $role)check(Access::capabilities_for($role)===['read'],'no admin privileges: '.$role);
check(!in_array('create_posts',Access::capabilities_for('editor'))&&in_array('edit_others_posts',Access::capabilities_for('editor')),'editor updates existing posts only');
check(in_array('create_posts',Access::capabilities_for('author'))&&!in_array('edit_others_posts',Access::capabilities_for('author')),'author own posts only');
check(!in_array('shop_manager',Owner::allowed_roles()),'shop manager unassignable on non-commerce site');
$commerce=true;check(in_array('shop_manager',Owner::allowed_roles()),'shop manager assignable when commerce activates');
$req=fn($route,$method='POST',$params=[])=>new class($route,$method,$params){function __construct(private $route,private $method,private $params){}function get_route(){return $this->route;}function get_method(){return $this->method;}function get_param($k){return $this->params[$k]??null;}};
foreach(['/phonekey/v3/verify-email','/phonekey/v3/account/factor/start','/dsa/v1/account/profile','/dsa/v1/account/password-reset','/dsa/v1/saved-items','/wp/v2/posts/10','/wp/v2/media','/dsa/v1/metrics/event'] as $route)check($access->guard_rest(null,null,$req($route))===null,'preserve authorized endpoint '.$route);
foreach(['/wp/v2/pages/11','/wp/v2/settings','/dsa/v1/settings','/bricks/v1/save','/rankmath/v1/updateSettings'] as $route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'deny technical write '.$route);
$actor=5;
check(current_user_can('kiwe_guest_submit')&&!current_user_can('edit_posts')&&!current_user_can('upload_files'),'Guest gets only the create-once Kiwe capability');
check($access->guard_rest(null,null,$req('/wp/v2/posts/10')) instanceof WP_Error,'Guest cannot bypass the protected workspace through REST');
check($access->guard_rest(null,null,$req('/dsa/v1/account/guest-application'))===null,'Guest account route remains available');
$actor=2;
foreach(['/bricks/v1/load_query_page','/bricks/v1/query_result','/bricks/v1/load_popup_content'] as $route)check($access->guard_rest(null,null,$req($route))===null,'preserve visitor-facing Bricks route '.$route);
$ajax=new ReflectionMethod($access,'guard_ajax');
foreach(['dsa_runtime_hydrate','stp_exit','stp_behavior','bricks_form_submit','bricks_regenerate_form_nonce'] as $action){$_REQUEST=['action'=>$action];check(!denies(fn()=>$ajax->invoke($access,'subscriber')),'preserve frontend AJAX '.$action);}
foreach(['bricks_save_post','bricks_save_settings','stp_trust_ip'] as $action){$_REQUEST=['action'=>$action];check(denies(fn()=>$ajax->invoke($access,'administrator')),'deny technical AJAX '.$action);}
$_REQUEST=[];
check($access->guard_rest(null,null,$req('/rankmath/v1/updateMeta','POST',['objectID'=>10,'objectType'=>'post']))===null,'Rank Math post metadata preserved');
check($access->guard_rest(null,null,$req('/rankmath/v1/updateMeta','POST',['objectID'=>11,'objectType'=>'post'])) instanceof WP_Error,'Rank Math cannot edit read-only pages');
check($access->guard_rest(null,null,$req('/rankmath/v1/updateMeta','POST',['objectID'=>10,'objectType'=>'post','meta'=>['_bricks_page_content_2'=>'code']])) instanceof WP_Error,'Rank Math cannot be used as a generic metadata writer');
$actor=3;$verified[3]=true;$owner->sync_verified_id(3);check($users[3]->roles===['kiwe_user'],'verified subscriber becomes User');
$verified[2]=true;$owner->sync_verified_id(2);check($users[2]->roles===['administrator'],'verification never changes staff role');
$actor=1;$options['kiwe_owner_transfer_lock']=time();check(denies(fn()=>activate('user2')),'concurrent transfer blocked');unset($options['kiwe_owner_transfer_lock']);
$fail_store=true;check(denies(fn()=>activate('user2'))&&Owner::owner_id()===1&&$users[1]->roles===[Owner::ROLE]&&$users[2]->roles===['administrator'],'failed persistence rolls back both roles and ownership');
check(!isset($options['kiwe_owner_transfer_lock']),'failure releases lock');
check(activate('user2')&&Owner::owner_id()===2&&$users[1]->roles===['administrator'],'ownership transfer demotes previous owner without deleting account');
check(denies(fn()=>activate('user1')),'former owner cannot reclaim ownership');
$actor=2;$multisite=true;$network=['user2'];check($owner->protect_network_owner(['user2','user3'],['user2'])===['user2'],'native network owner stays singleton');
echo "$n workspace policy tests passed.\n";
