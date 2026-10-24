#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const repo=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const args=Object.fromEntries(process.argv.slice(2).map(s=>{const i=s.indexOf('=');return[s.slice(0,i),s.slice(i+1)];}));
const output=args['--output-root']&&path.resolve(args['--output-root']),version=args['--version'],channel=args['--channel']||'candidate',renew=args['--renew-only']==='true';
if(!output||(!renew&&!/^\d+\.\d+\.\d+(?:-(?:rc|beta|alpha)\.[1-9]\d*)?$/.test(version||''))||!['stable','candidate'].includes(channel))throw Error('Use explicit --output-root, --version and --channel.');
if(output===repo||output.startsWith(repo+path.sep))throw Error('Signed build artifacts belong outside the working source.');
const ownership=JSON.parse(await fs.readFile(path.join(repo,'tools/release/apps-ownership.json'),'utf8'));
const privateKey=crypto.createPrivateKey(String(process.env.KIWE_UPDATE_ED25519_PRIVATE_PEM||''));
const publicKey=crypto.createPublicKey(privateKey).export({type:'spki',format:'der'}).subarray(-32).toString('base64');
if(privateKey.asymmetricKeyType!=='ed25519'||publicKey!=='v6qwuL3+Zqx76G5A1MCqkvnzOM62fyXudc8OCgXqKzg=')throw Error('The original release custodian key is required.');
const keyId='kiwe-release-2026-01',origin='https://app.kiwelaunch.com';
const canonical=v=>JSON.stringify(Array.isArray(v)?v.map(normalize):normalize(v));
function normalize(v){if(Array.isArray(v))return v.map(normalize);if(v!==null&&typeof v==='object')return Object.fromEntries(Object.keys(v).sort().map(k=>[k,normalize(v[k])]));return v;}
function sign(v){return {...v,signature:crypto.sign(null,Buffer.from(canonical(v)),privateKey).toString('base64')};}
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
let timestamp=new Date().toISOString().replace(/\.\d{3}Z$/,'Z');
const selected=renew?'renew':(args['--app']||'all');if(!['all','renew',...Object.keys(ownership.apps)].includes(selected))throw Error('Choose an owned App or all.');
const publicKeyObject=crypto.createPublicKey(privateKey);
function authenticated(value){if(!value||typeof value!=='object'||value.keyId!==keyId||typeof value.signature!=='string')return false;const payload={...value};delete payload.signature;try{return crypto.verify(null,Buffer.from(canonical(payload)),publicKeyObject,Buffer.from(value.signature,'base64'));}catch{return false;}}
let prior=null;try{prior=JSON.parse(await fs.readFile(path.join(output,'channels',channel+'.json'),'utf8'));}catch(error){if(error.code!=='ENOENT')throw error;}
if(prior&&(!authenticated(prior)||prior.schema!=='kiwe.apps-catalog.v1'||prior.channel!==channel||prior.coreApi!==ownership.coreApi||Date.parse(prior.publishedAt)>Date.now()+300000||Object.entries(prior.apps).some(([id,r])=>!ownership.apps[id]||r.id!==id||r.channel!==channel||!authenticated(r))))throw Error('The previous catalogue and each retained App must authenticate.');
if(renew&&(!prior||!Object.keys(prior.apps).length))throw Error('Renewal requires the previous authenticated App catalogue.');
if(prior&&Date.parse(timestamp)<=Date.parse(prior.publishedAt))timestamp=new Date(Date.parse(prior.publishedAt)+1000).toISOString().replace(/\.\d{3}Z$/,'Z');
const releases=selected==='all'?{}:{...(prior?.apps||{})};
const revoked=[...new Set([...(prior?.revoked||[]),...(args['--revoke']||'').split(',').filter(Boolean)])].sort();
if(revoked.length>32||revoked.some(v=>!/^(?:seo|store)@[0-9]+\.[0-9]+\.[0-9]+(?:-(?:rc|beta|alpha)\.[1-9][0-9]*)?$/.test(v)))throw Error('Only exact owned App versions may be revoked.');
for(const[id,app]of Object.entries(ownership.apps)){
 if(selected!=='all'&&selected!==id)continue;
 const root=path.join(output,'releases',id,version),stage=path.join(root,'source','kiwe-'+id);
 if(await fs.stat(root).catch(()=>null))throw Error('An immutable App version already exists: '+id+'@'+version);
 await fs.mkdir(stage,{recursive:true});
 const provider={schema:'kiwe.app-provider.v1',id,version,coreApi:ownership.coreApi,requiresPhp:app.requiresPhp,requiresWp:app.requiresWp,requiresWoo:app.requiresWoo,namespaces:app.namespaces,classes:app.classes,assets:Object.fromEntries(app.files.filter(n=>n.startsWith('assets/')).map(n=>[n,n]))};
 const bootstrap=`<?php\n/**\n * Plugin Name: ${app.name}\n * Description: Optional ${id==='store'?'commerce':'search'} engine for Kiwe Core.\n * Version: ${version}\n * Requires at least: ${app.requiresWp}\n * Requires PHP: ${app.requiresPhp}\n${id==='store'?' * Requires Plugins: woocommerce\n':''} * Update URI: ${origin}/apps/v1/${id}\n */\nif (!defined('ABSPATH')) exit;\n// Core is an MU plugin. A pre-contract Core keeps its bundled engine until migration.\nif (!class_exists('DSA\\\\Apps\\\\Runtime_Registry')) return;\n$kiwe_app_provider = json_decode((string)file_get_contents(__DIR__.'/provider.json'),true);\nif (is_array($kiwe_app_provider)) \\DSA\\Apps\\Runtime_Registry::register($kiwe_app_provider,__DIR__);\nunset($kiwe_app_provider);\n`;
 const bodies=new Map([['kiwe-'+id+'.php',Buffer.from(bootstrap)],['provider.json',Buffer.from(JSON.stringify(provider,null,2)+'\n')]]);
 for(const name of app.files){if(!/^[A-Za-z0-9_][A-Za-z0-9_/.\-]+$/.test(name)||name.split('/').some(p=>['.','..'].includes(p)))throw Error('Invalid ownership path');const body=await fs.readFile(path.join(repo,'wp-content/mu-plugins/dsa',name),'utf8');bodies.set(name,Buffer.from(body.replace(/\r\n?/g,'\n')));}
 const inventory={schema:'kiwe.app-files.v1',id,version,coreApi:ownership.coreApi,files:Object.fromEntries([...bodies.entries()].sort(([a],[b])=>a<b?-1:1).map(([name,body])=>[name,{bytes:body.length,sha256:hash(body)}]))};
 const manifest=Buffer.from(JSON.stringify(inventory,null,2)+'\n');bodies.set('kiwe-app-manifest.json',manifest);
 for(const[name,body]of bodies){const file=path.join(stage,name);await fs.mkdir(path.dirname(file),{recursive:true});await fs.writeFile(file,body,{flag:'wx'});}
 const archive=path.join(root,'kiwe-'+id+'.zip');
 const zip=spawnSync(args['--python']||'python',['-c',"import pathlib,sys,zipfile\nr=pathlib.Path(sys.argv[1]);o=sys.argv[2]\nwith zipfile.ZipFile(o,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:\n for f in sorted(r.rglob('*')):\n  if f.is_file():\n   n=zipfile.ZipInfo(str(f.relative_to(r.parent)).replace('\\\\','/'),(2026,1,1,0,0,0));n.create_system=3;n.external_attr=0o100644<<16;n.compress_type=zipfile.ZIP_DEFLATED;z.writestr(n,f.read_bytes())",stage,archive],{encoding:'utf8',windowsHide:true});
 if(zip.status!==0)throw Error('Deterministic ZIP build failed: '+zip.stderr);
 const body=await fs.readFile(archive);const release=sign({schema:'kiwe.app-release.v1',id,version,channel,coreApi:ownership.coreApi,requiresPhp:app.requiresPhp,requiresWp:app.requiresWp,requiresWoo:app.requiresWoo,packageUrl:`${origin}/apps/v1/releases/${id}/${version}/kiwe-${id}.zip`,sha256:hash(body),bytes:body.length,packageManifestSha256:hash(manifest),publishedAt:timestamp,keyId});
 await fs.writeFile(path.join(root,'release.json'),JSON.stringify(release,null,2)+'\n',{flag:'wx'});releases[id]=release;
}
for(const[id,r]of Object.entries(releases))if(revoked.includes(id+'@'+r.version))throw Error('A revoked release cannot remain an offered App.');
const expiresAt=new Date(Date.parse(timestamp)+7*86400000).toISOString().replace(/\.\d{3}Z$/,'Z');
const catalogue=sign({schema:'kiwe.apps-catalog.v1',channel,coreApi:ownership.coreApi,publishedAt:timestamp,expiresAt,apps:releases,revoked,keyId});
await fs.mkdir(path.join(output,'channels'),{recursive:true});await fs.writeFile(path.join(output,'channels',channel+'.json'),JSON.stringify(catalogue,null,2)+'\n');
console.log(JSON.stringify({passed:true,channel,version,renewed:renew,updated:renew?[]:(selected==='all'?Object.keys(ownership.apps):[selected]),apps:Object.fromEntries(Object.entries(releases).map(([id,r])=>[id,{bytes:r.bytes,sha256:r.sha256,files:ownership.apps[id].files.length+3}]))}));
