const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('wp-content/mu-plugins/dsa/assets/js/workspace-page-name.js', 'utf8');
function fixture(reply) {
 const listeners = {}, inputListeners = {}, windowListeners = {};
 const input = {value:'Original', defaultValue:'Original', addEventListener:(n,f)=>inputListeners[n]=f, focus(){this.focused=true;}};
 const expected = {value:'Original'}, status = {hidden:true,dataset:{}}, reset = {hidden:true}, button = {};
 const heading = {textContent:'Original'}, calls = [], timers = new Map();
 const form = {dataset:{restUrl:'https://example.test/wp-json/dsa/v1/workspace/pages/11/name'}, elements:{page_title:input, expected_title:expected,page_id:{value:'11'}},
 querySelector:s=>s.includes('save-state')?status:s.includes('reset')?reset:button,
 addEventListener:(n,f)=>listeners[n]=f, reportValidity:()=>true, setAttribute(){},removeAttribute(){}};
 const fetch = async(url,options)=>{calls.push({url,options});return reply(options);};
 const window = {KIWE_PAGE_NAME:{nonce:'synthetic',saving:'Saving',changed:'Unsaved',unchanged:'No changes',uncertain:'Unconfirmed'},fetch,AbortController,location:{href:'https://example.test/wp-admin/edit.php',origin:'https://example.test'},addEventListener:(n,f)=>windowListeners[n]=f,setTimeout:f=>{timers.set(1,f);return 1;},clearTimeout:id=>timers.delete(id)};
 vm.runInNewContext(source,{window,document:{querySelectorAll:()=>[form],querySelector:()=>heading},fetch,URL,AbortController});
 const event=()=>({preventDefault(){this.prevented=true;}});
 return {input,expected,status,reset,button,heading,calls,timers,windowListeners,edit(value){input.value=value;inputListeners.input();},submit:()=>listeners.submit(event()),undo:()=>listeners.reset(event())};
}
(async()=>{
 let f=fixture(async()=>({ok:true,json:async()=>({id:11,title:'Our studio',message:'Saved'})}));
 f.edit('Our studio');assert.equal(f.status.textContent,'Unsaved');assert.equal(f.reset.hidden,false);
 await f.submit();assert.equal(f.expected.value,'Our studio');assert.equal(f.heading.textContent,'Our studio');assert.equal(f.status.dataset.state,'saved');assert.equal(f.calls.length,1);assert.equal(f.timers.size,0);
 assert.equal(f.calls[0].options.credentials,'same-origin');assert.equal(f.calls[0].options.headers['X-WP-Nonce'],'synthetic');assert.deepEqual(JSON.parse(f.calls[0].options.body),{page_title:'Our studio',expected_title:'Original'});
 f.edit('Another');f.undo();assert.equal(f.input.value,'Our studio');assert.equal(f.input.focused,true);
 let release;f=fixture(()=>new Promise(resolve=>release=resolve));f.edit('Pending');const pending=f.submit();await f.submit();assert.equal(f.calls.length,1);assert.equal(f.button.disabled,true);assert.equal(f.input.readOnly,true);
 release({ok:true,json:async()=>({id:11,title:'Pending',message:'Saved'})});await pending;assert.equal(f.button.disabled,false);
 for(const reply of [async()=>({ok:false,json:async()=>({message:'A newer name exists'})}),async()=>{throw Error('offline');},async()=>({ok:true,json:async()=>({id:99,title:'Wrong object',message:'Saved'})}),async()=>({ok:true,json:async()=>{throw Error('invalid JSON');}})]){
  f=fixture(reply);f.edit('Keep my text');await f.submit();assert.equal(f.input.value,'Keep my text');assert.equal(f.expected.value,'Original');assert.equal(f.heading.textContent,'Original');assert.equal(f.status.dataset.state,'error');assert.equal(f.calls.length,1);assert.equal(f.button.disabled,false);assert.equal(f.timers.size,0);
  const event={preventDefault(){this.prevented=true;}};f.windowListeners.beforeunload(event);assert.equal(event.prevented,true);
 }
 f=fixture(options=>new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>reject(Error('timeout')))));f.edit('Slow network');const waiting=f.submit();f.timers.get(1)();await waiting;assert.equal(f.status.dataset.state,'error');assert.equal(f.input.value,'Slow network');assert.equal(f.button.disabled,false);
 console.log('PASS actual page-name script: confirmed readback, undo, duplicate submit, stale/network/malformed response, timeout and unsaved navigation');
})().catch(error=>{console.error(error);process.exitCode=1;});
