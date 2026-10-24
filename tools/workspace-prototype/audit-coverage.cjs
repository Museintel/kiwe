const fs=require('node:fs/promises'),path=require('node:path'),crypto=require('node:crypto');
const {pathToFileURL}=require('node:url');
const root=path.resolve(__dirname,'../..');
(async()=>{
 const out=path.resolve(process.argv[2]||'');if(!process.argv[2]||out===root||out.startsWith(root+path.sep))throw Error('Choose an output outside the repository.');
 const model=await import(pathToFileURL(path.join(__dirname,'workspace-model.mjs')));
 const owner='wp-content/mu-plugins/dsa/includes/Notifications/Notification_Preference_Service.php';
 const php=await fs.readFile(path.join(root,owner),'utf8'),section=php.slice(php.indexOf('private function topics()'),php.indexOf('private function categories('));
 const native=[...new Set([...section.matchAll(/'id'\s*=>\s*'([^']+)'/g)].map(match=>match[1]))].sort();
 const represented=model.notificationTopics.map(topic=>topic.id).sort();
 const coverage=JSON.parse(await fs.readFile(path.join(__dirname,'feature-coverage.json'),'utf8'));
 const mappings=JSON.parse(await fs.readFile(path.join(__dirname,'native-surface-map.json'),'utf8'));
 const inventory=require('./coverage-inventory.cjs').validate(root,coverage,mappings);
 const missingSources=[];for(const item of coverage)for(const source of item.sources)try{await fs.access(path.join(root,source));}catch{missingSources.push(source);}
 const result={scope:'Static built-in topic and feature coverage, not live capability or integration proof',passed:JSON.stringify(native)===JSON.stringify(represented)&&!missingSources.length,nativeTopicCount:native.length,nativeTopics:native,missingTopics:native.filter(id=>!represented.includes(id)),extraTopics:represented.filter(id=>!native.includes(id)),missingSources,topicSourceHash:crypto.createHash('sha256').update(php).digest('hex'),featureAreas:coverage,previewRoles:Object.fromEntries(Object.keys(model.roleNames).map(role=>[role,model.modulesFor(model.initial,role).map(module=>module.id)])),extensionBoundary:'Runtime filters can add further topics. Connected UI must consume the server catalog.'};
 result.inventory=inventory;result.passed=result.passed&&inventory.passed;
 result.launchReady=result.passed&&inventory.launchReady;
 await fs.mkdir(path.dirname(out),{recursive:true});await fs.writeFile(out,JSON.stringify(result,null,2));console.log(JSON.stringify({passed:result.passed,launchReady:result.launchReady,launchBlockers:inventory.launchBlockers.length,nativeTopicCount:native.length,featureAreas:coverage.length,out}));if(!result.passed)process.exitCode=1;else if(process.argv.includes('--require-launch')&&!result.launchReady)process.exitCode=2;
})();
