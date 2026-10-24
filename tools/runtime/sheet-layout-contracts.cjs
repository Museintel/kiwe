/* Shared runtime functions and real stylesheet, synthetic records only. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/js/surface.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/css/surface.css'), 'utf8');
const names = ['usesSheetPresentation','prepareSheetPanel','setSheetModal','onSheetFocus','settleSheetPanel','bindSheetDrag','onOverlayKeydown','resetOverlayScrollPosition','replaceOverlayContent','setCartPanelBusy'];
function extract(name) {
 const start = source.indexOf('\tfunction '+name+'(');
 assert(start >= 0, 'Existing runtime owner '+name);
 const end = source.indexOf('\n\tfunction ', start + 1);
 const fn = source.slice(start, end < 0 ? source.length : end);
 new vm.Script(fn);
 return fn;
}
const functions = names.map(extract).join('\n');
const profileSource=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/js/modules/profile-panel.js'),'utf8').replace(/export /g,'');
const profileContext={};vm.runInNewContext(profileSource+';this.status=verificationStatus;',profileContext);
const nativeBadge=profileContext.status({verified:false});
assert(source.includes('setSheetModal( active );'));
assert(source.includes('requiredStandaloneGateActive()'));
assert(source.includes("event.type !== 'pointercancel'"));
assert(css.includes('grid-auto-columns: minmax(0, 1fr);'));
assert(css.includes('.dsa-sheet-content .dsa-search-panel__results'));
assert(css.includes('.dsa-surface.has-modal-sheet > .dsa-dock-cluster'));
for(const block of css.matchAll(/:root\s*\{([^}]+)\}/g))assert(!/--dsa-runtime-token-\d+:\s*[^;]*var\(--dsa-sheet-(?:duration|max-height|width-percent)/.test(block[1]),'Native Sheet parameters cannot be frozen into inherited root aliases');
for(const key of ['0611','0612','0613','0617','0621','0624','0628'])assert(new RegExp('\\.dsa-theme-sheet\\s*\\{[^}]*--dsa-runtime-token-'+key+':').test(css),'Sheet parameter alias resolves at its native settings owner '+key);
if (!process.argv.includes('--serve')) {
 console.log(JSON.stringify({passed:true,checks:names.length+14,scope:'Source-bound shared shell and native parameter scopes; behavioral and geometry acceptance uses the Chrome fixture/native site'}));
 process.exit(0);
}
const page = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kiwe shared sheet acceptance</title><link rel="stylesheet" href="/surface.css"><style>
html.dsa-overlay-active{overflow:hidden}body{margin:0;font:16px system-ui;background:#f7f6f2;color:#19352b}main{padding:24px;min-height:150vh}button{font:inherit;cursor:pointer}#fixture-bar{display:flex;flex-wrap:wrap;gap:8px}#fixture-bar button{padding:8px}#receipt{white-space:pre-wrap}.dsa-surface{--dsa-screen-gutter:16px;--dsa-sheet-max-height:82dvh;--dsa-admin-bar-height:0px}.dsa-dock-cluster button{padding:12px}.fixture-result{padding:16px;background:var(--dsa-flat-surface-alt);border:1px solid var(--dsa-flat-line);border-radius:12px}.fixture-result h3{margin:0}.fixture-result p{margin:8px 0}.fixture-records{display:grid;gap:12px}.dsa-overlay-root [hidden]{display:none!important}
</style></head><body><main><h1>Shared sheet acceptance</h1><div id="fixture-bar"><button id="open-search">Search</button><button id="open-profile">Profile</button><button id="open-cart">Cart</button><button id="open-menu">Menu</button><button id="run">Run interaction checks</button><label>Presentation<select id="presentation"><option>sheet</option><option>classic</option></select></label><label>Position<select id="position"><option>bottom</option><option>right</option><option>left</option></select></label></div><pre id="receipt">Ready</pre><div id="prior-inert" inert="retained">Existing inert content</div></main><div class="dsa-surface dsa-theme-sheet dsa-sheet-position-bottom dsa-sheet-spacing-edge dsa-sheet-backdrop-blur dsa-visual-prototype" data-dsa-ui-contract="2" data-dsa-layout="wide" data-dsa-surface data-dsa-theme="sheet" data-dsa-sheet-position="bottom"><div class="dsa-dock-cluster"><button id="dock-search">Search</button><button id="dock-profile">Profile</button></div><div class="dsa-overlay-root" data-dsa-overlay-root hidden></div><div class="dsa-live-region" aria-live="polite"></div></div><script>
const surface=document.querySelector('.dsa-surface'),overlayRoot=document.querySelector('.dsa-overlay-root'),liveRegion=document.querySelector('.dsa-live-region'),scrim=null;
let sheetInertElements=[],lastFocusedElement=null,closeCount=0,activeOverlayPanel='',activeOverlayModuleId='',activeOverlayMode='dock',activeOverlayLifecycle=null,overlayContentSequence=0;
function getSurfaceModule(id){return {id,panel:id};}
function dispatchSurfaceLifecycle(){} function clearDockContextRail(){} function applyOverlayHeadingTag(){} function applySeamOverlayContract(){} function syncDockContextRail(){} function cssEscape(value){return CSS.escape(value);}
function resetNavigationState(){}
function closeOverlay(){closeCount++;setSheetModal(false);overlayRoot.hidden=true;overlayRoot.innerHTML='';document.documentElement.classList.remove('dsa-overlay-active');document.removeEventListener('keydown',onOverlayKeydown);if(lastFocusedElement&&lastFocusedElement.isConnected)lastFocusedElement.focus({preventScroll:true});}
${functions}
const nativeBadge=${JSON.stringify(nativeBadge)};
function records(){return '<div class="fixture-records">'+Array.from({length:18},(_,i)=>'<article class="fixture-result"><h3>Sample record '+(i+1)+'</h3><p>Public synthetic content for scroll and focus acceptance.</p><a href="#sample-'+i+'">View sample '+(i+1)+'</a></article>').join('')+'</div>';}
function markup(kind){
 const search=kind==='search';const profile=kind==='profile';
 return '<section tabindex="-1" class="dsa-panel '+(search?'dsa-search-panel kiwe-search-v2027':profile?'dsa-profile-panel kiwe-profile-v2027':'dsa-'+kind+'-panel')+'" '+(profile?'style="grid-template-columns:96px minmax(0,1fr)"':'')+' role="dialog" aria-modal="false" aria-label="'+kind+'" '+(search?'data-dsa-search-panel':'')+'>'+(search?'<div class="kiwe-search-v2027__title"><p class="dsa-search-panel__eyebrow">Search</p><h2>Find what you need.</h2><p class="dsa-panel__meta">A long introductory description should not compete with results.</p><p class="dsa-panel__meta dsa-search-panel__status" data-dsa-search-status>18 results</p></div><form class="dsa-search-panel__form kiwe-search-v2027__form" role="search"><label for="sample-search">Find a record</label><input id="sample-search" type="search" placeholder="Search sample content"></form><div class="kiwe-search-v2027__controls"><div class="dsa-search-panel__filters"><button type="button">Products</button><button type="button">Posts</button><button type="button">Authors</button><button type="button">Categories</button></div><div class="dsa-search-panel__sort"><button type="button">Latest</button><button type="button">Popular</button></div></div><div class="dsa-search-panel__results" data-dsa-search-results>'+records()+'</div>':'<h2>'+kind+'</h2>'+(profile?'<div class="kiwe-profile-v2027__identity"><span class="kiwe-profile-v2027__avatar">QA</span><span class="kiwe-profile-v2027__person"><small>Kiwe account</small><strong>Synthetic account</strong><em>synthetic@example.invalid</em>'+nativeBadge+'</span><button class="dsa-panel__button kiwe-profile-v2027__edit">Edit</button></div><div class="kiwe-profile-v2027__stats" aria-label="Account activity">'+['Orders','Saved','Kiwe points'].map((label,i)=>'<div class="kiwe-profile-v2027__metric"><strong>'+i+'</strong><span>'+label+'</span></div>').join('')+'</div>':'')+(profile?'<div class="dsa-recent-orders">'+records()+'</div>':records()))+'</section>';
}
function openFixture(kind){if(!overlayRoot.hidden)closeOverlay();activeOverlayPanel=kind;activeOverlayModuleId=kind;lastFocusedElement=document.activeElement;overlayRoot.innerHTML=markup(kind);overlayRoot.hidden=false;prepareSheetPanel();document.documentElement.classList.add('dsa-overlay-active');setSheetModal(true);document.addEventListener('keydown',onOverlayKeydown);overlayRoot.querySelector('[role=dialog]').focus({preventScroll:true});}
for(const kind of ['search','profile','cart','menu'])document.getElementById('open-'+kind).onclick=()=>openFixture(kind);
document.getElementById('dock-search').onclick=()=>openFixture('search');document.getElementById('dock-profile').onclick=()=>openFixture('profile');
document.getElementById('presentation').onchange=e=>{surface.dataset.dsaTheme=e.target.value;surface.classList.toggle('dsa-theme-sheet',e.target.value==='sheet');surface.classList.toggle('dsa-theme-classic',e.target.value==='classic');};
document.getElementById('position').onchange=e=>{for(const p of ['bottom','left','right'])surface.classList.toggle('dsa-sheet-position-'+p,p===e.target.value);surface.dataset.dsaSheetPosition=e.target.value;};
document.getElementById('run').onclick=()=>{
 const checks=[];const check=(ok,label)=>{checks.push({passed:!!ok,label});if(!ok)throw Error(label);};
 try{
  openFixture('search');let panel=overlayRoot.querySelector('[role=dialog]');
  check(panel.getAttribute('aria-modal')==='true','Sheets expose real modal semantics');
  check(panel.querySelectorAll('.dsa-sheet-chrome button').length===1&&!panel.querySelector('.dsa-sheet-close'),'Grabber is the sole fixed Close Sheet action');
  const searchWidth=panel.getBoundingClientRect().width;
  check(panel.querySelectorAll('[data-dsa-sheet-content]').length===1,'One shared content owner');
  check(panel.querySelector('.dsa-sheet-tools [type=search]')!==null,'Search input outside results scroller');
  check(panel.querySelector('.dsa-sheet-content [data-dsa-search-results]')!==null,'Results inside the shared scroller');
  check(document.querySelector('main').hasAttribute('inert'),'Background is inert');
  check(surface.querySelector('.dsa-dock-cluster').hasAttribute('inert'),'Dock is inert while modal');
  check(!surface.hasAttribute('inert')&&!overlayRoot.hasAttribute('inert'),'Modal ancestor path remains usable');
  check(getComputedStyle(surface.querySelector('.dsa-dock-cluster')).visibility==='hidden','Dock is hidden while modal');
  check(getComputedStyle(panel).overflowY==='hidden'&&getComputedStyle(panel.querySelector('.dsa-sheet-content')).overflowY==='auto'&&getComputedStyle(panel.querySelector('[data-dsa-search-results]')).overflowY==='visible','One vertical scroll owner');
  const body=panel.querySelector('.dsa-sheet-content'),handle=panel.querySelector('.dsa-sheet-grabber'),top=handle.getBoundingClientRect().top,formTop=panel.querySelector('form').getBoundingClientRect().top;
  body.scrollTop=body.scrollHeight;check(Math.abs(handle.getBoundingClientRect().top-top)<1,'Close handle stays fixed while content scrolls');check(Math.abs(panel.querySelector('form').getBoundingClientRect().top-formTop)<1,'Search input stays fixed while results scroll');
  resetOverlayScrollPosition();check(body.scrollTop===0,'Opening/reset returns the content to its top');
  const controls=Array.from(panel.querySelectorAll('button,input,a[href]')).filter(el=>el.getClientRects().length&&el.tabIndex>=0);
  controls[0].focus();onOverlayKeydown({key:'Tab',shiftKey:true,preventDefault(){}});check(document.activeElement===controls.at(-1),'Shift Tab wraps within dialog');
  controls.at(-1).focus();onOverlayKeydown({key:'Tab',shiftKey:false,preventDefault(){}});check(document.activeElement===controls[0],'Tab wraps within dialog');
  onSheetFocus({target:document.querySelector('main')});check(document.activeElement===panel,'Outside focus returns to the modal');
  const before=closeCount;handle.setPointerCapture=undefined;handle.releasePointerCapture=undefined;handle.dispatchEvent(new PointerEvent('pointerdown',{clientY:10,button:0}));handle.dispatchEvent(new PointerEvent('pointermove',{clientY:300}));handle.dispatchEvent(new PointerEvent('pointercancel'));check(closeCount===before,'Cancelled drag never dismisses');check(panel.style.transform===''&&!panel.classList.contains('is-sheet-dragging'),'Cancelled drag releases transient state');
  closeOverlay();check(!document.querySelector('main').hasAttribute('inert'),'Background interactivity restored');check(document.getElementById('prior-inert').getAttribute('inert')==='retained','Existing inert state preserved exactly');check(document.activeElement===document.getElementById('run'),'Focus returns to opener');check(getComputedStyle(surface.querySelector('.dsa-dock-cluster')).visibility!=='hidden','Dock is available again');
  openFixture('profile');panel=overlayRoot.querySelector('[role=dialog]');
  check(Math.abs(panel.getBoundingClientRect().width-searchWidth)<1,'Profile and Search share one Sheet width');
  const badge=panel.querySelector('.dsa-panel__status'),person=badge.parentElement,edit=panel.querySelector('.kiwe-profile-v2027__edit');
  check(badge.getBoundingClientRect().width<person.getBoundingClientRect().width,'Verification badge fits its text');
  check(edit.getBoundingClientRect().width<panel.clientWidth/2,'Edit is a compact action');const metrics=Array.from(panel.querySelectorAll('.kiwe-profile-v2027__metric')).map(el=>el.getBoundingClientRect());check(metrics.every(r=>Math.abs(r.top-metrics[0].top)<1),'All three profile metrics share one row');check(metrics.every(r=>r.width>50),'Metrics retain usable widths');check(getComputedStyle(panel.querySelector('.dsa-sheet-content')).display==='flex'&&getComputedStyle(panel.querySelector('.dsa-sheet-content')).flexDirection==='column','Profile content keeps vertical flow despite legacy child grid placements');check(Math.abs(panel.querySelector('.dsa-sheet-chrome').getBoundingClientRect().width-panel.clientWidth)<2,'Fixed chrome stretches across Profile');closeOverlay();

  openFixture('cart');panel=overlayRoot.querySelector('[role=dialog]');panel.classList.add('is-sheet-entered');const oldPanel=panel,oldChrome=panel.querySelector('.dsa-sheet-chrome'),oldContent=panel.querySelector('[data-dsa-sheet-content]');oldContent.scrollTop=70;const action=document.createElement('button');action.setAttribute('data-dsa-cart-quantity','synthetic-line');action.setAttribute('aria-label','Increase quantity');action.textContent='Increase quantity';oldContent.prepend(action);action.focus({preventScroll:true});const oldScroll=oldContent.scrollTop;setCartPanelBusy(panel,true);action.blur();check(action.disabled&&panel.dsaCartFocus===action&&document.activeElement!==action,'Native busy state captures focus before the disabled action loses focus');const refreshMarkup=markup('cart').replace('<h2>cart</h2>','<h2>cart</h2><button data-dsa-cart-quantity="synthetic-line" aria-label="Increase quantity">Increase quantity</button>');replaceOverlayContent(refreshMarkup,{module:{id:'cart',panel:'cart'},reason:'cart_refresh'});panel=overlayRoot.querySelector('[role=dialog]');check(panel===oldPanel&&panel.querySelector('.dsa-sheet-chrome')===oldChrome,'Same-panel cart refresh retains the original dialog and grabber');check(panel.classList.contains('is-sheet-entered')&&getComputedStyle(panel).animationName==='none','Cart refresh never replays the entry animation');check(oldContent.scrollTop===oldScroll,'Cart refresh preserves its scroll position');check(document.activeElement.getAttribute('data-dsa-cart-quantity')==='synthetic-line','Quantity focus survives refresh after the native disabled-button blur');setCartPanelBusy(panel,false);const restoredFocus=document.activeElement;setCartPanelBusy(panel,true);restoredFocus.blur();setCartPanelBusy(panel,false);check(document.activeElement===restoredFocus&&!restoredFocus.disabled&&!panel.dsaCartFocus,'Failed unchanged cart request returns focus after re-enabling the original action');closeOverlay();
  openFixture('profile');const profileDialog=overlayRoot.querySelector('[role=dialog]');replaceOverlayContent('<section class="dsa-panel dsa-account-view" role="dialog" aria-label="Addresses" data-dsa-account-view="addresses"><h2>Addresses</h2><button>Back</button></section>',{module:{id:'profile',panel:'profile'},reason:'account_loading'});const accountDialog=overlayRoot.querySelector('[role=dialog]');check(accountDialog===profileDialog&&accountDialog.getAttribute('data-dsa-account-view')==='addresses'&&accountDialog.getAttribute('aria-label')==='Addresses','Profile navigation retains the shared shell and receives its new dialog attributes');closeOverlay();
  surface.dataset.dsaTheme='classic';surface.classList.replace('dsa-theme-sheet','dsa-theme-classic');openFixture('menu');panel=overlayRoot.querySelector('[role=dialog]');check(!panel.querySelector('[data-dsa-sheet-content]')&&panel.getAttribute('aria-modal')==='false','Classic presentation retains its own contract');check(!document.querySelector('main').hasAttribute('inert'),'Classic does not acquire sheet modality');closeOverlay();surface.dataset.dsaTheme='sheet';surface.classList.replace('dsa-theme-classic','dsa-theme-sheet');
  document.getElementById('receipt').textContent=JSON.stringify({passed:true,checks},null,2);
 }catch(error){if(!overlayRoot.hidden)closeOverlay();document.getElementById('receipt').textContent=JSON.stringify({passed:false,error:error.message,checks},null,2);}
};
</script></body></html>`;
require('node:http').createServer((request,response)=>{
 if(request.url==='/'){response.writeHead(200,{'content-type':'text/html;charset=utf-8'});response.end(page);}
 else if(request.url==='/surface.css'){response.writeHead(200,{'content-type':'text/css;charset=utf-8'});response.end(css);}
 else {response.writeHead(404);response.end();}
}).listen(8320,'127.0.0.1',()=>console.log('Source-bound synthetic sheet acceptance at http://127.0.0.1:8320'));
