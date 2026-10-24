const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const php=process.env.KIWE_PHP||'php';
const probe=spawnSync(php,['-r','echo json_encode([PHP_BINARY,extension_loaded("openssl")]);'],{encoding:'utf8',windowsHide:true});
if(probe.status!==0)throw Error(probe.stderr||'PHP probe failed');
const [binary,crypto]=JSON.parse(probe.stdout),args=[];
if(!crypto){const extension=path.join(path.dirname(binary),'ext',process.platform==='win32'?'php_openssl.dll':'openssl.so');if(!fs.existsSync(extension))throw Error('OpenSSL unavailable');args.push('-d',`extension=${extension}`);}
args.push('tools/release/test-organization-snapshot.php');
const result=spawnSync(php,args,{encoding:'utf8',windowsHide:true});
if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr);
const receipt=JSON.parse(result.stdout);if(!receipt.passed||!receipt.cleanup||Object.keys(receipt.checks).length<30||!Object.values(receipt.checks).every(Boolean))throw Error('Incomplete organization recovery receipt');
process.stdout.write(JSON.stringify(receipt,null,2)+'\n');
