import React,{useEffect,useRef,useState} from 'react';
import {WorkspaceDialog} from './workspace-dialog.jsx';
import {archiveRequest} from './content-archive-jobs.jsx';

const active=job=>['capturing','recovering'].includes(job.status);
const names={capturing:'Creating design package',ready:'Download ready',review:'Ready to recover copies',recovering:'Recovering design copies',copies_ready:'Copies ready for review',settings_review:'Live settings review',settings_applying:'Check settings restore',complete:'Design settings restored',removing:'Removal in progress'};
const summary=counts=>`${counts.templates} templates · ${counts.images} images · ${counts.fonts} font families`;
const settingName=name=>name.replace(/^bricks_/,'').replace(/_/g,' ');

function DesignProgress({endpoint,nonce,initialJob,initialPhrase='',onBusy,onRemoved}){
 const [job,setJob]=useState(initialJob),[phrase,setPhrase]=useState(initialPhrase),[busy,setBusy]=useState(false),[error,setError]=useState(''),[removing,setRemoving]=useState(false);
 const mounted=useRef(true),pause=useRef(false),started=useRef(false);
 function remember(value){if(!value?.id||!Number.isInteger(value.revision))throw Error('Saved design progress was incomplete.');if(mounted.current)setJob(value);return value;}
 async function check(){const response=await archiveRequest(endpoint,nonce,'/jobs');const saved=response.jobs?.find(value=>value.id===job.id);if(!saved)throw Error('This design job expired or was removed. Completed copies are kept.');return remember(saved);}
 async function run(action='step',confirm=false){setBusy(true);onBusy(true);setError('');pause.current=false;try{
  let current=await check();
  if(action!=='step'||(current.status==='review'&&confirm)){const response=await archiveRequest(endpoint,nonce,`/jobs/${current.id}/${action}`,{passphrase:phrase,revision:current.revision,confirm});current=remember(response.job);}
  while(active(current)&&!pause.current&&mounted.current){const previous=current.revision;const response=await archiveRequest(endpoint,nonce,`/jobs/${current.id}/step`,{passphrase:phrase,revision:current.revision});current=remember(response.job);if(current.revision===previous)throw Error('The step did not advance. Check saved progress before retrying.');}
 }catch(failure){if(mounted.current)setError(failure.message);}finally{if(mounted.current){setBusy(false);setPhrase('');onBusy(false);}}}
 useEffect(()=>{mounted.current=true;if(initialPhrase&&active(initialJob)&&!started.current){started.current=true;run();}return()=>{mounted.current=false;pause.current=true;};},[]);
 async function remove(){setBusy(true);onBusy(true);setError('');try{const response=await archiveRequest(endpoint,nonce,`/jobs/${job.id}/remove`,{confirm:true});if(!response.removed)throw Error('Design job removal was not confirmed.');setPhrase('');onRemoved();}catch(failure){setError(failure.message);}finally{setBusy(false);onBusy(false);}}
 const settings=['copies_ready','settings_review','settings_applying'].includes(job.status),finished=['ready','complete'].includes(job.status);
 return <div className="kiwe-archive-form kiwe-archive-job">
  {(error||job.error)&&<p className="kiwe-archive-error" role="alert">{error||job.error}</p>}
  <div role="status" aria-live="polite"><strong>{names[job.status]||'Saved design progress'}</strong><p>{summary(job.counts)} · {job.counts.settings} design settings</p></div>
  {job.progress&&<><p>{job.progress.area==='assets'?'Images and fonts':'Templates'}</p><progress aria-label="Design backup progress" max={Math.max(job.progress.total,1)} value={job.progress.count}/></>}
  {job.expired?<p>This job expired. Remove the private progress and start a new package. Completed copies remain on your website.</p>:job.status==='ready'?<><a className="kiwe-archive-download" href={job.downloadUrl}>Download to computer</a><p>Keep this file and its passphrase separately. The private website copy expires after seven days.</p></>:job.status==='complete'?<><p>{job.result?.restored??0} live design settings restored. An encrypted before-copy is retained in Website snapshots.</p><a href={job.templatesUrl}>Open templates ↗</a></>:<>
   {job.status==='review'&&<><p>Recover native template drafts, image copies and font families from this package. Current content and live design settings stay in place.</p><p>Template placement conditions are removed. Review copies in Bricks before applying live settings.</p></>}
   {settings&&<><p>The design copies are saved. Review and publish the referenced templates in Bricks, then review the live settings here. Kiwe checks the copies again before applying settings.</p><a href={job.templatesUrl} target="_blank" rel="noopener noreferrer">Review template copies ↗</a></>}
   {job.status==='settings_applying'&&<p>The previous restore response was not confirmed. A before-copy is retained when preparation finished. Review the current settings before taking another action.</p>}
   {job.status==='settings_review'&&<><p><strong>{job.review.changedCount} of {job.review.count}</strong> settings differ from your website.</p>{job.review.changedCount>0&&<ul className="backup-changes">{job.review.changed.map(name=><li key={name}>{settingName(name)}</li>)}</ul>}<p>An encrypted before-copy is kept when you confirm this restore.</p></>}
   {!busy&&job.status!=='removing'&&<form className="kiwe-module-form" onSubmit={event=>{event.preventDefault();run(job.status==='settings_review'?'apply_settings':settings?'review_settings':'step',job.status==='review'||job.status==='settings_review');}}>
    <label>Recovery passphrase<input type="password" autoComplete="off" minLength={16} maxLength={1024} required value={phrase} onChange={event=>setPhrase(event.target.value)}/></label>
    <button className="kiwe-new-action">{job.status==='review'?'Recover design copies':job.status==='settings_review'?'Apply reviewed settings':settings?'Review live design settings':'Resume'}</button>
   </form>}
   {busy&&<button type="button" onClick={()=>{pause.current=true;}}>Pause after this step</button>}
  </>}
  {!busy&&!removing&&<div className="kiwe-archive-job-actions"><button type="button" onClick={()=>check().catch(failure=>setError(failure.message))}>Check saved progress</button>{job.status==='settings_review'&&<button type="button" disabled={phrase.length<16} onClick={()=>run('review_settings')}>Review again</button>}<button type="button" onClick={()=>setRemoving(true)}>Remove job</button></div>}
  {removing&&<div className="kiwe-archive-remove"><p>Remove this private package and its related progress? Completed template, media and font copies stay on the website. Recovery mappings expire. Keep your downloaded package first.</p><div className="kiwe-archive-job-actions"><button type="button" disabled={busy} onClick={remove}>Remove private design job</button><button type="button" disabled={busy} onClick={()=>setRemoving(false)}>Keep job</button></div></div>}
 </div>;
}

