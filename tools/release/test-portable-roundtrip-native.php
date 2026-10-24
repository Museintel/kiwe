<?php
declare(strict_types=1);
/** Private native qualification factory. Never included by the product or a public restore route. */
if(PHP_SAPI!=='cli'||PHP_OS_FAMILY==='Windows')return null;
return static function(array$c,string$phase,array$prior=[]):array{
 $proof=['schema'=>'kiwe.native-whole-site-roundtrip.v1','phase'=>$phase,'passed'=>false,'cleanup'=>false,'installedCandidate'=>false,'nativeInstalledSiteChanged'=>false,'checks'=>[]];
 $check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $deny=static function(callable$fn,string$label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
 $db=new mysqli($c['connection']['host'],$c['connection']['username'],$c['connection']['password'],$c['connection']['database'],$c['connection']['port']);$db->set_charset('utf8mb4');$db->query('SET SESSION wait_timeout=1800');
 $query=static function($sql)use($db){$r=$db->query($sql);if(!$r instanceof mysqli_result)return[];$v=$r->fetch_all(MYSQLI_NUM);$r->free();return$v;};
 $q=static fn($v)=>"'".$db->real_escape_string((string)$v)."'";
 $catalog=static function(string$prefix)use($query,$q){return array_column($query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,'.strlen($prefix).')='.$q($prefix).' ORDER BY BINARY TABLE_NAME'),0);};
 $mkdir=static function(string$p){if(!mkdir($p,0700)||!chmod($p,0700))throw new RuntimeException('Private fixture directory refused');};
 $write=static function(string$p,string$bytes){$s=fopen($p,'x+b');if(!$s||!chmod($p,0600))throw new RuntimeException('Private fixture write refused');$at=0;while($at<strlen($bytes)){$n=fwrite($s,substr($bytes,$at));if(!$n)throw new RuntimeException('Private fixture write incomplete');$at+=$n;}fflush($s);fsync($s);fclose($s);};
 $copy=static function(string$from,string$to)use(&$copy,$mkdir,$write){$s=lstat($from);if(!$s||is_link($from))throw new RuntimeException('Linked fixture source refused');if(is_dir($from)){$mkdir($to);foreach(scandir($from)as$n)if($n!=='.'&&$n!=='..')$copy($from.'/'.$n,$to.'/'.$n);}elseif(is_file($from))$write($to,file_get_contents($from));else throw new RuntimeException('Unsupported fixture source entry');};
 $remove=static function(string$root)use(&$remove){if(!file_exists($root))return;if(!preg_match('#^/tmp/kiwe-roundtrip-qa-[a-f0-9]{24}(?:/|$)#D',$root)||is_link($root))throw new RuntimeException('Foreign fixture cleanup refused');$s=lstat($root);if(function_exists('posix_geteuid')&&$s['uid']!==posix_geteuid())throw new RuntimeException('Foreign fixture owner refused');if(is_dir($root)){foreach(scandir($root)as$n)if($n!=='.'&&$n!=='..')$remove($root.'/'.$n);if(!rmdir($root))throw new RuntimeException('Known fixture directory retirement refused');}elseif(!unlink($root))throw new RuntimeException('Known fixture file retirement refused');};
 $child=static function(array$packet)use($c,&$proof){$p=proc_open([PHP_BINARY,'-d','memory_limit=512M','-d','display_errors=0','-d','disable_functions=mail',$c['worker']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Native WordPress worker did not start');fwrite($pipes[0],json_encode($packet)."\n");fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);$v=json_decode($out,true);if($code!==0||$err!==''||!is_array($v)||!($v['passed']??false)){$proof['failedChild']=['action'=>$packet['action'],'exitCode'=>$code,'stderrEmpty'=>$err==='','parsed'=>is_array($v),'proof'=>$v];throw new RuntimeException('Native child refused: '.($v['error']??'private diagnostics retained').' ('.($v['unexpectedOutput']??false?'unexpected output':'no output').')');}return$v;};
 $config=static function(string$prefix,string$url)use($c){$values=['DB_HOST'=>$c['connection']['host'],'DB_NAME'=>$c['connection']['database'],'DB_USER'=>$c['connection']['username'],'DB_PASSWORD'=>$c['connection']['password'],'DB_CHARSET'=>'utf8mb4','DB_COLLATE'=>'','WP_DEBUG'=>false,'WP_DEBUG_LOG'=>false,'WP_DEBUG_DISPLAY'=>false,'WP_CACHE'=>false,'DISABLE_WP_CRON'=>true,'AUTOMATIC_UPDATER_DISABLED'=>true,'WP_ENVIRONMENT_TYPE'=>'local','WP_HOME'=>$url,'WP_SITEURL'=>$url];foreach(['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']as$k)$values[$k]=bin2hex(random_bytes(32));$body="<?php\n";foreach($values as$k=>$v)$body.='define('.var_export($k,true).','.var_export($v,true).");\n";return$body.'$table_prefix='.var_export($prefix,true).";\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH.'wp-settings.php';\n";};
 $inventory=DSA\Backup\Portable_File_Inventory::class;$stage=DSA\Backup\Portable_Recovery_Stage::class;$database=DSA\Backup\Portable_Recovery_Database::class;$activation=DSA\Backup\Portable_Recovery_Activation::class;
 $state=$prior;
 try{
  if($phase==='capture'||$phase==='acceptance'){
   $id=bin2hex(random_bytes(12));$root='/tmp/kiwe-roundtrip-qa-'.$id;$mkdir($root);$sourcePrefix='qrs_'.$id.'_';$destPrefix='qrd_'.$id.'_';
   $check($catalog($sourcePrefix)===[]&&$catalog($destPrefix)===[],'both independent native namespaces start absent');
   $state=['root'=>$root,'sourcePrefix'=>$sourcePrefix,'destinationPrefix'=>$destPrefix,'source'=>$root.'/source/public','public'=>$root.'/destination/public','sourceUrl'=>'https://source.qualification.invalid','destinationUrl'=>'https://destination.qualification.invalid','sourcePassword'=>bin2hex(random_bytes(24)),'destinationPassword'=>bin2hex(random_bytes(24)),'phrase'=>bin2hex(random_bytes(24)),'token'=>bin2hex(random_bytes(24)),'ownedNativeTables'=>[]];
   foreach(['source','destination','capture','stages','databases','activations']as$n)$mkdir($root.'/'.$n);
   foreach($phase==='acceptance'?['source']:['source','destination']as$side){$public=$root.'/'.$side.'/public';$mkdir($public);
    foreach(scandir($c['installedRoot'])as$n)if(is_file($c['installedRoot'].'/'.$n)&&($n==='index.php'||preg_match('/^wp-[a-z-]+\.php$/D',$n))&&!in_array($n,['wp-config.php','wp-config-sample.php'],true))$copy($c['installedRoot'].'/'.$n,$public.'/'.$n);
    foreach(['wp-admin','wp-includes']as$n)$copy($c['installedRoot'].'/'.$n,$public.'/'.$n);
    $mkdir($public.'/wp-content');foreach(['plugins','mu-plugins','themes','uploads']as$n)$mkdir($public.'/wp-content/'.$n);
    $copy($c['installedRoot'].'/wp-content/themes/bricks',$public.'/wp-content/themes/bricks');
    if($side==='source'){$zip=new ZipArchive();if($zip->open($c['package'])!==true||hash_file('sha256',$c['package'])!==$c['packageSha256'])throw new RuntimeException('Frozen package refused');for($i=0;$i<$zip->numFiles;++$i){$n=$zip->getNameIndex($i);if(!preg_match('#^(?:dsa\.php|kiwe-incident-guard\.php|dsa/[a-zA-Z0-9_./-]+)$#D',$n)||str_contains($n,'..'))throw new RuntimeException('Frozen package path refused');$target=$public.'/wp-content/mu-plugins/'.$n;$cursor=dirname($target);$missing=[];while(!is_dir($cursor)){$missing[]=$cursor;$cursor=dirname($cursor);}foreach(array_reverse($missing)as$p)$mkdir($p);$write($target,$zip->getFromIndex($i));}$zip->close();}
    $write($public.'/wp-config.php',$config($side==='source'?$sourcePrefix:$destPrefix,$side==='source'?$state['sourceUrl']:$state['destinationUrl']));
    $packet=['action'=>'seed','root'=>$public,'source'=>$side==='source','login'=>$side==='source'?'source_owner':'destination_owner','email'=>$side==='source'?'source-owner@qualification.invalid':'destination-owner@qualification.invalid','password'=>$side==='source'?$state['sourcePassword']:$state['destinationPassword']];$r=$child($packet);
    // wp_install starts before its new site options exist. Populate in the next native process,
    // so WP_CONTENT_URL and upload caches are derived from the installed source, not empty defaults.
    if($side==='source'){$proof['sourceInstallation']=$r;$packet['action']='populate';$r=$child($packet);}
    $proof[$side]=$r;
    if($side==='source')$state['entities']=$r['entities'];else$state['destinationOwner']=$r['owner'];
    $state['ownedNativeTables']=array_values(array_unique(array_merge($state['ownedNativeTables'],$catalog($side==='source'?$sourcePrefix:$destPrefix))));
   }
   if($phase==='acceptance'){
    // Reuse current native setup for a focused workspace rerun; no repeat of the accepted large offline encryption/cutover.
    foreach(['pk_settings_v3','stp_settings']as$key){$table=DSA\Backup\Portable_Table_Definition::identifier($sourcePrefix.'options');$rows=$query('SELECT option_value FROM '.$table.' WHERE option_name='.$q($key));if(count($rows)!==1)throw new RuntimeException('Focused native settings unavailable');$query('UPDATE '.$table.' SET option_value='.$q(DSA\Backup\Portable_Recovery_Ownership::configuration($key,$rows[0][0])).' WHERE option_name='.$q($key));}
    $proof['focusedAcceptance']=$child(['action'=>'acceptance','root'=>$state['source'],'owner'=>$state['entities']['owner'],'fixture'=>'test-wordpress-react-apps.php','expectedVersion'=>$c['sourceVersion']]);
    $state['ownedNativeTables']=array_values(array_unique(array_merge($state['ownedNativeTables'],$catalog($sourcePrefix))));$check($proof['focusedAcceptance']['passed']===true,'focused native non-Woo workspace fixture passes');$proof['passed']=true;
   }else{
   $proof['capture']=$child(['action'=>'capture','root'=>$state['source'],'directory'=>$root.'/capture','phrase'=>$state['phrase']]);
   $state['capture']=$proof['capture']['capture'];$state['ownedNativeTables']=array_values(array_unique(array_merge($state['ownedNativeTables'],$catalog($sourcePrefix))));
   $check($state['capture']['files']>2000&&$state['capture']['fileBytes']>16777216,'full native core theme Kiwe and larger media are saved rather than tiny stubs');
   $check(openssl_public_encrypt(json_encode(['phrase'=>$state['phrase'],'token'=>$state['token']]),$sealed,$c['publicKey'],OPENSSL_PKCS1_OAEP_PADDING),'fresh recovery credentials seal to the offline machine');
   $proof['offline']=['url'=>$c['downloadUrl'],'encryptedCredentials'=>base64_encode($sealed),'members'=>['recovery.kiwebackup'=>['bytes'=>$state['capture']['bytes'],'sha256'=>$state['capture']['sha256']],'observation.kiwesource'=>['bytes'=>$state['capture']['observationBytes'],'sha256'=>$state['capture']['observationSha256']]]];
   $proof['passed']=true;$proof['retainedPrivateFixture']=true;
   }
  }elseif($phase==='restore'){
   $root=$state['root'];$public=$state['public'];$phrase=$state['phrase'];$from=$c['uploadedRoot'].'/recovery.kiwebackup';$observation=file_get_contents($c['uploadedRoot'].'/observation.kiwesource');
   $check(hash_file('sha256',$from)===$state['capture']['sha256']&&hash('sha256',$observation)===$state['capture']['observationSha256'],'re-uploaded offline members exactly match the saved source pair');
   $before=$inventory::scan($public,50000,8589934592,null,'posix',true);$nativeBefore=[];foreach($catalog($state['destinationPrefix'])as$name){$t=DSA\Backup\Portable_Table_Definition::identifier($name);$nativeBefore[$name]=hash('sha256',serialize([$query('SHOW CREATE TABLE '.$t),$query('SELECT * FROM '.$t)]));}
   $attempts=static fn()=>glob($root.'/stages/kiwe-recovery-*');
   $deny(static function()use($stage,$from,$observation,$root,$public){$s=fopen($from,'rb');try{$stage::prepare($s,$observation,'Wrong independent qualification phrase',$root.'/stages',$public);}finally{fclose($s);}},'wrong offline key refuses before destination replacement');
   $check($attempts()===[]&&$inventory::scan($public,50000,8589934592,null,'posix',true)===$before,'wrong key retains the prepared destination');
   $bad=$root.'/capture/corrupted.kiwebackup';$copy($from,$bad);$s=fopen($bad,'r+b');fseek($s,-1,SEEK_END);$last=fread($s,1);fseek($s,-1,SEEK_END);fwrite($s,chr(ord($last)^1));fclose($s);
   $deny(static function()use($stage,$bad,$observation,$phrase,$root,$public){$s=fopen($bad,'rb');try{$stage::prepare($s,$observation,$phrase,$root.'/stages',$public);}finally{fclose($s);}},'corrupted uploaded archive refuses without destination replacement');unlink($bad);
   $check($attempts()===[],'caught corrupt import removes only its acknowledged private attempt');
   $capacity=$root.'/capture/oversized.kiwebackup';$s=fopen($capacity,'x+b');ftruncate($s,25769803776);fseek($s,0);$deny(static fn()=>$stage::prepare($s,$observation,$phrase,$root.'/stages',$public),'declared oversized archive refuses before expanded allocation');fclose($s);unlink($capacity);
   $calls=0;$s=fopen($from,'rb');$deny(static fn()=>$stage::prepare($s,$observation,$phrase,$root.'/stages',$public,static function()use(&$calls){if(++$calls===150)throw new DSA\Backup\Archive_Error('Synthetic caught import interruption');}),'caught interrupted import retains the prepared destination');fclose($s);$check($attempts()===[],'caught interrupted stage leaves no acknowledged attempt');
   $s=fopen($from,'rb');$prepared=$stage::prepare($s,$observation,$phrase,$root.'/stages',$public);fclose($s);$state['stage']=$prepared['directory'];$review=$stage::inspect($state['stage'],$public,$phrase);$source=$review['source'];
   $migration=['schema'=>DSA\Backup\Portable_Recovery_Migration::SCHEMA,'recoverySha256'=>$state['capture']['sha256'],'source'=>['home'=>$state['sourceUrl'],'siteurl'=>$state['sourceUrl'],'root'=>$state['source']],'destination'=>['home'=>$state['destinationUrl'],'siteurl'=>$state['destinationUrl'],'root'=>$public],'versions'=>['wordpress'=>$source['platform']['wordpress'],'kiwe'=>$source['platform']['kiwe']],'dependencies'=>['activePlugins'=>$source['dependencies']['activePlugins'],'mustUseEntries'=>$source['dependencies']['mustUseEntries'],'themes'=>$source['dependencies']['themes']],'tableSuffixes'=>array_map(static fn($t)=>substr($t['name'],strlen($state['sourcePrefix'])),$review['database']['tables']),'consistencyAcceptance'=>'observed-stable-not-atomic'];
   $ownership=['schema'=>DSA\Backup\Portable_Recovery_Ownership::SCHEMA,'recoverySha256'=>$state['capture']['sha256'],'destinationOwnerId'=>$state['destinationOwner'],'sourceOwnerId'=>null,'mode'=>'quarantine-copied-authority'];
   $r=$database::prepare($c['connection'],$state['stage'],$public,$phrase,$root.'/databases',null,$migration,$ownership,$state['destinationPassword']);$state['database']=$r['directory'];$owner=$r['ownerId'];
   $policy=$activation::reviewed_plan($c['connection'],$state['database'],$state['stage'],$public,$phrase);$policy['codeReview']='exact-captured-code-approved';$policy['destinationIsolation']='requests-writers-host-cron-paused';$policy['withheldStateReview']='original-private-review-complete';
   $r=$activation::activate($c['connection'],$state['database'],$state['stage'],$public,$phrase,$root.'/activations',$policy);$state['activation']=$r['directory'];
   $tokens=token_get_all(file_get_contents($public.'/wp-config.php'));$target=null;foreach($tokens as$i=>$t)if(is_array($t)&&$t[0]===T_VARIABLE&&$t[1]==='$table_prefix'){for($j=$i+1;$j<min($i+8,count($tokens));++$j)if(is_array($tokens[$j])&&$tokens[$j][0]===T_CONSTANT_ENCAPSED_STRING){$target=substr($tokens[$j][1],1,-1);break;}break;}
   if(!is_string($target)||!preg_match('/^krd_[a-f0-9]{24}_$/D',$target))throw new RuntimeException('Activated private prefix did not authenticate');
   $state['ownedNativeTables']=array_values(array_unique(array_merge($state['ownedNativeTables'],$catalog($target))));
   $check($r['activated']===true&&$r['fullSiteReady']===false,'complete offline source activates only the reviewed isolated destination');
   $activation::reverse($c['connection'],$state['activation'],$public,$phrase);$activation::retire($state['activation'],$public,$phrase);unset($state['activation']);
   $check($inventory::scan($public,50000,8589934592,null,'posix',true)===$before,'full core theme and content reversal restores exact prepared files');
   foreach($nativeBefore as$name=>$sha){$t=DSA\Backup\Portable_Table_Definition::identifier($name);$check(hash('sha256',serialize([$query('SHOW CREATE TABLE '.$t),$query('SELECT * FROM '.$t)]))===$sha,'prepared native table retains exact rows and definition: '.substr($name,strlen($state['destinationPrefix'])));}
   $policy=$activation::reviewed_plan($c['connection'],$state['database'],$state['stage'],$public,$phrase);$policy['codeReview']='exact-captured-code-approved';$policy['destinationIsolation']='requests-writers-host-cron-paused';$policy['withheldStateReview']='original-private-review-complete';
   $r=$activation::activate($c['connection'],$state['database'],$state['stage'],$public,$phrase,$root.'/activations',$policy);$state['activation']=$r['directory'];
   $proof['nativeBootstrap']=$child(['action'=>'review','root'=>$public,'owner'=>$owner,'password'=>$state['destinationPassword'],'destinationUrl'=>$state['destinationUrl'],'entities'=>$state['entities'],'expectedVersion'=>$c['sourceVersion']]);
   $state['ownedNativeTables']=array_values(array_unique(array_merge($state['ownedNativeTables'],$catalog($target))));
   $check($proof['nativeBootstrap']['passed']===true,'independently restored non-Woo WordPress Kiwe and Bricks actually boot and render');
   if(!empty($c['combinedAcceptance'])){
    $nativeConfig=file_get_contents($public.'/wp-config.php');$proof['combinedAcceptance']=[];
    foreach(['test-wordpress-workspaces.php','test-wordpress-nonwoo-editorial.php','test-wordpress-react-apps.php','test-wordpress-media-archives.php','test-wordpress-bricks-design-snapshots.php','test-wordpress-service-history.php','test-wordpress-kiwe-configuration-snapshots.php','test-wordpress-account-profile-snapshots.php']as$fixture){
     $qa='krd_'.bin2hex(random_bytes(12)).'_';$check($catalog($qa)===[],'fresh native acceptance namespace: '.$fixture);$state['acceptancePrefixes'][]=$qa;
     try{
      foreach($catalog($target)as$name){$new=$qa.substr($name,strlen($target));$t=DSA\Backup\Portable_Table_Definition::identifier($new);$old=DSA\Backup\Portable_Table_Definition::identifier($name);$query('CREATE TABLE '.$t.' LIKE '.$old);$state['ownedNativeTables'][]=$new;$query('INSERT INTO '.$t.' SELECT * FROM '.$old);}
      $query('UPDATE '.DSA\Backup\Portable_Table_Definition::identifier($qa.'options').' SET option_name='.$q($qa.'user_roles').' WHERE option_name='.$q($target.'user_roles'));
      foreach(['capabilities','user_level']as$key)$query('UPDATE '.DSA\Backup\Portable_Table_Definition::identifier($qa.'usermeta').' SET meta_key='.$q($qa.$key).' WHERE meta_key='.$q($target.$key));
      if(file_put_contents($public.'/wp-config.php',$config($qa,$state['destinationUrl']))===false)throw new RuntimeException('Owned acceptance configuration failed');
      try{$proof['combinedAcceptance'][$fixture]=$child(['action'=>'acceptance','root'=>$public,'owner'=>$owner,'fixture'=>$fixture,'expectedVersion'=>$c['sourceVersion']]);}catch(Throwable$e){$proof['combinedAcceptance'][$fixture]=$proof['failedChild']??['passed'=>false,'error'=>'Native acceptance child refused'];}
     }finally{if(file_put_contents($public.'/wp-config.php',$nativeConfig)===false)throw new RuntimeException('Restored configuration readback failed');$state['ownedNativeTables']=array_values(array_unique(array_merge($state['ownedNativeTables'],$catalog($qa))));}
    }
    $check(!array_filter($proof['combinedAcceptance'],static fn($r)=>($r['passed']??false)!==true),'all combined native non-Woo fixtures pass on the current recovered candidate');
   }
   $proof['postBootstrapReversal']='not attempted: legitimate native initialization is newer state; no automatic merge or erase';
   $proof['capture']=$state['capture'];$proof['passed']=true;
  }elseif($phase==='retire'){$proof['passed']=true;}else throw new RuntimeException('Unknown private qualification phase');
 }catch(Throwable$e){$proof['error']=$e->getMessage();}
 finally{
  if($phase==='retire'||$phase==='restore'||$phase==='acceptance'||!$proof['passed']){
   // This factory owns fresh, unserved namespaces and their exact registered names, not real accounts/content.
   try{foreach(['sourcePrefix','destinationPrefix']as$k)if(isset($state[$k]))$state['ownedNativeTables']=array_values(array_unique(array_merge($state['ownedNativeTables'],$catalog($state[$k]))));
    foreach($state['ownedNativeTables']??[]as$name){if(!preg_match('/^(?:qrs_[a-f0-9]{24}_|qrd_[a-f0-9]{24}_|krd_[a-f0-9]{24}_)[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('Foreign table retirement refused');$query('DROP TABLE '.DSA\Backup\Portable_Table_Definition::identifier($name));}if(isset($state['root']))$remove($state['root']);$proof['cleanup']=!isset($state['root'])||!is_dir($state['root']);}catch(Throwable$e){$proof['cleanupFailure']=true;$proof['passed']=false;}
  }
  $db->close();
 }
 return['proof'=>$proof,'state'=>$state];
};
