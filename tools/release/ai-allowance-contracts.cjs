const {spawnSync}=require('node:child_process');
const {resolve}=require('node:path');
const r=spawnSync('php',['tools/release/test-ai-allowance.php'],{cwd:resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true});
process.stdout.write(r.stdout||'');process.stderr.write(r.stderr||'');
if(r.error)throw r.error;
if(r.status!==0||/Warning:|Fatal error:|Deprecated:/.test((r.stdout||'')+(r.stderr||'')))process.exit(1);
