const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.resolve(__dirname,'../../wp-content/mu-plugins/dsa/assets/js/surface.js'),'utf8');
function fn(name){const start=source.indexOf('\tfunction '+name+'(');assert(start>=0,name);const end=source.indexOf('\n\tfunction ',start+1);return source.slice(start,end<0?source.length:end);}
const functions=['normalizeSavedItem','mergeSavedItems','savedItemIdentity','savedActor','renderSavedMutations','acceptSavedServerItems','visibleSavedItems','persistSavedItems','updateSavedUi','initializeSavedItems','consumePendingSavedIntent','mutateSavedItem','toggleSavedItem','removeSavedItem'].map(fn).join('\n');
const item=(id,title='Item '+id)=>({id,type:'bookmark',key:'bookmark:'+id,title,url:'https://example.invalid/'+id,savedAt:10});
const tick=()=>new Promise(resolve=>setImmediate(resolve));let checks=0;
function context(initial=[item(1)]){
 let storage=JSON.stringify(initial),pending=null;const triggers=[];
 const c={Promise,Map,Date,Number,String,Array,Boolean,JSON,Error,phonekey:{user:{loggedIn:true,id:4}},commerce:{available:true},surface:null,overlayRoot:null,savedStorageKey:'saved',pendingSavedIntentKey:'pending',runtimeIdentityConfirmed:true,runtimeHydrationFailed:false,
  document:{querySelectorAll:()=>triggers},window:{localStorage:{getItem:()=>storage,setItem:(k,v)=>{storage=v;}},sessionStorage:{getItem:()=>pending,removeItem:()=>{pending=null;}}},
  posts:[],gets:0,notices:[],messages:[],updateDockBadge(){},inferSavedItem:t=>t.item,aiStringHash:s=>'client-'+s,rememberPendingSavedIntent(){},openOverlay(){},phonekeyState:{},announce(m){c.messages.push(m);},removeAiNotification(){},recordAiNotification:n=>n,queueAiPopout:n=>c.notices.push(n),
  dsaPost(url,payload){c.posts.push(payload);return c.post(payload);},dsaGet(){++c.gets;return c.get();},get:async()=>({ok:true,items:[]})};
 vm.createContext(c);vm.runInContext('var savedItems='+JSON.stringify(initial)+'; var savedConfirmedItems=null; var savedConfirmedActor=""; var savedServerRevision=0; var savedMutations=[]; var savedMutationQueue=Promise.resolve();\n'+functions,c);
 c.triggers=triggers;c.read=()=>JSON.parse(vm.runInContext('JSON.stringify(savedItems)',c));c.pending=()=>vm.runInContext('savedMutations.length',c);
 c.trigger=(row)=>{const attrs={},classes={};const t={item:row,dataset:{},hidden:false,attrs,classes,classList:{toggle:(k,v)=>{classes[k]=v;}},setAttribute:(k,v)=>{attrs[k]=v;},removeAttribute:k=>{delete attrs[k];}};triggers.push(t);return t;};
 return c;
}
(async()=>{
 let c=context();let first,second;c.post=()=>new Promise(r=>{if(!first)first=r;else second=r;});
 const trigger=c.trigger(item(2));const add=c.mutateSavedItem('add',item(2));assert.deepEqual(c.read().map(i=>i.id),[2,1]);assert.equal(trigger.attrs['aria-busy'],'true');checks+=2;
 await tick();const remove=c.mutateSavedItem('remove',item(1));assert.equal(c.posts.length,1);checks++;
 first({ok:true,items:[item(2),item(1)]});await tick();assert.equal(c.posts.length,2);assert.deepEqual(c.read().map(i=>i.id),[2]);checks+=2;
 second({ok:true,items:[item(2)]});await Promise.all([add,remove]);assert.equal(c.pending(),0);assert(!('aria-busy'in trigger.attrs));assert.deepEqual(c.read().map(i=>i.id),[2]);checks+=3;
 c.post=async()=>{throw Object.assign(new Error('Please try again.'),{status:409});};c.get=async()=>({ok:true,items:[item(3),item(2)]});
 assert.equal(await c.mutateSavedItem('add',item(4)),null);assert.deepEqual(c.read().map(i=>i.id),[3,2]);assert.equal(c.messages.at(-1),'Please try again.');checks+=3;
 let resolve;c=context([]);c.post=()=>new Promise(r=>{resolve=r;});const t=c.trigger(item(9));c.toggleSavedItem(t);await tick();assert.equal(c.notices.length,0);checks++;
 resolve({ok:true,items:[item(9)]});await tick();assert.equal(c.notices.length,1);assert.equal(t.classes['is-kiwe-saved'],true);checks+=2;
 c=context([]);c.post=async()=>{throw new Error('Storage unavailable');};c.get=async()=>({ok:true,items:[]});const failed=c.trigger(item(10));c.toggleSavedItem(failed);await tick();await tick();assert.equal(c.notices.length,0);assert.equal(failed.classes['is-kiwe-saved'],false);checks+=2;
 c=context([item(1)]);c.get=async()=>({ok:true,items:[item(2)]});await c.initializeSavedItems();assert.deepEqual(c.read().map(i=>i.id),[2]);checks++;
 const external={id:0,type:'bookmark',key:'client-hash',title:'Outside',url:'https://example.invalid/outside',savedAt:1};c=context([]);const externalTrigger=c.trigger(external);c.acceptSavedServerItems([{...external,key:'bookmark:native-md5'}]);assert.equal(externalTrigger.classes['is-kiwe-saved'],true);checks++;
 c=context([]);c.post=async()=>({ok:true,items:[item(2)]});const switched=c.mutateSavedItem('add',item(2));c.phonekey.user={loggedIn:true,id:8};assert.equal(await switched,null);assert.equal(c.posts.length,0);assert.deepEqual(c.read(),[]);checks+=3;
 c=context([]);let oldRead;c.get=()=>new Promise(r=>{oldRead=r;});const startup=c.initializeSavedItems();c.post=async()=>({ok:true,items:[item(2)]});await c.mutateSavedItem('add',item(2));oldRead({ok:true,items:[]});await startup;assert.deepEqual(c.read().map(i=>i.id),[2]);checks++;
 c=context([]);c.phonekey.user={loggedIn:false};assert.equal(await c.mutateSavedItem('add',item(1)),null);await c.initializeSavedItems();assert.equal(c.posts.length,0);assert.equal(c.gets,0);checks+=3;
 c=context([]);c.commerce.available=false;c.savedConfirmedActor='4';vm.runInContext('savedConfirmedActor="4"',c);c.acceptSavedServerItems([{...item(2),type:'wishlist'}]);c.acceptSavedServerItems([item(1)]);assert.equal(c.read().some(i=>i.type==='wishlist'),true);checks++;
 console.log(JSON.stringify({passed:true,checks,scope:'Actual browser Saved functions with deferred success/failure, queue races, account changes, authoritative reads and shared attribute state; no live browser or user data'}));
})().catch(e=>{console.error(e);process.exitCode=1;});
