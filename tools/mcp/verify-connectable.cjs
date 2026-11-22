const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..'),php=process.env.KIWE_PHP||'php';
function run(file,args=[]){const r=cp.spawnSync(php,[file,...args],{cwd:root,encoding:'utf8',windowsHide:true,maxBuffer:5e6});assert.equal(r.status,0,r.stdout+r.stderr);assert.equal(r.stderr.trim(),'','Unexpected runtime warning');const value=JSON.parse(r.stdout);assert(value.passed);return value;}
const auth=run('packages/kiwe-mcp-runtime/tests/authorization.php'),boot=run('packages/kiwe-mcp-runtime/tests/boot.php'),foundation=run('packages/kiwe-mcp-contracts/tests/foundation.php'),guidance=run('packages/kiwe-mcp-runtime/tests/guidance.php'),changes=run('packages/kiwe-mcp-runtime/tests/changes.php'),setup=run('packages/kiwe-mcp-runtime/tests/setup.php'),sandbox=run('packages/kiwe-mcp-runtime/tests/sandbox.php'),authoring=run('packages/kiwe-mcp-runtime/tests/authoring.php'),store=run('packages/kiwe-mcp-runtime/tests/store-authoring.php'),growth=run('packages/kiwe-mcp-runtime/tests/growth.php'),campaign=run('packages/kiwe-mcp-runtime/tests/campaign-native.php'),design=run('packages/kiwe-mcp-runtime/tests/design.php');
const ui=cp.spawnSync(process.execPath,['packages/kiwe-mcp-runtime/tests/growth-client.mjs'],{cwd:root,encoding:'utf8',windowsHide:true});assert.equal(ui.status,0,ui.stdout+ui.stderr);const client=JSON.parse(ui.stdout);assert(client.passed);
const bricks=run('packages/kiwe-mcp-runtime/tests/bricks-import.php');
const maintenance=run('packages/kiwe-mcp-runtime/tests/maintenance.php');
const isolation=run('packages/kiwe-mcp-runtime/tests/vendor-isolation.php');
const admission=run('packages/kiwe-mcp-runtime/tests/admission-denials.php');
const targets=['includes/Apps/MCP_App_Service.php','includes/Apps/MCP_Provider_Contract.php','includes/Access/MCP_Workspace_Service.php',...fs.readdirSync(path.join(root,'wp-content/mu-plugins/dsa/includes/MCP')).filter(n=>n.endsWith('.php')).map(n=>'includes/MCP/'+n)];
for(const file of targets){const r=cp.spawnSync(php,['-l',path.join(root,'wp-content/mu-plugins/dsa',file)],{encoding:'utf8',windowsHide:true});assert.equal(r.status,0,r.stdout+r.stderr);}
for(const file of['build-runtime-vendor.cjs','sync-app-ownership.cjs']){const r=cp.spawnSync(process.execPath,[path.join(__dirname,file),'--check'],{encoding:'utf8',windowsHide:true});assert.equal(r.status,0,r.stdout+r.stderr);}
const ownership=JSON.parse(fs.readFileSync(path.join(root,'tools/release/apps-ownership.json'),'utf8'));
assert.equal(ownership.coreApi,4);assert.equal(ownership.apps.mcp.requiresWoo,'');assert(ownership.apps.mcp.files.includes('vendor/mcp/autoload.php'));
assert(!ownership.apps.mcp.files.includes('includes/Apps/MCP_Provider_Contract.php'),'The inert provider ABI remains in Core');
const result={passed:true,checks:auth.checks+boot.checks+Object.keys(foundation.checks).length+guidance.checks+changes.checks+setup.checks+sandbox.checks+authoring.checks+store.checks+growth.checks+campaign.checks+design.checks+client.checks,syntaxFiles:targets.length,nativeSql:false,actualClient:false,sourceGates:{authorization:auth.checks,boot:boot.checks,foundation:Object.keys(foundation.checks).length,guidance:guidance.checks,changes:changes.checks,setup:setup.checks,sandbox:sandbox.checks,authoring:authoring.checks,store:store.checks,growth:growth.checks,campaignNative:campaign.checks,design:design.checks,growthClient:client.checks},vendorFiles:314,mcpOwnedFiles:ownership.apps.mcp.files.length};
result.checks+=bricks.checks;result.sourceGates.bricksImport=bricks.checks;
result.checks+=maintenance.checks;result.sourceGates.maintenance=maintenance.checks;
result.checks+=isolation.checks;result.sourceGates.vendorIsolation=isolation.checks;
result.checks+=admission.checks;result.sourceGates.admissionDenials=admission.checks;
console.log(JSON.stringify(result));
