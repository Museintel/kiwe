<?php
declare(strict_types=1);
ob_start(); require __DIR__.'/test-client-workspace.php'; $prior=ob_get_clean();
if (!str_contains($prior,'133 workspace policy tests passed.')) throw new RuntimeException('Native-owner policy fixture prerequisite');
function wp_json_encode($value){return json_encode($value);}
function is_admin(){return true;} function is_network_admin(){return false;} function is_user_admin(){return false;}
function is_user_member_of_blog($id){return true;}
function user_can($user,$cap){return $user->has_cap($cap);}
$checks=0;$check=static function($ok,$why)use(&$checks){++$checks;if(!$ok)throw new RuntimeException($why);};
$workspace=new DSA\Access\Workspace_Admin_Service();$multisite=false;$actor=2;
$original=DSA\Access\Site_Owner_Service::policy();$before=$original;unset($before['dashboardMandatory']);$options[DSA\Access\Site_Owner_Service::OPTION]=$before;
$check(DSA\Access\Site_Owner_Service::dashboard_mandatory(),'Missing choice preserves mandatory mode');
$snapshot=DSA\Access\Site_Owner_Service::dashboard_policy();$check(is_array($snapshot)&&$snapshot['mandatory'],'Only designated owner receives the control');
foreach([1,3,4,5,6,7] as $id){$actor=$id;$check(DSA\Access\Site_Owner_Service::dashboard_policy()===null,'No owner projection for role '.$id);$value=$owner->update_dashboard(['expected'=>$snapshot['expected'],'mandatory'=>false]);$check($value instanceof WP_Error&&$value->data['status']===403&&DSA\Access\Site_Owner_Service::policy()===$before,'Forged role write refused '.$id);}
$actor=2;
foreach([['mandatory'=>0],['mandatory'=>'false'],['mandatory'=>false,'unexpected'=>true]]as$fields){$value=$owner->update_dashboard(['expected'=>$snapshot['expected'],...$fields]);$check($value instanceof WP_Error&&DSA\Access\Site_Owner_Service::policy()===$before,'Malformed choice changes no access policy');}
$options['kiwe_owner_transfer_lock']=time();$value=$owner->update_dashboard(['expected'=>$snapshot['expected'],'mandatory'=>false]);$check($value instanceof WP_Error&&$value->data['status']===409,'Ownership transfer lock arbitrates dashboard changes');unset($options['kiwe_owner_transfer_lock']);
$value=$owner->update_dashboard(['expected'=>$snapshot['expected'],'mandatory'=>false]);$check(is_array($value)&&$value['mandatory']===false&&!isset($options['kiwe_owner_transfer_lock']),'Owner disables mandatory mode and releases lock');
$expected=$before;$expected['dashboardMandatory']=false;$check(DSA\Access\Site_Owner_Service::policy()===$expected,'All ownership and Rank Math settings preserved');
$check($owner->update_dashboard(['expected'=>$snapshot['expected'],'mandatory'=>true]) instanceof WP_Error,'A stale owner tab cannot overwrite newer access choice');
foreach([1,4,6,7]as$id){$actor=$id;$user=$users[$id];$check(DSA\Access\Workspace_Admin_Service::uses_workspace($user)&&!DSA\Access\Workspace_Admin_Service::requires_workspace($user),'Optional retains Kiwe eligibility without forcing role '.$id);$check($workspace->login_destination('https://site.test/wp-admin/',$user)==='https://site.test/wp-admin/','Optional login retains original native destination '.$id);$check(DSA\Access\Workspace_Admin_Service::can_open_native_dashboard($user),'Optional has native dashboard return '.$id);$GLOBALS['pagenow']='index.php';$_SERVER['REQUEST_METHOD']='GET';$_GET=[];$check($workspace->redirect_dashboard()===null,'Optional native dashboard is not redirected '.$id);$check(DSA\Access\Workspace_Admin_Service::app_launch_url($user)!=='','Installed app retains explicit Kiwe launch '.$id);}
$actor=3;$check(!DSA\Access\Workspace_Admin_Service::uses_workspace($users[3])&&!DSA\Access\Workspace_Admin_Service::can_open_native_dashboard($users[3]),'Subscriber gets no dashboard grant');
$actor=2;$snapshot=DSA\Access\Site_Owner_Service::dashboard_policy();$value=$owner->update_dashboard(['expected'=>$snapshot['expected'],'mandatory'=>true]);$check(is_array($value)&&$value['mandatory'],'Owner enables mandatory mode');
$actor=1;$check(DSA\Access\Workspace_Admin_Service::requires_workspace($users[1])&&!DSA\Access\Workspace_Admin_Service::can_open_native_dashboard($users[1]),'Client must use Kiwe without native-dashboard escape');
$_GET=['kiwe-native-dashboard'=>'1'];$redirect=false;try{$workspace->redirect_dashboard();}catch(Redirected $e){$redirect=$e->getMessage()===DSA\Access\Workspace_Admin_Service::url();}$check($redirect,'Client cannot bypass mandatory mode with the owner query flag');
$actor=2;$check(!DSA\Access\Workspace_Admin_Service::requires_workspace($users[2])&&$workspace->redirect_dashboard()===null,'Super Admin retains explicit native dashboard');
$fail_store=true;$snapshot=DSA\Access\Site_Owner_Service::dashboard_policy();$value=$owner->update_dashboard(['expected'=>$snapshot['expected'],'mandatory'=>false]);$check($value instanceof WP_Error&&$value->data['status']===500&&DSA\Access\Site_Owner_Service::dashboard_mandatory()&&!isset($options['kiwe_owner_transfer_lock']),'Failed storage is not reported saved and releases lock');
$actor=1;$bar=new class{public array $nodes=[];function add_node($node){$this->nodes[$node['id']]=$node;}};$workspace->toolbar($bar);$check(($bar->nodes['kiwe-workspace']['href']??'')===DSA\Access\Workspace_Admin_Service::url(),'Native toolbar has a Kiwe shortcut');
$actor=3;$bar->nodes=[];$workspace->toolbar($bar);$check(!$bar->nodes,'Front-end subscriber receives no staff shortcut');
$actor=2;$multisite=true;$network=['user2'];$snapshot=DSA\Access\Site_Owner_Service::dashboard_policy();$value=$owner->update_dashboard(['expected'=>$snapshot['expected'],'mandatory'=>false]);$check(is_array($value)&&$value['mandatory']===false,'Native network owner saves through original network policy owner');
echo json_encode(['passed'=>true,'cleanup'=>true,'checks'=>$checks,'prerequisiteChecks'=>133,'nativeSqlExecuted'=>false,'scope'=>'Actual owner policy, role-aware destinations, original owner state and lock behavior; native HTTP proof is separate']).PHP_EOL;
