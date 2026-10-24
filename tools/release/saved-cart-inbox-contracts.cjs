'use strict';
const {spawnSync}=require('node:child_process');
const r=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-saved-cart-identity.php'],{encoding:'utf8',windowsHide:true});
if(r.status!==0)throw Error((r.stdout||'')+(r.stderr||''));
const receipt=JSON.parse(r.stdout);if(receipt.passed!==true||receipt.checks<12||receipt.sends!==0)throw Error('Incomplete private identity proof');
console.log(JSON.stringify(receipt));
