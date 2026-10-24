/* Real runtime owners, synthetic identities only. Native browser geometry stays explicit. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/js/surface.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/css/surface.css'), 'utf8');
const names = ['usesSheetPresentation','prepareSheetPanel','settleSheetPanel','bindSheetDrag','replaceOverlayContent','phoneKeyRoot','phoneKeyCloseButton','bindPhoneKeyClose','renderPhoneKeyStep','isSheetContentClick','updateCheckoutShippingFields','collectCheckoutValues','bindProfilePanel'];
function extract(name) {
 const start = source.indexOf('\tfunction '+name+'(');
 assert(start >= 0, 'Existing shared runtime owner '+name);
 const end = source.indexOf('\n\tfunction ', start + 1);
 const text = source.slice(start, end < 0 ? source.length : end);
 new vm.Script(text);
 return text;
}
const functions = names.map(extract).join('\n');
const hashes = {functions:crypto.createHash('sha256').update(functions).digest('hex'),css:crypto.createHash('sha256').update(css).digest('hex')};
let checks = names.length;
function check(value,label) { assert(value,label); checks += 1; }
const closeContext = {appPhoneKeyGate:false,usesSheetPresentation:()=>true};
vm.runInNewContext(extract('phoneKeyCloseButton')+';this.closeButton=phoneKeyCloseButton;',closeContext);
check(closeContext.closeButton()==='', 'Sheet authentication relies on its sole shared close handle');
closeContext.usesSheetPresentation=()=>false;
check(closeContext.closeButton().includes('data-dsa-pk-close'), 'Classic authentication retains a usable close action');
closeContext.appPhoneKeyGate=true;
check(closeContext.closeButton()==='', 'Required authentication gate never invents a close action');
const collectionContext = {};
vm.runInNewContext(extract('collectCheckoutValues')+';this.collect=collectCheckoutValues;',collectionContext);
const fields = [
 {name:'billing_city',type:'text',value:'Synthetic city',matches:selector=>false},
 {name:'ship_to_different_address',type:'checkbox',checked:false,matches:selector=>false},
 {name:'shipping_city',type:'text',value:'Private draft',matches:selector=>selector===':disabled'},
];
let collected = collectionContext.collect({querySelectorAll:()=>fields});
check(collected.billing_city==='Synthetic city'&&collected.ship_to_different_address==='0', 'Billing and unchecked shipping intent are collected');
check(!Object.hasOwn(collected,'shipping_city'), 'Disabled shipping fields never enter the draft payload');
fields[1].checked=true;fields[2].matches=()=>false;
collected = collectionContext.collect({querySelectorAll:()=>fields});
check(collected.ship_to_different_address==='1'&&collected.shipping_city==='Private draft', 'Explicit different-shipping values remain available');
const shippingContext = {};
vm.runInNewContext(extract('updateCheckoutShippingFields')+';this.update=updateCheckoutShippingFields;',shippingContext);
const shipping = {hidden:false,disabled:false};
const toggle = {checked:false,attributes:{},setAttribute(name,value){this.attributes[name]=value;}};
const form = {querySelector:selector=>selector==='[data-dsa-checkout-shipping-toggle]'?toggle:shipping};
shippingContext.update(form);
check(shipping.hidden&&shipping.disabled&&toggle.attributes['aria-expanded']==='false', 'Different-shipping off immediately hides and disables its section');
toggle.checked=true;shippingContext.update(form);
check(!shipping.hidden&&!shipping.disabled&&toggle.attributes['aria-expanded']==='true', 'Different-shipping on immediately reveals and enables its section');
shippingContext.update({querySelector:()=>null});
check(true, 'Sites without a shipping section safely skip its visibility update');
const clickRoot = {};
const clickContext = {overlayRoot:clickRoot};
vm.runInNewContext(extract('isSheetContentClick')+';this.inside=isSheetContentClick;',clickContext);
const clickPanel = {contains:node=>node==='current-control'};
const detachedPanel = {getAttribute:name=>name==='role'?'dialog':null};
check(clickContext.inside({target:'current-control',composedPath:()=>[]},clickPanel), 'Current dialog controls remain content clicks');
check(clickContext.inside({target:'old-control',composedPath:()=>['old-control',detachedPanel,clickRoot]},clickPanel), 'Detached dialog click paths remain inside the same overlay');
check(!clickContext.inside({target:clickRoot,composedPath:()=>[clickRoot]},clickPanel), 'A genuine backdrop path stays outside');
check(!clickContext.inside({target:'elsewhere',composedPath:()=>[detachedPanel]},clickPanel), 'Unrelated dialog paths cannot bypass this overlay backdrop');
check(!clickContext.inside({target:'elsewhere'},null), 'Absent current dialog and composed path fail closed');
const profileListeners=[];
const directControl=()=>({bindings:0,addEventListener(){this.bindings++;}});
let profileForm=directControl(),profileAvatar=directControl(),profileLogout=directControl();
const editRegion={hidden:true,querySelector:()=>null};
const editButton={setAttribute(){}};
const profilePanel={contains:target=>target===editButton,querySelector:selector=>({'[data-dsa-profile-form]':profileForm,'[data-dsa-avatar-input]':profileAvatar,'[data-dsa-account-logout]':profileLogout,'[data-dsa-profile-edit-region]':editRegion}[selector]||null),addEventListener:(name,fn)=>profileListeners.push(fn)};
const profileContext={overlayRoot:{querySelector:()=>profilePanel},loadRecentOrders(){},refreshProfileContextBadges(){},closestEventTarget:(event,selector)=>selector==='[data-dsa-profile-edit]'?editButton:null};
vm.runInNewContext(extract('bindProfilePanel')+';this.bind=bindProfilePanel;',profileContext);
profileContext.bind();profileContext.bind();
check(profileListeners.length===1, 'Repeated Profile binding attaches one retained-root delegate');
check(profileForm.bindings===1&&profileAvatar.bindings===1, 'Repeated Profile binding does not duplicate current form/avatar handlers');
profileForm=directControl();profileAvatar=directControl();profileLogout=directControl();profileContext.bind();
check(profileForm.bindings===1&&profileAvatar.bindings===1&&profileLogout.bindings===1, 'Returning to a retained Profile binds every newly rendered direct control');
profileListeners.forEach(fn=>fn({target:editButton,preventDefault(){},stopPropagation(){}}));
check(!editRegion.hidden, 'Edit opens once after returning to a retained Profile');
profileListeners.forEach(fn=>fn({target:editButton,preventDefault(){},stopPropagation(){}}));
check(editRegion.hidden, 'The next Edit click closes once without duplicate delegates');
const fixtureIndex = process.argv.indexOf('--fixture');
if (fixtureIndex === -1) {
 console.log(JSON.stringify({passed:true,checks,hashes,scope:'Actual runtime syntax, classic/Sheet close ownership and checkout serialization; DOM continuity requires the generated Chrome fixture'}));
 process.exit(0);
}
const destination = path.resolve(process.argv[fixtureIndex+1]||'');
assert(process.argv[fixtureIndex+1], '--fixture requires an owned output directory');
fs.mkdirSync(destination,{recursive:true});
const page = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kiwe shared continuity acceptance</title><link rel="stylesheet" href="/surface.css"><style>
body{margin:0;font:16px system-ui;background:#f7f6f2;color:#19352b}main{padding:20px}button{font:inherit}#receipt{white-space:pre-wrap}.dsa-surface{--dsa-screen-gutter:16px;--dsa-sheet-max-height:82dvh;--dsa-admin-bar-height:0px}.fixture-space{height:900px}.fixture-result{padding:12px;border:1px solid #ccd3cd}.dsa-overlay-root fieldset{min-width:0}#run{padding:12px}
</style></head><body><main><h1>Shared sheet continuity</h1><button id="run">Run continuity checks</button><pre id="receipt">Ready</pre></main><div class="dsa-surface dsa-theme-sheet dsa-sheet-position-bottom dsa-sheet-spacing-edge dsa-sheet-backdrop-blur dsa-visual-prototype" data-dsa-surface data-dsa-ui-contract="2" data-dsa-layout="wide" data-dsa-theme="sheet" data-dsa-sheet-position="bottom"><div class="dsa-overlay-root" data-dsa-overlay-root hidden></div></div><script>
const surface=document.querySelector('.dsa-surface'),overlayRoot=document.querySelector('.dsa-overlay-root');
let activeOverlayPanel='',activeOverlayModuleId='',activeOverlayMode='dock',activeOverlayLifecycle=null,overlayContentSequence=0,appPhoneKeyGate=false,closeCount=0,surfaceLifecycleSequence=0;
window.DSA={surfaceLifecycle:{current:null}};
function getSurfaceModule(id){return {id,panel:id};}
function dispatchSurfaceLifecycle(){}function clearDockContextRail(){}function applyOverlayHeadingTag(){}function applySeamOverlayContract(){}function syncDockContextRail(){}function setDockModuleActive(){}function cssEscape(value){return CSS.escape(value);}
function createSurfaceLifecycle(module,mode,label){return {sequence:++surfaceLifecycleSequence,id:module.id,panel:module.panel,mode,label};}
function closeOverlay(){closeCount++;overlayRoot.hidden=true;overlayRoot.innerHTML='';document.documentElement.classList.remove('dsa-overlay-active');surface.classList.remove('has-modal-sheet');}
function clearOtpCountdown(){}function renderHomeTrustBadges(){return '<div class="dsa-home-trust">Synthetic trust copy</div>';}
function closestEventTarget(event,selector){return event.target&&event.target.closest?event.target.closest(selector):null;}function loadRecentOrders(){}function refreshProfileContextBadges(){}function handleAccountContextClick(){}function updateProfile(){}function updateAvatar(){}
${functions}
const sourceHashes=${JSON.stringify(hashes)};
function mark(kind,extra){return '<section class="dsa-panel dsa-'+kind+'-panel" role="dialog" aria-modal="false" aria-label="'+kind+'" data-fixture-kind="'+kind+'" '+(extra||'')+'><h2>'+kind+'</h2><input name="billing_city" data-dsa-checkout-field value="Synthetic city"><input name="billing_first_name" data-dsa-checkout-field value="Synthetic name"><div class="fixture-space"></div><button type="button">Continue</button></section>';}
function openFixture(kind,extra){if(!overlayRoot.hidden)closeOverlay();activeOverlayPanel=kind;activeOverlayModuleId=kind;activeOverlayLifecycle=createSurfaceLifecycle({id:kind,panel:kind},'dock',kind);overlayRoot.innerHTML=mark(kind,extra);overlayRoot.hidden=false;prepareSheetPanel();surface.classList.add('has-modal-sheet');document.documentElement.classList.add('dsa-overlay-active');const panel=overlayRoot.querySelector('[role=dialog]');panel.tabIndex=-1;panel.classList.add('is-sheet-entered');panel.focus({preventScroll:true});return panel;}
function shippingMarkup(){return '<form data-dsa-checkout-form><input name="billing_city" value="Synthetic city" data-dsa-checkout-field><input name="billing_first_name" value="Synthetic name" data-dsa-checkout-field><label><input type="checkbox" name="ship_to_different_address" data-dsa-checkout-field data-dsa-checkout-shipping-toggle aria-controls="dsa-checkout-shipping-fields" aria-expanded="false">Use a different shipping address</label><fieldset id="dsa-checkout-shipping-fields" data-dsa-checkout-shipping-fields hidden disabled><legend>Shipping address</legend><label>Shipping city<input name="shipping_city" value="Synthetic shipping" data-dsa-checkout-field required></label></fieldset><div class="fixture-space"></div></form>';}
document.getElementById('run').onclick=()=>{
 const checks=[];const check=(value,label)=>{checks.push({passed:!!value,label});if(!value)throw Error(label);};
 try{
  for(const state of [{id:'checkout',reason:'checkout_render'},{id:'saved',reason:'saved_refresh'},{id:'profile',reason:'account_orders'},{id:'profile',reason:'account_addresses'},{id:'menu',reason:'presentation_hydrated'}]){
   const panel=openFixture(state.id,'data-fixture-stale="old"'),chrome=panel.querySelector('.dsa-sheet-chrome'),body=panel.querySelector('[data-dsa-sheet-content]');body.scrollTop=65;const oldScroll=body.scrollTop;
   const focus=body.querySelector('[name="billing_first_name"]');focus.focus({preventScroll:true});
   const fresh=mark(state.id,'data-fixture-fresh="new"').replace('aria-label="'+state.id+'"','aria-label="Updated '+state.id+'"');
   replaceOverlayContent(fresh,{module:getSurfaceModule(state.id),reason:state.reason});
   const updated=overlayRoot.querySelector('[role=dialog]');
   check(updated===panel&&updated.querySelector('.dsa-sheet-chrome')===chrome&&updated.querySelector('[data-dsa-sheet-content]')===body,state.reason+' retains the same dialog, chrome and scroll owner');
   check(updated.classList.contains('is-sheet-entered')&&getComputedStyle(updated).animationName==='none',state.reason+' does not replay the opening animation');
   check(body.scrollTop===oldScroll,state.reason+' preserves its reading position');
   check(document.activeElement.name==='billing_first_name',state.reason+' restores the named field rather than the first empty marker');
   check(updated.getAttribute('data-fixture-fresh')==='new'&&!updated.hasAttribute('data-fixture-stale')&&updated.getAttribute('aria-label')==='Updated '+state.id,state.reason+' adopts fresh semantic attributes and removes stale state');
   check(updated.getAttribute('aria-modal')==='true'&&updated.dataset.dsaSheetReady==='1'&&updated.querySelectorAll('[data-dsa-sheet-content]').length===1,state.reason+' retains one modal Sheet contract');
  }
  let search=openFixture('search'),searchBody=search.querySelector('[data-dsa-sheet-content]');const searchMarkup='<section class="dsa-panel dsa-search-panel" role="dialog" aria-label="Search" data-dsa-search-panel><h2>Find a record</h2><form><input type="search" id="fixture-search" aria-label="Search synthetic records"></form><div data-dsa-search-results><div class="fixture-space"></div></div></section>';
  replaceOverlayContent(searchMarkup,{module:getSurfaceModule('search'),reason:'presentation_hydrated'});const searchTools=search.querySelector('.dsa-sheet-tools');
  check(overlayRoot.querySelector('[role=dialog]')===search&&search.querySelector('[data-dsa-sheet-content]')===searchBody,'Lazy Search hydration retains the existing shell and scroller');
  check(searchTools&&searchTools.querySelector('input[type="search"]')&&searchBody.querySelector('[data-dsa-search-results]'),'Hydrated Search keeps its controls outside the results scroller');
  searchTools.querySelector('input').focus({preventScroll:true});replaceOverlayContent(searchMarkup,{module:getSurfaceModule('search'),reason:'search_refresh'});
  check(search.querySelector('.dsa-sheet-tools')===searchTools&&search.querySelectorAll('.dsa-sheet-tools').length===1,'Later Search updates retain one fixed tools region');
  check(document.activeElement.id==='fixture-search'&&search.querySelectorAll('[data-dsa-sheet-content]').length===1,'Search focus and its sole scroll owner survive updates');
  let panel=openFixture('profile','data-dsa-phonekey-auth'),chrome=panel.querySelector('.dsa-sheet-chrome'),body=panel.querySelector('[data-dsa-sheet-content]'),bound=0;
  for(const step of ['Verify account','Enter code','Finish security']){
   renderPhoneKeyStep('<h2>'+step+'</h2><input id="synthetic-factor" aria-label="Synthetic factor"><button type="button">Continue</button>',()=>bound++);
   check(overlayRoot.querySelector('[role=dialog]')===panel&&panel.querySelector('.dsa-sheet-chrome')===chrome&&panel.querySelector('[data-dsa-sheet-content]')===body,step+' retains the original authentication shell');
   check(panel.querySelectorAll('[data-dsa-sheet-grabber]').length===1&&panel.querySelectorAll('[data-dsa-sheet-content]').length===1&&!panel.querySelector('[data-dsa-pk-close]'),step+' has one close action and one scroll owner');
   check(panel.classList.contains('is-sheet-entered')&&getComputedStyle(panel).animationName==='none',step+' does not reopen the Sheet');
  }
  check(bound===3,'Every authentication step binds its own controls exactly once');
  panel=openFixture('profile','data-dsa-profile-panel');const profileMarkup='<section class="dsa-panel dsa-profile-panel" role="dialog" aria-label="Profile" data-dsa-profile-panel><button type="button" data-dsa-profile-edit aria-expanded="false">Edit</button><div data-dsa-profile-edit-region hidden><input name="synthetic_profile_name" aria-label="Synthetic name"></div><button type="button" data-dsa-account-logout>Sign out</button></section>';
  replaceOverlayContent(profileMarkup,{module:getSurfaceModule('profile'),reason:'profile_return'});bindProfilePanel();replaceOverlayContent('<section class="dsa-panel dsa-account-view" role="dialog" aria-label="Addresses"><h2>Addresses</h2></section>',{module:getSurfaceModule('profile'),reason:'account_addresses'});replaceOverlayContent(profileMarkup,{module:getSurfaceModule('profile'),reason:'profile_return'});bindProfilePanel();bindProfilePanel();const edit=panel.querySelector('[data-dsa-profile-edit]'),region=panel.querySelector('[data-dsa-profile-edit-region]');edit.click();
  check(!region.hidden&&edit.getAttribute('aria-expanded')==='true','Profile to account and back retains a single effective Edit handler');edit.click();check(region.hidden&&edit.getAttribute('aria-expanded')==='false','The next Profile Edit click reverses exactly once');
  panel=openFixture('checkout');body=panel.querySelector('[data-dsa-sheet-content]');body.innerHTML=shippingMarkup();const form=body.querySelector('form'),toggle=form.querySelector('[data-dsa-checkout-shipping-toggle]'),shipping=form.querySelector('[data-dsa-checkout-shipping-fields]');
  updateCheckoutShippingFields(form);
  check(shipping.hidden&&shipping.disabled&&getComputedStyle(shipping).display==='none','Unticked different shipping is hidden and inactive');
  check(toggle.getAttribute('aria-expanded')==='false'&&!Object.hasOwn(collectCheckoutValues(form),'shipping_city'),'Unticked shipping does not validate or submit shipping fields');
  toggle.checked=true;updateCheckoutShippingFields(form);
  check(!shipping.hidden&&!shipping.disabled&&getComputedStyle(shipping).display!=='none','Different shipping reveals immediately when selected');
  check(toggle.getAttribute('aria-expanded')==='true'&&collectCheckoutValues(form).shipping_city==='Synthetic shipping','Selected shipping is included with matching expanded semantics');
  toggle.checked=false;updateCheckoutShippingFields(form);
  check(shipping.hidden&&shipping.disabled&&form.querySelector('[name="shipping_city"]').value==='Synthetic shipping','Closing shipping retains the draft without requiring it');
  check(!Object.hasOwn(collectCheckoutValues(form),'shipping_city'),'Reclosed shipping fields stay out of the saved payload');
  panel=openFixture('ai');const oldPanel=panel,oldButton=document.createElement('button');oldButton.textContent='Choose alerts';panel.querySelector('[data-dsa-sheet-content]').prepend(oldButton);let eventStayed=false;
  const onClick=event=>{eventStayed=isSheetContentClick(event,overlayRoot.querySelector('[role=dialog]'));if(!eventStayed)closeOverlay();};
  overlayRoot.addEventListener('click',onClick);oldButton.onclick=()=>replaceOverlayContent(mark('notifications'),{module:getSurfaceModule('notifications'),reason:'choose_alerts'});const before=closeCount;oldButton.click();
  check(!oldPanel.isConnected&&eventStayed&&!overlayRoot.hidden&&closeCount===before,'Clicking an old dialog control cannot dismiss its newly opened destination');
  overlayRoot.click();check(overlayRoot.hidden&&closeCount===before+1,'A real backdrop click remains dismissible');overlayRoot.removeEventListener('click',onClick);
  check(phoneKeyCloseButton()==='','Sheet authentication omits the duplicate X');surface.dataset.dsaTheme='classic';surface.classList.replace('dsa-theme-sheet','dsa-theme-classic');check(phoneKeyCloseButton().includes('data-dsa-pk-close'),'Classic authentication retains its close control');surface.dataset.dsaTheme='sheet';surface.classList.replace('dsa-theme-classic','dsa-theme-sheet');
  check(document.documentElement.scrollWidth<=window.innerWidth,'Continuity fixture creates no document horizontal overflow');
  document.getElementById('receipt').textContent=JSON.stringify({passed:true,checks,hashes:sourceHashes},null,2);
 }catch(error){if(!overlayRoot.hidden)closeOverlay();document.getElementById('receipt').textContent=JSON.stringify({passed:false,error:error.message,checks,hashes:sourceHashes},null,2);}
};
</script></body></html>`;
fs.writeFileSync(path.join(destination,'index.html'),page);
fs.writeFileSync(path.join(destination,'surface.css'),css);
console.log(JSON.stringify({passed:true,checks,hashes,fixture:destination,scope:'Generated actual-function fixture; browser checks have not run'}));
