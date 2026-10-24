const {spawnSync}=require('node:child_process');
const result=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-context-collections.php'],{encoding:'utf8',windowsHide:true});
if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr);
const proof=JSON.parse(result.stdout);if(!proof.passed||proof.count<12)throw Error('Incomplete native collection boundary checks');process.stdout.write(result.stdout);
