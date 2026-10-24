const {spawnSync}=require('node:child_process');
for(const script of ['test-notification-campaign-audience.php','test-notification-campaign-queue.php','test-new-post-notifications.php']){
 const r=spawnSync(process.env.KIWE_PHP||process.env.PHP_BINARY||'php',['tools/release/'+script],{encoding:'utf8',windowsHide:true});
 if(r.status!==0)throw Error(script+': '+(r.stdout||'')+(r.stderr||''));
 const proof=JSON.parse(r.stdout);
 if(proof.passed!==true||proof.checks<5)throw Error('Campaign proof incomplete: '+script);
 console.log('PASS '+script+' '+proof.checks+' checks');
}
