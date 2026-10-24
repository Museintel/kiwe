<?php
declare(strict_types=1);
namespace {
	$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'kiwe-choices-qa-'.bin2hex(random_bytes(6));mkdir($root.'/public_html',0700,true);
	define('ABSPATH',$root.'/public_html/');define('DAY_IN_SECONDS',86400);define('DSA_OPTION_SETTINGS','dsa_settings');
	$options=[];$transients=[];$userMeta=[2=>['dsa_notification_preferences'=>['topics'=>[],'updatedAt'=>1728000000],'pk_email_verified_at'=>1728000000,'session_tokens'=>['synthetic-session'=>'current']]];$caps=['backup'=>true,'manage_options'=>true];$actor=2;$logged=true;$site='https://choices.example.test/';$checks=[];$failChoice=false;
	function current_user_can($cap,...$args){return $GLOBALS['caps'][$cap]??false;}
	function is_user_logged_in(){return $GLOBALS['logged'];}
	function get_current_user_id(){return $GLOBALS['actor'];}
	function get_current_blog_id(){return 1;}
	function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
	function update_option($key,$value,$autoload=null){if($key===DSA_OPTION_SETTINGS&&$GLOBALS['failChoice']){$GLOBALS['failChoice']=false;return false;}$GLOBALS['options'][$key]=$value;return true;}
	function add_option($key,$value,$unused='',$autoload=null){if(array_key_exists($key,$GLOBALS['options']))return false;$GLOBALS['options'][$key]=$value;return true;}
	function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
	function get_transient($key){return $GLOBALS['transients'][$key]??false;}
	function set_transient($key,$value,$expiry){$GLOBALS['transients'][$key]=$value;return true;}
	function delete_transient($key){unset($GLOBALS['transients'][$key]);return true;}
	function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
	function wp_slash($v){if(is_array($v))return array_map('wp_slash',$v);return is_string($v)?addslashes($v):$v;}
	function wp_unslash($v){if(is_array($v))return array_map('wp_unslash',$v);return is_string($v)?stripslashes($v):$v;}
	function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
	function absint($v){return abs((int)$v);}
	function sanitize_text_field($v){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags((string)$v)));}
	function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
	function esc_url_raw($v){return $v===''?'':(filter_var($v,FILTER_VALIDATE_URL)&&preg_match('~^https?://~i',$v)?$v:'');}
	function home_url($path=''){return $GLOBALS['site'].ltrim($path,'/');}
	function get_stylesheet(){return 'synthetic-theme';}
	function get_theme_mod($key,$default=false){return $default;}
	function set_theme_mod($key,$value){}
	function remove_theme_mod($key){}
	function get_post($id){return null;}
	function wp_normalize_path($v){return str_replace('\\','/',$v);}
	function trailingslashit($v){return rtrim($v,'/\\').'/';}
	function untrailingslashit($v){return rtrim($v,'/\\');}
	function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
	function update_user_meta(...$args){throw new RuntimeException('Recovery attempted to change account or visitor state.');}
	function wp_mail(...$args){throw new RuntimeException('Recovery attempted a real send.');}
	function wp_remote_post(...$args){throw new RuntimeException('Recovery attempted provider delivery.');}
	function wp_schedule_single_event(...$args){throw new RuntimeException('Recovery attempted to schedule a campaign.');}
}
namespace DSA\Diagnostics { final class Runtime_Profiler { public static function start(){return 0;}public static function finish(...$args){} } }
namespace DSA\Access { final class Backup_Workspace_Service { public static function allowed(){return is_user_logged_in()&&current_user_can('backup');} } }
namespace {
	$base=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';
	foreach(['Settings.php','Settings_Choice_Schema.php','Backup/Archive_Error.php','Backup/Archive_Cipher.php','Backup/Kiwe_Configuration_Snapshot_Service.php','Backup/Configuration_Snapshot_Service.php']as$file)require $base.$file;
	use DSA\Settings;use DSA\Settings_Choice_Schema as Schema;use DSA\Backup\Kiwe_Configuration_Snapshot_Service as State;use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
	$assert=static function(bool $ok,string $label)use(&$checks):void{$checks[$label]=$ok;if(!$ok)throw new RuntimeException($label);};
	$deny=static function(callable $fn,string $label)use($assert):void{$rejected=false;try{$fn();}catch(Throwable $e){$rejected=true;}$assert($rejected,$label);};
	$reviews=[];$service=new Snapshots();$phrase='synthetic-choice-recovery-passphrase';$passed=false;$cleanup=false;$error=null;
	$preview=static function(string $bytes)use($service,$phrase,&$reviews):array{$r=$service->preview_upload($bytes,$phrase);$reviews[]=$r['token'];return $r;};
	try {
		$assert(!isset(Snapshots::option_groups()[State::GROUP]),'unconfigured native store has no actionable group');$deny(static fn()=>State::read(),'missing native store does not invent a settings backup');
		$options[DSA_OPTION_SETTINGS]=(new Settings())->defaults();$raw=&$options[DSA_OPTION_SETTINGS];
		$raw['app']['welcome_message']="Writer's path \\ articles";$raw['app']['future_label']='Current newer field';
		$raw['app']['button_labels']['future_button']='Current newer label';$raw['permissions']['offline_editorial_enabled']=true;$raw['permissions']['camera_enabled']=true;$raw['permissions']['location_enabled']=true;
		$raw['ai']['native_api_key']='synthetic-provider-marker';$raw['secure']['auto_logout_roles']=['subscriber'];$raw['phonekey']['future_secret']='synthetic-phonekey-marker';
		$options['stp_settings']=['enabled'=>true,'guard'=>'synthetic-current'];$raw['fragment_navigation']=true;$raw['enhancements']['legacy_only']='Keep exact native data';
		$options['dsa_notification_campaign_active']=['id'=>'synthetic-running-job'];$options['dsa_notification_campaign_history']=[['id'=>'synthetic-receipt']];$options['blogdescription']='Before';
		$state=State::read();$assert($state['choices']['app']['welcome_message']==="Writer's path \\ articles",'native text and literal slash semantics retained');
		$assert(count($state['choices'])===4&&count($state['choices']['permissions'])===18&&!isset($state['choices']['permissions']['pwa_enabled'])&&$state['choices']['app']['show_install_actions']===true,'bounded four-section native projection');
		$assert(!str_contains(wp_json_encode($state),'synthetic-')&&!isset($state['choices']['app']['future_label']),'provider security and newer unknown fields excluded from file');
		$legacy=$state;unset($legacy['choices']['app']['home_enabled']);State::validate($legacy);$assert(true,'previous v1 app choices without the new Home control remain recoverable');
		$nativeBefore=get_option(DSA_OPTION_SETTINGS);(new Settings())->update_selected(['app'=>['home_enabled'=>true]]);$current=State::read();$legacySetting=['exists'=>true,'value'=>$legacy];$review=State::review([State::NAME=>['exists'=>true,'value'=>$current]],[State::NAME=>$legacySetting]);$assert(!str_contains(wp_json_encode($review),'Show Home DSA'),'legacy review does not invent a missing Home change');State::write($legacySetting);$assert((new Settings())->get('app')['home_enabled']===true,'legacy app recovery preserves newer Home visibility');$options[DSA_OPTION_SETTINGS]=$nativeBefore;
		$assert(isset(Snapshots::option_groups()[State::GROUP])&&!in_array(DSA_OPTION_SETTINGS,array_merge(...array_values(Snapshots::option_groups())),true),'typed group never exposes the whole settings option');
		$caps['manage_options']=false;$assert(!isset(Snapshots::option_groups()[State::GROUP]),'client without native Settings authority has no app-choice group');$deny(static fn()=>State::read(),'native Settings denial is enforced in getter');$caps['manage_options']=true;
		$caps['backup']=false;$deny(static fn()=>State::read(),'Settings authority alone cannot grant backup access');$caps['backup']=true;
		$logged=false;$deny(static fn()=>State::read(),'signed-out capture denied');$logged=true;
		foreach([
			'wrong_schema'=>static function(&$v){$v['schema']='kiwe.configuration-choices.v2';},
			'unknown_section'=>static function(&$v){$v['choices']['secure']=[];},
			'unknown_field'=>static function(&$v){$v['choices']['app']['native_api_key']='synthetic-injection';},
			'missing_field'=>static function(&$v){unset($v['choices']['app']['pwa_pitch']);},
			'coerced_boolean'=>static function(&$v){$v['choices']['metrics']['enabled']='1';},
			'out_of_range'=>static function(&$v){$v['choices']['metrics']['retention_days']=91;},
			'non_native_units'=>static function(&$v){$v['choices']['app']['idle_delay_ms']=10001;},
			'oversized_text'=>static function(&$v){$v['choices']['permissions']['pwa_title']=str_repeat('a',1201);},
			'executable_url'=>static function(&$v){$v['choices']['app']['pc_url']='javascript:alert(1)';},
			'credential_url'=>static function(&$v){$v['choices']['app']['pc_url']='https://user:password@example.test/app';},
			'unknown_label'=>static function(&$v){$v['choices']['app']['button_labels']['future']='Unknown';},
			'consent_injection'=>static function(&$v){$v['choices']['permissions']['consent']='granted';},
		]as$label=>$change){$bad=$state;$change($bad);$deny(static fn()=>State::validate($bad),'reject '.$label);}
		$deny(static fn()=>State::write(['exists'=>false]),'typed native choices cannot be removed by restore');
		$copy=$service->create($phrase,'Synthetic app choices',['WordPress',State::GROUP]);$bytes=file_get_contents($service->download_path($copy['id']));
		$assert(!str_contains($bytes,'Writer')&&!str_contains($bytes,'synthetic-provider-marker'),'download is encrypted and excludes credentials');
		$deny(static fn()=>$service->preview_upload($bytes,'wrong-recovery-passphrase'),'wrong passphrase denied before settings review');
		$protected=static fn()=>[array_diff_key($GLOBALS['options'][DSA_OPTION_SETTINGS],array_flip(['app','permissions','metrics','schema_geo'])),$GLOBALS['userMeta'],$GLOBALS['options']['stp_settings'],$GLOBALS['options']['dsa_notification_campaign_active'],$GLOBALS['options']['dsa_notification_campaign_history']];
		$originalProtected=$protected();$raw['app']['welcome_message']='Changed welcome';$raw['metrics']['retention_days']=7;$options['blogdescription']='Changed';
		$review=$preview($bytes);$assert($review['changedCount']===2&&count($review['organization'][0]['changes'])===2,'review shows each changed native choice alongside WordPress changes');
		$assert($review['organization'][0]['changes'][0]['before']==='Changed welcome'&&$review['organization'][0]['changes'][0]['after']==="Writer's path \\ articles",'review shows human-readable current and saved values');
		$result=$service->apply($review['token'],$phrase);$assert($result['restored']===2&&State::read()===$state&&$options['blogdescription']==='Before','encrypted file restores changed data through native Settings writer');
		$assert($protected()===$originalProtected&&$raw['app']['future_label']==='Current newer field','security consent jobs history and newer fields preserved');
		$assert($raw['app']['button_labels']['future_button']==='Current newer label'&&$raw['permissions']['offline_editorial_enabled']&&$raw['permissions']['camera_enabled']&&$raw['permissions']['location_enabled'],'excluded current permission and nested future choices preserved');
		$assert(is_file($service->download_path($result['rollbackId'])),'encrypted before-copy retained');
		$raw['app']['pwa_pitch']='Changed again';$r=$preview($bytes);$raw['app']['pwa_pitch']='Concurrent change';$deny(static fn()=>$service->apply($r['token'],$phrase),'changed native choices invalidate review');
		$assert($raw['app']['pwa_pitch']==='Concurrent change','stale review does not overwrite concurrent editor');
		$r=$preview($bytes);$actor=3;$deny(static fn()=>$service->apply($r['token'],$phrase),'restore review belongs to original actor');$actor=2;
		$r=$preview($bytes);$caps['manage_options']=false;$deny(static fn()=>$service->apply($r['token'],$phrase),'native capability loss blocks reviewed restore');$caps['manage_options']=true;
		$options['blogdescription']='Partial write rollback';$r=$preview($bytes);$beforeFailure=$options;$failChoice=true;$deny(static fn()=>$service->apply($r['token'],$phrase),'unconfirmed native write triggers common reverse rollback');
		$assert($options[DSA_OPTION_SETTINGS]===$beforeFailure[DSA_OPTION_SETTINGS]&&$options['blogdescription']===$beforeFailure['blogdescription'],'native and WordPress before-values restored after partial failure');
		$r=$preview($bytes);$service->apply($r['token'],$phrase,static function($phase){if($phase==='prepared')$GLOBALS['options'][DSA_OPTION_SETTINGS]['ai']['native_api_key']='synthetic-newer-provider-marker';});
		$assert($options[DSA_OPTION_SETTINGS]['ai']['native_api_key']==='synthetic-newer-provider-marker','newer protected native setting survives restore preparation');
		$assert(!isset($options['kiwe_backup_restore_lock_v1']),'shared restore lock retired after success and failures');
		$header=json_decode($bytes,true);$header['groups']=array_fill(0,9,'Kiwe app choices');
		$bound=new ReflectionMethod(Snapshots::class,'read_envelope');$assert($bound->invoke(null,wp_json_encode($header))['groups']===$header['groups'],'header can represent nine native groups');
		$header['groups']=array_fill(0,13,'Kiwe app choices');$deny(static fn()=>$bound->invoke(null,wp_json_encode($header)),'header group count remains bounded');
		$assert(Schema::metrics(['enabled'=>false,'retention_days'=>999],[])===['enabled'=>false,'retention_days'=>90],'shared native form clamps analytics retention');
		$assert(Schema::app(wp_slash(['idle_delay_seconds'=>10,'welcome_message'=>"Writer's \\ path"]),[])['welcome_message']==="Writer's \\ path",'shared native form retains its original unslash behavior');
		$assert(Schema::permissions(['ai_popup_duration_seconds'=>1],[])['ai_popup_duration_ms']===2000,'shared native form retains prompt-duration minimum');
		$passed=true;
	} catch(Throwable $e){$error=$e->getMessage();} finally {
		try{foreach($reviews as$token)$service->retire_review($token);foreach($service->inventory()as$c)$service->remove($c['id']);$cleanup=!$service->inventory()&&!$transients&&!isset($options['kiwe_backup_restore_lock_v1']);}catch(Throwable $e){$error=$error?:'Fixture cleanup failed';}
		$verifiedRoot=realpath($root);$verifiedTemp=realpath(sys_get_temp_dir());
		if(!$verifiedRoot||!$verifiedTemp||dirname($verifiedRoot)!==$verifiedTemp||is_link($root)||!preg_match('/^kiwe-choices-qa-[a-f0-9]{12}$/D',basename($verifiedRoot))){$cleanup=false;$error=$error?:'Fixture cleanup refused an unexpected target.';}
		else{
			$remove=static function(string $path)use(&$remove,$verifiedRoot):void{
				$resolved=realpath($path);if(!$resolved||($resolved!==$verifiedRoot&&!str_starts_with($resolved,$verifiedRoot.DIRECTORY_SEPARATOR)))throw new RuntimeException('Fixture cleanup left its owned root.');
				foreach(scandir($resolved)?:[]as$name){if($name==='.'||$name==='..')continue;$p=$resolved.DIRECTORY_SEPARATOR.$name;if(is_dir($p)&&!is_link($p))$remove($p);else unlink($p);}rmdir($resolved);
			};$remove($verifiedRoot);$cleanup=$cleanup&&!is_dir($root);
		}
	}
	echo json_encode(['passed'=>$passed,'cleanup'=>$cleanup,'checks'=>$checks,'error'=>$error],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($passed&&$cleanup?0:1);
}
