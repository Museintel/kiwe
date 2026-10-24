const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..'),php=process.env.KIWE_PHP||'php';
const probe=spawnSync(php,['-r','echo json_encode([PHP_BINARY, extension_loaded("openssl")]);'],{cwd:root,encoding:'utf8',windowsHide:true});if(probe.status!==0)throw Error(probe.stderr||'PHP unavailable');
const [binary,ready]=JSON.parse(probe.stdout),args=[];
if(!ready){const extension=path.join(path.dirname(binary),'ext',process.platform==='win32'?'php_openssl.dll':'openssl.so');if(!fs.existsSync(extension))throw Error('OpenSSL unavailable');args.push('-d',`extension=${extension}`);}
for(const file of ['test-content-archive-boundaries.php','test-content-service-archives.php']){
 const result=spawnSync(php,[...args,`tools/release/${file}`],{cwd:root,encoding:'utf8',windowsHide:true});if(result.stdout)process.stdout.write(result.stdout);if(result.stderr)process.stderr.write(result.stderr);
 if(result.status!==0||/Warning:|Fatal error:/.test(result.stdout))throw Error(`Content archive boundary fixture failed: ${file}`);const value=JSON.parse(result.stdout.trim());if(value.passed!==true||value.cleanup!==true||Object.keys(value.checks).length<20||Object.values(value.checks).some(v=>v!==true))throw Error(`Incomplete archive proof: ${file}`);
}
