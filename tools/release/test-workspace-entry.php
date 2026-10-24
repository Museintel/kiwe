<?php
declare(strict_types=1);
// Actual workspace, route policy and completed Key login code; synthetic WordPress I/O.
require __DIR__.'/workspace-test-bootstrap.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Workspace_Admin_Service.php';
function is_super_admin($id){return $id===2;}
function is_network_admin(){return $GLOBALS['entry_network']??false;}function is_user_admin(){return $GLOBALS['entry_user_admin']??false;}
function add_menu_page(...$args){$GLOBALS['home_menu']=$args;}
function remove_menu_page($slug){$GLOBALS['removed_menu'][]=$slug;}
function remove_submenu_page(...$args){}
function esc_url($s){return esc_html($s);}
function esc_attr__($s,$domain=''){return $s;}
function add_action(...$args){$GLOBALS['hooks'][]=$args;}
function add_filter(...$args){$GLOBALS['hooks'][]=$args;}
function wp_send_json_error($data,$status){throw new WorkspaceDenied((string)$status);}
function wp_set_current_user($id){$GLOBALS['actor']=$id;}
function wp_set_auth_cookie(...$args){$GLOBALS['sessions']++;}
function do_action(...$args){}
function apply_filters($hook,$value,...$args){return $hook==='kiwe_workspace_login_destination'?$GLOBALS['workspace']->login_destination($value,$args[0]):$value;}
function pk_canonical_user_id($id){return get_userdata($id)?$id:0;}
function pk_is_privileged($id){return in_array($id,[1,2],true);}
function pk_admin_enrollment_complete($id){return $GLOBALS['enrolled'];}
function pk_flag_admin_enrollment($id){}
function pk_log(...$args){}
function pk_secure_auth_cookie(){return true;}
function pk_after_high_assurance_login(...$args){}
function pk_consume_login_return(){return 'https://example.test/account/';}
function pk_redirect_url(){return 'https://example.test/';}
function pk_counterpart_prompt_data($id,$url,$meta){return [];}
$workspace=new DSA\Access\Workspace_Admin_Service();
$users[7]=new WP_User(7,['shop_manager']);
$users[8]=new WP_User(8,['customer']);
$users[9]=new WP_User(9,['author','administrator']);
$users[10]=new WP_User(10,['kiwe_super_admin']);
$sessions=0;$enrolled=true;$hooks=[];
$workspace->register();
check(!in_array('woocommerce_login_redirect',array_column($hooks,0),true),'Woo login policy is deferred until a regular Woo plugin loads');
eval('function WC() { return null; }');$workspace->register_store_hooks();
foreach(['login_redirect','woocommerce_login_redirect','kiwe_workspace_login_destination']as$hook)check(in_array($hook,array_column($hooks,0),true),'registered destination: '.$hook);
foreach([false,true]as$enabled){
 $options[DSA\Access\Site_Owner_Service::OPTION]=['enabled'=>$enabled,'ownerId'=>2];
 foreach([1,2,3,4,5,6,7,8,9,10]as$actor){
  $expected=in_array($actor,[1,2,3,5,6,7,9],true);
  check(DSA\Access\Workspace_Admin_Service::uses_workspace($users[$actor])===$expected,'workspace eligibility: '.$actor.' policy '.(int)$enabled);
  check(($workspace->login_redirect('https://example.test/account/','https://elsewhere.invalid/',$users[$actor])===DSA\Access\Workspace_Admin_Service::url())===$expected,'login destination: '.$actor.' policy '.(int)$enabled);
 }
}
check($workspace->login_redirect('/unchanged','',new WP_Error('failed'))==='/unchanged','failed authentication is not routed into staff Home');
$options[DSA\Access\Site_Owner_Service::OPTION]=[];$actor=1;
$users[1]->allcaps=array_fill_keys(['read','manage_options','install_plugins','edit_posts'],true);
$workspace->menu();check($home_menu[3]===DSA\Access\Workspace_Admin_Service::PAGE,'pre-ownership Administrator can follow the shared logo to registered Home');
check(current_user_can('manage_options')&&current_user_can('install_plugins'),'pre-ownership Home does not reduce existing Administrator authority');
check(empty($options[DSA\Access\Site_Owner_Service::OPTION]),'Home presentation does not activate ownership policy');
$multisite=true;$outside_blog=[3];check(!DSA\Access\Workspace_Admin_Service::uses_workspace($users[3]),'another network site membership is insufficient');$outside_blog=[];check(DSA\Access\Workspace_Admin_Service::uses_workspace($users[2]),'network owner can use Home on a member site');$multisite=false;
$options[DSA\Access\Site_Owner_Service::OPTION]=['enabled'=>true,'ownerId'=>2];$actor=2;$pagenow='index.php';$_GET=[];$_SERVER['REQUEST_METHOD']='GET';
try{$workspace->redirect_dashboard();throw new RuntimeException('No owner redirect');}catch(WorkspaceRedirect $e){check($e->getMessage()===DSA\Access\Workspace_Admin_Service::url(),'owner wp-admin entry opens Home');}
$removed_menu=[];$home_menu=[];$workspace->menu();check($home_menu[3]===DSA\Access\Workspace_Admin_Service::PAGE&&!in_array('index.php',$removed_menu,true),'owner Home registers without removing native dashboard');
$_GET=['kiwe-native-dashboard'=>'1'];$workspace->redirect_dashboard();check(true,'owner can explicitly open native dashboard');
$_GET=[];$entry_network=true;$workspace->redirect_dashboard();check(true,'network admin root remains native');$entry_network=false;$entry_user_admin=true;$workspace->redirect_dashboard();check(true,'user admin root remains native');$entry_user_admin=false;
$actor=3;$_GET=['kiwe-native-dashboard'=>'1'];try{$workspace->redirect_dashboard();throw new RuntimeException('Staff bypassed Home');}catch(WorkspaceRedirect $e){check(true,'staff cannot opt into technical dashboard');}$_GET=[];
$options[DSA\Access\Site_Owner_Service::OPTION]['enabled']=false;
foreach([3,5,6,7]as$actor){
 $users[$actor]->allcaps=array_fill_keys(['read','upload_files','edit_posts','create_posts','edit_products','create_products','kiwe_guest_submit'],true);
 $pagenow='index.php';$_SERVER['REQUEST_METHOD']='GET';
 try{$workspace->redirect_dashboard();throw new RuntimeException('No redirect');}catch(WorkspaceRedirect $e){check($e->getMessage()===DSA\Access\Workspace_Admin_Service::url(),'old dashboard redirects to Kiwe: '.$actor);}
 $_SERVER['REQUEST_METHOD']='POST';$workspace->redirect_dashboard();check(true,'dashboard POST is not redirected: '.$actor);
 $pagenow='admin.php';$_SERVER['REQUEST_METHOD']='GET';$_GET=['page'=>DSA\Access\Workspace_Admin_Service::PAGE];$_REQUEST=$_GET;
 $workspace->redirect_dashboard();$access->guard_editorial_admin_route();check(true,'Kiwe Home route has no loop: '.$actor);
 $workspace->menu();check($home_menu[3]===DSA\Access\Workspace_Admin_Service::PAGE&&in_array('index.php',$removed_menu,true),'dedicated Home replaces default menu: '.$actor);
 $menu=[['Home','read',DSA\Access\Workspace_Admin_Service::PAGE],['Posts','edit_posts','edit.php'],['Plugins','activate_plugins','plugins.php']];$removed_menu=[];$access->limit_editorial_menu();check(!in_array(DSA\Access\Workspace_Admin_Service::PAGE,$removed_menu,true)&&in_array('plugins.php',$removed_menu,true),'staff menu retains Home and excludes technical tools: '.$actor);
}
$actor=3;$pagenow='post-new.php';$_GET=$_REQUEST=[];$access->guard_editorial_admin_route();check(true,'Author can open native creation with ownership policy off');
$actor=6;$users[6]->allcaps['create_posts']=false;check(denied(fn()=>$access->guard_editorial_admin_route()),'Editor cannot open new-post route');
$actor=7;$_GET=$_REQUEST=['post_type'=>'product'];$access->guard_editorial_admin_route();check(true,'shop manager can open product creation with policy off');
$actor=3;$pagenow='plugins.php';$_GET=$_REQUEST=[];check(denied(fn()=>$access->guard_editorial_admin_route()),'staff technical route denied');
foreach([3,5,6,7]as$actor)check(!$workspace->commerce_admin_access(true),'Woo customer redirect permits existing staff workspace: '.$actor);
$actor=4;check($workspace->commerce_admin_access(true),'Woo customer redirect remains active for visitors');
$actor=3;
$pagenow='admin-post.php';$_POST=['action'=>'unknown_action'];check(denied(fn()=>$access->guard_editorial_admin_route()),'unknown staff write remains denied');
$options[DSA\Access\Site_Owner_Service::OPTION]=['enabled'=>true,'ownerId'=>2];$actor=1;$pagenow='admin.php';$_GET=['page'=>'kiwe-design-context'];$_SERVER['REQUEST_METHOD']='GET';$access->guard_editorial_admin_route();check(true,'context manager can open the resumable journey');
$pagenow='admin-post.php';$_POST=['action'=>'kiwe_context_journey'];$_SERVER['REQUEST_METHOD']='POST';$access->guard_editorial_admin_route();check(true,'context manager can reach the guarded native save');
$_SERVER['REQUEST_METHOD']='GET';check(denied(fn()=>$access->guard_editorial_admin_route()),'journey write route refuses GET');
$_SERVER['REQUEST_METHOD']='POST';$denied=['kiwe_manage_context'];check(denied(fn()=>$access->guard_editorial_admin_route()),'revoked context management blocks journey saves');$denied=[];
$actor=3;$pagenow='admin.php';$_GET=['page'=>'kiwe-design-context'];$_SERVER['REQUEST_METHOD']='GET';check(denied(fn()=>$access->guard_editorial_admin_route()),'author cannot enter private setup');$_GET=$_POST=[];
// Evaluate only the real completed-login function; never stub its ordering.
$source=file_get_contents(dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
$start=strpos($source,'function pk_finish_login(');$end=strpos($source,'function pk_known_device_for_user(',$start);eval(substr($source,$start,$end-$start));
$actor=3;$result=pk_finish_login(3,'test',false);check($result['ok']&&$result['redirect']===DSA\Access\Workspace_Admin_Service::url(),'completed Key staff login overrides remembered visitor return');
$result=pk_finish_login(4,'test',false);check($result['ok']&&$result['redirect']==='https://example.test/account/','completed Key visitor login preserves return');
$enrolled=false;$before=$sessions;$result=pk_finish_login(1,'test',false);check(!$result['ok']&&$result['code']==='privileged_setup_incomplete'&&$sessions===$before,'incomplete privileged enrollment still blocks session and redirect');
$result=pk_finish_login(999,'test',false);check(!$result['ok']&&$sessions===$before,'invalid Key account gets no session or workspace redirect');
echo "$count workspace entry checks passed.\n";
