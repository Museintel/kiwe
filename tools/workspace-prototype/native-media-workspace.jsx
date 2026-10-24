import React,{useEffect,useState} from 'react';
import {WorkspaceHeader} from './workspace-chrome.jsx';
import {MediaUpload} from './media-upload.jsx';
import {TrashRecordAction} from './trash-record-action.jsx';
import {ContextMark} from './context-mark.jsx';
import {publishContextProgress} from './context-progress-event.mjs';
import './native-media-workspace.css';

const text=value=>typeof value==='string'?value:'';

export function MediaWorkspace({config}){
 const [uploadOpen,setUploadOpen]=useState(false);
  const [item,setItem]=useState(null),[draft,setDraft]=useState(null),[state,setState]=useState('idle'),[message,setMessage]=useState('');
  const request=async (id,body)=>{
    const response=await fetch(config.mediaEndpoint+'/'+id+'?context=edit',{method:body?'POST':'GET',credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce,...(body?{'Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify(body)}:{})});
    const value=await response.json();
    if(!response.ok)throw Error(text(value?.message)||'WordPress could not confirm this media item.');
    return value;
  };
  const select=async id=>{
    setState('loading');setMessage('');
    try{const value=await request(id);if(value.status==='trash'){setItem(null);setDraft(null);setState('idle');setMessage('This file is in Trash. Restore it from Organize → Trash.');return;}setItem(value);setDraft({title:text(value.title?.raw||value.title?.rendered),alt_text:text(value.alt_text),caption:text(value.caption?.raw),description:text(value.description?.raw)});setState('ready');}
    catch(error){setState('error');setMessage(error.message);}
  };
  useEffect(()=>{if(Number(config.initialId)>0)select(Number(config.initialId));},[]);
  const open=()=>{
    if(!window.wp?.media){setState('error');setMessage('The WordPress media library did not load. Refresh this page and try again.');return;}
    const frame=window.wp.media({title:'Choose or upload a file',button:{text:'Open details'},multiple:false});
    frame.on('select',()=>{const attachment=frame.state().get('selection')?.first();const id=Number(attachment?.get('id')||0);if(id>0)select(id);});
    frame.open();
  };
  const save=async event=>{
    event.preventDefault();if(!item||!draft)return;
    setState('saving');setMessage('');
    try{const value=await request(item.id,draft);setItem(value);setDraft({title:text(value.title?.raw||value.title?.rendered),alt_text:text(value.alt_text),caption:text(value.caption?.raw),description:text(value.description?.raw)});setState('ready');setMessage('Saved to the WordPress media library.');}
    catch(error){setState('error');setMessage(error.message+' Your edits are still here; review them before retrying.');}
  };
 const mark=async selected=>{if(!item||item.kiwe_design_context?.automatic)return;setState('saving');try{const response=await fetch(config.contextEndpoint+'/'+item.id+'/selection',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce,'Content-Type':'application/json','X-Kiwe-Mutation':'1'},body:JSON.stringify({selected,expected:!!item.kiwe_design_context?.explicit})});const value=await response.json();if(!response.ok)throw Error(value.message||'Selection could not be confirmed.');publishContextProgress(response);setItem({...item,kiwe_design_context:{...item.kiwe_design_context,selected,explicit:selected}});setState('ready');setMessage(value.message);}catch(error){setState('uncertain');setMessage(error.message+' Reload to review the current selection.');}};
 const dirty=!!item&&!!draft&&JSON.stringify(draft)!==JSON.stringify({title:text(item.title?.raw||item.title?.rendered),alt_text:text(item.alt_text),caption:text(item.caption?.raw),description:text(item.description?.raw)});
  return <div className="kiwe-screen kiwe-media-shell"><a className="kiwe-skip" href="#media-main">Skip to media</a><WorkspaceHeader config={config}/><main id="media-main"><p className="native-eyebrow">YOUR WEBSITE, YOUR FILES</p><h1>Media library.</h1><p>Upload images and files, or find an existing item. WordPress stores the file; the details you save here travel with it.</p>
    <div className="kiwe-media-grid"><section className="kiwe-media-tile"><small>LIBRARY TASK</small><h2>Choose a file.</h2><p>Search the library or upload a new file in the same WordPress media picker used by your articles.</p><button type="button" onClick={()=>setUploadOpen(true)}>+ Upload files</button><button type="button" onClick={open}>Browse files</button><a href={config.trashUrl}>Trash ↗</a></section>
    <section className="kiwe-media-tile kiwe-media-details"><small>FILE DETAILS</small><h2>{item?'Edit file details.':'Ready when you are.'}</h2>{!item?<p>Choose a file to update its name, image alt text, caption and description.</p>:<><p className="kiwe-media-filename">{text(item.source_url).split('/').pop()}</p>{config.canCurate&&<><ContextMark label="Mark as design context" selected={item.kiwe_design_context?.selected} disabled={state!=='ready'||item.kiwe_design_context?.automatic||(!item.kiwe_design_context?.public&&!item.kiwe_design_context?.selected)} onChange={mark}/>{item.kiwe_design_context?.automatic&&<p>{item.kiwe_design_context.reason}</p>}</>}<form onSubmit={save}><label>Title<input value={draft?.title||''} maxLength={255} onChange={e=>setDraft({...draft,title:e.target.value})} disabled={state==='saving'||state==='deleting'}/></label>{text(item.mime_type).startsWith('image/')&&<label>Image alt text<input value={draft?.alt_text||''} maxLength={500} onChange={e=>setDraft({...draft,alt_text:e.target.value})} disabled={state==='saving'||state==='deleting'}/></label>}<label>Caption<textarea rows="2" value={draft?.caption||''} onChange={e=>setDraft({...draft,caption:e.target.value})} disabled={state==='saving'||state==='deleting'}/></label><label>Description<textarea rows="3" value={draft?.description||''} onChange={e=>setDraft({...draft,description:e.target.value})} disabled={state==='saving'||state==='deleting'}/></label><div className="kiwe-media-actions"><button type="submit" disabled={state==='saving'||state==='deleting'||!draft}>{state==='saving'?'Saving…':'Save file details'}</button>{item.kiwe_can_delete===true&&<TrashRecordAction endpoint={config.trashEndpoint} nonce={config.nonce} type="attachment" id={item.id} title={text(item.title?.raw||item.title?.rendered)} disabled={state!=='ready'||dirty} onTrashed={value=>{if(value?.status==='trash'){setItem(null);setDraft(null);setState('idle');setMessage(value.message);}else select(item.id);}}/>}</div></form></>}</section></div>{uploadOpen&&<MediaUpload config={config} onClose={()=>setUploadOpen(false)} onUploaded={items=>{if(items.length)select(items.at(-1).id);}}/>}<p role="status" className="kiwe-media-status">{message}</p>
  </main></div>;
}
