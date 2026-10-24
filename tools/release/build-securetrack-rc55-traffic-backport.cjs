/** Reproducible production backport from canonical RC55 plus the four reviewed RC81 SecureTrack files. */
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),cp=require('node:child_process');
const root=path.resolve(__dirname,'../..');
const reviewedSourceHashes={
  "wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Db_Service.php": "c1d1e06937f5dc7179cff235087cf258b7cb47a54fb6aea7abce60e202b00bab",
  "wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Event_Service.php": "ace4996adf90072cffa0ac94134af86ec376e9e8ccd6f489c6815c9b23521421",
  "wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php": "097f16666392aa0f356bc1547608a02a26aa1bd07eb85052ab10bc17f1d4f0e4",
  "wp-content/mu-plugins/dsa/includes/Secure/securetrack-admin-core.php": "3d54eff2e88795be6c81b97c2b8ca1f341b4fa755b15d4b7d4518bb4972f2bec"
};
for(const [file,expected] of Object.entries(reviewedSourceHashes)){const actual=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file),'utf8').replace(/\r\n?/g,'\n')).digest('hex');if(actual!==expected)throw Error('Reviewed backport source changed: '+file+'. Prepare a new immutable backport version.');}
const out=process.argv[2];if(!out||!path.isAbsolute(out)||fs.existsSync(out))throw Error('Provide a new absolute output directory.');
const base=cp.execFileSync('git',['rev-parse','19634ec^{commit}'],{cwd:root,encoding:'utf8'}).trim();
const version='8.0.0-rc.55.3';fs.mkdirSync(out,{recursive:true});
const checkout=path.join(out,'source');fs.mkdirSync(checkout);
const env={...process.env,GIT_INDEX_FILE:path.join(out,'export.index')};
cp.execFileSync('git',['read-tree',base],{cwd:root,env});
cp.execFileSync('git',['checkout-index','--all','--prefix='+checkout.replaceAll('\\','/')+'/'],{cwd:root,env});
const changed=['SecureTrack_Db_Service.php','SecureTrack_Event_Service.php','securetrack-core.php','securetrack-admin-core.php'].map(n=>'wp-content/mu-plugins/dsa/includes/Secure/'+n);
const hashes={};
for(const p of changed){const b=fs.readFileSync(path.join(root,p));fs.writeFileSync(path.join(checkout,p),b);hashes[p]=crypto.createHash('sha256').update(b).digest('hex');}
for(const p of ['wp-content/mu-plugins/dsa.php','wp-content/mu-plugins/dsa/dsa.php']){const f=path.join(checkout,p);fs.writeFileSync(f,fs.readFileSync(f,'utf8').replaceAll('8.0.0-rc.55',version));}
for(const p of ['tools/runtime/test-securetrack-telemetry.php','tools/runtime/securetrack-telemetry-behavior.cjs','tools/runtime/test-securetrack-worker-budget.php','tools/runtime/securetrack-worker-budget.cjs','tools/release/test-wordpress-securetrack-telemetry.php','tools/release/test-securetrack-schema.php','tools/release/securetrack-schema-contracts.cjs','tools/release/test-wordpress-securetrack-schema.php','tools/runtime/securetrack-publish-performance-contracts.cjs','tools/release/build-folder-copy-archive.ps1'])fs.copyFileSync(path.join(root,p),path.join(checkout,p));
const changelog=path.join(checkout,'CHANGELOG.md');fs.writeFileSync(changelog,`# ${version} — targeted SecureTrack traffic protection\n\nRC55 plus reviewed RC81 SecureTrack files: retained schema repair prevention, optional-telemetry gates and immediate severe-request denial. PhoneKey and IP-trust implementations are unchanged; no pending workspace/AI features included.\n\n`+fs.readFileSync(changelog,'utf8'));
const baseline=path.join(checkout,'tools/release/verify-green-baseline.cjs');fs.writeFileSync(baseline,fs.readFileSync(baseline,'utf8').replace('const gates = [',"const gates = [\n\t['Optional telemetry behavior', 'tools/runtime/securetrack-telemetry-behavior.cjs'],\n\t['Denial worker budget', 'tools/runtime/securetrack-worker-budget.cjs'],\n\t['Explicit SecureTrack schema maintenance', 'tools/release/securetrack-schema-contracts.cjs'],"));
for(const script of ['packages/seam-contracts/tools/generate-types.cjs','tools/release/build-package-manifest.cjs','tools/release/verify-package.cjs'])cp.execFileSync(process.execPath,[script],{cwd:checkout,stdio:'pipe'});
const baseManifest=JSON.parse(cp.execFileSync('git',['show',base+':wp-content/mu-plugins/dsa/package-manifest.json'],{cwd:root,encoding:'utf8'}));
const manifest=JSON.parse(fs.readFileSync(path.join(checkout,'wp-content/mu-plugins/dsa/package-manifest.json')));
const actual=Object.keys(manifest.files).filter(p=>JSON.stringify(manifest.files[p])!==JSON.stringify(baseManifest.files[p]));
const allowed=['dsa.php',...changed.map(p=>p.replace('wp-content/mu-plugins/dsa/',''))];
if(manifest.file_count!==baseManifest.file_count||actual.length!==allowed.length||actual.some(p=>!allowed.includes(p)))throw Error('Unexpected runtime drift in backport.');
const receipt={version,base,sourceDirectory:checkout,files:manifest.file_count,changedPackageFiles:actual,secureTrackSourceHashes:hashes,rootLoaderVersionOnly:true,signed:false,deployed:false};
fs.writeFileSync(path.join(out,'build.json'),JSON.stringify(receipt,null,2)+'\n');console.log(JSON.stringify(receipt));
