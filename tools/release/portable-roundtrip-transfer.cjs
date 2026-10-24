'use strict';
// Qualification-only TLS file client. Never reads a browser session or WordPress password.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),cp=require('node:child_process');
const protect=(input,decode=false)=>{
 const script='Add-Type -AssemblyName System.Security; $v=[Console]::In.ReadToEnd(); $b=[Convert]::FromBase64String($v); '+(decode?'$r=[System.Security.Cryptography.ProtectedData]::Unprotect($b,$null,[System.Security.Cryptography.DataProtectionScope]::CurrentUser);':'$r=[System.Security.Cryptography.ProtectedData]::Protect($b,$null,[System.Security.Cryptography.DataProtectionScope]::CurrentUser);')+' [Console]::Write([Convert]::ToBase64String($r));';
 return Buffer.from(cp.execFileSync('powershell.exe',['-NoProfile','-NonInteractive','-Command',script],{input:Buffer.from(input).toString('base64'),encoding:'utf8',maxBuffer:1000000}).trim(),'base64');
};
const digest=p=>crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');
const init=directory=>{
 if(process.platform!=='win32'||!path.isAbsolute(directory)||fs.existsSync(directory))throw Error('Fresh absolute Windows qualification directory required');
 fs.mkdirSync(directory);const key=crypto.generateKeyPairSync('rsa',{modulusLength:2048,publicKeyEncoding:{type:'spki',format:'pem'},privateKeyEncoding:{type:'pkcs8',format:'pem'}});
 fs.writeFileSync(path.join(directory,'qualification-key.dpapi'),protect(key.privateKey),{flag:'wx'});
 return {publicKey:key.publicKey,publicKeySha256:crypto.createHash('sha256').update(key.publicKey).digest('hex'),keyStorage:'Windows DPAPI CurrentUser; separate from encrypted recovery members'};
};
const secret=(directory,encrypted)=>{
 const key=protect(fs.readFileSync(path.join(directory,'qualification-key.dpapi')),true);
 try{return JSON.parse(crypto.privateDecrypt({key,padding:crypto.constants.RSA_PKCS1_OAEP_PADDING},Buffer.from(encrypted,'base64')).toString('utf8'));}finally{key.fill(0);}
};
async function download(directory,meta,credentials,member,abort=false){
 const target=path.join(directory,member+(abort?'.interrupted':'.partial'));
 const r=await fetch(meta.url+'?member='+encodeURIComponent(member)+'&qualificationCheck='+crypto.randomUUID(),{headers:{'X-Kiwe-Qualification':credentials.token},signal:AbortSignal.timeout(180000)});
 if(r.status!==200||r.headers.get('content-type')!=='application/octet-stream')throw Error('Protected transfer did not return an archive');
 const expected=meta.members[member];if(Number(r.headers.get('content-length'))!==expected.bytes)throw Error('Protected transfer size differs');
 const file=fs.openSync(target,'wx');const hash=crypto.createHash('sha256');let bytes=0;
 try{for await(const chunk of r.body){fs.writeSync(file,chunk);hash.update(chunk);bytes+=chunk.length;if(abort){await r.body.cancel().catch(()=>{});break;}}fs.fsyncSync(file);}finally{fs.closeSync(file);}
 if(abort)return {interrupted:true,incomplete:bytes<expected.bytes,bytes,promoted:false};
 const sha=hash.digest('hex');if(bytes!==expected.bytes||sha!==expected.sha256)throw Error('Incomplete or corrupt transport remains unpromoted');
 const destination=path.join(directory,member);if(fs.existsSync(destination))throw Error('Immutable offline member already exists');fs.renameSync(target,destination);
 return {bytes,sha256:sha,saved:true};
}
async function transfer(directory,meta){
 const credentials=secret(directory,meta.encryptedCredentials);
 try{
  const denied=await fetch(meta.url+'?member=recovery.kiwebackup&qualificationCheck='+crypto.randomUUID(),{signal:AbortSignal.timeout(20000)});if(denied.status!==404)throw Error('Anonymous qualification download did not refuse');
  const bad=await fetch(meta.url+'?member=recovery.kiwebackup&qualificationCheck='+crypto.randomUUID(),{headers:{'X-Kiwe-Qualification':'wrong'},signal:AbortSignal.timeout(20000)});if(bad.status!==404)throw Error('Wrong qualification key did not refuse');
  const interrupted=await download(directory,meta,credentials,'recovery.kiwebackup',true);if(!interrupted.incomplete||interrupted.promoted)throw Error('Intentional transfer interruption was not retained');
  const recovery=await download(directory,meta,credentials,'recovery.kiwebackup');
  const observation=await download(directory,meta,credentials,'observation.kiwesource');
  if(!/^8\.0\.0-rc\.[0-9]+$/.test(meta.sourceVersion))throw Error('Qualification source version missing');
  const receipt={schema:'kiwe.offline-pair-transfer-proof.v1',passed:true,sourceVersion:meta.sourceVersion,checks:{anonymousDenied:true,wrongKeyDenied:true,interruptedBytesUnpromoted:true,savedRecoveryHashExact:true,savedObservationHashExact:true},interrupted,recovery,observation};
  fs.writeFileSync(path.join(directory,'transfer-proof.json'),JSON.stringify(receipt,null,2)+'\n',{flag:'wx'});return receipt;
 }finally{credentials.phrase='';credentials.token='';}
}
function verify(directory,meta,repository,php){
 const credentials=secret(directory,meta.encryptedCredentials);
 try{
  const source=path.join(repository,'tools/release/fixtures/portable-offline-pair-verify.php');
  const r=cp.spawnSync(php,[source,path.join(directory,'recovery.kiwebackup'),path.join(directory,'observation.kiwesource')],{input:credentials.phrase+'\n',encoding:'utf8',maxBuffer:1000000,timeout:180000});
  const proof=JSON.parse(r.stdout);if(r.status!==0||r.stderr||!proof.passed)throw Error('Independent saved offline authentication refused');
  fs.writeFileSync(path.join(directory,'offline-verification-proof.json'),JSON.stringify(proof,null,2)+'\n',{flag:'wx'});return proof;
 }finally{credentials.phrase='';credentials.token='';}
}
async function main(){
 const action=process.argv[2],directory=process.argv[3];
 if(action==='init')return init(directory);
 const meta=JSON.parse(fs.readFileSync(path.join(directory,'remote-capture.json'),'utf8'));
 if(action==='transfer')return transfer(directory,meta);
 if(action==='verify')return verify(directory,meta,process.argv[4],process.argv[5]);
 throw Error('Unknown qualification action');
}
if(require.main===module)main().then(r=>process.stdout.write(JSON.stringify(r)+'\n')).catch(()=>{process.stderr.write('Offline qualification failed; private partial members retained.\n');process.exitCode=1;});
module.exports={init,secret,download,digest};
