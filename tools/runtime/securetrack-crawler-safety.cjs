const {spawnSync}=require('node:child_process'),path=require('node:path');
const r=spawnSync('php',['tools/runtime/test-securetrack-crawler-safety.php'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true,timeout:15000});
let proof;try{proof=JSON.parse(r.stdout);}catch{}
if(!proof||r.error||r.stderr||r.status!==0||!proof.passed){console.error(r.error?.message||r.stderr||r.stdout||'Crawler safety proof unavailable');process.exit(1);}
for(const[name,passed]of Object.entries(proof.checks)){console.log(`${passed?'PASS':'FAIL'} ${name}`);if(!passed)process.exitCode=1;}
console.log(`${proof.count} actual-code crawler safety checks passed; native SQL remains separate.`);
