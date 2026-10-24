const cp=require('node:child_process');
for(const version of ['2.3.3','2.3.4','2.3.11','2.4.3','2.5.0']){const r=cp.spawnSync('php',['tools/release/test-loop-styles.php',version],{encoding:'utf8',windowsHide:true});if(r.status!==0)throw Error(r.stdout+r.stderr);process.stdout.write(r.stdout);}
