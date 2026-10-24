const fs=require('node:fs'),path=require('node:path');
const prefix='wp-content/mu-plugins/dsa/includes/';
function discover(root){
 const surfaces=[];
 function walk(dir){for(const entry of fs.readdirSync(dir,{withFileTypes:true})){const file=path.join(dir,entry.name);if(entry.isDirectory())walk(file);else if(entry.name.endsWith('.php')){
   const source=fs.readFileSync(file,'utf8');
   if(!/\badd_(?:sub)?menu_page\s*\(/.test(source))continue;
   const relative=path.relative(root,file).replaceAll('\\','/');
   // Includes render callbacks behind section loops/hidden admin pages, not just visible menu labels.
   const callbacks=[...new Set([...source.matchAll(/public\s+function\s+(render[a-zA-Z0-9_]*)\s*\(/g)].map(m=>m[1]))].sort();
   surfaces.push({source:relative,callbacks});
 }}}
 walk(path.join(root,prefix));return surfaces.sort((a,b)=>a.source.localeCompare(b.source));
}
function validate(root,features,mappings){
 const errors=[],ids=new Set(),allowed=['sample','partial','missing','external','future','verified'];
 for(const feature of features){
   if(!feature.id||ids.has(feature.id))errors.push('Missing/duplicate feature id: '+feature.id);ids.add(feature.id);
   if(!allowed.includes(feature.status)||!['client','visitor','owner','ecosystem','future'].includes(feature.audience))errors.push('Invalid feature disposition: '+feature.id);
   if(!feature.owner||!feature.nextAcceptance||!Array.isArray(feature.routes)||typeof feature.launchRequired!=='boolean')errors.push('Missing task ownership/acceptance: '+feature.id);
   if(feature.status==='verified'&&(!feature.nativeEvidence||!feature.browserEvidence))errors.push('Verified feature needs native and browser receipts: '+feature.id);
   if(feature.status==='future'&&feature.launchRequired)errors.push('Future feature cannot silently satisfy launch: '+feature.id);
   for(const source of feature.sources)if(!fs.existsSync(path.join(root,source)))errors.push('Missing source: '+source);
 }
 const discovered=discover(root),known=new Map();
 for(const mapping of mappings){
   if(known.has(mapping.source))errors.push('Duplicate surface mapping: '+mapping.source);known.set(mapping.source,mapping);
   if(!mapping.features?.length||mapping.features.some(id=>!ids.has(id)))errors.push('Unknown feature mapping: '+mapping.source);
   if(!mapping.retainedAccess||!mapping.reviewBoundary)errors.push('Missing retained access/boundary: '+mapping.source);
 }
 for(const surface of discovered){const mapping=known.get(surface.source);if(!mapping)errors.push('Unmapped native admin owner: '+surface.source);else if(JSON.stringify(surface.callbacks)!==JSON.stringify(mapping.callbacks))errors.push('Native callback inventory changed; review controls: '+surface.source);}
 for(const source of known.keys())if(!discovered.some(item=>item.source===source))errors.push('Native admin owner removed; review feature retention: '+source);
 const blockers=features.filter(item=>item.launchRequired&&item.status!=='verified').map(item=>({id:item.id,area:item.area,status:item.status,nextAcceptance:item.nextAcceptance}));
 return {passed:!errors.length,errors,launchReady:!errors.length&&!blockers.length,launchBlockers:blockers,nativeAdminOwners:discovered.length,nativeRenderCallbacks:discovered.reduce((sum,item)=>sum+item.callbacks.length,0),scope:'Built-in admin owner/render callback and declared feature inventory. Not exhaustive field, runtime filter or third-party integration discovery.'};
}
module.exports={discover,validate};
