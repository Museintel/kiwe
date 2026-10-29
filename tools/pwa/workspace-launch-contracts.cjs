const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=fs.readFileSync(process.argv[2]||path.join(__dirname,'../../wp-content/mu-plugins/dsa/assets/js/surface.js'),'utf8');
const start=source.indexOf('\tfunction initializeStandaloneAppJourney('),end=source.indexOf('\n\tfunction ',start+1);assert(start>=0&&end>start,'Existing PWA journey owner');
const body=source.slice(start,end);let checks=0;
const work='https://site.test/wp-admin/admin.php?page=kiwe-workspace';
function check(value,label){assert(value,label);checks++;}
function launch(user,href='https://site.test/?dsa_pwa=1',standalone=true,scope='/'){
 const redirects=[],panels=[],events=[];
 const context={URL,phonekey:{user},pwaConfig:{scope},notificationConfig:{enabled:false},isStandaloneApp:()=>standalone,recordAppActivity:kind=>events.push(kind),openOverlay:(...args)=>panels.push(args),window:{location:{href,replace:value=>redirects.push(value)}},appPhoneKeyGate:false,notificationIdentityIntent:'',notificationPreferences:{},resumeIosNotificationJourney:()=>false};
 vm.runInNewContext(body+'\ninitializeStandaloneAppJourney();',context);return{redirects,panels,events,gate:context.appPhoneKeyGate};
}
try{
 let r=launch({loggedIn:true,identityVerified:true,workspaceUrl:work});check(r.redirects[0]===work,'Verified staff manifest launch opens the role-derived dashboard');
 r=launch({loggedIn:true,identityVerified:true,workspaceUrl:work},'https://site.test/');check(r.redirects.length===0,'Deliberate public browsing stays public');
 r=launch({loggedIn:true,identityVerified:true,workspaceUrl:work},'https://site.test/story/?dsa_pwa=1');check(r.redirects.length===0,'A notification or content deep link is not replaced');
 r=launch({loggedIn:true,identityVerified:true,workspaceUrl:work},undefined,false);check(!r.redirects.length&&!r.events.length,'An ordinary browser is not treated as an installed app');
 r=launch({loggedIn:false,workspaceUrl:work});check(!r.redirects.length&&r.panels[0][0]==='profile'&&r.gate,'Signed-out app retains the native sign-in gate');
 r=launch({loggedIn:true,identityVerified:false,workspaceUrl:work});check(!r.redirects.length&&r.gate,'An unverified identity cannot skip the existing app gate');
 r=launch({loggedIn:true,identityVerified:true});check(!r.redirects.length&&!r.gate,'A customer remains on the public appsite');
 for(const target of ['https://other.test/wp-admin/admin.php?page=kiwe-workspace','javascript:alert(1)','https://site.test/wp-admin/admin.php?page=plugins','https://site.test/wp-admin/plugins.php'])check(!launch({loggedIn:true,identityVerified:true,workspaceUrl:target}).redirects.length,'Unrelated or cross-origin destination is refused');
 r=launch({loggedIn:true,identityVerified:true,workspaceUrl:'https://site.test/site/wp-admin/admin.php?page=kiwe-workspace'},'https://site.test/site/?dsa_pwa=1',true,'/site/');check(r.redirects.length===1,'WordPress subdirectory app scope is retained');
 r=launch({loggedIn:true,identityVerified:true,privilegedEnrollmentRequired:true,workspaceUrl:work});check(r.redirects.length===1,'Native dashboard admission and enrollment policy remain the destination authority');
 console.log(JSON.stringify({passed:true,cleanup:true,checks,scope:'Actual existing browser journey with synthetic private projections; not physical-device installation'}));
}catch(error){console.log(JSON.stringify({passed:false,cleanup:true,checks,error:error.message}));process.exitCode=1;}
