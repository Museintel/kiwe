const {spawnSync}=require('node:child_process');
const {resolve}=require('node:path');
for(const extra of [[],['--no-woo']]){
 const result=spawnSync('php',['tools/release/test-product-notifications.php',...extra],{cwd:resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true});
 process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');
 if(result.error)throw result.error;
 if(result.status!==0)process.exit(result.status||1);
}
