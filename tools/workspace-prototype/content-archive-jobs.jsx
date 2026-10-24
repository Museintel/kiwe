import React,{useEffect,useRef,useState} from 'react';
import {WorkspaceDialog} from './workspace-dialog.jsx';

export async function archiveRequest(endpoint,nonce,path,body){
 const upload=body instanceof FormData,controller=new AbortController(),timer=setTimeout(()=>controller.abort(),45000);
 try{
  const response=await fetch(endpoint+path,{method:body?'POST':'GET',credentials:'same-origin',signal:controller.signal,headers:{'X-WP-Nonce':nonce,...(body?{'X-Kiwe-Mutation':'1'}:{}),...(body&&!upload?{'Content-Type':'application/json'}:{})},...(body?{body:upload?body:JSON.stringify(body)}:{})});
  const value=await response.json().catch(()=>null);
  if(!response.ok||!value)throw Error(value?.message||'This step was not confirmed. Check saved progress before retrying.');return value;
 }catch(error){if(error.name==='AbortError')throw Error('The response was interrupted. Check saved progress; the server may have finished this step.');throw error;}
 finally{clearTimeout(timer);}
}
const ongoing=job=>['capturing','reviewing','importing','finishing'].includes(job.status);
const stateNames={capturing:'Creating backup',reviewing:'Checking import',review:'Ready to review',importing:'Saving drafts',finishing:'Restoring relationships',ready:'Download ready',complete:'Import saved'};
const jobName=job=>job.kind==='media'&&job.status==='importing'?'Saving media copies':stateNames[job.status];
export function ContentArchiveJobPanel({endpoint,nonce,initialJob,initialPhrase='',onBusy,onChange,onImported,onRemoved}){
 const [job,setJob]=useState(initialJob),[phrase,setPhrase]=useState(initialPhrase),[busy,setBusy]=useState(false),[error,setError]=useState(''),[removing,setRemoving]=useState(false),[pausing,setPausing]=useState(false);
 const pause=useRef(false),mounted=useRef(true),started=useRef(false),receipt=useRef(false);
 function remember(next){if(!next?.id||!Number.isInteger(next.revision))throw Error('The saved backup progress was incomplete.');setJob(next);onChange?.(next);if(next.status==='complete'&&!receipt.current){receipt.current=true;onImported?.(next.result);}}
 async function check(){const response=await archiveRequest(endpoint,nonce,'/jobs');const saved=response.jobs?.find(item=>item.id===job.id);if(!saved)throw Error('This job expired, was removed, or is unavailable for your account.');remember(saved);return saved;}
 async function run(confirm=false){setBusy(true);onBusy?.(true);setError('');setPausing(false);pause.current=false;try{
  let current=await check();
  if(current.status==='review'&&confirm){const response=await archiveRequest(endpoint,nonce,`/jobs/${current.id}/step`,{revision:current.revision,passphrase:phrase,confirm:true});current=response.job;remember(current);}
  while(ongoing(current)&&!pause.current&&mounted.current){const response=await archiveRequest(endpoint,nonce,`/jobs/${current.id}/step`,{revision:current.revision,passphrase:phrase});current=response.job;remember(current);}
 }catch(failure){if(mounted.current)setError(failure.message);}finally{if(mounted.current){setBusy(false);setPausing(false);setPhrase('');onBusy?.(false);}}}
 useEffect(()=>{mounted.current=true;if(initialPhrase&&ongoing(initialJob)&&!started.current){started.current=true;run();}return()=>{mounted.current=false;pause.current=true;};},[]);
 async function remove(){setBusy(true);onBusy?.(true);setError('');try{const response=await archiveRequest(endpoint,nonce,`/jobs/${job.id}/remove`,{confirm:true});if(!response.removed)throw Error('Job removal was not confirmed.');setPhrase('');onRemoved?.(response);}catch(failure){setError(failure.message);}finally{setBusy(false);onBusy?.(false);}}
 const media=job.kind==='media',savedNoun=media?'media copies':'drafts';
 const finished=job.status==='ready'||job.status==='complete'||job.expired,review=job.status==='review',count=job.count;
 return <div className="kiwe-archive-form kiwe-archive-job">
  {error&&<p className="kiwe-archive-error" role="alert">{error}</p>}{job.error&&!error&&<p className="kiwe-archive-error" role="alert">{job.error}</p>}
  <div role="status" aria-live="polite"><strong>{media&&job.status==='importing'?'Saving media copies':stateNames[job.status]||'Saved job'}</strong><p>{job.label} · {count} of {job.total} {media?'attachments':'records'}{job.variations>0&&` · ${job.variations} variations`}</p></div>
  {!finished&&<progress aria-label="Backup progress" max={Math.max(media?job.rawBytes:job.total,1)} value={media?job.processedBytes:count}/>}
  {job.fontCount>0&&<p>{job.fontCount} native font families.</p>}{media&&<p>{Math.ceil((job.processedBytes||0)/1024)} of {Math.ceil((job.rawBytes||0)/1024)} KB · {job.fileCount} registered files</p>}
  {job.selection&&(job.selection.from||job.selection.to)&&<p>Created {job.selection.from||'from the beginning'} to {job.selection.to||'the latest record'} · {job.selection.timezone}.</p>}
  {review&&<><p><strong>{job.review.count} {media?'attachments':'records'}</strong> checked. Confirm to save {media?'new media copies':'new drafts'}. Existing content is kept.</p><ul className="kiwe-archive-items">{job.review.items.map((item,index)=><li key={index}><strong>{item.title||'Untitled'}</strong><span>{item.type} · {item.author}</span></li>)}</ul>{job.review.count>job.review.items.length&&<p>Showing the first {job.review.items.length} of {job.review.count} checked records.</p>}{job.review.fonts?.length>0&&<p>Font families: {job.review.fonts.join(', ')}.</p>}{job.review.warnings?.length>0&&<ul className="kiwe-archive-items">{job.review.warnings.map((warning,index)=><li key={index}>{warning}</li>)}</ul>}<p>{media?'All registered files passed the private review. Existing attachments are kept.':job.review.recoveredMedia?'The selected media recovery reconnects images only in the new drafts.':'Media files must still exist on this website. Content links keep their original destinations.'}</p></>}
  {job.expired?<p>This job expired. Remove the private copy, then start a new backup or import. Completed {savedNoun} are kept.</p>:job.status==='ready'?<><a className="kiwe-archive-download" href={job.downloadUrl}>Download to computer</a><p>Keep the file and passphrase separately. This private website copy is available for seven days.</p></>:job.status==='complete'?<><p><strong>{job.result.count} {savedNoun}</strong> saved. {media?'They are available in the media library.':'Review and publish them from their workspace.'}</p>{job.result?.fonts>0&&<p>{job.result.fonts} native font families saved in Bricks.</p>}{media&&<p>Keep this recovery job until related content imports finish. Removing it removes the image mapping; the media copies are kept.</p>}</>:<>
   <p>{busy?'Progress is saved after each step.':job.mode==='import'?`Saved progress is available. Enter the passphrase to continue. Completed ${savedNoun} are kept if you stop.`:'Saved progress is available. Enter the passphrase to continue the frozen selection.'}</p>
   {!busy&&<form onSubmit={event=>{event.preventDefault();run(review);}}><label>Recovery passphrase<input type="password" autoComplete="off" required minLength={16} maxLength={1024} value={phrase} onChange={event=>setPhrase(event.target.value)}/></label><button className="kiwe-new-action">{review?(media?'Import media copies':'Import as drafts'):'Resume'}</button></form>}
   {busy&&<button type="button" disabled={pausing} onClick={()=>{pause.current=true;setPausing(true);}}> {pausing?'Pausing after this step…':'Pause'} </button>}
  </>}
  {!busy&&!removing&&<div className="kiwe-archive-job-actions"><button type="button" onClick={()=>{setError('');check().catch(failure=>setError(failure.message));}}>Check saved progress</button><button type="button" onClick={()=>setRemoving(true)}>Remove job</button></div>}
  {removing&&<div className="kiwe-archive-remove"><p>Remove this private job and its backup files? {job.mode==='import'?`Completed ${savedNoun} are kept. Only untouched copies from an interrupted step may be removed.${media?' Image mapping for related content imports will be removed.':''}`:'Keep your downloaded file before removing the website copy.'}</p><div className="kiwe-archive-job-actions"><button type="button" disabled={busy} onClick={remove}>Remove private job</button><button type="button" disabled={busy} onClick={()=>setRemoving(false)}>Keep job</button></div></div>}
 </div>;
}
export function ContentArchiveJobs({endpoint,nonce,refreshKey=0,kind='content'}){
 const [jobs,setJobs]=useState([]),[error,setError]=useState(''),[selected,setSelected]=useState(null),[busy,setBusy]=useState(false);
 async function refresh(){try{const response=await archiveRequest(endpoint,nonce,'/jobs');if(!Array.isArray(response.jobs))throw Error('Saved backup progress was incomplete.');setJobs(response.jobs);setError('');}catch(failure){setError(failure.message);}}
 useEffect(()=>{if(endpoint)refresh();},[endpoint,refreshKey]);
 if(!endpoint)return null;
 return <section className="kiwe-archive-jobs" aria-label={'Saved '+kind+' jobs'}><div className="kiwe-archive-jobs-heading"><h2>Saved progress</h2><button type="button" disabled={busy} onClick={refresh}>Refresh progress</button></div>{error&&<p role="alert">{error}</p>}{jobs.length===0?<p>{kind==='media'?'Media':'Content'} backups and imports appear here when you start them.</p>:<div className="kiwe-module-grid">{jobs.map(job=><article className="kiwe-module-card" key={job.id}><p className="kiwe-module-kicker">{(job.kind==='media'?'Media':'Content')+(job.mode==='export'?' backup':' import')}</p><h3>{job.label}</h3><p>{jobName(job)} · {job.count} of {job.total} {job.kind==='media'?'attachments':'records'}</p><p>Started {new Date(job.createdAt).toLocaleString()}</p><div className="kiwe-module-footer"><button type="button" onClick={()=>setSelected(job)}>Open progress ↗</button></div></article>)}</div>}{selected&&<WorkspaceDialog title={(selected.kind==='media'?'Media':'Content')+(selected.mode==='export'?' backup':' import')} busy={busy} onClose={()=>{setSelected(null);refresh();}}><ContentArchiveJobPanel endpoint={endpoint} nonce={nonce} initialJob={selected} onBusy={setBusy} onRemoved={()=>{setSelected(null);refresh();}}/></WorkspaceDialog>}</section>;
}
