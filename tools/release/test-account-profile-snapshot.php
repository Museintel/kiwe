<?php
declare(strict_types=1);
namespace {
	$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kiwe-profile-qa-'.bin2hex(random_bytes(6)); mkdir($root.'/public_html',0700,true);
	define('ABSPATH',$root.'/public_html/');define('DAY_IN_SECONDS',86400);
	$options=[];$transients=[];$users=[];$meta=[];$caps=[];$actor=2;$logged=true;$failMeta='';$site='https://profiles.example.test/';$checks=[];
	function current_user_can($cap,...$args){return $GLOBALS['caps'][$cap.($args?':'.$args[0]:'')]??$GLOBALS['caps'][$cap]??true;}
	function is_user_logged_in(){return $GLOBALS['logged'];}
	function is_multisite(){return false;}
	function get_current_user_id(){return $GLOBALS['actor'];}
	function get_current_blog_id(){return 1;}
	function get_users($args){$ids=array_keys($GLOBALS['users']);sort($ids,SORT_NUMERIC);return array_slice($ids,0,$args['number']??10000);}
	function get_userdata($id){return isset($GLOBALS['users'][$id])?new WP_User($id):false;}
	function user_can($id,$cap,...$args){$id=$id instanceof WP_User?$id->ID:$id;if($id===get_current_user_id()&&in_array($cap,['kiwe_manage_team','list_users','edit_users','edit_user'],true))return current_user_can($cap,...$args);$user=get_userdata($id);return $user&&in_array($user->roles[0],['author','administrator'],true);}
	class WP_User{public int $ID;public function __construct(int $id){$this->ID=$id;}public function __get(string $name){return $GLOBALS['users'][$this->ID][$name]??get_user_meta($this->ID,$name,true);}}
	class WP_Error{public function __construct(public string $code='',public string $message='',public array $data=[]){}public function get_error_message(){return $this->message;}}
	function is_wp_error($value){return $value instanceof WP_Error;}
	function get_user_meta($id,$key,$single=true){$v=$GLOBALS['meta'][$id][$key]??null;return $single?($v??''):($v===null?[]:[$v]);}
	function update_user_meta($id,$key,$value){if($GLOBALS['failMeta']===$key){$GLOBALS['failMeta']='';if(isset($GLOBALS['failed_profile_hook']))($GLOBALS['failed_profile_hook'])($id);return false;}$GLOBALS['meta'][$id][$key]=$value;return true;}
	function wp_update_user($input){$id=$input['ID'];foreach($input as$key=>$value){if($key==='ID')continue;if(in_array($key,['display_name','user_url'],true))$GLOBALS['users'][$id][$key]=$value;else update_user_meta($id,$key,$value);}return $id;}
	function wp_slash($input){return $input;}
	function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
	function sanitize_text_field($v){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags((string)$v)));}
	function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
	function esc_url_raw($v,$protocols=[]){return filter_var($v,FILTER_VALIDATE_URL)?$v:'';}
	function __($value,$domain=''){return $value;}
	function get_option($name,$default=false){return $GLOBALS['options'][$name]??$default;}
	function update_option($name,$value,$autoload=null){$GLOBALS['options'][$name]=$value;return true;}
	function add_option($name,$value,$unused='',$autoload=null){if(array_key_exists($name,$GLOBALS['options']))return false;$GLOBALS['options'][$name]=$value;return true;}
	function delete_option($name){unset($GLOBALS['options'][$name]);return true;}
	function get_transient($name){return $GLOBALS['transients'][$name]??false;}
	function set_transient($name,$value,$seconds){$GLOBALS['transients'][$name]=$value;return true;}
	function delete_transient($name){unset($GLOBALS['transients'][$name]);return true;}
	function home_url($path=''){return $GLOBALS['site'].ltrim($path,'/');}
	function get_stylesheet(){return 'synthetic-theme';}
	function get_theme_mod($key,$default=false){return $default;}
	function absint($value){return abs((int)$value);}
	function wp_normalize_path($p){return str_replace('\\','/',$p);}
	function trailingslashit($v){return rtrim($v,'/\\').'/';}
	function untrailingslashit($v){return rtrim($v,'/\\');}
	function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0700,true);}
	function cache_users($ids){}
	function update_meta_cache($type,$ids){}
}
namespace DSA\Access {
	class Site_Owner_Service{public static function is_owner($id=0){return ($id?:get_current_user_id())===1;}}
	class Backup_Workspace_Service{public static function allowed(){return is_user_logged_in()&&current_user_can('backup');}}
	class User_Group_Service{public static function allowed(){return current_user_can('kiwe_manage_team')&&current_user_can('list_users')&&current_user_can('edit_users');}}
}
namespace {
	$base=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';
	foreach(['Backup/Archive_Error.php','Backup/Archive_Cipher.php','Onboarding/Design_Context_Profile_Service.php','Onboarding/User_Profile_Service.php','Access/Team_Workspace_Service.php','Backup/Account_Profile_Snapshot_Service.php','Backup/Configuration_Snapshot_Service.php']as$file)require $base.$file;
	use DSA\Backup\Account_Profile_Snapshot_Service as State;
	use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
	use DSA\Access\Team_Workspace_Service as Native;
	use DSA\Onboarding\Design_Context_Profile_Service as Keys;
	$assert=static function(bool $ok,string $label)use(&$checks):void{$checks[$label]=$ok;if(!$ok)throw new RuntimeException($label);};
	$deny=static function(callable $fn,string $label)use($assert):void{$rejected=false;try{$fn();}catch(Throwable $error){$rejected=true;}$assert($rejected,$label);};
	$person=static function(int $id,string $role='subscriber'):void{
		$GLOBALS['users'][$id]=['user_login'=>'synthetic-'.$id,'user_registered'=>'2026-10-04 00:00:00','user_email'=>'synthetic-'.$id.'@example.test','user_pass'=>'synthetic-private-hash-'.$id,'roles'=>[$role],'display_name'=>'Synthetic '.$id,'user_url'=>'https://example.test/person/'.$id];
		$GLOBALS['meta'][$id]=['first_name'=>'Synthetic','last_name'=>(string)$id,'nickname'=>'Synthetic '.$id,'description'=>"Biography of person's work\nSecond line",'session_tokens'=>['private'=>'untouched'],'pk_email_verified_at'=>'123','dsa_notification_preferences'=>['enabled'=>false],'_kiwe_account_suspended'=>'1',Keys::USER_META_TEAM_MEMBER=>$role==='author'?'1':'0',Keys::USER_META_TEAM_TITLE=>'Writer',Keys::USER_META_TEAM_ORDER=>'2',Keys::USER_META_FOUNDER_TITLE=>'Contributor',Keys::USER_META_LINKEDIN=>'https://example.test/profile','facebook'=>''];
	};
	$service=new Snapshots();$phrase='synthetic-profile-recovery-passphrase';$reviews=[];$copies=[];$passed=false;$error=null;$cleanup=false;
	$review=static function(string $bytes)use($service,$phrase,&$reviews):array{$r=$service->preview_upload($bytes,$phrase);$reviews[]=$r['token'];return $r;};
	$collect=static function()use($service,&$copies):void{foreach($service->inventory()as$c)$copies[$c['id']]=true;};
	try{
		$person(1,'administrator');$person(2,'administrator');$person(3,'author');$person(4);
		$assert(isset(Snapshots::option_groups()[State::GROUP]),'profile group is native-permission derived');
		$value=State::read();$assert(array_column($value['records'],'id')===[2,3,4],'technical owner omitted and subscriber profile included');
		$subscriber=$value['records'][2];$assert($subscriber['public']===null&&$subscriber['team']===null,'subscriber cannot acquire public-team authority');
		$text=wp_json_encode($value);$assert(!str_contains($text,'example.test"')&&!str_contains($text,'synthetic-private-hash')&&!str_contains($text,'session_tokens')&&!str_contains($text,'pk_email_verified')&&!str_contains($text,'notification_preferences')&&!str_contains($text,'roles"'),'projection excludes login contacts credentials consent and security state');
		$security=[$users,$meta];$copy=$service->create($phrase,'Synthetic profiles',[State::GROUP]);$copies[$copy['id']]=true;$bytes=file_get_contents($service->download_path($copy['id']));
		$assert(!str_contains($bytes,'Synthetic 3')&&!str_contains($bytes,'Biography'),'profile file uses existing authenticated encryption');
		$r=$review($bytes);$assert($r['changedCount']===0&&$r['organization'][0]['label']===State::GROUP,'unchanged saved profiles review exactly');
		$users[3]['display_name']='Changed writer';$meta[3]['description']='Changed biography';$meta[3][Keys::USER_META_TEAM_MEMBER]='0';$meta[3][Keys::USER_META_TEAM_TITLE]='Changed title';$meta[4]['first_name']='Changed reader';$person(5);$newer=[$users[5],$meta[5]];
		$r=$review($bytes);$assert($r['changedCount']===1&&count($r['organization'][0]['changes'])===5,'review lists each changed profile and public-team field');
		$service->apply($r['token'],$phrase);$collect();
		$assert($users[3]['display_name']===$security[0][3]['display_name']&&$meta[3]['description']===$security[1][3]['description']&&$meta[4]['first_name']==='Synthetic','native name writer restores profile text');
		$assert($meta[3][Keys::USER_META_TEAM_MEMBER]==='1'&&$meta[3][Keys::USER_META_TEAM_TITLE]==='Writer','native team writer restores reviewed public appearance');
		$assert([$users[5],$meta[5]]===$newer,'people added after snapshot stay intact');
		$assert($users[1]===$security[0][1]&&$meta[1]===$security[1][1],'hidden technical-owner fields stay intact');
		foreach([2,3,4]as$id)$assert($users[$id]['user_pass']===$security[0][$id]['user_pass']&&$users[$id]['user_email']===$security[0][$id]['user_email']&&$users[$id]['roles']===$security[0][$id]['roles']&&$meta[$id]['session_tokens']===$security[1][$id]['session_tokens']&&$meta[$id]['dsa_notification_preferences']===$security[1][$id]['dsa_notification_preferences']&&$meta[$id]['pk_email_verified_at']===$security[1][$id]['pk_email_verified_at']&&$meta[$id]['_kiwe_account_suspended']===$security[1][$id]['_kiwe_account_suspended'],'identity roles opt-out verification sessions and ban unchanged '.$id);
		$r=$review($bytes);$meta[4]['description']='Newer editing';$deny(static fn()=>$service->apply($r['token'],$phrase),'profile edit after review refuses stale restore');$meta[4]['description']=$security[1][4]['description'];
		$r=$review($bytes);$actor=3;$deny(static fn()=>$service->apply($r['token'],$phrase),'foreign actor cannot use profile review');$actor=2;
		$r=$review($bytes);$users[3]['roles']=['subscriber'];$deny(static fn()=>$service->apply($r['token'],$phrase),'changed role invalidates saved profile identity');$users[3]['roles']=['author'];
		$r=$review($bytes);$users[3]['user_email']='changed@example.test';$deny(static fn()=>$service->apply($r['token'],$phrase),'changed login contact blocks recovery without rewriting contact');$users[3]['user_email']=$security[0][3]['user_email'];
		$r=$review($bytes);$caps['edit_user:3']=false;$deny(static fn()=>$service->apply($r['token'],$phrase),'lost per-person edit permission blocks restore');unset($caps['edit_user:3']);
		$caps['edit_user_meta:3']=false;$deny(static fn()=>State::validate($value),'public metadata permission is checked before any profile write');unset($caps['edit_user_meta:3']);
		$caps['edit_users']=false;$assert(!isset(Snapshots::option_groups()[State::GROUP]),'profile choice disappears without account management authority');$deny(static fn()=>State::read(),'profile capture denied without native authority');unset($caps['edit_users']);
		$bad=$value;$bad['records'][0]['name']['user_email']='injected@example.test';$deny(static fn()=>State::validate($bad),'archive cannot introduce login-email field');
		$bad=$value;$bad['records'][0]['roles']=['administrator'];$deny(static fn()=>State::validate($bad),'archive cannot introduce permission fields');
		$bad=$value;$bad['records'][2]['team']=['team_member'=>'1','team_title'=>'Injected','team_order'=>'0'];$deny(static fn()=>State::validate($bad),'archive cannot publish subscriber on public team');
		$bad=$value;$bad['records'][1]['name']['user_url']='javascript:alert(1)';$deny(static fn()=>State::validate($bad),'invalid public link rejected before writes');
		$bad=$value;$bad['records'][1]['team']['team_order']='10000';$deny(static fn()=>State::validate($bad),'team order native bound enforced');
		$bad=$value;$bad['records'][]=$bad['records'][0];$deny(static fn()=>State::validate($bad),'duplicate unordered account references rejected');
		$bad=$value;$bad['records'][1]['name']['description']='<strong>legacy</strong>';$deny(static fn()=>State::validate($bad),'lossy legacy formatting fails explicitly before backup restore');
		$users[3]['display_name']='Before failed restore';$meta[3][Keys::USER_META_FOUNDER_TITLE]='Before public title';$before=State::read();$r=$review($bytes);$failMeta=Keys::USER_META_FOUNDER_TITLE;$deny(static fn()=>$service->apply($r['token'],$phrase),'partial native public-field failure aborts restore');$collect();
		$assert(State::read()===$before,'automatic reverse rollback restores earlier successful native name writes');
		$assert(count($service->inventory())===3,'before-copy kept after successful and failed profile restore');
		$users[3]['display_name']='Before newer profile race';$meta[3][Keys::USER_META_FOUNDER_TITLE]='Before newer public race';$priorProfile=State::read();$r=$review($bytes);$failMeta=Keys::USER_META_FOUNDER_TITLE;
		$failed_profile_hook=static function($id){$GLOBALS['users'][$id]['display_name']='Newer profile edit';};$deny(static fn()=>$service->apply($r['token'],$phrase),'newer profile edit during partial failure refuses complete rollback confirmation');unset($failed_profile_hook);$collect();
		$assert($users[3]['display_name']==='Newer profile edit'&&$meta[3][Keys::USER_META_FOUNDER_TITLE]==='Before newer public race','partial profile rollback preserves newer fields and restores its own earlier changes');
		$oldSite=$site;$site='https://other.example.test/';$deny(static fn()=>$review($bytes),'cross-site profile file rejected');$site=$oldSite;
		$deny(static fn()=>$service->preview_upload($bytes,'wrong-recovery-passphrase'),'wrong passphrase refuses profile file');
		$oldUsers=$users;for($id=10;$id<1011;$id++)$users[$id]=$users[4];$deny(static fn()=>State::read(),'oversized directory refuses rather than truncating');$users=$oldUsers;
		$passed=true;
	}catch(Throwable $e){$error=['message'=>$e->getMessage(),'line'=>$e->getLine()];}
	finally{
		try{$actor=2;$caps=[];$collect();foreach($reviews as$token)$service->retire_review($token);foreach(array_keys($copies)as$id)$service->remove($id);$cleanup=$service->inventory()===[]&&$transients===[];}catch(Throwable $e){$cleanup=false;}
		if(is_dir($root.'/kiwe-private-backups'))rmdir($root.'/kiwe-private-backups');rmdir($root.'/public_html');rmdir($root);$cleanup=$cleanup&&!is_dir($root);
	}
	echo json_encode(['schema'=>'kiwe-account-profile-local.v1','passed'=>$passed&&$cleanup,'checks'=>$checks,'cleanup'=>$cleanup,'nativeOwner'=>'actual Team_Workspace_Service with deterministic WordPress stubs','error'=>$error],JSON_UNESCAPED_SLASHES)."\n";exit($passed&&$cleanup?0:1);
}
