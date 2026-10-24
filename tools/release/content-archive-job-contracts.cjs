const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..'),php=process.env.KIWE_PHP||'php';
const probe=spawnSync(php,['-r','echo json_encode([PHP_BINARY,extension_loaded("openssl"),extension_loaded("zip")]);'],{cwd:root,encoding:'utf8',windowsHide:true});
if(probe.status!==0)throw Error(probe.stderr||'PHP unavailable');const[binary,ssl,zip]=JSON.parse(probe.stdout),args=[];
for(const[name,ready]of[['openssl',ssl],['zip',zip]])if(!ready){const extension=path.join(path.dirname(binary),'ext',process.platform==='win32'?`php_${name}.dll`:`${name}.so`);if(!fs.existsSync(extension))throw Error(name+' unavailable');args.push('-d',`extension=${extension}`);}
const result=spawnSync(php,[...args,'tools/release/test-content-archive-jobs.php'],{cwd:root,encoding:'utf8',windowsHide:true,maxBuffer:4*1024*1024});if(result.stdout)process.stdout.write(result.stdout);if(result.stderr)process.stderr.write(result.stderr);
if(result.status!==0||/Warning:|Fatal error:/.test(result.stdout))throw Error('Resumable content fixture failed');const value=JSON.parse(result.stdout.trim());if(value.passed!==true||value.cleanup!==true||Object.keys(value.checks).length<25||Object.values(value.checks).some(v=>v!==true))throw Error('Incomplete job proof');
