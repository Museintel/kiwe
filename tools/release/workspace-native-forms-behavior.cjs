const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('wp-content/mu-plugins/dsa/assets/js/workspace-native-forms.js','utf8');
const event=()=>({defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}});
function fixture(configured=true){
 const windowEvents={},timers=[],confirmations=[];
 function form(move=false){
  const listeners={},attrs={},status={hidden:true},undo={hidden:true},count={textContent:''};
  const field={type:'text',value:'Original',disabled:false,focus(){this.focused=true;}};
  const fields=move?[{...field,type:'select-one',value:'20'},...Array.from({length:2},()=>({...field,type:'checkbox',checked:false}))]:[field];
  const button={disabled:false,attrs:{},setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];}};
  return {fields,field,status,undo,count,attrs,listeners,button,dataset:move?{selectionLimit:'20'}:{},valid:true,
   querySelector:s=>s.includes('native-state')?status:s.includes('native-undo')?undo:s.includes('selection-count')&&move?count:null,
   querySelectorAll:s=>s.startsWith('button')?[button]:s.startsWith('[aria')?(button.attrs['aria-disabled']?[button]:[]):fields,
   addEventListener:(n,f)=>listeners[n]=f,reportValidity(){return this.valid;},setAttribute(k,v){attrs[k]=v;},removeAttribute(k){delete attrs[k];},
   submit(){const e=event();listeners.submit?.(e);return e;},edit(v){field.value=v;listeners.input?.();},undoEdit(){listeners.reset?.(event());}};
 }
 const forms=[form(),form(),form(true)];
 const window={KIWE_NATIVE_FORMS:configured?{changed:'Unsaved',unchanged:'No changes',selected:'%1$d of %2$d selected',choosePosts:'Choose posts',otherEdits:'Other edits',submitting:'Submitting'}:null,confirm:msg=>{confirmations.push(msg);return window.accept;},accept:false,setTimeout:fn=>timers.push(fn),addEventListener:(n,f)=>windowEvents[n]=f,fetch(){throw new Error('Native feedback cannot fetch');}};
 vm.runInNewContext(source,{window,document:{querySelectorAll:()=>forms}});
 return {forms,window,windowEvents,confirmations,timers};
}
let f=fixture(),a=f.forms[0],b=f.forms[1],move=f.forms[2];
a.edit('Changed');assert.equal(a.status.textContent,'Unsaved');assert.equal(a.undo.hidden,false);
let leaving=event();f.windowEvents.beforeunload(leaving);assert.equal(leaving.defaultPrevented,true);
a.undoEdit();assert.equal(a.field.value,'Original');assert.equal(a.undo.hidden,true);assert.equal(a.field.focused,true);
b.edit('Keep other edit');a.edit('My save');assert.equal(a.submit().defaultPrevented,true);assert.equal(f.confirmations.length,1);assert.equal(a.attrs['aria-busy'],undefined);assert.equal(b.field.value,'Keep other edit');
f.window.accept=true;assert.equal(a.submit().defaultPrevented,false);assert.equal(a.attrs['aria-busy'],'true');assert.equal(a.button.disabled,false);assert.equal(a.field.disabled,false);assert.equal(a.status.textContent,'Submitting');assert.equal(a.submit().defaultPrevented,true);
leaving=event();f.windowEvents.beforeunload(leaving);assert.equal(leaving.defaultPrevented,false);
f.windowEvents.pageshow();assert.equal(a.attrs['aria-busy'],undefined);assert.equal(a.field.value,'My save');assert.equal(a.button.attrs['aria-disabled'],undefined);assert.equal(a.status.textContent,'Unsaved');
const cancelled=a.submit();cancelled.preventDefault();f.timers.forEach(fn=>fn());assert.equal(a.attrs['aria-busy'],undefined);leaving=event();f.windowEvents.beforeunload(leaving);assert.equal(leaving.defaultPrevented,true);
f=fixture();a=f.forms[0];a.valid=false;assert.equal(a.submit().defaultPrevented,true);assert.equal(a.attrs['aria-busy'],undefined);
const prevented=event();prevented.preventDefault();a.listeners.submit(prevented);assert.equal(a.attrs['aria-busy'],undefined);
move=f.forms[2];assert.equal(move.count.textContent,'0 of 20 selected');assert.equal(move.submit().defaultPrevented,true);assert.equal(move.status.textContent,'Choose posts');move.fields[1].checked=true;move.listeners.change();assert.equal(move.count.textContent,'1 of 20 selected');assert.equal(move.submit().defaultPrevented,false);
f.windowEvents.pageshow();move.undoEdit();assert.equal(move.fields[1].checked,false);assert.equal(move.count.textContent,'0 of 20 selected');
f=fixture(false);assert.equal(Object.keys(f.forms[0].listeners).length,0);
console.log('PASS shipped native-form JS: dirty/undo, selection count, validation, other-edit confirmation, native payload controls, duplicate submission, cancelled navigation, bfcache and disabled fallback');
