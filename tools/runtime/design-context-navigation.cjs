const {readFileSync}=require('node:fs');
const {resolve}=require('node:path');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const code=readFileSync(resolve(__dirname,'../../wp-content/mu-plugins/dsa/assets/js/onboarding.js'),'utf8');
function element(extra={}) { return Object.assign({hidden:false,disabled:false,dataset:{},listeners:{},addEventListener(type,fn){this.listeners[type]=fn;},setAttribute(k,v){this[k]=v;},removeAttribute(k){delete this[k];},querySelector(){return null;}},extra); }
function boot(commerce, saved=false, startStep=0, singleSection=false){
 const ids=singleSection?[startStep]:commerce?[0,1,2,3,4,6,5]:[0,1,2,3,4,6];
 const panels=ids.map(id=>element({dataset:{kiweStep:String(id)}}));
 const buttons=singleSection?[]:ids.map(id=>element({dataset:{kiweStepButton:String(id)}}));
 const prev=element(),next=element(),save=element(),status=element(),toggle=element({checked:false}),fields=element();
 const selectors={'[data-kiwe-prev]':prev,'[data-kiwe-next]':next,'[data-kiwe-save]':save,'[data-kiwe-step-status]':status,'[data-kiwe-services-toggle]':toggle,'[data-kiwe-services-fields]':fields};
 if(singleSection){delete selectors['[data-kiwe-prev]'];delete selectors['[data-kiwe-next]'];}
 const root=element({offsetTop:0,querySelector(s){return selectors[s]||null;},querySelectorAll(s){return s==='[data-kiwe-step]'?panels:s==='[data-kiwe-step-button]'?buttons:[];}});
 vm.runInNewContext(code,{document:{querySelector(){return root;}},window:{KIWE_ONBOARDING:{saved,startStep,singleSection},scrollTo(){}},console});
 return {panels,buttons,prev,next,save,status,toggle,fields};
}
for(const commerce of [false,true]){
 const ui=boot(commerce);
 assert.equal(ui.panels[0].hidden,false);
 assert.equal(ui.fields.disabled,true);
 ui.toggle.checked=true;ui.toggle.listeners.change();assert.equal(ui.fields.hidden,false);assert.equal(ui.fields.disabled,false);
 ui.buttons[5].listeners.click();assert.equal(ui.panels[5].dataset.kiweStep,'6');assert.equal(ui.panels[5].hidden,false);
 while(!ui.next.hidden)ui.next.listeners.click();
 assert.equal(ui.panels.at(-1).dataset.kiweStep,commerce?'5':'6');assert.equal(ui.save.hidden,false);
 assert.equal(ui.status.textContent,`Step ${ui.panels.length} of ${ui.panels.length}`);
 const saved=boot(commerce,true);assert.equal(saved.panels.at(-1).hidden,false);assert.match(saved.status.textContent,/Saved/);
 const contact=boot(commerce,false,2);assert.equal(contact.panels[2].hidden,false);
 console.log(`PASS ${commerce?'commerce':'non-commerce'} legacy navigation, services toggle and save without Review`);
}
for(const id of [0,1,2,3,4,6,5]){
 const ui=boot(id===5,false,id,true);assert.equal(ui.panels.length,1);assert.equal(ui.panels[0].hidden,false);assert.equal(ui.save.hidden,false);assert.equal(ui.status.textContent,'');
 const saved=boot(id===5,true,id,true);assert.equal(saved.status.textContent,'Saved');
 console.log(`PASS independent section ${id} works without step navigation and always offers Save`);
}
