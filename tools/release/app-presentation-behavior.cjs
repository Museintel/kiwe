const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('wp-content/mu-plugins/dsa/assets/js/surface.js','utf8');
function fn(name){const start=source.indexOf('\tfunction '+name+'(');assert(start>=0,name);const end=source.indexOf('\n\tfunction ',start+1);return source.slice(start,end<0?source.length:end);}
const classes=()=>{const set=new Set();return {add:v=>set.add(v),remove:v=>set.delete(v),toggle(v,on){on?set.add(v):set.delete(v);},contains:v=>set.has(v)};};
const store=new Map();
const ctx={window:{sessionStorage:{getItem:key=>store.get(key)||null,setItem:(key,value)=>store.set(key,value)}},lastTransitionMessage:'',visual:{transition_content:'animation',transition_title:'Please wait',transition_title_position:'above'},loader:{classList:classes()},loaderMessage:{classList:classes(),querySelector:()=>null},loaderTitle:{},loaderCopy:{},loaderLabel:{},syncLoaderTrustBadges(){},surfaceTriggerEnabled:(id,value)=>value};vm.createContext(ctx);vm.runInContext(['chooseTransitionMessage','renderLoaderMessage','renderLoaderMessageItem','shouldShowNavigationSurface'].map(fn).join('\n'),ctx);
let checks=0;function test(ok){assert(ok);checks++;}
test(ctx.renderLoaderMessage('https://site.invalid/checkout/')&&ctx.loader.classList.contains('is-animation')&&ctx.loaderTitle.textContent==='Please wait'&&ctx.loaderCopy.hidden);
ctx.visual.transition_title_position='below';ctx.renderLoaderMessage();test(ctx.loaderMessage.classList.contains('is-title-below'));
ctx.visual.transition_title='';test(!ctx.renderLoaderMessage()&&ctx.loaderMessage.hidden&&!ctx.loader.classList.contains('has-message'));
ctx.visual.transition_content='messages';ctx.visual.transition_messages=[{title:'',message:''},{title:'First',message:'One'},{title:'Second',message:'Two'}];ctx.visual.transition_message_mode='fixed';ctx.visual.transition_message_index=2;ctx.renderLoaderMessage('https://site.invalid/cart/');test(ctx.loader.classList.contains('is-messages')&&ctx.loaderTitle.textContent==='Second'&&ctx.loaderCopy.textContent==='Two');
ctx.visual.transition_message_index=0;ctx.renderLoaderMessage();test(ctx.loaderTitle.textContent==='First');
ctx.visual.transition_message_mode='random';for(let i=0;i<15;i++){ctx.renderLoaderMessage();test(['First','Second'].includes(ctx.loaderTitle.textContent));}
ctx.visual.transition_messages=[];test(!ctx.renderLoaderMessage()&&ctx.loaderMessage.hidden);
ctx.visual.show_on_navigation=true;ctx.visual.show_on_page_out=false;test(ctx.shouldShowNavigationSurface());ctx.visual.show_on_navigation=false;ctx.visual.show_on_page_out=true;test(!ctx.shouldShowNavigationSurface());
console.log(JSON.stringify({passed:true,checks,scope:'Actual transition functions: animation/title, stored message identity, random selection and one navigation gate'}));
