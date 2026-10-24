/* Pack the current checksum-bound read-only owners into one CLI-only source audit. */
const fs=require('node:fs');const path=require('node:path');const crypto=require('node:crypto');
const repo=path.resolve(__dirname,'../..');
const option=(name)=>{const v=process.argv.find(a=>a.startsWith('--'+name+'='));if(!v)throw Error('Required '+name);return v.slice(name.length+3);};
const root=option('root'),destination=option('output'),base=path.basename(destination),id=option('id');
if(!/^\/home\/u\d+\/domains\/[a-z0-9.-]+\/public_html$/.test(root)||!/^kiwe-[a-z0-9-]+\.php$/.test(base)||!/^kiwe-[a-z0-9-]+$/.test(id))throw Error('Exact owner paths required');
const quote=(v)=>"'"+v.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
const owners=['Archive_Error','Portable_File_Inventory','Portable_Table_Definition'];
const manifest={};let body='';
for(const name of owners){const p='wp-content/mu-plugins/dsa/includes/Backup/'+name+'.php';let s=fs.readFileSync(path.join(repo,p),'utf8').replaceAll('\r\n','\n');manifest[p]=crypto.createHash('sha256').update(s).digest('hex');s=s.replace(/^<\?php\s+namespace DSA\\Backup;\s*/,'');body+='\nnamespace DSA\\Backup {\n'+s+'\n}\n';}
const fixtureArg=process.argv.find(v=>v.startsWith('--fixture='));const fixtureName=fixtureArg?fixtureArg.slice('--fixture='.length):'private-source-preflight.php';if(!['private-source-preflight.php','private-source-capacity.php'].includes(fixtureName))throw Error('Known read-only native fixture required');
const fixturePath='tools/release/'+fixtureName;let fixture=fs.readFileSync(path.join(repo,fixturePath),'utf8').replaceAll('\r\n','\n');manifest[fixturePath]=crypto.createHash('sha256').update(fixture).digest('hex');fixture=fixture.replace(/^<\?php\s+declare\(strict_types=1\);\s*/,'');
let source=`<?php\ndeclare(strict_types=1);\nnamespace {\nif(PHP_SAPI!=='cli'){http_response_code(404);exit;}\nini_set('display_errors','0');define('ABSPATH',${quote(root+'/')});\n}\n`+body;
const secondsArg=process.argv.find(a=>a.startsWith('--seconds='));
const seconds=secondsArg?Number(secondsArg.slice(10)):1650;
if(!Number.isInteger(seconds)||seconds<60||seconds>1650)throw Error('Audit budget must remain below the observed hosting wrapper');
const config={root,private:path.posix.dirname(root)+'/kiwe-private-backups/'+id,id,base,kind:fixtureName,seconds};
const runnerPath='tools/release/private-preflight-runner.php';
let runner=fs.readFileSync(path.join(repo,runnerPath),'utf8').replaceAll('\r\n','\n');
manifest[runnerPath]=crypto.createHash('sha256').update(runner).digest('hex');
runner=runner.replace(/^<\?php\s+declare\(strict_types=1\);\s*/,'').replace('__CONFIG__',quote(JSON.stringify(config))).replace('__MANIFEST__',quote(JSON.stringify(manifest))).replace('/*__FACTORY__*/',fixture);
source+='\nnamespace {\n'+runner+'\n}\n';
fs.writeFileSync(destination,source,{flag:'wx'});
console.log(JSON.stringify({path:destination,bytes:Buffer.byteLength(source),sha256:crypto.createHash('sha256').update(source).digest('hex'),owners:owners.length,wordpressBooted:false}));
