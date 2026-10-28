<?php
declare(strict_types=1);
namespace DSA\Access {
 final class Site_Owner_Service {static function is_owner($id){return $id===2;}}
 final class WordPress_Role_Access_Service {static function role_for($user){return $user->roles[0]??'';}}
}
namespace DSA\Apps {final class Store_App_Service {static function enabled(){return false;}}}
namespace DSA\PhoneKey {final class PhoneKey_Bridge {static function account_verified($id){return $GLOBALS['verified'];}}}
namespace {
define('ABSPATH',__DIR__.'/');
class WP_User {function __construct(public int $ID,public array $roles){}}
class EntryRedirect extends RuntimeException {}
$actor=new WP_User(1,['administrator']);$verified=true;$member=true;$read=true;$admin=false;$ajax=false;$cron=false;$front=true;$actions=[];$headers=0;$hooks=[];$destination='';$checks=0;
function is_multisite(){return true;}function is_super_admin($id){return $id===2;}function is_user_member_of_blog($id){return $GLOBALS['member'];}
function user_can($user,$cap){return $GLOBALS['read'];}function wp_get_current_user(){return $GLOBALS['actor'];}function is_user_logged_in(){return $GLOBALS['actor']->ID>0;}function is_admin(){return $GLOBALS['admin'];}function wp_doing_ajax(){return $GLOBALS['ajax'];}function wp_doing_cron(){return $GLOBALS['cron'];}function is_front_page(){return $GLOBALS['front'];}
function admin_url($path){return 'https://site.test/sub/wp-admin/'.$path;}function nocache_headers(){$GLOBALS['headers']++;}function do_action($name,...$args){$GLOBALS['actions'][]=$name;}
function add_action($name,$callback,$priority=10,$count=1){$GLOBALS['hooks'][$name][]=$callback;}function add_filter(...$args){}
function wp_safe_redirect($url){$GLOBALS['destination']=$url;throw new EntryRedirect();}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Workspace_Admin_Service.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
function enter(){ $GLOBALS['destination']='';$GLOBALS['actions']=[];$GLOBALS['headers']=0;try{(new DSA\Access\Workspace_Admin_Service())->redirect_app_launch();}catch(EntryRedirect $e){}return $GLOBALS['destination']; }
try{
 $_GET=[];$_SERVER['REQUEST_METHOD']='GET';$service=new DSA\Access\Workspace_Admin_Service();$service->register();check(empty($hooks['template_redirect']),'Ordinary browsing adds no public app-entry hook');
 $_GET=['dsa_pwa'=>'1'];$service->register();check(count($hooks['template_redirect'])===1,'Explicit manifest entry adds one native redirect owner');
 check(enter()==='https://site.test/sub/wp-admin/admin.php?page=kiwe-workspace'&&$headers===1&&in_array('litespeed_control_set_nocache',$actions,true)&&DONOTCACHEPAGE,'Verified Administrator goes directly to the existing private uncached dashboard');
 $actor=new WP_User(2,['kiwe_super_admin']);check(enter()!=='','Existing technical owner enters the same role-aware dashboard');
 $actor=new WP_User(3,['author']);check(enter()!=='','Staff app launch retains the existing role policy');
 $actor=new WP_User(4,['subscriber']);check(enter()===''&&$headers===1,'Customer keeps the existing public appsite without a dashboard grant');
 $actor=new WP_User(0,[]);check(enter()===''&&$headers===1,'Guest keeps the native public sign-in journey');
 $actor=new WP_User(1,['administrator']);$verified=false;check(enter()==='','Unverified Administrator cannot skip native app identity verification');$verified=true;
 $read=false;check(enter()==='','Native read denial cannot be bypassed by an app entry');$read=true;
 $member=false;check(enter()==='','An account outside this site receives no dashboard destination');$member=true;
 foreach(['front','admin','ajax','cron']as$key){$GLOBALS[$key]=$key==='front'?false:true;check(enter()===''&&$headers===0,'Non-entry context keeps its existing owner: '.$key);$GLOBALS[$key]=$key==='front'?true:false;}
 $_SERVER['REQUEST_METHOD']='POST';check(enter()===''&&$headers===0,'Writes are never redirected into dashboard HTML');$_SERVER['REQUEST_METHOD']='GET';
 $_GET=[];check(enter()===''&&$headers===0,'Public homepage browsing is not hijacked for a signed-in Administrator');
 echo json_encode(['passed'=>true,'cleanup'=>true,'checks'=>$checks,'nativeSqlExecuted'=>false,'scope'=>'Actual PHP manifest-entry guards with synthetic WordPress I/O; actual roles and HTTP caching require native lab proof']).PHP_EOL;
}catch(Throwable $error){echo json_encode(['passed'=>false,'cleanup'=>true,'checks'=>$checks,'error'=>$error->getMessage()]).PHP_EOL;exit(1);}
}
