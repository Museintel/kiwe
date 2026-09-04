const {spawnSync}=require('node:child_process');
const {resolve}=require('node:path');
const root=resolve(__dirname,'../..');
for(const [bin,args] of [['php',['tools/release/test-design-context-native.php']],[process.execPath,['tools/runtime/design-context-navigation.cjs']]]){
 const result=spawnSync(bin,args,{cwd:root,encoding:'utf8'});
 process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');
 if(result.error)throw result.error;
 if(result.status!==0)process.exit(result.status||1);
}
