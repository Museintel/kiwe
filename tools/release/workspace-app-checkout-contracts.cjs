const {spawnSync}=require('node:child_process');
const php=process.env.KIWE_PHP||'php';let checks=0;
for(const file of ['test-checkout-details.php','test-optional-workspace-apps.php','test-ai-configuration.php']){
 const r=spawnSync(php,['tools/release/'+file],{encoding:'utf8',windowsHide:true});
 if(r.status!==0||/Warning:|Fatal error:|Deprecated:/.test(r.stdout+r.stderr))throw Error(r.stdout+r.stderr);
 const value=JSON.parse(r.stdout.trim());if(value.passed!==true||value.cleanup!==true)throw Error('Incomplete proof '+file);
 checks+=typeof value.checks==='number'?value.checks:Object.keys(value.checks).length;
}
require('../runtime/checkout-save-contracts.cjs');
console.log(JSON.stringify({passed:true,checks,scope:'Bounded checkout details and exact session match; optional App absence/paid-provider denial and complete deterministic prompt. Native SQL and browser acceptance remain separate.'}));

for(const [file,args] of [['tools/release/test-app-library-client.mjs',[]],['tools/workspace-prototype/build-workspace.cjs',['--check']]]){const r=spawnSync(process.execPath,[file,...args],{encoding:'utf8',windowsHide:true});if(r.status!==0)throw Error(r.stdout+r.stderr);if(file.includes('test-app-library'))process.stdout.write(r.stdout);}
