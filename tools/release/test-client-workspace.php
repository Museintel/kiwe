<?php
declare(strict_types=1);
// Executable policy tests: WordPress I/O is stubbed, actual Kiwe services execute.
define('ABSPATH', __DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/App_Workspace_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/SEO_App_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Native_Indexing_Service.php';
$options=[];$users=[];$usermeta=[];$actor=1;$multisite=false;$network=[];$commerce=false;$verified=[];$fail_store=false;$media_delete_allowed=false;$rest_fields=[];
class WP_Error {
 public function __construct(public string $code, public string $message='',public array $data=[]){}
 public function get_error_code(){return $this->code;}
 public function get_error_message(){return $this->message;}
 public function get_error_data(){return $this->data;}
 public function add_data($data){$this->data=$data;}
}
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
function get_post_field($field,$id){return $GLOBALS['test_posts'][$id]->{$field}??null;}
function wp_get_post_parent_id($id){return 11;}
function current_user_can($cap,...$args){$u=wp_get_current_user();if(!$u)return false;if($cap==='delete_post')return $GLOBALS['media_delete_allowed'];$caps=$GLOBALS['access']->client_capabilities(['manage_options'=>(bool)array_intersect(['administrator','kiwe_super_admin'],$u->roles),'edit_posts'=>true,'rank_math_general'=>true],[],[],$u);if($cap==='edit_post')return get_post_type($args[0])==='post'&&!empty($caps['edit_posts']);return !empty($caps[$cap]);}
function register_rest_field($type,$name,$args){$GLOBALS['rest_fields'][$type][$name]=$args;}
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
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Template_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Analytics_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Team_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/PhoneKey/PhoneKey_Bridge.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/WordPress_Role_Access_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Media_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Workspace_Admin_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Context_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Navigation_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/AI_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Backup_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Content_Archive_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Unit_Catalog_Service.php';
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\WordPress_Role_Access_Service as Access;
$owner=new Owner();$access=new Access();$users=[1=>new WP_User(1,['administrator']),2=>new WP_User(2,['administrator']),3=>new WP_User(3,['subscriber']),4=>new WP_User(4,['author']),5=>new WP_User(5,['kiwe_user']),6=>new WP_User(6,['kiwe_web_designer']),7=>new WP_User(7,['shop_manager'])];
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
foreach(['manage_options','install_plugins','edit_theme_options','rank_math_general','kiwe_manage_ownership'] as $cap)check(!current_user_can($cap),'client denied '.$cap);
check(current_user_can('bricks_full_access')&&current_user_can('kiwe_manage_templates'),'client can use native Bricks Page and Template design');
foreach(['publish_posts','upload_files','list_users','edit_users','promote_users','delete_users','moderate_comments','kiwe_manage_context','kiwe_manage_team','rank_math_onpage_general','edit_pages','delete_pages','delete_others_pages','delete_private_pages','delete_published_pages','kiwe_create_page_drafts','kiwe_manage_page_indexing'] as $cap)check(current_user_can($cap),'client allowed '.$cap);
foreach([11,12] as $id)check($access->content_boundary(['edit_posts'],'edit_post',2,[$id])===['edit_posts'],'client Page and Template editing allowed '.$id);
check($access->content_boundary(['edit_posts'],'edit_post',2,[15])===['edit_posts'],'native page revision follows parent Page authority');
check($access->content_boundary(['edit_posts'],'edit_post_meta',2,[10,'_bricks_page_content_2'])===['do_not_allow'],'builder metadata mutation denied');
check($access->content_boundary(['edit_posts'],'edit_post',2,[10])===['edit_posts'],'post editing preserved');
foreach(['subscriber','kiwe_user','customer','unknown'] as $role)check(Access::capabilities_for($role)===['read','kiwe_use_notifications'],'own inbox without admin privileges: '.$role);
check(!in_array('create_posts',Access::capabilities_for('editor'))&&in_array('edit_others_posts',Access::capabilities_for('editor'))&&in_array('moderate_comments',Access::capabilities_for('editor')),'editor updates existing posts and retains native moderation');
check(in_array('create_posts',Access::capabilities_for('author'))&&!in_array('edit_others_posts',Access::capabilities_for('author')),'author own posts only');
check(in_array(DSA\Access\Template_Workspace_Service::ROLE,Owner::allowed_roles()),'Web Designer can be assigned through client role policy');
$actor=6;
check(current_user_can('kiwe_manage_templates')&&current_user_can('edit_pages')&&current_user_can('bricks_full_access'),'Web Designer receives native Template and Page design access');
check(current_user_can('kiwe_use_notifications'),'Web Designer receives own inbox without unrelated business capabilities');
foreach(['manage_options','edit_posts','edit_products','edit_users','create_pages','kiwe_manage_ai_preferences'] as $cap)check(!current_user_can($cap),'Web Designer denied unrelated '.$cap);
check($access->content_boundary(['edit_kiwe_templates'],'edit_post',6,[12])===['edit_kiwe_templates'],'Web Designer can edit Bricks template content');
check($access->content_boundary(['edit_posts'],'edit_post',6,[10])===['do_not_allow'],'Web Designer cannot edit posts');
$test_posts=[14=>(object)['post_type'=>'attachment','post_author'=>6],16=>(object)['post_type'=>'attachment','post_author'=>7],17=>(object)['post_type'=>'attachment','post_author'=>4]];
check($access->content_boundary(['do_not_allow'],'delete_post',6,[14])===['upload_files'],'Web Designer can delete only an own upload');
check($access->content_boundary(['do_not_allow'],'delete_post',6,[16])===['do_not_allow'],'Web Designer cannot delete another upload');
check($access->content_boundary(['do_not_allow'],'delete_post',6,[11])===['do_not_allow'],'media permission does not grant Page deletion');
check($access->content_boundary(['delete_posts'],'delete_post',4,[17])===['delete_posts'],'Author keeps native own-media deletion');
$actor=2;
check(!in_array('shop_manager',Owner::allowed_roles()),'shop manager unassignable on non-commerce site');
$commerce=true;check(in_array('shop_manager',Owner::allowed_roles()),'shop manager assignable when commerce activates');
check($access->content_boundary(['do_not_allow'],'delete_post',7,[16])===['upload_files'],'Store Manager can delete an own upload');
check($access->content_boundary(['do_not_allow'],'delete_post',7,[14])===['do_not_allow'],'Store Manager cannot delete another upload');
$req=fn($route,$method='POST',$params=[])=>new class($route,$method,$params){function __construct(private $route,private $method,private $params){}function get_route(){return $this->route;}function get_method(){return $this->method;}function get_param($k){return $this->params[$k]??null;}};
if (!defined('KIWE_REACT_WORKSPACE')) define('KIWE_REACT_WORKSPACE',true);
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/ai/preference'))===null,'client AI preference reaches its exact private adapter');
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/apps/seo'))===null,'client SEO App toggle reaches its existing-capability private adapter');
$nativeAppGuard=new ReflectionMethod($access,'guard_client_route');$oldAppPost=$_POST;$oldAppGet=$_GET;$oldAppScript=$pagenow??null;$oldAppMethod=$_SERVER['REQUEST_METHOD']??null;
$_POST=['action'=>DSA\Apps\SEO_App_Service::ACTION];$_GET=[];$pagenow='admin-post.php';$_SERVER['REQUEST_METHOD']='POST';$nativeAppGuard->invoke($access,'administrator');check(true,'client native SEO App form reaches its existing-capability handler');
$actor=4;$nativeAppDenied=false;try{$nativeAppGuard->invoke($access,'author');}catch(Denied $e){$nativeAppDenied=true;}check($nativeAppDenied,'author cannot use the native SEO App form');$actor=2;
$actor=1;$_GET=['page'=>'kiwe-app-settings'];$_POST=[];$pagenow='admin.php';$_SERVER['REQUEST_METHOD']='GET';$options[DSA\Apps\Store_App_Service::OPTION]='0';$nativeAppGuard->invoke($access,'administrator');check(true,'client App settings page retains its existing permission while Store is off');
$actor=4;$nativeAppDenied=false;try{$nativeAppGuard->invoke($access,'author');}catch(Denied $e){$nativeAppDenied=true;}check($nativeAppDenied,'App page does not grant an author new settings permission');
unset($options[DSA\Apps\Store_App_Service::OPTION]);$actor=2;
$_POST=$oldAppPost;$_GET=$oldAppGet;$pagenow=$oldAppScript;if($oldAppMethod===null)unset($_SERVER['REQUEST_METHOD']);else $_SERVER['REQUEST_METHOD']=$oldAppMethod;
foreach(['/dsa/v1/workspace/apps/seo/extra','/dsa/v1/workspace/apps/other','/dsa/v1/workspace/app-library','/dsa/v1/workspace/app-library/extra']as$route)check($access->guard_rest(null,null,$req($route))instanceof WP_Error,'App route admission stays exact: '.$route);
$actor=4;check($access->guard_rest(null,null,$req('/dsa/v1/workspace/apps/seo'))instanceof WP_Error,'author cannot change the website SEO App');$actor=2;
foreach(['start','control','review'] as $action)check($access->guard_rest(null,null,$req('/dsa/v1/workspace/seo/history/'.$action))===null,'client historical SEO '.$action.' reaches its exact private adapter');
foreach(['/dsa/v1/workspace/seo/history/extra','/dsa/v1/workspace/seo/history/start/extra'] as $route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'historical SEO route guard stays narrow: '.$route);
$actor=4;check($access->guard_rest(null,null,$req('/dsa/v1/workspace/seo/history/start')) instanceof WP_Error,'Author cannot start sitewide SEO work');$actor=2;
foreach(['/dsa/v1/workspace/ai/owner','/dsa/v1/workspace/ai/preference/extra']as$route)check($access->guard_rest(null,null,$req($route))instanceof WP_Error,'client AI preference guard does not admit '.$route);
foreach(['/phonekey/v3/verify-email','/phonekey/v3/account/factor/start','/dsa/v1/account/profile','/dsa/v1/account/password-reset','/dsa/v1/saved-items','/wp/v2/posts/10','/wp/v2/media','/dsa/v1/metrics/event'] as $route)check($access->guard_rest(null,null,$req($route))===null,'preserve authorized endpoint '.$route);
foreach(['/wp/v2/pages/11','/bricks/v1/save'] as $route)check($access->guard_rest(null,null,$req($route))===null,'native Page and Bricks editor route retains object/nonce checks '.$route);
foreach(['/wp/v2/settings','/dsa/v1/settings','/rankmath/v1/updateSettings'] as $route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'deny technical write '.$route);
$actor=5;
check(current_user_can('kiwe_guest_submit')&&!current_user_can('edit_posts')&&!current_user_can('upload_files'),'Guest gets only the create-once Kiwe capability');
check($access->guard_rest(null,null,$req('/wp/v2/posts/10')) instanceof WP_Error,'Guest cannot bypass the protected workspace through REST');
check($access->guard_rest(null,null,$req('/dsa/v1/account/guest-application'))===null,'Guest account route remains available');
$actor=2;
foreach(['/bricks/v1/load_query_page','/bricks/v1/query_result','/bricks/v1/load_popup_content'] as $route)check($access->guard_rest(null,null,$req($route))===null,'preserve visitor-facing Bricks route '.$route);
$ajax=new ReflectionMethod($access,'guard_ajax');
$_SERVER['REQUEST_METHOD']='POST';$_REQUEST=['action'=>'delete-post'];$_POST=['id'=>17];$actor=4;$media_delete_allowed=true;
check(!denies(fn()=>$ajax->invoke($access,'author')),'native media deletion reaches WordPress nonce handler for allowed attachment');
$_POST=['id'=>10];check(denies(fn()=>$ajax->invoke($access,'author')),'post deletion does not enter media AJAX exception');
$_POST=['id'=>17];$media_delete_allowed=false;check(denies(fn()=>$ajax->invoke($access,'author')),'another user media deletion denied');
$media_delete_allowed=true;$_SERVER['REQUEST_METHOD']='GET';check(denies(fn()=>$ajax->invoke($access,'author')),'media deletion requires POST');
$_SERVER['REQUEST_METHOD']='POST';$actor=2;
$media=new DSA\Access\Media_App_Service();$media->register_delete_permission();$field=$rest_fields['attachment']['kiwe_can_delete'];
check(($field['get_callback'])(['id'=>17])===true&&$field['schema']['context']===['edit'],'private REST media view reflects WordPress delete permission');
$media_delete_allowed=false;check(($field['get_callback'])(['id'=>17])===false,'private REST media view hides denied delete action');
foreach(['dsa_runtime_hydrate','stp_exit','stp_behavior','bricks_form_submit','bricks_regenerate_form_nonce'] as $action){$_REQUEST=['action'=>$action];check(!denies(fn()=>$ajax->invoke($access,'subscriber')),'preserve frontend AJAX '.$action);}
$_REQUEST=['action'=>'bricks_save_post'];check(!denies(fn()=>$ajax->invoke($access,'administrator')),'client Bricks save reaches native object and nonce checks');
foreach(['bricks_save_settings','bricks_generate_code_signature','stp_trust_ip'] as $action){$_REQUEST=['action'=>$action];check(denies(fn()=>$ajax->invoke($access,'administrator')),'deny technical AJAX '.$action);}
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
