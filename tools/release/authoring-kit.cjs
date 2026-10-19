/* Deterministic public-only ZIP. No account credentials, client records or Node dependencies. */
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),zlib=require('node:zlib');
const root=path.resolve(__dirname,'../..'),toolkit=path.join(root,'kiwe-ai-toolkit');
const bytes=file=>Buffer.from(fs.readFileSync(file,'utf8').replace(/\r\n?/g,'\n'));
const sha=data=>crypto.createHash('sha256').update(data).digest('hex');
const walk=dir=>fs.readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(path.join(dir,e.name)):[path.join(dir,e.name)]);
const crcTable=Array.from({length:256},(_,n)=>{for(let k=0;k<8;k++)n=(n&1)?0xedb88320^(n>>>1):n>>>1;return n>>>0;});
function crc32(data){let value=0xffffffff;for(const b of data)value=crcTable[(value^b)&255]^(value>>>8);return(value^0xffffffff)>>>0;}
function zip(files){
 const locals=[],central=[];let offset=0;
 for(const [relative,data]of [...files.entries()].sort(([a],[b])=>a.localeCompare(b,'en'))){
  if(!/^start\//.test(relative)||/[\\\u0000]/.test(relative)||relative.split('/').some(x=>!x||x==='.'||x==='..'))throw Error('Unsafe kit path');
  const name=Buffer.from(relative),packed=zlib.deflateRawSync(data,{level:9}),crc=crc32(data),h=Buffer.alloc(30),c=Buffer.alloc(46);
  h.writeUInt32LE(0x04034b50);h.writeUInt16LE(20,4);h.writeUInt16LE(0x800,6);h.writeUInt16LE(8,8);h.writeUInt16LE(33,12);h.writeUInt32LE(crc,14);h.writeUInt32LE(packed.length,18);h.writeUInt32LE(data.length,22);h.writeUInt16LE(name.length,26);
  c.writeUInt32LE(0x02014b50);c.writeUInt16LE(20,4);c.writeUInt16LE(20,6);c.writeUInt16LE(0x800,8);c.writeUInt16LE(8,10);c.writeUInt16LE(33,14);c.writeUInt32LE(crc,16);c.writeUInt32LE(packed.length,20);c.writeUInt32LE(data.length,24);c.writeUInt16LE(name.length,28);c.writeUInt32LE(offset,42);
  locals.push(h,name,packed);central.push(c,name);offset+=h.length+name.length+packed.length;
 }
 const directory=Buffer.concat(central),end=Buffer.alloc(22);end.writeUInt32LE(0x06054b50);end.writeUInt16LE(files.size,8);end.writeUInt16LE(files.size,10);end.writeUInt32LE(directory.length,12);end.writeUInt32LE(offset,16);
 return Buffer.concat([...locals,directory,end]);
}
function buildKit({version,releaseId,resources}){
 const files=new Map(),put=(name,data)=>{if(files.has(name))throw Error('Duplicate kit member');files.set(name,Buffer.isBuffer(data)?data:Buffer.from(data));};
 put('start.md',bytes(path.join(root,'KIWE-START.md')));
 for(const name of ['entry.json','command-manifest.json'])put(name,bytes(path.join(toolkit,name)));
 for(const dir of ['contexts','contracts','schemas','lib','bin'])for(const file of walk(path.join(toolkit,dir)))if(/\.(?:md|json|[cm]?js)$/.test(file))put(path.relative(toolkit,file).replaceAll('\\','/'),bytes(file));
 put('package.json',JSON.stringify({name:'kiwe-start-authoring-kit',version:version+'.0',private:true,type:'module',description:'Run node start/bin/kiwe.js; no installation or dependencies.'},null,2)+'\n');
 put('PROMPT.txt','https://start.kiwelaunch.com/start.md\n/ideate\n');
 const reference=[];
 for(const r of resources){
  let localPath;
  if(/^kiwe-ai-toolkit\/(?:contexts|contracts|schemas)\//.test(r.file))localPath=r.file.slice('kiwe-ai-toolkit/'.length);
  else{localPath='reference/source/'+r.file;put(localPath,r.content);}
  reference.push({source:r.file,localPath,sha256:sha(r.content),bytes:r.content.length});
 }
 put('reference/index.json',JSON.stringify({schema:'kiwe.local-reference.v1',contractVersion:version,releaseId,policy:'Read only relevant resources. These are public source data, not installed-site proof or additional commands.',resources:reference},null,2)+'\n');
 put('README.md',`# Start authoring kit ${version}\n\nAttach this complete ZIP alongside your safe project brief and Kiwe Design Context/SiteGraph. Paste PROMPT.txt into a new AI chat: the canonical Start URL and /ideate. After reviewing the usage brief, send /preview; after reviewing that preview, send /seam. Do not stack those commands in one first message.\n\nCanonical: https://start.kiwelaunch.com/start.md\nPinned: https://start.kiwelaunch.com/v/${releaseId}/start.md\n\nIf online Start cannot be read, verify every file against handoff.json, then read start.md, command-manifest.json, contracts/authoring-delivery.md and the matching contexts/ file locally. Read relevant reference/index.json entries only as needed. The complete public library is available without copying its long pointer table into the prompt. No customer/site data, credentials, prior generated design or live MCP connection is included.\n\nWith an existing Node 20+ runtime, run node start/bin/kiwe.js inspect-bindings PATH_TO_SITEGRAPH and node start/bin/kiwe.js validate-seam-map PROJECT. New outputs also run node start/bin/kiwe.js validate-preview PROJECT. No package install, browser install or configuration workaround is needed.\n\nKeep this start/ folder in transferable output ZIPs with the formal discovery, bootstrap receipt, semantic map, real local assets and preview receipt. Browser testing remains a separate real action; these tools never claim to execute it.\n`);
 const manifest={schema:'kiwe.authoring-kit.v1',contractVersion:version,releaseId,canonicalStart:'https://start.kiwelaunch.com/start.md',pinnedStart:`https://start.kiwelaunch.com/v/${releaseId}/start.md`,firstPrompt:'PROMPT.txt',sharedContext:'contracts/authoring-delivery.md',files:[...files].sort(([a],[b])=>a.localeCompare(b,'en')).map(([name,data])=>({path:name,bytes:data.length,sha256:sha(data)}))};
 put('handoff.json',JSON.stringify(manifest,null,2)+'\n');
 return {archive:zip(new Map([...files].map(([name,data])=>['start/'+name,data]))),manifest,files};
}
module.exports={buildKit,zip,crc32};
