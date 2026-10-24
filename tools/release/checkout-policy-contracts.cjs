const {spawnSync}=require('node:child_process');
require('./checkout-draft-contracts.cjs');
for(const test of ['test-cod-policy.php','test-checkout-policy-disabled.php','test-checkout-draft.php']){const r=spawnSync(process.env.KIWE_PHP||'php',['tools/release/'+test],{encoding:'utf8',windowsHide:true});if(r.status!==0){process.stderr.write(r.stdout+r.stderr);process.exit(1);}const p=JSON.parse(r.stdout.trim());if(p.passed!==true||p.checks<1)throw Error('Missing behavioural proof');process.stdout.write(JSON.stringify({fixture:test,...p})+'\n');}
