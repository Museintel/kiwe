import React,{useEffect,useRef,useState} from 'react';
import {workflowClient} from './workflow-client.mjs';
import {FeedbackSettings} from './feedback-settings.jsx';

/** Native discussion policy has its own stale-write token, separate from shell choices. */
export function FeedbackSettingsTab({config,onBusy}){
 const [request]=useState(()=>workflowClient({...config,endpoint:config.feedbackEndpoint}));
 const [data,setData]=useState(null),[busy,setBusy]=useState(true),[uncertain,setUncertain]=useState(false),[message,setMessage]=useState('');
 const alive=useRef(true),saving=useRef(false);
 const check=value=>{if(!value?.discussion?.values||typeof value.discussion.expected!=='string')throw Error('Feedback settings could not be read completely.');return value;};
 useEffect(()=>{alive.current=true;const abort=new AbortController();request('',undefined,{},abort.signal).then(check).then(value=>{if(alive.current)setData(value);}).catch(error=>{if(alive.current)setMessage(error.message);}).finally(()=>{if(alive.current)setBusy(false);});return()=>{alive.current=false;abort.abort();};},[]);
 async function reload(){if(saving.current)return;setBusy(true);setMessage('');try{setData(check(await request()));setUncertain(false);}catch(error){setMessage(error.message);}finally{setBusy(false);}}
 async function save(operation,field,value,expected){
  if(busy||uncertain||saving.current)return;saving.current=true;setBusy(true);onBusy(true);setMessage('Saving feedback settings…');
  try{const saved=check(await request('',{operation,field,value,expected}));if(alive.current){setData(saved);setMessage('Feedback settings saved.');}}
  catch(error){if(alive.current){setUncertain(true);setMessage((error.message||'The save could not be confirmed.')+' Reload the saved settings before trying again.');}}
  finally{saving.current=false;if(alive.current)setBusy(false);onBusy(false);}
 }
 return <section id="app-settings-feedback" role="tabpanel" className="kiwe-settings-section"><h2>Feedback</h2><p>Manage comments, reviews and the conversation across your website. Read and reply to contributions in Daily → Feedback.</p>{message&&<p role={uncertain||!data?'alert':'status'}>{message}</p>}{busy&&!data&&<p role="status">Reading feedback settings…</p>}{(!data||uncertain)&&!busy&&<button type="button" onClick={reload}>Reload saved settings</button>}{data&&<FeedbackSettings data={data} busy={busy||uncertain} save={save}/>}</section>;
}
