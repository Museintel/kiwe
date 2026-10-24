const {spawnSync}=require('node:child_process');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
const result=spawnSync('php',['tools/release/test-lean-media-route.php'],{cwd:root,encoding:'utf8',windowsHide:true});
if(result.status!==0)throw Error((result.stdout||'')+(result.stderr||''));
process.stdout.write(result.stdout);
