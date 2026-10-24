/* Execute the shipped adapter/event owners; native cookies and responsive DOM
 * are qualified separately on the disposable WordPress lab and in Chrome. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
const bridge=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php'),'utf8');
const surface=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/js/surface.js'),'utf8');
const adapter=bridge.match(/private function add_to_cart_js\(\): string \{\s*return '([\s\S]*?)';\s*\}/)[1];
let checks=0;const check=(ok,label)=>{assert(ok,label);checks++;};
const tick=()=>new Promise(resolve=>setImmediate(resolve));
async function adapterChecks(){
 const attributes=new Map([['data-brx-t5','true'],['data-dsa-product-id','42']]);
 const listeners={},notices=[],calls=[],fetches=[];let pending,fail=false,shared=true;
 const r={hasAttribute:k=>attributes.has(k),getAttribute:k=>attributes.get(k),setAttribute:(k,v)=>attributes.set(k,String(v)),removeAttribute:k=>attributes.delete(k),querySelector:s=>s==='[data-product_id]'?{getAttribute:()=> '42'}:s==='[data-dsa-atc-error]'?null:s==='.quantity input.qty'?{value:1}:null,appendChild:n=>notices.push(n)};
 const control={tagName:'SPAN',classList:{contains:k=>k==='plus'},closest:s=>s.includes('data-brx-t')?(shared?r:null):(s.includes('.action.plus')||s.includes('.action[role=button]'))?control:null,setAttribute:(k,v)=>control[k]=v};
 const doc={body:{},dispatchEvent(){},querySelectorAll:s=>s==='[data-dsa-product-id]'?[r]:[control],addEventListener:(name,fn)=>(listeners[name]??=[]).push(fn),createElement:()=>({setAttribute(k,v){this[k]=v;}})};
 const $=()=>({on(){},trigger(){}});
 const context={document:doc,jQuery:$,URL,Promise,Date,Array,Number,String,JSON,parseInt,Error,fetch:(url,options)=>{fetches.push({url,options});return Promise.resolve({ok:true,json:()=>Promise.resolve(String(url).includes('/nonce')?{nonce:'synthetic-proof'}:{item:{key:'line',quantity:1},cart:{items:[{productId:42,quantity:1,key:'line'}]}})});},window:{location:{href:'https://example.invalid/'},DSA_BRICKS_ATC:{addUrl:'https://example.invalid/add',itemUrl:'https://example.invalid/item',nonceUrl:'https://example.invalid/nonce'},DSA:{cart:{addProduct:(...args)=>{calls.push(args);return fail?Promise.reject(Error('Stock unavailable')):new Promise(resolve=>{pending=resolve;});}}},sessionStorage:{setItem(){}},localStorage:{setItem(){}}}};
 context.CustomEvent=class{constructor(name,details){this.type=name;this.detail=details?.detail;}};vm.runInNewContext(adapter,context);
 const event=()=>({target:control,preventDefault(){this.prevented=true;},stopImmediatePropagation(){this.stopped=true;}});
 const click=()=>{const e=event();for(const fn of listeners.click)fn(e);return e;};
 control.click=click;
 check(control.role==='button'&&control.tabindex==='0','native span quantity actions acquire keyboard semantics');
 shared=false;let e=click();check(!e.prevented&&calls.length===0,'unselected native controls remain native');shared=true;
 e=click();check(e.prevented&&e.stopped&&calls.length===1&&calls[0][0]===42&&calls[0][2]===1,'query-loop plus resolves its native product and shared cart owner');
 e=click();check(e.stopped&&calls.length===1,'busy plus suppresses repeated/native double submission');
 pending({item:{key:'line',quantity:1},cart:{items:[{productId:42,quantity:1,key:'line'}]}});await tick();
 check(!attributes.has('data-brx-loading')&&attributes.get('data-brx-cart-key')==='line'&&fetches.length===0,'shared response updates control without another transport or fragment publication');
 listeners.keydown[0]({...event(),key:'Enter',repeat:false});check(calls.length===2,'Enter activates one native plus action');pending({item:{key:'line',quantity:2},cart:{items:[{productId:42,quantity:2,key:'line'}]}});await tick();
 fail=true;click();await tick();check(notices.at(-1)?.role==='alert'&&notices.at(-1).textContent==='Stock unavailable'&&!attributes.has('data-brx-loading'),'cart failure is visible and releases the control');
 delete context.window.DSA.cart;click();await tick();await tick();
 check(fetches.length===2&&fetches[0].options.cache==='no-store'&&fetches[0].url.includes('_kiwe_nonce='),'standalone adapter bootstraps one uncached nonce');
 check(fetches[1].options.headers['X-WP-Nonce']==='synthetic-proof'&&fetches[1].options.headers['X-Kiwe-Mutation']==='1','standalone add retains native nonce and mutation proof');
}
function extract(name){const start=surface.indexOf('\tfunction '+name+'('),end=surface.indexOf('\n\tfunction ',start+1);assert(start>=0);return surface.slice(start,end);}
function eventChecks(){
 const native={},body={},timers=new Map();let timer=0,reads=0,resets=0,drains=0;
 const doc={body:{},addEventListener:(name,fn)=>native[name]=fn,querySelectorAll:()=>[],createElement:()=>({})};
 const jq=()=>({on(names,fn){for(const name of names.split(' '))body[name]=fn;},trigger(name,args){body[name]?.({type:name},...(args||[]));}});jq.fn={};
 const c={document:doc,commerce:{available:true},window:{DSA:{},jQuery:jq,setTimeout:fn=>{timers.set(++timer,fn);return timer;},clearTimeout:id=>timers.delete(id)},MutationObserver:class{observe(){}takeRecords(){drains++;}},cartDomSyncObserver:null,applyingOwnedCartFragments:false,cartRefreshMutationPending:false,cartRefreshTimers:[],navigationInFlight:false,cartStateInitialized:true,phonekey:{cart:{count:1}},firstCartConfettiQueued:false,resetNavigationState:()=>resets++,refreshCartState:()=>reads++,playFirstCartConfetti(){},applyCartPayload(){},debugLog(){},wooCartEventButton:()=>({})};
 c.window.MutationObserver=c.MutationObserver;vm.runInNewContext(['scheduleCartRefreshSequence','bindNativeCartDomSync','bindExternalUiEscape','applyWooCartFragments','triggerWooCartEventSafely'].map(extract).join('\n'),c);
 c.bindExternalUiEscape();body.added_to_cart({type:'added_to_cart'});body.wc_fragments_refreshed({type:'wc_fragments_refreshed'});
 check(timers.size===1&&resets===0,'external Woo event burst coalesces one read without disturbing the active surface');
 for(const fn of timers.values())fn();timers.clear();check(reads===1,'coalesced refresh performs one read');
 c.applyWooCartFragments({fragments:{}},'updated_cart_totals');check(timers.size===0&&drains===1&&!c.applyingOwnedCartFragments,'owned cart fragments do not echo into extra cart reads');
 native['dsa:external-ui-open']({type:'dsa:external-ui-open'});check(resets===1,'actual external UI retains its navigation escape');
}
(async()=>{await adapterChecks();eventChecks();console.log(JSON.stringify({passed:true,checks,scope:'native Bricks adapter behavior and shared Woo event coalescing'}));})().catch(e=>{console.error(e.stack);process.exitCode=1;});
