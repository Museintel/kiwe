<?php
/** Native Home admission/menu/root routing; lab-only, no business data writes. */
if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || true !== KIWE_WORKSPACE_TEST_SITE || ! defined( 'KIWE_WORKSPACE_EXPECTED_VERSION' ) ) exit(2);
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\Workspace_Admin_Service as Home;
use DSA\Access\Workspace_Shell_Service as Shell;
class KiweDashboardProofRedirect extends RuntimeException {}
$proof=['schema'=>'kiwe.native-dashboard-access.v1','version'=>DSA_VERSION,'php'=>PHP_VERSION,'checks'=>[],'cleanup'=>false];
$assert=static function($ok,$name)use(&$proof){$proof['checks'][]=['label'=>$name,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($name);};
$actor=get_current_user_id();$users=[];$filters=[];$mail=[];$http=[];$original=[];$policy=[];$level=ob_get_level();
foreach([Owner::OPTION,DSA_OPTION_SETTINGS,'kiwe_seam_design_context_v1','pk_settings_v3','stp_settings']as$key)$original[$key]=get_option($key);
$filter=static function($hook,$fn,$args=1)use(&$filters){add_filter($hook,$fn,PHP_INT_MAX,$args);$filters[]=[$hook,$fn];};
$filter('pre_wp_mail',static function($v,$args)use(&$mail){$mail[]=true;return false;},2);
$filter('pre_http_request',static function($v,$args,$url)use(&$http){$http[]=true;return new WP_Error('kiwe_qa_block','No outbound acceptance traffic');},3);
$filter('pre_schedule_event','__return_false');
$filter('pre_option_'.Owner::OPTION,static function()use(&$policy){return $policy;});
$filter('wp_redirect',static function($url){throw new KiweDashboardProofRedirect($url);});
$redirect=static function($expected,$label)use($assert){try{(new Home())->redirect_dashboard();$actual='';}catch(KiweDashboardProofRedirect$e){$actual=$e->getMessage();}$assert($actual===$expected,$label);};
try{
 $verified=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($verified['valid'])&&!empty($verified['complete']),'matching complete installed package');
 if(!defined('WP_ADMIN'))define('WP_ADMIN',true);require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/user.php';
 $prefix='kiwe_home_'.bin2hex(random_bytes(5));
 foreach(['owner'=>'administrator','client'=>'administrator','author'=>'author','editor'=>'editor','guest'=>'contributor','shop'=>'shop_manager','designer'=>'kiwe_web_designer','subscriber'=>'subscriber','customer'=>'customer','unassignedOwnerRole'=>Owner::ROLE]as$kind=>$role){$id=wp_insert_user(['user_login'=>$prefix.$kind,'user_pass'=>wp_generate_password(48,true,true),'user_email'=>$prefix.$kind.'@example.invalid','role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Synthetic account creation');$users[$kind]=(int)$id;}
 foreach([false,true]as$enabled){
  $policy=['enabled'=>$enabled,'ownerId'=>$users['owner'],'rankMath'=>'inline'];
  foreach($users as$kind=>$id){
   wp_set_current_user($id);$_SERVER['REQUEST_METHOD']='GET';$GLOBALS['pagenow']='admin.php';$_GET=['page'=>Home::PAGE];
   $expected=!in_array($kind,['subscriber','customer','unassignedOwnerRole'],true);$before=wp_get_current_user()->allcaps;
   $assert(Home::uses_workspace(wp_get_current_user())===$expected,"Home admission $kind policy ".(int)$enabled);
   $GLOBALS['menu']=[];$GLOBALS['submenu']=[];$GLOBALS['admin_page_hooks']=[];$GLOBALS['_registered_pages']=[];$GLOBALS['_wp_menu_nopriv']=[];$GLOBALS['_wp_submenu_nopriv']=[];$GLOBALS['_wp_real_parent_file']=[];$GLOBALS['parent_file']='';$GLOBALS['plugin_page']=Home::PAGE;
   (new Home())->menu();$hook=get_plugin_page_hookname(Home::PAGE,'');
   $assert((isset($GLOBALS['_registered_pages'][$hook])&&user_can_access_admin_page())===$expected,"native menu and page admission $kind policy ".(int)$enabled);
   $assert(Shell::eligible(Home::PAGE)===$expected,"private shell admission $kind policy ".(int)$enabled);
   foreach(['login_redirect','woocommerce_login_redirect','kiwe_workspace_login_destination']as$event){$destination=apply_filters($event,home_url('/'),...($event==='login_redirect'?['',wp_get_current_user()]:[wp_get_current_user()]));$assert(($destination===Home::url())===$expected,"native $event $kind policy ".(int)$enabled);}
   $GLOBALS['pagenow']='index.php';$_GET=[];$redirect($expected?Home::url():'',"wp-admin root $kind policy ".(int)$enabled);
   $_GET=['kiwe-native-dashboard'=>'1'];$redirect($expected&&$kind!=='owner'?Home::url():'',"native dashboard opt-in $kind policy ".(int)$enabled);
   $_GET=[];$_SERVER['REQUEST_METHOD']='POST';$redirect('',"POST preserved $kind policy ".(int)$enabled);
   $GLOBALS['pagenow']='admin.php';$_GET=['page'=>Home::PAGE];$_SERVER['REQUEST_METHOD']='HEAD';$redirect('',"Home has no redirect loop $kind policy ".(int)$enabled);
   $assert($before===wp_get_current_user()->allcaps,"presentation grants no capabilities $kind policy ".(int)$enabled);
   if($kind==='owner'){
    $_SERVER['REQUEST_METHOD']='GET';$tasks=(new Home())->task_catalog();$native=array_values(array_filter($tasks,static fn($t)=>$t['label']===__('WordPress dashboard','dsa')));$assert(count($native)===1&&$native[0]['url']===admin_url('index.php?kiwe-native-dashboard=1'),'owner has one explicit technical dashboard shortcut');$labels=array_column($tasks,'label');foreach(['Posts'=>'edit_posts','Pages'=>'kiwe_view_pages','Products'=>'edit_products']as$label=>$cap)$assert(in_array(__($label,'dsa'),$labels,true)===current_user_can($cap),'owner catalog follows existing capability '.$label.' policy '.(int)$enabled);
    foreach(['plugins.php','options-general.php']as$page){$GLOBALS['pagenow']=$page;$_GET=[];$assert((new Home())->body_class('native')==='native','technical screen preserves native chrome '.$page);(new DSA\Access\WordPress_Role_Access_Service())->guard_editorial_admin_route();$assert(current_user_can('manage_options')&&current_user_can('install_plugins'),'existing technical authority preserved '.$page);}
   }
  }
 }
 wp_set_current_user($users['owner']);$GLOBALS['pagenow']='index.php';$_GET=[];$_SERVER['REQUEST_METHOD']='HEAD';$redirect(Home::url(),'HEAD entry redirects');
 $ajax=static fn()=>true;add_filter('wp_doing_ajax',$ajax,PHP_INT_MAX);$redirect('','AJAX is never redirected');remove_filter('wp_doing_ajax',$ajax,PHP_INT_MAX);
 $cron=static fn()=>true;add_filter('wp_doing_cron',$cron,PHP_INT_MAX);$redirect('','cron is never redirected');remove_filter('wp_doing_cron',$cron,PHP_INT_MAX);
 wp_set_current_user(0);$_SERVER['REQUEST_METHOD']='GET';$redirect('','anonymous entry does not gain Home');$assert(!Home::uses_workspace(wp_get_current_user())&&!Shell::eligible(Home::PAGE),'anonymous shell denied');
 $assert(!$mail&&!$http,'no outbound delivery or provider calls');
 wp_set_current_user($users['client']);$revoke=static function($caps){$caps['read']=false;return $caps;};add_filter('user_has_cap',$revoke,PHP_INT_MAX,1);$GLOBALS['pagenow']='index.php';$_GET=[];$redirect('','revoked read is not redirected into Home');$assert(!Shell::eligible(Home::PAGE),'revoked read blocks private shell');remove_filter('user_has_cap',$revoke,PHP_INT_MAX);
 define('REST_REQUEST',true);wp_set_current_user($users['owner']);$redirect('','REST never redirects to HTML');$assert(!Shell::eligible(Home::PAGE),'REST never enters private shell');
}catch(Throwable$e){$proof['error']=['class'=>get_class($e),'line'=>$e->getLine(),'message'=>$e->getMessage()];}
finally{
 try{
  $policy=[];wp_set_current_user(0);foreach($users as$id){wp_delete_user($id);$assert(!get_userdata($id),'synthetic account retired');}
  foreach($filters as[$hook,$fn])if($hook==='pre_option_'.Owner::OPTION)remove_filter($hook,$fn,PHP_INT_MAX);
  foreach($original as$key=>$value)$assert(get_option($key)===$value,'original state retained '.$key);
  $proof['cleanup']=true;
 }catch(Throwable$e){$proof['cleanupError']=$e->getMessage();}
 while(ob_get_level()>$level)ob_end_clean();foreach($filters as[$hook,$fn])remove_filter($hook,$fn,PHP_INT_MAX);wp_set_current_user($actor);
}
$proof['mailAttempts']=count($mail);$proof['httpAttempts']=count($http);
$proof['passed']=!$mail&&!$http&&!isset($proof['error'])&&!isset($proof['cleanupError'])&&$proof['cleanup']&&!in_array(false,array_column($proof['checks'],'passed'),true);
echo wp_json_encode($proof,JSON_UNESCAPED_SLASHES).PHP_EOL;
