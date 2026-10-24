<?php
/** Actual native authoring read path over exact connection-local copies; no customer data in proof. */
if(PHP_SAPI!=='cli'||!defined('KIWE_SEO_NATIVE_TEST')||true!==KIWE_SEO_NATIVE_TEST)throw new RuntimeException('Owned native context fixture required');
function kiwe_context_seo_independence_run():array{
 global $wpdb;
 $proof=['passed'=>false,'cleanup'=>false,'checks'=>[],'hashes'=>[]];
 $check=static function($ok,$name)use(&$proof){$proof['checks'][$name]=(bool)$ok;if(!$ok)throw new RuntimeException($name);};
 $hash=static fn($value)=>hash('sha256',serialize($value));
 try{
  // The common isolation owner copies options and creates empty native-shaped tables.
  // Read tests copy original rows into those tables; guards never admit original writes.
  foreach($GLOBALS['kiwe_seo_test_original']as$name=>$source)if($name!=='options')$check(false!==$wpdb->query('INSERT INTO '.$wpdb->$name.' SELECT * FROM '.$source),'Exact temporary native '.$name.' read copy');
  $actor=wp_insert_user(['user_login'=>'kiwe_context_flow_fixture','user_email'=>'context-flow@example.invalid','user_pass'=>wp_generate_password(32,true,true),'role'=>'administrator']);
  $check(!is_wp_error($actor)&&$actor>0,'Temporary native context manager is created only in owned tables');
  $native_actor=new WP_User((int)$actor);$native_actor->add_cap('kiwe_manage_context');wp_set_current_user((int)$actor);
  $check(current_user_can('kiwe_manage_context'),'Native fixture manager has the exact context permission');
  $profile=new DSA\Onboarding\Design_Context_Profile_Service();
  $stored=get_option($profile::OPTION_PROFILE);$raw=$profile->current();$public=$profile->public_context(false);$admin=$profile->public_context(true);
  $families=['identity','business','about','contact','brand','audience','contentPlan','services','testimonials','resources','regulatory','commercePlan','seo','scores'];
  foreach($families as$key)$check(array_key_exists($key,$public)&&array_key_exists($key,$admin),'Complete Design Context family '.$key);
  $check(array_key_exists('primaryGoal',$public['seo'])&&array_key_exists('searchIntent',$public['seo']),'Goal and search intent remain in shared design evidence');
  $check(array_key_exists('designContextStrength',$public['scores'])&&array_key_exists('seoStrength',$public['scores']),'Saved context coverage and search evidence remain available');
  DSA\Onboarding\Design_Context_Journey_Service::invalidate();$journey=DSA\Onboarding\Design_Context_Journey_Service::projection();
  $check(is_array($journey)&&in_array('search',array_column($journey['steps'],'id'),true),'Full native guided setup retains Search and sharing');
  $modules=new DSA\Modules\Module_Registry();$modules->register_defaults();$graph=new DSA\AI\Site_Graph_Service(new DSA\Settings(),$modules);
  $packet=(new DSA\Site_Graph\Design_Context_Service($graph))->context(['productLimit'=>4,'mediaLimit'=>4,'contentLimit'=>4,'customContentLimit'=>4,'termLimit'=>4],false);
  foreach(['authority','siteGraph','business','seamDesignContext','dynamicBindings','resolvedDesignContext','designContextEnhancementContract','ideationContract']as$key)$check(isset($packet[$key]),'Real SiteGraph authoring packet retains '.$key);
  $check($packet['seamDesignContext']===$public,'Real SiteGraph exports the complete same Core context');
  $check($packet['dynamicBindings']===DSA\Bricks\Design_Context_Binding_Service::contract($public),'Real dynamic bindings use the same owner context');
  $check($packet['ideationContract']['authority']['mayMutateDesignContext']===false&&$packet['authority']['mayMutateWordPress']===false,'Browser AI design handoff retains read-only authority');
  $check($packet['ideationContract']['autoComposeCommands']===['/ideate'],'Start command handoff remains available');
  $check(get_option($profile::OPTION_PROFILE)===$stored,'Reading the complete authoring flow does not rewrite Design Context');
  $proof['hashes']=['storedContext'=>$hash($stored),'resolvedProfile'=>$hash($raw),'publicContext'=>$hash($public),'administratorContext'=>$hash($admin),'journey'=>$hash($journey)];
  foreach(['seamDesignContext','dynamicBindings','resolvedDesignContext','designContextEnhancementContract','ideationContract']as$key)$proof['hashes'][$key]=$hash($packet[$key]);
  $proof['contextFamilies']=$families;$proof['packetKeys']=array_keys($packet);
  $proof['includedSEO']=array_values(array_map('basename',array_filter(get_included_files(),static fn($f)=>(str_contains(str_replace('\\','/',$f),'/dsa/includes/SEO/')||str_contains(str_replace('\\','/',$f),'/kiwe-seo/includes/SEO/')))));
  if(!DSA\Apps\SEO_App_Service::enabled())$check($proof['includedSEO']===[],'Complete Core authoring read loads no SEO namespace file when inactive');
  $check(($GLOBALS['kiwe_seo_test_http_calls']??0)===0&&($GLOBALS['kiwe_seo_test_mail_calls']??0)===0,'Authoring evidence reads make no provider or mail request');
  $proof['passed']=true;
 }catch(Throwable$e){$proof['error']=$e->getMessage();$proof['frames']=array_map(static fn($f)=>['file'=>basename($f['file']??''),'line'=>$f['line']??0,'function'=>$f['function']??''],array_slice($e->getTrace(),0,5));}
 finally{
  try{do_action('shutdown');remove_all_actions('shutdown');}catch(Throwable$e){$proof['passed']=false;$proof['shutdownError']=$e->getMessage();}
  kiwe_seo_test_cleanup($proof);
 }
 $proof['passed']=$proof['passed']&&$proof['cleanup']&&!in_array(false,$proof['checks'],true);return$proof;
}
