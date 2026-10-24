<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);

/** Shared adversarial transport proof; callers supply only an exact owned synthetic job. */
return static function(\DSA\Backup\Portable_Capture_Job_Service $service,string $id,string $phrase):array{
 $proof=['schema'=>'kiwe.portable-download-boundaries.v1','passed'=>false,'cleanup'=>false,'checks'=>[],'limitations'=>[]];
 $check=static function(bool $ok,string $label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $deny=static function(callable $fn,string $label)use($check){try{$fn();}catch(\DSA\Backup\Archive_Error $e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
 $dir=\DSA\Backup\Archive_Job_Files::directory('portable',$id);$path=$dir.'/recovery.kiwe';$op=$dir.'/observation.kiwe';$info=json_decode(file_get_contents($dir.'/info.json'),true);$actor=get_current_user_id();$held=null;$out=null;$original=null;$observation=null;$linked=false;
 $save=static fn(array $v)=>\DSA\Backup\Archive_Job_Files::write($dir,'info.json',json_encode($v));
 $start=static function()use(&$out){if(is_resource($out))fclose($out);$out=fopen('php://temp/maxmemory:262144','w+b');if(!$out)throw new RuntimeException('Owned output stream failed');};
 $write=static function(string $chunk)use(&$out){return fwrite($out,$chunk)===strlen($chunk);};
 try{
  $check($info['status']==='captured'&&$info['bytes']>65536&&$info['bytes']<4194304,'fixture supplies only a small owned independently verified encrypted capture');
  $original=file_get_contents($path);$observation=file_get_contents($op);$start();$meta=null;$max=0;
  $done=$service->stream_review_capture($id,'recovery',static function($m)use(&$meta,$service,$id,$deny,$check){
   $meta=$m;$state=$service->status($id);$check($state['status']==='captured'&&$state['busy']&&!$state['fullSiteReady'],'busy polling reports a pinned captured job without changing its readiness');
   $deny(static fn()=>$service->remove($id),'removal cannot retire a file held by an active download');
   $deny(static fn()=>$service->stream_review_capture($id,'recovery',static fn()=>null,static fn()=>null),'competing download cannot replace the active file owner');
  },static function($chunk)use($write,&$max){$max=max($max,strlen($chunk));return$write($chunk);});
  rewind($out);$downloaded=stream_get_contents($out);
  $check($done['complete']&&strlen($downloaded)===$info['bytes']&&hash('sha256',$downloaded)===$info['archiveSha256'],'successful one-pass download matches the entire verified capture length and checksum');
  $check($max<=65536&&$max>0,'transport never emits more than sixty-four KiB in one callback');
  $check($meta['scope']==='capture-review'&&$meta['fullSiteReady']===false&&$meta['sha256']===$info['archiveSha256']&&str_ends_with($meta['filename'],'-recovery.kiwe'),'download metadata states review scope and never claims complete recovery acceptance');
  $check(!str_contains(json_encode($meta),$dir)&&!str_contains(json_encode($meta),$phrase)&&!str_contains($downloaded,$phrase),'metadata and encrypted download reveal neither private path nor recovery phrase');
  rewind($out);$review=\DSA\Backup\Portable_Recovery_Set::verify($out,$phrase);
  $check(count($review['database']['tables'])===12&&!$review['databaseFileConsistencyVerified']&&!$review['destinationCompatibilityVerified'],'download independently authenticates source database and files without asserting consistency or restoration');
  $check(!$service->status($id)['busy']&&file_get_contents($path)===$original,'successful stream releases its lock and leaves the source artifact intact');
  $start();$done=$service->stream_review_capture($id,'observation',static fn()=>null,$write);rewind($out);$downloaded=stream_get_contents($out);
  $opened=\DSA\Backup\Portable_Source_Observation::open($downloaded,$phrase);
  $check($done['complete']&&$downloaded===$observation&&$done['sha256']===$info['observationSha256']&&$opened['recoverySha256']===$info['archiveSha256'],'offline observation remains encrypted and bound to the same authenticated capture');
  foreach(['../wp-config','handoff','database','']as$member)$deny(static fn()=>$service->stream_review_capture($id,$member,static fn()=>null,static fn()=>null),'unavailable component is refused '.$member);
  $headers=0;$sent=0;$begin=static function()use(&$headers){++$headers;};$emit=static function($b)use(&$sent){$sent+=strlen($b);};
  $deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit,static function(){throw new \DSA\Backup\Archive_Error('Owned pre-header cancellation');}),'cancellation before transport start is refused');
  $check($headers===0&&$sent===0&&!$service->status($id)['busy'],'pre-header cancellation emits no bytes and releases the job owner');
  $deny(static fn()=>$service->stream_review_capture($id,'recovery',static fn()=>false,$emit),'transport adapter can refuse before the first encrypted byte');
  $check($sent===0&&!$service->status($id)['busy'],'refused transport start leaves a reusable verified capture');
  $sent=0;$deny(static function()use($service,$id,$begin,&$sent){return $service->stream_review_capture($id,'recovery',$begin,static function($b)use(&$sent){$sent+=strlen($b);return false;});},'failed output adapter does not report a completed capture');
  $check($sent>0&&$sent<$info['bytes']&&!$service->status($id)['busy'],'failed output releases its lock without emitting completion bytes');
  $sent=0;$cancelled=false;$deny(static function()use($service,$id,$begin,&$sent,&$cancelled){return $service->stream_review_capture($id,'recovery',$begin,static function($b)use(&$sent,&$cancelled){$sent+=strlen($b);$cancelled=true;},static function()use(&$cancelled){if($cancelled)throw new \DSA\Backup\Archive_Error('Owned mid-stream cancellation');});},'interrupted client transfer cannot authenticate as a completed file');
  $check($sent>0&&$sent<$info['bytes']&&file_get_contents($path)===$original,'interrupted client transfer retains the original encrypted artifact for a fresh download');
  $sent=0;$deny(static function()use($service,$id,$begin,&$sent){return $service->stream_review_capture($id,'recovery',$begin,static function($b)use(&$sent){$sent+=strlen($b);wp_set_current_user(0);});}, 'account context lost mid-stream cannot receive a completed capture');wp_set_current_user($actor);
  $check($sent>0&&$sent<$info['bytes']&&!$service->status($id)['busy'],'changed account context withholds the final authenticated completion bytes');
  foreach(['archiveSha256','observationSha256']as$key){$bad=$info;$bad[$key]=str_repeat('0',64);$save($bad);$member=$key==='archiveSha256'?'recovery':'observation';$sent=0;$deny(static fn()=>$service->stream_review_capture($id,$member,$begin,$emit),'changed stored completion checksum is refused '.$member);$bytes=$member==='recovery'?$info['bytes']:strlen($observation);$check($sent===max(0,$bytes-65536),'checksum refusal withholds the entire bounded tail '.$member);$save($info);}
  foreach([-1,1]as$delta){$bad=$info;$bad['bytes']+=$delta;$save($bad);$headers=0;$sent=0;$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'changed expected capture size is refused '.$delta);$check($headers===0&&$sent===0,'size mismatch is detected before transport headers '.$delta);$save($info);}
  $sent=0;$deny(static fn()=>$service->stream_review_capture($id,'recovery',static function()use($path,$original){$bad=$original;$bad[100]=chr(ord($bad[100])^1);file_put_contents($path,$bad);},$emit),'in-place encrypted byte corruption cannot receive completion bytes');
  $check($sent===$info['bytes']-65536,'single-pass byte corruption withholds the complete tail');file_put_contents($path,$original);chmod($path,0600);
  $sent=0;$deny(static fn()=>$service->stream_review_capture($id,'recovery',static fn()=>file_put_contents($path,substr($original,0,70000)),$emit),'pinned file truncation during transport is refused');$check($sent<$info['bytes'],'truncated capture does not emit a complete file');file_put_contents($path,$original);chmod($path,0600);
  $sent=0;$deny(static fn()=>$service->stream_review_capture($id,'recovery',static fn()=>file_put_contents($path,'tail',FILE_APPEND),$emit),'pinned file growth during transport is refused');$check($sent===$info['bytes']-65536,'unexpected appended data cannot release the completion tail');file_put_contents($path,$original);chmod($path,0600);
  $sent=0;$deny(static fn()=>$service->stream_review_capture($id,'recovery',static function()use($save,$info){$bad=$info;++$bad['attempt'];$save($bad);},$emit),'changed journal during a pinned download is refused');$check($sent===$info['bytes']-65536,'journal change withholds completion even when byte checksum still matches');$save($info);
  $bad=$info;$bad['createdAt']=time()-604900;$bad['expiresAt']=$bad['createdAt']+604800;$save($bad);$headers=0;$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'expired private capture cannot be downloaded');$check($headers===0,'expired capture emits no transport headers');$save($info);
  $bad=$info;$bad['status']='pending';$bad['bytes']=0;$bad['archiveSha256']='';$bad['observationSha256']='';$save($bad);$headers=0;$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'unfinished capture cannot be streamed');$check($headers===0,'unfinished capture emits no transport headers');$save($info);
  $bad=$info;$bad['status']='running';$bad['bytes']=0;$bad['archiveSha256']='';$bad['observationSha256']='';$save($bad);$held=fopen($dir.'/lock','c+b');flock($held,LOCK_EX);$startTime=hrtime(true);$state=$service->status($id);
  $check($state['busy']&&$state['status']==='running'&&!$state['restartRequired']&&(hrtime(true)-$startTime)/1e9<0.25,'live worker polling is nonblocking and never marks its journal interrupted');
  $check(json_decode(file_get_contents($dir.'/info.json'),true)===$bad,'busy poll leaves the live worker journal exactly unchanged');flock($held,LOCK_UN);fclose($held);$held=null;
  $check($service->status($id)['status']==='interrupted','a genuinely abandoned worker is classified only after exclusive ownership is acquired');$save($info);
  if(PHP_OS_FAMILY!=='Windows'){
   $alias=$dir.'/review-source.kiwe';
   if(function_exists('link')){$check(link($path,$alias),'owned native hard-link fixture can be created');$linked=true;$headers=0;$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'multiply linked private file is refused');$check($headers===0,'hard-link refusal precedes transport headers');unlink($alias);$linked=false;}else $proof['limitations'][]='Host PHP disables link(); native multiply linked artifact refusal needs another compatible host or VPS proof.';
   if(function_exists('symlink')){rename($path,$alias);$linked=true;$check(symlink($alias,$path),'owned native symbolic-link fixture can be created');$headers=0;$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'symbolic-link private capture is refused');$check($headers===0,'symbolic-link refusal precedes transport headers');unlink($path);rename($alias,$path);$linked=false;}else $proof['limitations'][]='Host PHP disables symlink(); native linked-path refusal needs another compatible host or VPS proof.';
   chmod($path,0644);$headers=0;$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'publicly readable native private artifact is refused');$check($headers===0,'private permission refusal precedes transport headers');chmod($path,0600);
  }
  $start();$done=$service->stream_review_capture($id,'recovery',static fn()=>null,$write);rewind($out);$check($done['complete']&&hash('sha256',stream_get_contents($out))===$info['archiveSha256'],'fresh complete download succeeds after every refused or interrupted transfer');
  $deny(static fn()=>$service->download_path($id),'review transport does not open the full-site download gate');$proof['passed']=true;
 }catch(\Throwable $e){$proof['error']=$e->getMessage();}
 finally{
  if(is_resource($held)){flock($held,LOCK_UN);fclose($held);}if(is_resource($out))fclose($out);wp_set_current_user($actor);
  if($linked){$alias=$dir.'/review-source.kiwe';if(is_link($path))unlink($path);if(is_file($alias)&&!is_link($alias)){if(!file_exists($path))rename($alias,$path);else unlink($alias);}}
  if(is_string($original)){file_put_contents($path,$original);chmod($path,0600);}if(is_string($observation)){file_put_contents($op,$observation);chmod($op,0600);}$save($info);
  $proof['cleanup']=is_string($original)&&file_get_contents($path)===$original&&file_get_contents($op)===$observation&&json_decode(file_get_contents($dir.'/info.json'),true)===$info&&!file_exists($dir.'/review-source.kiwe')&&!$service->status($id)['busy'];
  $proof['checks']['owned fixture journal and encrypted artifacts restored and download lock released']=$proof['cleanup'];$proof['passed']=$proof['passed']&&$proof['cleanup'];
 }
 return$proof;
};
