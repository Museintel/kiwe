<?php
/** Native lab qualification; no account, content or provider fixtures. */
function kiwe_crawler_native_tests(callable $check): array {
 if(PHP_SAPI!=='cli'||home_url()!=='https://plum-giraffe-539524.hostingersite.com')throw new RuntimeException('Named lab qualification required');
 wp_set_current_user(DSA\Access\Site_Owner_Service::owner_id());
 $check(DSA\Access\Site_Owner_Service::is_owner(),'Existing native technical owner');
 $before=['dsa_settings'=>get_option('dsa_settings'),'stp_settings'=>get_option('stp_settings')];
 $policy=new DSA\Access\System_App_Workspace_Service();
 try{
  $fields=DSA\Access\System_Control_Catalogue::fields('security',true);
  $check(!isset($fields['honeypot_enabled']),'Retired trap is absent from the technical settings catalogue');
  $view=$policy->snapshot('security');
  $removed=$policy->update('security',['expected'=>$view['expected'],'changes'=>['honeypot_enabled'=>true]]);
  $check(is_wp_error($removed)&&get_option('stp_settings')===$before['stp_settings'],'A stale client cannot restore the retired trap control');
  $request=new WP_REST_Request('GET','/dsa/v1/workspace/system/security');$request->set_url_params(['app'=>'security']);$request->set_header('X-WP-Nonce','invalid');
  $check(!$policy->permission($request),'Native REST nonce remains mandatory');
  $secure=get_option('dsa_settings');$secure['secure']['enabled']=true;update_option('dsa_settings',$secure,false);
  $view=$policy->snapshot('security');$saved=$policy->update('security',['expected'=>$view['expected'],'changes'=>['kiwe.protection_profile'=>'recommended']]);
  $check(!is_wp_error($saved),'Actual transactional App writer applies an explicit recommended preset');
  $native=get_option('stp_settings');$check($native['adaptive_waf']===1&&$native['block_brute_force']===1&&$native['honeypot_enabled']===0,'Native preset retains protection without a hidden trap');
  $override=$policy->update('security',['expected'=>$saved['expected'],'changes'=>['adaptive_waf'=>false]]);
  $check(!is_wp_error($override)&&get_option('stp_settings')['adaptive_waf']===0,'Actual individual native override saves');
  $choices=get_option('stp_settings');$settings=new DSA\Settings();
  for($i=0;$i<3;$i++)DSA\Secure\SecureTrack_Settings_Policy::sync_from_kiwe($settings->get('secure',[]));
  $check(get_option('stp_settings')===$choices,'Repeated ordinary native sync preserves exact individual choices');
  $check($policy->snapshot('security')['values']['adaptive_waf']===false,'Fresh App projection reads the persisted native override');
  $stale=$policy->update('security',['expected'=>$saved['expected'],'changes'=>['adaptive_waf'=>true]]);
  $check(is_wp_error($stale)&&get_option('stp_settings')===$choices,'Stale native review cannot overwrite a later individual choice');
  $view=$policy->snapshot('security');$keep=$policy->update('security',['expected'=>$view['expected'],'changes'=>['kiwe.protection_profile'=>'monitor']]);
  $check(!is_wp_error($keep)&&get_option('stp_settings')===$choices,'Keeping individual choices does not switch off other protections');
  $check(get_option('dsa_settings')['secure']['enabled']===true,'Engine remains enabled independently of preset choice');
  $check(!empty(get_option('stp_settings')['block_brute_force']),'Independent brute-force protection remains active');
  $check(!isset(DSA\Access\System_Control_Catalogue::fields('security',false)['kiwe.protection_profile']),'Client administrators gain no technical preset control');
  return ['passed'=>true,'php'=>PHP_VERSION,'woo'=>defined('WC_VERSION')?WC_VERSION:null,'bricks'=>class_exists('Bricks\\Elements',false),'nativeSqlExecuted'=>true];
 }finally{
  foreach($before as$key=>$value)update_option($key,$value,false);
  DSA\Secure\SecureTrack_Settings_Policy::refresh_runtime_cache();
  foreach($before as$key=>$value)$check(get_option($key)===$value,'Exact native fixture restoration '.$key);
 }
}
