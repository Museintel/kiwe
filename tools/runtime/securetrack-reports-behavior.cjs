const {spawnSync}=require('node:child_process');const {resolve}=require('node:path');
const result=spawnSync(process.env.KIWE_PHP||'php',['tools/runtime/test-securetrack-reports.php'],{cwd:resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true,timeout:15000});
let proof;try{proof=JSON.parse(result.stdout);}catch{}
if(!proof||!Array.isArray(proof.checks)||result.error||result.stderr){console.error(result.error?.message||result.stderr||result.stdout);process.exit(1);}
for(const c of proof.checks)console.log(`${c.passed?'PASS':'FAIL'} ${c.name}`);
console.log(`${proof.checks.filter(c=>c.passed).length}/${proof.checks.length} SecureTrack report checks passed.`);
process.exitCode=result.status===0&&proof.passed&&!proof.checks.some(c=>!c.passed)?0:1;
