const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..');let checks=0;
for(const mode of ['on','off','rankmath']){
 const r=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-context-search-snapshot.php',mode],{cwd:root,encoding:'utf8',windowsHide:true});
 assert.equal(r.status,0,r.stdout+r.stderr);assert(!/Warning:|Fatal error:|Deprecated:/.test(r.stdout+r.stderr));const proof=JSON.parse(r.stdout.trim());assert(proof.passed&&!proof.engineLoaded);checks+=proof.checks;
}
// These consumers retain their own permissions; the SEO App cannot become a design/export gate.
for(const file of ['Onboarding/Onboarding_Service.php','Onboarding/Design_Context_Enhancement_Service.php','Onboarding/Design_Context_Refinement_Service.php','Onboarding/Design_Context_Journey_Service.php','Site_Graph/Design_Context_Service.php','AI/Site_Graph_Service.php','AI/Internal_AI_Context_Service.php','Rest/Site_Graph_Controller.php','Rest/AI_Access_Controller.php','Bricks/Design_Context_Binding_Service.php']){
 const source=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes',file),'utf8');
 assert(!/SEO_App_Service|seo_enabled\(|seo_workspace_enabled|SEO_Defaults_Service/.test(source),'SEO gate entered Core design flow: '+file);checks++;
}
const profile=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Profile_Service.php'),'utf8');assert(!profile.includes('\\DSA\\SEO\\'));checks++;
for(const file of ['kiwe-ai-toolkit/mcp/sitegraph-client.js','kiwe-ai-toolkit/lib/kiwe-core.js']){const source=fs.readFileSync(path.join(root,file),'utf8');assert(!/seo_workspace_enabled|seoEnabled|seo_enabled/.test(source),'SEO gate entered external authoring client');checks++;}
console.log(JSON.stringify({passed:true,checks,scope:'Core Design Context/passive saved report, provider-independent design exports and authoring adapters'}));
