const {spawnSync}=require('node:child_process');const {resolve}=require('node:path');
const r=spawnSync(process.env.KIWE_PHP||'php',['tools/runtime/test-securetrack-lifecycle.php'],{cwd:resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true,timeout:15000});
let p;try{p=JSON.parse(r.stdout);}catch{}
if(!p||!Array.isArray(p.checks)||r.error||r.stderr){console.error(r.error?.message||r.stderr||r.stdout);process.exit(1);}
for(const c of p.checks)console.log(`${c.passed?'PASS':'FAIL'} ${c.name}`);
console.log(`${p.checks.filter(c=>c.passed).length}/${p.checks.length} SecureTrack lifecycle checks passed.`);
process.exitCode=r.status===0&&p.passed&&p.checks.every(c=>c.passed)?0:1;
