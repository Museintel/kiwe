<?php
declare(strict_types=1);

function wp_slash($value){return $value;}
function is_email($value){return filter_var($value,FILTER_VALIDATE_EMAIL)!==false;}
function email_exists($email){foreach($GLOBALS['users'] as$user)if(strtolower($user->user_email)===strtolower((string)$email))return $user->ID;return false;}
function username_exists($login){foreach($GLOBALS['users'] as$user)if(strtolower($user->user_login)===strtolower((string)$login))return $user->ID;return false;}
function wp_generate_password($length=12,$special=true,$extra=false){return str_repeat('X',(int)$length);}
function wp_insert_user($input){
 if(!empty($GLOBALS['insert_user_error']))return new WP_Error('insert_failed','Synthetic user creation failed.');
 if(email_exists($input['user_email']))return new WP_Error('existing_user_email','Email already exists.');
 if(username_exists($input['user_login']))return new WP_Error('existing_user_login','Username already exists.');
 $id=max(array_keys($GLOBALS['users']))+1;$user=new WP_User($id,[$input['role']],$input['display_name']);
 $user->user_login=$input['user_login'];$user->user_email=$input['user_email'];$GLOBALS['users'][$id]=$user;
 foreach(['first_name','last_name','nickname']as$key)$GLOBALS['usermeta'][$id][$key]=$input[$key];
 $GLOBALS['created_secret']=$input['user_pass'];return $id;
}
function wp_new_user_notification($id,$deprecated=null,$notify=''){if(!empty($GLOBALS['notification_throw']))throw new RuntimeException('mail unavailable');$GLOBALS['notifications'][]=[$id,$notify];}
function retrieve_password($email){if(!empty($GLOBALS['reset_error']))return new WP_Error('mail_failed','Synthetic mail failure');$GLOBALS['reset_requests'][]=$email;return true;}
function wp_update_user($input){if(!empty($GLOBALS['update_user_error']))return new WP_Error('update_failed','Synthetic user update failure');$id=(int)$input['ID'];$GLOBALS['users'][$id]->user_email=$input['user_email'];return $id;}
function wc_get_orders($args){return !empty($GLOBALS['order_owners'][(int)$args['customer_id']])?[91]:[];}
function wp_delete_user($id,$reassign=null){if(!empty($GLOBALS['delete_user_error']))return false;if(!$reassign)return false;foreach($GLOBALS['posts'] as$post)if(($post->post_author??0)===$id)$post->post_author=$reassign;unset($GLOBALS['users'][$id]);return true;}
function wp_set_password($password,$id){$GLOBALS['users'][$id]->user_pass=password_hash($password,PASSWORD_DEFAULT);}
function wp_check_password($password,$hash,$id=0){return password_verify($password,$hash);}
define('MINUTE_IN_SECONDS',60);
class WP_Session_Tokens {public static function get_instance($id): self {$GLOBALS['session_target']=$id;return new self();}public function destroy_all(): void {$GLOBALS['sessions_destroyed'][]=$GLOBALS['session_target'];}}
function delete_user_meta($id,$key){if(!isset($GLOBALS['usermeta'][$id][$key]))return false;unset($GLOBALS['usermeta'][$id][$key]);return true;}
function do_action($name,...$args){$GLOBALS['account_actions'][]=[$name,...$args];}

require __DIR__.'/test-team-app.php';

$actor=1;$denied=[];$notifications=[];$notification_throw=false;$insert_user_error=false;
$invite=fn(array $body,array $headers=['X-WP-Nonce'=>'wp_rest','X-Kiwe-Mutation'=>'1'])=>$app->create(new WP_REST_Request('POST',[],$body,[],$headers));
$role=fn(int $id,array $body)=>$app->save(new WP_REST_Request('POST',['id'=>$id],$body));
$valid=['operation'=>'invite','email'=>'person3@new.test','firstName'=>'Asha','lastName'=>'Patel','role'=>'author'];

