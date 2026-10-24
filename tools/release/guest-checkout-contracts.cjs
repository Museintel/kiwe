const {spawnSync}=require('node:child_process');
const r=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-guest-checkout.php'],{encoding:'utf8',windowsHide:true});
if(r.status!==0)throw Error(r.stdout+r.stderr);
const proof=JSON.parse(r.stdout.trim());if(!proof.passed||proof.checks<19)throw Error('Guest checkout boundary proof missing');
console.log(JSON.stringify(proof));
