<?php
declare(strict_types=1);
namespace DSA\Access { final class Backup_Workspace_Service {public static function allowed():bool{return ($GLOBALS['qaActor']??0)>0&&($GLOBALS['qaAllowed']??false)&&empty($GLOBALS['qaRevoked'][$GLOBALS['qaActor']]);}} }
namespace {
if(PHP_SAPI!=='cli')exit(2);
function get_current_user_id(){return $GLOBALS['qaActor'];}
function is_user_logged_in(){return get_current_user_id()>0;}
function current_user_can($cap){return is_user_logged_in()&&($cap==='read'||$GLOBALS['qaAllowed']);}
function wp_set_current_user($id){$GLOBALS['qaActor']=$id;}
function clean_user_cache($id){$GLOBALS['qaRefreshes']=($GLOBALS['qaRefreshes']??0)+1;}
function wp_salt($scheme='auth'){return 'Synthetic portable handoff salt '.$scheme;}
function apply_filters($name,$value,...$args){return $value;}
function get_current_blog_id(){return 1;}
function home_url($path='/'){return 'https://synthetic.example'.$path;}
function untrailingslashit($value){return rtrim($value,'/\\');}
function trailingslashit($value){return untrailingslashit($value).'/';}
function wp_normalize_path($value){return str_replace('\\','/',$value);}
function wp_mkdir_p($path){return mkdir($path,0700,true);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function is_multisite(){return false;}
function get_option($name,$default=false){return $name==='active_plugins'?['example/main.php']:$default;}
function get_template(){return'example';}
function get_stylesheet(){return'example';}
function get_template_directory(){return ABSPATH.'wp-content/themes/example';}
function get_stylesheet_directory(){return get_template_directory();}
function get_theme_root($slug=''){return ABSPATH.'wp-content/themes';}
function wp_get_theme($slug='',$root=''){return new SyntheticCaptureTheme();}
function wp_upload_dir($time=null,$create=true,$refresh=false){if($create!==false||$time!==null||$refresh!==true)throw new RuntimeException('Upload preflight attempted a native write');return ['basedir'=>ABSPATH.'wp-content/uploads','error'=>false];}
final class SyntheticCaptureTheme{public function exists(){return true;}public function errors(){return false;}}

$proof=['schema'=>'kiwe.portable-capture-job-source-proof.v1','passed'=>false,'cleanup'=>false,'native'=>false,'checks'=>[]];
$check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable$fn,string$label)use($check){try{$fn();}catch(\DSA\Backup\Archive_Error$e){$check(true,$label);return$e;}throw new RuntimeException('Expected refusal: '.$label);};
$base=sys_get_temp_dir().'/kiwe-capture-job-'.bin2hex(random_bytes(12));$root=$base.'/public_html';$vault=$base.'/kiwe-private-backups';$phrase='Synthetic private capture job phrase';$GLOBALS['qaActor']=4;$GLOBALS['qaAllowed']=true;$jobs=[];
try{
 mkdir($base,0700);mkdir($root,0700);
 foreach(['wp-includes','wp-admin','wp-admin/includes','wp-content','wp-content/plugins','wp-content/plugins/example','wp-content/mu-plugins','wp-content/mu-plugins/kiwe','wp-content/mu-plugins/kiwe/includes','wp-content/mu-plugins/kiwe/includes/Backup','wp-content/mu-plugins/kiwe/includes/Security','wp-content/mu-plugins/kiwe/fixtures','wp-content/themes','wp-content/themes/example','wp-content/uploads']as$dir)mkdir($root.'/'.$dir,0700);
 foreach(['index.php','wp-blog-header.php','wp-load.php','wp-settings.php','wp-config.php','wp-login.php','wp-cron.php','wp-includes/version.php','wp-includes/load.php','wp-includes/functions.php','wp-admin/admin.php','wp-admin/includes/admin.php','wp-content/plugins/example/main.php','wp-content/mu-plugins/kiwe/entry.php','wp-content/themes/example/style.css']as$file)file_put_contents($root.'/'.$file,'<?php /* owned synthetic source */');
 file_put_contents($root.'/wp-content/mu-plugins/kiwe-loader.php','<?php function kiwe_mu_debug_log(){}');file_put_contents($root.'/wp-content/uploads/image.bin',random_bytes(700000));
 define('ABSPATH',$root.'/');define('WPINC','wp-includes');define('WP_CONTENT_DIR',$root.'/wp-content');define('WP_PLUGIN_DIR',WP_CONTENT_DIR.'/plugins');define('WPMU_PLUGIN_DIR',WP_CONTENT_DIR.'/mu-plugins');define('DSA_DIR',WPMU_PLUGIN_DIR.'/kiwe/');define('DSA_FILE',DSA_DIR.'entry.php');define('DSA_VERSION','8.0.0-fixture');define('KIWE_MU_LOADER_VERSION',DSA_VERSION);define('ARRAY_N','ARRAY_N');
 $wp_version='7.1.2';$wp_db_version=61234;$wp_theme_directories=[$root.'/wp-content/themes'];require $root.'/wp-config.php';require WPMU_PLUGIN_DIR.'/kiwe-loader.php';require DSA_FILE;
 foreach(['Archive_Error','Archive_Cipher','Configuration_Snapshot_Service','Archive_Job_Files','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Archive_Container','Portable_Database_Capture','Portable_Source_Compatibility','Portable_Recovery_Set','Portable_Source_Observation','Portable_Capture_Job_Service','Portable_Capture_Worker_Service']as$class){copy(__DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$class.'.php',DSA_DIR.'includes/Backup/'.$class.'.php');require DSA_DIR.'includes/Backup/'.$class.'.php';}
 copy(__DIR__.'/../../wp-content/mu-plugins/dsa/includes/Security/Secret_Store.php',DSA_DIR.'includes/Security/Secret_Store.php');require DSA_DIR.'includes/Security/Secret_Store.php';
 copy(__DIR__.'/fixtures/portable-wpdb.php',DSA_DIR.'fixtures/portable-wpdb.php');require DSA_DIR.'fixtures/portable-wpdb.php';$wpdb=new wpdb();$service=new \DSA\Backup\Portable_Capture_Job_Service();
 $make=static function()use($service,&$jobs){$r=$service->create();$jobs[$r['id']]=get_current_user_id();return$r['id'];};
 $dir=static fn(string$id)=>\DSA\Backup\Archive_Job_Files::directory('portable',$id);
 $load=static fn(string$id)=>json_decode(\DSA\Backup\Archive_Job_Files::read($dir($id),'info.json',16384),true);
 $save=static fn(string$id,array$info)=>\DSA\Backup\Archive_Job_Files::write($dir($id),'info.json',json_encode($info));
 $check($service->inventory()===[]&&!is_dir($vault),'empty account listing does not create a private vault or queue');
 $GLOBALS['qaActor']=0;$reject(static fn()=>$service->inventory(),'anonymous capture inventory denied');$GLOBALS['qaActor']=4;
 $id=$make();$check($service->status($id)['status']==='pending'&&!$service->status($id)['fullSiteReady'],'job starts privately without claiming full-site download readiness');
 $listing=$service->inventory();$check(count($listing)===1&&$listing[0]['id']===$id&&$listing[0]['canQueue']&&!$listing[0]['canReview']&&!$listing[0]['expired']&&!$listing[0]['sourceChanged'],'account listing describes only its own bounded pending attempt');
 $check(!str_contains(json_encode($listing),$base)&&!str_contains(json_encode($listing),'binding')&&!str_contains(json_encode($listing),'reservedBytes'),'capture inventory omits native binding storage reservations and private paths');
 $check(realpath(dirname($dir($id)))===realpath($vault)&&!str_starts_with(realpath($vault),realpath($root).DIRECTORY_SEPARATOR),'capture storage remains outside the website root');
 $check(!str_contains(json_encode($service->status($id)),$phrase)&&!str_contains(json_encode($service->status($id)),$base),'job projection reveals no recovery secret or private path');
 $worker=\DSA\Backup\Portable_Capture_Worker_Service::class;
 $check(!$worker::readiness()['ready'],'a website without an observed CLI worker refuses readiness');
 $reject(static fn()=>$service->enqueue($id,$phrase),'queue refused before native worker observation');
 $check($worker::tick()===['claimed'=>0,'failed'=>0,'busy'=>false]&&!is_file($vault.'/worker.kiwe'),'non-cron bootstrap cannot run capture or manufacture readiness');
 $reject(static fn()=>$service->run_queued($id),'queued claim requires native CLI cron context');
 define('DOING_CRON',true);$check($worker::tick()===['claimed'=>0,'failed'=>0,'busy'=>false]&&$worker::readiness()['ready'],'native CLI cron establishes private authenticated readiness without a capture');
 $check(!str_contains(file_get_contents($vault.'/worker.kiwe'),$base)&&!str_contains(json_encode($worker::readiness()),$base),'worker readiness reveals no native source path');
 $id2=$make();$reject(static fn()=>$service->create(),'third per-account capture refused');
 $GLOBALS['qaActor']=5;$check($service->inventory()===[],'another account cannot enumerate existing private captures');foreach(['status','remove']as$method)$reject(static fn()=>$service->$method($id),'other account '.$method.' denied');
 $reject(static fn()=>$service->run($id,$phrase,$wpdb),'other account capture worker denied');$third=$make();$fourth=$make();$GLOBALS['qaActor']=6;$reject(static fn()=>$service->create(),'fifth website capture refused');
 $GLOBALS['qaActor']=5;$listing=$service->inventory();$check(count($listing)===2&&!in_array($id,array_column($listing,'id'),true)&&!in_array($id2,array_column($listing,'id'),true),'per-account listing excludes every other owner at the four-site-attempt limit');
 $GLOBALS['qaActor']=5;$service->remove($third);unset($jobs[$third]);$service->remove($fourth);unset($jobs[$fourth]);$GLOBALS['qaActor']=4;$service->remove($id2);unset($jobs[$id2]);
 $lock=fopen($dir($id).DIRECTORY_SEPARATOR.'lock','c+b');flock($lock,LOCK_EX);$start=hrtime(true);$check($service->status($id)['busy'],'active worker lock permits only a busy status projection');$check((hrtime(true)-$start)/1e9<0.2,'private job contention introduces no deliberate blocking wait');$held=$load($id);$check($service->inventory()[0]['busy']&&!$service->inventory()[0]['canQueue']&&$load($id)===$held,'busy inventory does not mutate the active journal or offer retry');flock($lock,LOCK_UN);fclose($lock);
 $GLOBALS['qaAllowed']=false;$reject(static fn()=>$service->status($id),'revoked backup access cannot read capture state');$check(count($service->inventory())===1&&!$service->inventory()[0]['canQueue']&&!$service->inventory()[0]['canReview'],'revoked Backups account can locate only its own attempts for cleanup');$GLOBALS['qaAllowed']=true;
 $prefix=$wpdb->prefix;$wpdb->prefix='other_';$reject(static fn()=>$service->status($id),'changed native source binding denied');$check($service->inventory()[0]['sourceChanged']&&!$service->inventory()[0]['canQueue'],'source-changed attempt remains visible only for account-owned cleanup');$wpdb->prefix=$prefix;
 $reject(static fn()=>$service->run($id,$phrase,new wpdb()),'capture cannot substitute another database owner');
 $cancelled=$reject(static fn()=>$service->run($id,$phrase,$wpdb,static function(){throw new \DSA\Backup\Archive_Error('synthetic secret must not leak');}),'cancelled worker fails instead of publishing a capture');
 $check($cancelled->getPrevious()===null&&!str_contains($cancelled->getMessage(),'synthetic secret'),'external cancellation exceptions are not chained into operator diagnostics');
 $failed=$service->status($id);$check($failed['status']==='failed'&&$failed['attempt']===1&&$failed['bytes']===0&&$failed['error']==='capture-failed','failed attempt records durable redacted status');
 $check(array_values(array_diff(scandir($dir($id)),['.','..','info.json','lock']))===[],'cancelled worker retires all partial capture artifacts');
 $info=$load($id);$info['status']='running';$info['reservedBytes']=67108864;$save($id,$info);file_put_contents($dir($id).DIRECTORY_SEPARATOR.'database.kiwe','interrupted partial ciphertext');
 $interrupted=$service->status($id);$check($interrupted['status']==='interrupted'&&$interrupted['restartRequired']&&$interrupted['bytes']===0,'stale worker state is classified interrupted only after acquiring its exclusive lock');
 $reject(static fn()=>$service->download_path($id),'interrupted job cannot be downloaded');
 $before=$load($id);$reject(static fn()=>$service->run($id,'too short',$wpdb),'invalid recovery phrase refused before retry retirement');$check($load($id)===$before&&is_file($dir($id).DIRECTORY_SEPARATOR.'database.kiwe'),'invalid retry secret leaves interrupted bytes and journal unchanged');
 $r=$service->run($id,$phrase,$wpdb);$check($r['status']==='captured'&&$r['attempt']===2&&$r['bytes']>0&&!$r['fullSiteReady'],'fresh attempt independently verifies its set before recording captured state');
 $check(array_values(array_diff(scandir($dir($id)),['.','..','info.json','lock','recovery.kiwe','observation.kiwe']))===[],'successful capture retains only encrypted set observation and journal');
 $info=$load($id);$path=$dir($id).DIRECTORY_SEPARATOR.'recovery.kiwe';$check(hash_file('sha256',$path)===$info['archiveSha256']&&filesize($path)===$info['bytes'],'retained set exactly matches its durable completion receipt');
 $check($service->inventory()[0]['canReview']&&!$service->inventory()[0]['canQueue']&&!$service->inventory()[0]['fullSiteReady'],'authenticated captured listing offers review without completed full-site readiness');
 $input=fopen($path,'rb');try{$review=\DSA\Backup\Portable_Recovery_Set::verify($input,$phrase);}finally{fclose($input);}
 $sealed=\DSA\Backup\Archive_Job_Files::read($dir($id),'observation.kiwe',1048576);$observation=\DSA\Backup\Portable_Source_Observation::open($sealed,$phrase);
 $check($observation['observedStable']&&$observation['recoverySha256']===$info['archiveSha256']&&$observation['recoveryArchiveId']===$review['archiveId'],'encrypted source observation is bound to the verified recovery set');
 $check(!str_contains($sealed,$phrase)&&!str_contains($sealed,'fileInventorySha256')&&!str_contains(file_get_contents($path),'synthetic private'),'private files do not persist the recovery phrase or source observation plaintext');
 $check(!$wpdb->active&&!$wpdb->readonly&&$wpdb->isolation==='READ-COMMITTED','completed job restores native caller transaction ownership and defaults');
 $export=getenv('KIWE_CAPTURE_QA_EXPORT');if($export!==false){if(!is_dir($export)||is_link($export)||!str_starts_with(basename($export),'kiwe-download-fixture-'))throw new RuntimeException('Unsafe synthetic export folder');foreach(['recovery.kiwe','observation.kiwe']as$name){$target=$export.DIRECTORY_SEPARATOR.$name;if(file_exists($target)||is_link($target)||!copy($dir($id).DIRECTORY_SEPARATOR.$name,$target)||!chmod($target,0600))throw new RuntimeException('Synthetic export failed');}}
 copy(__DIR__.'/fixtures/portable-download.php',DSA_DIR.'fixtures/portable-download.php');$download=(static fn()=>require DSA_DIR.'fixtures/portable-download.php')();$proof['download']=$download($service,$id,$phrase);$check($proof['download']['passed']===true&&$proof['download']['cleanup']===true,'shared protected offline transport boundaries pass');
 $reject(static fn()=>$service->download_path($id),'captured transport still cannot advertise a completed full-site backup');
 $hash=hash_file('sha256',$path);$reject(static fn()=>$service->run($id,$phrase,$wpdb),'completed captured bytes cannot be replaced by retry');$check(hash_file('sha256',$path)===$hash,'refused completed retry preserves the original captured bytes');
 $guarded=$make();$changedAfterView=false;$guardedDatabase=$dir($guarded).DIRECTORY_SEPARATOR.'database.kiwe';
 $replaceConnection=static function()use($wpdb,$guardedDatabase,&$changedAfterView){clearstatcache(true,$guardedDatabase);if(!$changedAfterView&&!$wpdb->active&&is_file($guardedDatabase)&&filesize($guardedDatabase)>0){$changedAfterView=true;$wpdb->connection='99';usleep(2100000);}};
 $reject(static fn()=>$service->run($guarded,$phrase,$wpdb,$replaceConnection),'connection replacement after the read view closes refuses archive completion');
 $check($changedAfterView&&$service->status($guarded)['status']==='failed'&&!$wpdb->active&&!$wpdb->readonly&&array_values(array_diff(scandir($dir($guarded)),['.','..','info.json','lock']))===[],'post-view connection failure preserves defaults and retires incomplete ciphertext');
 $service->remove($guarded);unset($jobs[$guarded]);$wpdb->connection='41';
 $large=$make();$wpdb->hook=static fn($sql)=>str_starts_with($sql,'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0)')?[['4294967296']]:null;
 try{$captured=$service->run($large,$phrase,$wpdb);$check($captured['status']==='captured','supported native storage estimate above the former reservation ceiling captures');}finally{$wpdb->hook=null;}
 $original=$load($large);$tampered=$original;$tampered['reservedBytes']=\DSA\Backup\Portable_Capture_Job_Service::MAX_RESERVED_BYTES+1;$save($large,$tampered);
 try{$reject(static fn()=>$service->status($large),'capture journals above the enlarged hard reservation refuse');}finally{$save($large,$original);}
 $check($service->remove($large)['removed'],'larger reviewed reservation releases after acknowledged removal');unset($jobs[$large]);
 $other=$make();$wpdb->hook=static fn($sql)=>str_starts_with($sql,'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0)')?[['4294967297']]:null;$storage=$reject(static fn()=>$service->run($other,$phrase,$wpdb),'unsupported native storage estimate fails closed');$wpdb->hook=null;$check($service->status($other)['status']==='failed'&&$load($other)['reservedBytes']===0,'storage refusal releases its reservation and retains a retryable journal');
 $diagnostic=$storage->getPrevious();$check($diagnostic instanceof \DSA\Backup\Archive_Error&&preg_match('/^Capture boundary Portable_Capture_Job_Service\\.php:[1-9][0-9]*$/D',$diagnostic->getMessage())&&!str_contains($diagnostic->getMessage(),$base)&&!str_contains($diagnostic->getMessage(),$phrase),'trusted library failures retain only a filename and line while client feedback remains generic');
 $handoff=static fn(string$job)=>$dir($job).DIRECTORY_SEPARATOR.'handoff.kiwe';
 $sealed=static fn(string$job)=>\DSA\Security\Secret_Store::decrypt(file_get_contents($handoff($job)));
 $alter=static function(string$job,callable$edit)use($handoff,$sealed){$data=json_decode($sealed($job),true);$edit($data);file_put_contents($handoff($job),\DSA\Security\Secret_Store::encrypt(json_encode($data)));};
 $q=$service->enqueue($other,$phrase);$check($q['status']==='queued'&&$q['attempt']===1&&!$q['fullSiteReady'],'enqueue records pending worker ownership without running a capture');
 $check(!str_contains(file_get_contents($handoff($other)),$phrase)&&!str_contains(file_get_contents($dir($other).'/info.json'),$phrase)&&!str_contains(json_encode($q),$phrase),'recovery passphrase appears in neither queue journal projection nor ciphertext plaintext');
 $data=json_decode($sealed($other),true);$check($data['id']===$other&&$data['actor']===4&&$data['attempt']===2&&$data['expiresAt']-$data['createdAt']===900,'encrypted handoff binds one job account source attempt and fifteen-minute claim window');
 $reject(static fn()=>$service->enqueue($other,$phrase),'a queued handoff cannot be replaced');$reject(static fn()=>$service->run($other,$phrase,$wpdb),'direct capture cannot bypass a queued handoff');
 $GLOBALS['qaActor']=5;$reject(static fn()=>$service->enqueue($other,$phrase),'another account cannot replace a queued handoff');$GLOBALS['qaActor']=4;
 $lock=fopen($vault.'/worker.lock','c+b');flock($lock,LOCK_EX);$start=hrtime(true);$check($worker::tick()['busy']&&is_file($handoff($other))&&(hrtime(true)-$start)/1e9<0.2,'competing worker fails immediately and preserves an unclaimed handoff');flock($lock,LOCK_UN);fclose($lock);
 $reject(static fn()=>$service->run_queued($other,static function(){throw new \DSA\Backup\Archive_Error('synthetic cancellation secret');}),'queued cancelled capture cannot publish a completed set');
 $check($service->status($other)['status']==='failed'&&$service->status($other)['attempt']===2&&!is_file($handoff($other))&&get_current_user_id()===4,'failed queued capture consumes its handoff once and restores caller principal');
 $check(!$service->run_queued($other),'consumed handoff cannot be replayed');
 $service->enqueue($other,$phrase);$alter($other,static function(&$d){$d['createdAt']=time()-901;$d['expiresAt']=$d['createdAt']+900;});$reject(static fn()=>$service->run_queued($other),'expired encrypted handoff refused');
 $check($service->status($other)['error']==='handoff-refused'&&!is_file($handoff($other)),'expired handoff is retired without a capture or plaintext failure');
 foreach(['id','actor','binding','attempt','extra','passphrase']as$key){$service->enqueue($other,$phrase);$alter($other,static function(&$d)use($key){if($key==='extra')$d['extra']='unsupported';elseif($key==='actor'||$key==='attempt')++$d[$key];elseif($key==='passphrase')$d[$key]='short';else$d[$key]=str_repeat('0',$key==='binding'?64:32);});$reject(static fn()=>$service->run_queued($other),'changed encrypted handoff '.$key.' refused');$check($service->status($other)['error']==='handoff-refused','changed handoff '.$key.' remains a redacted failed journal');}
 $service->enqueue($other,$phrase);$raw=file_get_contents($handoff($other));file_put_contents($handoff($other),substr($raw,0,-6).'damage');$reject(static fn()=>$service->run_queued($other),'tampered handoff ciphertext refused');
 $service->enqueue($other,$phrase);$GLOBALS['qaRevoked'][4]=true;$GLOBALS['qaActor']=0;$reject(static fn()=>$service->run_queued($other),'initiating account permission revoked before native claim is refused');$check(get_current_user_id()===0&&!is_file($handoff($other)),'revoked queued claim preserves anonymous caller and retires its recovery secret');$GLOBALS['qaRevoked'][4]=false;$GLOBALS['qaActor']=4;
 $service->enqueue($other,$phrase);unlink($handoff($other));$reject(static fn()=>$service->run_queued($other),'crash between claim and capture leaves a refused missing handoff');
 $service->enqueue($other,$phrase);$held=fopen($dir($other).'/lock','c+b');flock($held,LOCK_EX);$reject(static fn()=>$service->run_queued($other),'queued job held by another operation cannot be claimed');$check(is_file($handoff($other)),'job-lock contention leaves the recovery handoff intact');flock($held,LOCK_UN);fclose($held);
 $GLOBALS['qaActor']=0;$check($service->run_queued($other)&&get_current_user_id()===0,'native worker completes one synthetic capture using its initiating account and restores anonymous cron context');$GLOBALS['qaActor']=4;
 $check($service->status($other)['status']==='captured'&&$service->status($other)['attempt']===3&&!is_file($handoff($other))&&!$service->status($other)['fullSiteReady'],'independently verified queued capture keeps download acceptance closed');
 $check($GLOBALS['qaRefreshes']>=5,'capture checks fresh native account state before publication');
 $reject(static fn()=>$service->enqueue($other,$phrase),'completed capture cannot be requeued');
 $service->remove($other);unset($jobs[$other]);$other=$make();
 $ready=json_decode(\DSA\Security\Secret_Store::decrypt(file_get_contents($vault.'/worker.kiwe')),true);$ready['observedAt']=time()-901;\DSA\Backup\Archive_Job_Files::write($vault,'worker.kiwe',\DSA\Security\Secret_Store::encrypt(json_encode($ready)));
 $check(!$worker::readiness()['ready'],'stale native heartbeat cannot advertise a working background service');$reject(static fn()=>$service->enqueue($other,$phrase),'stale worker refuses a new recovery secret');$worker::tick();
 $service->enqueue($other,str_repeat("\0",1024));$reject(static fn()=>$service->run_queued($other,static function(){throw new \DSA\Backup\Archive_Error('cancel');}),'maximum escaped recovery phrase can reach a worker without an envelope size refusal');
 $check($service->status($other)['error']==='capture-failed'&&$service->status($other)['attempt']===1,'accepted maximum-length handoff reaches capture and consumes once');
 $prefix=$wpdb->prefix;$wpdb->prefix='changed_';$check(!$worker::readiness()['ready'],'worker readiness is bound to the current native database source');$wpdb->prefix=$prefix;
 $ready['observedAt']=time()+10;\DSA\Backup\Archive_Job_Files::write($vault,'worker.kiwe',\DSA\Security\Secret_Store::encrypt(json_encode($ready)));$check(!$worker::readiness()['ready'],'future native heartbeat refused');$worker::tick();
 $service->enqueue($other,$phrase);$GLOBALS['qaActor']=0;
 // Revoke after capture starts but before publication, using the SQL owner hook.
 $wpdb->hook=static function($sql){if($sql==='ROLLBACK')$GLOBALS['qaRevoked'][4]=true;return null;};
 $reject(static fn()=>$service->run_queued($other),'account access revoked during capture refuses publication after closing the read view');$wpdb->hook=null;$GLOBALS['qaRevoked'][4]=false;$GLOBALS['qaActor']=4;
 $check($service->status($other)['status']==='failed'&&$service->status($other)['bytes']===0&&!is_file($dir($other).'/recovery.kiwe'),'publication refusal retires partial ciphertext and keeps a redacted failed attempt');
 $info=$load($other);$info['createdAt']=time()-604900;$info['expiresAt']=$info['createdAt']+604800;$save($other,$info);$reject(static fn()=>$service->status($other),'expired capture state cannot be served');$expired=array_values(array_filter($service->inventory(),static fn($j)=>$j['id']===$other))[0];$check($expired['expired']&&!$expired['canQueue']&&!$expired['canReview'],'expired inventory supports cleanup but neither retry nor offline review');$check($service->remove($other)['removed'],'account can remove its expired private capture');unset($jobs[$other]);
 file_put_contents($dir($id).DIRECTORY_SEPARATOR.'unexpected.keep','owned unknown sentinel');$reject(static fn()=>$service->remove($id),'unknown job artifact prevents destructive cleanup');$check(is_file($path)&&is_file($dir($id).DIRECTORY_SEPARATOR.'unexpected.keep'),'unknown-entry refusal preserves the captured set and sentinel');unlink($dir($id).DIRECTORY_SEPARATOR.'unexpected.keep');
 $GLOBALS['qaAllowed']=false;$check($service->remove($id)['removed'],'revoked backup account can retire its own ciphertext while retaining native read access');unset($jobs[$id]);$GLOBALS['qaAllowed']=true;
 $check(!glob($vault.DIRECTORY_SEPARATOR.'portable-job-*'),'all owned capture jobs have been retired');$proof['passed']=true;
 file_put_contents($vault.'/portable-job-invalid','owned unreviewed entry');$reject(static fn()=>$service->inventory(),'unreviewed private capture entry refuses inventory without removal');$check(is_file($vault.'/portable-job-invalid'),'inventory refusal preserves an unreviewed entry');unlink($vault.'/portable-job-invalid');
 for($n=0;$n<1025;++$n)file_put_contents($vault.'/inventory-fixture-'.$n,'owned');$reject(static fn()=>$service->inventory(),'capture inventory enforces its directory inspection budget');for($n=0;$n<1025;++$n)unlink($vault.'/inventory-fixture-'.$n);
}catch(\Throwable$e){$proof['error']=$e->getMessage();}
finally{
 // Everything under this unpredictable test root was created by this fixture.
 if(is_dir($base)&&!is_link($base)&&str_starts_with($base,sys_get_temp_dir().'/kiwe-capture-job-')){
  $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
  foreach($it as$entry){$entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($base);
 }
 $proof['cleanup']=!file_exists($base);$proof['checks']['all owned fixture code files vault jobs and quota lock retired']=$proof['cleanup'];$proof['passed']=$proof['passed']&&$proof['cleanup'];
}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($proof['passed']?0:1);
}
