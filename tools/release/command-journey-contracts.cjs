const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{pathToFileURL}=require('node:url'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'../..'),pub=path.join(root,'public/start.kiwelaunch.com');
let checks=0;
const check=(v,m)=>{assert(v,m);checks++;};
const j=p=>JSON.parse(fs.readFileSync(path.join(pub,p),'utf8'));
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
async function run(){
 const core=await import(pathToFileURL(path.join(root,'kiwe-ai-toolkit/lib/kiwe-core.js')));
 check(core.diagnoseCommand({command:'/preview'}).status==='needs_input','Preview cannot invent a reviewed brief');
 check(core.diagnoseCommand({command:'/seam'}).status==='needs_input','Seam cannot invent an accepted source');
 for(const command of ['/preview /publish','/seam /deploy','/library /save','/previewanything'])check(core.diagnoseCommand({command,brief:'x',artifactSummary:'x'}).status==='rejected','Reject unapproved modifiers: '+command);
 const seam=core.routeCommand({command:'/seam',artifactSummary:'accepted source and hash inventory'});
 check(seam.ok&&seam.options.frameworkMode&&!seam.options.emitsBricksJson&&!seam.options.deploymentAuthorized,'Framework source is explicit; native generation/deployment remain separate');
 check(core.routeCommand({command:'/library'}).ok,'Reference lookup needs no site or private data');
 const registry=j('registry.json'),catalog=j('library/catalog.json');
 check(catalog.releaseId===registry.releaseId,'Library and command release are atomic');
 check(catalog.resources.length>=40&&catalog.entryCount>=1000,'Full canonical contracts are published, without duplicate portable copies');
 for(const file of ['ui-system/seam-vocabulary.json','ui-system/seam-class-vocabulary.json','assets/css/seam.css','assets/js/seam.js'])check(catalog.resources.some(r=>r.source.endsWith(file)),'Actual runtime authority '+file);
 check(new Set(catalog.resources.map(r=>r.source)).size===catalog.resources.length,'Each source appears once');
 for(const r of catalog.resources){
  const relative=new URL(r.raw).pathname.slice(1),data=fs.readFileSync(path.join(pub,relative));
  check(sha(data)===r.sha256&&data.length===r.bytes,'Exact public source '+r.source);
  check(fs.existsSync(path.join(pub,new URL(r.url).pathname,'index.html')),'Browsable resource '+r.source);
  if(r.source.endsWith('.json')){
   const source=JSON.parse(data);
   for(const e of r.entries){let value=source;for(const part of e.pointer.split('/').slice(1))value=value[part.replaceAll('~1','/').replaceAll('~0','~')];check(JSON.stringify(value)===JSON.stringify(e.value),'Exact pointer '+r.id+e.pointer);}
  }
 }
 const tokens=catalog.resources.find(r=>r.source.endsWith('kiwe-token-catalog.json'));
 check(tokens.entries.some(e=>e.value==='--kiwe-color-brand'),'Universal variable name is discoverable');
 const map=catalog.resources.find(r=>r.source.endsWith('/schemas/seam-map.schema.json'));
 for(const word of ['interaction','condition','query'])check(map.entries.some(e=>JSON.stringify(e).toLowerCase().includes(word)),'Explicit native '+word+' coverage');
 const pinned=j(`v/${registry.releaseId}/library/catalog.json`);
 for(const r of pinned.resources)check(r.raw.startsWith(registry.immutableBase+'/library/')&&r.url.startsWith(registry.immutableBase+'/library/'),'Pinned resource closure '+r.id);
 const {leaves}=require('./command-library.cjs');
 const escaped=leaves({'a/b':{'c~d':[0,false,'<script>',null]}});
 check(escaped[0].pointer==='/a~1b/c~0d/0'&&escaped[2].value==='<script>','Catalog pointers preserve exact punctuation and values');
 console.log(JSON.stringify({schema:'kiwe.command-journey-proof.v1',passed:true,checks,releaseId:registry.releaseId,resources:catalog.resourceCount,entries:catalog.entryCount}));
}
run().catch(e=>{console.error(e.stack);process.exitCode=1;});
