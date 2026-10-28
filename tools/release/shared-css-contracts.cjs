const cp=require('node:child_process');
for(const file of ['test-css-rule-reducer.php','test-css-reduction-cache.php']){const r=cp.spawnSync('php',['tools/release/'+file],{encoding:'utf8',windowsHide:true});if(r.status!==0)throw Error(r.stdout+r.stderr);process.stdout.write(r.stdout);}
for(const version of ['2.3.3','2.3.4','2.3.11','2.4.3','2.5.0']){const r=cp.spawnSync('php',['tools/release/test-bricks-inline-style.php',version],{encoding:'utf8',windowsHide:true});if(r.status!==0)throw Error(r.stdout+r.stderr);process.stdout.write(r.stdout);}
const rest=cp.spawnSync('php',['tools/release/test-bricks-inline-style.php','2.4.3','rest'],{encoding:'utf8',windowsHide:true});if(rest.status!==0)throw Error(rest.stdout+rest.stderr);process.stdout.write(rest.stdout);
