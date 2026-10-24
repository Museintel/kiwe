const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..');
const source=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Journey_Service.php'),'utf8');
assert(!source.includes('->current('),'Progress cannot build the full Design Context packet');
assert(source.includes("'number'=>1")&&source.includes('LIMIT 1'),'Evidence must be bounded');
assert(source.includes('check_admin_referer')&&source.includes('hash_equals'),'Native nonce and stale-state gates');
const php=process.env.KIWE_PHP||'php';const result=spawnSync(php,[path.join(__dirname,'test-design-context-journey.php')],{encoding:'utf8'});if(result.status!==0)throw Error(result.stdout+result.stderr);
const proof=JSON.parse(result.stdout.trim());assert(proof.passed&&proof.checks>=24);
(async()=>{
 const {publishContextProgress}=await import('../workspace-prototype/context-progress-event.mjs');let events=[];
 global.location={origin:'https://example.invalid'};global.CustomEvent=class{constructor(type,options){this.type=type;this.detail=options.detail;}};global.window={dispatchEvent:event=>events.push(event)};
 const valid={percent:50,ready:1,total:2,later:1,url:'https://example.invalid/wp-admin/admin.php?page=kiwe-design-context',resumeUrl:'https://example.invalid/wp-admin/admin.php?page=kiwe-design-context#context-story',next:'story'};
 const send=value=>publishContextProgress({headers:{get:()=>JSON.stringify(value)}});
 send(valid);assert.equal(events.length,1);assert.deepEqual(events[0].detail,valid);
 for(const patch of [{percent:101},{total:0},{ready:-1},{percent:'50'},{url:'https://other.invalid/'},{resumeUrl:'https://other.invalid/'}])send({...valid,...patch});
 publishContextProgress({});assert.equal(events.length,1);delete global.window;send(valid);assert.equal(events.length,1);
 console.log(JSON.stringify({passed:true,modelChecks:proof.checks,receiptChecks:9,noFollowupRequests:true}));
})().catch(error=>{console.error(error);process.exitCode=1;});
