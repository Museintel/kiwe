import React,{useEffect,useState} from 'react';
import {createRoot} from 'react-dom/client';
import {workflowClient} from './workflow-client.mjs';
import {WorkspaceHeader,useWorkspaceNavigationGuard} from './workspace-chrome.jsx';
import {WorkspaceDialog} from './workspace-dialog.jsx';
import './forms-app.css';

function checkRecord(value){if(!Number.isSafeInteger(value?.id)||value.id<1||typeof value.title!=='string'||typeof value.created!=='string'||typeof value.formId!=='string'||typeof value.page!=='string')throw Error('This saved submission could not be read completely.');}

function App(){
 const config=globalThis.KIWE_FORMS_APP,request=workflowClient(config);
 const [data,setData]=useState(null),[form,setForm]=useState(''),[cursors,setCursors]=useState([0]),[refresh,setRefresh]=useState(0),[busy,setBusy]=useState(false),[error,setError]=useState(''),[selected,setSelected]=useState(null),[detail,setDetail]=useState(null),[reading,setReading]=useState(false),[detailError,setDetailError]=useState('');
 const navigate=useWorkspaceNavigationGuard(false,busy||reading),before=cursors[cursors.length-1];
 useEffect(()=>{if(Number.isSafeInteger(config.focusId)&&config.focusId>0)setSelected({id:config.focusId,title:'Form message'});},[]);
 useEffect(()=>{const abort=new AbortController();setBusy(true);setError('');request('',undefined,{form,...(before?{before}:{})},abort.signal).then(value=>{if(abort.signal.aborted)return;if(typeof value.available!=='boolean'||!Array.isArray(value.items)||value.items.length>20||!Array.isArray(value.forms)||value.forms.length>100||!Number.isSafeInteger(value.total)||value.total<0||typeof value.hasNext!=='boolean')throw Error('Forms could not be read completely.');value.items.forEach(checkRecord);setData(value);}).catch(e=>{if(!abort.signal.aborted)setError(e.message);}).finally(()=>{if(!abort.signal.aborted)setBusy(false);});return()=>abort.abort();},[form,before,refresh]);
 useEffect(()=>{if(!selected)return;const abort=new AbortController();setReading(true);setDetail(null);setDetailError('');request('/'+selected.id,undefined,{},abort.signal).then(value=>{if(abort.signal.aborted)return;checkRecord(value.item);if(value.item.id!==selected.id||!Array.isArray(value.item.fields)||value.item.fields.length>100||value.item.fields.some(f=>typeof f.label!=='string'||typeof f.value!=='string'))throw Error('This submission could not be read completely.');setDetail(value.item);}).catch(e=>{if(!abort.signal.aborted)setDetailError(e.message);}).finally(()=>{if(!abort.signal.aborted)setReading(false);});return()=>abort.abort();},[selected?.id]);
 return <div className="kiwe-screen kiwe-module"><WorkspaceHeader config={config} onNavigate={navigate}/><main><p className="native-eyebrow">YOUR DAILY INBOX</p><h1>Forms.</h1><p>Messages saved by your website’s native Bricks forms. Only the site owner can open this inbox.</p>
  <div className="kiwe-task-tabs"><nav aria-label="Form views"><button type="button" aria-pressed={!form} disabled={busy} onClick={()=>{setForm('');setCursors([0]);}}>All submissions</button></nav></div>
  <div className="kiwe-task-filters"><label>Form<select value={form} disabled={busy} onChange={e=>{setForm(e.target.value);setCursors([0]);}}><option value="">All forms</option>{data?.forms.map(f=><option key={f.id} value={f.id}>{f.label} · {f.count}</option>)}</select></label><button className="kiwe-filter-action" type="button" disabled={busy} onClick={()=>setRefresh(v=>v+1)}>Refresh</button></div>
  {error&&<p role="alert" className="kiwe-module-error">{error}</p>}{busy&&<p role="status">Opening saved submissions…</p>}
  {data&&!data.available&&<p role="status">No native Bricks submission inbox is available yet. Your website manager needs to enable Bricks form storage and the Save submission action on enquiry forms. Existing forms and emails are unchanged.</p>}
  {data?.available&&<p role="status">{data.total} {data.total===1?'submission':'submissions'}{form?' for this form':''}.</p>}
  {data?.formsLimited&&<p>The form selector shows the 100 most recently used forms. All submissions remain available below.</p>}
  <div className="kiwe-forms-list" aria-busy={busy}>{data?.items.map(item=><article className="kiwe-module-card" key={item.id}><div><small>SUBMISSION #{item.id}</small><h2>{item.title}</h2><p>{item.page} · {item.created}</p></div><div className="kiwe-module-footer"><button type="button" disabled={busy} onClick={()=>setSelected(item)}>Read message ↗</button></div></article>)}</div>
  {data?.available&&!data.items.length&&!busy&&<p>No submissions in this view yet.</p>}
  {(cursors.length>1||data?.hasNext)&&<nav className="kiwe-module-pagination" aria-label="Submission pages"><button disabled={busy||cursors.length===1} onClick={()=>setCursors(v=>v.slice(0,-1))}>Previous</button><span>Page {cursors.length}</span><button disabled={busy||!data?.hasNext} onClick={()=>setCursors(v=>[...v,data.nextBefore])}>Next</button></nav>}
  <p className="kiwe-module-note kiwe-forms-note">Contact email: {config.businessEmail||'not set'}. <a href={config.contactUrl} onClick={navigate}>Update business contact details ↗</a>. Delivery depends on each native form’s email action and the site’s mail service. A saved message is not proof that email was delivered.</p>
  {selected&&<WorkspaceDialog title={selected.title} onClose={()=>setSelected(null)}>{reading&&<p role="status">Opening message…</p>}{detailError&&<p role="alert">{detailError}</p>}{detail&&<><p>Submission #{detail.id} · {detail.created}</p><dl>{detail.fields.map((field,index)=><React.Fragment key={index}><dt><strong>{field.label}</strong></dt><dd className="kiwe-form-answer">{field.value||'—'}</dd></React.Fragment>)}</dl>{!detail.fields.length&&<p>No displayable business fields were saved. Hidden, password, file and credential fields are omitted.</p>}</>}</WorkspaceDialog>}
 </main></div>;
}
createRoot(document.getElementById('kiwe-forms-app')).render(<App/>);
