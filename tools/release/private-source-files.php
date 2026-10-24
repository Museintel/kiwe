<?php
declare(strict_types=1);
/** Operator-only fresh encrypted file member; native database observation remains a separate gate. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
return static function(array$c):array{
 $p=['schema'=>'kiwe.private-original-file-capture.v1','passed'=>false,'cleanup'=>false,'wordpressBooted'=>false,'sourceRuntimeReplaced'=>false,'fullRecoveryCreated'=>false,'checks'=>[]];$s=null;$phrase=null;$started=hrtime(true);$last=-10;
 $check=static function(bool$ok,string$label)use(&$p){$p['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $write=static function(string$f,string$b){$h=fopen($f,'x+b');if(!$h||!chmod($f,0600))throw new RuntimeException('Private file capture storage refused');$at=0;while($at<strlen($b)){$n=fwrite($h,substr($b,$at,65536));if(!$n)throw new RuntimeException('Private file capture write incomplete');$at+=$n;}if(!fflush($h)||!fsync($h))throw new RuntimeException('Private file capture synchronization refused');fclose($h);};
 $budget=static function()use($c,$started,&$last,&$s){$elapsed=(hrtime(true)-$started)/1000000000;if($elapsed-$last>=10){$b=json_encode(['schema'=>'kiwe.private-source-progress.v1','phase'=>'fresh-files','seconds'=>$elapsed,'cursor'=>is_resource($s)?ftell($s):null,'memoryBytes'=>memory_get_usage(true),'peakMemoryBytes'=>memory_get_peak_usage(true)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$h=fopen($c['private'].'/progress.private.json','c+b');if(!$h||!chmod($c['private'].'/progress.private.json',0600)||!ftruncate($h,0)||fwrite($h,$b)!==strlen($b)||!fflush($h)||!fsync($h))throw new RuntimeException('Private file progress refused');fclose($h);$last=$elapsed;}if($elapsed>$c['captureSeconds'])throw new DSA\Backup\Archive_Error('Fresh original file capture reached its operation budget');};
 try{
  $preflight=json_decode(file_get_contents($c['preflight'].'/preflight.json'),true,512,JSON_THROW_ON_ERROR);$before=json_decode(file_get_contents($c['preflight'].'/original-native-fingerprints.json'),true,512,JSON_THROW_ON_ERROR);
  $check($preflight['passed']===true&&$preflight['cleanup']===true&&$preflight['wordpressBooted']===false,'accepted no-WordPress current source preflight');
  $check($before['configurationSha256']===hash_file('sha256',$c['root'].'/wp-config.php'),'original connection and salts remain unchanged');
  $file=$c['preflight'].'/original-file-inventory.json';$stat=lstat($file);$check($stat&&!is_link($file)&&($stat['mode']&0170000)===0100000&&$stat['nlink']===1&&($stat['mode']&0777)===0600,'reviewed original inventory remains ordinary and private');
  $plan=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);DSA\Backup\Portable_File_Inventory::validate_plan($plan,$budget);
  $check(count($plan['files'])===$preflight['source']['files']&&$plan['bytes']===$preflight['source']['fileBytes'],'reviewed inventory and current source audit agree');
  $phrase=bin2hex(random_bytes(32));$token=bin2hex(random_bytes(32));$sealed=null;
  $check(openssl_public_encrypt(json_encode(['phrase'=>$phrase,'token'=>$token],JSON_THROW_ON_ERROR),$sealed,$c['publicKey'],OPENSSL_PKCS1_OAEP_PADDING),'operator key capsule sealed to independent custody');
  $write($c['private'].'/recovery-phrase.private',$phrase);$write($c['private'].'/transfer-token.private',$token);$write($c['private'].'/operator-key.sealed',base64_encode($sealed));unset($sealed,$token);
  $s=fopen($c['private'].'/files.kiwe','x+b');if(!$s||!chmod($c['private'].'/files.kiwe',0600))throw new RuntimeException('Fresh ordinary private file member required');
  $r=DSA\Backup\Portable_Archive_Container::capture($c['root'],$plan,$s,new DSA\Backup\Portable_Archive_Cipher(DSA\Backup\Portable_Archive_Cipher::new_header(),$phrase),$budget);
  if(!fflush($s)||!fsync($s))throw new RuntimeException('Fresh encrypted file member synchronization refused');fclose($s);$s=null;
  $check(filesize($c['private'].'/files.kiwe')===$r['bytes'],'complete encrypted file extent matches shared owner receipt');
  $p['member']=$r;$p['fileCount']=count($plan['files']);$p['fileBytes']=$plan['bytes'];$p['reviewedPlanSha256']=hash_file('sha256',$file);$p['exclusions']=$plan['exclusions'];$p['passed']=true;$p['cleanup']=true;$p['milliseconds']=(hrtime(true)-$started)/1000000;
 }catch(Throwable$e){$p['errorClass']=get_class($e);$p['error']=$e instanceof DSA\Backup\Archive_Error?$e->getMessage():'Fresh original file capture refused';}
 finally{if(is_resource($s))fclose($s);$phrase=null;}
 return$p;
};