$list=$app->listing(new WP_REST_Request())->data;
check($list['accountPolicy']['canCreate']&&$list['accountPolicy']['canChangeRoles']&&array_column($list['accountPolicy']['roles'],'id')===['subscriber','author','editor','administrator'],'client Administrator receives only installed, bounded non-commerce role choices');
check($access->guard_rest(null,null,new WP_REST_Request('POST',[], $valid))===null,'exact native account invitation route passes the outer client boundary');
$created=$invite($valid);
check($created->status===200&&$created->data['notificationRequested']===true&&$created->data['login']==='person3-2','native invitation derives a unique username and requests the WordPress setup email');
$new=$created->data['person'];
check($new['roles'][0]['id']==='author'&&$new['account']['role']==='author'&&$new['profile']['wordpress']['email']==='person3@new.test','created account returns the explicit native account projection');
check($notifications===[[$new['id'],'user']]&&strlen($created_secret)===32&&!isset($created->data['password'])&&!isset($created->data['secret']),'generated credential is never returned and only WordPress owns setup delivery');

foreach([
 ['payload'=>array_replace($valid,['email'=>'invalid']),'status'=>400,'label'=>'invalid email'],
 ['payload'=>array_replace($valid,['email'=>'new@example.test','role'=>'contributor']),'status'=>400,'label'=>'Guest contributor bypass'],
 ['payload'=>array_replace($valid,['email'=>'new@example.test','role'=>'kiwe_super_admin']),'status'=>400,'label'=>'owner role assignment'],
 ['payload'=>$valid+['password'=>'forged'],'status'=>400,'label'=>'caller supplied password'],
 ['payload'=>array_replace($valid,['email'=>'person3@example.test']),'status'=>409,'label'=>'duplicate email'],
] as$case)check($invite($case['payload'])->status===$case['status'],'invitation rejects '.$case['label']);

$before=count($users);$actor=3;check($invite(array_replace($valid,['email'=>'lower@example.test']))->status===403&&count($users)===$before,'lower role cannot create an account');$actor=1;
$notification_throw=true;$created_without_mail=$invite(array_replace($valid,['email'=>'maildown@example.test']));$notification_throw=false;
check($created_without_mail->status===200&&$created_without_mail->data['notificationRequested']===false&&email_exists('maildown@example.test'),'mail request failure preserves the confirmed native account and reports delivery uncertainty');
$insert_user_error=true;$notifications_before=count($notifications);check($invite(array_replace($valid,['email'=>'fail@example.test']))->status===409&&count($notifications)===$notifications_before,'failed account creation never requests a notification');$insert_user_error=false;

$expected=DSA\Access\Team_Workspace_Service::role_fingerprint(3);
$changed=$role(3,['operation'=>'role','role'=>'editor','expected'=>$expected]);
check($changed->status===200&&$users[3]->roles===['editor']&&$changed->data['account']['role']==='editor','role task writes and observes the native WordPress role');
check($role(3,['operation'=>'role','role'=>'author','expected'=>$expected])->status===409&&$users[3]->roles===['editor'],'stale role fingerprint prevents overwrite');
$fresh=DSA\Access\Team_Workspace_Service::role_fingerprint(3);
check($role(3,['operation'=>'role','role'=>'contributor','expected'=>$fresh])->status===400&&$role(3,['operation'=>'role','role'=>'kiwe_super_admin','expected'=>$fresh])->status===400,'role task cannot bypass Guest approval or assign ownership');
check($role(2,['operation'=>'role','role'=>'author','expected'=>DSA\Access\Team_Workspace_Service::role_fingerprint(2)])->status===403,'site owner role is protected');
check($role(1,['operation'=>'role','role'=>'author','expected'=>DSA\Access\Team_Workspace_Service::role_fingerprint(1)])->status===403,'signed-in Administrator cannot demote their own account');
$users[6]->roles=['author','editor'];check($role(6,['operation'=>'role','role'=>'author','expected'=>DSA\Access\Team_Workspace_Service::role_fingerprint(6)])->status===403,'multi-role account is not flattened by the simplified task');
$users[6]->roles=['editor'];$denied=['promote_user:6'];check($role(6,['operation'=>'role','role'=>'author','expected'=>DSA\Access\Team_Workspace_Service::role_fingerprint(6)])->status===403,'object-level promote denial remains authoritative');$denied=[];

