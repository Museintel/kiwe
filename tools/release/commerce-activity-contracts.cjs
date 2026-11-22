'use strict';
const {spawnSync}=require('node:child_process');
const result=spawnSync(process.env.KIWE_PHP||process.env.PHP_BINARY||'php',['tools/release/test-commerce-activity.php'],{encoding:'utf8',windowsHide:true});
if(result.status!==0||/Warning:|Deprecated:|Fatal error:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr);
const receipt=JSON.parse(result.stdout);if(!receipt.passed||receipt.checks<22)throw Error('Incomplete commerce activity boundaries');
console.log(JSON.stringify(receipt));
