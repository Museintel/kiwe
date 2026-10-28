const {spawnSync}=require('node:child_process');
const php=process.env.KIWE_PHP||'php';let checks=0;
for(const name of ['test-admin-settings-home.php','test-store-display.php','test-settings-named-maps.php','test-app-settings.php','test-workspace-app-entry.php']){
 const r=spawnSync(php,['tools/release/'+name],{encoding:'utf8',windowsHide:true});
 if(r.status!==0||/Warning:|Fatal error:|Deprecated:/.test(r.stdout+r.stderr))throw Error(r.stdout+r.stderr);
 const receipt=JSON.parse(r.stdout.trim());if(receipt.passed!==true||receipt.cleanup!==true)throw Error('Incomplete proof '+name);
 checks+=typeof receipt.checks==='number'?receipt.checks:Object.keys(receipt.checks).length;
}
require('../pwa/workspace-launch-contracts.cjs');require('../pwa/workspace-app-use-contracts.cjs');
console.log(JSON.stringify({passed:true,cleanup:true,checks,scope:'One editing home, unique technical controls, minimal native patches and role-aware installed-app entry; native WordPress/browser proof remains separate.'}));
