/* Explicit compiler-owned artifact projection, separate from Kiwe toolkit sync. */
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const repo=path.resolve(__dirname,'../..'),source=path.resolve(process.argv.find(a=>a.startsWith('--seam='))?.slice(7)||path.join(repo,'../seam.kiwe'));
const r=cp.spawnSync(process.execPath,['--import','tsx','tools/export-source-runtime.mts'],{cwd:source,encoding:'utf8',windowsHide:true,maxBuffer:4*1024*1024});
if(r.status!==0)throw Error('Canonical compiler export failed: '+r.stderr);
const out=path.join(repo,'wp-content/mu-plugins/dsa/assets/js/source-runtime.js');
if(process.argv.includes('--check')){if(!fs.existsSync(out)||fs.readFileSync(out,'utf8')!==r.stdout)throw Error('Compiler bridge projection drift');}
else fs.writeFileSync(out,r.stdout);
console.log(JSON.stringify({passed:true,checked:process.argv.includes('--check'),owner:'Seam app/runtimeBridge.ts',bytes:Buffer.byteLength(r.stdout)}));
