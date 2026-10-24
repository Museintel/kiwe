import React,{useEffect,useState} from 'react';
import {createRoot} from 'react-dom/client';
import {WorkspaceHeader} from './workspace-chrome.jsx';
import {SeoEditorPanel} from './seo-editor.jsx';
import {SeoDefaults} from './seo-defaults.jsx';
import {client,History,validateHistory,validateList} from './seo-object-app-entry.jsx';

function startContext(){
 const params=new URLSearchParams(globalThis.location?.search||'');
 const scope=params.get('scope')||'';
 const id=params.get('item_id')||'';
 const taxonomy=params.get('taxonomy')||'';
 const termId=params.get('term_id')||'';
 const category=params.get('category')||'';
 if(['post','page','product','service','media'].includes(scope)&&/^[1-9][0-9]*$/.test(id))return {kind:'record',scope,id:Number(id)};
 if(/^[a-z0-9_]+$/.test(taxonomy)&&/^[1-9][0-9]*$/.test(termId))return {kind:'category',scope:scope==='category'?'category':scope||'post',objectScope:scope==='category'?'':scope||'post',taxonomy,termId:Number(termId),category:category||taxonomy+':'+termId};
 return null;
}

const cleanFilters=()=>({search:'',sort:'modified_desc',author:'',mediaContext:'',category:'',taxonomy:'',from:'',to:''});
function App(){
 const config=globalThis.KIWE_SEO_OBJECT_APP;
 const [request]=useState(()=>client(config));
 const [context,setContext]=useState(startContext);
 const [data,setData]=useState(null),[scope,setScope]=useState(''),[page,setPage]=useState(1);
 const [filters,setFilters]=useState(cleanFilters),[busy,setBusy]=useState(false),[error,setError]=useState(''),[message,setMessage]=useState('');
 const [history,setHistory]=useState(null),[historyOpen,setHistoryOpen]=useState(false),[selectedPreview,setSelectedPreview]=useState(null);
 const [searchInput,setSearchInput]=useState('');
 const [defaultsOpen,setDefaultsOpen]=useState(false);
 function paramsFor(nextScope,nextPage,nextFilters,nextContext){
  const params={scope:nextScope,page:nextPage,search:nextFilters.search,sort:nextScope==='category'&&nextFilters.sort==='modified_desc'?'title_asc':nextFilters.sort,author:nextScope==='post'?nextFilters.author:'',media_context:nextScope==='media'?nextFilters.mediaContext:'',category:['post','product','service'].includes(nextScope)?nextFilters.category:'',taxonomy:nextScope==='category'?nextFilters.taxonomy:'',from:nextScope==='category'?'':nextFilters.from,to:nextScope==='category'?'':nextFilters.to};
  if(nextContext?.kind==='record'&&nextContext.scope===nextScope)params.item_id=nextContext.id;
  if(nextContext?.kind==='category'&&nextScope==='category'){params.taxonomy=nextContext.taxonomy;params.term_id=nextContext.termId;}
  return params;
 }
 async function load(nextScope=scope,nextPage=page,nextFilters=filters,nextContext=null){
  setBusy(true);setError('');
  try{
   const result=validateList(await request('/objects',undefined,paramsFor(nextScope,nextPage,nextFilters,nextContext)));
   setData(result);setScope(result.scope);setPage(result.page);
  }catch(reason){setError(reason.message);}finally{setBusy(false);}
 }
 useEffect(()=>{
  const controller=new AbortController();
  const initial=cleanFilters();
  if(context?.kind==='category'&&context.scope!=='category')initial.category=context.category;
  setFilters(initial);setBusy(true);
  request('/objects',undefined,paramsFor(context?.scope||'',1,initial,context),controller.signal).then(value=>{
   if(controller.signal.aborted)return;
   const result=validateList(value);setData(result);setScope(result.scope);setPage(result.page);
  }).catch(reason=>{if(!controller.signal.aborted)setError(reason.message);}).finally(()=>{if(!controller.signal.aborted)setBusy(false);});
  return()=>controller.abort();
 },[]);
 function selectScope(next){
  const selected=cleanFilters();
  let carried=null;
  if(context?.kind==='category'){
   if(next==='category')carried={...context,scope:'category'};
   else if(next===context.objectScope){selected.category=context.category;carried={...context,scope:next};}
  }
  setContext(carried);setFilters(selected);setSearchInput('');setPage(1);setMessage('');
  load(next,1,selected,carried);
 }
 function apply(event){event.preventDefault();const next={...filters,search:searchInput.trim()};setFilters(next);setContext(null);setPage(1);setMessage('');load(scope,1,next,null);}
 function clear(){
  const next=cleanFilters();setFilters(next);setSearchInput('');setContext(null);setPage(1);setMessage('');
  load(scope,1,next,null);
 }
 async function loadHistory(){
  setBusy(true);setError('');
  try{setHistory(validateHistory(await request('/history')));setHistoryOpen(true);}catch(reason){setError(reason.message);}finally{setBusy(false);}
 }
 async function historyAction(path,body,confirmation){
  if(busy||confirmation&&!confirm(confirmation))return;
  setBusy(true);setError('');setMessage('');
  try{setHistory(validateHistory(await request(path,body)));setMessage('Library choice saved. Review the current progress below.');}
  catch(reason){setError(reason.message+' Refresh progress and inspect native content before retrying.');}
  finally{setBusy(false);}
 }
 const startHistory=next=>historyAction('/history/start',{scope:next},'Start a bounded SEO review for older '+next.replace('all_','')+'? Scheduled steps may use the shared AI allowance.');
 const controlHistory=action=>historyAction('/history/control',{jobId:history.job.id,action},action==='cancel'?'Cancel future processing? Existing suggestions stay available for review.':null);
 const reviewHistory=(item,decision)=>historyAction('/history/review',{jobId:history.job.id,proposalId:item.id,version:item.version,decision},decision==='accept'?'Apply these fields to the native record after reviewing them?':null);
 const preview=data?.items?.find(item=>selectedPreview?.scope===item.scope&&selectedPreview?.id===item.id)||data?.items?.find(item=>context?.kind==='record'&&context.scope===item.scope&&context.id===item.id)||data?.items?.find(item=>context?.kind==='category'&&item.scope==='term'&&item.taxonomy===context.taxonomy&&item.id===context.termId);
 const update=(key,value)=>setFilters(previous=>({...previous,[key]:value}));
 const sortOptions=scope==='category'?[['title_asc','Name A–Z'],['title_desc','Name Z–A'],['count_desc','Most items']]:[['modified_desc','Recently changed'],['modified_asc','Changed longest ago'],['date_desc','Newest first'],['date_asc','Oldest first'],['title_asc','Name A–Z']];
 return <div className="seo-shell kiwe-screen">
  <a className="seo-skip" href="#seo-main">Skip to SEO</a>
  <WorkspaceHeader config={config} previewUrl={preview?.viewUrl}/>
  {defaultsOpen&&<SeoDefaults request={request} onClose={()=>setDefaultsOpen(false)} onSaved={setMessage}/>}
  <main id="seo-main">
   <p className="seo-eyebrow">{config.siteName} · saved records</p><h1>SEO.</h1><p className="seo-title-sub">Help the right people find your work.</p>
   {config.goal&&<section className="seo-goal" aria-labelledby="seo-goal-title"><div><p className="seo-eyebrow">YOUR SEO GOAL</p><h2 id="seo-goal-title">{config.goal.label}</h2><p>SEO and AI suggestions follow the goal you save in Design Context.</p></div>{config.goalUrl&&<a href={config.goalUrl}>Review goal ↗</a>}</section>}
   {message&&<p role="status" className="seo-message">{message}</p>}{error&&<p role="alert" className="seo-error">{error}</p>}
   {data&&<>
    <div className="seo-tab-row"><nav className="seo-tabs" aria-label="Content types">{data.scopes.map(item=><button type="button" key={item.id} aria-current={scope===item.id?'page':undefined} disabled={busy} onClick={()=>selectScope(item.id)}>{item.label}</button>)}</nav><div className="seo-tab-action">{config.defaultsAllowed&&<button type="button" disabled={busy} onClick={()=>setDefaultsOpen(true)}>Website defaults</button>}{config.historyAllowed&&<button type="button" disabled={busy} onClick={()=>historyOpen?setHistoryOpen(false):loadHistory()}>{historyOpen?'Close older library':'Review older library'}</button>}</div></div>
    {historyOpen&&history&&<History value={history} busy={busy} onStart={startHistory} onControl={controlHistory} onReview={reviewHistory} onRefresh={loadHistory}/>}
    {context&&<p className="seo-message">Showing {context.kind==='record'?'this saved '+context.scope:'this category and its related work'}. Clear filters to browse everything.</p>}
    <form className="seo-filters" onSubmit={apply}>
     <label>Find by name<input type="search" value={searchInput} maxLength="100" onChange={event=>setSearchInput(event.target.value)} placeholder="Type a name"/></label>
     <label>Sort by<select value={scope==='category'&&filters.sort==='modified_desc'?'title_asc':filters.sort} onChange={event=>update('sort',event.target.value)}>{sortOptions.map(([value,label])=><option key={value} value={value}>{label}</option>)}</select></label>
     {scope==='post'&&<label>Author<select value={filters.author} onChange={event=>update('author',event.target.value)}><option value="">All available authors</option>{(data.authors||[]).map(person=><option key={person.id} value={person.id}>{person.name}</option>)}</select></label>}
     {['post','product','service'].includes(scope)&&<label>Category<select value={filters.category} onChange={event=>update('category',event.target.value)}><option value="">All categories</option>{(data.categories||[]).map(term=><option key={term.taxonomy+':'+term.id} value={term.taxonomy+':'+term.id}>{term.name}</option>)}</select></label>}
     {scope==='category'&&<label>Category type<select value={context?.kind==='category'?context.taxonomy:filters.taxonomy} onChange={event=>{setContext(null);update('taxonomy',event.target.value);}}><option value="">All category types</option>{(data.taxonomies||[]).map(taxonomy=><option key={taxonomy} value={taxonomy}>{taxonomy.replaceAll('_',' ')}</option>)}</select></label>}
     {scope==='media'&&<label>Used with<select value={filters.mediaContext} onChange={event=>update('mediaContext',event.target.value)}><option value="">All media</option><option value="post">Posts</option>{data.scopes.some(item=>item.id==='product')&&<option value="product">Products</option>}</select></label>}
     {scope!=='category'&&<><label>From date<input type="date" value={filters.from} onChange={event=>update('from',event.target.value)}/></label><label>To date<input type="date" value={filters.to} onChange={event=>update('to',event.target.value)}/></label></>}
     <div className="seo-filter-actions"><button disabled={busy}>Apply filters</button><button type="button" disabled={busy} onClick={clear}>Clear</button></div>
    </form>
    {!data.aiReady&&<p className="seo-paused">Kiwe AI is paused by website setup, preference, allowance or provider readiness. Manual SEO editing remains available.{data.aiSettingsUrl&&<> <a href={data.aiSettingsUrl}>Open AI assistance</a>.</>}</p>}
    <section className="seo-list" aria-busy={busy}>{!busy&&!data.items.length&&<p>No editable records match these filters.</p>}
     {data.items.map((item,index)=><article className={'seo-card seo-card--'+(index%4)} key={item.scope+':'+(item.taxonomy||'')+':'+item.id} onFocus={()=>setSelectedPreview({scope:item.scope,id:item.id})} onClick={()=>setSelectedPreview({scope:item.scope,id:item.id})}>
      <div className="seo-card-summary"><div className="seo-card-summary-copy"><div className="seo-card-top"><span>{item.scope==='term'?item.taxonomy.replaceAll('_',' ')+' category':item.scope}</span><span>{item.status}</span></div><h2>{item.label||'Untitled'}</h2><p>{item.scope==='term'?item.count+' items':(item.authorName&&item.scope==='post'?item.authorName+' · ':'')+(item.modified?new Date(item.modified).toLocaleDateString():'')}</p></div>{item.thumbnail&&<img className="kiwe-seo-thumbnail seo-card-thumbnail" src={item.thumbnail} alt="" loading="lazy"/>}</div>
      <SeoEditorPanel endpoint={config.endpoint} nonce={config.nonce} scope={item.scope} taxonomy={item.taxonomy||''} id={item.id} initial={item} aiReady={data.aiReady} showThumbnail={false} showGuidance={false} onSaved={saved=>setData(previous=>({...previous,items:previous.items.map(record=>record.scope===saved.scope&&record.id===saved.id&&record.taxonomy===saved.taxonomy?saved:record)}))}/>
     </article>)}
    </section>
    {(page>1||data.more)&&<nav className="seo-pages" aria-label="Result pages"><button type="button" disabled={busy||page<=1} onClick={()=>load(scope,page-1,filters,context)}>Previous</button><span>Page {page}</span><button type="button" disabled={busy||!data.more} onClick={()=>load(scope,page+1,filters,context)}>Next</button></nav>}
   </>}
   {busy&&!data&&<p role="status">Reading saved content…</p>}
  </main>
 </div>;
}

createRoot(document.getElementById('kiwe-seo-object-app')).render(<App/>);
