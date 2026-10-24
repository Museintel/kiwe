<?php
declare(strict_types=1);
/** Complete a reviewed member phase with the same Backup owners, without booting source WordPress. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
return static function(array $c):array{
 $p=['schema'=>'kiwe.private-original-source-capture.v1','passed'=>false,'cleanup'=>false,'sourceRuntimeReplaced'=>false,'wordpressBooted'=>false,'checks'=>[]];$streams=[];$phrase=null;$phase='accepted-members';$started=hrtime(true);
 $check=static function(bool$ok,string$label)use(&$p){$p['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $lastProgress=-10;
 $budget=static function()use($c,$started,&$lastProgress,&$phase,&$streams){
  $seconds=(hrtime(true)-$started)/1000000000;
  if($seconds-$lastProgress>=10){
   $members=[];foreach($streams as$name=>$stream)if(is_resource($stream))$members[$name]=['cursor'=>ftell($stream),'bytes'=>fstat($stream)['size']];
   $json=json_encode(['schema'=>'kiwe.private-source-progress.v1','phase'=>$phase,'seconds'=>$seconds,'members'=>$members,'memoryBytes'=>memory_get_usage(true),'peakMemoryBytes'=>memory_get_peak_usage(true)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
   $h=fopen($c['private'].'/progress.private.json','c+b');if(!$h||!chmod($c['private'].'/progress.private.json',0600)||!ftruncate($h,0)||fwrite($h,$json)!==strlen($json)||!fflush($h)||!fsync($h))throw new RuntimeException('Private finish progress storage refused');fclose($h);$lastProgress=$seconds;
  }
  if($seconds>$c['captureSeconds'])throw new DSA\Backup\Archive_Error('Original-source finish reached its bounded operation time');
 };
 $ordinary=static function(string$f){$s=lstat($f);if(!$s||is_link($f)||($s['mode']&0170000)!==0100000||$s['nlink']!==1||($s['mode']&0777)!==0600)throw new RuntimeException('Ordinary private source member required');return$s;};
 $write=static function(string$f,string$b)use($ordinary){$s=fopen($f,'x+b');if(!$s||!chmod($f,0600))throw new RuntimeException('Fresh private finish member required');$at=0;while($at<strlen($b)){$n=fwrite($s,substr($b,$at,65536));if(!$n)throw new RuntimeException('Private finish write incomplete');$at+=$n;}fflush($s);fsync($s);fclose($s);$ordinary($f);};
 try{
  $private=$c['private'];$from=$c['members'];$check(realpath($from)===$from&&!is_link($from)&&dirname($from)===dirname($private),'exact accepted member phase stays in source vault');
  $file=$from.'/observed-members.private.json';$ordinary($file);$check(hash_file('sha256',$file)===$c['stateSha256'],'native accepted member state fingerprint exact');$state=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
  $check(($state['schema']??null)==='kiwe.private-original-observed-members.v1'&&$state['root']===$c['root']&&$state['observation']['observedStable']===true&&$state['observation']['database']['transactionEnded']===true,'ended original native observation is preserved');
  $ordinary($from.'/recovery-phrase.private');$phrase=file_get_contents($from.'/recovery-phrase.private');$check(preg_match('/^[a-f0-9]{64}$/D',$phrase)===1,'original private phrase format exact');
  foreach(['recovery-phrase.private','transfer-token.private','operator-key.sealed','previous-kiwe.kiwefiles','previous-kiwe-manifest.json']as$n){$ordinary($from.'/'.$n);$write($private.'/'.$n,file_get_contents($from.'/'.$n));$check(hash_file('sha256',$private.'/'.$n)===hash_file('sha256',$from.'/'.$n),'exact retained recovery material: '.$n);}
  foreach(['database','files']as$kind){$m=$state[$kind];$path=$m['path'];$check(is_string($path)&&dirname(dirname($path))===dirname($private)&&preg_match('#/kiwe-[a-z0-9-]+/(?:database|files)\.kiwe$#D',$path)===1,'source member remains in exact private vault: '.$kind);$s=$ordinary($path);$check($s['size']===$m['bytes']&&hash_file('sha256',$path)===$m['sha256'],'accepted ciphertext fingerprint exact: '.$kind);$streams[$kind]=fopen($path,'rb');}
  $streams['output']=fopen($private.'/recovery.kiwebackup','x+b');chmod($private.'/recovery.kiwebackup',0600);$phase='recovery-envelope';
  $set=DSA\Backup\Portable_Recovery_Set::create($streams['database'],$streams['files'],$state['source'],$streams['output'],$phrase,$budget);fflush($streams['output']);fsync($streams['output']);foreach($streams as$s)fclose($s);$streams=[];
  $observed=$state['observation'];$observed['recoveryArchiveId']=$set['archiveId'];$observed['recoverySha256']=$set['sha256'];$write($private.'/observation.kiwesource',DSA\Backup\Portable_Source_Observation::seal($observed,$phrase));
  $phase='independent-verification';$in=fopen($private.'/recovery.kiwebackup','rb');try{$verified=DSA\Backup\Portable_Recovery_Set::verify($in,$phrase,$budget);}finally{fclose($in);}
  $check($verified['archiveId']===$set['archiveId']&&hash_file('sha256',$private.'/recovery.kiwebackup')===$set['sha256'],'complete original encrypted source independently authenticates');
  $members=[];foreach(['recovery.kiwebackup','observation.kiwesource','previous-kiwe.kiwefiles','previous-kiwe-manifest.json','operator-key.sealed']as$n)$members[$n]=['bytes'=>filesize($private.'/'.$n),'sha256'=>hash_file('sha256',$private.'/'.$n)];
  $token=file_get_contents($private.'/transfer-token.private');$write($private.'/transfer-reference.private.json',json_encode(['directory'=>$private,'tokenSha256'=>hash('sha256',$token),'members'=>$members],JSON_THROW_ON_ERROR));$token=null;
  $p['capture']=['archiveId'=>$set['archiveId'],'members'=>$members,'files'=>$observed['fileCount'],'fileBytes'=>$observed['fileBytes'],'tables'=>$observed['database']['tables'],'rows'=>$observed['database']['rows'],'previousKiweMembers'=>$state['previousKiweMembers'],'bricks'=>$state['bricks'],'exclusions'=>$state['source']['exclusions'],'platform'=>$state['source']['platform'],'writerFreezeVerified'=>false,'databaseFileConsistencyVerified'=>false];
  $p['operatorKeySealed']=file_get_contents($private.'/operator-key.sealed');$p['acceptedMemberStateSha256']=$c['stateSha256'];$p['passed']=true;$p['cleanup']=true;$p['milliseconds']=(hrtime(true)-$started)/1000000;
 }catch(Throwable$e){$p['passed']=false;$p['phase']=$phase;$p['errorClass']=get_class($e);$p['error']=$e instanceof DSA\Backup\Archive_Error?$e->getMessage():'Original source finish did not complete';}
 finally{foreach($streams as$s)if(is_resource($s))fclose($s);$phrase=null;}
 return$p;
};
