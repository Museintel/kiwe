/* The compiler owns this registry. Snapshot only a committed, explicitly selected revision. */
const fs=require('node:fs'), path=require('node:path'), cp=require('node:child_process');
const root=path.resolve(__dirname,'../..'), compiler=path.resolve(root,'../seam.kiwe');
const revision=process.argv.find(a=>a.startsWith('--ref='))?.slice(6);
if(!/^[a-f0-9]{40}$/.test(revision||'')) throw new Error('Select a complete committed compiler SHA with --ref=.');
const source='app/bricksCapabilities.ts';
const text=cp.execFileSync('git',['show',`${revision}:${source}`],{cwd:compiler,encoding:'utf8'});
const strings=s=>Array.from(s.matchAll(/"([a-z0-9-]+)"/g),m=>m[1]);
const names=strings(text.match(/const elementNames = \[([\s\S]*?)\] as const;/)?.[1]||'');
const inferred=new Set(strings(text.match(/const inferred = new Set\(\[([\s\S]*?)\]\);/)?.[1]||''));
const fallback=new Set(strings(text.match(/const fallback = new Set\(\[([\s\S]*?)\]\);/)?.[1]||''));
if(names.length<100||new Set(names).size!==names.length||!inferred.size)throw new Error('Compiler registry shape changed; review the snapshot adapter.');
const value={schema:'kiwe.compiler-elements-reference.v1',repository:'Museintel/seam-compiler',revision,source,sourceSha256:require('node:crypto').createHash('sha256').update(text.replace(/\r\n?/g,'\n')).digest('hex'),bricksVersion:text.match(/bricksVersion: "([^"]+)"/)?.[1],registryVersion:text.match(/registryVersion: "([^"]+)"/)?.[1],policy:'Registered means available in the source inventory, not automatic conversion or target verification. Current target calibration/SiteGraph is required. This reference grants no executable-code authority.',elements:names.map(name=>({name,status:inferred.has(name)?'inferred':fallback.has(name)?'fallback':'registered'}))};
const target=path.join(root,'kiwe-ai-toolkit/reference/bricks-elements.json');
const output=JSON.stringify(value,null,2)+'\n';
if(process.argv.includes('--check')){if(fs.readFileSync(target,'utf8').replace(/\r\n?/g,'\n')!==output)throw new Error('Compiler reference drift.');}
else{fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,output);}
console.log(JSON.stringify({passed:true,elements:names.length,revision}));
