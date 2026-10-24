const {spawnSync}=require('node:child_process'),{resolve}=require('node:path');
const r=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-search-performance.php'],{cwd:resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true});
process.stdout.write(r.stdout||'');process.stderr.write(r.stderr||'');if(r.error)throw r.error;
if(r.status!==0||/Warning:|Fatal error:|Deprecated:/.test((r.stdout||'')+(r.stderr||'')))process.exit(1);
