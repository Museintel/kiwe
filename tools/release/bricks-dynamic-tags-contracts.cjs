const path=require('path'),{spawnSync}=require('child_process');
const result=spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'test-bricks-dynamic-tags.php')],{encoding:'utf8'});
if(result.status!==0)throw Error(result.stdout+'\n'+result.stderr);const proof=JSON.parse(result.stdout.trim());if(!proof.passed)throw Error('Dynamic tag boundaries failed');console.log(JSON.stringify(proof));
const bestseller=spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'test-bricks-bestseller-compat.php')],{encoding:'utf8'});
if(bestseller.status!==0)throw Error(bestseller.stdout+'\n'+bestseller.stderr);const native=JSON.parse(bestseller.stdout.trim());if(!native.passed)throw Error('Bestseller compatibility failed');console.log(JSON.stringify(native));
