import React,{useEffect,useRef,useState} from 'react';
import {CoreTabs} from './core-tabs.jsx';
import {WorkspaceDialog} from './workspace-dialog.jsx';
import {MediaField} from './native-media.jsx';
import {LegacyVisualEditor} from './legacy-visual-editor.jsx';
import {SeoEditorPanel} from './seo-editor.jsx';

const editable=['stableId','recordId','title','summary','description','status','imageId','parentId','menuOrder','categoryPaths','taxonomyPaths','meta'];
const clean=item=>Object.fromEntries(editable.filter(key=>key in item).map(key=>[key,item[key]]));
const writing=item=>JSON.stringify([item.title,item.description,item.summary]);

/** Contextual Service journey; the existing native writer and WordPress recovery remain authoritative. */
export function ServiceJourneyEditor({field,data,request,config,OrganizeFields,onClose,onGuardChange}){
 const seed=data.values['services.items'].find(item=>item.stableId===field.serviceId)||{stableId:field.serviceId,recordId:0,title:'',summary:'',description:'',status:'draft',imageId:0,parentId:0,menuOrder:0,categoryPaths:'',taxonomyPaths:{},meta:{}};
 const [record,setRecord]=useState(seed),[draft,setDraft]=useState(seed),[tab,setTab]=useState('create'),[state,setState]=useState('ready'),[message,setMessage]=useState(''),[seoDirty,setSeoDirty]=useState(false);
 const [recovery,setRecovery]=useState({phase:seed.canHistory?'checking':'idle',expected:'',copyExpected:'',snapshot:''}),[check,setCheck]=useState(0),[history,setHistory]=useState(null),[version,setVersion]=useState(null),[historyState,setHistoryState]=useState('idle');
 const locked=useRef(false),current=useRef(draft),readContent=useRef(null),alive=useRef(true),historySequence=useRef(0);
 const [images,setImages]=useState(data.service.images||{}),[editorEpoch,setEditorEpoch]=useState(0);
 current.current=draft;
 const dirty=JSON.stringify(clean(draft))!==JSON.stringify(clean(record)),writingDirty=writing(draft)!==writing(record),busy=state==='saving'||recovery.phase==='saving'||historyState==='saving',disabled=state!=='ready'||busy;
 useEffect(()=>{alive.current=true;return()=>{alive.current=false;historySequence.current++;};},[]);
 const previewUrl=record.status==='publish'?(record.url||data.recordUrls?.[record.recordId]||''):'';
 useEffect(()=>{onGuardChange({dirty:dirty||seoDirty||state==='uncertain',busy,previewUrl});},[dirty,seoDirty,state,busy,previewUrl,onGuardChange]);
 useEffect(()=>()=>onGuardChange({dirty:false,busy:false}),[onGuardChange]);
 useEffect(()=>{if(!record.recordId||!record.canHistory)return;const controller=new AbortController();setRecovery({phase:'checking',expected:'',copyExpected:'',snapshot:''});request('',undefined,{operation:'service_versions',id:record.recordId,editorExpected:record.expected},controller.signal).then(value=>{if(controller.signal.aborted)return;setRecovery({phase:value.autosave?'blocked':'ready',expected:value.expected,copyExpected:value.autosave?.expected||'',snapshot:''});}).catch(error=>{if(!controller.signal.aborted){setRecovery(previous=>({...previous,phase:'uncertain'}));setMessage(error.message);}});return()=>controller.abort();},[record.recordId,record.expected,check]);
 useEffect(()=>{if(history||!writingDirty||disabled||recovery.phase!=='ready'||recovery.snapshot===writing(draft))return;const timer=setTimeout(saveRecovery,1500);return()=>clearTimeout(timer);},[history,writingDirty,disabled,recovery.phase,recovery.snapshot,writing(draft)]);
 function descriptionChanged(){if(!readContent.current)return;const description=readContent.current();setDraft(previous=>({...previous,description}));}
 function close(){if(busy)return;if((dirty||seoDirty)&&!confirm('Discard unsaved Service changes? A confirmed WordPress recovery copy stays available in Timeline.'))return;onClose();}
 function changeTab(destination){if(seoDirty&&destination!=='seo'&&!confirm('Discard unsaved SEO changes?'))return;if(destination!=='seo')setSeoDirty(false);setTab(destination);}
 function closeHistory(){historySequence.current++;setHistory(null);setVersion(null);setHistoryState('idle');}
 async function saveRecovery(){
  if(locked.current||disabled||recovery.phase!=='ready'||!writingDirty)return;
  locked.current=true;setRecovery(previous=>({...previous,phase:'saving'}));const value=current.current,snapshot=writing(value);
  try{const result=await request('',{operation:'service_autosave',id:record.recordId,expected:recovery.expected,autosaveExpected:recovery.copyExpected,title:value.title,content:value.description,excerpt:value.summary});if(result.expected!==recovery.expected||!result.autosave?.expected)throw Error('Recovery could not be confirmed.');if(alive.current)setRecovery({phase:'ready',expected:result.expected,copyExpected:result.autosave.expected,snapshot,modifiedGmt:result.autosave.modifiedGmt});}
  catch(error){if(alive.current){setRecovery(previous=>({...previous,phase:'uncertain'}));setMessage(error.message||'Recovery could not be confirmed. Your writing remains here.');}}
  finally{locked.current=false;}
 }
 async function save(event,destination){
  event.preventDefault();if(locked.current||disabled||!draft.title.trim())return;
  locked.current=true;setState('saving');setMessage('');
  try{const value=clean(current.current);if(!record.recordId)value.status='draft';const result=await request('',{operation:'service_record',value,expected:record.expected||data.expected});if(!result.saved?.recordId)throw Error('WordPress did not confirm the Service save.');setRecord(result.saved);setDraft(result.saved);setTab(destination);setState('ready');setMessage(result.saved.status==='draft'?'Service draft saved.':'Service saved.');}
  catch(error){setState('uncertain');setMessage(error.message||'The save could not be confirmed. Reload and review.');}
  finally{locked.current=false;}
 }
 async function openHistory(){
  if(busy||historyState==='loading')return;const sequence=++historySequence.current;setHistoryState('loading');setHistory({revisions:[],autosave:null});setVersion(null);setMessage('');
  try{const value=await request('',undefined,{operation:'service_versions',id:record.recordId,editorExpected:record.expected});if(sequence!==historySequence.current)return;setHistory(value);setHistoryState('ready');}
  catch(error){if(sequence!==historySequence.current)return;setHistoryState('uncertain');setMessage(error.message||'History could not be read.');}
 }
 async function review(id){if(historyState!=='ready')return;const sequence=++historySequence.current;setHistoryState('loading');try{const value=await request('',undefined,{operation:'service_version',id});if(sequence!==historySequence.current)return;setVersion(value);setHistoryState('ready');}catch(error){if(sequence!==historySequence.current)return;setHistoryState('uncertain');setMessage(error.message);}}
 async function historyAction(operation){
  if(!version||historyState!=='ready'||busy||dirty||seoDirty)return;
  if(!confirm(operation==='service_restore'?'Restore this version’s name, description and summary? WordPress keeps the previous writing.':'Discard your reviewed recovery copy?'))return;
  setHistoryState('saving');
  try{await request('',{operation,id:record.recordId,revisionId:version.id,expected:version.recordExpected,[operation==='service_restore'?'revisionExpected':'recoveryExpected']:version.expected});if(operation==='service_restore'){onClose();return;}setHistory(null);setVersion(null);setHistoryState('idle');setCheck(value=>value+1);setMessage('Recovery copy discarded.');}
  catch(error){setHistoryState('uncertain');setMessage(error.message||'The change could not be confirmed. Reopen history before trying again.');}
 }
 return <section className="continuity-service-journey" aria-label="Service editor">
  <div className="continuity-service-editor-head"><h2>{record.recordId?'Edit service':'New service'}</h2><button type="button" disabled={busy} onClick={close}>Close</button></div>
  <CoreTabs active={tab} label="Service journey" onChange={changeTab} tabs={[{id:'create',label:record.recordId?'Edit':'Create',panelId:'service-create',disabled:busy},{id:'organize',label:'Organize',panelId:'service-organize',disabled:busy||!record.recordId},...(config.seoEndpoint?[{id:'seo',label:'SEO',panelId:'service-seo',disabled:busy||!record.recordId||dirty}]:[])]}/>
  <form id="service-create" hidden={tab!=='create'} role="tabpanel" onSubmit={event=>save(event,'organize')}>
   <MediaField label="Service image" seoEndpoint={config.seoEndpoint} nonce={config.nonce} disabled={disabled||!data.canUpload} value={draft.imageId?[{id:draft.imageId,url:images[draft.imageId]||''}]:[]} onChange={items=>{setImages(previous=>({...previous,...Object.fromEntries(items.map(item=>[item.id,item.url]))}));setDraft(previous=>({...previous,imageId:items[0]?.id||0}));}}/>
   <label>Name<input maxLength={200} required disabled={disabled} value={draft.title} onChange={event=>setDraft(previous=>({...previous,title:event.target.value}))}/></label>
   <label>Summary<textarea rows={3} maxLength={16000} disabled={disabled} value={draft.summary} onChange={event=>setDraft(previous=>({...previous,summary:event.target.value}))}/></label>
   <LegacyVisualEditor key={(record.expected||'new')+'-'+editorEpoch} initial={record.description} editorId="kiwe-service-description" label="Service description" heading="Description" toolbar="formatselect,bold,italic,underline,strikethrough,bullist,numlist,blockquote,link,unlink,undo,redo" disabled={disabled} readContent={readContent} onTouch={descriptionChanged}/>
   <div className="continuity-actions"><button type="submit" disabled={disabled||!dirty||!draft.title.trim()}>{record.recordId?'Save and organize':'Save draft and organize'}</button>{record.canHistory&&writingDirty&&<button type="button" disabled={disabled||recovery.phase!=='ready'} onClick={saveRecovery}>Save recovery copy</button>}</div>
  </form>
  {tab==='organize'&&<form id="service-organize" role="tabpanel" onSubmit={event=>save(event,config.seoEndpoint?'seo':'organize')}><OrganizeFields items={[draft]} disabled={disabled} onChange={items=>setDraft(items[0])} meta={data.service} selected={draft.stableId} request={request} seoEndpoint={config.seoEndpoint} nonce={config.nonce} organizeOnly/><div className="continuity-actions"><button type="submit" disabled={disabled||!dirty}>Save changes</button>{record.canHistory&&<button type="button" disabled={busy} onClick={openHistory}>View revisions</button>}</div></form>}
  {tab==='seo'&&<section id="service-seo" role="tabpanel"><SeoEditorPanel endpoint={config.seoEndpoint} nonce={config.nonce} scope="service" id={record.recordId} onDirtyChange={setSeoDirty} onSaved={()=>setCheck(value=>value+1)}/></section>}
  {record.canHistory&&<p className="continuity-service-recovery" role="status">{recovery.phase==='checking'?'Checking WordPress recovery…':recovery.phase==='saving'?'Saving recovery copy…':recovery.phase==='blocked'?'An earlier recovery copy is waiting in revisions. Review it before replacing it.':recovery.phase==='uncertain'?'Recovery could not be confirmed. Your writing remains in this editor.':recovery.modifiedGmt?'Recovery saved at '+recovery.modifiedGmt+' UTC.':'Automatic recovery saves name, description and summary after a brief pause.'} {recovery.phase==='uncertain'&&<button type="button" disabled={busy} onClick={()=>setCheck(value=>value+1)}>Check recovery</button>}</p>}
  {(dirty||seoDirty)&&<div className="continuity-actions"><p className="continuity-note">You have unsaved changes.</p>{dirty&&<button type="button" disabled={busy} onClick={()=>{if(confirm('Undo the unsaved Service changes in this editor?')){setDraft(record);setEditorEpoch(value=>value+1);setCheck(value=>value+1);}}}>Undo edits</button>}</div>}{message&&<p role="status">{message}</p>}{state==='uncertain'&&<p>Close and reload the saved Service before another save.</p>}
  {history&&<WorkspaceDialog title="Service revisions" busy={busy} onClose={closeHistory} className="continuity-service-history">
   {historyState==='loading'&&<p role="status">Reading WordPress history…</p>}
   {!version&&<div className="continuity-service-version-list">{[...(history.autosave?[{...history.autosave,recovery:true}]:[]),...history.revisions].map(item=><article key={item.id}><small>{item.recovery?'UNSAVED RECOVERY':'SAVED VERSION'} · {item.modifiedGmt} UTC · {item.author}</small><h3>{item.title||'Untitled version'}</h3><p>{item.excerpt}</p><button type="button" disabled={historyState!=='ready'} onClick={()=>review(item.id)}>Review version</button></article>)}{historyState==='ready'&&!history.autosave&&!history.revisions.length&&<p>No earlier writing is saved in WordPress yet.</p>}</div>}
   {version&&<><div className="continuity-version-compare"><section><h3>This version</h3><h4>{version.title}</h4><p>Summary</p><div dangerouslySetInnerHTML={{__html:version.excerptHtml}}/><div className="continuity-version-content" dangerouslySetInnerHTML={{__html:version.contentHtml}}/></section><section><h3>Current saved service</h3><h4>{version.currentTitle}</h4><p>Summary</p><div dangerouslySetInnerHTML={{__html:version.currentExcerptHtml}}/><div className="continuity-version-content" dangerouslySetInnerHTML={{__html:version.currentHtml}}/></section></div><p>Restoring changes the name, description and summary. Publication, categories, image, hierarchy and other settings stay with the current Service.</p>{(dirty||seoDirty)&&<p>Save or undo the editor changes before restoring or discarding a recovery copy.</p>}<div className="continuity-actions">{version.canRestore&&<button type="button" disabled={historyState!=='ready'||dirty||seoDirty} onClick={()=>historyAction('service_restore')}>Restore writing</button>}{version.canDiscard&&<button type="button" disabled={historyState!=='ready'||dirty||seoDirty} onClick={()=>historyAction('service_discard')}>Discard recovery copy</button>}<button type="button" disabled={historyState==='saving'} onClick={()=>setVersion(null)}>Back to versions</button></div></>}
   {historyState==='uncertain'&&<p role="alert">{message} Close and reopen history before another action.</p>}
  </WorkspaceDialog>}
 </section>;
}
