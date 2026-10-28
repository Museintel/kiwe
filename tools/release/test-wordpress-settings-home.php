<?php
/** Actual WordPress menus, role policy and selected settings writes, isolated on the named lab. */
if(PHP_SAPI!=='cli'||!defined('KIWE_SETTINGS_HOME_NATIVE')||KIWE_SETTINGS_HOME_NATIVE!==true||home_url()!=='https://plum-giraffe-539524.hostingersite.com')exit(2);
use DSA\Access\Workspace_Admin_Service as Home;
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\App_Workspace_Service as AppSettings;
use DSA\Apps\Store_App_Service as StoreApp;
use DSA\Admin\Legacy_Settings as Legacy;
global $wpdb;
$out=['schema'=>'kiwe.native-settings-home.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
$check=static function($ok,$label)use(&$out){$out['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$actor=get_current_user_id();$originalOptions=$wpdb->options;$cache=$GLOBALS['wp_object_cache'];$optionsBefore=get_option(DSA_OPTION_SETTINGS);$choiceBefore=get_option(StoreApp::OPTION);$ownerBefore=get_option(Owner::OPTION);$server=$_SERVER;$get=$_GET;$users=[];$filters=[];$borrowed=[];$fixture='';$level=ob_get_level();$deliveries=0;$policy=[];
$filter=static function($hook,$fn,$args=1)use(&$filters){add_filter($hook,$fn,PHP_INT_MAX,$args);$filters[]=[$hook,$fn];};
$filter('pre_wp_mail',static function()use(&$deliveries){$deliveries++;return false;},2);
$filter('pre_http_request',static fn()=>new WP_Error('ap129_no_delivery','No acceptance delivery'),3);
$filter('pre_schedule_event','__return_false');
$filter('pre_option_'.Owner::OPTION,static function()use(&$policy){return $policy;});
try{
 $manifest=DSA\Runtime\Package_Manifest::verify(true);$check(DSA_VERSION===KIWE_SETTINGS_HOME_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'Exact complete current physical Core');
 require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/user.php';
 foreach(['user_register','set_user_role']as$hook)foreach(($GLOBALS['wp_filter'][$hook]->callbacks??[])as$priority=>$entries)foreach($entries as$entry){$cb=$entry['function'];if(is_array($cb)&&is_object($cb[0])&&(str_starts_with(get_class($cb[0]),'DSA\\Notifications\\')||$cb[0] instanceof DSA\Access\Workspace_Activity_Service)){$borrowed[]=[$hook,$priority,$entry];remove_action($hook,$cb,$priority);}}
 $tag='kiwe_ap129_'.bin2hex(random_bytes(4));
 foreach(['owner'=>'administrator','admin'=>'administrator','author'=>'author','customer'=>'subscriber']as$kind=>$role){$id=wp_insert_user(['user_login'=>$tag.$kind,'user_pass'=>wp_generate_password(48,true,true),'user_email'=>$tag.$kind.'@example.invalid','role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Disposable native role unavailable');$users[$kind]=(int)$id;}
 $policy=['enabled'=>true,'ownerId'=>$users['owner']];
 $admin=null;$seoMenu=null;foreach(($GLOBALS['wp_filter']['admin_menu']->callbacks??[])as$entries)foreach($entries as$entry){$fn=$entry['function'];if(is_array($fn)&&$fn[0] instanceof DSA\Admin\Admin&&$fn[1]==='menu')$admin=$fn[0];if(is_array($fn)&&$fn[0] instanceof DSA\SEO\SEO_Refinement_Service&&$fn[1]==='menu')$seoMenu=$fn[0];}
 $check($admin instanceof DSA\Admin\Admin,'Original registered Admin owner is used');
 foreach($users as$kind=>$id){
  wp_set_current_user(0);wp_set_current_user($id);$caps=wp_get_current_user()->allcaps;
  $expected=$kind!=='customer';$check((Home::app_launch_url(wp_get_current_user())===Home::url())===$expected,'Actual role-specific PWA launch '.$kind);
  $check(Legacy::allowed()===($kind==='owner'),'Technical controls remain owner-only '.$kind);
  $GLOBALS['pagenow']='admin.php';$_GET=['page'=>Home::PAGE];$GLOBALS['plugin_page']=Home::PAGE;
  foreach(['menu','submenu','admin_page_hooks','_registered_pages','_wp_menu_nopriv','_wp_submenu_nopriv','_wp_real_parent_file','_parent_pages']as$key)$GLOBALS[$key]=[];
  $GLOBALS['parent_file']='';$GLOBALS['menu']=[2=>['Dashboard','read','index.php'],5=>['Posts','edit_posts','edit.php'],55=>['WooCommerce','manage_woocommerce','woocommerce'],60=>['Bricks','edit_theme_options','bricks']];$native=$GLOBALS['menu'];
  $admin->menu();(new Home())->menu();(new AppSettings())->menu();(new DSA\Access\Page_Workspace_Service())->menu();
  if(DSA\Apps\SEO_App_Service::enabled()){$check($seoMenu instanceof DSA\SEO\SEO_Refinement_Service,'Original registered SEO owner is initialized');$seoMenu->menu();}
  // The original role policy already removes WordPress Home for client staff.
  // Compare the cleanup with that admitted native menu, not with an invented grant.
  $native=array_filter($GLOBALS['menu'],static fn($row)=>in_array($row[2],['index.php','edit.php','woocommerce','bricks'],true));
  $registered=$GLOBALS['_registered_pages'];$admin->consolidate_workspace_menus();
  $check($GLOBALS['_registered_pages']===$registered,'Menu cleanup preserves registered native page hooks '.$kind);
  foreach($native as$key=>$row)$check(isset($GLOBALS['menu'][$key])&&$GLOBALS['menu'][$key]===$row,'Native menu retained '.$row[2].' '.$kind);
  $check(!array_filter($GLOBALS['menu'],static fn($row)=>str_starts_with((string)$row[2],'kiwe-')),'No duplicate Kiwe sidebar shortcuts '.$kind);
  $GLOBALS['plugin_page']=Home::PAGE;$GLOBALS['parent_file']='';$hook=get_plugin_page_hookname(Home::PAGE,'');
  $check((isset($GLOBALS['_registered_pages'][$hook])&&user_can_access_admin_page())===$expected,'Hidden registered Home remains natively admitted only for eligible roles '.$kind);
  if($kind==='owner'){$labels=array_column($GLOBALS['submenu']['kiwe']??[],0);$check(count($labels)===2&&in_array('Dashboard',$labels,true)&&in_array('Technical tools',$labels,true),'WordPress Kiwe menu contains only Dashboard and Technical tools');}
  $check($caps===wp_get_current_user()->allcaps,'Presentation grants no capability '.$kind);
 }
 wp_set_current_user(0);$check(Home::app_launch_url(wp_get_current_user())===''&&!Legacy::allowed(),'Signed-out accounts receive no staff projection');
 wp_set_current_user($users['owner']);$fixture=$wpdb->prefix.'ap129_'.bin2hex(random_bytes(4)).'_options';
 $check(!wp_using_ext_object_cache()&&$wpdb->query("CREATE TABLE `{$fixture}` LIKE `{$originalOptions}`")!==false&&$wpdb->query("INSERT INTO `{$fixture}` SELECT * FROM `{$originalOptions}`")!==false,'Selected settings writes use an isolated native options table');
 $wpdb->options=$fixture;$GLOBALS['wp_object_cache']=new WP_Object_Cache();
 $service=new AppSettings();$before=get_option(DSA_OPTION_SETTINGS);$snapshot=$service->snapshot();
 $result=$service->update(['expected'=>$snapshot['expected'],'changes'=>['theme_screens'=>['profile'=>['title'=>'Synthetic account label']],'search'=>['result_limit'=>7]]]);
 $check(!is_wp_error($result)&&(new DSA\Settings())->get('theme_screens')['profile']['title']==='Synthetic account label'&&(new DSA\Settings())->get('search')['result_limit']===7,'App Screen text and Search save through actual native settings owner');
 $after=get_option(DSA_OPTION_SETTINGS);$wanted=$before;$wanted['theme_screens']['profile']['title']='Synthetic account label';$wanted['search']['result_limit']=7;$wanted['search']['configuration_version']=2;$wanted['search']['bricks_bridge_enabled']=true;
 $normalize=static function($value)use(&$normalize){if(!is_array($value))return$value;foreach($value as$key=>$item)$value[$key]=$normalize($item);if(!array_is_list($value))ksort($value);return$value;};
 $check($normalize($after)===$normalize($wanted),'Combined save preserves every unrelated native setting');
 $check(is_wp_error($service->update(['expected'=>$snapshot['expected'],'changes'=>['app'=>['home_enabled'=>false]]]))&&get_option(DSA_OPTION_SETTINGS)===$after,'Stale native App save is rejected without a write');
 $commerce=new DSA\Access\Commerce_App_Service();$display=$commerce->display_policy_record();$check(!is_wp_error($display)&&count($display['fields'])===11,'New Store presentation exposes its bounded native controls');
 $displayResult=$commerce->save_display_policy_record(['expected'=>$display['expected'],'changes'=>['fbt_title'=>'Synthetic pair title']]);$check(!is_wp_error($displayResult)&&(new DSA\Settings())->get('commerce')['fbt_title']==='Synthetic pair title','Store presentation saves through the same native settings authority');
 $raw=get_option(DSA_OPTION_SETTINGS);$settings=new DSA\Settings();$patch=Legacy::prepare('permissions',['permissions.cooldown_hours'=>'48'],$settings);$check(!is_wp_error($patch)&&$settings->compare_and_update_selected($raw,$patch)&&(new DSA\Settings())->get('permissions')['cooldown_hours']===48,'Unique technical field uses native selected compare-and-save');
 update_option(StoreApp::OPTION,false,false);$off=$service->snapshot();$check(!isset($off['screenCopySchema']['cart'],$off['screenCopySchema']['saved']['fields']['wishlistLabel'],$off['choices']['search']['families']['products'])&&!Legacy::fields('store'),'Store-off projection hides inapplicable controls and retains Core bookmarks');
 $check(isset($off['screenCopySchema']['saved']['fields']['bookmarksLabel'])&&is_wp_error($commerce->display_policy_record()),'Core saved bookmarks remain while Store operations are unavailable');
 $policy['ownerId']=$users['owner'];wp_set_current_user($users['admin']);$check(!Legacy::allowed()&&AppSettings::settings_allowed(),'Client Administrator has client settings without technical ownership');
 wp_set_current_user($users['customer']);$check(!AppSettings::allowed()&&is_wp_error($service->update(['expected'=>'','changes'=>['app'=>['home_enabled'=>false]]])),'Customer cannot write App settings');
 $out['passed']=true;
}catch(Throwable $error){$out['error']=['class'=>get_class($error),'line'=>$error->getLine(),'message'=>$error->getMessage()];}
finally{
 try{
  $wpdb->options=$originalOptions;$GLOBALS['wp_object_cache']=$cache;$policy=[];wp_set_current_user(0);
  foreach($users as$id){wp_delete_user($id);$check(!get_userdata($id),'Disposable role retired');}
  if($fixture!==''){if(!preg_match('/^'.preg_quote($wpdb->prefix,'/').'ap129_[a-f0-9]{8}_options$/D',$fixture))throw new RuntimeException('Exact fixture table required');$check($wpdb->query("DROP TABLE `{$fixture}`")!==false,'Isolated settings table retired');}
  foreach($filters as[$hook,$fn])remove_filter($hook,$fn,PHP_INT_MAX);
  foreach($borrowed as[$hook,$priority,$entry])add_action($hook,$entry['function'],$priority,$entry['accepted_args']);
  $check(get_option(DSA_OPTION_SETTINGS)===$optionsBefore&&get_option(StoreApp::OPTION)===$choiceBefore&&get_option(Owner::OPTION)===$ownerBefore,'Original business settings and App/owner choices stay exact');
  $out['cleanup']=true;
 }catch(Throwable $error){$out['cleanupError']=$error->getMessage();$out['passed']=false;}
 $_SERVER=$server;$_GET=$get;wp_set_current_user($actor);while(ob_get_level()>$level)ob_end_clean();
}
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES).PHP_EOL;if(!$out['passed']||!$out['cleanup'])exit(1);
