<?php
/** Native WordPress fixture: the existing connection-local schema owner contains every write. */
if ( PHP_SAPI !== 'cli' || ! defined('KIWE_NATIVE_WORKFLOW_TEST') || true !== KIWE_NATIVE_WORKFLOW_TEST || empty($GLOBALS['kiwe_seo_test_tables']) ) throw new RuntimeException('Owned native workflow fixture required');
function kiwe_optional_native_workflow_run(): array {
	global $wpdb, $menu, $submenu, $pagenow, $current_screen;
	$out=['passed'=>false,'cleanup'=>false,'checks'=>[]];
	$check=static function($ok,$why)use(&$out){$out['checks'][$why]=(bool)$ok;if(!$ok)throw new RuntimeException($why);};
	$restore=[$menu??null,$submenu??null,$pagenow??null,$current_screen??null,$_GET,$_POST,$_REQUEST,$_SERVER['REQUEST_METHOD']??null];
	$redirect=static function($url){throw new RuntimeException('Unexpected redirect: '.$url);};
	try {
		foreach(['litespeed_purge_all','litespeed_purge_post','litespeed_purge_url','publish_post','user_register','set_user_role']as$hook)remove_all_actions($hook);
		foreach([1=>'administrator',2=>'administrator',3=>'author',4=>'editor']as$id=>$role){
			$check($wpdb->insert($wpdb->users,['ID'=>$id,'user_login'=>'workflowfixture'.$id,'user_pass'=>'not-a-login-password','user_email'=>'workflow'.$id.'@example.invalid','display_name'=>'Synthetic workflow '.$id,'user_registered'=>current_time('mysql',true)])===1,'Connection-local user '.$id);
			update_user_meta($id,$wpdb->prefix.'capabilities',[$role=>true]);
		}
		update_option(DSA\Access\Site_Owner_Service::OPTION,['enabled'=>true,'ownerId'=>1,'rankMath'=>'inline','dashboardMandatory'=>false],false);
		$policy=DSA\Access\Site_Owner_Service::policy();
		require_once ABSPATH.'wp-admin/includes/class-wp-screen.php'; require_once ABSPATH.'wp-admin/includes/screen.php';
		set_current_screen('dashboard');
		$service=new DSA\Access\Workspace_Admin_Service();$access=new DSA\Access\WordPress_Role_Access_Service();
		add_filter('wp_redirect',$redirect,PHP_INT_MAX);
		foreach([1,2,3,4]as$id){
			wp_set_current_user(0);wp_set_current_user($id);
			$check(is_admin()&&current_user_can('read'),'Native admin context '.$id);
			$check(apply_filters('login_redirect',admin_url(),'',wp_get_current_user())===admin_url(),'Native login destination '.$id);
			foreach(['index.php','edit.php','post-new.php','post.php','upload.php','media-new.php','edit-tags.php']as$script){
				$pagenow=$script;$_GET=$_POST=$_REQUEST=[];$_SERVER['REQUEST_METHOD']='GET';
				$service->redirect_dashboard();$access->guard_editorial_admin_route();$check(true,'Native task retained '.$id.' '.$script);
			}
			$menu=[['Dashboard','read','index.php'],['Posts','edit_posts','edit.php'],['Media','upload_files','upload.php']];$submenu=['edit.php'=>[['All posts','edit_posts','edit.php'],['Add post','create_posts','post-new.php']]];$original=[$menu,$submenu];
			$access->limit_editorial_menu();$check([$menu,$submenu]===$original,'Native menu retained '.$id);
			$check(DSA\Access\Workspace_Admin_Service::app_launch_url(wp_get_current_user())===DSA\Access\Workspace_Admin_Service::url(),'Explicit installed-app launch retained '.$id);
		}
		wp_set_current_user(0);wp_set_current_user(2);
		$check(current_user_can('create_posts')&&current_user_can('rank_math_onpage_general')&&!current_user_can('install_plugins'),'Client post and inline Rank Math capabilities without software authority');
		$check(!DSA\Access\Site_Owner_Service::can_manage_dashboard(),'Client has no owner policy writer');
		update_option(DSA\Access\Site_Owner_Service::OPTION,array_replace($policy,['dashboardMandatory'=>true]),false);
		$check(DSA\Access\Workspace_Admin_Service::requires_workspace(wp_get_current_user()),'Mandatory staff routing retained');
		$pagenow='index.php';$_GET=$_REQUEST=[];$caught=false;try{$service->redirect_dashboard();}catch(RuntimeException$e){$caught=$e->getMessage()==='Unexpected redirect: '.DSA\Access\Workspace_Admin_Service::url();}$check($caught,'Mandatory Dashboard redirects to Kiwe');
		$pagenow='post-new.php';$caught=false;try{$access->guard_editorial_admin_route();}catch(RuntimeException$e){$caught=$e->getMessage()==='Unexpected redirect: '.DSA\Access\Post_Workspace_Service::url();}$check($caught,'Mandatory Add post still opens Kiwe');
		$check($GLOBALS['kiwe_seo_test_http_calls']===0&&$GLOBALS['kiwe_seo_test_mail_calls']===0,'No notification or external delivery');
		$out['passed']=true;
	}catch(Throwable$error){$out['error']=$error->getMessage();}
	finally{remove_filter('wp_redirect',$redirect,PHP_INT_MAX);[$menu,$submenu,$pagenow,$current_screen,$_GET,$_POST,$_REQUEST,$_SERVER['REQUEST_METHOD']]=$restore;kiwe_seo_test_cleanup($out);}
	return $out;
}
