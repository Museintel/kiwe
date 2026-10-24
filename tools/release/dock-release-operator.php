<?php
/** Rendered only by build-dock-release-operator.cjs. CLI-only signed native update. */
if ( PHP_SAPI !== 'cli' ) { http_response_code(404); exit; }
/* __KIWE_BYTE_SPLICES__ */
$configBody = base64_decode('__KIWE_DOCK_CONFIG__');
if (str_starts_with($configBody,"\x1f\x8b")) $configBody = gzdecode($configBody);
$c = json_decode($configBody,true,512,JSON_THROW_ON_ERROR);
$target=$argv[2]??'lab';
if(isset($c['liveTarget'])){
 if(!in_array($target,['lab','live'],true))exit(2);
 if($target==='live'){
  $labReceipt=json_decode((string)@file_get_contents($c['private'].'/receipt.json'),true);
  if(empty($labReceipt['passed'])||empty($labReceipt['cleanup'])||empty($labReceipt['native']['passed'])||($labReceipt['version']??'')!==$c['release']['version'])exit(3);
  $c=array_replace($c,$c['liveTarget']);
 }
}
$c['_target']=$target;
if ( realpath(__DIR__) !== $c['executionRoot'] || basename(__FILE__) !== $c['helper'] ) {echo json_encode(['passed'=>false,'cleanup'=>false,'error'=>'Helper path binding mismatch']);exit(2);}
ini_set('display_errors','0');ini_set('log_errors','0');set_time_limit(180);
$mu=$c['root'].'/wp-content/mu-plugins';$private=$c['private'];$phase=$argv[1]??'--apply';
function kd_pair($root){$out=[];foreach(['dsa','dsa.php','kiwe-incident-guard.php']as$entry){$p=$root.'/'.$entry;if(!file_exists($p))continue;$files=is_dir($p)?new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,FilesystemIterator::SKIP_DOTS)):[$p];foreach($files as$f){$p=is_string($f)?$f:$f->getPathname();if(is_link($p)||!is_file($p))throw new RuntimeException('Ordinary package file required');$out[substr($p,strlen($root)+1)]=hash_file('sha256',$p);}}ksort($out);return$out;}
function kd_write($path,$value){if(is_link($path))throw new RuntimeException('Ordinary private receipt required');$tmp=$path.'.new';if(file_exists($tmp))throw new RuntimeException('Receipt already being written');file_put_contents($tmp,json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);chmod($tmp,0600);if(!rename($tmp,$path))throw new RuntimeException('Receipt write failed');}
function kd_core(){global$c;define('WP_USE_THEMES',false);$_SERVER['HTTP_HOST']=$c['domain'];$_SERVER['SERVER_NAME']=$c['domain'];$_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REMOTE_ADDR']='127.0.0.1';ob_start();require $c['root'].'/wp-load.php';ob_end_clean();wp_set_current_user(1);}
function kd_copy($from,$to,$map){if(file_exists($to)||!mkdir($to,0700))throw new RuntimeException('Fresh previous pair destination required');foreach($map as$name=>$hash){$dest=$to.'/'.$name;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);if(!copy($from.'/'.$name,$dest)||hash_file('sha256',$dest)!==$hash)throw new RuntimeException('Previous pair verification failed');}if(kd_pair($to)!==$map)throw new RuntimeException('Previous pair incomplete');}
function kd_normalize_controls(array $values){
 global $c;
 unset($values['woocommerce_admin_scheduler_last_processed_order_modified_date']);
 // Bricks 2.4 advances this derived index stamp when the owned Daily form/product fixtures are created/deleted.
 if(($c['nativeProofFile']??'')==='test-wordpress-daily-arrivals.php')unset($values['bricks_media_usage_version']);
 // This native import fixture changes classes through Bricks' own writer. Its
 // last-editor/time signals must advance; they are not authored style choices.
 if(($c['nativeProofFile']??'')==='test-wordpress-compiler-import.php'){
  foreach(['BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP','BRICKS_DB_GLOBAL_CLASSES_USER']as$constant)if(defined($constant))unset($values[constant($constant)]);
  unset($values['bricks_global_classes_changes'],$values['bricks_media_usage_version']);
 }
 return $values;
}
function kd_controls(){global$wpdb;$out=[];$rows=$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN ('dsa_settings','active_plugins','template','stylesheet','kiwe_access_policy_v1','home','siteurl') OR option_name LIKE 'woocommerce\\_%' OR option_name LIKE 'bricks\\_%' ORDER BY option_name",ARRAY_A);if(!is_array($rows)||$wpdb->last_error!=='')throw new RuntimeException('Native settings read failed');foreach($rows as$row)$out[$row['option_name']]=hash('sha256',$row['option_value']);return kd_normalize_controls($out);}
function kd_remove($path,$parent){if(is_link($path)||realpath(dirname($path))!==$parent||realpath($path)!==$path)throw new RuntimeException('Owned stage required');$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f){if($f->isLink())throw new RuntimeException('Linked stage refused');$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}if(!rmdir($path))throw new RuntimeException('Stage retirement failed');}
if('--inspect-controls'===$phase){
 if(empty($c['test'])||$c['domain']!=='plum-giraffe-539524.hostingersite.com')exit(2);
 kd_core();$before=kd_normalize_controls(json_decode(file_get_contents($private.'/controls-before.json'),true));$now=kd_controls();$changed=[];
 foreach(array_unique(array_merge(array_keys($before),array_keys($now)))as$name)if(($before[$name]??null)!==($now[$name]??null))$changed[]=['name'=>$name,'before'=>$before[$name]??null,'after'=>$now[$name]??null];
 $result=['schema'=>'kiwe.native-controls-read.v1','passed'=>true,'cleanup'=>true,'runtime'=>DSA_VERSION,'changes'=>$changed,'helperRetired'=>unlink(__FILE__)];kd_write($private.'/controls-difference.json',$result);echo json_encode($result,JSON_UNESCAPED_SLASHES);exit;
}
if('--inspect-style-import'===$phase){
 if(empty($c['test'])||$c['domain']!=='plum-giraffe-539524.hostingersite.com'||($c['nativeProofFile']??'')!=='test-wordpress-compiler-import.php')exit(2);
 kd_core();if(DSA_VERSION!==$c['release']['version']||kd_pair($mu)!==json_decode(file_get_contents($private.'/candidate-manifest.json'),true))exit(3);
 $settings=new DSA\Settings();$tokens=$settings->get('tokens',[]);$tokens['bricks_theme_style']=['enabled'=>true,'id'=>'seam-native-import-proof','label'=>'Import proof'];
 $universal=DSA\Design\Seam_Token_Service::export_for_bricks(DSA\Design\Seam_Token_Service::tokens_with_overrides($tokens['overrides']??[]),$tokens['bricks_theme_style']);
 $snapshot=DSA\Bricks\Framework_Style_Service::snapshot();$collisions=[];
 foreach(['variables','categories','colorPalette','classes','classCategories']as$kind)foreach($universal[$kind]??[]as$item){$matches=[];$names=[];foreach($snapshot[$kind]['value']as$old){if(($old['id']??'')===$item['id'])$matches[]=$old;if(in_array($kind,['variables','classes'],true)&&($old['name']??'')===$item['name'])$names[]=$old;}
  if(count($matches)>1||count($names)>1)$collisions[]=['kind'=>$kind,'incoming'=>['id'=>$item['id'],'name'=>$item['name']],'idMatches'=>count($matches),'nameMatches'=>count($names),'existing'=>array_map(static function($old)use($item,$kind){return['id'=>$old['id']??null,'name'=>$old['name']??null,'source'=>$old['source']??null,'project'=>$old['project']??null,'equivalent'=>$kind==='variables'?($old['value']??null)===($item['value']??null):($old['settings']??[])===($item['settings']??[])];},$matches+$names)];
 }
 $before=kd_normalize_controls(json_decode(file_get_contents($private.'/controls-before.json'),true));$result=['schema'=>'kiwe.native-style-import-read.v1','passed'=>true,'cleanup'=>true,'runtime'=>DSA_VERSION,'collisions'=>$collisions,'types'=>array_keys(DSA\Access\Template_Workspace_Service::types()),'controlsUnchanged'=>kd_controls()===$before,'helperRetired'=>unlink(__FILE__)];kd_write($private.'/style-difference.json',$result);echo json_encode($result,JSON_UNESCAPED_SLASHES);exit;
}
if('--inspect-compiler-jobs'===$phase){
 if(empty($c['test'])||$c['domain']!=='plum-giraffe-539524.hostingersite.com'||($c['nativeProofFile']??'')!=='test-wordpress-compiler-import.php')exit(2);
 $timer=microtime(true);$result=['schema'=>'kiwe.native-compiler-job-read.v1','passed'=>false,'cleanup'=>false,'phase'=>'boot'];
 try{
  kd_core();$result['runtime']=DSA_VERSION;$result['pairMatches']=kd_pair($mu)===json_decode(file_get_contents($private.'/candidate-manifest.json'),true);$result['phase']='jobs';
  wp_set_current_user(DSA\Access\Site_Owner_Service::owner_id());$result['allowed']=DSA\Bricks\Compiler_Import_Plan::allowed();$service=new DSA\Bricks\Compiler_Import_Service();$jobs=[];foreach($service->listing()as$item){$status=$service->status($item['id']);$jobs[]=array_intersect_key($status,array_flip(['id','status','revision','mediaDone','templatesDone','error','messages','drafts','sourceHash']));}
  $before=kd_normalize_controls(json_decode(file_get_contents($private.'/controls-before.json'),true));$now=kd_controls();$changed=[];foreach(array_unique(array_merge(array_keys($before),array_keys($now)))as$name)if(($before[$name]??null)!==($now[$name]??null))$changed[]=$name;
  $sizes=[];$vault=DSA\Backup\Configuration_Snapshot_Service::private_directory(false);foreach($jobs as$item){$dir=$vault.'/compiler-job-'.$item['id'];foreach(['review-source.kiwe','state-'.$item['id'].'.kiwe','recovery.kiwe']as$name)if(is_file($dir.'/'.$name))$sizes[$item['id']][$name]=filesize($dir.'/'.$name);}
  $result+=['jobs'=>$jobs,'changedControls'=>$changed,'checkpointBytes'=>$sizes,'elapsedSeconds'=>microtime(true)-$timer,'peakMemoryBytes'=>memory_get_peak_usage(true)];$result['passed']=true;$result['cleanup']=true;
 }catch(Throwable$e){$result['error']=$e->getMessage();}
 $result['helperRetired']=unlink(__FILE__);kd_write($private.'/compiler-jobs-read.json',$result);echo json_encode($result,JSON_UNESCAPED_SLASHES);exit;
}
function kd_lab_label_order($before){
 // A UI round trip can reorder PHP array keys without changing any choices.
 // Recover only the exact pre-test serialized value, identified by its hash.
 global$c;$now=kd_controls();$changed=array_keys(array_filter($now,static fn($v,$k)=>($before[$k]??null)!==$v,ARRAY_FILTER_USE_BOTH));
 if($changed!==['dsa_settings']||array_keys($now)!==array_keys($before))return false;
 $raw=get_option(DSA_OPTION_SETTINGS);if(!is_array($raw)||!is_array($raw['dock']??null)||($raw['dock']['menu_label']??null)!=='Menu'||count($raw)>100||count($raw['dock'])>100)return false;
 $top=$raw;unset($top['dock']);$inner=$raw['dock'];unset($inner['menu_label']);$insert=static function($a,$k,$v,$n){return array_slice($a,0,$n,true)+[$k=>$v]+array_slice($a,$n,null,true);};
 for($i=-1;$i<=count($inner);$i++){$dock=$i<0?$inner:$insert($inner,'menu_label','Menu',$i);for($j=0;$j<=count($top);$j++){$candidate=$insert($top,'dock',$dock,$j);if(hash('sha256',maybe_serialize($candidate))===$before['dsa_settings']){update_option(DSA_OPTION_SETTINGS,$candidate,false);return kd_controls()===$before;}}}return false;
}
if('--recover-compiler-proof'===$phase){
 $recoveryFile=$private.'/compiler-proof-recovery'.(empty($c['acceptanceAttempt'])?'':'-'.$c['acceptanceAttempt']).'.json';
 if(is_file($recoveryFile)){echo file_get_contents($recoveryFile);exit;}
 $result=['schema'=>'kiwe.native-compiler-proof-recovery.v1','passed'=>false,'cleanup'=>false];
 kd_write($private.'/compiler-proof-recovery-progress.json',['phase'=>'entered']);
 register_shutdown_function(static function()use($private){$error=error_get_last();if($error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))echo json_encode(['passed'=>false,'fatal'=>true,'message'=>substr($error['message'],0,300)]);});
 try{
  if(empty($c['test'])||$c['domain']!=='plum-giraffe-539524.hostingersite.com'||empty($c['recoverCompilerJobs'])||($c['nativeProofFile']??'')!=='test-wordpress-compiler-import.php')throw new RuntimeException('Exact synthetic lab review scope required');
  kd_core();kd_write($private.'/compiler-proof-recovery-progress.json',['phase'=>'booted']);if(DSA_VERSION!==$c['release']['version']||kd_pair($mu)!==json_decode(file_get_contents($private.'/candidate-manifest.json'),true))throw new RuntimeException('Installed candidate changed');
  wp_set_current_user(DSA\Access\Site_Owner_Service::owner_id());$service=new DSA\Bricks\Compiler_Import_Service();$retired=[];
  if(!empty($c['recoverReviewOnlyFast'])){
   if(!DSA\Bricks\Compiler_Import_Plan::allowed())throw new RuntimeException('Native authoring recovery authority required');
   foreach($c['recoverCompilerJobs']as$job){$dir=DSA\Backup\Archive_Job_Files::directory('compiler',$job['id']);$jobLock=fopen(DSA\Backup\Archive_Job_Files::path($dir,'lock'),'c+b');if(!$jobLock||!flock($jobLock,LOCK_EX|LOCK_NB))throw new RuntimeException('Synthetic review is in use');
    try{$sealed=DSA\Security\Secret_Store::decrypt_with_status(DSA\Backup\Archive_Job_Files::read($dir,'state-'.$job['id'].'.kiwe',65536));$state=($sealed['status']??'')==='ok'?json_decode($sealed['value'],true):null;
     if(!is_array($state)||($state['schema']??0)!==2||($state['id']??'')!==$job['id']||($state['actor']??0)!==get_current_user_id()||($state['site']??'')!==hash('sha256',home_url('/'))||($state['status']??'')!=='review'||($state['revision']??0)!==1||!empty($state['media'])||!empty($state['mediaIntents'])||!empty($state['drafts'])||!empty($state['draftCursor'])||!empty($state['journal'])||isset($state['applyPlan'])||isset($state['tokenJournal']))throw new RuntimeException('Only an untouched encrypted review can be retired without reading its large plan');
     $zip=DSA\Backup\Archive_Job_Files::path($dir,'source.seam.zip');if(!hash_equals($state['zipHash'],hash_file('sha256',$zip)))throw new RuntimeException('Synthetic review package changed');$reader=new DSA\Bricks\Compiler_Package_Reader($zip);$inspection=$reader->inspect();unset($reader);if($inspection['sourceHash']!==$job['sourceHash'])throw new RuntimeException('Synthetic source identity changed');DSA\Backup\Archive_Job_Files::retire($dir);$retired[]=$job['id'];
    }finally{flock($jobLock,LOCK_UN);fclose($jobLock);}
   }
  }else{
   $present=array_column($service->listing(),'id');foreach($c['recoverCompilerJobs']as$job){if(!in_array($job['id'],$present,true)){ $retired[]=$job['id'];continue; }$s=$service->status($job['id']);if($s['status']!=='review'||$s['sourceHash']!==$job['sourceHash']||$s['mediaDone']||$s['templatesDone']||$s['drafts'])throw new RuntimeException('Synthetic review advanced or changed; preserve it');$service->advance($s['id'],$s['revision'],'remove');$retired[]=$s['id'];}
  }
  $before=kd_normalize_controls(json_decode(file_get_contents($private.'/controls-before.json'),true));$styles=DSA\Bricks\Framework_Style_Service::snapshot();$classes=$styles['classes']['value'];$count=0;
  $clean=array_values(array_filter($classes,static function($row)use(&$count){if(($row['id']??'')==='seamfloating1'&&($row['name']??'')==='sf-native-typed-proof'&&($row['source']??'')==='kiwe-project'&&($row['project']??'')==='sf-native-typed-proof'){$count++;return false;}return true;}));
  if($count>1||hash('sha256',maybe_serialize($clean))!==($before[BRICKS_DB_GLOBAL_CLASSES]??null))throw new RuntimeException('Only the exact original native class inventory can be restored');
  if($count&& !DSA\Bricks\Framework_Style_Service::restore('classes',['exists'=>true,'value'=>$clean],$styles['classes']))throw new RuntimeException('Native class recovery conflicted');
  if($count)DSA\Bricks\Framework_Style_Service::refresh();if(kd_controls()!==$before)throw new RuntimeException('Native choices still differ after selective fixture recovery');
  $source=$private.'/compiler-proof.zip';if(is_file($source)){ $reader=new DSA\Bricks\Compiler_Package_Reader($source);$inspection=$reader->inspect();unset($reader);if(!in_array($inspection['sourceHash'],array_column($c['recoverCompilerJobs'],'sourceHash'),true)||!unlink($source))throw new RuntimeException('Preserve unmatched synthetic source'); }
  $result+=['retiredReviews'=>$retired,'controlsUnchanged'=>true];$result['passed']=true;$result['cleanup']=true;$result['helperRetired']=unlink(__FILE__);
 }catch(Throwable$e){$result['error']=$e->getMessage();}
 kd_write($recoveryFile,$result);echo json_encode($result,JSON_UNESCAPED_SLASHES);exit;
}
if('--retire-compiler-transport'===$phase){
 $result=['schema'=>'kiwe.compiler-lab-transport-retirement.v1','passed'=>false,'cleanup'=>false,'retired'=>[]];
 try{
  if(empty($c['test'])||$c['domain']!=='plum-giraffe-539524.hostingersite.com'||($c['nativeProofFile']??'')!=='test-wordpress-compiler-import.php'||empty($c['acceptanceAttempt']))throw new RuntimeException('Exact lab retirement scope required');
  $accepted=json_decode(file_get_contents($private.'/receipt.json'),true);if(empty($accepted['passed'])||empty($accepted['cleanup'])||empty($accepted['native']['passed'])||empty($accepted['native']['cleanup'])||($accepted['version']??'')!==$c['release']['version'])throw new RuntimeException('Native import and recovery must pass before transport retirement');
  if(kd_pair($mu)!==json_decode(file_get_contents($private.'/candidate-manifest.json'),true))throw new RuntimeException('Installed accepted pair changed');
  foreach($c['retireLabFiles']??[]as$file){$p=$c['executionRoot'].'/'.$file['name'];if(!is_file($p)||is_link($p)||hash_file('sha256',$p)!==$file['sha256'])throw new RuntimeException('A named transport member changed; preserve it');}
  foreach($c['retireCandidateRoots']??[]as$name){$older=dirname($c['root']).'/'.$name;$stage=$older.'/candidate';if(is_dir($stage)){if(is_link($older)||realpath($older)!==$older||kd_pair($stage)!==json_decode(file_get_contents($older.'/candidate-manifest.json'),true))throw new RuntimeException('Superseded stage changed; preserve it');kd_remove($stage,$older);$result['retired'][]=$name.'/candidate';}}
  foreach($c['retireLabFiles']??[]as$file){$p=$c['executionRoot'].'/'.$file['name'];if(!unlink($p))throw new RuntimeException('Transport retirement failed');$result['retired'][]=$file['name'];}
  $result['passed']=true;$result['cleanup']=true;$result['helperRetired']=unlink(__FILE__);
 }catch(Throwable$e){$result['error']=$e->getMessage();}
 kd_write($private.'/transport-'.$c['acceptanceAttempt'].'.json',$result);echo json_encode($result,JSON_UNESCAPED_SLASHES);exit;
}
function kd_child($phase,$file){global$c,$private;$descriptor=[0=>['pipe','r'],1=>['file',$private.'/'.$file,'w'],2=>['file',$private.'/'.$file.'.err','w']];$process=proc_open([PHP_BINARY,__FILE__,$phase,$c['_target']??'lab'],$descriptor,$pipes,$c['root']);if(!is_resource($process))throw new RuntimeException('Native child unavailable');fclose($pipes[0]);$start=microtime(true);$budget=($phase==='--test'&&($c['nativeProofFile']??'')==='test-wordpress-compiler-import.php')?150:90;do{$status=proc_get_status($process);if(!$status['running'])break;if(microtime(true)-$start>$budget){proc_terminate($process);throw new RuntimeException('Native child exceeded budget');}usleep(100000);}while(true);proc_close($process);chmod($private.'/'.$file,0600);chmod($private.'/'.$file.'.err',0600);$text=file_get_contents($private.'/'.$file);$value=json_decode(trim($text),true);if(!is_array($value)||empty($value['passed'])||empty($value['cleanup']))throw new RuntimeException('Native child did not pass '.$phase);return$value;}
if ( '--boot' === $phase ) {
 kd_core();$checks=[];$assert=static function($ok,$label)use(&$checks){$checks[]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
 $assert(DSA_VERSION===$c['release']['version'],'fresh matching native boot');$manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(!empty($manifest['valid'])&&!empty($manifest['complete']),'complete native manifest');
 if(!empty($c['prepareCoreCartCapture'])){
  $assert(DSA_VERSION==='8.0.0-rc.392'&&in_array($c['domain'],['plum-giraffe-539524.hostingersite.com','dharwadpedha.com','nationalchikki.com'],true)&&current_user_can('kiwe_manage_context')&&current_user_can('edit_others_shop_orders'),'named authorized core cart preparation');
  $owners=[];foreach(($GLOBALS['wp_filter']['admin_init']->callbacks??[])as$entries)foreach($entries as$entry){$callback=$entry['function'];if(is_array($callback)&&$callback[0] instanceof DSA\Commerce\Abandoned_Cart_Service&&$callback[1]==='prepare_core_capture')$owners[spl_object_id($callback[0])]=$callback[0];}
  $assert(count($owners)===1,'single registered native cart owner');$owner=reset($owners);$owner->prepare_core_capture();
  $assert($owner->ready()&&get_option('dsa_abandoned_cart_db_version')==='4'&&(bool)wp_next_scheduled('dsa_abandoned_cart_maintenance'),'core cart schema and maintenance ready');
  $assert((new DSA\Settings())->get('abandoned_cart',[])['enabled']===true,'legacy capture choice is inert');
 }
 if(!empty($c['prepareCoreAppTracking'])){
  $assert(in_array(DSA_VERSION,['8.0.0-rc.404','8.0.0-rc.405','8.0.0-rc.406','8.0.0-rc.407','8.0.0-rc.408','8.0.0-rc.409','8.0.0-rc.410','8.0.0-rc.411'],true)&&in_array($c['domain'],['plum-giraffe-539524.hostingersite.com','dharwadpedha.com','nationalchikki.com'],true)&&current_user_can('kiwe_manage_notification_policy'),'named authorized App schema preparation');
  $owners=[];foreach(($GLOBALS['wp_filter']['admin_init']->callbacks??[])as$entries)foreach($entries as$entry){$cb=$entry['function'];if(is_array($cb)&&$cb[0] instanceof DSA\Notifications\Notification_Preference_Service&&$cb[1]==='maybe_install')$owners[spl_object_id($cb[0])]=$cb[0];}
  $assert(count($owners)===1,'single registered native notification audience owner');$owner=reset($owners);$table=DSA\Notifications\Notification_Preference_Service::audience_table();$before=$wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id",ARRAY_A);$keys=$before?array_keys($before[0]):[];$owner->maybe_install();
  $after=$wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id",ARRAY_A);$assert(get_option('dsa_notification_preferences_db_version')==='2'&&is_array($after),'App observation schema ready');
  $assert(!$before||array_map(static fn($r)=>array_intersect_key($r,array_flip($keys)),$after)===$before,'every existing notification audience field preserved');
  $assert((new DSA\Settings())->get('abandoned_cart',[])['enabled']===true&&get_option('dsa_abandoned_cart_db_version')==='4'&&(bool)wp_next_scheduled('dsa_abandoned_cart_maintenance'),'existing core saved-cart capture and native maintenance preserved');
 }
 $assert(!file_exists($mu.'/kiwe-update-transaction.json'),'native boot retired update transaction');
 $assert(kiwe_mu_refresh_php_runtime($mu),'all package PHP runtime refreshed');
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mu.'/dsa/assets',FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile()&&in_array($f->getExtension(),['js','css'],true))touch($f->getPathname());
 do_action('litespeed_purge_all');
 $assert(class_exists('DSA\Access\Dock_Workspace_Service'),'Dock workspace available');
 $assert(kd_controls()===kd_normalize_controls(json_decode(file_get_contents($private.'/controls-before.json'),true)),'native website and business choices preserved');
 $assert(hash_file('sha256',$c['root'].'/wp-config.php')===json_decode(file_get_contents($private.'/configuration-before.json'),true)['sha256'],'literal configuration preserved');
 $bricks=wp_get_theme(get_template());
 echo json_encode(['passed'=>true,'cleanup'=>true,'checks'=>$checks,'bricks'=>['version'=>$bricks->get('Version'),'ajaxAddToCart'=>class_exists('Bricks\\Woocommerce')?(bool)Bricks\Woocommerce::enabled_ajax_add_to_cart():null],'woocommerce'=>['version'=>defined('WC_VERSION')?WC_VERSION:null,'databaseVersion'=>get_option('woocommerce_db_version',null)]]);exit;
}
if ( '--test' === $phase ) {
 kd_core();define('KIWE_WORKSPACE_TEST_SITE',true);define('KIWE_WORKSPACE_EXPECTED_VERSION',$c['release']['version']);try{eval('?>'.base64_decode($c['nativeProof']));}catch(Throwable$e){$GLOBALS['kiwe_test_receipt_emitted']=true;echo json_encode(['passed'=>false,'cleanup'=>false,'error'=>$e->getMessage(),'unexpectedThrowable'=>true]);}exit;
}
if ( '--accept-test' === $phase ) {
 $acceptanceFile=$private.'/acceptance'.(empty($c['acceptanceAttempt'])?'':'-'.$c['acceptanceAttempt']).'.json';
 // Resume only a lab fixture failure after a fully verified native boot. Keep
 // the original failed receipt intact; never replay the package installation.
 // A scheduled invocation can repeat; report its completed attempt instead of
 // replacing the first acceptance result with a freshness error.
 if(is_file($acceptanceFile)&&((empty($c['restoreLabLabelOrder'])&&empty($c['resumeCompilerBookkeepingReview']))||is_file($private.'/acceptance-first.json'))){echo file_get_contents($acceptanceFile);exit;}
 $proof=['schema'=>'kiwe.dock-lab-acceptance.v1','version'=>$c['release']['version'],'passed'=>false,'cleanup'=>false,'checks'=>[]];
 $assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};$lock=null;
 try{
  $assert(!empty($c['test'])&&$c['domain']==='plum-giraffe-539524.hostingersite.com','named disposable lab only');
  $lock=fopen($private.'/owner.lock','c+b');$assert($lock&&flock($lock,LOCK_EX|LOCK_NB),'exclusive native acceptance owner');
  if((!empty($c['restoreLabLabelOrder'])||!empty($c['resumeCompilerBookkeepingReview']))&&file_exists($private.'/acceptance.json')){$prior=json_decode(file_get_contents($private.'/acceptance.json'),true);$assert(empty($prior['passed'])&&($prior['error']??'')==='original settings unchanged before acceptance'&&!file_exists($private.'/acceptance-first.json')&&rename($private.'/acceptance.json',$private.'/acceptance-first.json'),'prior freshness failure retained');}
  $assert(!file_exists($acceptanceFile),'fresh acceptance receipt');
  $failed=json_decode(file_get_contents($private.'/receipt.json'),true);
  $boot=$failed['boot']??null;$lateBoot=false;
  if(!is_array($boot)&&($failed['error']??'')==='Native child exceeded budget'&&is_file($private.'/boot.json')){
   $boot=json_decode(file_get_contents($private.'/boot.json'),true);
   $lateBoot=is_array($boot)&&!empty($boot['passed'])&&!empty($boot['cleanup'])&&count($boot['checks']??[])===7&&count(array_filter($boot['checks'],static fn($row)=>($row['passed']??false)!==true))===0;
   if($lateBoot)$proof['verifiedLateBoot']=$boot;
  }
  $assert(empty($failed['passed'])&&((($failed['error']??'')==='Native child did not pass --test'&&!empty($boot['passed']))||$lateBoot)&&!empty($failed['swapped'])&&(empty($failed['nativeRollbackPending'])||$lateBoot),'only native fixture acceptance was incomplete');
  kd_core();$assert(DSA_VERSION===$c['release']['version'],'expected installed candidate');$candidate=json_decode(file_get_contents($private.'/candidate-manifest.json'),true);
  $assert(kd_pair($mu)===$candidate,'installed signed pair unchanged');$assert(kd_pair($private.'/previous')===json_decode(file_get_contents($private.'/previous-manifest.json'),true),'exact previous pair retained');
  $before=kd_normalize_controls(json_decode(file_get_contents($private.'/controls-before.json'),true));if(kd_controls()!==$before&&!empty($c['restoreLabLabelOrder']))$assert(kd_lab_label_order($before),'exact original serialization restored after Menu label round trip');
  $assert(kd_controls()===$before,'original settings unchanged before acceptance');
  $assert(hash_file('sha256',$c['root'].'/wp-config.php')===json_decode(file_get_contents($private.'/configuration-before.json'),true)['sha256'],'literal native configuration unchanged');
  $assert(!file_exists($mu.'/kiwe-update-transaction.json')&&!get_option('kiwe_update_lock',false),'no unfinished native update');
  $proof['native']=kd_child('--test','native-recheck'.(empty($c['acceptanceAttempt'])?'':'-'.$c['acceptanceAttempt']).'.json');
  $assert(kd_pair($mu)===$candidate&&kd_controls()===kd_normalize_controls(json_decode(file_get_contents($private.'/controls-before.json'),true)),'candidate and settings unchanged after acceptance');
  $zip=$c['executionRoot'].'/'.$c['archive'];$transportHash=in_array($c['transport']??'',['deduplicated','spliced'],true)?$c['transportSha256']:$c['release']['sha256'];$assert(is_file($zip)&&!is_link($zip)&&hash_file('sha256',$zip)===$transportHash,'original upload still exact');if(empty($c['retainArchive']))$assert(unlink($zip),'original upload retired');else $proof['archiveRetainedForLive']=true;kd_remove($private.'/candidate',$private);
  foreach($c['retireLabFiles']??[]as$file){$old=$c['executionRoot'].'/'.$file['name'];$assert(is_file($old)&&!is_link($old)&&hash_file('sha256',$old)===$file['sha256']&&unlink($old),'exact superseded lab file retired '.$file['name']);}
  $old=$c['executionRoot'].'/'.($c['supersededHelper']??'');$assert(preg_match('/^kiwe-dock-[a-z0-9-]+\\.php$/D',$c['supersededHelper']??'')&&$old!==__FILE__&&is_file($old)&&!is_link($old)&&unlink($old),'original lab helper retired');
  $proof['passed']=true;$proof['cleanup']=true;kd_write($private.'/journal.json',['status'=>'accepted-after-fixture-correction']);
 }catch(Throwable$e){$proof['error']=$e->getMessage();}finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}if($proof['passed']){if(empty($c['retainHelper']))$proof['helperRetired']=unlink(__FILE__);else{$proof['helperRetainedForLive']=true;if(file_exists($private.'/receipt-first.json')||!rename($private.'/receipt.json',$private.'/receipt-first.json'))throw new RuntimeException('Original failed receipt must be retained');$accepted=$failed;unset($accepted['error']);$accepted['native']=$proof['native'];$accepted['passed']=true;$accepted['cleanup']=true;$accepted['acceptedAfterFixtureCorrection']=true;$accepted['archiveRetainedForLive']=true;$accepted['previousVersion']=$c['previousVersion'];kd_write($private.'/receipt.json',$accepted);}}kd_write($acceptanceFile,$proof);}
 echo json_encode($proof,JSON_UNESCAPED_SLASHES);exit($proof['passed']?0:1);
}
$proof=['schema'=>'kiwe.dock-signed-rollout.v1','domain'=>$c['domain'],'version'=>$c['release']['version'],'checks'=>[],'cleanup'=>false,'passed'=>false];$lock=null;$locked=false;$swapped=false;
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
try {
 if(file_exists($private.'/receipt.json')){echo file_get_contents($private.'/receipt.json');exit;}
 if(!is_dir($private)&&!mkdir($private,0700))throw new RuntimeException('Private owner root unavailable');if(is_link($private)||realpath($private)!==$private)throw new RuntimeException('Ordinary private owner required');
 $lock=fopen($private.'/owner.lock','c+b');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another owner is active');
 $assert(!file_exists($private.'/journal.json'),'fresh operation journal');
 $spliced=($c['transport']??'')==='spliced';$deduplicated=$spliced||($c['transport']??'')==='deduplicated';
 $zip=$c['executionRoot'].'/'.$c['archive'];$assert(is_file($zip)&&!is_link($zip)&&hash_file('sha256',$zip)===($deduplicated?$c['transportSha256']:$c['release']['sha256'])&&filesize($zip)===($deduplicated?$c['transportBytes']:$c['release']['bytes']),'exact pinned transport upload');
 $configHash=hash_file('sha256',$c['root'].'/wp-config.php');$assert($c['configHash']==='capture'||$configHash===$c['configHash'],'expected native configuration');kd_write($private.'/configuration-before.json',['sha256'=>$configHash]);kd_core();
 $assert(DSA_VERSION===$c['previousVersion'],'expected previous installed version');$assert(!file_exists($mu.'/kiwe-update-transaction.json')&&!get_option('kiwe_update_lock',false),'no previous update in progress');
 $previous=kd_pair($mu);kd_copy($mu,$private.'/previous',$previous);kd_write($private.'/previous-manifest.json',$previous);$assert(kd_pair($private.'/previous')===$previous,'exact previous pair retained');kd_write($private.'/controls-before.json',kd_controls());
 $stage=$private.'/candidate';
 if($deduplicated){$updater=new DSA\Runtime\Update_Service();$baseSignature=new ReflectionMethod($updater,'verify_release');$assert($baseSignature->invoke($updater,$c['baseRelease'],'candidate')===true,'existing pinned base signature');$baseRoot=new ReflectionMethod($updater,'verify_package_root');$baseRoot->invoke($updater,$mu,$c['baseRelease']);$assert(true,'complete native base verified');kd_copy($mu,$stage,$previous);}
 elseif(!mkdir($stage,0700))throw new RuntimeException('Fresh candidate stage unavailable');
 $z=new ZipArchive();$assert($z->open($zip)===true,'archive opens');$members=[];
 for($i=0;$i<$z->numFiles;$i++){$n=$z->getNameIndex($i);$assert(!str_starts_with($n,'/')&&!str_contains($n,'\\')&&!in_array('..',explode('/',$n),true),'bounded archive member '.$i);if($deduplicated){$assert(!isset($members[$n])&&isset($c['targetFiles'][$n])&&in_array($n,$c['transportFiles'],true),'pinned changed member '.$i);$members[$n]=true;}}
 if($deduplicated){$expected=$c['transportFiles'];sort($expected);$actual=array_keys($members);sort($actual);$assert($actual===$expected,'exact changed-file transport inventory');}
 if($spliced){foreach($c['transportFiles']as$name){$path=$stage.'/'.$name;$base=is_file($path)?file_get_contents($path):'';$patch=json_decode($z->getFromName($name),true,512,JSON_THROW_ON_ERROR);$bytes=kiwe_release_splice_bytes($base,$patch,$c['targetFiles'][$name]);if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);$assert(file_put_contents($path,$bytes)===strlen($bytes),'exact staged member reconstructed '.$name);}}
 else $assert($z->extractTo($stage),'candidate extracted');$z->close();
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST)as$f){if($f->isLink())throw new RuntimeException('Linked candidate refused');chmod($f->getPathname(),$f->isDir()?0755:0644);}
 $updater=new DSA\Runtime\Update_Service();$verify=new ReflectionMethod($updater,'verify_release');$assert($verify->invoke($updater,$c['release'],'candidate')===true,'existing pinned signature owner');$verifyRoot=new ReflectionMethod($updater,'verify_package_root');$verifyRoot->invoke($updater,$stage,$c['release']);$assert(true,'existing complete package owner');
 $candidate=kd_pair($stage);
 if($deduplicated){
  if(isset($c['targetInventorySha256']))$assert(count($candidate)===$c['targetFileCount']&&hash_equals($c['targetInventorySha256'],hash('sha256',json_encode($candidate,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))),'every reconstructed byte matches complete signed artifact inventory');
  else{$target=$c['targetFiles'];ksort($target);$assert($candidate===$target,'every reconstructed byte matches complete signed artifact inventory');}
 }
 kd_write($private.'/candidate-manifest.json',$candidate);
 $acquire=new ReflectionMethod($updater,'acquire_lock');$assert($acquire->invoke($updater)===true,'native update lock acquired');$locked=true;kd_write($private.'/journal.json',['status'=>'installing']);
 $swap=new ReflectionMethod($updater,'swap_package');$swap->invoke($updater,$stage,$c['release']);$swapped=true;$releaseLock=new ReflectionMethod($updater,'release_lock');$releaseLock->invoke($updater);$locked=false;
 $assert(kd_pair($mu)===$candidate,'exact installed candidate');$proof['boot']=kd_child('--boot','boot.json');
 if(!empty($c['test']))$proof['native']=kd_child('--test','native.json');
 $assert(kd_pair($mu)===$candidate,'exact candidate after native acceptance');$assert(kd_controls()===kd_normalize_controls(json_decode(file_get_contents($private.'/controls-before.json'),true)),'original native settings remain exact');
 if(($c['_target']??'lab')==='lab')foreach($c['retireLabFiles']??[]as$file){$old=$c['executionRoot'].'/'.$file['name'];$assert(is_file($old)&&!is_link($old)&&hash_file('sha256',$old)===$file['sha256']&&unlink($old),'exact superseded lab file retired '.$file['name']);}
 if(empty($c['retainArchive']))$assert(unlink($zip),'upload retired');else $proof['archiveRetainedForLive']=true;
 kd_remove($stage,$private);$assert(!file_exists($mu.'/kiwe-update-transaction.json')&&!get_option('kiwe_update_lock',false),'native update owner retired');
 $proof['previousVersion']=$c['previousVersion'];$proof['previousPairRetained']=true;$proof['passed']=true;$proof['cleanup']=true;kd_write($private.'/journal.json',['status'=>'accepted']);
} catch(Throwable $e) {
 $proof['error']=$e->getMessage();
 // The root loader owns interruption rollback while its transaction exists.
 // After a complete new boot, retain exact previous custody for reviewed reversal.
 $proof['swapped']=$swapped;$proof['nativeRollbackPending']=file_exists($mu.'/kiwe-update-transaction.json');
 if(is_dir($private))kd_write($private.'/failure.json',['message'=>$e->getMessage(),'swapped'=>$swapped]);
} finally {
 if($locked&&isset($updater)){$r=new ReflectionMethod($updater,'release_lock');$r->invoke($updater);}
 if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
 if($proof['passed']&&empty($c['retainHelper']))$proof['helperRetired']=unlink(__FILE__);
 if(is_dir($private))kd_write($private.'/receipt.json',$proof);
}
echo json_encode($proof,JSON_UNESCAPED_SLASHES);exit($proof['passed']?0:1);
