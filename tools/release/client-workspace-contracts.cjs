const {spawnSync}=require('node:child_process');
const {resolve}=require('node:path');
for(const fixture of ['test-native-pages.php','test-client-business-access.php']){
const result=spawnSync(process.env.KIWE_PHP||'php',['tools/release/'+fixture,...process.argv.slice(2)],{cwd:resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true});
process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');
if(result.error)throw result.error;
if(result.status!==0||/Warning:|Fatal error:/.test(result.stdout||''))process.exit(1);
}
