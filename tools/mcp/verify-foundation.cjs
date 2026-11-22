const nodeFs=require('node:fs'),nodePath=require('node:path'),nodeCp=require('node:child_process'),nodeCrypto=require('node:crypto'),assert=require('node:assert/strict');
const repo=nodePath.resolve(__dirname,'../..'),base=nodePath.join(repo,'packages/kiwe-mcp-contracts');
const php=process.env.KIWE_PHP||'php';
const files=['src/Admission_Policy.php','src/Orientation.php','tests/foundation.php'];
for(const file of files){const r=nodeCp.spawnSync(php,['-l',nodePath.join(base,file)],{encoding:'utf8',windowsHide:true});assert.equal(r.status,0,r.stderr||r.stdout||'PHP syntax process unavailable');}
const run=nodeCp.spawnSync(php,[nodePath.join(base,'tests/foundation.php')],{encoding:'utf8',windowsHide:true,maxBuffer:4e6});
assert.equal(run.status,0,run.stderr||run.stdout||'Foundation PHP process unavailable');
assert.equal(run.stderr.trim(),'','Unexpected PHP warning');
const evidence=JSON.parse(run.stdout);assert(evidence.passed&&Object.values(evidence.checks).every(v=>v===true));
const sha=bytes=>nodeCrypto.createHash('sha256').update(bytes).digest('hex');
const manifest=nodeFs.readFileSync(nodePath.join(repo,'wp-content/mu-plugins/dsa/package-manifest.json'));
// Reuse the existing package owner, including its text-integrity/LF contract.
const sourceManifest=JSON.parse(manifest);
const preserved=nodeCp.spawnSync(process.execPath,[nodePath.join(repo,'tools/release/verify-package.cjs')],{cwd:repo,encoding:'utf8',windowsHide:true,maxBuffer:4e6});
assert.equal(preserved.status,0,preserved.stderr||preserved.stdout||'Existing package verifier unavailable');
const preservedManifestFiles=sourceManifest.file_count;
const qualificationPath=process.argv.find(a=>a.startsWith('--baseline='))?.slice('--baseline='.length);
let acceptedRuntimeManifestUnchanged=null;
if(qualificationPath){const previous=JSON.parse(nodeFs.readFileSync(qualificationPath));assert(previous.passed);acceptedRuntimeManifestUnchanged=sha(manifest)===previous.finalSourceManifestSha256;assert(acceptedRuntimeManifestUnchanged,'Accepted Core manifest changed');}
const catalogue=JSON.parse(nodeFs.readFileSync(nodePath.join(base,'operation-catalogue.json')));
const result={passed:true,scope:'M01 local source only; no authenticated server, native execution, HTTP/client or deployment acceptance',checkedAt:new Date().toISOString(),checks:Object.keys(evidence.checks).length,syntaxFiles:files.length,plannedOperations:Object.keys(catalogue.operations).length,runtimeExposure:catalogue.runtimeExposure,acceptedRuntimeManifestUnchanged,preservedManifestFiles,evidence,sourceHashes:Object.fromEntries([...files,'operation-catalogue.json'].map(file=>[file,sha(nodeFs.readFileSync(nodePath.join(base,file)))]))};
const output=process.argv.find(a=>a.startsWith('--output='))?.slice('--output='.length);
if(output){const target=nodePath.resolve(output);nodeFs.mkdirSync(nodePath.dirname(target),{recursive:true});nodeFs.writeFileSync(target,JSON.stringify(result,null,2)+'\n',{flag:'wx'});}
console.log(JSON.stringify({...result,evidence:undefined,sourceHashes:undefined}));
