import React,{useEffect,useRef,useState} from 'react';
import {WorkspaceDialog} from './workspace-dialog.jsx';
import {workflowClient} from './workflow-client.mjs';

/** Same small recovery action wherever native content is listed. */
export function TrashRecordAction({endpoint,nonce,id,type,disabled=false,onTrashed}){
 const [open,setOpen]=useState(false),[record,setRecord]=useState(null),[state,setState]=useState('idle'),[message,setMessage]=useState(''),lock=useRef(false);
 const request=workflowClient({endpoint,nonce});
 useEffect(()=>{if(!open)return;const controller=new AbortController();setState('loading');request('',undefined,{operation:'record',type,id},controller.signal).then(value=>{if(!controller.signal.aborted){setRecord(value);setState('ready');setMessage('');}}).catch(error=>{if(!controller.signal.aborted){setState('uncertain');setMessage(error.message);}});return()=>controller.abort();},[open]);
 async function trash(){if(lock.current||state!=='ready')return;lock.current=true;setState('saving');try{const result=await request('',{operation:'trash',type,item:{id,expected:record.expected}});setMessage(result.message);setState('done');onTrashed?.(result);}catch(error){setMessage(error.message+' Refresh the original list to check its current state.');setState('uncertain');}finally{lock.current=false;}}
 return <><button type="button" disabled={disabled} onClick={()=>setOpen(true)}>Move to Trash</button>{open&&<WorkspaceDialog title="Move to Trash" busy={state==='saving'} onClose={()=>{setOpen(false);if(state==='done'||state==='uncertain')onTrashed?.();}}>{record&&<><h3>{record.title}</h3><p>This record will leave its current list. Restore it from Organize → Trash if needed.</p></>}<p role={state==='uncertain'?'alert':'status'}>{message||({loading:'Checking the current record…',saving:'Moving…'}[state]||'')}</p>{state==='ready'&&<button type="button" className="kiwe-new-action" onClick={trash}>Move this record to Trash</button>}{state==='done'&&<button type="button" onClick={()=>{setOpen(false);onTrashed?.();}}>Done</button>}</WorkspaceDialog>}</>;
}
