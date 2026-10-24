const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict'),crypto=require('node:crypto'),cp=require('node:child_process'),{pathToFileURL}=require('node:url');
const root=path.resolve(__dirname,'../..'),toolkit=path.join(root,'kiwe-ai-toolkit'),{buildKit}=require('./authoring-kit.cjs'),library=require('./command-library.cjs');
const sha=data=>crypto.createHash('sha256').update(data).digest('hex');
let checks=0;const check=(value,label)=>{assert(value,label);checks++;};
async function run(){
 const core=await import(pathToFileURL(toolkit+'/lib/kiwe-core.js'));
 check(core.diagnoseCommand({command:'/ideate /preview',brief:'x'}).status==='rejected','Stacked workflow commands are not approval');
 for(const command of ['/ideate','/preview','/seam','/audit'])check(core.routeCommand({command,brief:'reviewed',artifactSummary:'source'}).sharedContext.includes('Automatic verification and correction'),'Every routed command carries shared rules');
 for(const command of ['/ideate','/preview','/seam']){
  const rules=core.routeCommand({command,brief:'reviewed',artifactSummary:'source'}).sharedContext;
  check(rules.includes("Visual quality and the owner's accepted design take priority")&&rules.includes('same variable name may legitimately have different values'),'Every authoring stage preserves creative and scoped source authority');
  check(rules.includes('actual tool produced its bytes')&&rules.includes('does not author the final native Framework Profile')&&rules.includes('does not prove licensing'),'Every authoring stage separates real assets/compiler output/native proof');
 }
 const graph={dynamicBindings:{schema:'kiwe.dynamic-bindings.v1',fields:{'commerce.product.priceText':{tag:'{missing_price}',kind:'text',owner:'woocommerce',scope:'current-public-product'},'commerce.product.price':{tag:'{woo_product_price}',kind:'text',owner:'woocommerce',scope:'current-public-product'},'commerce.product.imageAlt':{tag:'{missing_alt}',kind:'text',owner:'woocommerce',scope:'current-public-product'},'commerce.product.title':{tag:'{post_title}',kind:'text',owner:'woocommerce',scope:'current-public-product'}}},siteGraph:{bricks:{dynamicTags:[{name:'{woo_product_price}'},{name:'{post_title}'}]}}};
 const before=JSON.stringify(graph),found=core.inspectBindings(graph,{fields:['commerce.product.priceText','commerce.product.imageAlt']});
 check(found.ok&&found.counts.fallback===2,'Known exported-tag gaps use proven declared native fallbacks');
 check(JSON.stringify(graph)===before,'Inspector never changes source authority');
 const altered=structuredClone(graph);altered.dynamicBindings.fields['commerce.product.price'].scope='private-order';
 check(!core.inspectBindings(altered,{fields:['commerce.product.priceText']}).ok,'Private or different-scope fallback refused');
 const unknown=core.inspectBindings(graph,{fields:['commerce.product.unknown']});check(!unknown.ok&&unknown.counts.blocked===1,'Unknown binding fails closed');
 const noTags=structuredClone(graph);noTags.siteGraph.bricks.dynamicTags=[];check(!core.inspectBindings(noTags,{fields:['commerce.product.priceText']}).ok,'No native catalogue is not proof');
 const version=JSON.parse(fs.readFileSync(toolkit+'/entry.json')).contractVersion;
 const kit=buildKit({version,releaseId:version+'-fixture',resources:library.resources()});
 check(kit.archive.equals(buildKit({version,releaseId:version+'-fixture',resources:library.resources()}).archive),'Authoring kit ZIP is deterministic');
 check(kit.files.get('PROMPT.txt').toString()==='https://start.kiwelaunch.com/start.md\n/ideate\n','First prompt always contains canonical link and one command');
 for(const name of ['start.md','contexts/ideate.md','contexts/preview.md','contracts/authoring-delivery.md','schemas/preview-receipt.schema.json','lib/authoring-schemas.cjs','bin/kiwe.js'])check(kit.manifest.files.some(f=>f.path===name),'Complete offline dependency '+name);
 check(kit.files.get('contracts/authoring-delivery.md').toString().includes('package-integrity.json')&&kit.files.get('contexts/seam.md').toString().includes('AI must not manufacture those artifacts'),'Offline receiving flow carries real dependency authority without another client prompt');
 check(![...kit.files.keys()].some(name=>/mcp\/|node_modules|credential|private\.json|\.php$/.test(name)),'Kit excludes adapters, dependencies and private/server files');
 const temp=fs.mkdtempSync(path.join(os.tmpdir(),'kiwe-authoring-'));
 try{
  fs.cpSync(toolkit+'/fixtures/seam-map-valid',temp,{recursive:true});
  for(const [name,data]of kit.files){const file=path.join(temp,'start',name);fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,data);}
  const kitEntry=JSON.parse(cp.execFileSync(process.execPath,['start/bin/kiwe.js','entry'],{cwd:temp,encoding:'utf8',windowsHide:true}));
  check(kitEntry.contractVersion===version&&kitEntry.startText.includes('Contract version: `'+version+'`'),'Extracted offline CLI reads its own pinned bootstrap without installation');
  const write=(name,value)=>{const file=path.join(temp,name);fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,JSON.stringify(value,null,2));};
  const record=name=>{const b=fs.readFileSync(path.join(temp,name));return{path:name,sha256:sha(b),bytes:b.length};};
  const discovery={schema:'kiwe.ideate-discovery.v5',lane:'approved-source-preservation',sourceInventory:[{path:'home.html',role:'source',authorizedUse:'source'}],siteGraph:{requirement:'required',available:true,reason:'Fixture public source',transport:'attached-export',schema:'kiwe.site-graph.v1',contextHash:null},designContextCoverage:{status:'not-applicable',score:null,method:'not-applicable',covered:[],gaps:[],lockedOwnerFacts:[],preservedOwnerPreferences:[],aiWritableGaps:[]},designContextUsage:{facts:['Example'],preferences:[],gaps:[]},designContentUsage:{selected:[],dynamic:[],previewOnly:[],excluded:[],requiresHumanReview:[]},contentReadiness:{status:'production-ready',basis:'self-contained-approved-source',placeholderRegions:[],groundedRegions:['home'],dynamicRegions:[],unresolvedClaims:[],runtimeSmokeTest:'passed'},approval:{status:'approved',requiredPhrase:'proceed'},sourceAuthority:{status:'approved-final',decisionSource:'explicit-user',permittedChanges:['nonvisual markers']},creativeBoundary:{preserveAcceptedDirection:true,frameworkNeutral:true,redesignAuthorized:false},nextAction:'compose-project'};
  write('ideate/kiwe-ideate-discovery.json',discovery);
  write('authoring/bootstrap-receipt.json',{canonicalStart:'https://start.kiwelaunch.com/start.md',contractVersion:version,source:'attached-kit',projectDataTransport:'attached-export',resources:['start/start.md','start/command-manifest.json','start/contracts/authoring-delivery.md','start/contexts/preview.md'].map(record)});
  // Protocol fixture captures, not actual viewport evidence or a website acceptance claim.
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jBq0AAAAASUVORK5CYII=','base64');
  fs.writeFileSync(path.join(temp,'authoring/mock-desktop.png'),png);fs.writeFileSync(path.join(temp,'authoring/mock-phone.png'),png);
  const receipt={schema:'kiwe.preview-receipt.v2',status:'ready-for-design-review',approvedBrief:{path:'ideate/kiwe-ideate-discovery.json',sha256:record('ideate/kiwe-ideate-discovery.json').sha256},sourceAssetInventory:[record('home.html')],semanticMapValidation:{status:'passed'},runtimeResult:{status:'passed',viewports:[{width:1280,height:800},{width:390,height:844}],proof:[record('authoring/mock-desktop.png'),record('authoring/mock-phone.png')]}};
  write('authoring/preview-receipt.json',receipt);
  let result=core.validatePreview(temp);check(result.ok&&result.readyForDesignReview&&!result.browserExecutedByValidator,'Consistent full receipt passes without claiming browser execution');
  const compiled=require(toolkit+'/lib/authoring-schemas.cjs');check(compiled.discovery(discovery)&&compiled.receipt(receipt),'Shipped standalone full schemas pass valid fixtures');
  for(const [name,digest]of Object.entries(compiled.sourceSchemaHashes))check(digest===sha(kit.files.get('schemas/'+name)),'Compiled validator matches portable schema bytes '+name);
  const broken=structuredClone(discovery);delete broken.designContextUsage;check(!compiled.discovery(broken),'Missing formal discovery sections rejected by full schema');
  const checkpoint=structuredClone(receipt);checkpoint.status='source-exported-unverified';checkpoint.runtimeResult={status:'unavailable',reason:'Browser tool absent',viewports:[],proof:[]};write('authoring/preview-receipt.json',checkpoint);
  result=core.validatePreview(temp);check(result.ok&&!result.readyForDesignReview&&result.status==='source-exported-unverified','Unavailable browser yields honest source checkpoint');
  const falseReady=structuredClone(checkpoint);falseReady.status='ready-for-design-review';write('authoring/preview-receipt.json',falseReady);check(!core.validatePreview(temp).ok,'No browser execution cannot become readiness');
  write('authoring/preview-receipt.json',receipt);fs.appendFileSync(path.join(temp,'home.html'),'\nchanged');check(!core.validatePreview(temp).ok,'Changed visitor bytes and semantic hashes rejected');fs.copyFileSync(toolkit+'/fixtures/seam-map-valid/home.html',path.join(temp,'home.html'));
  const escape=structuredClone(receipt);escape.sourceAssetInventory[0].path='../outside';write('authoring/preview-receipt.json',escape);check(!core.validatePreview(temp).ok,'Evidence traversal refused');
  const malformed=structuredClone(receipt);malformed.sourceAssetInventory=[null];write('authoring/preview-receipt.json',malformed);check(!core.validatePreview(temp).ok,'Malformed inventory yields structured failure');
  write('authoring/preview-receipt.json',receipt);fs.appendFileSync(path.join(temp,'start/start.md'),'\nstale');check(!core.validatePreview(temp).ok,'Tampered bootstrap cannot pass');
  const archive=path.join(temp,'kit.zip');fs.writeFileSync(archive,kit.archive);
  cp.execFileSync('python',['-c','import zipfile,sys; z=zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None; assert all(n.startswith("start/") and ".." not in n.split("/") for n in z.namelist()); print(len(z.namelist()))',archive],{windowsHide:true});
  check(true,'Independent Python ZIP integrity and bounded paths');
 }finally{if(!path.resolve(temp).startsWith(path.resolve(os.tmpdir())+path.sep)||!path.basename(temp).startsWith('kiwe-authoring-'))throw Error('Unsafe fixture cleanup');fs.rmSync(temp,{recursive:true,force:true});}
 console.log(JSON.stringify({schema:'kiwe.authoring-delivery-proof.v1',passed:true,checks,kitBytes:kit.archive.length,kitFiles:kit.files.size,realBrowserTrial:'Deferred to the founder’s next project; fixtures do not certify AI obedience.'}));
}
run().catch(error=>{console.error(error.stack);process.exitCode=1;});
