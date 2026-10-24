<?php
/** Native WordPress platform boundary test. The common owner has already isolated all writes. */
if(PHP_SAPI!=='cli'||!defined('KIWE_SEO_NATIVE_TEST')||KIWE_SEO_NATIVE_TEST!==true)throw new RuntimeException('Owned native Apps fixture required');
function kiwe_apps_platform_native_run(int $software_manager):array{
 $proof=['passed'=>false,'cleanup'=>false,'checks'=>[]];$check=static function($ok,$name)use(&$proof){$proof['checks'][$name]=(bool)$ok;if(!$ok)throw new RuntimeException($name);};
 try{
  global $wpdb;
  // Preserve real native IDs in the isolated copy so synthetic users never reuse the protected owner ID.
  foreach(['users','usermeta']as$table)$check(false!==$wpdb->query('INSERT INTO '.$wpdb->$table.' SELECT * FROM '.$GLOBALS['kiwe_seo_test_original'][$table]),'Exact temporary native '.$table.' identity copy');
  $_SERVER['HTTP_ORIGIN']=home_url();$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';
  $library=new DSA\Apps\Library_Service();$request=static function(){ $r=new WP_REST_Request('POST');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_header('X-Kiwe-Mutation','1');return$r;};
  wp_set_current_user(0);$check(!$library->permission($request()),'Native guest cannot administer App software');
  foreach(['subscriber','author','editor','shop_manager']as$role){$id=wp_insert_user(['user_login'=>'apps-fixture-'.$role,'user_email'=>'apps-'.$role.'@example.invalid','user_pass'=>wp_generate_password(32,true,true),'role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Temporary native role unavailable');wp_set_current_user($id);$check(!$library->permission($request()),'Native '.$role.' has no software-management mutation');}
  wp_set_current_user($software_manager);$check(current_user_can('install_plugins')&&current_user_can('activate_plugins')&&current_user_can('update_plugins'),'Current native software manager has actual WordPress software capabilities');$check($library->permission($request()),'Same-site nonce-protected native software manager is admitted');
  $bad=$request();$bad->set_header('X-WP-Nonce','invalid');$check(!$library->permission($bad),'Invalid native nonce is refused');$_SERVER['HTTP_ORIGIN']='https://unrelated.invalid';$check(!$library->permission($request()),'Foreign origin cannot administer App software');$_SERVER['HTTP_ORIGIN']=home_url();
  $settings=get_option(DSA_OPTION_SETTINGS);$context=get_option(DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE);$active=get_option('active_plugins');$beforeCaps=DSA\Apps\Runtime_Registry::capabilities();$calls=$GLOBALS['kiwe_seo_test_http_calls'];
  $cache=(new DSA\Apps\Catalogue_Service())->refresh(true);$check($GLOBALS['kiwe_seo_test_http_calls']===$calls+1,'Only the explicit native metadata check attempts one blocked origin request');$check(str_contains($cache['message'],'could not be reached'),'Provider outage is truthfully shown');$check(DSA\Apps\Runtime_Registry::capabilities()===$beforeCaps,'Installed engines and their switches survive catalogue outage');$check(get_option(DSA_OPTION_SETTINGS)===$settings&&get_option(DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE)===$context&&get_option('active_plugins')===$active,'Outage preserves all tested native business and activation choices');
  $modules=new DSA\Modules\Module_Registry();$modules->register_defaults();$graph=(new DSA\AI\Site_Graph_Service(new DSA\Settings(),$modules))->graph(['sampleLimit'=>2]);$check(($graph['kiwe']['apps']??null)===$beforeCaps,'Actual Site Graph includes the exact read-only App capability contract');$check(!isset($graph['kiwe']['apps']['installToken'])&&$graph['kiwe']['apps']['designContextOwner']==='core','Capability discovery exposes no installation authority');
  $proof['passed']=true;
 }catch(Throwable$e){$proof['error']=$e->getMessage();$proof['frames']=array_map(static fn($f)=>['file'=>basename($f['file']??''),'line'=>$f['line']??0],array_slice($e->getTrace(),0,5));}
 finally{try{do_action('shutdown');remove_all_actions('shutdown');}catch(Throwable$e){$proof['passed']=false;$proof['shutdownError']=$e->getMessage();}kiwe_seo_test_cleanup($proof);}
 $proof['passed']=$proof['passed']&&$proof['cleanup']&&!in_array(false,$proof['checks'],true);return$proof;
}
