'use strict';
// Windows operator custody for an encrypted source backup. Never emits a recovery phrase.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),cp=require('node:child_process');
const dpapi=(bytes,open=false)=>{
 const script='Add-Type -AssemblyName System.Security; $b=[Convert]::FromBase64String([Console]::In.ReadToEnd()); $r=[System.Security.Cryptography.ProtectedData]::'+(open?'Unprotect':'Protect')+'($b,$null,[System.Security.Cryptography.DataProtectionScope]::CurrentUser); [Console]::Write([Convert]::ToBase64String($r));';
 return Buffer.from(cp.execFileSync('powershell.exe',['-NoProfile','-NonInteractive','-Command',script],{input:bytes.toString('base64'),encoding:'utf8',maxBuffer:16384,windowsHide:true}).trim(),'base64');
};
async function main(){
 const action=process.argv[2],directory=path.resolve(process.argv[3]||'');
 if(process.platform!=='win32'||!path.isAbsolute(process.argv[3]||'')||fs.lstatSync(directory).isSymbolicLink()||path.resolve(fs.realpathSync(directory))!==directory)throw Error('Ordinary private Windows custody required');
 const metaPath=path.join(directory,'remote-capture.private.json'),keyPath=path.join(directory,'recovery-key.dpapi');
 if(action==='seal'){
  const meta=JSON.parse(fs.readFileSync(metaPath,'utf8')),key=dpapi(fs.readFileSync(path.join(directory,'operator-rsa.dpapi')),true);let plain;
  try{plain=crypto.privateDecrypt({key:crypto.createPrivateKey({key,format:'der',type:'pkcs8'}),padding:crypto.constants.RSA_PKCS1_OAEP_PADDING},Buffer.from(meta.operatorKeySealed,'base64'));const c=JSON.parse(plain.toString());if(!/^[a-f0-9]{64}$/.test(c.phrase)||!/^[a-f0-9]{64}$/.test(c.token))throw Error('Independent operator capsule refused');fs.writeFileSync(keyPath,dpapi(plain),{flag:'wx'});return {passed:true,keyStorage:'DPAPI CurrentUser',plaintextKeyWritten:false};}finally{key.fill(0);plain?.fill(0);}
 }
 const plain=dpapi(fs.readFileSync(keyPath),true),credentials=JSON.parse(plain.toString());plain.fill(0);
 try{
  const meta=JSON.parse(fs.readFileSync(metaPath,'utf8')),repo=path.resolve(__dirname,'../..');
  if(action==='probe'){
   const url=new URL(meta.url);url.searchParams.set('member','recovery.kiwebackup');const started=Date.now();
   try{const r=await fetch(url,{redirect:'error',headers:{'X-Kiwe-Qualification':credentials.token,Range:'bytes=0-4095'},signal:AbortSignal.timeout(30000)});const body=Buffer.from(await r.arrayBuffer());return {passed:r.status===206&&body.length===4096,httpStatus:r.status,bytes:body.length,etagMatches:r.headers.get('etag')==='"'+meta.capture.members['recovery.kiwebackup'].sha256+'"',rangeMatches:r.headers.get('content-range')==='bytes 0-4095/'+meta.capture.members['recovery.kiwebackup'].bytes,milliseconds:Date.now()-started};}
   catch(e){return{passed:false,errorClass:e.name,causeCode:/^[A-Z_0-9]{1,64}$/.test(e.cause?.code??'')?e.cause.code:null,milliseconds:Date.now()-started};}
  }
  if(action==='download'||action==='download-members'){
   const rangeBytes=process.argv[4]?Number(process.argv[4]):8388608,concurrency=process.argv[5]?Number(process.argv[5]):2;
   if(!Number.isInteger(rangeBytes)||rangeBytes<65536||rangeBytes>33554432||!Number.isInteger(concurrency)||concurrency<1||concurrency>4)throw Error('Bounded ciphertext transfer parameters required');
   const transfer=action==='download-members'?JSON.parse(fs.readFileSync(path.join(directory,'transfer.private.json'),'utf8')):{url:meta.url,domain:meta.domain,members:meta.capture.members};if(action==='download-members'&&transfer.schema!=='kiwe.private-member-transfer.v1')throw Error('Reviewed private member transfer required');
   const child=cp.spawn(process.execPath,[path.join(__dirname,'private-encrypted-download.mjs')],{stdio:['pipe','inherit','inherit'],windowsHide:true});child.stdin.end(JSON.stringify({...transfer,directory,token:credentials.token,rangeBytes,concurrency})+'\n');const code=await new Promise((resolve,reject)=>{child.on('error',reject);child.on('close',resolve);});if(code!==0)throw Error('Private encrypted transfer incomplete');return {passed:true,downloadFinished:true};
  }
  if(action==='verify-members'){
   const php=process.argv[4],ini=process.argv[5],transfer=JSON.parse(fs.readFileSync(path.join(directory,'transfer.private.json'),'utf8'));
   if(!php||!path.isAbsolute(php)||!ini||!path.isAbsolute(ini)||transfer.schema!=='kiwe.private-member-transfer.v1')throw Error('Known native PHP runtime and reviewed member profile required');
   const reference=transfer.memberProfile==='native-source'?'kiwe-woo-source-final-readback-rc307-c.json':transfer.memberProfile==='previous-code'?'kiwe-woo-previous-source-review-rc306-f.json':null;if(!reference)throw Error('Fixed saved-member reference required');
   const r=cp.spawnSync(php,['-c',ini,'-d','memory_limit=1024M',path.join(__dirname,'fixtures/private-source-members-offline-verify.php'),directory,transfer.memberProfile,path.join(repo,'docs/generated',reference)],{input:credentials.phrase+'\n',encoding:'utf8',maxBuffer:1048576,timeout:1800000,env:{...process.env,TEMP:directory,TMP:directory},windowsHide:true});
   const proof=JSON.parse(r.stdout);if(r.status!==0||r.stderr||proof.passed!==true)throw Error('Independent saved native-member authentication refused');fs.writeFileSync(path.join(directory,'offline-members-verification-proof.json'),JSON.stringify(proof,null,2)+'\n',{flag:'wx'});return proof;
  }
  if(action==='verify'){
   const php=process.argv[4];if(!php||!path.isAbsolute(php))throw Error('Known native PHP runtime required');
   const r=cp.spawnSync(php,['-d','memory_limit=1024M',path.join(__dirname,'fixtures/portable-offline-pair-verify.php'),path.join(directory,'recovery.kiwebackup'),path.join(directory,'observation.kiwesource')],{input:credentials.phrase+'\n',encoding:'utf8',maxBuffer:1048576,timeout:1800000,env:{...process.env,TEMP:directory,TMP:directory}});
   const proof=JSON.parse(r.stdout);if(r.status!==0||r.stderr||proof.passed!==true)throw Error('Independent saved pair authentication refused');fs.writeFileSync(path.join(directory,'offline-verification-proof.json'),JSON.stringify(proof,null,2)+'\n',{flag:'wx'});
   const pair=cp.spawnSync(php,[path.join(__dirname,'fixtures/private-previous-pair-verify.php'),directory],{input:credentials.phrase+'\n',encoding:'utf8',maxBuffer:1048576,timeout:120000});const previous=JSON.parse(pair.stdout);if(pair.status!==0||pair.stderr||previous.passed!==true)throw Error('Saved previous pair does not authenticate');fs.writeFileSync(path.join(directory,'previous-pair-verification-proof.json'),JSON.stringify(previous,null,2)+'\n',{flag:'wx'});return {passed:true,independentPair:proof,previousPair:previous};
  }
  throw Error('Unknown private custody action');
 }finally{credentials.phrase='';credentials.token='';}
}
if(require.main===module)main().then(r=>process.stdout.write(JSON.stringify(r)+'\n')).catch(()=>{process.stderr.write('Private recovery operation did not complete; existing encrypted state retained.\n');process.exitCode=1;});
