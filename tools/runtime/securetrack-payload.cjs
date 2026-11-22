const fs=require('node:fs'),cp=require('node:child_process'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),php=process.env.PHP_BIN||'php';
let count=0;
for(const file of ['test-securetrack-payload.php','test-securetrack-authored-content.php']){
 const run=cp.spawnSync(php,[path.join(__dirname,file)],{encoding:'utf8',windowsHide:true});
 if(run.status!==0)throw Error(run.stderr||run.stdout||'Native payload-parser check failed');
 const result=JSON.parse(run.stdout);if(!result.passed)throw Error('Native payload-parser check failed');
 if(typeof result.checks==='object'){if(Object.values(result.checks).some(v=>v!==true))throw Error('Payload case failed');count+=Object.keys(result.checks).length;}else count+=result.checks;
}
console.log('PASS SecureTrack parser and owner-bound authored content ('+count+' source checks; native SQL/browser separate)');
