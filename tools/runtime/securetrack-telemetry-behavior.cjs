const {spawnSync}=require('node:child_process');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
const cases=['events','visitor','footer','phonekey','exit-off','exit-visitor-off','exit-admin','exit-admin-off','exit-excluded-admin','exit-invalid-nonce','exit-enabled'];
let passed=0,failed=0;
for(const name of cases){
 const r=spawnSync('php',['tools/runtime/test-securetrack-telemetry.php',name],{cwd:root,encoding:'utf8',windowsHide:true,timeout:15000});
 let result;try{result=JSON.parse(r.stdout);}catch{}
 if(!result||!Array.isArray(result.checks)||r.stderr||r.error){failed++;console.log(`FAIL ${name}: ${r.error?.message||r.stderr||r.stdout}`);continue;}
 for(const c of result.checks){console.log(`${c.passed?'PASS':'FAIL'} ${c.name}`);c.passed?passed++:failed++;}
 if(r.status!==0&&result.passed){failed++;console.log(`FAIL ${name}: inconsistent process result`);}
}
console.log(`${passed}/${passed+failed} SecureTrack telemetry behavior checks passed.`);
process.exitCode=failed?1:0;
