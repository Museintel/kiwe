const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const php=process.env.KIWE_PHP||'php';
const probe=spawnSync(php,['-r','echo json_encode([PHP_BINARY,extension_loaded("openssl")]);'],{encoding:'utf8',windowsHide:true});
if(probe.status!==0)throw Error(probe.stderr||'PHP probe failed');
const [binary,crypto]=JSON.parse(probe.stdout),args=[];
if(!crypto){const extension=path.join(path.dirname(binary),'ext',process.platform==='win32'?'php_openssl.dll':'openssl.so');if(!fs.existsSync(extension))throw Error('OpenSSL unavailable');args.push('-d',`extension=${extension}`);}
args.push('tools/release/test-kiwe-configuration-snapshot.php');
const result=spawnSync(php,args,{encoding:'utf8',windowsHide:true});
if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr);
const receipt=JSON.parse(result.stdout);
if(!receipt.passed||!receipt.cleanup||Object.keys(receipt.checks).length<35||!Object.values(receipt.checks).every(Boolean))throw Error('Incomplete Kiwe app-choice recovery receipt');
const nativeGuard=spawnSync(php,['tools/release/test-wordpress-kiwe-configuration-snapshots.php'],{encoding:'utf8',windowsHide:true});
if(nativeGuard.status!==2||nativeGuard.stdout||nativeGuard.stderr)throw Error('The native fixture must refuse execution without the owned isolated WordPress harness');
receipt.checks['native fixture refuses an unisolated execution']=true;
process.stdout.write(JSON.stringify(receipt,null,2)+'\n');
