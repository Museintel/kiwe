import React,{useEffect,useState} from 'react';
import {createRoot} from 'react-dom/client';
import {workflowClient} from './workflow-client.mjs';
import {WorkspaceHeader,useWorkspaceNavigationGuard} from './workspace-chrome.jsx';
import './system-app.css';

function check(value){
 if(!['auth','security'].includes(value?.app)||!Array.isArray(value.fields)||value.fields.length>300||!value.values||typeof value.values!=='object'||!/^[a-f0-9]{64}$/.test(value.expected)||value.fields.some(field=>typeof field.key!=='string'||typeof field.label!=='string'||!['bool','int','choice','text','textarea','url','secret','roles'].includes(field.type)))throw Error('These settings could not be read completely.');
 return value;
}
const equal=(a,b)=>JSON.stringify(a)===JSON.stringify(b);
function Field({field,value,onChange,roles,secretSet,disabled}){
 const id='system-'+field.key.replace(/[^a-zA-Z0-9_-]/g,'-');
 return <fieldset className="kiwe-system-field" disabled={disabled}><legend>{field.label}</legend>
  {field.help&&<p id={id+'-help'}>{field.help}</p>}
  {field.type==='choice'||field.type==='bool'?<div className="kiwe-system-choices">{Object.entries(field.type==='bool'?{on:'On',off:'Off'}:field.options||{}).map(([key,label])=><label key={key}><input type="radio" name={id} checked={field.type==='bool'?value===(key==='on'):value===key} onChange={()=>onChange(field.type==='bool'?key==='on':key)}/>{label}</label>)}</div>
  :field.type==='roles'?<div className="kiwe-system-choices">{Object.entries(roles||{}).map(([key,label])=><label key={key}><input type="checkbox" checked={Array.isArray(value)&&value.includes(key)} onChange={event=>onChange(event.target.checked?[...(value||[]),key]:(value||[]).filter(role=>role!==key))}/>{label}</label>)}</div>
  :field.type==='textarea'?<textarea id={id} aria-label={field.label} rows={4} maxLength={field.max} value={value||''} onChange={event=>onChange(event.target.value)}/>
  :<input id={id} aria-label={field.label} aria-describedby={field.help?id+'-help':undefined} type={field.type==='int'?'number':field.type==='secret'?'password':field.type==='url'?'url':'text'} autoComplete={field.type==='secret'?'new-password':'off'} min={field.min} max={field.max} maxLength={field.type==='int'?undefined:field.max} value={value??''} placeholder={field.type==='secret'&&secretSet?'Stored securely — leave blank to keep':undefined} onChange={event=>onChange(field.type==='int'?Number(event.target.value):event.target.value)}/>}
 </fieldset>;
}
function App(){
 const config=globalThis.KIWE_SYSTEM_APP,[request]=useState(()=>workflowClient(config));
 const [data,setData]=useState(null),[draft,setDraft]=useState({}),[busy,setBusy]=useState(true),[uncertain,setUncertain]=useState(false),[message,setMessage]=useState(''),[reload,setReload]=useState(0);
 const changed=data?Object.fromEntries(Object.entries(draft).filter(([key,value])=>!equal(value,data.values[key])&&!(data.fields.find(field=>field.key===key)?.type==='secret'&&!String(value).trim()))):{},dirty=Object.keys(changed).length>0;
 const navigate=useWorkspaceNavigationGuard(dirty,busy);
 useEffect(()=>{const controller=new AbortController();setBusy(true);request('',undefined,{},controller.signal).then(value=>{if(controller.signal.aborted)return;const state=check(value);setData(state);setDraft(state.values);setUncertain(false);setMessage('');}).catch(error=>{if(!controller.signal.aborted)setMessage(error.message);}).finally(()=>{if(!controller.signal.aborted)setBusy(false);});return()=>controller.abort();},[reload]);
 async function save(event){event.preventDefault();if(busy||uncertain||!dirty)return;setBusy(true);setMessage('Saving…');try{const next=check(await request('',{expected:data.expected,changes:changed}));setData(next);setDraft(next.values);setMessage('Saved to the original website settings.');}catch(error){setMessage(error.message);setUncertain(true);}finally{setBusy(false);}}
 const groups=data?data.fields.reduce((groups,field)=>{(groups[field.group]??=[]).push(field);return groups;},{}):{};
 return <div className="kiwe-screen kiwe-module"><WorkspaceHeader config={config} onNavigate={navigate}/><main><p className="native-eyebrow">INCLUDED WITH KIWE</p><h1>{config.app==='auth'?'Auth.':'Security.'}</h1><p>{config.app==='auth'?'Help people join, sign in and verify their account. Existing account, staff and recovery rules remain in force.':'Manage your website’s existing protection and security alerts. Notification delivery follows each eligible team member’s choices.'}</p><p>This App is included with Kiwe and cannot be removed.</p>
  {busy&&!data&&<p role="status">Opening settings…</p>}{message&&<p role={uncertain?'alert':'status'}>{message}</p>}
  {(!data||uncertain)&&!busy&&<button type="button" onClick={()=>{if(dirty&&!confirm('Discard these local edits and review the current saved settings?'))return;setReload(value=>value+1);}}>Reload and review saved settings</button>}
  {data&&<form onSubmit={save} className="kiwe-app-settings"><fieldset disabled={busy||uncertain}><div className="kiwe-system-groups">{Object.entries(groups).map(([group,fields])=>{const content=fields.map(field=><Field key={field.key} field={field} value={draft[field.key]} roles={data.roles} secretSet={data.secretSet?.[field.key]} onChange={value=>setDraft(previous=>({...previous,[field.key]:value}))}/>);return group.startsWith('Advanced')||group.startsWith('Role policy')?<details key={group}><summary>{group}</summary>{content}</details>:<section className="kiwe-settings-section" key={group}><h2>{group}</h2>{content}</section>;})}</div></fieldset><div className="kiwe-app-save"><button type="submit" disabled={busy||uncertain||!dirty}>{busy?'Saving…':'Save changes'}</button><a href={config.appsHome} onClick={navigate}>Back to Apps</a></div></form>}
  {!!data?.links?.length&&<nav aria-label="Related controls" className="kiwe-system-links">{data.links.map(link=><a key={link.url} href={link.url} onClick={navigate}>{link.label} ↗</a>)}</nav>}
 </main></div>;
}
createRoot(document.getElementById('kiwe-system-app')).render(<App/>);
