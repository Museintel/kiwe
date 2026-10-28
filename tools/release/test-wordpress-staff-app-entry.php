<?php
/** Actual public WordPress query/identity/redirect owners, with disposable lab identities. */
if(PHP_SAPI!=='cli'||!defined('KIWE_STAFF_ENTRY_NATIVE')||!KIWE_STAFF_ENTRY_NATIVE||is_admin()||home_url()!=='https://plum-giraffe-539524.hostingersite.com')exit(2);
use DSA\Access\Workspace_Admin_Service as Home;
class KiweStaffEntryRedirect extends RuntimeException {}
$entry=['schema'=>'kiwe.native-staff-app-entry.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];$check=static function($ok,$label)use(&$entry){$entry['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$actor=get_current_user_id();$get=$_GET;$server=$_SERVER;$query=$GLOBALS['wp_query']??null;$users=[];$borrowed=[];$filters=[];$originalSettings=get_option(DSA_OPTION_SETTINGS);$originalActivity=get_option(DSA\Access\Workspace_Activity_Service::OPTION,null);$nocache=[];
try{
 require_once ABSPATH.'wp-admin/includes/user.php';
 $filter=static function($hook,$fn,$args=1)use(&$filters){add_filter($hook,$fn,PHP_INT_MAX,$args);$filters[]=[$hook,$fn];};
 $filter('pre_wp_mail',static fn()=>false,2);$filter('pre_http_request',static fn()=>new WP_Error('ap129_no_delivery','No native app-entry delivery'),3);$filter('pre_schedule_event','__return_false');
 $filter('wp_redirect',static function($url){throw new KiweStaffEntryRedirect($url);});$filter('litespeed_control_set_nocache',static function($reason)use(&$nocache){$nocache[]=$reason;return$reason;});
 foreach(['user_register','set_user_role']as$hook)foreach(($GLOBALS['wp_filter'][$hook]->callbacks??[])as$priority=>$entries)foreach($entries as$item){$cb=$item['function'];if(is_array($cb)&&is_object($cb[0])&&(str_starts_with(get_class($cb[0]),'DSA\\Notifications\\')||$cb[0] instanceof DSA\Access\Workspace_Activity_Service||$cb[0] instanceof DSA\Commerce\Store_Analytics_Service)){$borrowed[]=[$hook,$priority,$item];remove_action($hook,$cb,$priority);}}
 $tag='kiwe_entry_'.bin2hex(random_bytes(4));foreach(['admin'=>'administrator','customer'=>'subscriber']as$kind=>$role){$id=wp_insert_user(['user_login'=>$tag.$kind,'user_pass'=>wp_generate_password(48,true,true),'user_email'=>$tag.$kind.'@example.invalid','role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Disposable app-entry identity unavailable');$users[$kind]=(int)$id;}
 $args=get_option('show_on_front')==='page'?['page_id'=>(int)get_option('page_on_front'),'post_type'=>'page']:['posts_per_page'=>1];$GLOBALS['wp_query']=new WP_Query($args);$check(is_front_page(),'Actual configured WordPress front-page query');
 $_GET=['dsa_pwa'=>'1'];$_SERVER['REQUEST_METHOD']='GET';$enter=static function(){$url='';try{(new Home())->redirect_app_launch();}catch(KiweStaffEntryRedirect$error){$url=$error->getMessage();}return$url;};
 wp_set_current_user($users['admin']);update_user_meta($users['admin'],'pk_verified_at',gmdate('Y-m-d H:i:s'));$check(DSA\PhoneKey\PhoneKey_Bridge::account_verified($users['admin']),'Native identity owner confirms the disposable verified staff account');$check($enter()===Home::url(),'Verified Administrator manifest entry uses the existing private dashboard');$check(defined('DONOTCACHEPAGE')&&DONOTCACHEPAGE&&!empty($nocache),'Explicit app entrance uses native page-cache exclusion');
 $_GET=[];$check($enter()==='','Deliberate public browsing is not redirected');$_GET=['dsa_pwa'=>'1'];
 delete_user_meta($users['admin'],'pk_verified_at');$check(!DSA\PhoneKey\PhoneKey_Bridge::account_verified($users['admin'])&&$enter()==='','Unverified staff retains the native public verification journey');
 wp_set_current_user($users['customer']);update_user_meta($users['customer'],'pk_verified_at',gmdate('Y-m-d H:i:s'));$check($enter()===''&&Home::app_launch_url(wp_get_current_user())==='','Verified customer gets no staff dashboard destination');
 wp_set_current_user(0);$check($enter()==='','Guest keeps the existing sign-in/appsite journey');
 wp_set_current_user($users['admin']);update_user_meta($users['admin'],'pk_verified_at',gmdate('Y-m-d H:i:s'));$_SERVER['REQUEST_METHOD']='POST';$check($enter()==='','App-entry writes never become dashboard navigation');$_SERVER['REQUEST_METHOD']='GET';
 $GLOBALS['wp_query']=new WP_Query(['post_type'=>'post','posts_per_page'=>1,'p'=>-1]);$check(!is_front_page()&&$enter()==='','A non-front-page destination is preserved');
 $entry['passed']=true;
}catch(Throwable$error){$entry['passed']=false;$entry['error']=['class'=>get_class($error),'line'=>$error->getLine(),'message'=>$error->getMessage()];}
finally{
 try{
  wp_set_current_user(0);foreach($users as$id){wp_delete_user($id);$check(!get_userdata($id),'Disposable app-entry identity retired');}
  $check(get_option(DSA_OPTION_SETTINGS)===$originalSettings&&get_option(DSA\Access\Workspace_Activity_Service::OPTION,null)===$originalActivity,'Original settings and arrival activity stay exact');$entry['cleanup']=true;
 }catch(Throwable$error){$entry['passed']=false;$entry['cleanupError']=$error->getMessage();}
 foreach($filters as[$hook,$fn])remove_filter($hook,$fn,PHP_INT_MAX);foreach($borrowed as[$hook,$priority,$item])add_action($hook,$item['function'],$priority,$item['accepted_args']);$_GET=$get;$_SERVER=$server;$GLOBALS['wp_query']=$query;wp_set_current_user($actor);
}
echo wp_json_encode($entry,JSON_UNESCAPED_SLASHES).PHP_EOL;if(!$entry['passed']||!$entry['cleanup'])exit(1);
