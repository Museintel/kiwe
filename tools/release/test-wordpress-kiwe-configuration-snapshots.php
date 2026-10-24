<?php
/** The existing isolated native CLI runner must own every table and block delivery. */
if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || true !== KIWE_WORKSPACE_TEST_SITE || empty( $GLOBALS['kiwe_media_tables_isolated'] ) || ! preg_match( '/^[a-zA-Z0-9_]*kiwechoices266_[a-f0-9]{12}_options$/D', $GLOBALS['wpdb']->options ?? '' ) ) exit(2);

use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
use DSA\Backup\Kiwe_Configuration_Snapshot_Service as State;
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\Backup_Workspace_Service as Workspace;
use DSA\Settings;

$proof=['schema'=>'kiwe-native-configuration-snapshots.v1','version'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'checks'=>[],'cleanup'=>false];
$GLOBALS['kiwe_media_proof'] =& $proof;
$assert=static function(bool $ok,string $label)use(&$proof):void{$proof['checks'][]=['label'=>$label,'passed'=>$ok];if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable $fn,string $label)use($assert):void{$blocked=false;try{$fn();}catch(Throwable $e){$blocked=true;}$assert($blocked,$label);};
$actor=get_current_user_id();$filters=[];$reviews=[];$copies=[];$service=new Snapshots();$phrase='synthetic-native-choice-recovery-phrase';$clean=true;
$filter=static function(string $hook,callable $fn,int $args=1)use(&$filters):void{add_filter($hook,$fn,PHP_INT_MAX,$args);$filters[]=[$hook,$fn];};
$preview=static function(string $bytes)use($service,$phrase,&$reviews):array{$r=$service->preview_upload($bytes,$phrase);$reviews[]=$r['token'];return $r;};
$dispatch=static function(string $method,string $path='',?array $body=null,bool $nonce=true,bool $mutation=true){$r=new WP_REST_Request($method,'/dsa/v1/workspace/backups'.$path);if($nonce)$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($mutation)$r->set_header('X-Kiwe-Mutation','1');if($body!==null){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}return rest_do_request($r);};
try {
	$m=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($m['valid'])&&!empty($m['complete']),'exact current native package and loader match');
	$assert(!function_exists('WC'),'native non-Woo configuration path');$assert(!$service->inventory(),'native proof starts with an empty private inventory');
	$prefix='kiwechoicesqa_'.bin2hex(random_bytes(5));$people=[];$off=static fn()=>[];$filter('pre_option_'.Owner::OPTION,$off);
	foreach(['owner'=>'administrator','client'=>'administrator','reader'=>'subscriber']as$name=>$role){$id=wp_insert_user(['user_login'=>$prefix.'_'.$name,'user_pass'=>wp_generate_password(40,true,true),'user_email'=>$prefix.'_'.$name.'@example.invalid','display_name'=>'Synthetic '.$name,'role'=>$role]);$assert(!is_wp_error($id),'owned native '.$name.' account created');$people[$name]=(int)$id;}
	remove_filter('pre_option_'.Owner::OPTION,$off,PHP_INT_MAX);$filter('pre_option_'.Owner::OPTION,static fn()=>['enabled'=>true,'ownerId'=>$people['owner'],'rankMath'=>'inline']);wp_set_current_user($people['owner']);
	$assert(Workspace::allowed()&&State::allowed(),'native backup and technical Settings owner allowed');
	$raw=(new Settings())->defaults();$raw['app']['welcome_message']="Synthetic author's \\ app";$raw['app']['future_label']='Keep newer field';$raw['app']['button_labels']['future']='Keep newer button';$raw['ai']['native_api_key']='synthetic-provider-marker';$raw['secure']['auto_logout_roles']=['subscriber'];$raw['permissions']['camera_enabled']=true;$raw['permissions']['location_enabled']=true;$raw['permissions']['offline_editorial_enabled']=true;$raw['fragment_navigation']=true;$raw['enhancements']['legacy_only']='Keep exact legacy data';update_option(DSA_OPTION_SETTINGS,$raw,false);
	update_user_meta($people['reader'],'dsa_notification_preferences',['topics'=>[],'channels'=>[],'updatedAt'=>time()]);update_user_meta($people['reader'],'pk_email_verified_at',time());update_user_meta($people['reader'],'session_tokens',['synthetic'=>'current']);
	// The owned posts table is empty; seeded site options must not refer to original pages/images.
	update_option('show_on_front','posts',false);update_option('page_on_front',0,false);update_option('page_for_posts',0,false);update_option('site_icon',0,false);
	update_option('dsa_notification_campaign_active',['id'=>'synthetic-running','status'=>'paused'],false);update_option('dsa_notification_campaign_history',[['id'=>'synthetic-receipt']],false);update_option('stp_settings',['native_marker'=>'synthetic-security'],false);update_option('blogdescription','Synthetic before',false);
	$protected=static fn()=>hash('sha256',serialize([array_diff_key(get_option(DSA_OPTION_SETTINGS),array_flip(['app','permissions','metrics','schema_geo'])),get_user_meta($people['reader']),get_option('dsa_notification_campaign_active'),get_option('dsa_notification_campaign_history'),get_option('stp_settings')]));$beforeProtected=$protected();$value=State::read();
	$assert(count($value['choices'])===4&&!str_contains(wp_json_encode($value),'synthetic-provider-marker'),'native capture is bounded and excludes credentials');
	$assert(!in_array(DSA_OPTION_SETTINGS,array_merge(...array_values(Snapshots::option_groups())),true),'native capture never exposes the whole Settings row');
	$copy=$service->create($phrase,'Synthetic native choices',['WordPress',State::GROUP]);$bytes=file_get_contents($service->download_path($copy['id']));$copies[$copy['id']]=true;
	$assert(!str_contains($bytes,'Synthetic author'),'actual native download is encrypted');$reject(static fn()=>$service->preview_upload($bytes,'incorrect-native-recovery-phrase'),'native wrong-passphrase denial');
	$raw=get_option(DSA_OPTION_SETTINGS);$raw['app']['welcome_message']='Changed native welcome';$raw['metrics']['retention_days']=7;update_option(DSA_OPTION_SETTINGS,$raw,false);update_option('blogdescription','Changed native WordPress',false);
	$r=$preview($bytes);$assert($r['changedCount']===2&&count($r['organization'][0]['changes'])===2,'native field review includes both changed app choices');
	$result=$service->apply($r['token'],$phrase);$copies[$result['rollbackId']]=true;$assert($result['restored']===2&&State::read()===$value&&get_option('blogdescription')==='Synthetic before','actual changed-data restore confirms native readback');
	// Exercise an ordinary core option whose native autoload policy is enabled.
	$autoloadBefore=$wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s",'blogdescription'));
	wp_set_option_autoload_values(['blogdescription'=>true]);update_option('blogdescription','Changed autoloaded tagline');
	$r=$preview($bytes);$result=$service->apply($r['token'],$phrase);$copies[$result['rollbackId']]=true;
	$assert(get_option('blogdescription')==='Synthetic before'&&in_array($wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s",'blogdescription')),['yes','on','auto-on'],true),'native autoloaded core option retains loading policy after recovery');
	wp_set_option_autoload_values(['blogdescription'=>in_array($autoloadBefore,['yes','on','auto-on'],true)]);
	$assert($protected()===$beforeProtected,'native credentials roles consent verification sessions jobs and history retained');$raw=get_option(DSA_OPTION_SETTINGS);$assert($raw['app']['future_label']==='Keep newer field'&&$raw['app']['button_labels']['future']==='Keep newer button'&&$raw['permissions']['camera_enabled']&&$raw['permissions']['location_enabled']&&$raw['permissions']['offline_editorial_enabled'],'native excluded and newer nested fields retained');
	$assert(is_file($service->download_path($result['rollbackId'])),'native encrypted before-copy retained');
	$raw['app']['welcome_message']='Before stale review';update_option(DSA_OPTION_SETTINGS,$raw,false);$r=$preview($bytes);$raw['app']['welcome_message']='Concurrent native editor';update_option(DSA_OPTION_SETTINGS,$raw,false);$reject(static fn()=>$service->apply($r['token'],$phrase),'native stale-state review refused');$assert(get_option(DSA_OPTION_SETTINGS)['app']['welcome_message']==='Concurrent native editor','native stale refusal preserves newer editor');
	$bad=$value;$bad['choices']['permissions']['visitorConsent']='granted';$reject(static fn()=>State::validate($bad),'native consent field injection refused');$bad=$value;$bad['choices']['app']['pc_url']='https://user:password@example.invalid/app';$reject(static fn()=>State::validate($bad),'native login-bearing app URL refused');
	$wpBefore='Before partial native failure';update_option('blogdescription',$wpBefore,false);$nativeBefore=get_option(DSA_OPTION_SETTINGS);$r=$preview($bytes);$failed=false;
	$deny=static function($next,$old)use(&$failed){if(!$failed){$failed=true;return $old;}return $next;};add_filter('pre_update_option_'.DSA_OPTION_SETTINGS,$deny,PHP_INT_MAX,2);
	try{$reject(static fn()=>$service->apply($r['token'],$phrase),'native option-write denial triggers common rollback');}finally{remove_filter('pre_update_option_'.DSA_OPTION_SETTINGS,$deny,PHP_INT_MAX);}
	$assert($failed&&get_option(DSA_OPTION_SETTINGS)===$nativeBefore&&get_option('blogdescription')===$wpBefore,'actual native partial rollback restores exact before-values');
	$r=$preview($bytes);$result=$service->apply($r['token'],$phrase,static function($phase){if($phase==='prepared'){$raw=get_option(DSA_OPTION_SETTINGS);$raw['ai']['native_api_key']='synthetic-newer-provider-marker';update_option(DSA_OPTION_SETTINGS,$raw,false);}});$copies[$result['rollbackId']]=true;$assert(get_option(DSA_OPTION_SETTINGS)['ai']['native_api_key']==='synthetic-newer-provider-marker','actual current protected native setting survives restore preparation');
	$r=$preview($bytes);wp_set_current_user($people['client']);$assert(!State::allowed()&&!isset(Snapshots::option_groups()[State::GROUP]),'native workspace client does not inherit technical Settings access');$reject(static fn()=>$service->apply($r['token'],$phrase),'changed native actor and authority refuse owner review');
	wp_set_current_user($people['reader']);$assert(!Workspace::allowed()&&!State::allowed(),'native subscriber cannot open configuration backups');wp_set_current_user($people['owner']);
	$listing=$dispatch('GET');$assert($listing->get_status()===200&&isset($listing->get_data()['groups'][State::GROUP]),'native signed-in owner route projects configuration group');
	$assert($dispatch('GET','',null,false)->get_status()>=400,'native missing REST nonce refused');$assert($dispatch('POST','',['passphrase'=>$phrase,'groups'=>[State::GROUP]],true,false)->get_status()>=400,'native missing mutation proof refused');
	$assert(!get_option('kiwe_backup_restore_lock_v1'),'native common restore lock retired');$proof['passed']=true;
} catch(Throwable $e){$proof['passed']=false;$proof['error']='Native Kiwe configuration proof failed: '.$e->getMessage();}
finally {
	try{wp_set_current_user($people['owner']??$actor);foreach($reviews as$token)$service->retire_review($token);foreach($service->inventory()as$copy){$copies[$copy['id']]=true;$service->remove($copy['id']);}$clean=$clean&&!$service->inventory()&&!get_option('kiwe_backup_restore_lock_v1');}catch(Throwable $e){$clean=false;}
	foreach(array_reverse($filters)as[$hook,$fn])remove_filter($hook,$fn,PHP_INT_MAX);wp_set_current_user($actor);$proof['cleanup']=$clean;$proof['ownedCopies']=count($copies);$proof['passed']=!empty($proof['passed'])&&$clean;
}

return $proof;
