const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),vm=require('node:vm'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..');let checks=0;
for(const mode of ['default','on','off','rankmath','yoast']){
 const r=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-seo-app.php',mode],{cwd:root,encoding:'utf8',windowsHide:true});
 assert.equal(r.status,0,r.stdout+'\n'+r.stderr);assert(!/Warning:|Fatal error:|Deprecated:/.test(r.stdout+r.stderr));const v=JSON.parse(r.stdout.trim());assert.equal(v.passed,true);assert.equal(v.cleanup,true);checks+=Object.keys(v.checks).length;
}
const runtime=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/js/workspace-shell.js'),'utf8');
const plugin=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Plugin.php'),'utf8');
assert(plugin.includes('SEO_App_Service() )->register( function ()'));assert(plugin.includes('SEO_Engine_Service() )->register('));
assert(!/\$this->seo_(context|refinement|metadata_migration)|\$this->schema_geo/.test(plugin));checks+=3;
// Execute the actual Home renderer and switch; no DOM/browser correctness claim from this fixture.
async function homeScenario({enabled=false,externalOwner='',failure=false}={}){
 let rendered,request,assigned='',counts=0;
 const task={group:'apps',url:'https://site.test/wp-admin/admin.php?page=kiwe-workspace&area=apps',label:'SEO',icon:'dashicons-search',app:{enabled,canChange:true,externalOwner,endpoint:'https://site.test/wp-json/dsa/v1/workspace/apps/seo',expected:'a'.repeat(64),url:enabled?'https://site.test/wp-admin/admin.php?page=kiwe-seo-assistant':'',description:'Saved SEO',status:enabled?'On':'Off'}};
 const h=(type,props,...children)=>({type,props:props||{},children});
 const c={URL,Date,setTimeout(){},fetch:async(url,options)=>{counts++;request={url,options};return {ok:!failure,json:async()=>failure?{message:'Refresh before saving.'}:{app:{enabled:!enabled},reload:true}};},document:{visibilityState:'visible',getElementById:()=>({}),querySelector:()=>null},window:{KIWE_CLIENT_SHELL_DATA:{home:true,tasks:[task],nonce:'wp_rest'},wp:{element:{createElement:h,createRoot:()=>({render:value=>{rendered=value;}})}},location:{href:task.url,origin:'https://site.test',assign:url=>{assigned=url;}},addEventListener(){}}};
 vm.createContext(c);vm.runInContext(runtime,c);
 function all(n){return !n||typeof n!=='object'?[]:[n,...n.children.flatMap(v=>Array.isArray(v)?v.flatMap(all):all(v))];}
 let nodes=all(rendered),control=nodes.find(n=>n.type==='input');assert(control);assert.equal(control.props.checked,enabled);assert.equal(Boolean(control.props.disabled),Boolean(externalOwner));assert.equal(counts,0);assert(nodes.some(n=>n.type==='article'&&n.props.className.includes(enabled?'apricot':'is-off')));
 if(externalOwner)return 5;
 await control.props.onChange({target:{checked:!enabled}});assert.equal(counts,1);assert.equal(request.options.method,'POST');assert.equal(request.options.cache,'no-store');assert.equal(request.options.headers['X-Kiwe-Mutation'],'1');assert.deepEqual(JSON.parse(request.options.body),{enabled:!enabled,expected:'a'.repeat(64)});
 assert.equal(assigned,failure?'':task.url);if(failure)assert(all(rendered).some(n=>n.props.role==='alert'));
 return failure?12:11;
}
(async()=>{checks+=await homeScenario();checks+=await homeScenario({enabled:true});checks+=await homeScenario({externalOwner:'rank_math'});checks+=await homeScenario({failure:true});console.log(JSON.stringify({passed:true,checks,scope:'local app/native-option admission, late external plugins, privacy precedence and actual Home JS switch with synthetic boundaries'}));})().catch(e=>{console.error(e);process.exitCode=1;});
