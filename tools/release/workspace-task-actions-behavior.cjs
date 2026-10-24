const assert = require('node:assert/strict');
const vm = require('node:vm');
const source = require('node:fs').readFileSync('wp-content/mu-plugins/dsa/assets/js/workspace-task-actions.js','utf8');
const token = 'a'.repeat(64), nextToken = 'b'.repeat(64);
function fixture(kind, reply, options={}) {
 const calls=[],timers=new Map(),windowEvents={},scopeEvents={},fields=[];
 const scope={dataset:{selected:'false'},addEventListener:(n,f)=>scopeEvents[n]=f,setAttribute(n,v){this[n]=v;},removeAttribute(n){delete this[n];}};
 const badge={textContent:'Not in Design Context'},heading={dataset:{kiweMenuTitle:'501'},textContent:'Original'},parent={value:'501',textContent:'Original'};
 const forms=Array.from({length:kind==='menu'?2:1},(_,index)=>{
  const events={},inputEvents={};const input={value:index?'Second':'Original',disabled:false,addEventListener:(n,f)=>inputEvents[n]=f,focus(){this.focused=true;}};
  const expected={value:kind==='menu'?token:'0'},button={disabled:false,textContent:'Include',attrs:{role:kind==='menu'?'button':'switch','aria-checked':'false'},getAttribute(n){return this.attrs[n];},setAttribute(n,v){this.attrs[n]=v;},classList:{toggle(name,value){this[name]=value;}}},undo={hidden:true,disabled:false};
  const status={hidden:true,dataset:{}},reload={hidden:true};
  const elements=kind==='menu'?{label:input,expected,menu_id:{value:'70'},item_id:{value:String(501+index)}}:{selected:{value:'1'},expected,kind:{value:'media'},record_id:{value:'206'}};
  const form={dataset:{kiweTask:kind==='menu'?'menu-label':'context-selection',restUrl:options.url||'https://example.test/wp-json/dsa/v1/workspace/test'},elements,
   querySelector:s=>s.includes('save-state')?status:s.includes('reload')?reload:s.includes('reset')?undo:button,
   closest:()=>scope,addEventListener:(n,f)=>events[n]=f,reportValidity:()=>!options.invalid};
  const item={form,events,input,expected,button,undo,status,reload,elements,edit(value){input.value=value;inputEvents.input();},submit(){const e={preventDefault(){this.prevented=true;}};scopeEvents.submit?.(e);return events.submit?.(e);},reset(){events.reset({preventDefault(){}});}};fields.push(item);return form;
 });
 const nativeButton={disabled:false},nativeExpected={value:token};
 const controls=fields.flatMap(f=>kind==='menu'?[f.input,f.button,f.undo]:[f.button]).concat(nativeButton);
 scope.querySelectorAll=s=>{
  if(s==='input[name=expected]')return fields.map(f=>f.expected).concat(nativeExpected);
  if(s==='[data-kiwe-menu-title]')return[heading];
  if(s==='select[name=parent_id] option')return[parent];
  if(s.startsWith('form[method=post]'))return fields.map(f=>f.button).concat(nativeButton);
  return controls;
 };
 scope.querySelector=()=>badge;
 let timerId=0;
 const fetch=async(url,opts)=>{calls.push({url,opts});return reply(opts,calls.length);};
 const window={KIWE_TASK_ACTIONS:{nonce:'synthetic',saving:'Saving',changed:'Unsaved',unchanged:'No changes',uncertain:'Unconfirmed'},fetch:options.noFetch?null:fetch,AbortController,
  location:{origin:'https://example.test',href:'https://example.test/wp-admin/admin.php?page=test'},addEventListener:(n,f)=>windowEvents[n]=f,
  setTimeout:f=>{timers.set(++timerId,f);return timerId;},clearTimeout:id=>timers.delete(id)};
 vm.runInNewContext(source,{window,document:{querySelectorAll:()=>forms},URL,fetch,AbortController});
 return {fields,calls,timers,scope,scopeEvents,windowEvents,heading,parent,badge,nativeButton,nativeExpected};
}
const menuReply=(extra={})=>({ok:true,json:async()=>({menuId:70,itemId:501,label:'Saved label',title:'Displayed label',expected:nextToken,message:'Saved',...extra})});
const selectionReply=(selected,extra={})=>({ok:true,json:async()=>({id:206,kind:'media',selected,canSelect:true,button:selected?'Remove':'Include',state:selected?'Included':'Not included',message:'Saved',...extra})});
(async()=>{
 let f=fixture('menu',async()=>menuReply());let [a,b]=f.fields;
 a.edit('New label');b.edit('Keep second unsaved');assert.equal(a.status.textContent,'Unsaved');await a.submit();
 assert.equal(a.input.value,'Saved label');assert.equal(f.heading.textContent,'Displayed label');assert.equal(f.parent.textContent,'Displayed label');assert.equal(b.input.value,'Keep second unsaved');assert.equal(b.expected.value,nextToken);assert.equal(f.nativeExpected.value,nextToken);assert.equal(a.status.dataset.state,'saved');
 assert.deepEqual(JSON.parse(f.calls[0].opts.body),{label:'New label',expected:token});assert.equal(f.calls[0].opts.headers['X-WP-Nonce'],'synthetic');assert.equal(f.calls[0].opts.credentials,'same-origin');assert.equal(f.calls[0].opts.redirect,'error');
 a.edit('Another');a.reset();assert.equal(a.input.value,'Saved label');assert.equal(a.input.focused,true);
 const leave={preventDefault(){this.prevented=true;}};f.windowEvents.beforeunload(leave);assert.equal(leave.prevented,true);
 let release;f=fixture('menu',()=>new Promise(resolve=>release=resolve));[a,b]=f.fields;a.edit('Pending');const pending=a.submit();await b.submit();assert.equal(f.calls.length,1);assert.equal(b.button.disabled,true);const nativeEvent={preventDefault(){this.prevented=true;}};f.scopeEvents.submit(nativeEvent);assert.equal(nativeEvent.prevented,true);release(menuReply());await pending;assert.equal(b.button.disabled,false);assert.equal(f.nativeButton.disabled,false);
 for(const bad of [async()=>({ok:false,json:async()=>({message:'Changed elsewhere'})}),async()=>({ok:false,json:async()=>null}),async()=>{throw Error('offline');},async()=>menuReply({itemId:999}),async()=>menuReply({expected:'invalid'}),async()=>({ok:true,json:async()=>{throw Error('invalid JSON');}})]){
  f=fixture('menu',bad);[a,b]=f.fields;a.edit('Keep text');await a.submit();await b.submit();assert.equal(f.calls.length,1);assert.equal(a.input.value,'Keep text');assert.equal(f.heading.textContent,'Original');assert.equal(b.expected.value,token);assert.equal(a.status.dataset.state,'error');assert.equal(a.reload.hidden,false);assert.equal(f.nativeButton.disabled,true);assert.equal(a.input.disabled,false);assert.equal(f.timers.size,0);
 }
 f=fixture('selection',async(opts)=>selectionReply(JSON.parse(opts.body).selected));a=f.fields[0];await a.submit();assert.equal(f.scope.dataset.selected,'true');assert.equal(a.button.textContent,'Remove');assert.equal(a.expected.value,'1');assert.equal(f.badge.textContent,'Included');await a.submit();assert.equal(f.scope.dataset.selected,'false');assert.equal(a.button.textContent,'Include');assert.deepEqual(JSON.parse(f.calls[1].opts.body),{selected:false,expected:true});
 f=fixture('selection',async()=>selectionReply(true,{id:999}));a=f.fields[0];await a.submit();assert.equal(f.scope.dataset.selected,'false');assert.equal(a.expected.value,'0');assert.equal(a.reload.hidden,false);assert.equal(a.status.dataset.state,'error');
 f=fixture('selection',async()=>selectionReply(false,{canSelect:false}));a=f.fields[0];a.elements.selected.value='0';a.expected.value='1';await a.submit();assert.equal(a.status.dataset.state,'saved');assert.equal(a.button.disabled,true);assert.equal(a.reload.hidden,false);
 f=fixture('selection',opts=>new Promise((resolve,reject)=>opts.signal.addEventListener('abort',()=>reject(Error('timeout')))));a=f.fields[0];const waiting=a.submit();f.timers.values().next().value();await waiting;await a.submit();assert.equal(f.calls.length,1);assert.equal(a.status.dataset.state,'error');assert.equal(a.expected.value,'0');assert.equal(f.timers.size,0);
 for(const options of [{url:'https://other.test/write'},{url:'http://['},{noFetch:true}]){f=fixture('selection',async()=>selectionReply(true),options);assert.equal(f.fields[0].events.submit,undefined);assert.equal(f.calls.length,0);}
 f=fixture('menu',async()=>menuReply(),{invalid:true});await f.fields[0].submit();assert.equal(f.calls.length,0);
 console.log('PASS actual task JS: canonical readback, preservation of other edits, shared menu locking, undo, selection reversal, expired/conflicting/malformed/offline replies, timeout, no replay and native fallback');
})().catch(error=>{console.error(error);process.exitCode=1;});
