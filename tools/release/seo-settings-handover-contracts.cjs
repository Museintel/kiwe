const {spawnSync}=require('node:child_process'),path=require('node:path');
for(const [file,args,min] of [['test-seo-settings-handover.php',[],35],['test-seo-archive-policy.php',[],40],['test-seo-archive-policy.php',['external'],2]]){
const result=spawnSync(process.env.KIWE_PHP||'php',['tools/release/'+file,...args],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true});
if(result.stdout)process.stdout.write(result.stdout);if(result.stderr)process.stderr.write(result.stderr);
if(result.error)throw result.error;
if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test((result.stdout||'')+(result.stderr||'')))throw Error('SEO settings handover fixture failed');
const proof=JSON.parse(result.stdout.trim());if(proof.passed!==true||proof.cleanup!==true||Object.keys(proof.checks).length<min||Object.values(proof.checks).some(v=>v!==true))throw Error('Incomplete SEO settings handover proof');
}
