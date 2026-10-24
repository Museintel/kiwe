<?php
declare(strict_types=1);
// Operator-only authentication. Decryption never extracts files or executes SQL/PHP.
if(PHP_SAPI!=='cli'||$argc!==4)exit(2);
define('ABSPATH',__DIR__.'/');$base=__DIR__.'/../../../wp-content/mu-plugins/dsa/includes/Backup/';
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Archive_Container','Portable_Database_Capture']as$n)require$base.$n.'.php';
$phrase=rtrim((string)fgets(STDIN,1026),"\r\n");$stream=null;$proof=['schema'=>'kiwe.independent-native-members-offline.v1','passed'=>false,'checks'=>[],'plaintextExtracted'=>false,'sqlExecuted'=>false,'wordpressBooted'=>false,'fullRecoveryEnvelope'=>false,'actualSourceRestoreExecuted'=>false];
try{
 $root=realpath($argv[1]);if(!$root||is_link($argv[1]))throw new RuntimeException('Ordinary private directory required');
 $profile=$argv[2];$expected=json_decode(file_get_contents($argv[3]),true,512,JSON_THROW_ON_ERROR);
 $check=static function(string$n,bool$v)use(&$proof):void{$proof['checks'][$n]=$v;if(!$v)throw new RuntimeException('Saved native member does not match reviewed source');};
 $verify=static function(string$n,bool$db=false)use($root,$phrase,&$stream):array{$p=$root.'/'.$n;$s=lstat($p);if(!$s||is_link($p)||($s['mode']&0170000)!==0100000||$s['nlink']!==1)throw new RuntimeException('Ordinary saved ciphertext required');$stream=fopen($p,'rb');$r=$db?DSA\Backup\Portable_Database_Capture::verify($stream,$phrase):DSA\Backup\Portable_Archive_Container::verify($stream,$phrase);fclose($stream);$stream=null;return$r;};
 if($profile==='native-source'){
  $source=$expected['sourceMembers'];$observation=$source['observation'];$proof['members']=[];
  foreach(['database.kiwe','files.kiwe','diagnostics.kiwe']as$n){$m=$expected['retainedMembers'][$n];$check('exact saved bytes and digest '.$n,filesize($root.'/'.$n)===$m['bytes']&&hash_file('sha256',$root.'/'.$n)===$m['sha256']);$r=$verify($n,$n==='database.kiwe');$proof['members'][$n]=['bytes'=>$m['bytes'],'sha256'=>$m['sha256'],'archiveId'=>$r['archiveId'],'authenticated'=>true];
   if($n==='database.kiwe'){$check('database archive identity',$r['archiveId']===$observation['database']['archiveId']);$check('complete native tables and rows',count($r['tables'])===$observation['database']['tables']&&$r['rows']===$observation['database']['rows']);$proof['databaseTables']=count($r['tables']);$proof['databaseRows']=$r['rows'];}
   elseif($n==='files.kiwe'){$inventory=$r['inventory'];$check('file archive identity',$r['archiveId']===$observation['files']['archiveId']);$check('complete source file and directory totals',count($inventory['files'])===$source['fileCount']&&count($inventory['directories'])===$source['directoryCount']&&$inventory['bytes']===$source['fileBytes']);$proof['sourceFiles']=count($inventory['files']);$proof['sourceDirectories']=count($inventory['directories']);$proof['sourceFileBytes']=$inventory['bytes'];$check('captured recovery exclusions preserved',$inventory['exclusions']===$source['exclusions']);}
   else{$check('diagnostics archive identity',$r['archiveId']===$source['diagnostics']['archiveId']);$check('diagnostic initial extent totals',count($r['inventory']['files'])===$source['diagnostics']['fileCount']&&$r['inventory']['bytes']===$source['diagnostics']['fileBytes']);$proof['diagnosticFiles']=count($r['inventory']['files']);}
  }
  $proof['writerFreezeVerified']=false;$proof['databaseFileConsistencyVerified']=false;
 }elseif($profile==='previous-code'){
  $capture=$expected['previousCode'];foreach(['previous-kiwe.kiwefiles','previous-kiwe-manifest.json']as$n){$m=$capture['privateMembers'][$n];$check('exact saved bytes and digest '.$n,filesize($root.'/'.$n)===$m['bytes']&&hash_file('sha256',$root.'/'.$n)===$m['sha256']);}$r=$verify('previous-kiwe.kiwefiles');$actual=[];foreach($r['inventory']['files']as$f)$actual[$f['path']]=['bytes'=>$f['bytes'],'sha256'=>$f['sha256']];$manifest=json_decode(file_get_contents($root.'/previous-kiwe-manifest.json'),true,512,JSON_THROW_ON_ERROR);ksort($actual);ksort($manifest);$check('independent complete installed-member manifest',$actual===$manifest);$check('original installed member count',count($actual)===$capture['previousCodeMembers']);
  foreach(['dsa.php'=>'originalLoaderSha256','dsa/dsa.php'=>'originalEntrySha256','dsa/package-manifest.json'=>'originalManifestSha256']as$n=>$k)$check('exact original '.$n,isset($actual[$n])&&$actual[$n]['sha256']===$capture[$k]);$check('original optional incident guard presence',isset($actual['kiwe-incident-guard.php'])===$capture['incidentGuardOriginallyPresent']);$proof['previousCodeMembers']=count($actual);$proof['sourceVersion']=$capture['sourceVersion'];$proof['originalManifestDifferencesPreserved']=count($capture['installedManifestDrift']);$proof['archiveSha256']=hash_file('sha256',$root.'/previous-kiwe.kiwefiles');
 }else throw new RuntimeException('Fixed reviewed profile required');
 $proof['passed']=true;
}catch(Throwable$e){$proof['error']='Saved native member authentication refused';$proof['errorClass']=get_class($e);}finally{if(is_resource($stream))fclose($stream);if(function_exists('sodium_memzero'))sodium_memzero($phrase);}
echo json_encode($proof,JSON_UNESCAPED_SLASHES);exit($proof['passed']?0:1);