$identity=fn(int $id,array $body)=>$app->save(new WP_REST_Request('POST',['id'=>$id],$body));
$emailExpected=DSA\Access\Team_Workspace_Service::identity_fingerprint(3);
$reset=$identity(3,['operation'=>'password_reset','expected'=>$emailExpected]);
check($reset->status===200&&$reset->data['notificationRequested']===true&&$reset_requests===['person3@example.test']&&!isset($reset->data['key'])&&!isset($reset->data['url']),'WordPress receives one password-reset request without disclosing a key or URL');
check($identity(3,['operation'=>'password_reset','expected'=>$emailExpected])->status===429&&count($reset_requests)===1,'repeated reset is rate limited for the account');
check($identity(3,['operation'=>'password_reset','expected'=>$emailExpected,'email'=>'other@example.test'])->status===400,'reset cannot redirect to an arbitrary address');
unset($transients['kiwe_account_reset_3']);$reset_error=true;
check($identity(3,['operation'=>'password_reset','expected'=>$emailExpected])->status===409&&!isset($transients['kiwe_account_reset_3']),'mail request failure does not claim delivery or start cooldown');$reset_error=false;
$newEmail='changed@example.test';$emailBody=['operation'=>'login_email','expected'=>$emailExpected,'email'=>$newEmail,'confirmEmail'=>$newEmail];
check($identity(3,array_replace($emailBody,['confirmEmail'=>'mismatch@example.test']))->status===400,'login email requires repeated confirmation');
check($identity(3,array_replace($emailBody,['email'=>'person1@example.test','confirmEmail'=>'person1@example.test']))->status===409,'existing account email cannot be taken');
$update_user_error=true;check($identity(3,$emailBody)->status===409&&$users[3]->user_email==='person3@example.test','failed WordPress email write leaves identity unchanged');$update_user_error=false;
$changedEmail=$identity(3,$emailBody);
check($changedEmail->status===200&&$users[3]->user_email===$newEmail&&$changedEmail->data['profile']['wordpress']['email']===$newEmail,'login email writes and reads back native WordPress identity');
check($identity(3,$emailBody)->status===409,'stale email task cannot overwrite a changed identity');
check($identity(2,['operation'=>'password_reset','expected'=>DSA\Access\Team_Workspace_Service::identity_fingerprint(2)])->status===403&&$identity(1,['operation'=>'login_email','expected'=>DSA\Access\Team_Workspace_Service::identity_fingerprint(1),'email'=>'x@example.test','confirmEmail'=>'x@example.test'])->status===403,'owner and signed-in account identities remain protected');
$denied=['edit_user:3'];check($identity(3,['operation'=>'password_reset','expected'=>DSA\Access\Team_Workspace_Service::identity_fingerprint(3)])->status===403,'target edit_user authority applies to recovery');$denied=[];
$actor=6;check($identity(3,['operation'=>'password_reset','expected'=>DSA\Access\Team_Workspace_Service::identity_fingerprint(3)])->status===403,'editor cannot send another account reset');$actor=1;

