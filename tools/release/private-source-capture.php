<?php
declare(strict_types=1);
/** Operator-only capture of an existing native Kiwe site with the reviewed current Backup owners. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
return static function(array $c): array {
 $proof=['schema'=>'kiwe.private-original-source-capture.v1','passed'=>false,'cleanup'=>false,'sourceRuntimeReplaced'=>false,'checks'=>[]];
 $check=static function(bool $ok,string $label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $private=$c['private'];$root=$c['root'];$started=hrtime(true);$phase='preflight';$lastProgress=-10;$streams=[];
 $budget=static function()use($started,$c,$private,&$phase,&$lastProgress,&$streams){
  $elapsed=(hrtime(true)-$started)/1000000000;
  if($elapsed-$lastProgress>=10){$members=[];foreach($streams as$name=>$stream)if(is_resource($stream))$members[$name]=['cursor'=>ftell($stream),'bytes'=>fstat($stream)['size']];$json=json_encode(['schema'=>'kiwe.private-source-progress.v1','phase'=>$phase,'seconds'=>$elapsed,'members'=>$members,'memoryBytes'=>memory_get_usage(true),'peakMemoryBytes'=>memory_get_peak_usage(true)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$h=fopen($private.'/progress.private.json','c+b');if(!$h||!chmod($private.'/progress.private.json',0600)||!ftruncate($h,0)||fwrite($h,$json)!==strlen($json)||!fflush($h)||!fsync($h))throw new RuntimeException('Private capture progress storage refused');fclose($h);$lastProgress=$elapsed;}
  if($elapsed>$c['captureSeconds'])throw new DSA\Backup\Archive_Error('Original-source capture exceeded its operation budget');
 };
 $write=static function(string $p,string $bytes){$s=fopen($p,'x+b');if(!$s||!chmod($p,0600))throw new RuntimeException('Private capture storage refused');$at=0;while($at<strlen($bytes)){$n=fwrite($s,substr($bytes,$at,65536));if(!$n)throw new RuntimeException('Private capture write incomplete');$at+=$n;}fflush($s);fsync($s);fclose($s);};
 $phrase=null;$token=null;$retained=null;
 try {
  $preflight=json_decode(file_get_contents($c['preflight'].'/preflight.json'),true,512,JSON_THROW_ON_ERROR);
  $before=json_decode(file_get_contents($c['preflight'].'/original-native-fingerprints.json'),true,512,JSON_THROW_ON_ERROR);
  $check($preflight['passed']===true&&$preflight['cleanup']===true&&$preflight['wordpressBooted']===false,'accepted original native source preflight');
  $check($before['configurationSha256']===hash_file('sha256',$root.'/wp-config.php'),'existing connection and salts are unchanged');
  $check(DSA\Backup\Portable_Archive_Cipher::new_header()['crypto']['iterations']===DSA\Backup\Archive_Cipher::ITERATIONS,'complete canonical encryption dependencies are present');
  $phrase=bin2hex(random_bytes(32));$token=bin2hex(random_bytes(32));
  if(isset($c['reuse'])){
   $check(realpath($c['reuse'])===$c['reuse']&&!is_link($c['reuse'])&&dirname($c['reuse'])===dirname($private),'retained private member belongs to the exact source vault');
   $key=$c['reuse'].'/recovery-phrase.private';$file=$c['reuse'].'/files.kiwe';
   foreach([$key,$file]as$p){$s=lstat($p);$check($s&&!is_link($p)&&($s['mode']&0170000)===0100000&&$s['nlink']===1&&($s['mode']&0777)===0600,'retained source material remains private and ordinary: '.basename($p));}
   $phrase=file_get_contents($key);$check(preg_match('/^[a-f0-9]{64}$/D',$phrase)===1,'retained file phrase has the exact operator format');$retained=fopen($file,'rb');
  }
  $write($private.'/recovery-phrase.private',$phrase);$write($private.'/transfer-token.private',$token);
  $write($private.'/native-php-errors.private.log','');ini_set('log_errors','1');ini_set('error_log',$private.'/native-php-errors.private.log');
  $encrypted=null;$check(openssl_public_encrypt(json_encode(['phrase'=>$phrase,'token'=>$token],JSON_THROW_ON_ERROR),$encrypted,$c['publicKey'],OPENSSL_PKCS1_OAEP_PADDING),'recovery key encrypted to independent operator custody');
  $write($private.'/operator-key.sealed',base64_encode($encrypted));unset($encrypted);
  // The normal native bootstrap remains the source authority. Delivery/cron are blocked in this process.
  foreach(['DISABLE_WP_CRON'=>true,'WP_HTTP_BLOCK_EXTERNAL'=>true,'AUTOMATIC_UPDATER_DISABLED'=>true,'WP_USE_THEMES'=>false]as$k=>$v)if(!defined($k))define($k,$v);
  $_SERVER['HTTP_HOST']=$c['domain'];$_SERVER['SERVER_NAME']=$c['domain'];$_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/';
  require_once $root.'/wp-includes/plugin.php';
  add_filter('pre_http_request',static fn()=>new WP_Error('kiwe_private_capture','Network delivery blocked during operator capture'),PHP_INT_MIN);
  add_filter('pre_wp_mail',static fn()=>false,PHP_INT_MIN);
  $phase='native-bootstrap';require $root.'/wp-load.php';
  // WordPress debug setup may reset ini values. Keep operator diagnostics private after bootstrap.
  ini_set('display_errors','0');ini_set('log_errors','1');ini_set('error_log',$private.'/native-php-errors.private.log');
  global $wpdb;
  $check(defined('DSA_VERSION')&&DSA_VERSION===$c['kiwe']&&DSA_VERSION===KIWE_MU_LOADER_VERSION,'exact original Kiwe pair boots without replacement');
  $check(!class_exists('WooCommerce'),'original source is a non-Woo website');
  $wpdb->query('SET SESSION wait_timeout=1800');
  $phase='source-inventory';
  if($retained){
   if(isset($c['reviewedPlanSha256'])){
    $file=$c['preflight'].'/original-file-inventory.json';$stat=lstat($file);
    $check($stat&&!is_link($file)&&($stat['mode']&0170000)===0100000&&$stat['nlink']===1&&($stat['mode']&0777)===0600,'reviewed original inventory remains private and ordinary');
    $bytes=file_get_contents($file);$check(hash('sha256',$bytes)===$c['reviewedPlanSha256'],'reviewed original inventory fingerprint exact');$plan=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);unset($bytes);
    DSA\Backup\Portable_File_Inventory::validate_plan($plan,$budget);
    $check(count($plan['files'])===$preflight['source']['files']&&$plan['bytes']===$preflight['source']['fileBytes'],'reviewed original inventory agrees with accepted source preflight');
    // This is a pinned reviewed plan, not an accepted member. The shared observation
    // owner fully authenticates the retained ciphertext before opening the native view.
    $proof['reviewedPlanSha256']=$c['reviewedPlanSha256'];
   }else{$review=DSA\Backup\Portable_Archive_Container::verify($retained,$phrase,$budget);rewind($retained);$plan=$review['inventory'];}
   $proof['retainedFiles']=['sourcePrivateId'=>basename($c['reuse']),'bytes'=>filesize($c['reuse'].'/files.kiwe'),'authenticated'=>false];
  }
  else $plan=DSA\Backup\Portable_File_Inventory::scan($root,DSA\Backup\Portable_File_Inventory::MAX_FILES,DSA\Backup\Portable_File_Inventory::MAX_BYTES,$budget,'posix');
  $source=DSA\Backup\Portable_Source_Compatibility::inspect($root,$plan,$budget);
  $check($source['platform']['wordpress']===$c['wordpress'],'native source WordPress version agrees');
  $check(wp_get_theme('bricks')->get('Version')===$c['bricks'],'native source Bricks version agrees');
  $sourceBytes=json_encode($source,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$write($private.'/source-layout.private.json',$sourceBytes);
  $phase='previous-kiwe-pair';$previous=$private.'/previous-kiwe-pair';if(!mkdir($previous,0700)||!chmod($previous,0700))throw new RuntimeException('Previous Kiwe directory refused');
  $pair=[];
  foreach($plan['files']as$entry){$rel=$entry['path'];if($rel!=='wp-content/mu-plugins/dsa.php'&&$rel!=='wp-content/mu-plugins/kiwe-incident-guard.php'&&!str_starts_with($rel,'wp-content/mu-plugins/dsa/'))continue;
   $name=substr($rel,strlen('wp-content/mu-plugins/'));$dest=$previous.'/'.$name;$parent=dirname($dest);if(!is_dir($parent)&&(!mkdir($parent,0700,true)||!chmod($parent,0700)))throw new RuntimeException('Previous Kiwe parent refused');
   $from=DSA\Backup\Portable_File_Inventory::unchanged($root,$entry,$budget,'posix');$write($dest,file_get_contents($from));$check(hash_file('sha256',$dest)===$entry['sha256'],'previous Kiwe member exact: '.$name);$pair[$name]=['bytes'=>$entry['bytes'],'sha256'=>$entry['sha256']];
  }
  $write($private.'/previous-kiwe-manifest.json',json_encode($pair,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
  $pairPlan=DSA\Backup\Portable_File_Inventory::scan($previous,DSA\Backup\Portable_File_Inventory::MAX_FILES,DSA\Backup\Portable_File_Inventory::MAX_BYTES,$budget,'posix');
  $pairStream=fopen($private.'/previous-kiwe.kiwefiles','x+b');chmod($private.'/previous-kiwe.kiwefiles',0600);
  try{DSA\Backup\Portable_Archive_Container::capture($previous,$pairPlan,$pairStream,new DSA\Backup\Portable_Archive_Cipher(DSA\Backup\Portable_Archive_Cipher::new_header(),$phrase),$budget);fflush($pairStream);fsync($pairStream);}finally{fclose($pairStream);}
  foreach($c['phase']==='members'?['database.kiwe','files.kiwe']:['database.kiwe','files.kiwe','recovery.kiwebackup']as$n){if($n==='files.kiwe'&&$retained){$streams[$n]=$retained;continue;}$s=fopen($private.'/'.$n,'x+b');if(!$s||!chmod($private.'/'.$n,0600))throw new RuntimeException('Fresh private member required');$streams[$n]=$s;}
  $phase='consistent-members';$method=$retained?'capture_existing_files':'capture';$observed=DSA\Backup\Portable_Source_Observation::$method($root,$plan,$wpdb,$streams['database.kiwe'],$streams['files.kiwe'],$phrase,$budget);
  if($retained)$proof['retainedFiles']['authenticated']=true;
  if($c['phase']==='members'){
   foreach($streams as$s){fflush($s);if(stream_get_meta_data($s)['mode']!=='rb')fsync($s);fclose($s);}$streams=[];
   $file=$retained?$c['reuse'].'/files.kiwe':$private.'/files.kiwe';
   $state=['schema'=>'kiwe.private-original-observed-members.v1','root'=>$root,'source'=>$source,'observation'=>$observed,'database'=>['path'=>$private.'/database.kiwe','bytes'=>filesize($private.'/database.kiwe'),'sha256'=>hash_file('sha256',$private.'/database.kiwe')],'files'=>['path'=>$file,'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file)],'previousKiweMembers'=>count($pair),'bricks'=>$c['bricks']];
   $write($private.'/observed-members.private.json',json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
   $proof['schema']='kiwe.private-original-member-capture.v1';$proof['scope']='authenticated-files-and-ended-native-read-view-only';$proof['stateSha256']=hash_file('sha256',$private.'/observed-members.private.json');
   $proof['members']=['database'=>['bytes'=>$state['database']['bytes'],'sha256'=>$state['database']['sha256']],'files'=>['bytes'=>$state['files']['bytes'],'sha256'=>$state['files']['sha256']]];$proof['tables']=$observed['database']['tables'];$proof['rows']=$observed['database']['rows'];$proof['fileCount']=count($plan['files']);$proof['fileBytes']=$plan['bytes'];$proof['databaseTransactionEnded']=$observed['database']['transactionEnded'];$proof['observedStable']=$observed['observedStable'];$proof['fullRecoveryCreated']=false;
   $proof['passed']=true;$proof['cleanup']=true;$proof['milliseconds']=(hrtime(true)-$started)/1000000;return$proof;
  }
  rewind($streams['database.kiwe']);rewind($streams['files.kiwe']);
  $phase='recovery-envelope';$set=DSA\Backup\Portable_Recovery_Set::create($streams['database.kiwe'],$streams['files.kiwe'],$source,$streams['recovery.kiwebackup'],$phrase,$budget);
  foreach($streams as$s){fflush($s);fsync($s);fclose($s);}$streams=[];
  $observed['recoveryArchiveId']=$set['archiveId'];$observed['recoverySha256']=$set['sha256'];$write($private.'/observation.kiwesource',DSA\Backup\Portable_Source_Observation::seal($observed,$phrase));
  $phase='independent-verification';$in=fopen($private.'/recovery.kiwebackup','rb');try{$verified=DSA\Backup\Portable_Recovery_Set::verify($in,$phrase,$budget);}finally{fclose($in);}
  $check($set['archiveId']===$verified['archiveId']&&hash_file('sha256',$private.'/recovery.kiwebackup')===$set['sha256'],'complete original encrypted recovery authenticates independently');
  $check($observed['observedStable']===true&&$observed['database']['transactionEnded']===true,'original files remain stable across native database read view');
  $members=[];foreach(['recovery.kiwebackup','observation.kiwesource','previous-kiwe.kiwefiles','previous-kiwe-manifest.json','operator-key.sealed']as$n)$members[$n]=['bytes'=>filesize($private.'/'.$n),'sha256'=>hash_file('sha256',$private.'/'.$n)];
  $write($private.'/transfer-reference.private.json',json_encode(['directory'=>$private,'tokenSha256'=>hash('sha256',$token),'members'=>$members],JSON_THROW_ON_ERROR));
  $proof['capture']=['archiveId'=>$set['archiveId'],'members'=>$members,'files'=>count($plan['files']),'fileBytes'=>$plan['bytes'],'tables'=>$observed['database']['tables'],'rows'=>$observed['database']['rows'],'sourceLayoutSha256'=>hash('sha256',$sourceBytes),'previousKiweMembers'=>count($pair),'exclusions'=>$plan['exclusions'],'platform'=>$source['platform'],'activePlugins'=>$source['dependencies']['activePlugins'],'themes'=>$source['dependencies']['themes'],'bricks'=>$c['bricks'],'writerFreezeVerified'=>false,'databaseFileConsistencyVerified'=>false];
  $proof['operatorKeySealed']=file_get_contents($private.'/operator-key.sealed');$proof['passed']=true;
  // Current source code is never switched; encrypted intermediate members alone may retire after verification.
  foreach(isset($c['reuse'])?['database.kiwe']:['database.kiwe','files.kiwe']as$n){if(!unlink($private.'/'.$n))throw new RuntimeException('Owned encrypted intermediate retirement refused');}
  $proof['cleanup']=true;$proof['milliseconds']=(hrtime(true)-$started)/1000000;
 }catch(Throwable$e){$proof['passed']=false;$proof['phase']=$phase;$proof['errorClass']=get_class($e);$proof['error']=$e instanceof DSA\Backup\Archive_Error?$e->getMessage():'Original source capture did not complete';$proof['milliseconds']=(hrtime(true)-$started)/1000000;}
 finally{foreach($streams as$s)if(is_resource($s))fclose($s);if(is_resource($retained))fclose($retained);$phrase=null;$token=null;}
 return $proof;
};
