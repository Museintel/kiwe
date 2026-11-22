const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.resolve(__dirname,'../../wp-content/mu-plugins/dsa/assets/js/surface.js'),'utf8');
function fn(name,next){const start=source.indexOf('\tfunction '+name+'('),end=source.indexOf('\tfunction '+next+'(',start);assert(start>=0&&end>start);return source.slice(start,end);}
const handlers={},context={overlayRoot:{},presentationModuleUrls:{comments:''},commerce:{available:true},searchConfig:{},discussionConfig:{targetId:1,currentTargetId:2,siteTargetId:3},data:{restUrl:'/fixture'},discussionPendingCounts:new Set(),discussionInFlightCounts:new Set(),discussionCountFrame:0,moduleItems:()=>[],requests:0,window:{addEventListener:(name,callback)=>handlers[name]=callback,requestAnimationFrame:()=>{context.requests++;return 1;}},dsaGet:()=>{context.requests++;return Promise.reject(new Error('Fixture has no network'));}};vm.createContext(context);
vm.runInContext(fn('externalModuleAvailable','updateExternalCartBadge')+fn('discussionLauncherId','updateDiscussionCount')+fn('queueDiscussionCount','queueDiscussionCountBatch'),context);
const eventStart=source.indexOf("\twindow.addEventListener( 'surface:navigation:complete', function ( event ) {",source.indexOf('function queueDiscussionCount')),eventEnd=source.indexOf('\n\tfunction dsaPost(',eventStart);assert(eventStart>0&&eventEnd>eventStart);vm.runInContext(source.slice(eventStart,eventEnd),context);
const node=value=>({getAttribute:()=>value});let checks=0;function check(ok,label){assert(ok,label);checks++;}
check(!context.externalModuleAvailable('comments'),'An off or missing Feedback asset makes the external module unavailable');
for(const value of['current','site','246',null])check(context.discussionLauncherId(node(value))===0,'Off Feedback refuses '+value+' launcher IDs');
context.queueDiscussionCount(246);handlers['surface:navigation:complete']({detail:{mode:'morph'}});check(context.requests===0&&context.discussionPendingCounts.size===0,'Off Feedback sends no count or navigation-context request');
context.presentationModuleUrls.comments='/signed/feedback/comments-panel.js';check(context.externalModuleAvailable('comments'),'Enabled Feedback admits its existing module');
for(const[value,id]of[['current',2],['site',3],['246',246],[null,1]])check(context.discussionLauncherId(node(value))===id,'Enabled Feedback retains '+value+' native target');
check(context.externalModuleAvailable('cart')&&context.externalModuleAvailable('search'),'Other external actions retain availability');
console.log(JSON.stringify({passed:true,checks,scope:'Actual public module/target/count/navigation functions with explicit App-off and App-on fixtures; no browser claim.'}));
