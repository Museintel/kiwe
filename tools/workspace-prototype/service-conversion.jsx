import React,{useEffect,useRef,useState} from 'react';
import {WorkspaceDialog} from './workspace-dialog.jsx';

/** One reviewed source transition. An uncertain write must be read back, never replayed. */
export function ServiceConversion({request,undo=false,onChanged,onClose}){
 const [review,setReview]=useState(null),[state,setState]=useState('loading'),[message,setMessage]=useState(''),[accepted,setAccepted]=useState(false),lock=useRef(false);
 useEffect(()=>{const controller=new AbortController();request('',undefined,{operation:undo?'service_conversion_undo':'service_conversion'},controller.signal).then(value=>{if(controller.signal.aborted)return;const viewOnly=undo&&value.canUndo===false&&value.expected==='';if((!viewOnly&&!/^[a-f0-9]{64}$/.test(value.expected))||!Array.isArray(value.items)||value.items.length<1||value.items.length>50||value.count!==value.items.length||value.mode!==(undo?'undo':'convert'))throw Error('The Service review was incomplete. Reload the saved details.');setReview(value);setState('ready');}).catch(error=>{if(!controller.signal.aborted){setMessage(error.message);setState('error');}});return()=>controller.abort();},[request,undo]);
 async function save(){if(lock.current||state!=='ready'||!accepted||!review)return;lock.current=true;setState('saving');try{const result=await request('',{operation:undo?'undo_service_conversion':'convert_service_plans',expected:review.expected});if(result.changed!==true||result.mode!==review.mode||result.count!==review.count)throw Error('The saved result was incomplete. Reload Services before continuing.');onChanged(result);onClose();}catch(error){setMessage(error.message);setState('uncertain');}finally{lock.current=false;}}
 return <WorkspaceDialog title={undo?'Return to Service plans':'Review Service conversion'} busy={state==='saving'} onClose={onClose} className="continuity-conversion">
  {state==='loading'&&<p role="status">Reading the saved Service plans…</p>}
  {review&&<><p>{undo?(review.canUndo===false?'These original Service plans are retained for review.':`Remove the ${review.count} unchanged native drafts and their new categories, then recover the original plans.`):`Create ${review.count} WordPress drafts with their names, descriptions, images, categories and display order.`}</p>
   {review.reason&&<p role="status">{review.reason}</p>}
   {!undo&&<p className="continuity-note">Review and publish each draft separately. Menu entries stay as they are. Suggested addresses become available after publication; WordPress confirms the final address.</p>}
   <div className="continuity-conversion-list">{review.items.map((item,index)=><article key={index} className="continuity-conversion-item">
    {item.imageUrl&&<img src={item.imageUrl} alt="" loading="lazy"/>}<div><small>{undo?'ORIGINAL PLAN': 'WORDPRESS DRAFT'}</small><h3>{item.title}</h3>{item.summary&&<p>{item.summary}</p>}
    {!undo&&<><details><summary>Review description & categories</summary><div className="continuity-conversion-description" dangerouslySetInnerHTML={{__html:item.description||'<p>No description yet.</p>'}}/><p>{item.categories?.length?item.categories.join(' · '):'No categories selected.'}</p></details><p className="continuity-conversion-address">Suggested address: {item.suggestedUrl}</p><p>Display order: {item.menuOrder} · Saved plan: {item.originalStatus}</p></>}
    {undo&&<><p>Saved visibility: {item.status}</p><details><summary>Review original description & categories</summary><div className="continuity-conversion-description" dangerouslySetInnerHTML={{__html:item.description||'<p>No description yet.</p>'}}/><p>{item.categories?.length?item.categories.join(' · '):'No categories selected.'}</p></details></>}</div></article>)}</div>
   {review.canUndo!==false&&<><label className="continuity-conversion-accept"><input type="checkbox" checked={accepted} disabled={state!=='ready'} onChange={e=>setAccepted(e.target.checked)}/>{undo?'Recover these original plans and remove only the unchanged converted drafts.':'Save every Service as a draft and retain the original plans for guarded undo.'}</label>
   <div className="continuity-actions"><button type="button" className="kiwe-new-action" disabled={state!=='ready'||!accepted} onClick={save}>{state==='saving'?'Saving…':undo?'Recover original plans':'Create Service drafts'}</button></div></>}
  </>}
  {message&&<p role="alert">{message}</p>}
  {state==='uncertain'&&<p>Close and reload Services to see the saved state before taking another action.</p>}
 </WorkspaceDialog>;
}
