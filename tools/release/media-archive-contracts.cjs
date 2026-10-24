const {spawnSync}=require('node:child_process'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),php=process.env.KIWE_PHP||'php';
const probe=spawnSync(php,['-r','echo json_encode([PHP_BINARY,extension_loaded("openssl"),extension_loaded("zip")]);'],{cwd:root,encoding:'utf8',windowsHide:true});
if(probe.status!==0)throw Error(probe.stderr||'PHP unavailable');const[binary,ssl,zip]=JSON.parse(probe.stdout),args=[];
for(const[name,ready]of[['openssl',ssl],['zip',zip]])if(!ready)args.push('-d',`extension=${path.join(path.dirname(binary),'ext',process.platform==='win32'?`php_${name}.dll`:`${name}.so`)}`);
const result=spawnSync(php,[...args,'tools/release/test-media-archive-boundaries.php'],{cwd:root,encoding:'utf8',windowsHide:true,maxBuffer:2*1024*1024});
if(result.stdout)process.stdout.write(result.stdout);if(result.stderr)process.stderr.write(result.stderr);
if(result.status!==0||/Warning:|Fatal error:/.test(result.stdout))throw Error('Media archive boundary proof failed');
const receipt=JSON.parse(result.stdout.trim());if(receipt.passed!==true||receipt.cleanup!==true||Object.keys(receipt.checks).length<24||Object.values(receipt.checks).some(v=>v!==true))throw Error('Incomplete media boundary proof');
