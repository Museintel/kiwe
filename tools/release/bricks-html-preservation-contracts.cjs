const path=require('path'),{spawnSync}=require('child_process');
const result=spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'test-bricks-html-preservation.php')],{encoding:'utf8'});
if(result.status!==0)throw Error(result.stdout+'\n'+result.stderr);const proof=JSON.parse(result.stdout.trim());if(!proof.passed||!proof.checks?.length||proof.checks.some(x=>!x.passed))throw Error('Approved HTML preservation boundaries failed');console.log(JSON.stringify(proof));
