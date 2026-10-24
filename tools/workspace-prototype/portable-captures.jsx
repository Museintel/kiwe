import React,{useEffect,useRef,useState} from 'react';
import {WorkspaceDialog} from './workspace-dialog.jsx';

const names={pending:'Ready to start',queued:'Waiting to capture',running:'Capturing',captured:'Captured for review',failed:'Attempt failed',interrupted:'Attempt interrupted',removing:'Removal unfinished'};
const date=value=>new Date(value*1000).toLocaleString();

/** Capture review only: background queue, bounded polling and shared task dialogs. */
export function PortableCaptures({endpoint,nonce,intent='',onIntentClose,onBusy,onReady,onDirty}){
 const [data,setData]=useState(null),[selected,setSelected]=useState(null),[phrase,setPhrase]=useState(''),[dialog,setDialog]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState(''),[message,setMessage]=useState('');
 const mounted=useRef(false),requestSerial=useRef(0),readController=useRef(null),operation=useRef(false);
 async function request(path='',body,signal){
  const response=await fetch(endpoint+path,{method:body?'POST':'GET',credentials:'same-origin',signal,headers:{'X-WP-Nonce':nonce,...(body?{'X-Kiwe-Mutation':'1','Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify(body)}:{})});
  const result=await response.json().catch(()=>null);
  if(!response.ok||!result)throw Error(result?.message||'Kiwe could not confirm this capture action. Refresh the saved attempts before trying again.');
  return result;
 }
 async function refresh(){
  const serial=++requestSerial.current;readController.current?.abort();const controller=new AbortController();readController.current=controller;
  try{
   const next=await request('',undefined,controller.signal);
   if(next.schema!=='kiwe.site-capture-workspace.v1'||!Array.isArray(next.jobs)||next.fullSiteReady!==false||next.scope!=='capture-review')throw Error('The saved capture list was incomplete.');
   if(mounted.current&&serial===requestSerial.current){setData(next);onReady?.(next.canCreate&&next.workerReady&&next.jobs.length<2);return next;}
  }catch(failure){if(mounted.current&&serial===requestSerial.current&&failure.name!=='AbortError'){setError(failure.message);onReady?.(false);}}
 }
 useEffect(()=>{mounted.current=true;refresh();return()=>{mounted.current=false;++requestSerial.current;readController.current?.abort();onReady?.(false);};},[endpoint,nonce]);
 useEffect(()=>{onDirty?.(!!phrase);return()=>onDirty?.(false);},[phrase]);
 useEffect(()=>{if(intent==='capture-create'){setSelected(null);setError('');setDialog('queue');onIntentClose?.();}},[intent]);
 useEffect(()=>{
  if(error||busy||!data?.jobs.some(job=>job.busy||job.status==='queued'||job.status==='running'))return;
  const timer=setTimeout(()=>{if(!document.hidden)refresh();},5000);
  const visible=()=>{if(!document.hidden)refresh();};document.addEventListener('visibilitychange',visible);
  return()=>{clearTimeout(timer);document.removeEventListener('visibilitychange',visible);};
 },[data,busy,error]);
 const saved=selected&&data?.jobs.find(job=>job.id===selected.id);
 function close(){if(operation.current)return;setDialog('');setSelected(null);setPhrase('');setError('');}
 async function queue(event){
  event.preventDefault();if(operation.current)return;operation.current=true;setBusy(true);onBusy?.(true);setError('');setMessage('');
  let job=selected;
  try{
   if(!job){const created=await request('',{});job=created.job;if(!job?.id)throw Error('The new attempt was not confirmed. Refresh before starting another.');setSelected(job);}
   const result=await request(`/${job.id}/queue`,{passphrase:phrase});
   if(result.job?.status!=='queued')throw Error('Capture was not confirmed. Refresh the saved attempts.');
   setMessage('Your capture is queued. You can continue working and return to check this attempt.');setDialog('');setSelected(null);
  }catch(failure){setError(failure.message);}finally{
   setPhrase('');operation.current=false;setBusy(false);onBusy?.(false);
   const current=await refresh(),confirmed=job&&current?.jobs.find(item=>item.id===job.id);
   // A lost response is not permission to submit a second attempt. A fresh,
   // account-owned listing may confirm the original request instead.
   if(confirmed&&['queued','running','captured'].includes(confirmed.status)){setError('');setDialog('');setSelected(null);setMessage('Your capture was queued. You can continue working and return to check this attempt.');}
  }
 }
 async function remove(event){
  event.preventDefault();if(operation.current||!selected)return;operation.current=true;setBusy(true);onBusy?.(true);setError('');
  try{const result=await request(`/${selected.id}/remove`,{confirm:true});if(result.removed!==true)throw Error('Removal was not confirmed. Refresh the saved attempts.');setDialog('');setSelected(null);setMessage('The private capture attempt was removed.');}
  catch(failure){setError(failure.message);}finally{operation.current=false;setBusy(false);onBusy?.(false);await refresh();}
 }
 return <section className="kiwe-captures" aria-label="Site capture review">
  <div className="backup-scope"><div><strong>Site captures · Preview</strong><span>Review encrypted website files and database captures. Complete website recovery is still being prepared.</span></div><p>Download both files after capture. Keep your recovery passphrase separately.</p></div>
  <div className="backup-summary"><span>{data?`${data.jobs.length} saved ${data.jobs.length===1?'attempt':'attempts'}`:'Opening saved attempts…'}</span><button type="button" disabled={busy} onClick={()=>{setError('');refresh();}}>Refresh</button></div>
  {error&&!dialog&&<p className="kiwe-module-error" role="alert">{error}</p>}{message&&<p className="backup-notice" role="status">{message}</p>}
  {data&&!data.workerReady&&<p className="backup-notice" role="status">Background capture is unavailable. Your website administrator needs to prepare it before you can start a new attempt.</p>}
  {data&&!data.jobs.length&&<div className="backup-empty"><h2>No captures yet.</h2><p>Start an attempt when background capture is available.</p></div>}
  <div className="kiwe-module-grid backup-grid">{data?.jobs.map(job=><article className="kiwe-module-card" key={job.id}><small>ENCRYPTED SITE CAPTURE</small><h2>{names[job.status]||'Saved attempt'}</h2><p>{date(job.createdAt)} · Attempt {job.attempt}</p><p>{job.expired?'Expired':job.sourceChanged?'Website source changed':job.busy?'An operation is in progress':job.status==='captured'?`${Math.ceil(job.bytes/1048576)} MB · Available until ${date(job.expiresAt)}`:'Saved for seven days'}</p><div className="kiwe-module-footer"><button type="button" disabled={busy} onClick={()=>{setSelected(job);setError('');setDialog('review');}}>Open capture ↗</button></div></article>)}</div>
  {dialog==='queue'&&<WorkspaceDialog title={selected?'Start saved attempt':'New site capture'} busy={busy} dirty={!!phrase} onClose={close}><form className="kiwe-module-form" onSubmit={queue}>{error&&<p className="kiwe-module-error" role="alert">{error}</p>}<p>This preview creates encrypted files for review. Restoring a complete website is not available yet.</p><label>Recovery passphrase<input type="password" autoComplete="new-password" required minLength={16} maxLength={1024} value={phrase} disabled={busy} onChange={event=>setPhrase(event.target.value)}/></label><p className="kiwe-module-note">At least 16 characters. Keep it separately; Kiwe cannot recover it for you.</p><button className="kiwe-new-action" disabled={busy||!data?.workerReady||!data?.canCreate||(!!selected&&!saved?.canQueue)}>{busy?'Queuing…':'Start capture'}</button></form></WorkspaceDialog>}
  {dialog==='review'&&saved&&<WorkspaceDialog title="Site capture" busy={busy} onClose={close}><div className="kiwe-module-form"><p role="status"><strong>{names[saved.status]}</strong> · {date(saved.createdAt)}</p><p>{saved.expired?'This attempt expired. Remove it and create a new one.':saved.sourceChanged?'The source changed. Remove this attempt and create a new one.':saved.busy?'Capture or download is in progress. Return here after it finishes.':saved.error?'This attempt did not complete. Start a fresh attempt or remove its private files.':'Your website content stays unchanged.'}</p>{saved.canReview&&<><p>Download the capture and its source report for offline review. Complete website restore is not available yet.</p><div className="kiwe-capture-downloads"><a href={saved.downloads.recovery}>Download capture</a><a href={saved.downloads.observation}>Download source report</a></div><p>Both files are encrypted. Keep the recovery passphrase separately.</p></>}{saved.canQueue&&data.workerReady&&<button type="button" className="kiwe-new-action" onClick={()=>{setError('');setDialog('queue');}}>Start {saved.attempt?'fresh ':'saved '}attempt</button>}<button type="button" disabled={busy||saved.busy} onClick={()=>setDialog('remove')}>Remove private attempt</button></div></WorkspaceDialog>}
  {dialog==='remove'&&selected&&<WorkspaceDialog title="Remove site capture" busy={busy} onClose={close}><form className="kiwe-module-form" onSubmit={remove}>{error&&<p className="kiwe-module-error" role="alert">{error}</p>}<p>Remove this private attempt and its encrypted files? Download both files first if you want to keep them.</p><button className="kiwe-new-action" disabled={busy||saved?.busy}>{busy?'Removing…':'Remove private attempt'}</button></form></WorkspaceDialog>}
 </section>;
}
