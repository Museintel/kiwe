const{spawnSync}=require('node:child_process');
let checks=0;
for(const profile of ['state','campaign','post','push']){
 const r=spawnSync(process.env.KIWE_PHP||process.env.PHP_BINARY||'php',['tools/release/test-notification-worker-ownership.php',profile],{encoding:'utf8',windowsHide:true});
 if(r.status!==0)throw Error(profile+': '+r.stdout+r.stderr);
 const p=JSON.parse(r.stdout);if(!p.passed||p.profile!==profile||p.externalDelivery!==false||p.checks<5)throw Error('Complete bounded worker evidence required');checks+=p.checks;console.log('PASS '+profile+' '+p.checks+' checks');
}
console.log(JSON.stringify({passed:true,checks,externalDelivery:false}));
