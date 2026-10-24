import {TrashShortcut} from './trash-shortcut.jsx';
import {TrashRecordAction} from './trash-record-action.jsx';
import React,{useEffect,useRef,useState} from 'react';
import {createRoot} from 'react-dom/client';
import {workflowClient,checkRecord} from './workflow-client.mjs';
import {WorkspaceHeader,useWorkspaceNavigationGuard} from './workspace-chrome.jsx';
import {WorkspaceDialog} from './workspace-dialog.jsx';
import {ContentArchiveAction} from './content-archive.jsx';
import './templates-app.css';

function TemplateForm({item,types,busy,blocked,onSave,onClose}){
 const [title,setTitle]=useState(item?.title||''),[type,setType]=useState(Object.keys(types)[0]||'');
 const dirty=title!==(item?.title||'')||(!item&&type!==Object.keys(types)[0]);
 return <WorkspaceDialog title={item?'Rename template':'New template'} busy={busy} dirty={dirty} onClose={onClose}>
  <form className="kiwe-module-form" onSubmit={event=>{event.preventDefault();onSave({title:title.trim(),...(item?{expected:item.expected}:{type})});}}>
   <label>Name<input autoFocus required maxLength={240} value={title} disabled={busy||blocked} onChange={event=>setTitle(event.target.value)}/></label>
   {!item&&<label>Template type<select value={type} disabled={busy||blocked} onChange={event=>setType(event.target.value)}>{Object.entries(types).map(([key,label])=><option key={key} value={key}>{label}</option>)}</select></label>}
   {!item&&<p className="kiwe-module-note">Starts as a draft. Design and display conditions are managed in Bricks.</p>}
   <button className="kiwe-new-action" disabled={busy||blocked||!title.trim()||(!item&&!type)}>{busy?'Saving…':item?'Save name':'Create template'}</button>
  </form>
 </WorkspaceDialog>;
}
function App(){
 const config=globalThis.KIWE_TEMPLATES_APP,request=workflowClient(config);
 const [data,setData]=useState(null),[query,setQuery]=useState({status:'all',type:'',sort:'modified',search:'',page:1}),[search,setSearch]=useState(''),[editing,setEditing]=useState(null),[busy,setBusy]=useState(false),[blocked,setBlocked]=useState(false),[error,setError]=useState(''),[message,setMessage]=useState(''),[refresh,setRefresh]=useState(0);
 const lock=useRef(false),navigate=useWorkspaceNavigationGuard(false,busy);
 function valid(value){if(typeof value.available!=='boolean'||!Array.isArray(value.items)||value.items.length>12||!value.types||typeof value.hasNext!=='boolean')throw Error('Templates could not be read completely.');value.items.forEach(item=>checkRecord(item));return value;}
 useEffect(()=>{const abort=new AbortController();setBusy(true);setError('');request('',undefined,query,abort.signal).then(value=>{if(!abort.signal.aborted){setData(valid(value));setBlocked(false);}}).catch(e=>{if(!abort.signal.aborted)setError(e.message);}).finally(()=>{if(!abort.signal.aborted)setBusy(false);});return()=>abort.abort();},[JSON.stringify(query),refresh]);
 async function save(body){if(lock.current||blocked)return;lock.current=true;setBusy(true);setError('');try{const value=await request(editing?.id?'/'+editing.id:'',body);checkRecord(value.item);setMessage(editing?.id?'Template name saved.':'Draft template created.');setEditing(null);setQuery({...query,status:'all',type:'',search:'',page:1});setData(previous=>({...previous,items:[value.item,...previous.items.filter(item=>item.id!==value.item.id)].slice(0,12)}));}catch(e){setError(e.message+' Refresh Templates before retrying.');setBlocked(true);}finally{lock.current=false;setBusy(false);}}
 return <div className="kiwe-screen kiwe-module"><WorkspaceHeader config={config} onNavigate={navigate}/><main><p className="native-eyebrow">YOUR WEBSITE, BY DESIGN</p><h1>Templates.</h1><p>Headers, footers and reusable layouts, connected to Bricks.</p>
  <div className="kiwe-task-tabs"><nav aria-label="Template status">{[['all','all templates'],['draft','drafts'],['publish','published']].map(([id,label])=><button key={id} type="button" aria-pressed={query.status===id} disabled={busy} onClick={()=>setQuery({...query,status:id,page:1})}>{label}</button>)}</nav><div className="kiwe-archive-actions"><TrashShortcut config={config} type="bricks_template" disabled={busy||blocked}/>{config.canExportArchiveCollections?.templates&&<ContentArchiveAction endpoint={config.archiveEndpoint} nonce={config.nonce} mediaEndpoint={config.mediaArchiveEndpoint} scope="templates" mode="collection" timezone={config.archiveTimezone} disabled={busy||!data?.available}/>} {config.canImportArchives?.template&&<ContentArchiveAction endpoint={config.archiveEndpoint} nonce={config.nonce} mediaEndpoint={config.mediaArchiveEndpoint} scope="template" mode="import" disabled={busy||!data?.available} onImported={()=>setRefresh(value=>value+1)}/>}<button className="kiwe-new-action" type="button" disabled={busy||blocked||!data?.available||!Object.keys(data.types).length} onClick={()=>setEditing({})}>+ New template</button></div></div>
  <div className="kiwe-task-filters"><form role="search" onSubmit={e=>{e.preventDefault();setQuery({...query,search:search.trim(),page:1});}}><label>Find a template<input type="search" maxLength={200} value={search} onChange={e=>setSearch(e.target.value)}/></label><button disabled={busy}>Search</button></form><label>Type<select disabled={busy} value={query.type} onChange={e=>setQuery({...query,type:e.target.value,page:1})}><option value="">All types</option>{Object.entries(data?.types||{}).map(([id,label])=><option key={id} value={id}>{label}</option>)}</select></label><label>Sort templates<select value={query.sort} disabled={busy} onChange={e=>setQuery({...query,sort:e.target.value,page:1})}><option value="modified">Recently updated</option><option value="date">Newest created</option><option value="title">Name A–Z</option></select></label><button className="kiwe-filter-action" type="button" disabled={busy} onClick={()=>{setEditing(null);setRefresh(value=>value+1);}}>Refresh</button></div>
  {message&&<p role="status">{message}</p>}{error&&<p role="alert" className="kiwe-module-error">{error}</p>}{busy&&!data&&<p role="status">Opening native templates…</p>}
  {data&&!data.available&&<p>Bricks is not active on this website. Templates will become available when your website manager activates Bricks.</p>}
  <div className="kiwe-module-grid">{data?.items.map(item=><article className="kiwe-module-card" key={item.id}><div><small>{item.typeLabel||'TEMPLATE'} · {item.status}</small><h2>{item.title||'Untitled template'}</h2><p className="kiwe-module-note">Updated {item.modified}</p></div><div className="kiwe-module-footer">{item.canTrash&&config.trashEndpoint&&<TrashRecordAction endpoint={config.trashEndpoint} nonce={config.nonce} id={item.id} type="bricks_template" disabled={busy||blocked||!!editing} onTrashed={()=>setRefresh(v=>v+1)}/>}<button type="button" disabled={busy||blocked} onClick={()=>setEditing(item)}>Rename</button>{item.builderUrl&&<a href={item.builderUrl} target="_blank" rel="noopener noreferrer">Edit with Bricks ↗</a>}{item.canExport&&<ContentArchiveAction endpoint={config.archiveEndpoint} nonce={config.nonce} mediaEndpoint={config.mediaArchiveEndpoint} scope="template" id={item.id} label={item.title} disabled={busy||blocked}/>}</div></article>)}</div>
  {data?.available&&!data.items.length&&!busy&&<p>No templates in this view yet.</p>}
  {(query.page>1||data?.hasNext)&&<nav className="kiwe-module-pagination" aria-label="Template pages"><button disabled={busy||query.page===1} onClick={()=>setQuery({...query,page:query.page-1})}>Previous</button><span>Page {query.page}</span><button disabled={busy||!data?.hasNext} onClick={()=>setQuery({...query,page:query.page+1})}>Next</button></nav>}
  {editing&&<TemplateForm item={editing.id?editing:null} types={data?.types||{}} busy={busy} blocked={blocked} onSave={save} onClose={()=>setEditing(null)}/>}
 </main></div>;
}
createRoot(document.getElementById('kiwe-templates-app')).render(<App/>);
