/* Actual owners with synthetic I/O. WordPress printing and browser geometry have separate acceptance. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..'),read=n=>fs.readFileSync(path.join(root,n),'utf8').replace(/\r\n/g,'\n');
const source=read('wp-content/mu-plugins/dsa/assets/js/surface.js'),contract=read('wp-content/mu-plugins/dsa/assets/js/public-contract.js'),diagnostics=read('wp-content/mu-plugins/dsa/assets/js/modules/diagnostics.js');
let checks=0;function check(v,label){checks++;assert.ok(v,label);}function same(a,b,label){checks++;assert.deepEqual(JSON.parse(JSON.stringify(a)),JSON.parse(JSON.stringify(b)),label);}
function extract(name){const a=source.indexOf('\tfunction '+name+'('),b=source.indexOf('\n\tfunction ',a+1);check(a>=0,'Existing runtime owner '+name);return source.slice(a,b<0?source.length:b);}
async function main(){
 const php=spawnSync('php',[path.join(__dirname,'test-public-contract.php')],{encoding:'utf8',windowsHide:true});
 check(php.status===0&&!/Warning|Deprecated|Fatal error/.test(php.stdout+php.stderr),'Real compact PHP projection without warnings: '+php.stderr);
 const projected=JSON.parse(php.stdout),context={window:{}};vm.runInNewContext(contract,context);
 check(!context.window.DSA_DATA,'Loading the reusable catalog does not create site state');
 for(const c of projected.cases){
  const d={contractCatalog:'1',kiweTokens:c.compact,seam:{enabled:true,contract:'kiwe.seam',frameworkCss:true},nonce:'',phonekey:{nonce:'',user:{}},commerce:{available:false}};
  const result=context.window.KiwePublicContract.hydrate(d);
  check(result===d,'Hydration preserves the original boot object');same(d.kiweTokens,c.expected,'Public token fields equal the previous actual PHP projection');
  same(d.seam.vocabulary,projected.catalog.vocabulary,'Vocabulary equals its canonical PHP owner');
  check(d.kiweTokens.items.length===80&&d.kiweTokens.count>=80,'Existing bounded catalog and total count remain compatible');
  check(!Object.hasOwn(d.kiweTokens,'values')&&d.nonce===''&&d.phonekey.nonce===''&&!d.commerce.available,'Compact metadata cannot create private authority or commerce');
  const before=JSON.stringify(d);context.window.KiwePublicContract.hydrate(d);check(JSON.stringify(d)===before,'Repeated hydration preserves consumer state');
 }
 const old={kiweTokens:{items:[]},seam:{vocabulary:{existing:true}}};context.window.KiwePublicContract.hydrate(old);check(old.seam.vocabulary.existing,'Older boot objects are untouched');
 const calls={parse:0};const registryBlock=source.slice(source.indexOf('\tlet registryParsed = false;'),source.indexOf('\twindow.DSA.boot ='));
 const fixture={elements:[{type:'image',editable:true,aiVisible:true,bricksType:'image'},{type:'image',editable:true,aiVisible:false,bricksType:'icon'},{type:'text',editable:false,aiVisible:true}],count:3,customMetadata:{preserved:true}};
 const rctx={window:{DSA:{}},registry:{elements:[],count:0},registryNode:{textContent:JSON.stringify(fixture)},JSON:{parse(s){calls.parse++;return JSON.parse(s);}},surfaceFeedback:()=>{}};
 vm.runInNewContext(registryBlock,rctx);check(calls.parse===0,'Ordinary initialization does not parse the registry');
 same(rctx.window.DSA.registry,fixture,'First inspection returns every original registry field');
 check(calls.parse===1&&rctx.window.DSA.getElementsByType('image').length===2&&calls.parse===1,'Public inspection APIs share one parse');
 check(rctx.window.DSA.getAiVisibleElements().length===2&&rctx.window.DSA.getEditableElements().length===2&&rctx.window.DSA.getReplacementImageTargets().length===1,'All four filtering APIs keep original semantics');
 rctx.window.DSA.registry={elements:[],count:7};check(rctx.window.DSA.registry.count===7&&calls.parse===1,'Existing setter remains compatible');
 const bad={...rctx,window:{DSA:{}},registry:{elements:[],count:0},registryNode:{textContent:'{bad'}};vm.runInNewContext(registryBlock,bad);check(bad.window.DSA.registry.error==='parse_failed'&&bad.window.DSA.registry.count===0,'Malformed optional inspection data fails locally without private work');
 const absent={...rctx,window:{DSA:{}},registry:{elements:[],count:0},registryNode:null};vm.runInNewContext(registryBlock,absent);check(absent.window.DSA.registry.count===0,'Absent footer registry keeps the empty public API');
 let imports=0,resolveImport;const loader={presentationModules:new Map(),presentationModulePromises:new Map(),presentationModuleUrls:{links:'synthetic:links'},importModule:()=>{imports++;return new Promise(resolve=>resolveImport=resolve);}};
 vm.runInNewContext(extract('loadPresentationModule').replace('import( url )','importModule( url )')+';this.load=loadPresentationModule;',loader);
 const first=loader.load('links'),second=loader.load('links');check(imports===1&&first===second,'Concurrent first opens share one existing module request');resolveImport({render:()=>''});await first;await loader.load('links');check(imports===1,'Repeated opens reuse the mounted module cache');
 let retries=0;loader.presentationModuleUrls.games='synthetic:games';loader.importModule=()=>{retries++;return retries===1?Promise.reject(Error('synthetic network failure')):Promise.resolve({render:()=>''});};await assert.rejects(loader.load('games'),/synthetic network/);checks++;await loader.load('games');check(retries===2,'Failed import can be explicitly retried');
 const pending={then(fn){this.fn=fn;return Promise.resolve();}};let captured;
 const fresh={loadPresentationModule:()=>pending,data:{},hydrationConfig:{},phonekey:{marker:'initial'},runtimeHydrated:false,surface:{},commerce:{},searchConfig:{},presentationModuleUrls:{},gamesConfig:{},reconciliationConfig:{},debugConsoleEnabled:false,noStoreUrl:()=>'',noStoreHeaders:()=>({}),printHealthReport:()=>{}};
 vm.runInNewContext(extract('runHealthCheck').split('\n\twindow.DSA.healthCheck')[0]+';this.run=runHealthCheck;',fresh);fresh.run();fresh.phonekey={marker:'current'};fresh.runtimeHydrated=true;pending.fn({run(c){captured=c;}});check(captured.phonekey.marker==='current'&&captured.runtimeHydrated,'Requested diagnostics read current state after the lazy load');
 let probes=0;const requests=[];const diag={window:{DSA:{},DSA_DATA:{},location:{href:'https://example.invalid/'},performance:{now:()=>0}},URL,fetch:async(url,options)=>{probes++;requests.push({url,options});return {ok:!url.includes('failure'),status:url.includes('failure')?503:200,headers:{get:()=> 'application/json'},text:async()=>'{"count":2}'};}};
 vm.runInNewContext(diagnostics.replace('export function run','function run')+';this.run=run;',diag);check(probes===0,'Loading diagnostic code performs no endpoint/provider request');
 const report=await diag.run({data:{restUrl:'https://example.invalid/api',nonce:'synthetic'},hydrationConfig:{endpoint:'https://example.invalid/hydrate'},phonekey:{nonce:'synthetic',cart:{count:1}},runtimeHydrated:true,surface:{dataset:{dsaRuntimeHydrated:'true'},querySelector:()=>({}),querySelectorAll:()=>[]},commerce:{available:true},searchConfig:{endpoint:'https://example.invalid/search'},presentationModuleUrls:{},gamesConfig:{},reconciliationConfig:{},debugConsoleEnabled:false,noStoreUrl:u=>u,noStoreHeaders:h=>({...h,'Cache-Control':'no-store'}),printHealthReport:()=>{throw Error('No automatic console output');}});
 check(report.ok&&probes===4&&diag.window.DSA.lastHealthReport===report,'Explicit diagnostics preserve the real read-only report API');
 check(requests.every(r=>r.options.method==='GET'&&r.options.cache==='no-store'),'Diagnostic probes keep their existing private no-store read boundary');
 check(source.includes("window.DSA.healthCheck = runHealthCheck")&&source.includes("window.DSA.report = runHealthCheck"),'Both public diagnostic aliases remain available');
 check(!source.includes('function probeFetch(')&&!source.includes('function safeHost('),'Diagnostic implementation is absent from ordinary core parsing');
 const css=read('wp-content/mu-plugins/dsa/assets/css/surface.css');
 check(css.includes('[data-dsa-search-panel]:not([data-dsa-sheet-ready="1"])'),'Classic Search uses the existing common panel and excludes mounted Sheets');
 check(css.includes('flex:1 1 auto;min-height:0;max-height:none;overflow-y:auto'),'Classic Search results have one flexible body scroll owner');
 const before=read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php'),manifest=read('wp-content/mu-plugins/dsa/includes/Runtime/Package_Manifest.php');
 check(before.includes("[ 'dsa-seam', 'dsa-public-contract' ]")&&before.includes("wp_add_inline_script( 'dsa-surface'")&&before.includes("'before'"),'WordPress declares the metadata consumer dependency and pre-consumer hydration');
 check(manifest.includes("'assets/js/public-contract.js'")&&manifest.includes("'assets/js/modules/diagnostics.js'"),'Both new critical assets participate in incomplete-package refusal');
 console.log(JSON.stringify({passed:true,checks,scope:'Canonical PHP projection, actual browser owners with synthetic I/O, existing lazy-module and public API compatibility; native printing and Chrome acceptance are separate'}));
}
main().catch(e=>{console.error(e.stack);process.exitCode=1;});
