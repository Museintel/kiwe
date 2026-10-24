import React,{useEffect,useRef,useState} from 'react';
import {CoreTabs} from './core-tabs.jsx';

export function Detail({detail,client,onSaved,onClose,seoUrl}){
 const [data,setData]=useState(detail),[name,setName]=useState(detail.term.name),[selected,setSelected]=useState([]),[target,setTarget]=useState(''),[search,setSearch]=useState(detail.targets_search),[moveOpen,setMoveOpen]=useState(false),[state,setState]=useState('ready'),[message,setMessage]=useState('');
 const pending=useRef(null),operation=useRef(false),heading=useRef(null),moveDialog=useRef(null),moveButton=useRef(null),dirty=name!==data.term.name,busy=state!=='ready',isTag=['post_tag','product_tag'].includes(data.term.taxonomy),label=isTag?'tag':'category';
 useEffect(()=>()=>pending.current?.abort(),[]);
 useEffect(()=>{heading.current?.focus();},[]);
 useEffect(()=>{const node=moveDialog.current;if(moveOpen&&node&&!node.open)node.showModal();if(!moveOpen&&node?.open)node.close();},[moveOpen]);
 useEffect(()=>{const guard=e=>{if(dirty||operation.current){e.preventDefault();e.returnValue='';}};addEventListener('beforeunload',guard);return()=>removeEventListener('beforeunload',guard);},[dirty]);
 const query=()=>({objectsPage:data.objects_page,targetsPage:data.targets_page,targetSearch:data.targets_search});
 async function load(nextQuery,{reset=false,clearItems=false,clearTarget=false}={}){
  pending.current?.abort();const controller=new AbortController();pending.current=controller;setState('loading');
  try{const next=await client.detail(data.term.taxonomy,data.term.id,nextQuery,controller.signal);if(controller.signal.aborted)return false;
   // Paging must not give an unsaved name a newer snapshot from an outside edit.
   const merged=dirty&&!reset?{...next,term:data.term}:next;setData(merged);if(!dirty||reset)setName(next.term.name);
   if(clearItems||reset)setSelected([]);if(clearTarget||reset)setTarget('');if(reset)setSearch(next.targets_search);
   onSaved(next);setState('ready');return true;
  }catch(error){if(!controller.signal.aborted){setState('uncertain');setMessage(error.message||'Could not load this page. Reload and review.');}return false;}
 }
 async function save(event){event.preventDefault();if(!name.trim()||busy||operation.current)return;operation.current=true;setState('saving');setMessage('Saving…');
  try{const next=await client.rename(data.term,name.trim());setData({...data,term:next});setName(next.name);onSaved({...data,term:next});setState('ready');setMessage('Name saved to your website.');}
  catch(error){setState('uncertain');setMessage(error.message||'The name could not be confirmed.');}finally{operation.current=false;}
 }
 async function move(){if(!target||!selected.length||busy||operation.current)return;
  operation.current=true;setState('saving');setMessage('Moving selected items…');
  try{const result=await client.move(data.term,Number(target),selected);const failed=result.outcomes.filter(item=>item.status!=='moved').map(item=>item.objectId);const confirmed=await load(query());if(!confirmed)return;setSelected(failed);setMessage(result.failed?`${result.moved} moved; ${result.failed} need review.`:`${result.moved} items moved. Their other ${isTag?'tags':'categories'} stayed assigned.`);if(!result.failed){setMoveOpen(false);setTarget('');requestAnimationFrame(()=>moveButton.current?.focus());}}
  catch(error){setState('uncertain');setMessage(error.message||'The move could not be confirmed.');}finally{operation.current=false;}
 }
 function pageItems(page){if(selected.length&&!confirm('Clear this page’s selection and open another page?'))return;load({...query(),objectsPage:page},{clearItems:true});}
 function closeMove(){if(state==='saving')return;setMoveOpen(false);setTarget('');setSearch(data.targets_search);requestAnimationFrame(()=>moveButton.current?.focus());}
 function toggleAll(){const ids=data.objects.map(item=>item.id);setSelected(ids.every(id=>selected.includes(id))?[]:ids);}
 function close(){if(dirty&&!confirm('Discard the unsaved name and close this '+label+'?'))return;pending.current?.abort();onClose();}
 function reload(){if(dirty&&!confirm('Discard the unsaved name and reload the saved '+label+'?'))return;load(query(),{reset:true});}
 const seoScope=data.term.objectType==='product'?'product':data.term.objectType==='service'?'service':'post';
 const seoHref=!isTag&&seoUrl?`${seoUrl}${seoUrl.includes('?')?'&':'?'}scope=${seoScope}&category=${encodeURIComponent(data.term.taxonomy+':'+data.term.id)}&taxonomy=${encodeURIComponent(data.term.taxonomy)}&term_id=${data.term.id}`:'';
 return <section className="native-tax-detail" aria-label={'Edit '+data.term.name}>
  <header><div><p className="native-tax-eyebrow">{data.term.objectType.toUpperCase()} {label.toUpperCase()}</p><h2 ref={heading} tabIndex="-1">{data.term.name}</h2></div><button type="button" onClick={close} disabled={state==='saving'}>Close</button></header>
  {seoHref&&<CoreTabs label="Category journey" active="organize" tabs={[{id:'organize',label:'Organize',panelId:'category-step-organize'},{id:'seo',label:'SEO',href:seoHref,disabled:state==='saving'}]} onChange={(next,event)=>{if(next==='seo'&&dirty&&!confirm('Discard the unsaved category name and open SEO?'))event.preventDefault();}}/>}
  <form id="category-step-organize" onSubmit={save}><label>{isTag?'Tag':'Category'} name<input value={name} maxLength={200} disabled={busy} onChange={e=>setName(e.target.value)}/></label><div className="native-tax-actions"><button disabled={!dirty||busy||!name.trim()}>Save name</button>{dirty&&!busy&&<button type="button" onClick={()=>setName(data.term.name)}>Undo edits</button>}</div></form>
  <p role={state==='uncertain'?'alert':'status'}>{state==='loading'?'Loading this page…':message}</p>{state==='uncertain'&&<button onClick={reload}>Reload and review</button>}
  <section className="native-tax-assigned"><div className="native-tax-assigned-head"><div><h3>Items with this {label}</h3><p>Choose the items to move. You can move up to 20 at a time.</p></div><div><button type="button" disabled={busy||!data.objects.length} onClick={toggleAll}>{data.objects.length&&data.objects.every(item=>selected.includes(item.id))?'Clear selection':'Select all on this page'}</button><button type="button" ref={moveButton} disabled={busy||!selected.length} onClick={()=>setMoveOpen(true)}>Move {selected.length||''} selected</button></div></div>
   {data.objects.map(item=><label className="native-tax-object" key={item.id}><input type="checkbox" checked={selected.includes(item.id)} disabled={busy} onChange={e=>setSelected(e.target.checked?[...selected,item.id]:selected.filter(id=>id!==item.id))}/><span><strong>{item.title||'Untitled'}</strong><small>{item.type} · {item.status}</small></span></label>)}
   {!data.objects.length&&<p>No editable items on this page.</p>}
   {(data.objects_page>1||data.objects_has_next)&&<nav className="native-tax-pagination" aria-label="Assigned item pages"><button disabled={busy||data.objects_page===1} onClick={()=>pageItems(data.objects_page-1)}>Previous items</button><span>Page {data.objects_page}</span><button disabled={busy||!data.objects_has_next} onClick={()=>pageItems(data.objects_page+1)}>Next items</button></nav>}
  </section>
  <dialog ref={moveDialog} className="native-tax-modal native-tax-move-dialog" aria-modal="true" aria-label="Move selected items" onCancel={event=>{event.preventDefault();closeMove();}}>
   <div className="native-tax-move-panel"><div className="native-tax-modal-head"><div><p className="native-tax-eyebrow">MOVE BETWEEN {isTag?'TAGS':'CATEGORIES'}</p><h2>Move {selected.length} selected</h2></div><button type="button" disabled={state==='saving'} onClick={closeMove}>Close</button></div>
    <p>Choose another {data.term.objectType} {label}. Other {isTag?'tags':'categories'} assigned to these items will stay.</p>
    <form onSubmit={e=>{e.preventDefault();if(!busy)load({...query(),targetSearch:search,targetsPage:1},{clearTarget:true});}}><label>Find a {label}<input type="search" maxLength={200} disabled={busy} value={search} onChange={e=>setSearch(e.target.value)}/></label><button disabled={busy}>Search</button></form>
    <div className="native-tax-targets" role="radiogroup" aria-label={'Destination '+label}>{data.targets.map(item=><label key={item.id} className={String(item.id)===target?'is-selected':''}><input type="radio" name="target-category" value={item.id} checked={String(item.id)===target} disabled={busy} onChange={()=>setTarget(String(item.id))}/>{item.name}</label>)}</div>
    {!data.targets.length&&<p>No other {isTag?'tags':'categories'} match this search.</p>}
    {(data.targets_page>1||data.targets_has_next)&&<nav className="native-tax-pagination" aria-label="Destination pages"><button disabled={busy||data.targets_page===1} onClick={()=>load({...query(),targetsPage:data.targets_page-1},{clearTarget:true})}>Previous</button><span>Page {data.targets_page}</span><button disabled={busy||!data.targets_has_next} onClick={()=>load({...query(),targetsPage:data.targets_page+1},{clearTarget:true})}>Next</button></nav>}
    <div className="native-tax-actions"><button type="button" disabled={busy||!target} onClick={move}>Move {selected.length} {selected.length===1?'item':'items'}</button></div><p role={state==='uncertain'?'alert':'status'}>{message}</p>
   </div>
  </dialog>
 </section>;
}
