const {spawnSync}=require('node:child_process');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
let passed=0,failed=0;
for(const name of ['session','diagnostics','diagnostics-optin','visitor','failures','brain']){
 const args=name==='brain'?['tools/runtime/test-securetrack-brain.php']:['tools/runtime/test-securetrack-ingestion.php',name];
 const r=spawnSync('php',args,{cwd:root,encoding:'utf8',windowsHide:true,timeout:15000});
 let result;try{result=JSON.parse(r.stdout);}catch{}
 if(!result||!Array.isArray(result.checks)||r.stderr||r.error){failed++;console.log(`FAIL ${name}: ${r.error?.message||r.stderr||r.stdout}`);continue;}
 for(const c of result.checks){console.log(`${c.passed?'PASS':'FAIL'} ${c.name}`);c.passed?passed++:failed++;}
 if(r.status!==0&&result.passed){failed++;console.log(`FAIL ${name}: inconsistent process result`);}
}
console.log(`${passed}/${passed+failed} SecureTrack ingestion behavior checks passed.`);
process.exitCode=failed?1:0;
