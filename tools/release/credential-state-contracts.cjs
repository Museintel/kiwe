const {spawnSync}=require('node:child_process');
const path=require('node:path');
const fs=require('node:fs');
const root=path.resolve(__dirname,'../..');
const probe=spawnSync('php',['-r','echo json_encode(["binary"=>PHP_BINARY,"openssl"=>extension_loaded("openssl")]);'],{encoding:'utf8',windowsHide:true});
if(probe.status!==0)throw Error('Working PHP runtime required');
const runtime=JSON.parse(probe.stdout),cryptoArgs=[],cryptoEnv={...process.env};
if(process.platform==='win32'){
 const base=path.dirname(runtime.binary),extension=path.join(base,'ext/php_openssl.dll'),config=path.join(base,'extras/ssl/openssl.cnf');
 if(!runtime.openssl&&fs.existsSync(extension))cryptoArgs.push('-d','extension_dir='+path.dirname(extension),'-d','extension=openssl');
 if(!cryptoEnv.OPENSSL_CONF&&fs.existsSync(config))cryptoEnv.OPENSSL_CONF=config;
}
let failed=false;
for(const[file,minimum]of[['test-credential-state.php',31],['test-phonekey-claims.php',26],['test-phonekey-webauthn.php',8]]){
 const crypto=file==='test-phonekey-webauthn.php';
 const r=spawnSync('php',[...(crypto?cryptoArgs:[]),path.join(__dirname,file)],{cwd:root,env:crypto?cryptoEnv:process.env,encoding:'utf8',windowsHide:true});
 let proof;try{proof=JSON.parse(r.stdout.trim());}catch{}
 const count=Object.keys(proof?.checks||{}).length;
 const passed=r.status===0&&proof?.passed===true&&count>=minimum&&Object.values(proof.checks).every(Boolean);
 console.log(`${passed?'PASS':'FAIL'} ${file}: ${count} actual-service/function checks`);
 if(!passed){failed=true;process.stderr.write(String(r.stderr||r.stdout||'Fixture unavailable')+'\n');}
}
if(failed)process.exit(1);
