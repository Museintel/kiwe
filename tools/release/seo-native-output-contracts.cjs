const {spawnSync}=require('node:child_process'),{resolve}=require('node:path');
for(const file of ['test-seo-native-output.php','test-seo-output-context.php']){
const result=spawnSync('php',['tools/release/'+file],{cwd:resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true});
process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');
if(result.error)throw result.error;
if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test((result.stdout||'')+(result.stderr||'')))process.exit(1);

}
