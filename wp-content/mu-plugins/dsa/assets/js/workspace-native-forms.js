/* Progressive feedback around native WordPress POST; never sends or replays a request. */
(() => {
 'use strict';
 const config=window.KIWE_NATIVE_FORMS;
 if(!config)return;
 const states=[];
 let leaving=false;
 document.querySelectorAll('form[data-kiwe-native-form]').forEach(form=>{
  const status=form.querySelector('[data-kiwe-native-state]'),undo=form.querySelector('[data-kiwe-native-undo]');
  const fields=[...form.querySelectorAll('input:not([type=hidden]):not([type=submit]),select,textarea')];
  if(!status||!undo||!fields.length)return;
  const value=field=>field.type==='checkbox'?field.checked:field.value;
  const original=fields.map(value);
  const state={form,dirty:()=>fields.some((field,i)=>value(field)!==original[i]),submitting:false};states.push(state);
  const message=text=>{status.hidden=false;status.textContent=text;};
  const sync=()=>{
   undo.hidden=!state.dirty();
   if(!state.submitting)message(state.dirty()?config.changed:config.unchanged);
   const count=form.querySelector('[data-kiwe-selection-count]');
   if(count)count.textContent=config.selected.replace('%1$d',String(fields.filter(field=>field.type==='checkbox'&&field.checked).length)).replace('%2$d',form.dataset.selectionLimit);
  };
  form.addEventListener('input',sync);form.addEventListener('change',sync);
  form.addEventListener('reset',event=>{
   event.preventDefault();if(state.submitting)return;
   fields.forEach((field,i)=>{if(field.type==='checkbox')field.checked=original[i];else field.value=original[i];});
   sync();fields[0].focus();
  });
  form.addEventListener('submit',event=>{
   if(event.defaultPrevented)return;
   if(state.submitting){event.preventDefault();return;}
   if(!form.reportValidity()){event.preventDefault();return;}
   if(form.dataset.selectionLimit){
    const count=fields.filter(field=>field.type==='checkbox'&&field.checked).length;
    if(!count||count>Number(form.dataset.selectionLimit)){event.preventDefault();message(config.choosePosts);return;}
   }
   // Saving this form navigates away: protect unrelated unfinished edits too.
   if(states.some(other=>other!==state&&other.dirty())&&!window.confirm(config.otherEdits)){event.preventDefault();return;}
   state.submitting=true;leaving=true;form.setAttribute('aria-busy','true');
   // Keep successful controls enabled so the browser serializes their native values.
   form.querySelectorAll('button[type=submit],input[type=submit]').forEach(button=>button.setAttribute('aria-disabled','true'));
   message(config.submitting);
   window.setTimeout(()=>{
    if(event.defaultPrevented){
     state.submitting=false;leaving=false;form.removeAttribute('aria-busy');
     form.querySelectorAll('[aria-disabled=true]').forEach(button=>button.removeAttribute('aria-disabled'));
     sync();
    }
   },0);
  });
  if(form.dataset.selectionLimit)sync();
 });
 window.addEventListener('beforeunload',event=>{
  if(!leaving&&states.some(state=>state.dirty())){event.preventDefault();event.returnValue='';}
 });
 window.addEventListener('pageshow',()=>{
  leaving=false;
  states.forEach(state=>{
   state.submitting=false;state.form.removeAttribute('aria-busy');
   state.form.querySelectorAll('[aria-disabled=true]').forEach(button=>button.removeAttribute('aria-disabled'));
   const status=state.form.querySelector('[data-kiwe-native-state]');
   if(status){status.hidden=!state.dirty();status.textContent=config.changed;}
  });
 });
})();
