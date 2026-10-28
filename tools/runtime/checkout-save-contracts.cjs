/* Execute the shipped checkout queue against deferred requests, without a browser,
 * WordPress session, network, order or provider. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.resolve(__dirname,'../../wp-content/mu-plugins/dsa/assets/js/surface.js'),'utf8');
function extract(name){const start=source.indexOf('\tfunction '+name+'('),end=source.indexOf('\n\tfunction ',start+1);assert(start>=0&&end>start,name);return source.slice(start,end);}
const shipped=['flushCheckoutDraft','saveCheckoutDraft','collectCheckoutValues','updateCheckoutShippingFields'].map(extract).join('\n');
const checks=[];function check(ok,label){assert(ok,label);checks.push(label);}
async function settle(){for(let i=0;i<24;i++)await Promise.resolve();}
function fixture(){
 const calls=[],paint=[];const button={disabled:false,isConnected:true},message={textContent:''},shipping={hidden:true,disabled:true};
 const toggle={name:'ship_to_different_address',type:'checkbox',checked:false,attrs:{},setAttribute(name,value){this.attrs[name]=value;},matches(){return false;}};
 const city={name:'billing_city',type:'text',value:'Initial city',matches(){return false;}};
 const separate={name:'shipping_city',type:'text',value:'Separate city',matches(selector){assert.equal(selector,':disabled');return shipping.disabled;}};
 const form={querySelector(selector){if(selector==='button[type="submit"]')return button;if(selector==='[data-dsa-checkout-message]')return message;if(selector==='[data-dsa-checkout-shipping-toggle]')return toggle;if(selector==='[data-dsa-checkout-shipping-fields]')return shipping;return null;},querySelectorAll(selector){assert.equal(selector,'[data-dsa-checkout-field]');return[toggle,city,separate];}};
 const c={Promise,Boolean,Object,window:{clearTimeout(){}},checkoutDraftTimer:91,checkoutDraftDirty:false,overlayRoot:{querySelector(){return form;}},checkoutDraftPromise:Promise.resolve(),checkoutRequestSequence:0,checkoutAppliedSequence:0,activeOverlayLifecycle:{sequence:7},open:true,checkoutState:{contract:{marker:'initial'},errors:{},notices:[]},
  dsaPost(route,payload){assert.equal(route,'/checkout');let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});calls.push({payload,resolve,reject});return promise;},
  renderCheckoutSurface(){paint.push('render');},focusFirstCheckoutError(){paint.push('focus');},completeCheckoutSurface(){paint.push('complete');}
 };
 c.overlayLifecycleCurrent=sequence=>c.open&&c.activeOverlayLifecycle&&c.activeOverlayLifecycle.sequence===sequence;
 vm.runInNewContext(shipped,c);
 return {c,calls,paint,button,message,shipping,toggle,city,separate,form};
}
function response(marker,shipToDifferent=false,extra={}){return{valid:true,checkout:{marker,shipToDifferent,errors:{},notices:[]},...extra};}
(async()=>{
 {
  const f=fixture();f.c.checkoutDraftDirty=true;f.city.value='Final address';f.c.flushCheckoutDraft();f.c.open=false;await settle();
  check(f.calls.length===1&&f.calls[0].payload.fields.billing_city==='Final address','Final pending draft starts even after the sheet lifecycle closes');
  f.calls[0].resolve(response('final snapshot'));await settle();check(f.paint.length===0,'Final draft persistence never revives a closed sheet');
 }
 {
  const f=fixture();const one=f.c.saveCheckoutDraft(f.form,false,true);await settle();
  check(f.calls.length===1&&f.calls[0].payload.fields.ship_to_different_address==='0','First unchecked snapshot starts one request');
  f.toggle.checked=true;f.c.updateCheckoutShippingFields(f.form);f.city.value='Middle';const two=f.c.saveCheckoutDraft(f.form,false,true);
  f.toggle.checked=false;f.c.updateCheckoutShippingFields(f.form);f.city.value='Latest snapshot';const three=f.c.saveCheckoutDraft(f.form,false,true);f.city.value='Typed after snapshot';await settle();
  check(f.calls.length===1,'Rapid later edits wait while the first native draft write is pending');
  f.calls[0].resolve(response('stale first',true));await settle();
  check(f.calls.length===2&&f.calls[1].payload.fields.ship_to_different_address==='0','Superseded true edit is coalesced; final false is the next native write');
  check(f.calls[1].payload.fields.billing_city==='Latest snapshot','Queued write uses call-time values rather than a later mutable form');
  check(!('shipping_city'in f.calls[1].payload.fields),'Inactive inherited-disabled shipping fields are excluded from submission');
  check(f.c.checkoutState.contract.marker==='initial'&&f.paint.length===0,'Old response cannot replace the current contract or repaint');
  f.calls[1].resolve(response('latest false'));await Promise.all([one,two,three]);
  check(f.c.checkoutState.contract.marker==='latest false'&&f.c.checkoutState.contract.shipToDifferent===false&&f.c.checkoutAppliedSequence===3,'Last selected false state is the accepted native draft');
  check(f.paint.join(',')==='render','Only the final current edit repaints once');
 }
 {
  const f=fixture();const old=f.c.saveCheckoutDraft(f.form,true,true);await settle();f.city.value='New edit';const newer=f.c.saveCheckoutDraft(f.form,false,true);
  f.calls[0].resolve(response('old valid'));await settle();check(!f.paint.includes('complete')&&f.c.checkoutState.contract.marker==='initial','Older valid submit cannot navigate past a newer edit');
  f.calls[1].resolve(response('current edit'));await Promise.all([old,newer]);check(f.paint.join(',')==='render','Newer edit is saved without completing checkout');
 }
 {
  const f=fixture();const old=f.c.saveCheckoutDraft(f.form,true,true);await settle();const current=f.c.saveCheckoutDraft(f.form,true,true);
  f.calls[0].resolve(response('old invalid',false,{valid:false,errors:{billing_city:'Old error'}}));await settle();
  check(f.paint.length===0&&Object.keys(f.c.checkoutState.errors).length===0,'Stale validation errors neither repaint nor replace current errors');
  check(f.button.disabled,'Older completion leaves the newer validation button locked');
  f.calls[1].resolve(response('current valid'));await Promise.all([old,current]);check(f.paint.join(',')==='complete'&&f.c.checkoutState.contract.marker==='current valid','Only latest successful validation completes the journey');
  check(!f.button.disabled,'Latest completed validation releases its button');
 }
 {
  const f=fixture();const current=f.c.saveCheckoutDraft(f.form,true,true);await settle();f.calls[0].resolve(response('current invalid',false,{valid:false,errors:{billing_city:'Required'}}));await current;
  check(f.paint.join(',')==='render,focus'&&f.c.checkoutState.errors.billing_city==='Required','Current invalid draft renders and focuses the actual validation error');
 }
 for(const changed of['closed','new module']){
  const f=fixture();const first=f.c.saveCheckoutDraft(f.form,true,true);await settle();const queued=f.c.saveCheckoutDraft(f.form,false,true);
  if(changed==='closed')f.c.open=false;else f.c.activeOverlayLifecycle={sequence:8};
  f.calls[0].resolve(response('wrong lifecycle'));await Promise.all([first,queued]);
  check(f.calls.length===1&&f.paint.length===0&&f.c.checkoutState.contract.marker==='initial',changed+' lifecycle prevents response painting and cancels queued native writes');
 }
 {
  const f=fixture();const queued=f.c.saveCheckoutDraft(f.form,false,true);f.c.activeOverlayLifecycle={sequence:8};await queued;
  check(f.calls.length===0&&f.paint.length===0,'Lifecycle change before start cancels the request entirely');
 }
 {
  const f=fixture();const pending=f.c.saveCheckoutDraft(f.form,true,true);await settle();check(f.button.disabled&&f.message.textContent==='Checking details...','Current validation exposes a locked progress state');
  f.calls[0].reject(Error('Temporary native error'));const result=await pending;
  check(result===null&&!f.button.disabled&&f.message.textContent==='Temporary native error'&&f.paint.length===0,'Current transport failure reports the error and releases the button without navigating');
  const retry=f.c.saveCheckoutDraft(f.form,true,true);await settle();check(f.calls.length===2,'Rejected earlier request does not poison the serial queue');f.calls[1].resolve(response('retry'));await retry;check(f.paint.join(',')==='complete','A later valid retry completes exactly once');
 }
 {
  const f=fixture();const first=f.c.saveCheckoutDraft(f.form,true,true);await settle();const latest=f.c.saveCheckoutDraft(f.form,true,true);f.calls[0].reject(Error('Obsolete failure'));await settle();
  check(f.message.textContent==='Checking details...'&&f.paint.length===0,'Stale error cannot replace the current progress message');
  f.calls[1].resolve(response('latest'));await Promise.all([first,latest]);
 }
 {
  const f=fixture();f.toggle.checked=true;f.c.updateCheckoutShippingFields(f.form);check(!f.shipping.hidden&&!f.shipping.disabled&&f.toggle.attrs['aria-expanded']==='true','Selecting separate shipping immediately reveals and enables its dependent group');
  f.toggle.checked=false;f.c.updateCheckoutShippingFields(f.form);check(f.shipping.hidden&&f.shipping.disabled&&f.toggle.attrs['aria-expanded']==='false','Deselecting separate shipping immediately hides and disables its dependent group');
  check(await f.c.saveCheckoutDraft(null,true,true)===null&&f.calls.length===0,'Absent form leaves native draft and surface untouched');
 }
 process.stdout.write(JSON.stringify({passed:true,checks:checks.length,scope:'Shipped checkout queue, snapshot/serialization/coalescing, stale result and lifecycle guards; no native or browser transaction'})+'\n');
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exitCode=1;});
