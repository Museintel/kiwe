<?php
/** Run only through the CLI harness with owned native replicas and delivery blocked. */
if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || true !== KIWE_WORKSPACE_TEST_SITE || empty( $GLOBALS['kiwe_media_tables_isolated'] ) ) exit(2);

use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
use DSA\Backup\Account_Profile_Snapshot_Service as State;
use DSA\Access\Backup_Workspace_Service as Workspace;
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\Team_Workspace_Service as Native;
use DSA\Onboarding\Design_Context_Profile_Service as Keys;

$proof=['schema'=>'kiwe-native-account-profile-snapshots.v1','version'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'checks'=>[],'cleanup'=>false];
$GLOBALS['kiwe_media_proof'] =& $proof;
$assert=static function(bool $ok,string $label)use(&$proof):void{$proof['checks'][]=['label'=>$label,'passed'=>$ok];if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable $fn,string $label)use($assert):void{$rejected=false;try{$fn();}catch(Throwable $error){$rejected=true;}$assert($rejected,$label);};
$prefix='kiweprofileqa_'.bin2hex(random_bytes(6));$actor=get_current_user_id();$copies=[];$reviews=[];$filters=[];$service=new Snapshots();$phrase='synthetic-account-profile-recovery-phrase';$clean=true;
$filter=static function(string $hook,callable $callback,int $args=1)use(&$filters):void{add_filter($hook,$callback,PHP_INT_MAX,$args);$filters[]=[$hook,$callback];};
$dispatch=static function(string $method,string $path='',?array $body=null,bool $nonce=true,bool $mutation=true){$r=new WP_REST_Request($method,'/dsa/v1/workspace/backups'.$path);if($nonce)$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($mutation)$r->set_header('X-Kiwe-Mutation','1');if($body!==null){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}return rest_do_request($r);};
$review=static function(string $bytes)use($service,$phrase,&$reviews):array{$r=$service->preview_upload($bytes,$phrase);$reviews[]=$r['token'];return $r;};
$collect=static function()use($service,&$copies):void{foreach($service->inventory()as$c)$copies[$c['id']]=true;};
$guarded=static function(int $id):string{$u=get_userdata($id);$m=[];foreach(['session_tokens','pk_email_verified_at','dsa_notification_preferences','_kiwe_account_suspended']as$key)$m[$key]=get_user_meta($id,$key,false);return hash('sha256',serialize([$u->user_login,$u->user_registered,$u->user_email,$u->user_pass,$u->roles,$m]));};
try{
	$manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'exact current package and loader match');
	$assert(!function_exists('WC'),'real non-Woo account profile path');$assert(!$service->inventory(),'proof starts with empty private settings inventory');
	// Delete seeded people only inside this runner's owned tables, then use real native creation.
	wp_set_current_user(0);$assert($wpdb->query("DELETE FROM {$wpdb->usermeta}")!==false&&$wpdb->query("DELETE FROM {$wpdb->users}")!==false,'all account work occurs in owned native replicas');wp_cache_flush();
	$off=static fn()=>[];$filter('pre_option_'.Owner::OPTION,$off);$people=[];
	foreach(['owner'=>'administrator','client'=>'administrator','writer'=>'author','reader'=>'subscriber']as$name=>$role){$id=wp_insert_user(['user_login'=>$prefix.'_'.$name,'user_pass'=>wp_generate_password(40,true,true),'user_email'=>$prefix.'_'.$name.'@example.invalid','display_name'=>'Synthetic '.ucfirst($name),'nickname'=>'Synthetic '.ucfirst($name),'description'=>"Writer's biography\nSecond line",'role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Native replica person creation failed');$people[$name]=(int)$id;}
	remove_filter('pre_option_'.Owner::OPTION,$off,PHP_INT_MAX);$filter('pre_option_'.Owner::OPTION,static fn()=>['enabled'=>true,'ownerId'=>$people['owner'],'rankMath'=>'inline']);
	wp_set_current_user($people['client']);$assert(Workspace::allowed()&&State::allowed()&&!current_user_can('manage_options'),'native client authority works without full technical-owner access');
	$assert($dispatch('GET','',null,false)->get_status()===403&&$dispatch('POST','',['groups'=>[State::GROUP],'passphrase'=>$phrase],true,false)->get_status()===403,'real routes require nonce and mutation proof');
	$_SERVER['HTTP_ORIGIN']='https://outside.invalid';$assert($dispatch('GET')->get_status()===403,'foreign-origin profile inventory denied');unset($_SERVER['HTTP_ORIGIN']);
	wp_set_current_user($people['writer']);$assert($dispatch('GET')->get_status()===403,'author cannot enter account recovery');$reject(static fn()=>State::read(),'direct profile read cannot bypass role denial');wp_set_current_user($people['client']);
	foreach($people as$name=>$id){update_user_meta($id,'dsa_notification_preferences',['channels'=>[],'topics'=>[],'updatedAt'=>123]);update_user_meta($id,'session_tokens',['synthetic'=>'untouched']);update_user_meta($id,'pk_email_verified_at','123');update_user_meta($id,'_kiwe_account_suspended',in_array($name,['writer','reader'],true)?'1':'0');}
	update_user_meta($people['writer'],Keys::USER_META_TEAM_MEMBER,'1');update_user_meta($people['writer'],Keys::USER_META_TEAM_TITLE,'Writer');update_user_meta($people['writer'],Keys::USER_META_TEAM_ORDER,'2');update_user_meta($people['writer'],Keys::USER_META_FOUNDER_TITLE,'Contributor');update_user_meta($people['writer'],Keys::USER_META_LINKEDIN,'https://example.invalid/writer');
	$value=State::read();$assert(array_column($value['records'],'id')===[$people['client'],$people['writer'],$people['reader']],'native directory excludes technical owner and includes subscriber');
	$reader=array_column($value['records'],null,'id')[$people['reader']];$assert($reader['public']===null&&$reader['team']===null,'subscriber cannot receive staff-only public content');
	$text=wp_json_encode($value);$assert(!str_contains($text,'@example.invalid')&&!str_contains($text,'user_pass')&&!str_contains($text,'session_tokens')&&!str_contains($text,'pk_email_verified')&&!str_contains($text,'notification_preferences'),'encrypted projection excludes account credentials and contact identity');
	$guard=[];foreach($people as$name=>$id)$guard[$name]=$guarded($id);$ownerProfile=(new Native())->account_section($people['owner'],'name');$assert(is_wp_error($ownerProfile),'actual Users workspace protects technical owner');
	$response=$dispatch('POST','',['groups'=>[State::GROUP],'passphrase'=>$phrase,'label'=>'Synthetic native profiles']);$body=$response->get_data();$assert($response->get_status()===200&&isset($body['created']['id']),'actual client REST creates encrypted profile copy');$copy=$body['created'];$copies[$copy['id']]=true;$bytes=file_get_contents($service->download_path($copy['id']));
	$units=array_column($body['coverage']['units'],null,'id');$assert(isset($body['groups'][State::GROUP])&&!empty($units['wordpress.profiles']['canCapture'])&&$units['wordpress.accounts']['status']==='pending','actual catalog distinguishes available profiles from unimplemented full account recovery');
	$assert(!str_contains($bytes,'Synthetic Writer')&&!str_contains($bytes,"Writer's biography"),'native saved file hides profile text');$r=$review($bytes);$assert($r['changedCount']===0,'native unchanged profiles review without loss');
	wp_update_user(wp_slash(['ID'=>$people['writer'],'display_name'=>'Changed Writer','description'=>'Changed biography']));update_user_meta($people['writer'],Keys::USER_META_FOUNDER_TITLE,'Changed public title');update_user_meta($people['writer'],Keys::USER_META_TEAM_MEMBER,'0');update_user_meta($people['writer'],Keys::USER_META_TEAM_TITLE,'Changed team title');wp_update_user(['ID'=>$people['reader'],'first_name'=>'Changed reader']);
	$new=wp_insert_user(['user_login'=>$prefix.'_newer','user_pass'=>wp_generate_password(40,true,true),'user_email'=>$prefix.'_newer@example.invalid','display_name'=>'Newer person','nickname'=>'Newer person','role'=>'subscriber']);if(is_wp_error($new))throw new RuntimeException('Newer native person unavailable');$new=(int)$new;$newBefore=(new Native())->account_section($new,'name')['values'];
	$consent=['channels'=>[],'topics'=>[],'updatedAt'=>456];update_user_meta($people['writer'],'dsa_notification_preferences',$consent);$guard['writer']=$guarded($people['writer']);
	$response=$dispatch('POST','/preview',['id'=>$copy['id'],'passphrase'=>$phrase]);$body=$response->get_data();$assert($response->get_status()===200&&($body['review']['changedCount']??0)===1,'actual native REST detects changed profile task');$r=$body['review'];$reviews[]=$r['token'];$assert(count($r['organization'])===1&&count($r['organization'][0]['changes'])===6,'review names all six changed native fields');
	$assert($dispatch('POST','/restore',['token'=>$r['token'],'passphrase'=>$phrase])->get_status()===400,'profile restore requires explicit confirmation');
	$response=$dispatch('POST','/restore',['token'=>$r['token'],'passphrase'=>$phrase,'confirm'=>true]);$body=$response->get_data();if($response->get_status()!==200)$proof['restoreFailure']=['status'=>$response->get_status(),'message'=>$body['message']??null];$assert($response->get_status()===200&&($body['restored']['restored']??0)===1,'actual client REST uses native profile writers');$collect();
	$writer=(new Native())->account_section($people['writer'],'name');$assert($writer['values']['display_name']==='Synthetic Writer'&&$writer['values']['description']==="Writer's biography\nSecond line",'native names and apostrophe biography read back exactly');
	$assert(get_user_meta($people['writer'],Keys::USER_META_FOUNDER_TITLE,true)==='Contributor'&&get_user_meta($people['writer'],Keys::USER_META_TEAM_MEMBER,true)==='1'&&get_user_meta($people['writer'],Keys::USER_META_TEAM_TITLE,true)==='Writer','native public links and team owner confirm restored details');
	$assert((new Native())->account_section($new,'name')['values']===$newBefore,'newer native account profile survives recovery');
	foreach($people as$name=>$id)$assert($guarded($id)===$guard[$name],'native login password roles verification opt-out and sessions unchanged '.$name);
	$assert(get_user_meta($people['writer'],'dsa_notification_preferences',true)===$consent,'newer explicit opt-out retained and never replayed');
	$r=$review($bytes);wp_update_user(['ID'=>$people['reader'],'first_name'=>'Newer editor']);$reject(static fn()=>$service->apply($r['token'],$phrase),'native edit after review prevents stale write');
	$r=$review($bytes);wp_set_current_user($people['owner']);$reject(static fn()=>$service->apply($r['token'],$phrase),'foreign native actor cannot use client review');wp_set_current_user($people['client']);
	$r=$review($bytes);(new WP_User($people['writer']))->set_role('subscriber');$reject(static fn()=>$service->apply($r['token'],$phrase),'native role change invalidates saved profile reference');(new WP_User($people['writer']))->set_role('author');
	$bad=$value;$bad['records'][0]['name']['user_email']='injected@example.invalid';$reject(static fn()=>State::validate($bad),'native projection rejects imported login-email field');
	$bad=$value;$bad['records'][2]['team']=['team_member'=>'1','team_title'=>'Injected','team_order'=>'0'];$reject(static fn()=>State::validate($bad),'native subscriber cannot become public staff through import');
	wp_update_user(['ID'=>$people['writer'],'display_name'=>'Before failed profile write']);update_user_meta($people['writer'],Keys::USER_META_FOUNDER_TITLE,'Before failed public write');$before=State::read();$r=$review($bytes);$failed=false;
	$deny=static function($check,$id,$key)use(&$failed,$people){if(!$failed&&(int)$id===$people['writer']&&$key===Keys::USER_META_FOUNDER_TITLE){$failed=true;return false;}return $check;};add_filter('update_user_metadata',$deny,PHP_INT_MAX,3);
	try{$reject(static fn()=>$service->apply($r['token'],$phrase),'actual failed public write aborts restore after profile write');}finally{remove_filter('update_user_metadata',$deny,PHP_INT_MAX);}$collect();
	$assert($failed&&State::read()===$before,'actual automatic rollback restores all earlier native profile fields');$assert(count($service->inventory())===3,'native success and failure retain encrypted before-copies');
	$assert([]===$GLOBALS['kiwe_media_mail']&&[]===$GLOBALS['kiwe_media_http'],'no reset verification mail provider send or registration replay attempted');$proof['passed']=true;
}catch(Throwable $error){$proof['passed']=false;$proof['error']=['message'=>$error->getMessage(),'line'=>$error->getLine()];}
finally{
	try{wp_set_current_user($people['client']??$actor);$collect();foreach($reviews as$token)$service->retire_review($token);foreach(array_keys($copies)as$id)$service->remove($id);$clean=[]===$service->inventory();}catch(Throwable $error){$clean=false;$proof['cleanupError']=$error->getMessage();}
	foreach($filters as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);wp_set_current_user($actor);$proof['cleanup']=$clean;
}
return $proof;
