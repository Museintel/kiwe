#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const repo=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const args=Object.fromEntries(process.argv.slice(2).map(v=>{const n=v.indexOf('=');return[v.slice(0,n),v.slice(n+1)];}));
if(!args['--profile-root']||!args['--output-root'])throw Error('Explicit external profile and release roots are required.');
const profile=path.resolve(args['--profile-root']),output=path.resolve(args['--output-root']);
for(const root of[profile,output])if(root===repo||root.startsWith(repo+path.sep))throw Error('Release custody must be outside source.');
const source=path.join(repo,'wp-content/mu-plugins'),stage=path.join(profile,'mu-plugins');
const ownership=JSON.parse(await fs.readFile(path.join(repo,'tools/release/apps-ownership.json'),'utf8'));
const optional=new Set(Object.values(ownership.apps).flatMap(v=>v.files));
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
const text=new Set(['.css','.html','.js','.json','.md','.php','.svg','.txt','.xml']);
async function files(root){const all=[];for(const entry of await fs.readdir(root,{withFileTypes:true})){const file=path.join(root,entry.name);if(entry.isSymbolicLink())throw Error('Symlinks cannot authorize a release.');if(entry.isDirectory())all.push(...await files(file));else if(entry.isFile())all.push(file);else throw Error('Unsupported source member.');}return all.sort();}
const expected=new Map();
for(const file of[path.join(source,'dsa.php'),path.join(source,'kiwe-incident-guard.php'),...await files(path.join(source,'dsa'))]){
 const name=path.relative(source,file).split(path.sep).join('/');
 if(name==='dsa/package-manifest.json'||optional.has(name.replace(/^dsa\//,'')))continue;
 let body=await fs.readFile(file);if(text.has(path.extname(file)))body=Buffer.from(body.toString('utf8').replace(/\r\n?/g,'\n'));
 if(name==='dsa/includes/Apps/Package_Layout.php'){
  const marker='public const BUNDLED = true; // KIWE_APPS_BUNDLED_PROFILE';
  if(body.toString().split(marker).length!==2)throw Error('The Core profile marker is ambiguous.');
  body=Buffer.from(body.toString().replace(marker,'public const BUNDLED = false; // KIWE_APPS_BUNDLED_PROFILE'));
 }
 expected.set(name,body);
}
const manifestBody=await fs.readFile(path.join(stage,'dsa/package-manifest.json'));
const manifest=JSON.parse(manifestBody),build=JSON.parse(await fs.readFile(path.join(profile,'build.json'),'utf8'));
if(manifest.schema!==1||manifest.algorithm!=='sha256'||manifest.apps?.profile!=='core'||manifest.apps?.coreApi!==1||manifest.version!==build.version||manifest.file_count!==Object.keys(manifest.files).length)throw Error('A complete Core App profile is required.');
for(const id of Object.keys(ownership.apps))if(manifest.apps.minimum?.[id]!==ownership.apps[id].minimumVersion||!/^\d+\.\d+\.\d+(?:-(?:rc|beta|alpha)\.[1-9]\d*)?$/.test(manifest.apps.minimum?.[id]||''))throw Error('Every App minimum needs an exact compatible version.');
for(const[name,body]of expected){
 const actual=await fs.readFile(path.join(stage,name));if(!actual.equals(body))throw Error('The profile differs from current canonical source: '+name);
 if(name.startsWith('dsa/')){const proof=manifest.files[name.slice(4)];if(proof?.sha256!==hash(body)||proof.bytes!==body.length)throw Error('The inner manifest does not commit this Core file: '+name);}
}
expected.set('dsa/package-manifest.json',manifestBody);
if((await files(stage)).length!==expected.size||Object.keys(manifest.files).length!==expected.size-3)throw Error('Core contains extra or missing members.');
for(const [name,constant]of[['dsa.php','KIWE_MU_LOADER_VERSION'],['dsa/dsa.php','DSA_VERSION']])if(!expected.get(name).toString().includes(`'${constant}', '${manifest.version}'`))throw Error('Core loader versions differ.');
const archive=path.join(profile,'kiwe-core.zip');
const audit=spawnSync(args['--python']||'python',['-c',"import pathlib,sys,zipfile,stat\nr=pathlib.Path(sys.argv[1]);z=zipfile.ZipFile(sys.argv[2]);expected={str(p.relative_to(r)).replace('\\\\','/'):p.read_bytes() for p in r.rglob('*') if p.is_file()}\nassert len(z.infolist())==len(expected)\nseen=set()\nfor f in z.infolist():\n assert f.filename in expected and f.filename not in seen and not f.flag_bits&1 and stat.S_IFMT(f.external_attr>>16) in (0,stat.S_IFREG)\n assert z.read(f)==expected[f.filename]\n seen.add(f.filename)\nassert seen==set(expected)",stage,archive],{encoding:'utf8',windowsHide:true});
if(audit.status!==0)throw Error('The exact Core ZIP does not match its independently checked stage.');
const bytes=await fs.readFile(archive);
if(build.sha256!==hash(bytes)||build.bytes!==bytes.length||build.packageManifestSha256!==hash(manifestBody))throw Error('The Core build receipt changed.');
const key=crypto.createPrivateKey(String(process.env.KIWE_UPDATE_ED25519_PRIVATE_PEM||''));
if(key.asymmetricKeyType!=='ed25519'||crypto.createPublicKey(key).export({type:'spki',format:'der'}).subarray(-32).toString('base64')!=='v6qwuL3+Zqx76G5A1MCqkvnzOM62fyXudc8OCgXqKzg=')throw Error('The original release custodian is required.');
const channel=args['--channel']||'candidate';if(!['candidate','stable'].includes(channel))throw Error('Unsupported channel.');
const release={schema:'kiwe.mu-release.v1',version:manifest.version,channel,publishedAt:new Date().toISOString(),packageUrl:`https://app.kiwelaunch.com/updates/kiwe/v1/releases/${manifest.version}/kiwe-mu-plugin.zip`,sha256:hash(bytes),bytes:bytes.length,requiresPhp:'8.2',requiresWp:'7.1',packageManifestSha256:hash(manifestBody),keyId:'kiwe-release-2026-01'};
const payload=['schema','version','channel','publishedAt','packageUrl','sha256','bytes','requiresPhp','requiresWp','packageManifestSha256','keyId'].map(k=>String(release[k])).join('\n')+'\n';
release.signature=crypto.sign(null,Buffer.from(payload),key).toString('base64');
const destination=path.join(output,'releases',release.version);if(await fs.stat(destination).catch(()=>null))throw Error('This immutable Core release already exists.');
await fs.mkdir(destination,{recursive:true});await fs.writeFile(path.join(destination,'kiwe-mu-plugin.zip'),bytes,{flag:'wx'});await fs.writeFile(path.join(destination,'manifest.json'),JSON.stringify(release,null,2)+'\n',{flag:'wx'});
await fs.writeFile(path.join(output,'core-'+channel+'.json'),JSON.stringify(release,null,2)+'\n');
console.log(JSON.stringify({passed:true,profile:'core',version:release.version,channel,files:expected.size,excludedAppFiles:optional.size,bytes:release.bytes,sha256:release.sha256}));
