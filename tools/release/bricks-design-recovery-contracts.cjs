const {spawnSync}=require('node:child_process'),assert=require('node:assert/strict');
const result=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-bricks-design-recovery-boundaries.php'],{encoding:'utf8',windowsHide:true});
if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr);
const proof=JSON.parse(result.stdout);assert(proof.passed&&Object.values(proof.checks).every(Boolean));
console.log(JSON.stringify({passed:true,checks:Object.keys(proof.checks).length,scope:'typed mapping boundaries; native jobs require the WordPress fixture'}));
