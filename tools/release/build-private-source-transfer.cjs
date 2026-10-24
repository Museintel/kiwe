// Operator transport: fixed encrypted members, expiring authorization, no WordPress bootstrap.
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const arg = name => { const v = process.argv.find(a => a.startsWith('--' + name + '=')); if (!v) throw Error('Required ' + name); return v.slice(name.length + 3); };
const root = arg('root'), id = arg('id'), output = path.resolve(arg('output')), expires = Number(arg('expires'));
if (!/^\/home\/u\d+\/domains\/[a-z0-9.-]+\/public_html$/.test(root) || !/^kiwe-[a-z0-9-]+$/.test(id) || !/^kiwe-[a-z0-9-]+\.php$/.test(path.basename(output)) || !Number.isInteger(expires) || expires <= Date.now() / 1000 || expires > Date.now() / 1000 + 14400) throw Error('Exact source and bounded expiry required');
const q = v => "'" + v.replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'";
const extra = process.argv.find(a=>a.startsWith('--member-reference=')),reference=extra?JSON.parse(fs.readFileSync(extra.slice('--member-reference='.length),'utf8')):null;
const profiles={'native-source':['database.kiwe','files.kiwe','diagnostics.kiwe'],'previous-code':['previous-kiwe.kiwefiles','previous-kiwe-manifest.json']};
const names=reference?profiles[reference.memberProfile]:['recovery.kiwebackup','observation.kiwesource','previous-kiwe.kiwefiles','previous-kiwe-manifest.json'];
if(reference&&(!names||reference.schema!=='kiwe.private-member-reference.v1'||path.posix.dirname(reference.directory)!==path.posix.dirname(root)||!/^kiwe-private-[a-z0-9-]+$/.test(path.posix.basename(reference.directory))||!/^[a-f0-9]{64}$/.test(reference.tokenSha256)||Object.keys(reference.members??{}).sort().join('\n')!==[...names].sort().join('\n')||!names.every(n=>Number.isSafeInteger(reference.members[n].bytes)&&reference.members[n].bytes>0&&reference.members[n].bytes<=2000000000&&/^[a-f0-9]{64}$/.test(reference.members[n].sha256))))throw Error('Exact reviewed private member reference required');
const privateRoot = reference?reference.directory:path.posix.dirname(root) + '/kiwe-private-backups/' + id;
const body = `<?php
declare(strict_types=1);
ini_set('display_errors','0');
$root=${q(root)};$private=${q(privateRoot)};$name=${q(path.basename(output))};$expiry=${expires};
$names=json_decode(${q(JSON.stringify(names))},true);$reference=${reference?'json_decode('+q(JSON.stringify(reference))+',true)':'null'};
$reject=static function(){http_response_code(404);exit;};
// Expired HTTP access stays closed; the exact CLI owner may still retire its public helper.
if((!$reference&&time()>$expiry&&!(PHP_SAPI==='cli'&&($argv[1]??'')==='--retire'))||is_link($private)||realpath($private)!==$private||realpath(__DIR__)!==$root||basename(__FILE__)!==$name)$reject();
$suffix=$reference?'-'.${q(id)}:'';$ref=$private.'/transfer-reference'.$suffix.'.private.json';$bound=$private.'/transfer-bound'.$suffix.'.private.json';$preparation=$private.'/transfer-preparation'.$suffix.'.json';$retirement=$private.'/transfer-retirement'.$suffix.'.json';
if(PHP_SAPI==='cli'&&$reference&&!file_exists($ref)&&($argv[1]??'')!=='--retire'){$out=fopen($ref,'x+b');$bytes=json_encode($reference,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(!$out||!chmod($ref,0600)||fwrite($out,$bytes)!==strlen($bytes)||!fflush($out)||!fsync($out))exit(3);fclose($out);}
if(!is_file($ref)||is_link($ref))$reject();
$r=json_decode(file_get_contents($ref),true);
if(!is_array($r)||($r['directory']??null)!==$private||!preg_match('/^[a-f0-9]{64}$/D',$r['tokenSha256']??''))$reject();
$stat=static function(string $p):array{$s=lstat($p);if(!$s||is_link($p)||($s['mode']&0170000)!==0100000||$s['nlink']!==1||($s['mode']&0777)!==0600)throw new RuntimeException('Private ordinary member required');return array_intersect_key($s,array_flip(['dev','ino','size','mtime','ctime']));};
if(PHP_SAPI==='cli'){
 $p=['schema'=>'kiwe.private-fixed-transfer.v1','passed'=>false,'cleanup'=>false,'checks'=>[],'helperSha256'=>hash_file('sha256',__FILE__),'sourceWrites'=>0,'wordpressBooted'=>false,'databaseConnected'=>false];
 try{
  if(($argv[1]??'')==='--retire'){
   if(!is_file($bound)||is_link($bound)||(!$reference&&is_file($private.'/transfer-tool-retired.php')))throw new RuntimeException('Bound transport retirement refused');
   if($reference){foreach([$ref,$bound,$preparation]as$owned)if(!is_file($owned)||is_link($owned)||realpath($owned)!==$owned||(fileperms($owned)&0777)!==0600)throw new RuntimeException('Exact owned transfer retirement refused');foreach([$ref,$bound,$preparation]as$owned)if(!unlink($owned))throw new RuntimeException('Private transfer retirement refused');if(!unlink(__FILE__))throw new RuntimeException('Public transfer retirement refused');}
   elseif(!rename(__FILE__,$private.'/transfer-tool-retired.php')||!chmod($private.'/transfer-tool-retired.php',0600))throw new RuntimeException('Public transport retirement refused');
   $p['passed']=true;$p['cleanup']=true;$p['checks']['public transfer retired']=true;
  }else{
   if(is_file($bound)){if($reference&&is_file($preparation)){$saved=json_decode(file_get_contents($preparation),true);if(($saved['helperSha256']??null)===$p['helperSha256']){echo json_encode($saved);exit($saved['passed']?0:1);}}throw new RuntimeException('Existing transfer binding retained');}$b=['directory'=>$private,'members'=>[]];if($reference)$b['expires']=time()+14400;
   foreach($names as$n){$s=$stat($private.'/'.$n);$m=$r['members'][$n]??null;if(!$m||$s['size']!==$m['bytes']||!hash_equals($m['sha256'],hash_file('sha256',$private.'/'.$n)))throw new RuntimeException('Encrypted member does not match accepted capture');$b['members'][$n]=$s;$p['checks'][$n.' fixed ciphertext matches']=true;}
   $out=fopen($bound,'x+b');if(!$out||!chmod($bound,0600))throw new RuntimeException('Fresh private transfer binding required');$bytes=json_encode($b,JSON_THROW_ON_ERROR);if(fwrite($out,$bytes)!==strlen($bytes)||!fflush($out)||!fsync($out))throw new RuntimeException('Private transfer binding incomplete');fclose($out);$p['passed']=true;if($reference)$p['expires']=$b['expires'];
  }
 }catch(Throwable$e){$p['error']='Fixed encrypted transfer did not complete';$p['errorClass']=get_class($e);}
 $json=json_encode($p);$receipt=($argv[1]??'')==='--retire'?$retirement:$preparation;file_put_contents($receipt,$json);chmod($receipt,0600);echo$json;exit($p['passed']?0:1);
}
header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');
if(strtolower($_SERVER['HTTP_HOST']??'')!==${q(root.split('/')[4])}||($_SERVER['REQUEST_METHOD']??'')!=='GET'||($_SERVER['HTTPS']??'')!=='on'||!is_file($bound)||is_link($bound))$reject();
$token=$_SERVER['HTTP_X_KIWE_QUALIFICATION']??'';if(!is_string($token)||strlen($token)!==64||!hash_equals($r['tokenSha256'],hash('sha256',$token)))$reject();
$member=$_GET['member']??'';if(!is_string($member)||!in_array($member,$names,true))$reject();$b=json_decode(file_get_contents($bound),true);if(($b['directory']??null)!==$private||($reference&&(!is_int($b['expires']??null)||time()>$b['expires'])))$reject();
try{$s=$stat($private.'/'.$member);}catch(Throwable$e){$reject();}if($s!==($b['members'][$member]??null))$reject();
$nativeRange=$_SERVER['HTTP_RANGE']??'';$compatRange=$_SERVER['HTTP_X_KIWE_TRANSFER_RANGE']??'';if($nativeRange!==''&&$compatRange!==''&&$nativeRange!==$compatRange)$reject();$range=$nativeRange!==''?$nativeRange:$compatRange;if(!preg_match('/^bytes=(0|[1-9][0-9]*)-(0|[1-9][0-9]*)$/D',$range,$m))$reject();$start=(int)$m[1];$end=(int)$m[2];
if($start>$end||$end>=$s['size']||$end-$start+1>33554432)$reject();$in=fopen($private.'/'.$member,'rb');if(!$in)$reject();$f=fstat($in);if($f['ino']!==$s['ino']||$f['dev']!==$s['dev']||$f['size']!==$s['size']||fseek($in,$start)!==0){fclose($in);$reject();}
set_time_limit(120);http_response_code(206);header('Content-Type: application/octet-stream');header('Content-Length: '.($end-$start+1));header('Content-Range: bytes '.$start.'-'.$end.'/'.$s['size']);header('ETag: "'.$r['members'][$member]['sha256'].'"');
$remaining=$end-$start+1;while($remaining>0&&!connection_aborted()){$chunk=fread($in,min(1048576,$remaining));if(!is_string($chunk)||$chunk==='')break;echo$chunk;$remaining-=strlen($chunk);}fclose($in);
`;
fs.writeFileSync(output, body, {flag:'wx'});
console.log(JSON.stringify({path:output,bytes:Buffer.byteLength(body),sha256:crypto.createHash('sha256').update(body).digest('hex'),expires,fixedMembers:names.length,memberProfile:reference?.memberProfile??'whole-site'}));
