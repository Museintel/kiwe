const {spawnSync}=require('node:child_process');
const {resolve}=require('node:path');
const assert=require('node:assert/strict');
(async()=>{
 const result=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-seo-context-journey.php'],{cwd:resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true});
 process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');
 if(result.error)throw result.error;
 if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test((result.stdout||'')+(result.stderr||'')))throw Error('SEO context PHP behavior failed');
 const {initialContextField}=await import('../workspace-prototype/social-link-entry.mjs');
 const goal={path:'seo.primaryGoal',options:{buy:'Sales',read:'Readership'}},social={path:'contact.socialLinks.instagram',type:'url'};
 assert.equal(initialContextField([goal],'?field=seo.primaryGoal','story'),goal);
 assert.equal(initialContextField([goal],'?field=seo.primaryGoal','contact'),null);
 assert.equal(initialContextField([], '?field=seo.primaryGoal','story'),null);
 assert.equal(initialContextField([goal],'?field=seo.allowIndexing','story'),null);
 assert.equal(initialContextField([goal],'?field=seo.primaryGoal','website-plan'),null);
 assert.equal(initialContextField([social],'?link=instagram','contact'),social);
 console.log('6 registered-field deep-link checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
