const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const repo=path.resolve(__dirname,'../..');const arg=n=>{const v=process.argv.find(a=>a.startsWith('--'+n+'='));if(!v)throw Error('Required '+n);return v.slice(n.length+3);};
const root=arg('root'),output=arg('output'),id=arg('id'),preflight=arg('preflight'),publicKey=fs.readFileSync(arg('public-key'),'utf8'),wordpress=arg('wordpress'),bricks=arg('bricks'),kiwe=arg('kiwe');
if(!/^\/home\/u\d+\/domains\/[a-z0-9.-]+\/public_html$/.test(root)||![id,preflight].every(x=>/^kiwe-[a-z0-9-]+$/.test(x))||!/^kiwe-[a-z0-9-]+\.php$/.test(path.basename(output)))throw Error('Exact source/private owner paths required');
if(crypto.createPublicKey(publicKey).asymmetricKeyType!=='rsa')throw Error('Independent operator RSA public key required');
const q=v=>"'"+v.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
const phaseArg=process.argv.find(a=>a.startsWith('--phase='));const phase=phaseArg?phaseArg.slice(8):'full';if(!['full','files','members','finish','stage'].includes(phase))throw Error('Known capture phase required');
const driftDiagnostics=process.argv.includes('--private-drift-diagnostics');if(driftDiagnostics&&!['members','full'].includes(phase))throw Error('Private drift diagnostics require a native observation');
const stageDiagnostics=process.argv.includes('--private-stage-diagnostics');if(stageDiagnostics&&phase!=='stage')throw Error('Private stage diagnostics require a stage attempt');
const owners=['Archive_Error','Archive_Cipher','Portable_File_Inventory','Portable_Archive_Cipher','Portable_Database_Capture','Portable_Archive_Container','Portable_Source_Compatibility','Portable_Source_Observation','Portable_Recovery_Set'];
if(phase==='stage')owners.push('Portable_Recovery_Stage');
let source="<?php\ndeclare(strict_types=1);\nnamespace { if(PHP_SAPI!=='cli'){http_response_code(404);exit;}ini_set('display_errors','0');define('ABSPATH',"+q(root+'/')+"); }\n";const hashes={};
for(const name of owners){const p='wp-content/mu-plugins/dsa/includes/Backup/'+name+'.php';let s=fs.readFileSync(path.join(repo,p),'utf8').replaceAll('\r\n','\n');hashes[p]=crypto.createHash('sha256').update(s).digest('hex');
 if(stageDiagnostics&&name==='Portable_Recovery_Stage'){
  const f='tools/release/private-stage-open-diagnostic.php',original=fs.readFileSync(path.join(repo,f),'utf8').replaceAll('\r\n','\n'),body=original.replace(/^<\?php\s+declare\(strict_types=1\);\s*/,'');
  const needle="$stream = @fopen( $path,'x+b' ); if ( ! $stream ) self::fail( 'A fresh private recovery file could not be created.' );";
  if(s.split(needle).length!==2)throw Error('Exact current exclusive open predicate required');
  s=s.replace(needle,"error_clear_last(); $stream = @fopen( $path,'x+b' ); if ( ! $stream ) { $failure=error_get_last(); $record=(static function(){\n"+body+"\n})(); $record($path,$failure,count($this->owned)); self::fail( 'A fresh private recovery file could not be created.' ); }");
  hashes[f]=crypto.createHash('sha256').update(original).digest('hex');hashes['operator-instrumented-recovery-stage']=crypto.createHash('sha256').update(s).digest('hex');
 }
 if(driftDiagnostics&&name==='Portable_Source_Observation'){
  const f='tools/release/private-source-drift-diagnostics.php',original=fs.readFileSync(path.join(repo,f),'utf8').replaceAll('\r\n','\n'),body=original.replace(/^<\?php\s+declare\(strict_types=1\);\s*/,'');
  const needle="if ( self::stamps( $root,$plan,$budget ) !== $stamps ) self::fail( 'Source file identity or metadata changed during capture. Start a fresh attempt.' );";
  if(s.split(needle).length!==2)throw Error('Exact current refusal predicate required');
  s=s.replace(needle,"$now = self::stamps( $root,$plan,$budget ); if ( $now !== $stamps ) { $record=(static function(){\n"+body+"\n})(); $record($stamps,$now); self::fail( 'Source file identity or metadata changed during capture. Start a fresh attempt.' ); }");
  hashes[f]=crypto.createHash('sha256').update(original).digest('hex');hashes['operator-instrumented-source-observation']=crypto.createHash('sha256').update(s).digest('hex');
 }
 s=s.replace(/^<\?php\s+namespace DSA\\Backup;\s*/,'');source+='namespace DSA\\Backup {\n'+s+'\n}\n';}
