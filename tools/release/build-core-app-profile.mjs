#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const repo=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const arg=process.argv.find(s=>s.startsWith('--output-root='));if(!arg)throw Error('An explicit external --output-root is required.');const output=path.resolve(arg.slice(14));
if(output===repo||output.startsWith(repo+path.sep)||await fs.stat(output).catch(()=>null))throw Error('Use a new package directory outside source.');
const check=spawnSync(process.execPath,['tools/release/build-apps-ownership.cjs','--check'],{cwd:repo,encoding:'utf8',windowsHide:true});if(check.status!==0)throw Error('The reviewed ownership map must match source first.');
const ownership=JSON.parse(await fs.readFile(path.join(repo,'tools/release/apps-ownership.json'),'utf8'));
const bundled=(process.argv.find(s=>s.startsWith('--bundled-apps='))?.slice(15)||'').split(',').filter(Boolean);
if(bundled.some(id=>!['guest','ai','sitegraph'].includes(id))||new Set(bundled).size!==bundled.length)throw Error('Only the reviewed workspace bridge may retain new engines.');
const optional=new Set(Object.entries(ownership.apps).filter(([id])=>!bundled.includes(id)).flatMap(([,app])=>app.files));const source=path.join(repo,'wp-content/mu-plugins');const stage=path.join(output,'mu-plugins');
await fs.mkdir(stage,{recursive:true});
const textExtensions=new Set(['.css','.html','.js','.json','.md','.php','.svg','.txt','.xml']);
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
async function walk(root){const out=[];for(const entry of await fs.readdir(root,{withFileTypes:true})){const file=path.join(root,entry.name);if(entry.isSymbolicLink())throw Error('Package symlinks are refused.');if(entry.isDirectory())out.push(...await walk(file));else if(entry.isFile())out.push(file);else throw Error('Unsupported package member');}return out.sort();}
for(const file of [path.join(source,'dsa.php'),path.join(source,'kiwe-incident-guard.php'),...await walk(path.join(source,'dsa'))]){
 const relative=path.relative(source,file).split(path.sep).join('/');if(relative==='dsa/package-manifest.json'||optional.has(relative.replace(/^dsa\//,'')))continue;
 let body=await fs.readFile(file);if(textExtensions.has(path.extname(file)))body=Buffer.from(body.toString('utf8').replace(/\r\n?/g,'\n'));
 if(relative==='dsa/includes/Apps/Package_Layout.php'){const text=body.toString();if(text.split('public const BUNDLED = true; // KIWE_APPS_BUNDLED_PROFILE').length!==2)throw Error('The package layout marker is ambiguous.');body=Buffer.from(text.replace('public const BUNDLED = true; // KIWE_APPS_BUNDLED_PROFILE','public const BUNDLED = false; // KIWE_APPS_BUNDLED_PROFILE').replace('public const BUNDLED_APPS = []; // KIWE_APPS_PARTIAL_PROFILE',"public const BUNDLED_APPS = ["+bundled.map(id=>"'"+id+"'").join(',')+"]; // KIWE_APPS_PARTIAL_PROFILE"));}
 const destination=path.join(stage,relative);await fs.mkdir(path.dirname(destination),{recursive:true});await fs.writeFile(destination,body,{flag:'wx'});
}
const version=(await fs.readFile(path.join(stage,'dsa/dsa.php'),'utf8')).match(/define\(\s*'DSA_VERSION',\s*'([^']+)'\s*\)/)?.[1];if(!version)throw Error('Core version is missing.');
const files={};for(const file of await walk(path.join(stage,'dsa'))){const body=await fs.readFile(file);files[path.relative(path.join(stage,'dsa'),file).split(path.sep).join('/')]={bytes:body.length,sha256:hash(body)};}
for(const file of optional)if(files[file])throw Error('An App-owned file leaked into Core: '+file);
const manifest={schema:1,version,algorithm:'sha256',file_count:Object.keys(files).length,apps:{profile:'core',coreApi:bundled.length?1:ownership.coreApi,minimum:Object.fromEntries(Object.entries(ownership.apps).filter(([id])=>!bundled.includes(id)).map(([id,app])=>[id,app.minimumVersion]))},files};
await fs.writeFile(path.join(stage,'dsa/package-manifest.json'),JSON.stringify(manifest,null,2)+'\n');
const archive=path.join(output,'kiwe-core.zip');const zip=spawnSync('python',['-c',"import pathlib,sys,zipfile\nr=pathlib.Path(sys.argv[1]);o=sys.argv[2]\nwith zipfile.ZipFile(o,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:\n for f in sorted(r.rglob('*')):\n  if f.is_file():\n   n=zipfile.ZipInfo(str(f.relative_to(r)).replace('\\\\','/'),(2026,1,1,0,0,0));n.create_system=3;n.external_attr=0o100644<<16;n.compress_type=zipfile.ZIP_DEFLATED;z.writestr(n,f.read_bytes())",stage,archive],{encoding:'utf8',windowsHide:true});if(zip.status!==0)throw Error('Core ZIP failed: '+zip.stderr);
const body=await fs.readFile(archive);const proof={passed:true,schema:'kiwe.core-app-profile-build.v1',version,files:Object.keys(files).length,excludedAppFiles:optional.size,bundledApps:bundled,bytes:body.length,sha256:hash(body),packageManifestSha256:hash(await fs.readFile(path.join(stage,'dsa/package-manifest.json')))};
await fs.writeFile(path.join(output,'build.json'),JSON.stringify(proof,null,2)+'\n');console.log(JSON.stringify(proof));
