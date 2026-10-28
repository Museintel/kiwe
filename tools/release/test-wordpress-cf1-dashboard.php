<?php
/** Composed with the existing connection-local native isolation and tag-search fixture. */
if(PHP_SAPI!=='cli'||!defined('KIWE_CF1_NATIVE_TEST')||KIWE_CF1_NATIVE_TEST!==true||empty($GLOBALS['kiwe_seo_test_tables']))throw new RuntimeException('Owned native CF1 fixture required');
function kiwe_cf1_dashboard_run():array{
	global$wpdb;$out=['passed'=>false,'cleanup'=>false,'checks'=>[]];$check=static function($ok,$label)use(&$out){$out['checks'][$label]=(bool)$ok;if(!$ok)throw new RuntimeException($label);};
	try{
		foreach(['litespeed_purge_all','litespeed_purge_post','litespeed_purge_url','publish_post','user_register','set_user_role']as$hook)remove_all_actions($hook);
		$wpdb->insert($wpdb->users,['ID'=>1,'user_login'=>'cf1syntheticowner','user_pass'=>'not-an-authenticatable-password','user_email'=>'owner@example.invalid','display_name'=>'Synthetic owner','user_registered'=>current_time('mysql',true)]);update_user_meta(1,$wpdb->prefix.'capabilities',['administrator'=>true]);wp_set_current_user(0);wp_set_current_user(1);
		update_option(DSA\Access\Site_Owner_Service::OPTION,['enabled'=>true,'ownerId'=>1,'rankMath'=>'inline'],false);
		$check(current_user_can('kiwe_manage_context')&&DSA\Access\Site_Owner_Service::can_manage_dashboard(),'Actual native owner authority is retained in isolated tables');
		kiwe_cf1_search_seed();$out['search']=kiwe_cf1_search_run(true);$check($out['search']['passed'],'Installed signed Core passes real PHP8.2 WordPress/MariaDB/Woo tag search');wp_set_current_user(1);
		$service=new DSA\Access\Site_Owner_Service();$original=DSA\Access\Site_Owner_Service::policy();$snapshot=DSA\Access\Site_Owner_Service::dashboard_policy();
		$saved=$service->update_dashboard(['expected'=>$snapshot['expected'],'mandatory'=>false]);$check(is_array($saved)&&!$saved['mandatory']&&get_option(DSA\Access\Site_Owner_Service::OPTION)['dashboardMandatory']===false,'Native owner writes the optional choice through the original policy');$check(array_diff_key(DSA\Access\Site_Owner_Service::policy(),['dashboardMandatory'=>true])===$original,'Native access owner and Rank Math choices remain exact');
		$id=wp_insert_user(['user_login'=>'cf1syntheticadmin','user_pass'=>wp_generate_password(36),'user_email'=>'admin@example.invalid','role'=>'administrator']);$check(!is_wp_error($id),'Native client Administrator fixture created');wp_set_current_user(0);wp_set_current_user($id);
		$check(DSA\Access\Site_Owner_Service::dashboard_policy()===null&&!DSA\Access\Site_Owner_Service::can_manage_dashboard(),'Native client gets no Super Admin control');$check(!DSA\Access\Workspace_Admin_Service::requires_workspace(wp_get_current_user())&&DSA\Access\Workspace_Admin_Service::can_open_native_dashboard(wp_get_current_user()),'Native optional client retains WordPress dashboard access');
		$before=get_option(DSA\Access\Site_Owner_Service::OPTION);$value=$service->update_dashboard(['expected'=>$saved['expected'],'mandatory'=>true]);$check(is_wp_error($value)&&get_option(DSA\Access\Site_Owner_Service::OPTION)===$before,'Forged client direct writer changes no native access state');
		$app=new DSA\Access\App_Workspace_Service();$view=$app->snapshot();$check($view['dashboardPolicy']===null,'App projection hides owner policy from native client');
		$_SERVER['HTTP_ORIGIN']=home_url();$_SERVER['HTTP_REFERER']=admin_url();$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';$request=new WP_REST_Request('POST','/dsa/v1/workspace/app/dashboard');$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$request->set_header('X-Kiwe-Mutation','1');$request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode(['expected'=>$saved['expected'],'mandatory'=>true]));$check($app->save_dashboard($request)->get_status()===403,'Native nonce alone cannot grant the owner endpoint');
		wp_set_current_user(0);wp_set_current_user(1);$state=DSA\Access\Site_Owner_Service::dashboard_policy();$value=$service->update_dashboard(['expected'=>$state['expected'],'mandatory'=>true]);$check(is_array($value)&&$value['mandatory'],'Native owner restores mandatory mode');wp_set_current_user(0);wp_set_current_user($id);$check(DSA\Access\Workspace_Admin_Service::requires_workspace(wp_get_current_user())&&!DSA\Access\Workspace_Admin_Service::can_open_native_dashboard(wp_get_current_user()),'Native mandatory client follows Kiwe while capabilities remain native');
		$check($GLOBALS['kiwe_seo_test_http_calls']===0&&$GLOBALS['kiwe_seo_test_mail_calls']===0,'No fixture delivery or provider request');$out['passed']=true;
	}catch(Throwable$error){$out['error']=$error->getMessage();}
	finally{kiwe_seo_test_cleanup($out);}
	return$out;
}
