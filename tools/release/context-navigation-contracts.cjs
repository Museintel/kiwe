const {spawnSync}=require('node:child_process');
const {resolve}=require('node:path');
for(const [command,args] of [['php',['tools/release/test-workspace-task-rest.php']],[process.execPath,['tools/release/workspace-task-actions-behavior.cjs']]]) {
const result=spawnSync(command,args,{cwd:resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true});
process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');
if(result.error)throw result.error;
if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test((result.stdout||'')+(result.stderr||'')))process.exit(1);
}
