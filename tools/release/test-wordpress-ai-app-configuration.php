<?php
/** Run only after the shared connection-local native isolation owner. */
if(PHP_SAPI!=='cli'||!defined('KIWE_SEO_NATIVE_TEST')||!KIWE_SEO_NATIVE_TEST||empty($GLOBALS['kiwe_seo_test_tables'])||home_url()!=='https://plum-giraffe-539524.hostingersite.com')exit(2);
$proof=['schema'=>'kiwe.native-ai-configuration.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
$check=static function($ok,$label)use(&$proof){$proof['checks'][$label]=(bool)$ok;if(!$ok)throw new RuntimeException($label);};
try{
 global $wpdb;
 foreach([900000127=>'administrator',900000128=>'author']as$id=>$role){$check($wpdb->insert($wpdb->users,['ID'=>$id,'user_login'=>'ap127-config-'.$id,'user_pass'=>wp_hash_password(wp_generate_password(32)),'user_email'=>'ap127-'.$id.'@example.invalid','user_registered'=>current_time('mysql',true),'display_name'=>'Synthetic App reviewer'])!==false,'Synthetic native role is confined to temporary account tables');update_user_meta($id,$wpdb->prefix.'capabilities',[$role=>true]);clean_user_cache($id);}
 wp_set_current_user(900000127);
 $check(current_user_can('kiwe_manage_ai_preferences')&&current_user_can('kiwe_manage_context')&&!current_user_can('manage_options'),'Client administrator can configure AI without technical WordPress authority');
 $request=static function(string $method='GET',array $body=[]):WP_REST_Request{$r=new WP_REST_Request($method);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_header('x-kiwe-mutation','1');$r->set_header('content-type','application/json');$r->set_body(wp_json_encode($body));return$r;};
 $workspace=new DSA\Access\AI_Workspace_Service();$read=$workspace->read_app($request());$view=$read->get_data();
 $check($read->get_status()===200&&is_array($view['owner']['configuration']),'Actual client read exposes supported provider configuration');
 $oldExpected=$view['owner']['expected'];$key='synthetic-private-native-key';
 $save=$workspace->save_owner_app($request('POST',['key'=>'configuration','value'=>['native_provider'=>'none','native_api_key'=>$key],'expected'=>$oldExpected]));
 $check($save->get_status()===200,'Native client configuration saves through the atomic Settings writer');
 $ai=get_option(DSA_OPTION_SETTINGS)['ai'];$sealed=$ai['native_api_key'];
 $check($sealed!==$key&&DSA\Security\Secret_Store::decrypt($sealed)===$key,'Actual native Secret Store encrypts and reads back the credential');
 $check(!str_contains(wp_json_encode($save->get_data()),$key)&&!str_contains(wp_json_encode($save->get_data()),$sealed),'Private responses disclose neither plaintext key nor ciphertext');
 $check($workspace->save_owner_app($request('POST',['key'=>'configuration','value'=>['native_model'=>'stale-model'],'expected'=>$oldExpected]))->get_status()===409,'Native stale review cannot overwrite the newer key configuration');
 $view=$workspace->read_app($request())->get_data();$save=$workspace->save_owner_app($request('POST',['key'=>'configuration','value'=>['native_api_key'=>''],'expected'=>$view['owner']['expected']]));
 $check($save->get_status()===200&&get_option(DSA_OPTION_SETTINGS)['ai']['native_api_key']===$sealed,'Blank entry preserves the original encrypted key');
 $view=$workspace->read_app($request())->get_data();$save=$workspace->save_owner_app($request('POST',['key'=>'configuration','value'=>['remove_api_key'=>true],'expected'=>$view['owner']['expected']]));
 $check($save->get_status()===200&&get_option(DSA_OPTION_SETTINGS)['ai']['native_api_key']==='','Explicit native removal clears the protected key');
 wp_set_current_user(900000128);$check($workspace->read_app($request())->get_status()===403&&$workspace->save_owner_app($request('POST',['key'=>'configuration','value'=>['native_model'=>'denied'],'expected'=>$view['owner']['expected']]))->get_status()===403,'Native Author cannot inspect or mutate provider configuration');
 $check(($GLOBALS['kiwe_seo_test_http_calls']??0)===0&&($GLOBALS['kiwe_seo_test_mail_calls']??0)===0,'Configuration reads and edits make no provider or mail request');
 $proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();$proof['line']=$e->getLine();}
finally{try{do_action('shutdown');remove_all_actions('shutdown');kiwe_seo_test_cleanup($proof);}catch(Throwable$e){$proof['passed']=false;$proof['error']=$e->getMessage();}}
echo wp_json_encode($proof);if(!$proof['passed']||!$proof['cleanup'])exit(1);