const fp=phase==='stage'?'tools/release/private-original-stage.php':phase==='finish'?'tools/release/private-source-finish.php':phase==='files'?'tools/release/private-source-files.php':'tools/release/private-source-capture.php';let fixture=fs.readFileSync(path.join(repo,fp),'utf8').replaceAll('\r\n','\n');hashes[fp]=crypto.createHash('sha256').update(fixture).digest('hex');fixture=fixture.replace(/^<\?php\s+declare\(strict_types=1\);\s*/,'');
const cfg={root,domain:root.split('/')[4],private:path.posix.dirname(root)+'/kiwe-private-backups/'+id,preflight:path.posix.dirname(root)+'/kiwe-private-backups/'+preflight,publicKey,wordpress,bricks,kiwe};
cfg.phase=phase;
if(phase==='stage'){const capture=arg('capture'),sha=arg('capture-sha');if(!/^kiwe-[a-z0-9-]+$/.test(capture)||capture===id||!/^[a-f0-9]{64}$/.test(sha))throw Error('Accepted complete original archive required');cfg.capture=path.posix.dirname(root)+'/kiwe-private-backups/'+capture;cfg.captureSha256=sha;}
if(phase==='finish'){const members=arg('members'),sha=arg('state-sha');if(!/^kiwe-[a-z0-9-]+$/.test(members)||members===id||!/^[a-f0-9]{64}$/.test(sha))throw Error('Accepted exact native member receipt required');cfg.members=path.posix.dirname(root)+'/kiwe-private-backups/'+members;cfg.stateSha256=sha;}
const reuseArg=process.argv.find(a=>a.startsWith('--reuse='));
if(reuseArg){const reuse=reuseArg.slice(8);if(!/^kiwe-[a-z0-9-]+$/.test(reuse)||reuse===id)throw Error('Exact prior private capture required');cfg.reuse=path.posix.dirname(root)+'/kiwe-private-backups/'+reuse;}
const planArg=process.argv.find(a=>a.startsWith('--reviewed-plan-sha='));
if(planArg){const sha=planArg.slice('--reviewed-plan-sha='.length);if(!cfg.reuse||!/^[a-f0-9]{64}$/.test(sha))throw Error('Pinned original reviewed inventory requires retained files');cfg.reviewedPlanSha256=sha;}
const secondsArg=process.argv.find(a=>a.startsWith('--seconds='));
const job=fs.readFileSync(path.join(repo,'wp-content/mu-plugins/dsa/includes/Backup/Portable_Capture_Job_Service.php'),'utf8').replaceAll('\r\n','\n');
cfg.captureSeconds=Number(job.match(/MAX_SECONDS\s*=\s*(\d+)/)[1]);hashes['wp-content/mu-plugins/dsa/includes/Backup/Portable_Capture_Job_Service.php']=crypto.createHash('sha256').update(job).digest('hex');
if(secondsArg){const seconds=Number(secondsArg.slice(10));if(!Number.isInteger(seconds)||seconds<60||seconds>cfg.captureSeconds)throw Error('Operator budget cannot exceed the current owner');cfg.captureSeconds=seconds;}
source+='namespace {\n$c=json_decode('+q(JSON.stringify(cfg))+',true);$hashes=json_decode('+q(JSON.stringify(hashes))+',true);\n';
source+=`if(($argv[1]??'')==='--worker') {
 if(realpath(__DIR__)!==$c['private']||basename(__FILE__)!=='capture-tool.php'){exit(2);}
 $_SERVER['SCRIPT_FILENAME']=__FILE__;ob_start();
 $proof=['schema'=>'kiwe.private-original-source-capture.v1','passed'=>false,'cleanup'=>false,'unexpectedExit'=>true];$returned=false;$reserve=str_repeat('x',1048576);
 $publish=static function()use(&$proof,$c){$e=error_get_last();if($e&&in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR],true)){$proof['passed']=false;$proof['cleanup']=false;$proof['unexpectedExit']=true;$proof['fatalType']=$e['type'];}$noise='';while(ob_get_level()>0)$noise=ob_get_clean().$noise;$proof['suppressedOutputBytes']=strlen($noise);$json=json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);file_put_contents($c['private'].'/capture.json',$json);chmod($c['private'].'/capture.json',0600);echo 'KIWE_CAPTURE_FINAL:'.$json;};
 // Denied native handlers and fatal failures may exit before the factory returns.
 // This early guard only publishes refusal. Success remains after native shutdown.
 register_shutdown_function(static function()use(&$returned,&$reserve,$publish){$reserve=null;if(!$returned)$publish();});
 $runner=(static function(){\n${fixture}\n})();
 $proof=$runner($c);$proof['ownerSources']=$hashes;
 $returned=true;register_shutdown_function($publish);
 exit;
}
$proof=['schema'=>'kiwe.private-original-source-capture.v1','passed'=>false,'cleanup'=>false];
try {
 if(realpath(__DIR__)!==$c['root']||basename(__FILE__)!==${q(path.basename(output))}||is_dir($c['private'])||!mkdir($c['private'],0700)||!chmod($c['private'],0700))throw new \\RuntimeException('Fresh source capture directory required');
 if(!rename(__FILE__,$c['private'].'/capture-tool.php')||!chmod($c['private'].'/capture-tool.php',0600))throw new \\RuntimeException('Public capture entry retirement refused');
 $stdout=fopen($c['private'].'/native-stdout.private.log','x+b');$stderr=fopen($c['private'].'/native-stderr.private.log','x+b');
 if(!$stdout||!$stderr||!chmod($c['private'].'/native-stdout.private.log',0600)||!chmod($c['private'].'/native-stderr.private.log',0600))throw new \\RuntimeException('Private native child output storage refused');
 $p=proc_open([PHP_BINARY,'-d','memory_limit=1024M','-d','display_errors=0','-d','disable_functions=mail',$c['private'].'/capture-tool.php','--worker'],[0=>['pipe','r'],1=>$stdout,2=>$stderr],$pipes);
 if(!is_resource($p))throw new \\RuntimeException('Native capture child refused');fclose($pipes[0]);$code=proc_close($p);fflush($stdout);fflush($stderr);$errBytes=fstat($stderr)['size'];$outBytes=fstat($stdout)['size'];
 if($outBytes>1048576)throw new \\RuntimeException('Native final receipt exceeded its bound');rewind($stdout);$out=stream_get_contents($stdout);fclose($stdout);fclose($stderr);
 $marker='KIWE_CAPTURE_FINAL:';$at=strrpos($out,$marker);if($at===false)throw new \\RuntimeException('Native capture has no final shutdown receipt');$proof=json_decode(substr($out,$at+strlen($marker)),true,512,JSON_THROW_ON_ERROR);$proof['nativeExitCode']=$code;$proof['stderrBytes']=$errBytes;if($code!==0)$proof['passed']=false;
}catch(\\Throwable $e){$proof['error']='Original native capture did not produce an accepted final receipt';$proof['errorClass']=get_class($e);}
$json=json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);if(is_dir($c['private'])){file_put_contents($c['private'].'/capture-final.json',$json);chmod($c['private'].'/capture-final.json',0600);}echo$json;
}\n`;
fs.writeFileSync(output,source,{flag:'wx'});console.log(JSON.stringify({path:output,bytes:Buffer.byteLength(source),sha256:crypto.createHash('sha256').update(source).digest('hex'),ownerFiles:owners.length,sourceRuntimeReplaced:false}));
