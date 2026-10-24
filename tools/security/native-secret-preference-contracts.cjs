const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..'),php=process.env.KIWE_PHP||'php';
const probe=spawnSync(php,['-n','-r','echo json_encode([PHP_BINARY,ini_get("extension_dir"),extension_loaded("openssl"),extension_loaded("sodium")]);'],{encoding:'utf8',windowsHide:true});
if(probe.status!==0)throw Error('Native PHP capability probe failed');
const[binary,configured,hasSSL,hasSodium]=JSON.parse(probe.stdout),ext=process.platform==='win32'?path.join(path.dirname(binary),'ext'):configured;
const flag=name=>['-d','extension='+path.join(ext,process.platform==='win32'?'php_'+name+'.dll':name+'.so')];
const available=name=>fs.existsSync(path.join(ext,process.platform==='win32'?'php_'+name+'.dll':name+'.so'));
const cases=[{name:'base',flags:[]}];
if(!hasSSL&&available('openssl'))cases.push({name:'openssl',flags:flag('openssl')});
if(!hasSodium&&available('sodium'))cases.push({name:'sodium',flags:flag('sodium')});
let native=hasSSL||hasSodium;
for(const entry of cases){const result=spawnSync(php,['-n',...entry.flags,'tools/security/test-native-secret-preference.php'],{cwd:root,encoding:'utf8',windowsHide:true,maxBuffer:1024*1024});if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(result.stdout+result.stderr))throw Error(entry.name+': '+result.stdout+result.stderr);const receipt=JSON.parse(result.stdout);native=native||receipt.native;process.stdout.write(result.stdout);}
if(!native)throw Error('At least one actual native authenticated encryption primitive is required to verify the new preference');
