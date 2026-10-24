import React,{useEffect,useRef,useState} from 'react';

/** Bounded native WordPress revision trail; content and actions load only on review. */
export function TimelineView({data,query,onQuery,ready,request,onChanged}){
 const [search,setSearch]=useState(query.search||''),[from,setFrom]=useState(query.from||''),[to,setTo]=useState(query.to||'');
 const [version,setVersion]=useState(null),[busy,setBusy]=useState(false),[message,setMessage]=useState('');
 const dialog=useRef(null),launcher=useRef(null);
 const tabs=[['all','All'],...Object.entries(data.types||{}).map(([type,label])=>[type,label+'s'])];
 useEffect(()=>{const node=dialog.current;if(version&&node&&!node.open)node.showModal();if(!version&&node?.open)node.close();},[version]);
 function apply(event){event.preventDefault();onQuery({...query,search:search.trim(),from,to,page:1});}
 function clear(){setSearch('');setFrom('');setTo('');onQuery({page:1,type:'all',sort:'date_desc',search:'',author:'',from:'',to:''});}
 function close(){if(busy)return;setVersion(null);setMessage('');requestAnimationFrame(()=>launcher.current?.focus());}
 async function review(id,event){
  if(busy)return;launcher.current=event.currentTarget;setBusy(true);setMessage('');
  try{const value=await request('',undefined,{operation:'version',id});if(value?.id!==id||!Number.isSafeInteger(value.recordId)||!['post','page','product'].includes(value.type)&&typeof value.type!=='string'||typeof value.contentHtml!=='string'||typeof value.currentHtml!=='string'||typeof value.excerptHtml!=='string'||typeof value.currentExcerptHtml!=='string'||!/^[a-f0-9]{64}$/.test(value.expected))throw Error('This version could not be read.');setVersion(value);}
  catch(error){setMessage(error.message||'This version could not be opened.');}
  finally{setBusy(false);}
 }
 async function act(operation){
  if(!version||busy||!['restore','discard'].includes(operation))return;
  if(!confirm(operation==='restore'?'Restore this version’s title, writing and reader excerpt? The current saved version will remain in WordPress history.':'Discard this unsaved recovery copy?'))return;
  setBusy(true);setMessage('');
  try{const result=await request('',{operation,id:version.id,expected:version.expected,recordExpected:version.recordExpected});if(result?.ok!==true||result.operation!==operation)throw Error('WordPress did not confirm the version change.');setVersion(null);setMessage(operation==='restore'?'Version restored.':'Recovery copy discarded.');onChanged();requestAnimationFrame(()=>launcher.current?.focus());}
  catch(error){setMessage(error.message||'The change could not be confirmed. Reload and review.');}
  finally{setBusy(false);}
 }
 return <>
  <div className="kiwe-task-tabs continuity-timeline-tabs"><nav aria-label="Revision type">{tabs.map(([value,label])=><button key={value} type="button" aria-pressed={(query.type||'all')===value} disabled={!ready} onClick={()=>onQuery({...query,type:value,page:1})}>{label}</button>)}</nav></div>
  <form className="kiwe-task-filters continuity-timeline-filters" onSubmit={apply}>
   <label>Find a record<input type="search" maxLength={100} value={search} onChange={event=>setSearch(event.target.value)}/></label>
   <label>Sort<select value={query.sort||'date_desc'} disabled={!ready} onChange={event=>onQuery({...query,sort:event.target.value,page:1})}><option value="date_desc">Newest first</option><option value="date_asc">Oldest first</option></select></label>
   <label>Author<select value={query.author||''} disabled={!ready} onChange={event=>onQuery({...query,author:event.target.value,page:1})}><option value="">All available authors</option>{(data.authors||[]).map(person=><option key={person.id} value={person.id}>{person.name}</option>)}</select></label>
   <label>From<input type="date" value={from} onChange={event=>setFrom(event.target.value)}/></label>
   <label>To<input type="date" value={to} onChange={event=>setTo(event.target.value)}/></label>
   <div className="continuity-timeline-filter-actions"><button type="submit" disabled={!ready}>Apply filters</button><button type="button" disabled={!ready} onClick={clear}>Clear</button></div>
  </form>
  {message&&<p role={/could not|unavailable|did not/i.test(message)?'alert':'status'}>{message}</p>}
  <ol className="continuity-timeline-list">{data.items.map(item=><li key={item.id} className="continuity-timeline-item"><article><p className="continuity-timeline-kind">{item.typeLabel} · {item.recovery?'unsaved recovery':'saved version'}</p><h2>{item.title||'Untitled record'}</h2><dl><div><dt>What</dt><dd>{item.typeLabel} #{item.recordId}</dd></div><div><dt>Who</dt><dd>{item.author}</dd></div><div><dt>When</dt><dd><time dateTime={item.when}>{new Date(item.when).toLocaleString()}</time></dd></div></dl><button type="button" disabled={busy||!ready} onClick={event=>review(item.id,event)}>Review version</button></article></li>)}</ol>
  {version&&<dialog ref={dialog} className="continuity-version-dialog" aria-label="Review revision" onCancel={event=>{event.preventDefault();close();}}><div className="continuity-version-head"><div><p className="continuity-eyebrow">{version.typeLabel} · {version.recovery?'UNSAVED RECOVERY':'SAVED VERSION'}</p><h2>{version.title||'Untitled version'}</h2><small>{new Date(version.when).toLocaleString()}</small></div><button type="button" disabled={busy} onClick={close}>Close</button></div><div className="continuity-version-compare"><section><h3>This version</h3>{version.excerptHtml&&<><h4>Excerpt or short description</h4><div className="continuity-version-content" dangerouslySetInnerHTML={{__html:version.excerptHtml}}/></>}<div className="continuity-version-content" dangerouslySetInnerHTML={{__html:version.contentHtml||'<p>No writing in this version.</p>'}}/></section><section><h3>Current saved record</h3><h4>{version.currentTitle||'Untitled record'}</h4>{version.currentExcerptHtml&&<><h4>Excerpt or short description</h4><div className="continuity-version-content" dangerouslySetInnerHTML={{__html:version.currentExcerptHtml}}/></>}<div className="continuity-version-content" dangerouslySetInnerHTML={{__html:version.currentHtml||'<p>No saved writing.</p>'}}/></section></div><p>WordPress versions contain writing and excerpt. Restoring here changes title, writing and reader excerpt. Publication, terms, images and other settings remain with the current record. Saved history stays available.</p><div className="continuity-version-actions"><button type="button" disabled={busy} onClick={close}>Keep current</button>{version.canRestore&&<button type="button" disabled={busy} onClick={()=>act('restore')}>Restore this version</button>}{version.canDiscard&&<button type="button" disabled={busy} onClick={()=>act('discard')}>Discard recovery copy</button>}</div>{message&&<p role="alert">{message}</p>}</dialog>}
 </>;
}
