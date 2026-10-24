const cp=require('node:child_process');let checks=0;
for(const [exe,args]of [[process.env.PHP_BINARY||'php',['tools/release/test-app-settings.php']],[process.execPath,['tools/release/app-presentation-behavior.cjs']]]){const r=cp.spawnSync(exe,args,{encoding:'utf8',windowsHide:true});if(r.status!==0)throw Error(r.stdout+r.stderr);const out=JSON.parse(r.stdout);if(!out.passed)throw Error('App settings regression');checks+=out.checks;}
console.log(JSON.stringify({passed:true,checks,scope:'Independent native choices/permissions and actual transition runtime'}));
