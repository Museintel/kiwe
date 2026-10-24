<?php
declare(strict_types=1);
require __DIR__.'/test-team-app.php';
function wp_slash($value){return $value;}
function is_email($value){return filter_var($value,FILTER_VALIDATE_EMAIL)!==false;}
function wp_update_user($input){$id=$input['ID'];foreach($input as$key=>$value){if($key==='ID')continue;if(in_array($key,['display_name','description','user_url'],true))$GLOBALS['users'][$id]->{$key}=$value;else update_user_meta($id,$key,$value);}return $id;}
function WC(){return (object)['countries'=>new class {function get_countries(){return ['IN'=>'India','US'=>'United States'];} function get_states($country){return $country==='IN'?['MH'=>'Maharashtra']:['CA'=>'California'];}}];}
class WC_Customer {
 private array $changes=[];
 function __construct(private int $id){}
 function __call($method,$args){$key=substr($method,4);if(str_starts_with($method,'get_'))return $GLOBALS['usermeta'][$this->id][$key]??'';$this->changes[$key]=$args[0];}
 function save(){foreach($this->changes as$key=>$value)update_user_meta($this->id,$key,$value);return $this->id;}
}
$actor=1;$failed_meta='';$denied=[];$service=new DSA\Access\Team_Workspace_Service();
$read=fn($id,$section)=>$app->read(new WP_REST_Request('GET',['id'=>$id],[],['profile_section'=>$section]));
$save=fn($value,$fields)=>$app->save(new WP_REST_Request('POST',['id'=>$value['id']],['operation'=>'profile','section'=>$value['section'],'values'=>$fields,'expected'=>$value['expected']]));
$view=$read(3,'name')->data;$fields=$view['values'];$fields['display_name']='New public name';$fields['nickname']='New name';$fields['description']='Updated biography';$email=$users[3]->user_email;
check($save($view,$fields)->status===200&&$users[3]->display_name==='New public name'&&$users[3]->description==='Updated biography','Profile writes native WordPress fields');
check($users[3]->user_email===$email&&$users[3]->roles===['author'],'Profile save preserves login and role');
check($save($view,$fields)->status===409,'Stale profile is rejected');
$view=$read(3,'name')->data;foreach(['user_email','user_pass','role','wp_capabilities','bricks_access']as$key)check($save($view,$view['values']+[$key=>'forged'])->status===400,'Reject unlisted profile key: '.$key);
$fields=$view['values'];$fields['user_url']='javascript:alert(1)';check($save($view,$fields)->status===400,'Unsafe website URL rejected');
$view=$read(3,'public')->data;$fields=$view['values'];$fields['facebook']='https://example.test/public';$failed_meta='facebook';check($save($view,$fields)->status===409,'Failed public metadata write cannot report success');$failed_meta='';
$view=$read(4,'billing')->data;$fields=$view['values'];$fields['country']='IN';$fields['state']='MH';$fields['address_1']='Synthetic address';$fields['email']='billing@example.test';
check($save($view,$fields)->status===200&&$usermeta[4]['billing_address_1']==='Synthetic address','Subscriber/customer address uses Woo customer setters');
check($users[4]->user_email==='person4@example.test'&&!isset($usermeta[4]['kiwe_public_team_member']),'Billing edit does not alter identity or team membership');
$view=$read(4,'billing')->data;$fields=$view['values'];$fields['country']='XX';check($save($view,$fields)->status===400,'Unknown Woo country rejected');$fields['country']='IN';$fields['state']='INVALID';check($save($view,$fields)->status===400,'Unknown Woo state rejected');
$fields=$view['values'];$fields['city']='Unconfirmed';$failed_meta='billing_city';check($save($view,$fields)->status===409,'Woo readback catches refused address write');$failed_meta='';
check($read(2,'name')->status===403&&$read(4,'public')->status===404,'Owner account and ineligible public fields remain protected');
$multisite=true;$outside_blog=[4];check($read(4,'name')->status===403,'Cross-blog account read denied');$multisite=false;$outside_blog=[];
$actor=3;check($read(4,'billing')->status===403,'Staff cannot read customer addresses');$actor=1;
$query_users=[$users[2],$users[3],$users[4]];$list=$app->listing(new WP_REST_Request('GET',[],[],['team_view'=>'accounts']))->data;
check(array_column($list['people'],'id')===[3,4]&&!isset($user_query['capability__in'])&&$user_query['number']===13,'Account directory is bounded and excludes owner');
check($list['people'][1]['canTeam']===false,'Nonstaff account has no team controls');
echo "Account section boundary checks passed.\n";
