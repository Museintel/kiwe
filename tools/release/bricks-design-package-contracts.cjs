const {spawnSync}=require('node:child_process'),assert=require('node:assert/strict');
const result=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-bricks-design-package-boundaries.php'],{encoding:'utf8',windowsHide:true});
if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr);
const proof=JSON.parse(result.stdout);assert(proof.passed&&Object.values(proof.checks).every(Boolean));
const permissions=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-bricks-design-package-permissions.php'],{encoding:'utf8',windowsHide:true});
if(permissions.status!==0||/Warning:|Fatal error:|Deprecated:/.test(permissions.stdout+permissions.stderr))throw Error(permissions.stdout+permissions.stderr);
const access=JSON.parse(permissions.stdout);assert(access.passed&&Object.values(access.checks).every(Boolean));
console.log(JSON.stringify({passed:true,checks:Object.keys(proof.checks).length+Object.keys(access.checks).length,scope:'closed package grammar and controller boundaries; actual native authority requires WordPress'}));
