import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
// Credentials enter stdin from independent private custody, never URLs or command arguments.
if(process.stdin.isTTY)process.stdin.setRawMode(true);
let line='';for await(const chunk of process.stdin){line+=chunk;if(line.includes('\n')||line.includes('\r'))break;if(line.length>32768)throw Error('Private transfer configuration exceeds bound');}
const cfg=JSON.parse(line);line='';
const concurrency=cfg.concurrency??2;
if(!Number.isInteger(concurrency)||concurrency<1||concurrency>4)throw Error('Bounded transfer concurrency required');
const rangeBytes=cfg.rangeBytes??8388608;
if(!Number.isInteger(rangeBytes)||rangeBytes<65536||rangeBytes>33554432)throw Error('Bounded transfer range required');
const profiles={'whole-site':['recovery.kiwebackup','observation.kiwesource','previous-kiwe.kiwefiles','previous-kiwe-manifest.json'],'native-source':['database.kiwe','files.kiwe','diagnostics.kiwe'],'previous-code':['previous-kiwe.kiwefiles','previous-kiwe-manifest.json']};
const allowed=profiles[cfg.memberProfile??'whole-site'];if(!allowed||Object.keys(cfg.members??{}).sort().join('\n')!==[...allowed].sort().join('\n'))throw Error('Exact reviewed encrypted member profile required');
const endpoint=new URL(cfg.url),directory=path.resolve(cfg.directory);
if(endpoint.protocol!=='https:'||endpoint.username||endpoint.password||endpoint.search||endpoint.hash||!/^kiwe-[a-z0-9-]+\.php$/.test(path.posix.basename(endpoint.pathname))||endpoint.hostname!==cfg.domain||!/^[a-f0-9]{64}$/.test(cfg.token)||!path.isAbsolute(cfg.directory))throw Error('Pinned TLS and private destination required');
if(!allowed.every(n=>cfg.members[n]&&Number.isSafeInteger(cfg.members[n].bytes)&&cfg.members[n].bytes>0&&cfg.members[n].bytes<=28*1024**3&&/^[a-f0-9]{64}$/.test(cfg.members[n].sha256)))throw Error('Accepted ciphertext receipts required');
const ds=await fsp.lstat(directory);if(!ds.isDirectory()||ds.isSymbolicLink()||path.resolve(await fsp.realpath(directory))!==directory)throw Error('Ordinary private custody directory required');
const hash=async p=>{const h=crypto.createHash('sha256');for await(const c of fs.createReadStream(p))h.update(c);return h.digest('hex');};
const ordinary=async p=>{const s=await fsp.lstat(p);if(!s.isFile()||s.isSymbolicLink()||s.nlink!==1)throw Error('Ordinary private member required');return s;};
const proof={schema:'kiwe.saved-ciphertext-transfer.v1',passed:false,members:{}};
let budget=null,budgetLock=null,budgetLockId=null,budgetLockOwned=false;
const reserve=bytes=>{
 if(!budget)return;
 if(budget.reservedBytes+bytes>budget.maxBytes)throw Error('Authorized transfer byte allowance exhausted; saved prefix retained');
 const state=cfg.transferBudget.path,s=fs.lstatSync(state);if(!s.isFile()||s.isSymbolicLink()||s.nlink!==1)throw Error('Ordinary transfer budget receipt required');
 const current=JSON.parse(fs.readFileSync(state,'utf8'));if(JSON.stringify(current)!==JSON.stringify(budget))throw Error('Transfer budget changed outside this process');
 budget.reservedBytes+=bytes;budget.requests++;const body=Buffer.from(JSON.stringify(budget)+'\n'),h=fs.openSync(state,'r+');try{fs.writeFileSync(h,body);fs.ftruncateSync(h,body.length);fs.fsyncSync(h);}finally{fs.closeSync(h);}
};
try{
 if(cfg.transferBudget){
  const b=cfg.transferBudget;if(!path.isAbsolute(b.path)||!Number.isSafeInteger(b.maxBytes)||b.maxBytes<1||b.maxBytes>2000000000)throw Error('Bounded authorized transfer budget required');
  const parent=path.dirname(path.resolve(b.path)),s=await fsp.lstat(parent);if(!s.isDirectory()||s.isSymbolicLink()||path.resolve(await fsp.realpath(parent))!==parent)throw Error('Ordinary private transfer budget parent required');
  budgetLock=b.path+'.lock';budgetLockId=crypto.randomBytes(32).toString('hex');await fsp.writeFile(budgetLock,budgetLockId,{flag:'wx',mode:0o600});budgetLockOwned=true;
  try{const s=await ordinary(b.path);if(s.size>1024)throw Error('Bounded transfer budget receipt required');budget=JSON.parse(await fsp.readFile(b.path,'utf8'));}catch(e){if(e.code!=='ENOENT')throw e;budget={schema:'kiwe.private-transfer-budget.v1',maxBytes:b.maxBytes,reservedBytes:0,requests:0};await fsp.writeFile(b.path,JSON.stringify(budget)+'\n',{flag:'wx',mode:0o600});}
  if(budget.schema!=='kiwe.private-transfer-budget.v1'||budget.maxBytes!==b.maxBytes||!Number.isSafeInteger(budget.reservedBytes)||budget.reservedBytes<0||budget.reservedBytes>budget.maxBytes||!Number.isSafeInteger(budget.requests)||budget.requests<0)throw Error('Existing authorized transfer budget disagrees');
 }
 for(const n of allowed){
  const final=path.join(directory,n),partial=final+'.partial',expected=cfg.members[n];let saved=false;
  try{const s=await ordinary(final);if(s.size!==expected.bytes||await hash(final)!==expected.sha256)throw Error('Existing immutable saved member disagrees');saved=true;}catch(e){if(e.code!=='ENOENT')throw e;}
  if(!saved){
   let offset=0;try{offset=(await ordinary(partial)).size;}catch(e){if(e.code!=='ENOENT')throw e;await fsp.writeFile(partial,Buffer.alloc(0),{flag:'wx',mode:0o600});}
   if(offset>expected.bytes)throw Error('Partial ciphertext exceeds accepted size');let announced=-1;
   const range=async(start,end)=>{
    const url=new URL(endpoint);url.searchParams.set('member',n);let body;
    let reason='network';
    for(let attempt=0;attempt<3;attempt++)try{
     reserve(end-start+1);
     const r=await fetch(url,{redirect:'error',headers:{'X-Kiwe-Qualification':cfg.token,Range:`bytes=${start}-${end}`,'X-Kiwe-Transfer-Range':`bytes=${start}-${end}`},signal:AbortSignal.timeout(180000)});
     if(r.status!==206){reason='http-'+r.status;await r.body?.cancel();throw Error('Bound ciphertext response refused');}
     if(r.headers.get('content-range')!==`bytes ${start}-${end}/${expected.bytes}`||r.headers.get('etag')!==`"${expected.sha256}"`){reason='bound-headers';await r.body?.cancel();throw Error('Bound ciphertext response refused');}
     reason='body';body=Buffer.from(await r.arrayBuffer());if(body.length!==end-start+1){reason='incomplete-body';throw Error('Incomplete ciphertext range');}break;
    }catch(e){if(reason==='network'&&/^[A-Z_0-9]{1,64}$/.test(e.cause?.code??''))reason='network-'+e.cause.code;if(attempt===2)throw Error('Encrypted member transfer interrupted ('+reason+')');reason='network';await new Promise(r=>setTimeout(r,1000*(attempt+1)));}
    return{start,end,body};
   };
   while(offset<expected.bytes){
    const requests=[];for(let i=0,start=offset;i<concurrency&&start<expected.bytes;i++,start+=rangeBytes)requests.push(range(start,Math.min(expected.bytes-1,start+rangeBytes-1)));
    // Retain a contiguous durable prefix only. Failed or reordered responses cannot create holes.
    const results=await Promise.allSettled(requests);const failure=results.find(r=>r.status!=='fulfilled');if(failure)throw failure.reason;
    for(const{start,end,body}of results.map(r=>r.value)){
    if(start!==offset)throw Error('Bound ciphertext range order disagrees');
    const out=await fsp.open(partial,'r+');try{if((await out.stat()).size!==offset)throw Error('Private partial changed during transfer');let written=0;while(written<body.length){const w=await out.write(body,written,body.length-written,offset+written);if(!w.bytesWritten)throw Error('Private ciphertext write incomplete');written+=w.bytesWritten;}await out.sync();}finally{await out.close();}
    offset=end+1;const percent=Math.floor(offset/expected.bytes*10)*10;if(percent!==announced){console.log(JSON.stringify({member:n,percent,bytes:offset}));announced=percent;}
    }
   }
   if(await hash(partial)!==expected.sha256)throw Error('Saved ciphertext hash disagrees');
   try{await fsp.lstat(final);throw Error('Immutable saved member appeared during transfer');}catch(e){if(e.code!=='ENOENT')throw e;}
   await fsp.rename(partial,final);
  }
  proof.members[n]={...expected,saved:true,independentSha256:true};
 }
 proof.passed=true;
 if(budget)proof.transferBudget={...budget,wireOverheadMeasured:false};
 const receipt=path.join(directory,'saved-transfer-proof.json');try{await fsp.writeFile(receipt,JSON.stringify(proof,null,2)+'\n',{flag:'wx',mode:0o600});}catch(e){if(e.code!=='EEXIST')throw e;await ordinary(receipt);const saved=JSON.parse(await fsp.readFile(receipt,'utf8'));if(saved.schema!==proof.schema||saved.passed!==true||JSON.stringify(saved.members)!==JSON.stringify(proof.members))throw Error('Existing immutable transfer receipt disagrees');proof.durableReceiptReused=true;}
 console.log(JSON.stringify(proof));
}catch(e){console.log(JSON.stringify({schema:proof.schema,passed:false,error:e.message,members:proof.members}));process.exitCode=1;}
finally{cfg.token='';if(budgetLockOwned){try{const s=await ordinary(budgetLock);if(s.size!==64||await fsp.readFile(budgetLock,'utf8')!==budgetLockId)throw Error('Transfer lease identity changed');await fsp.unlink(budgetLock);}catch(e){if(e.code!=='ENOENT')throw e;}}}
