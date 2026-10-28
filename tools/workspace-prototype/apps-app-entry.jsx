import React,{useEffect,useRef,useState} from 'react';
import {createRoot} from 'react-dom/client';
import {workflowClient} from './workflow-client.mjs';
import {WorkspaceHeader} from './workspace-chrome.jsx';
import {WorkspaceDialog} from './workspace-dialog.jsx';
import './apps-app.css';
import {APP_IDS,checkedAppLibrary} from './apps-client-contract.mjs';

function App(){
 const config=globalThis.KIWE_APPS_APP,request=workflowClient(config);
 const [data,setData]=useState(null),[selected,setSelected]=useState(APP_IDS.includes(new URL(location.href).searchParams.get('app'))?new URL(location.href).searchParams.get('app'):''),[review,setReview]=useState(null),[automatic,setAutomatic]=useState(false),[job,setJob]=useState(null),[busy,setBusy]=useState(false),[error,setError]=useState('');
 const running=useRef(false),mounted=useRef(true);
 useEffect(()=>{refresh();return()=>{mounted.current=false;running.current=false;};},[]);
 async function refresh(force=false){setBusy(true);setError('');try{const value=await request('',force?{operation:'refresh'}:undefined);checkedAppLibrary(value);if(mounted.current)setData(value);}catch(e){if(mounted.current)setError(e.message);}finally{if(mounted.current)setBusy(false);}}
 function open(id){setSelected(id);setReview(null);setJob(null);setError('');setAutomatic(Boolean(data?.items.find(v=>v.id===id)?.automatic));}
 const item=data?.items.find(v=>v.id===selected);
 useEffect(()=>{if(item){setAutomatic(item.automatic);}},[item?.id,item?.version]);
 async function prepare(){setBusy(true);setError('');try{setReview(await request('',{operation:'review',app:selected,automatic}));}catch(e){setError(e.message);}finally{setBusy(false);}}
 async function advance(initial){if(running.current)return;running.current=true;setBusy(true);setError('');let value=initial;
  try{while(mounted.current&&running.current&&value.status!=='complete'){const receipt=await request('',{operation:'advance',app:selected,job:value.id,cursor:value.cursor});if(receipt.job?.id!==value.id||receipt.job.cursor<=value.cursor)throw Error('Progress could not be confirmed. Refresh before resuming.');value=receipt.job;setJob(value);}if(mounted.current){setJob(value);setReview(null);await refresh();}}
  catch(e){if(mounted.current){setJob(null);setError(e.message);await refresh();setError(e.message);}}finally{running.current=false;if(mounted.current)setBusy(false);}
 }
 async function start(){setBusy(true);setError('');try{const receipt=await request('',{operation:'start',app:selected,automatic,expected:review.expected});setJob(receipt.job);setBusy(false);advance(receipt.job);}catch(e){setError(e.message);setBusy(false);}}
 async function change(operation,fields={}){setBusy(true);setError('');try{const value=await request('',{operation,app:selected,...fields});setData(checkedAppLibrary(value));setReview(null);setJob(null);}catch(e){setError(e.message);}finally{setBusy(false);}}
 const currentJob=job||item?.job,unfinished=currentJob&&!['complete','cancelled'].includes(currentJob.status);
 return <div className="kiwe-screen kiwe-module"><WorkspaceHeader config={config}/><main><p className="native-eyebrow">YOUR KIWE APPS</p><h1>Apps.</h1><p>Add what your business needs. Your settings and activity keep their familiar places in Kiwe.</p>
  <div className="kiwe-task-tabs"><a href={config.appsHome}>← Home</a><button type="button" disabled={busy} onClick={()=>refresh(true)}>Check available Apps</button><a href={data?.nativeUpdatesUrl}>WordPress updates ↗</a></div>
  {error&&!selected&&<p role="alert" className="kiwe-module-error">{error}</p>}{data?.installationMessage&&<p role="status">{data.installationMessage}</p>}{data?.catalogue.message&&<p role="status">{data.catalogue.message}</p>}
  <div className="kiwe-module-grid">{data?.items.map(app=><article key={app.id} className={'kiwe-module-card kiwe-app-library-card '+(!app.enabled?'is-off':'')}><small>{app.externalOwner?'EXISTING SEO PROVIDER':app.enabled?'ON':app.installed?'INSTALLED · OFF':'AVAILABLE TO ADD'}</small><h2>{app.name}</h2><p>{app.description}</p>{app.version&&<p>Version {app.version}</p>}<div className="kiwe-module-footer"><button type="button" disabled={busy} onClick={()=>open(app.id)}>{app.installed?'Review App':'Add App'} ↗</button>{app.settingsUrl&&<a href={app.settingsUrl}>Open settings ↗</a>}</div></article>)}</div>
  {busy&&!selected&&<p role="status">Checking Apps…</p>}
  {selected&&item&&<WorkspaceDialog title={item.name} busy={busy} onClose={()=>{setSelected('');setReview(null);setJob(null);setError('');}}>
   <p>{item.description}</p>{error&&<p role="alert" className="kiwe-module-error">{error}</p>}
   {item.externalOwner?<p>Your existing SEO provider stays in charge. Complete its reviewed handover before turning on Kiwe SEO. Your full Design Context remains available.</p>:<>
    {unfinished?<section aria-live="polite"><p>{currentJob.message}</p><progress max={currentJob.total} value={currentJob.cursor}/><p>Step {currentJob.cursor} of {currentJob.total}</p>{!busy&&<><button onClick={()=>advance(currentJob)}>Resume installation</button><button onClick={()=>change('cancel',{job:currentJob.id})}>Cancel installation</button></>}</section>:<>
     {currentJob?.status==='complete'&&<p role="status">Installed and ready.</p>}
     {!review?<>{!item.installed&&<label className="kiwe-app-choice"><input type="checkbox" checked={automatic} disabled={busy||!item.canUpdate} onChange={e=>setAutomatic(e.target.checked)}/>Use WordPress automatic updates for this App</label>}<button type="button" disabled={busy||!data.catalogue.available||!data.canInstall} onClick={prepare}>{item.installed&&item.version===item.releaseVersion?'Verify current version':item.installed?'Review update':'Review installation'}</button></>:<section><p>Kiwe {item.name} {review.plan.version}</p>{review.plan.dependencies.map(text=><p key={text}>{text}</p>)}{item.installed&&item.version===review.plan.version&&<p>This version is already installed. Reinstalling verifies and replaces its code.</p>}<p>{review.plan.preserves}</p><p>{review.plan.automatic?'Compatible signed updates will use WordPress automatic updates.':'You will review updates through WordPress.'}</p><button type="button" disabled={busy} onClick={start}>{item.installed?(item.version===review.plan.version?'Reinstall ':'Update and turn on '):'Install and turn on '}{item.name}</button><button type="button" disabled={busy} onClick={()=>setReview(null)}>Back</button></section>}
    </>}
   </>}
   {item.installed&&item.canUpdate&&!unfinished&&<section><h3>Updates</h3><p>WordPress owns the schedule. Kiwe authenticates each release before installation.</p><button type="button" disabled={busy} onClick={()=>change('automatic',{enabled:!item.automatic,expected:item.automaticExpected})}>{item.automatic?'Turn automatic updates off':'Turn automatic updates on'}</button></section>}
   {item.canRecover&&!unfinished&&<section><h3>{item.recoveryRemovesApp?'Before installation':'Previous App code'}</h3><p>{item.recoveryRemovesApp?'Remove the newly installed App code. Existing products, orders, accounts and business records stay on your website.':'Restore the immediate previous code. Products, orders, accounts and business records stay current.'}</p><button type="button" disabled={busy} onClick={()=>{if(window.confirm(item.recoveryRemovesApp?'Remove this App code while keeping current business data?':'Restore this App’s previous code while keeping current business data?'))change('recover',{expected:item.recoveryExpected});}}>{item.recoveryRemovesApp?'Remove App code':'Restore previous code'}</button></section>}
   {busy&&<p role="status">Finishing this step…</p>}
  </WorkspaceDialog>}
 </main></div>;
}
createRoot(document.getElementById('kiwe-apps-app')).render(<App/>);