function pk_security_completion($id){return ['state'=>'partial','emailVerified'=>false,'phoneVerified'=>true,'passkeyEnrolled'=>false];}
function pk_factor_verified($id,$factor){return false;}
function pk_send_email_otp_or_link($id,$email,$flow,$method){$GLOBALS['verification_requests'][]=[$id,$email,$method];return ['accepted'=>empty($GLOBALS['verification_error'])];}
$person=$app->read(new WP_REST_Request('GET',['id'=>3]))->data;
check($person['security']['verification']==='partial'&&$person['security']['phoneVerified']===true&&$person['account']['canVerify'],'Users reuses PhoneKey verification state without exposing secrets');
$current=DSA\Access\Team_Workspace_Service::identity_fingerprint(3);
$verify=$identity(3,['operation'=>'verify_email','expected'=>$current]);
check($verify->status===200&&$verify->data['notificationRequested']===true&&$verification_requests===[[3,'changed@example.test','magic_link']],'verification action asks PhoneKey to send a link to the native login email');
check($identity(3,['operation'=>'verify_email','expected'=>$current])->status===429&&count($verification_requests)===1,'verification requests are rate limited');
$ban=$identity(3,['operation'=>'suspend','expected'=>$current,'suspended'=>true]);
check($ban->status===200&&$ban->data['security']['suspended']===true&&$sessions_destroyed===[3]&&$access->deny_suspended_cookie(3)===false,'ban revokes sessions and rejects existing WordPress cookies');
check(is_wp_error($access->deny_suspended_password($users[3])),'ban rejects native password login');
check($identity(3,['operation'=>'suspend','expected'=>$current,'suspended'=>false])->status===409,'stale access fingerprint cannot undo a ban');
$current=DSA\Access\Team_Workspace_Service::identity_fingerprint(3);
$unban=$identity(3,['operation'=>'suspend','expected'=>$current,'suspended'=>false]);
check($unban->status===200&&$unban->data['security']['suspended']===false&&$access->deny_suspended_cookie(3)===3,'unban restores native authentication without reviving old sessions');
check($identity(2,['operation'=>'suspend','expected'=>DSA\Access\Team_Workspace_Service::identity_fingerprint(2),'suspended'=>true])->status===403,'site owner cannot be banned through the client account workspace');
$passwordBody=['operation'=>'set_password','expected'=>DSA\Access\Team_Workspace_Service::identity_fingerprint(3),'password'=>'NewLongPassphrase!2026','confirmPassword'=>'NewLongPassphrase!2026'];
check($identity(3,array_replace($passwordBody,['confirmPassword'=>'Mismatch']))->status===400,'direct password change requires matching confirmation');
check($identity(2,array_replace($passwordBody,['expected'=>DSA\Access\Team_Workspace_Service::identity_fingerprint(2)]))->status===403,'direct password change protects the site owner');
$passwordChanged=$identity(3,$passwordBody);
check($passwordChanged->status===200&&$passwordChanged->data['changed']===true&&wp_check_password($passwordBody['password'],$users[3]->user_pass,3)&&$sessions_destroyed===[3,3]&&!isset($passwordChanged->data['password']),'WordPress confirms the changed password, ends sessions and never returns the secret');

$plan=fn(int $id,string $search='')=>$app->read(new WP_REST_Request('GET',['id'=>$id],[],['account_task'=>'remove','recipient_search'=>$search]));
$remove=fn(int $id,array $body)=>$app->save(new WP_REST_Request('POST',['id'=>$id],$body));
$users[7]=new WP_User(7,['author'],'Synthetic writer');$posts[104]=(object)['ID'=>104,'post_type'=>'post','post_status'=>'publish','post_author'=>7];
$planned=$plan(7);check($planned->status===200&&$planned->data['username']==='person7'&&$planned->data['recipients'][0]['id']===1&&!$planned->data['hasOrders'],'removal plan offers a bounded client Administrator recipient');
$body=['operation'=>'remove_account','expected'=>$planned->data['expected'],'reassignTo'=>1,'confirmLogin'=>'person7','confirmTransfer'=>true];
check($remove(7,array_replace($body,['confirmLogin'=>'wrong']))->status===400&&get_userdata(7),'account removal requires exact typed username');
check($remove(7,array_replace($body,['reassignTo'=>3]))->status===400&&get_userdata(7),'content cannot be reassigned to another editorial role');
check($remove(7,$body+['deletePosts'=>true])->status===400&&get_userdata(7),'account removal rejects forged delete-content field');
$order_owners=[7=>true];check($plan(7)->data['hasOrders']&&$plan(7)->data['recipients']===[]&&$remove(7,$body)->status===409&&get_userdata(7),'WooCommerce order ownership blocks simplified deletion');$order_owners=[];
$denied=['delete_user:7'];check($remove(7,$body)->status===403&&get_userdata(7),'target delete_user authority is enforced');$denied=[];
$multisite=true;check($plan(7)->status===403&&$remove(7,$body)->status===403&&get_userdata(7),'multisite membership is kept out of site-level account removal');$multisite=false;
$actor=6;check($remove(7,$body)->status===403&&get_userdata(7),'editor cannot remove another account');$actor=1;
$removed=$remove(7,$body);check($removed->status===200&&$removed->data['reassignedTo']===1&&!get_userdata(7)&&$posts[104]->post_author===1,'WordPress removes account only after transferring authored content');
check($remove(7,$body)->status===403,'completed removal cannot be replayed');

echo "Account lifecycle boundary checks passed.\n";
