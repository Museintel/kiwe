import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import crypto from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const arg=name=>{const index=process.argv.indexOf(name);return index>=0?process.argv[index+1]:undefined;};
const php=arg('--php')||process.env.KIWE_PHP||'php',outArg=arg('--out');
if(!outArg)throw Error('Use --out with an external evidence directory.');
const out=path.resolve(outArg);
if(out===root||out.startsWith(root+path.sep))throw Error('Evidence must be outside the repository.');
fs.mkdirSync(out,{recursive:true});
const destination=fs.mkdtempSync(path.join(out,'preflight-')),scratch=fs.mkdtempSync(path.join(os.tmpdir(),'kiwe-preflight-'));
const env={...process.env,KIWE_PHP:php,PHP_BINARY:php,PATH:path.isAbsolute(php)?path.dirname(php)+path.delimiter+process.env.PATH:process.env.PATH};
const checks=[],failures=[],start=new Date().toISOString();
function files(dir){return fs.readdirSync(dir,{withFileTypes:true}).filter(item=>!['node_modules','.secrets','.git'].includes(item.name)).flatMap(item=>item.isDirectory()?files(path.join(dir,item.name)):item.isFile()?[path.join(dir,item.name)]:[]);}
function hash(file){return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');}
function sourceDigest(){const paths=[...files(path.join(root,'wp-content/mu-plugins')),...files(path.join(root,'tools/release')),...files(path.join(root,'tools/workspace-prototype')).filter(file=>!file.includes(path.sep+'node_modules'+path.sep))].sort();const digest=crypto.createHash('sha256');for(const file of paths)digest.update(path.relative(root,file)).update(hash(file));return digest.digest('hex');}
function run(label,command,args,parse=false){
 const time=Date.now(),r=spawnSync(command,args,{cwd:root,env,encoding:'utf8',windowsHide:true,maxBuffer:32*1024*1024});
 const text=(r.stdout||'')+(r.stderr||''),log=String(checks.length+1).padStart(2,'0')+'-'+label.replace(/[^a-z0-9]+/gi,'-').toLowerCase()+'.txt';
 fs.writeFileSync(path.join(destination,log),text);
 let parsed=null,passed=r.status===0&&!r.error&&!/(?:PHP )?(?:Fatal error|Parse error|Warning|Deprecated):/.test(text);
 if(parse){try{parsed=JSON.parse(r.stdout);passed=passed&&parsed.passed===true&&parsed.debugLogCreated!==true;}catch{passed=false;}}
 const record={label,passed,exit:r.status,durationMs:Date.now()-time,log,...(parsed?{proof:parsed}:{})};checks.push(record);if(!passed)failures.push(label);console.log((passed?'PASS ':'FAIL ')+label);return r.stdout;
}
const before=sourceDigest();let cleaned=false;
try{
 const version=spawnSync(php,['-r','echo PHP_VERSION;'],{cwd:root,env,encoding:'utf8'});
 if(version.status!==0||!/^8\.2\./.test(version.stdout))throw Error('This preflight requires an actual PHP 8.2 runtime.');
 console.log('PHP '+version.stdout);
 const phpFiles=files(path.join(root,'wp-content/mu-plugins')).filter(file=>file.endsWith('.php'));
 const lintFailures=[];for(const file of phpFiles){const r=spawnSync(php,['-l',file],{cwd:root,env,encoding:'utf8',windowsHide:true});if(r.status!==0)lintFailures.push({file:path.relative(root,file),output:(r.stdout||'')+(r.stderr||'')});}
 fs.writeFileSync(path.join(destination,'php-lint.json'),JSON.stringify({php:version.stdout,count:phpFiles.length,failures:lintFailures},null,2));
 checks.push({label:'All package PHP files parse on PHP 8.2',passed:lintFailures.length===0,files:phpFiles.length});if(lintFailures.length)failures.push('PHP 8.2 lint');
 const stage=path.join(scratch,'stage');fs.mkdirSync(stage);
 for(const file of ['dsa','dsa.php','kiwe-incident-guard.php'])fs.cpSync(path.join(root,'wp-content/mu-plugins',file),path.join(stage,file),{recursive:true});
 run('Signed metadata and current staged package',php,['tools/release/test-staged-release.php',stage],true);
 run('MU recovery and debug-off receipts',process.execPath,['tools/release/update-recovery-contracts.cjs'],true);
 run('Native workspace route registration',process.execPath,['tools/release/workspace-route-contracts.cjs']);
 run('Cached package proof has no warm writes',php,['tools/release/test-package-proof-refresh.php']);
 run('Full green baseline on PHP 8.2',process.execPath,['tools/release/verify-green-baseline.cjs']);
 run('Maintained documentation links',process.execPath,['tools/maintenance/check-maintained-doc-links.mjs']);
}catch(error){failures.push(error.message);}
finally{
 // This directory was uniquely created above, never derived from a site or user source path.
 const resolved=fs.realpathSync(scratch),temp=fs.realpathSync(os.tmpdir());
 if(path.dirname(resolved)===temp&&path.basename(resolved).startsWith('kiwe-preflight-')){fs.rmSync(resolved,{recursive:true,force:true});cleaned=!fs.existsSync(resolved);}
 const after=sourceDigest();if(before!==after)failures.push('Source changed during the preflight; evidence is inconclusive');if(!cleaned)failures.push('Disposable fixture cleanup incomplete');
 const manifest=JSON.parse(fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/package-manifest.json')));
 const report={schema:'kiwe.workspace-preflight.v1',startedAt:start,finishedAt:new Date().toISOString(),passed:failures.length===0,cleanup:cleaned,version:manifest.version,packageFiles:Object.keys(manifest.files).length,sourceBefore:before,sourceAfter:after,checks,failures,nativeHostAcceptance:false,currentPackageSigned:false,deployed:false,limitations:['Synthetic filesystem/WordPress I/O only; no live Woo/HPOS or provider proof.','No real hosting load, concurrent installer process or power-loss proof.','Historical signature proof does not sign the current local candidate.']};
 fs.writeFileSync(path.join(destination,'receipt.json'),JSON.stringify(report,null,2)+'\n');
 console.log(JSON.stringify({passed:report.passed,cleanup:cleaned,evidence:destination,failures}));if(!report.passed)process.exitCode=1;
}
