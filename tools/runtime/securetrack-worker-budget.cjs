const {spawnSync}=require('node:child_process'),path=require('node:path');
let passed=0,failed=0;
for(const mode of ['on','off','paused','headers-sent']){
 const r=spawnSync('php',['tools/runtime/test-securetrack-worker-budget.php',mode],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true,timeout:5000});
 let receipt;try{receipt=JSON.parse(r.stdout);}catch{}
 if(!receipt||r.stderr||r.error){console.log(`FAIL ${mode}: ${r.error?.message||r.stderr||r.stdout}`);failed++;continue;}
 for(const c of receipt.checks){console.log(`${c.passed?'PASS':'FAIL'} ${c.name}`);c.passed?passed++:failed++;}
 if(r.status!==0&&receipt.passed){console.log('FAIL inconsistent receipt exit');failed++;}
}
console.log(`${passed}/${passed+failed} SecureTrack worker budget checks passed.`);process.exitCode=failed?1:0;
