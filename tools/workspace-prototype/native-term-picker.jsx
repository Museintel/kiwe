import React,{useEffect,useRef,useState} from 'react';
import {tagGroupClient} from './tag-group-client.mjs';

const selectedFirst=(terms,ids)=>[...terms].sort((a,b)=>Number(ids.includes(b.id))-Number(ids.includes(a.id))||a.name.localeCompare(b.name));

/** Native terms and optional Kiwe folders. Bundle selection resolves native IDs on demand. */
export function TermPicker({title,taxonomy,initial=[],selected=[],onChange,canCreate=false,loadTerms,createTerm,tagGroupEndpoint='',nonce='',recordId=0,recordLabel='post',disabled=false}){
 const isTag=taxonomy==='post_tag'||taxonomy==='product_tag',kind=taxonomy==='post_tag'?'post':'product',limit=taxonomy==='post_tag'?20:100;
 const [groupsClient]=useState(()=>isTag&&tagGroupEndpoint&&nonce?tagGroupClient({tagGroupEndpoint,nonce}):null);
 const [search,setSearch]=useState(''),[options,setOptions]=useState(initial),[message,setMessage]=useState('');
 const [createOpen,setCreateOpen]=useState(false),[newName,setNewName]=useState(''),[creating,setCreating]=useState(false);
 const [groups,setGroups]=useState([]),[groupId,setGroupId]=useState(0),[newGroupId,setNewGroupId]=useState(0);
 const [groupOpen,setGroupOpen]=useState(false),[groupName,setGroupName]=useState(''),[groupBusy,setGroupBusy]=useState(false);
 const singular=taxonomy==='category'||taxonomy==='product_cat'?'category':'tag';
 const dialog=useRef(null),groupDialog=useRef(null),launcher=useRef(null),groupLauncher=useRef(null),nameField=useRef(null),groupNameField=useRef(null);
 useEffect(()=>{const controller=new AbortController(),timer=setTimeout(()=>{loadTerms(taxonomy,search,recordId,controller.signal).then(items=>{if(controller.signal.aborted)return;setOptions(previous=>{const retained=previous.filter(item=>selected.includes(item.id));return [...new Map([...retained,...items].map(item=>[item.id,item])).values()];});setMessage('');}).catch(error=>{if(!controller.signal.aborted)setMessage(error.message||'Terms could not be searched.');});},search?250:0);return()=>{clearTimeout(timer);controller.abort();};},[taxonomy,search,recordId,loadTerms]);
 useEffect(()=>{if(!groupsClient)return;const controller=new AbortController();groupsClient.list(kind,controller.signal).then(setGroups).catch(error=>{if(!controller.signal.aborted)setMessage(error.message||'Tag categories could not be loaded.');});return()=>controller.abort();},[groupsClient,kind]);
 useEffect(()=>{const node=dialog.current;if(createOpen&&node&&!node.open){node.showModal();requestAnimationFrame(()=>nameField.current?.focus());}if(!createOpen&&node?.open)node.close();},[createOpen]);
 useEffect(()=>{const node=groupDialog.current;if(groupOpen&&node&&!node.open){node.showModal();requestAnimationFrame(()=>groupNameField.current?.focus());}if(!groupOpen&&node?.open)node.close();},[groupOpen]);
 function close(force=false){if(creating&&!force)return;setCreateOpen(false);setNewName('');requestAnimationFrame(()=>launcher.current?.focus());}
 function closeGroup(force=false){if(groupBusy&&!force)return;setGroupOpen(false);setGroupName('');requestAnimationFrame(()=>groupLauncher.current?.focus());}
 async function create(){
  const name=newName.trim();if(!name||creating||disabled||!createTerm)return;
  setCreating(true);setMessage('');
  try{
   const term=await createTerm(taxonomy,name);
   setOptions(previous=>[term,...previous.filter(item=>item.id!==term.id)]);
   if(!selected.includes(term.id))onChange([...selected,term.id]);
   let grouped=true;
   if(groupsClient&&newGroupId){try{const receipt=await groupsClient.move(kind,0,newGroupId,[term.id]);grouped=receipt.moved===1&&receipt.failed===0;}catch{grouped=false;}}
   setMessage(grouped?`${term.name} was created and selected${newGroupId?' in '+(groups.find(group=>group.id===newGroupId)?.name||'the chosen category'):''}.`:`${term.name} was selected, but its category could not be confirmed. Check its category in Tags before saving.`);
   setCreating(false);close(true);
  }catch(error){setCreating(false);setMessage(error.message||`The ${singular} could not be created.`);}
 }
 async function createGroup(event){
  event.preventDefault();event.stopPropagation();if(!groupsClient||!groupName.trim()||groupBusy)return;
  setGroupBusy(true);setMessage('');
  try{const group=await groupsClient.create(kind,groupName.trim());setGroups(previous=>[...previous,group]);setGroupId(group.id);setNewGroupId(group.id);setMessage(`${group.name} is ready. Choose its tags or add a new tag to it.`);setGroupBusy(false);closeGroup(true);}
  catch(error){setGroupBusy(false);setMessage(error.message||'The category could not be created.');}
 }
 async function selectGroup(){
  if(!groupsClient||!groupId||disabled||groupBusy)return;
  setGroupBusy(true);setMessage('');
  try{const ids=await groupsClient.bundle(kind,groupId),combined=[...new Set([...selected,...ids])];if(combined.length>limit)throw Error(`This ${recordLabel} can have at most ${limit} tags. Select tags individually from this category.`);onChange(combined);setMessage(ids.length?`${ids.length} tags from ${groups.find(group=>group.id===groupId)?.name||'the category'} are selected. Save ${recordLabel} to assign them.`:'This category has no tags yet.');}
  catch(error){setMessage(error.message||'The category could not be selected.');}
  finally{setGroupBusy(false);}
 }
 function chooseTag(term,checked){if(checked&&selected.length>=limit){setMessage(`This ${recordLabel} can have at most ${limit} tags.`);return;}onChange(checked?[...selected,term.id]:selected.filter(id=>id!==term.id));}
 return <section className="native-term-group">
  <div className="native-term-heading"><h3>{title}</h3>{canCreate&&<button type="button" ref={launcher} className="native-term-new" disabled={disabled} onClick={()=>{setNewGroupId(groupId);setCreateOpen(true);}}>+ New</button>}</div>
  {groupsClient&&<div className="native-term-bundles"><label>Tags in category<select aria-label={`Tag category for ${recordLabel}`} value={groupId} disabled={disabled||groupBusy} onChange={event=>setGroupId(Number(event.target.value))}><option value="0">Choose a tag category</option>{groups.map(group=><option key={group.id} value={group.id}>{group.name}</option>)}</select></label><button type="button" disabled={disabled||groupBusy||!groupId} onClick={selectGroup}>Select all tags in category</button>{canCreate&&<button type="button" ref={groupLauncher} disabled={disabled||groupBusy} onClick={()=>setGroupOpen(true)}>+ New category</button>}</div>}
  <label className="native-term-search">Find existing {title.toLowerCase()}<input type="search" value={search} maxLength={80} disabled={disabled} onKeyDown={event=>{if(event.key==='Enter')event.preventDefault();}} onChange={event=>setSearch(event.target.value)}/></label>
  {message&&<p role={/could not|at most|could not be confirmed/i.test(message)?'alert':'status'}>{message}</p>}
  <fieldset><legend>Choose {title.toLowerCase()} · {selected.length} selected</legend>{selectedFirst(options,selected).map(term=><label key={term.id} className="native-category-choice"><input type="checkbox" checked={selected.includes(term.id)} disabled={disabled} onChange={event=>isTag?chooseTag(term,event.target.checked):onChange(event.target.checked?[...selected,term.id]:selected.filter(id=>id!==term.id))}/>{term.name}</label>)}{!options.length&&<small>No matching {title.toLowerCase()} found.</small>}</fieldset>
  {createOpen&&<dialog ref={dialog} className="native-term-dialog" aria-label={'New '+singular} onCancel={event=>{event.preventDefault();close();}}><div className="native-term-dialog-head"><h3>New {singular}</h3><button type="button" disabled={creating} onClick={()=>close()}>Close</button></div><label>Name<input ref={nameField} value={newName} maxLength={80} disabled={creating} onChange={event=>setNewName(event.target.value)} onKeyDown={event=>{if(event.key==='Enter'){event.preventDefault();create();}}}/></label>{groupsClient&&<label>Tags in category<select value={newGroupId} disabled={creating} onChange={event=>setNewGroupId(Number(event.target.value))}><option value="0">Ungrouped</option>{groups.map(group=><option key={group.id} value={group.id}>{group.name}</option>)}</select></label>}<p>The new WordPress {singular} will be selected for this {recordLabel}. Save the {recordLabel} to assign it.</p><button type="button" disabled={creating||!newName.trim()||(isTag&&selected.length>=limit)} onClick={create}>{creating?'Creating…':'Create and select'}</button></dialog>}
  {groupOpen&&<dialog ref={groupDialog} className="native-term-dialog" aria-label="New tag category" onCancel={event=>{event.preventDefault();closeGroup();}}><div><div className="native-term-dialog-head"><h3>New tag category</h3><button type="button" disabled={groupBusy} onClick={()=>closeGroup()}>Close</button></div><label>Name<input ref={groupNameField} value={groupName} maxLength={100} disabled={groupBusy} onChange={event=>setGroupName(event.target.value)} onKeyDown={event=>{if(event.key==='Enter'){event.preventDefault();createGroup(event);}}}/></label><p>This organizes existing WordPress tags. It does not change a post or product until you select its tags and save.</p><button type="button" disabled={groupBusy||!groupName.trim()} onClick={createGroup}>{groupBusy?'Creating…':'Create category'}</button></div></dialog>}
 </section>;
}
