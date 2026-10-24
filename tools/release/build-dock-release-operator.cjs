const fs=require('fs'),path=require('path');
const [configPath,outPath]=process.argv.slice(2);if(!configPath||!outPath)throw Error('Pass private config and output paths.');
const c=JSON.parse(fs.readFileSync(configPath,'utf8'));
for(const key of ['executionRoot','root','private'])if(typeof c[key]!=='string'||!/^\/home\/u[0-9]+\/domains\/[a-z0-9.-]+\/[a-zA-Z0-9._/-]+$/.test(c[key])||c[key].split('/').includes('..'))throw Error('Expected bounded absolute hosting '+key);
if(!/^[a-z0-9.-]+$/.test(c.domain)||!c.root.endsWith('/'+c.domain+'/public_html')||path.posix.dirname(c.private)!==path.posix.dirname(c.root)||!/^kiwe-dock-[a-z0-9-]+\.php$/.test(c.helper)||!/^kiwe-dock-[a-z0-9-]+\.zip$/.test(c.archive)||!(c.configHash==='capture'||/^[a-f0-9]{64}$/.test(c.configHash)))throw Error('Named target binding refused');
if(c.test&&c.domain!=='plum-giraffe-539524.hostingersite.com')throw Error('Mutating native acceptance is lab-only.');
if(c.prepareCoreCartCapture!==undefined&&(c.prepareCoreCartCapture!==true||c.release?.version!=='8.0.0-rc.392'||!['plum-giraffe-539524.hostingersite.com','dharwadpedha.com','nationalchikki.com'].includes(c.domain)))throw Error('Core cart preparation requires the exact Daily108 candidate and named target.');
if(c.prepareCoreAppTracking!==undefined&&(c.prepareCoreAppTracking!==true||!['8.0.0-rc.404','8.0.0-rc.405','8.0.0-rc.406','8.0.0-rc.407','8.0.0-rc.408','8.0.0-rc.409','8.0.0-rc.410','8.0.0-rc.411'].includes(c.release?.version)||!['plum-giraffe-539524.hostingersite.com','dharwadpedha.com','nationalchikki.com'].includes(c.domain)))throw Error('App schema preparation requires a named accepted App candidate and target.');
if(c.supersededHelper&&(!c.test||!/^kiwe-dock-[a-z0-9-]+\.php$/.test(c.supersededHelper)||c.supersededHelper===c.helper))throw Error('Expected exact previous lab helper.');
if(c.restoreLabLabelOrder&&(!c.test||c.restoreLabLabelOrder!==true))throw Error('Serialization recovery is lab-only.');
if(c.resumeCompilerBookkeepingReview&&(!c.test||c.resumeCompilerBookkeepingReview!==true||c.nativeProofFile!=='test-wordpress-compiler-import.php'||c.restoreLabLabelOrder))throw Error('Native compiler bookkeeping review is a separate lab-only fixture amendment.');
if(c.acceptanceAttempt&&(!c.test||!/^[a-z][a-z0-9-]{0,35}$/.test(c.acceptanceAttempt)||c.restoreLabLabelOrder||c.resumeCompilerBookkeepingReview))throw Error('A named lab acceptance attempt must retain its own immutable receipt.');
if(c.recoverCompilerJobs&&(!c.test||c.nativeProofFile!=='test-wordpress-compiler-import.php'||!Array.isArray(c.recoverCompilerJobs)||c.recoverCompilerJobs.length>8||c.recoverCompilerJobs.some(j=>!/^[a-f0-9]{32}$/.test(j.id)||!/^sha256:[a-f0-9]{64}$/.test(j.sourceHash))))throw Error('Only exact named synthetic compiler reviews can be retired.');
if(c.recoverReviewOnlyFast&&(!c.test||c.recoverReviewOnlyFast!==true||!c.recoverCompilerJobs||!c.acceptanceAttempt))throw Error('Small-state review retirement requires exact lab recovery scope and a distinct receipt.');
if(c.retireCandidateRoots&&(!c.test||c.nativeProofFile!=='test-wordpress-compiler-import.php'||!Array.isArray(c.retireCandidateRoots)||c.retireCandidateRoots.length>3||c.retireCandidateRoots.some(n=>!/^kiwe-private-sc103-runtime-rc(?:385|386|387|388)$/.test(n))))throw Error('Only this compiler run’s verified superseded staging roots may retire.');
if(c.retainArchive&&(!c.test||c.retainArchive!==true))throw Error('Archive retention is only a temporary lab-to-live handoff.');
if(c.retainHelper&&(!c.liveTarget||!c.test||!c.retainArchive||c.retainHelper!==true))throw Error('Helper retention requires the explicit lab-to-live handoff.');
if(c.liveTarget){
 const live=c.liveTarget;
 if(!c.test||c.domain!=='plum-giraffe-539524.hostingersite.com'||live.domain!=='dharwadpedha.com'||live.root!=='/home/u406734797/domains/dharwadpedha.com/public_html'||path.posix.dirname(live.private)!==path.posix.dirname(live.root)||!/^\/home\/u406734797\/domains\/dharwadpedha\.com\/[a-zA-Z0-9._-]+$/.test(live.private)||! /^[a-f0-9]{64}$/.test(live.configHash)||live.test!==false||live.retainArchive!==false||live.retainHelper!==false||Object.keys(live).some(key=>!['domain','root','private','configHash','test','retainArchive','retainHelper','previousVersion','baseRelease'].includes(key)))throw Error('Exact reviewed production handoff required.');
 if(live.previousVersion||live.baseRelease){if(c.transport!=='deduplicated'||!/^8\.0\.0-rc\.[0-9]+$/.test(live.previousVersion||'')||live.baseRelease?.version!==live.previousVersion)throw Error('Independent pinned native base requires complete changed members.');}
}
if(c.nativeProofFile&&!['test-wordpress-dock-workspace.php','test-wordpress-bricks-dynamic-tags.php','test-wordpress-bricks-html-preservation.php','test-wordpress-bricks-automatic.php','test-wordpress-design-context-journey.php','test-wordpress-media-trash.php','test-wordpress-dashboard-access.php','test-wordpress-core-notifications.php','test-wordpress-notification-badge-display.php','test-wordpress-native-reporting-capture.php','test-wordpress-cart-totals.php','test-wordpress-checkout-draft.php','test-wordpress-saved-cart-inbox.php','test-wordpress-daily-arrivals.php','test-wordpress-app-workspace.php','test-wordpress-seo-foundation.php','test-wordpress-workspace-foundation.php','test-wordpress-lean-runtime.php','test-wordpress-forms.php','test-wordpress-national-compatibility.php','test-wordpress-compiler-import.php'].includes(c.nativeProofFile))throw Error('Known native acceptance scope required.');
if(c.retireLabFiles&&(!c.test||!Array.isArray(c.retireLabFiles)||c.retireLabFiles.length>8||c.retireLabFiles.some(f=>!/^kiwe-dock-[a-z0-9-]+\.(php|zip)$/.test(f.name)||f.name===c.helper||f.name===c.archive||!/^[a-f0-9]{64}$/.test(f.sha256))))throw Error('Exact superseded lab files required.');
if(c.transport){
 if(!['deduplicated','spliced'].includes(c.transport)||!c.baseRelease||c.baseRelease.version!==c.previousVersion||!Number.isSafeInteger(c.transportBytes)||c.transportBytes<1||! /^[a-f0-9]{64}$/.test(c.transportSha256)||!c.targetFiles||!Array.isArray(c.transportFiles)||c.transportFiles.length<1)throw Error('Pinned deduplicated transport required.');
 for(const [name,hash]of Object.entries(c.targetFiles))if(!/^(?:dsa\/[a-zA-Z0-9._/-]+|dsa\.php|kiwe-incident-guard\.php)$/.test(name)||name.split('/').includes('..')||! /^[a-f0-9]{64}$/.test(hash))throw Error('Bounded exact artifact inventory required.');
 if(new Set(c.transportFiles).size!==c.transportFiles.length||c.transportFiles.some(name=>!Object.hasOwn(c.targetFiles,name)))throw Error('Exact changed-file inventory required.');
}
let nativeSource=fs.readFileSync(path.join(__dirname,c.nativeProofFile||'test-wordpress-dock-workspace.php'),'utf8');
if(c.nativeProofFile==='test-wordpress-compiler-import.php') nativeSource='<?php\n'+fs.readFileSync(path.join(__dirname,'fixtures/compiler-package.php'),'utf8').replace(/^<\?php\s*/,'')+'\n'+nativeSource.replace(/^<\?php\s*/,'');
if(c.nativeProofFile==='test-wordpress-seo-foundation.php'){
 const before="<?php if(PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||!KIWE_WORKSPACE_TEST_SITE||home_url()!=='https://plum-giraffe-539524.hostingersite.com')throw new RuntimeException('Owned lab SEO scope required');define('KIWE_SEO_NATIVE_TEST',true);define('KIWE_SEO_SOURCE_DIR',WP_CONTENT_DIR.'/mu-plugins/dsa');define('KIWE_SEO_TEST_PROGRESS',$private.'/seo-native-progress.json');\n";
 nativeSource=before+nativeSource.replace(/^<\?php\s*/,'')+"\nkiwe_seo_test_isolate();$nativeProof=kiwe_seo_test_run();$nativeProof['runtime']=DSA_VERSION;echo json_encode($nativeProof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);";
}
c.nativeProof=Buffer.from(nativeSource).toString('base64');
// A SHA-256 commitment still proves the complete exact inventory. Carry only
// changed member hashes, avoiding another transfer of hundreds of unchanged hashes.
if(process.argv.includes('--compact')){
 if(!c.transport)throw Error('Compact inventory requires verified base reconstruction.');
 const complete=Object.fromEntries(Object.keys(c.targetFiles).sort().map(name=>[name,c.targetFiles[name]]));
 c.targetInventorySha256=require('crypto').createHash('sha256').update(JSON.stringify(complete)).digest('hex');
 c.targetFileCount=Object.keys(complete).length;
 c.targetFiles=Object.fromEntries(c.transportFiles.map(name=>[name,complete[name]]));
}
let template=fs.readFileSync(path.join(__dirname,'dock-release-operator.php'),'utf8');if(template.split('__KIWE_DOCK_CONFIG__').length!==2)throw Error('One config marker required');
template=template.replace('/* __KIWE_BYTE_SPLICES__ */',fs.readFileSync(path.join(__dirname,'release-byte-splices.php'),'utf8').replace(/^<\?php\s*/,''));
const body=Buffer.from(JSON.stringify(c));const encoded=c.transport?require('zlib').gzipSync(body):body;
let rendered=template.replace('__KIWE_DOCK_CONFIG__',encoded.toString('base64'));
if(process.argv.includes('--packed')){
 rendered=rendered.replace(/__FILE__/g,'KIWE_OPERATOR_FILE').replace(/__DIR__/g,'dirname(KIWE_OPERATOR_FILE)');
 const hash=require('crypto').createHash('sha256').update(rendered).digest('hex');
 const packed=require('zlib').gzipSync(Buffer.from(rendered));
 const wrapper="<?php if(PHP_SAPI!=='cli'){http_response_code(404);exit;}if(defined('KIWE_OPERATOR_FILE'))exit(2);define('KIWE_OPERATOR_FILE',__FILE__);$operatorBody=gzdecode(substr(file_get_contents(__FILE__),__COMPILER_HALT_OFFSET__));if(!is_string($operatorBody)||strlen($operatorBody)>1048576||!hash_equals('"+hash+"',hash('sha256',$operatorBody)))exit(2);eval('?>'.$operatorBody);__halt_compiler();";
 rendered=Buffer.concat([Buffer.from(wrapper),packed]);
}
fs.writeFileSync(outPath,rendered,{flag:'wx'});console.log(JSON.stringify({passed:true,bytes:fs.statSync(outPath).size,target:c.domain,compactInventory:Boolean(c.targetInventorySha256),packed:process.argv.includes('--packed')}));