export function DesignPackages({endpoint,nonce,maxUploadBytes,available=false,intent='',onIntentClose,onBusy}){
 const [jobs,setJobs]=useState([]),[selected,setSelected]=useState(null),[phrase,setPhrase]=useState(''),[file,setFile]=useState(null),[busy,setBusy]=useState(false),[error,setError]=useState('');
 const requestKey=useRef('');
 async function refresh(){try{const response=await archiveRequest(endpoint,nonce,'/jobs');if(!Array.isArray(response.jobs))throw Error('Saved design progress was incomplete.');setJobs(response.jobs);setError('');}catch(failure){setError(failure.message);}}
 useEffect(()=>{if(endpoint)refresh();},[endpoint]);
 useEffect(()=>{if(intent){requestKey.current=globalThis.crypto.randomUUID();setPhrase('');setFile(null);setError('');}},[intent]);
 function changedBusy(value){setBusy(value);onBusy(value);}
 function close(){setSelected(null);setPhrase('');setFile(null);onIntentClose();refresh();}
 async function start(event){event.preventDefault();changedBusy(true);setError('');try{
  let body;if(intent==='design-import'){if(!file||file.size>maxUploadBytes)throw Error('Choose a design package within the upload limit.');body=new FormData();body.append('file',file);body.append('passphrase',phrase);body.append('requestKey',requestKey.current);}else body={passphrase:phrase,requestKey:requestKey.current};
  const response=await archiveRequest(endpoint,nonce,intent==='design-import'?'/preview':'/export',body);if(!response.job?.id)throw Error('The design job response was incomplete. Check saved progress before retrying.');setSelected({...response.job,initialPhrase:phrase});setPhrase('');onIntentClose();
 }catch(failure){setError(failure.message);}finally{changedBusy(false);}}
 if(!endpoint)return <p>Design packages require native Bricks design access, upload permission and the PHP ZIP extension.</p>;
 return <section className="kiwe-archive-jobs" aria-label="Bricks design packages">
	  {!available&&<p>Creating and recovering design packages requires native Bricks design access, upload permission and the PHP ZIP extension. You can remove your saved private jobs here.</p>}
  <div className="backup-scope"><div><strong>One design recovery point</strong><span>Save Bricks global design, templates and their referenced images and fonts together.</span></div><p>Recover copies first. Review live settings separately. Executable code, whole-site recovery and Google Drive remain separate.</p></div>
  <div className="kiwe-archive-jobs-heading"><h2>Saved progress</h2><button type="button" disabled={busy} onClick={refresh}>Refresh progress</button></div>
  {error&&!intent&&<p role="alert">{error}</p>}{!jobs.length?<p>Create a design package before making design changes.</p>:<div className="kiwe-module-grid backup-grid">{jobs.map(job=><article className="kiwe-module-card" key={job.id}><small>{job.mode==='export'?'DESIGN BACKUP':'DESIGN RECOVERY'}</small><h3>Bricks design</h3><p>{summary(job.counts)}</p><p>{names[job.status]} · {new Date(job.createdAt).toLocaleString()}</p><div className="kiwe-module-footer"><button type="button" onClick={()=>setSelected(job)}>Open progress ↗</button></div></article>)}</div>}
  {intent&&<WorkspaceDialog title={intent==='design-import'?'Recover Bricks design':'New Bricks design backup'} busy={busy} dirty={!!phrase||!!file} onClose={close}><form className="kiwe-module-form" onSubmit={start}>{error&&<p className="kiwe-module-error" role="alert">{error}</p>}<p>{intent==='design-import'?'Choose a design package from this same website. Review it before creating copies.':'Capture native global design, all eligible templates and their referenced local images and fonts. Progress is saved after each step.'}</p>{intent==='design-import'&&<label>Design package<input type="file" accept=".kiwe-design,application/octet-stream" required disabled={busy} onChange={event=>setFile(event.target.files?.[0]||null)}/><span>Upload limit: {Math.floor(maxUploadBytes/1048576)} MB.</span></label>}<label>Recovery passphrase<input type="password" autoComplete={intent==='design-import'?'off':'new-password'} minLength={16} maxLength={1024} required disabled={busy} value={phrase} onChange={event=>setPhrase(event.target.value)}/></label><p>At least 16 characters. Kiwe does not store your passphrase.</p><button className="kiwe-new-action" disabled={busy}>{busy?'Checking…':intent==='design-import'?'Review design recovery':'Create encrypted package'}</button></form></WorkspaceDialog>}
  {selected&&<WorkspaceDialog title={selected.mode==='export'?'Bricks design backup':'Bricks design recovery'} busy={busy} onClose={close}><DesignProgress key={selected.id} endpoint={endpoint} nonce={nonce} initialJob={selected} initialPhrase={selected.initialPhrase||''} onBusy={changedBusy} onRemoved={close}/></WorkspaceDialog>}
 </section>;
}
