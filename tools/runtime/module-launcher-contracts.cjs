/* Execute shipped event owners locally; --fixture emits the same owners for real
 * Chrome DOM, keyboard, mutation and responsive acceptance (no WordPress data). */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'../..');
const source=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/js/surface.js'),'utf8');
function extract(name){const start=source.indexOf('\tfunction '+name+'('),end=source.indexOf('\n\tfunction ',start+1);assert(start>=0&&end>start,name);return source.slice(start,end);}
const start=source.indexOf("\tdocument.addEventListener( 'click', function ( event ) {\n\t\tconst launcher = closestEventTarget( event, '[data-dsa-open-module]' );");
const end=source.indexOf('\n\ttry {\n\t\tconst directAuth',start);
assert(start>=0&&end>start,'actual delegated module events');const events=source.slice(start,end);
let checks=0;function check(ok,label){assert(ok,label);checks++;}
const listeners={},opened=[];let enhanced=0,focus=0,clicked=0,themes=0;
const launcher={dataset:{dsaOpenModule:'search'},closest:()=>null,getAttribute:()=>'',focus:()=>focus++,click:()=>clicked++};
const c={document:{addEventListener:(name,fn)=>(listeners[name]??=[]).push(fn)},overlayRoot:{},commerce:{available:true,settings:{cartSurfaceEnabled:true}},searchConfig:{},moduleItems:()=>[],closestEventTarget:(e,s)=>s.includes('data-dsa-open-module')?e.target:null,enhanceModuleLaunchers:()=>enhanced++,openOverlay:(...args)=>opened.push(args),toggleColorMode:()=>themes++};
vm.runInNewContext(extract('externalModuleAvailable')+'\n'+events,c);
function event(target=launcher,extra={}){return{target,preventDefault(){this.prevented=true;},stopPropagation(){this.stopped=true;},...extra};}
function dispatch(name,e){for(const fn of listeners[name]||[])fn(e);return e;}
check(dispatch('click',event()).prevented&&opened.length===1&&enhanced===1&&focus===1,'cosmetic search delegates once and focuses its launcher before the shared overlay');
check(dispatch('pointerdown',event()).prevented&&opened.length===1,'pointerdown prevents native field focus without opening twice');
dispatch('keydown',event(launcher,{key:'Enter',repeat:false}));check(clicked===1,'Enter activates the same launcher');
dispatch('keydown',event(launcher,{key:' ',repeat:false}));check(clicked===2,'Space activates the same launcher');
dispatch('keydown',event(launcher,{key:'Enter',repeat:true}));check(clicked===2,'held activation does not repeat');
check(!dispatch('keydown',event(launcher,{key:'a'})).prevented,'ordinary keys are not hijacked');
const form={matches:()=>true,closest:s=>s==='[data-dsa-surface]'?null:launcher,querySelector:()=>null};
check(dispatch('submit',event(form)).prevented&&clicked===3,'marked form submission opens the shared search instead of navigating');
c.searchConfig.enabled=false;check(!dispatch('click',event()).prevented&&!dispatch('pointerdown',event()).prevented&&!dispatch('submit',event(form)).prevented,'unavailable Search preserves native click, field and form fallbacks');c.searchConfig.enabled=true;
launcher.dataset.dsaOpenModule='cart';check(dispatch('click',event()).prevented&&opened.at(-1)[0]==='cart','custom cart icon opens the shared Cart');
c.commerce.available=false;check(!dispatch('click',event()).prevented&&!dispatch('keydown',event(launcher,{key:'Enter'})).prevented,'non-Woo cart keeps its native fallback');c.commerce.available=true;
c.commerce.settings.cartSurfaceEnabled=false;check(!dispatch('click',event()).prevented,'unavailable Cart does not intercept');c.commerce.settings.cartSurfaceEnabled=true;
launcher.dataset.dsaOpenModule='notifications';dispatch('click',event());check(opened.at(-1)[0]==='ai','public Notifications attribute opens the inbox rather than alert preferences');
launcher.dataset.dsaOpenModule='theme';dispatch('click',event());check(themes===1,'existing theme action is preserved');
launcher.dataset.dsaOpenModule='unregistered';check(!dispatch('click',event()).prevented,'unregistered destination preserves native fallback');
launcher.dataset.dsaOpenModule='search';launcher.closest=()=>({});check(!dispatch('click',event()).prevented&&!dispatch('pointerdown',event()).prevented,'internal Surface controls are excluded');launcher.closest=()=>null;
c.overlayRoot=null;check(!dispatch('click',event()).prevented,'absent runtime overlay preserves native fallback');
const functions=['moduleItems','getSurfaceModule','closestEventTarget','externalModuleAvailable','updateExternalCartBadge','updateExternalModuleBadge','enhanceModuleLaunchers','updateDockBadge','enhanceFormMessageAccessibility','bindFormMessageAccessibility'].map(extract).join('\n');
const hash=crypto.createHash('sha256').update(source).digest('hex');
if(process.argv[2]==='--fixture'){
 const destination=path.resolve(process.argv[3]);fs.mkdirSync(destination,{recursive:true});
 fs.copyFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/css/surface.css'),path.join(destination,'surface.css'));
 const test=String.raw`
const results=[];function proof(ok,label){results.push({label,passed:Boolean(ok)});if(!ok)throw Error(label);}
const frame=()=>new Promise(resolve=>setTimeout(resolve,0));
async function run(){
const form=document.querySelector('#cosmetic'),input=form.querySelector('input'),normal=document.querySelector('#ordinary'),direct=document.querySelector('#direct');
const svg=document.querySelector('#vector'),image=document.querySelector('#image');const svgBefore=svg.getBoundingClientRect(),imageBefore=image.getBoundingClientRect();
bindFormMessageAccessibility();await frame();
proof(form.getAttribute('role')==='button'&&form.tabIndex===0&&input.readOnly&&input.tabIndex===-1,'cosmetic form is one keyboard launcher with a readonly decorative input');
proof(!normal.readOnly&&!normal.hasAttribute('role'),'unmarked search field stays editable');
proof(direct.readOnly&&direct.getAttribute('role')==='button'&&direct.tabIndex===0,'attribute directly on a search input also works');
proof(document.querySelector('#icon').getAttribute('role')==='button'&&document.querySelector('#icon').tabIndex===0,'attributed icon gains keyboard semantics');
proof(document.querySelectorAll('[data-dsa-launcher-badge]').length===4&&[...document.querySelectorAll('[data-dsa-launcher-badge]')].every(e=>e.hidden),'all HTML, button, SVG and image cart badges hide at zero');
proof(svg===document.querySelector('#vector')&&Math.abs(svg.getBoundingClientRect().width-svgBefore.width)<1&&Math.abs(svg.getBoundingClientRect().height-svgBefore.height)<1,'SVG carrier preserves the original icon node and dimensions');
proof(image===document.querySelector('#image')&&Math.abs(image.getBoundingClientRect().width-imageBefore.width)<1,'image carrier preserves original width');
const count=n=>{phonekey.cart.count=n;updateDockBadge('cart',n);};count(3);await frame();
proof([...document.querySelectorAll('[data-dsa-launcher-badge], [data-dsa-badge=cart]')].every(e=>!e.hidden&&e.textContent==='3'),'custom and dock badges share exact initial quantity');
count(2);count(2);await frame();proof(document.querySelectorAll('[data-dsa-launcher-badge]').length===4,'repeated cart refresh never duplicates badges');
proof(document.querySelector('#icon').getAttribute('aria-label')==='Open basket, 2 items in cart','authored accessible label is preserved and count does not compound');
document.querySelector('#icon').setAttribute('aria-label','My basket');count(1);proof(document.querySelector('#icon').getAttribute('aria-label')==='My basket, 1 item in cart','later authored accessible label changes remain authoritative');
surface.querySelector('[data-dsa-module=cart]').hidden=true;count(4);proof(document.querySelector('#icon [data-dsa-launcher-badge]').textContent==='4','hidden dock does not disable external badges');
const pointer=new PointerEvent('pointerdown',{bubbles:true,cancelable:true,pointerType:'touch'});input.dispatchEvent(pointer);proof(pointer.defaultPrevented&&opens===0,'touch pointerdown avoids cosmetic input focus and duplicate opening');
input.click();proof(opens===1&&document.activeElement===document.querySelector('#search-field'),'click moves typing to the Search screen');
document.querySelector('#close').click();proof(document.activeElement===form,'closing restores the cosmetic launcher focus');
form.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true}));proof(opens===2,'Enter opens Search once');document.querySelector('#close').click();
form.dispatchEvent(new KeyboardEvent('keydown',{key:' ',bubbles:true,cancelable:true}));proof(opens===3,'Space opens Search once');document.querySelector('#close').click();
form.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',repeat:true,bubbles:true,cancelable:true}));proof(opens===3,'held Enter does not reopen Search');
const submit=new Event('submit',{bubbles:true,cancelable:true});form.dispatchEvent(submit);proof(submit.defaultPrevented&&opens===4,'cosmetic form submit cannot navigate away');document.querySelector('#close').click();
proof(input.value===''&&!normal.readOnly,'cosmetic input stays empty while normal search is unchanged');
const late=document.createElement('button');late.setAttribute('data-dsa-open-module','cart');late.textContent='Late cart';document.querySelector('#late').appendChild(late);await frame();await frame();proof(late.querySelector('[data-dsa-launcher-badge]').textContent==='4','existing mutation observer binds late AJAX launchers to current count');
const lateForm=document.createElement('form');lateForm.setAttribute('data-dsa-open-module','search');lateForm.innerHTML='<input type=search>';document.querySelector('#late').appendChild(lateForm);await frame();await frame();proof(lateForm.tabIndex===0&&lateForm.querySelector('input').readOnly,'late search forms become cosmetic launchers');
count(0);proof([...document.querySelectorAll('[data-dsa-launcher-badge], [data-dsa-badge=cart]')].every(e=>e.hidden),'removal and empty cart hide every badge');
commerce.available=false;const noWoo=document.createElement('a');noWoo.href='#fallback';noWoo.setAttribute('data-dsa-open-module','cart');document.querySelector('#late').appendChild(noWoo);await frame();proof(!noWoo.querySelector('[data-dsa-launcher-badge]')&&!noWoo.classList.contains('dsa-module-launcher'),'non-Woo launcher receives no phantom badge or decoration');
const nonWooClick=new MouseEvent('click',{bubbles:true,cancelable:true});noWoo.dispatchEvent(nonWooClick);proof(!nonWooClick.defaultPrevented,'non-Woo native link remains usable');commerce.available=true;
searchConfig.enabled=false;const disabled=document.createElement('input');disabled.type='search';disabled.setAttribute('data-dsa-open-module','search');document.querySelector('#late').appendChild(disabled);await frame();proof(!disabled.readOnly&&!disabled.hasAttribute('role'),'unavailable Search does not lock native fields');
const bell=document.createElement('button');bell.setAttribute('data-dsa-open-module','notifications');bell.setAttribute('aria-label','My updates');bell.innerHTML='<svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0v9H6z"/></svg>';document.querySelector('#late').appendChild(bell);await frame();await frame();proof(bell.querySelector('[data-dsa-launcher-badge=ai]').hidden,'notification attribute starts without a phantom badge');
notificationCount=2;updateDockBadge('ai',2);proof(bell.querySelector('[data-dsa-launcher-badge=ai]').textContent==='2'&&bell.getAttribute('aria-label')==='My updates, 2 notifications','custom notification icon shares count and accessible label');
pendingActions=[{id:'eligible'}];checkoutPage=true;updateDockBadge('ai',2);proof(bell.classList.contains('dsa-notification-reminder'),'custom notification icon inherits pending checkout reminder');
const reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;proof(getComputedStyle(bell.querySelector('svg')).animationName===(reduced?'none':'dsa-ai-ring'),'custom bell follows the current reduced-motion preference');
updateDockBadge('ai',2);proof(bell.querySelectorAll('[data-dsa-launcher-badge]').length===1,'refresh does not duplicate notification badge');
pendingActions=[];notificationCount=0;updateDockBadge('ai',0);proof(bell.querySelector('[data-dsa-launcher-badge]').hidden&&!bell.classList.contains('dsa-notification-reminder'),'resolving actions clears badge and ring');
const legacyBell=document.createElement('i');legacyBell.setAttribute('data-dsa-open-module','ai');document.querySelector('#late').appendChild(legacyBell);notificationCount=1;await frame();await frame();proof(legacyBell.querySelector('[data-dsa-launcher-badge=ai]').textContent==='1','legacy AI notification attribute remains compatible');
const notificationImage=document.createElement('img');notificationImage.src=image.src;notificationImage.alt='Notification image';notificationImage.setAttribute('data-dsa-open-module','notifications');document.querySelector('#late').appendChild(notificationImage);const notificationSvg=svg.cloneNode(true);notificationSvg.id='notification-svg';notificationSvg.setAttribute('data-dsa-open-module','notifications');document.querySelector('#late').appendChild(notificationSvg);pendingActions=[{id:'still-required'}];await frame();await frame();updateDockBadge('ai',1);
proof(getComputedStyle(legacyBell).animationName===(reduced?'none':'dsa-ai-ring'),'direct Bricks font icon receives the reminder motion');
proof(notificationImage.parentElement.querySelector('[data-dsa-launcher-badge=ai]').textContent==='1'&&getComputedStyle(notificationImage).animationName===(reduced?'none':'dsa-ai-ring'),'image notification launcher shares badge and reminder');
proof(notificationSvg.parentElement.querySelector('[data-dsa-launcher-badge=ai]').textContent==='1'&&getComputedStyle(notificationSvg).animationName===(reduced?'none':'dsa-ai-ring'),'direct SVG notification launcher shares badge and reminder');
pendingActions=[];notificationCount=0;updateDockBadge('ai',0);
proof(document.documentElement.scrollWidth<=window.innerWidth,'launcher decorations do not create document overflow');
}
run().catch(error=>{results.push({label:error.message,passed:false});}).finally(()=>{document.querySelector('#results').textContent=JSON.stringify({passed:results.every(x=>x.passed),checks:results.length,sourceSha256:sourceHash,results},null,2);});
`;
 const html='<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kiwe authored launcher acceptance</title><link rel="stylesheet" href="surface.css"><style>body{margin:0;padding:16px;font:16px system-ui;background:#f8f7f3}header{display:flex;align-items:center;flex-wrap:wrap;gap:18px}#cosmetic{flex:1;min-width:140px;padding:8px;border:1px solid #bbb;border-radius:24px}input{box-sizing:border-box;max-width:100%}#cosmetic input{width:100%;border:0;background:transparent}#icon,header button{display:inline-block;padding:6px}svg,img{width:28px;height:28px}#overlay[hidden]{display:none}#overlay{position:fixed;inset:15%;padding:30px;background:white;border:2px solid #234;z-index:5}pre{white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px}#late{display:flex;gap:12px;flex-wrap:wrap}#late form{max-width:160px}</style><body><header><form id="cosmetic" data-dsa-open-module="search"><input type="search" placeholder="Search sweets"></form><i id="icon" data-dsa-open-module="cart" aria-label="Open basket">🛒</i><button type="button" data-dsa-open-module="cart">Basket</button><svg id="vector" data-dsa-open-module="cart" viewBox="0 0 24 24" aria-label="Vector cart"><path d="M2 2h4l4 14h10l2-10H7" fill="none" stroke="currentColor"/></svg><img id="image" data-dsa-open-module="cart" alt="Image basket" src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2228%22 height=%2228%22%3E%3Crect width=%2228%22 height=%2228%22 fill=%22green%22/%3E%3C/svg%3E"></header><p><input id="ordinary" type="search" placeholder="Ordinary search"><input id="direct" type="search" data-dsa-open-module="search" placeholder="Direct launcher"></p><div id="surface" data-dsa-surface><button data-dsa-module="cart">Dock <span data-dsa-badge="cart" hidden></span></button></div><div id="overlay" hidden></div><div id="late"></div><pre id="results">Running…</pre><script>const sourceHash='+JSON.stringify(hash)+';const surface=document.querySelector("#surface"),overlayRoot=document.querySelector("#overlay"),externalLauncherLabels=new WeakMap(),moduleContract={items:[{id:"search",label:"Search"},{id:"cart",label:"Cart"}]},commerce={available:true,settings:{cartSurfaceEnabled:true}},searchConfig={},phonekey={cart:{count:0}};let opens=0,returnTo=null;let pendingActions=[],checkoutPage=false,notificationCount=0;const pendingNotificationActions=()=>pendingActions,isCurrentCheckoutPage=()=>checkoutPage,unreadAiNotificationCount=()=>notificationCount;const screenLabel=(id,fallback)=>fallback,isAdvertisingNode=()=>false,toggleColorMode=()=>{};function openOverlay(module,label){opens++;returnTo=document.activeElement;overlayRoot.hidden=false;overlayRoot.innerHTML="<h2>"+module+"</h2><input id=search-field><button id=close>Close</button>";overlayRoot.querySelector("input").focus();overlayRoot.querySelector("button").onclick=()=>{overlayRoot.hidden=true;returnTo.focus();};}\n'+functions+'\n'+events+'\n'+test+'<\/script></body></html>';
 fs.writeFileSync(path.join(destination,'index.html'),html);console.log(JSON.stringify({passed:true,fixture:destination,sourceSha256:hash}));
}else console.log(JSON.stringify({passed:true,checks,sourceSha256:hash,scope:'shipped external module event owners'}));
