'use strict';
// Pack only existing archive/signature owners into an explicitly separate CLI operator context.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const repo=path.resolve(__dirname,'../..'),arg=n=>{const v=process.argv.find(a=>a.startsWith('--'+n+'='));if(!v)throw Error('Required '+n);return v.slice(n.length+3);};
const sourceRoot=arg('source-root'),executionRoot=arg('execution-root'),output=arg('output'),id=arg('id'),sourceVersion=arg('source-version');
const rootPattern=/^\/home\/(u\d+)\/domains\/([a-z0-9.-]+)\/public_html$/;const source=rootPattern.exec(sourceRoot),executor=rootPattern.exec(executionRoot);
assert(source&&executor&&source[1]===executor[1]&&sourceRoot!==executionRoot,'Distinct verified source and authorized operator site in the same account required');
assert(/^kiwe-[a-z0-9-]+$/.test(id)&&/^kiwe-[a-z0-9-]+\.php$/.test(path.basename(output))&&/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/.test(sourceVersion),'Exact bounded owner identities required');
const publicKey=fs.readFileSync(arg('public-key'),'utf8'),key=crypto.createPublicKey(publicKey);assert(key.asymmetricKeyType==='rsa'&&key.asymmetricKeyDetails.modulusLength>=2048,'Independent RSA operator custody required');
const release=JSON.parse(fs.readFileSync(arg('release-manifest'),'utf8'));assert(release.schema==='kiwe.mu-release.v1'&&/^[a-f0-9]{64}$/.test(release.sha256)&&/^[a-f0-9]{64}$/.test(release.packageManifestSha256)&&Number.isInteger(release.bytes)&&release.bytes>=1024,'Exact prepared public signed release required');
const privateDirectory=path.posix.dirname(executionRoot)+'/'+id;
const config={sourceRoot,executionRoot,private:privateDirectory,sourceVersion,publicKey,release,base:path.basename(output)};
const quote=v=>"'"+v.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
const rawHashes={},loadedHashes={};let packed="<?php\ndeclare(strict_types=1);\nnamespace {if(PHP_SAPI!=='cli'){http_response_code(404);exit;}umask(0077);ini_set('display_errors','0');define('ABSPATH',"+quote(sourceRoot+'/')+");}\n";
for(const [namespace,names]of [['DSA\\Backup',['Archive_Error','Archive_Cipher','Portable_File_Inventory','Portable_Archive_Cipher','Portable_Archive_Container']],['DSA\\Runtime',['Update_Service']]])for(const name of names){const p='wp-content/mu-plugins/dsa/includes/'+namespace.split('\\')[1]+'/'+name+'.php',raw=fs.readFileSync(path.join(repo,p),'utf8'),body=raw.replaceAll('\r\n','\n');rawHashes[p]=crypto.createHash('sha256').update(raw).digest('hex');loadedHashes[p]=crypto.createHash('sha256').update(body).digest('hex');packed+='namespace '+namespace+' {\n'+body.replace(/^<\?php\s+namespace DSA\\(?:Backup|Runtime);\s*/,'')+'\n}\n';}
const factory=n=>{const p='tools/release/'+n,raw=fs.readFileSync(path.join(repo,p),'utf8'),body=raw.replaceAll('\r\n','\n');rawHashes[p]=crypto.createHash('sha256').update(raw).digest('hex');loadedHashes[p]=crypto.createHash('sha256').update(body).digest('hex');return body.replace(/^<\?php\s+declare\(strict_types=1\);\s*/,'');};
const capture=factory('private-previous-code-capture.php'),signature=factory('private-wordpress-signature-review.php');
packed+="namespace {\n$c=json_decode("+quote(JSON.stringify(config))+",true,512,JSON_THROW_ON_ERROR);$sources=json_decode("+quote(JSON.stringify({rawSha256:rawHashes,loadedSha256:loadedHashes}))+",true,512,JSON_THROW_ON_ERROR);\n";
packed+=`if(realpath(__DIR__)!==$c['executionRoot']||basename(__FILE__)!==$c['base']||is_link(__FILE__))exit(2);
if(($argv[1]??'')==='--crypto-worker'){
 $c['mode']=$argv[2]??'';if(!in_array($c['mode'],['native','pure'],true))exit(2);ob_start();$review=(static function(){\n${signature}\n})();$p=$review($c);$noise=ob_get_clean();$p['suppressedOutputBytes']=strlen($noise);if($noise!=='')$p['passed']=false;echo json_encode($p,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit($p['passed']?0:1);
}
$private=$c['private'];$receipt=$private.'/receipt.json';
if(is_file($receipt)&&!is_link($receipt)){$p=json_decode(file_get_contents($receipt),true,512,JSON_THROW_ON_ERROR);if(($p['helperSha256']??null)!==hash_file('sha256',__FILE__))exit(2);echo json_encode($p,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit($p['passed']?0:1);}
$proof=['schema'=>'kiwe.private-previous-code-review.v1','passed'=>false,'sourceWrites'=>0,'wordpressBooted'=>false,'databaseConnected'=>false,'helperSha256'=>hash_file('sha256',__FILE__),'ownerSources'=>$sources,'sourceDomain'=>basename(dirname($c['sourceRoot'])),'sourceVersion'=>$c['sourceVersion'],'privateDirectory'=>$private,'candidateVersion'=>$c['release']['version'],'candidateArchiveSha256'=>$c['release']['sha256'],'productionUpgradeExecuted'=>false];$lease=null;
try{
 if(file_exists($private)||is_link($private)||!mkdir($private,0700)||!chmod($private,0700))throw new RuntimeException('Fresh exact private custody required');
 $lease=fopen($private.'/lease.lock','x+b');if(!$lease||!chmod($private.'/lease.lock',0600)||!flock($lease,LOCK_EX|LOCK_NB))throw new RuntimeException('Exclusive previous-code lease required');
 $capture=(static function(){\n${capture}\n})();$proof['previousCode']=$capture($c);if(!$proof['previousCode']['passed'])throw new RuntimeException('Previous-code preservation refused');
 $proof['sourceInstalledUpdaterPresent']=is_file($c['sourceRoot'].'/wp-content/mu-plugins/dsa/includes/Runtime/Update_Service.php');
 foreach(['native','pure']as$mode){$stdout=fopen($private.'/crypto-'.$mode.'.stdout.private','x+b');$stderr=fopen($private.'/crypto-'.$mode.'.stderr.private','x+b');if(!$stdout||!$stderr||!chmod($private.'/crypto-'.$mode.'.stdout.private',0600)||!chmod($private.'/crypto-'.$mode.'.stderr.private',0600))throw new RuntimeException('Private signature output required');
  $command=$mode==='pure'?[PHP_BINARY,'-n','-d','display_errors=0',__FILE__,'--crypto-worker',$mode]:[PHP_BINARY,'-d','display_errors=0',__FILE__,'--crypto-worker',$mode];
  $child=proc_open($command,[0=>['pipe','r'],1=>$stdout,2=>$stderr],$pipes);if(!is_resource($child))throw new RuntimeException('Signature-only process refused');fclose($pipes[0]);$exit=proc_close($child);fflush($stdout);fflush($stderr);$errorBytes=fstat($stderr)['size'];$outputBytes=fstat($stdout)['size'];if($outputBytes>65536)throw new RuntimeException('Bounded signature proof required');rewind($stdout);$body=stream_get_contents($stdout);fclose($stdout);fclose($stderr);$p=json_decode($body,true,64,JSON_THROW_ON_ERROR);$p['nativeExitCode']=$exit;$p['stderrBytes']=$errorBytes;if($exit!==0||$errorBytes!==0)$p['passed']=false;$proof['signature'][$mode]=$p;if(!$p['passed'])throw new RuntimeException('Source signature route refused');
 }
 $proof['operatorKeySealed']=file_get_contents($private.'/operator-key.sealed');$proof['originalCodeAuthenticated']=true;$proof['sourceWordPressSignatureFunctionsQualifiedOnCli']=true;$proof['webFpmExecutionQualified']=false;$proof['fullSiteBackup']=false;$proof['kiweStateRecoveryQualified']=false;$proof['passed']=true;$proof['retainedPrivateCustody']=true;
}catch(Throwable $error){$proof['error']='Source-bound previous-code and signature qualification did not finish';$proof['errorClass']=get_class($error);}
finally{if(is_resource($lease)){flock($lease,LOCK_UN);fclose($lease);}}
$body=json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(is_dir($private)){$h=fopen($receipt,'x+b');if(!$h||!chmod($receipt,0600)||fwrite($h,$body)!==strlen($body)||!fflush($h)||!fsync($h))exit(3);fclose($h);}echo $body;exit($proof['passed']?0:1);
}\n`;
fs.writeFileSync(output,packed,{flag:'wx'});console.log(JSON.stringify({helper:path.basename(output),bytes:Buffer.byteLength(packed),sha256:crypto.createHash('sha256').update(packed).digest('hex'),sourceFilesReadOnly:true,wordpressBooted:false,databaseConnected:false,sourceVersion,candidateVersion:release.version,ownerFiles:Object.keys(rawHashes).length}));
