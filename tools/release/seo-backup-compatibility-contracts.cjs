const {spawnSync}=require('node:child_process'),path=require('node:path');
const probe = spawnSync(process.env.KIWE_PHP || 'php', ['-r', 'echo json_encode([PHP_BINARY,extension_loaded("openssl")]);'], {encoding:'utf8',windowsHide:true});
if(probe.status!==0)throw Error(probe.stderr||'PHP unavailable');
const [binary,ssl]=JSON.parse(probe.stdout),phpArgs=[];
if(!ssl){const ext=path.join(path.dirname(binary),'ext',process.platform==='win32'?'php_openssl.dll':'openssl.so');if(!require('node:fs').existsSync(ext))throw Error('OpenSSL unavailable');phpArgs.push('-d','extension='+ext);}
const result=spawnSync(process.env.KIWE_PHP||'php',[...phpArgs,'tools/release/test-seo-backup-compatibility.php'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true});
if(result.stdout)process.stdout.write(result.stdout);if(result.stderr)process.stderr.write(result.stderr);
if(result.error)throw result.error;
if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test((result.stdout||'')+(result.stderr||'')))throw Error('SEO backup compatibility fixture failed');
const proof=JSON.parse(result.stdout.trim());
if(proof.passed!==true||proof.cleanup!==true||Object.keys(proof.checks).length<28||Object.values(proof.checks).some(v=>v!==true))throw Error('Incomplete SEO backup compatibility proof');
