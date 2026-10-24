import React,{useEffect,useRef,useState} from 'react';
import {navigationClient} from './navigation-client.mjs';
import './menu-placement-control.css';

/** One WordPress-backed visitor menu picker for every eligible record card. */
export function MenuGroupButtons({menus,source,busy,onToggle,onActivate}){
 const visibleCount=menus.filter(menu=>menu.usedByDsa).length;
 return <div className="kiwe-menu-group-choices">{menus.map(menu=>{
  const entry=menu.items.find(item=>item.object===source.object&&item.objectId===source.id);
  const full=menu.items.length>=menu.visibleLimit;
  if(!menu.usedByDsa)return <div className="kiwe-menu-group-inactive" key={menu.id}>
   <span><strong>{menu.name}</strong><small>WordPress menu · not in the visitor DSA menu</small></span>
   <button type="button" disabled={busy||visibleCount>=10} onClick={()=>onActivate(menu)}>{visibleCount>=10?'Visitor menu has 10 groups':'Show group in visitor menu'}</button>
  </div>;
  return <button key={menu.id} type="button" className="kiwe-card-switch" role="switch" aria-checked={!!entry}
   disabled={busy||(!entry&&(!source.public||full))} onClick={()=>onToggle(menu,source,!entry)}>
   <span>{menu.name}{!entry&&full?' · full':''}</span><span className="kiwe-card-switch-track" aria-hidden="true"/>
  </button>;
 })}</div>;
}

/** Fresh fingerprints are fetched only when a person opens this picker. */
export function MenuPlacementControl({kind,id,object,publicRecord=true,enabled=true,menus=[],endpoint,nonce,onChange,label='Show in menu'}){
 const [client]=useState(()=>navigationClient({endpoint,nonce}));
 const [open,setOpen]=useState(false),[current,setCurrent]=useState(menus),[groupExpected,setGroupExpected]=useState('');
 const [busy,setBusy]=useState(false),[message,setMessage]=useState(''),[name,setName]=useState(''),[adding,setAdding]=useState(false);
 const [loaded,setLoaded]=useState(false);
 const modal=useRef(null),launcher=useRef(null);
 const source={kind,id,object,public:publicRecord};
 useEffect(()=>{if(!open)setCurrent(menus);},[menus,open]);
 useEffect(()=>{const node=modal.current;if(open&&node&&!node.open)node.showModal();if(!open&&node?.open)node.close();},[open]);
 const included=current.some(menu=>menu.usedByDsa&&menu.items.some(item=>item.object===object&&item.objectId===id));
 async function reload(){const value=await client.list({source_kind:kind,source_id:id});setCurrent(value.menus);setGroupExpected(value.groupExpected);onChange?.(value.menus);return value;}
 async function show(){if(!enabled)return;setLoaded(false);setOpen(true);setBusy(true);setMessage('Opening groups…');try{await reload();setLoaded(true);setMessage('');}catch(error){setMessage(error.message||'Groups could not be loaded.');}finally{setBusy(false);}}
 function close(){if(busy)return;setOpen(false);setAdding(false);setName('');setMessage('');requestAnimationFrame(()=>launcher.current?.focus());}
 async function toggle(menu,selected,include){
  setBusy(true);setMessage(include?'Adding to group…':'Removing from group…');
  try{const item=menu.items.find(entry=>entry.object===selected.object&&entry.objectId===selected.id);
   await client.save(menu,{operation:include?'add':'remove',source_kind:selected.kind,source_id:selected.id,parent_id:0,item_id:item?.id||0,expected:menu.expected});
   await reload();setMessage(include?'Added to '+menu.name+'.':'Removed from '+menu.name+'.');
  }catch(error){setMessage(error.message||'The link change could not be confirmed.');}finally{setBusy(false);}
 }
 async function activate(menu){
  setBusy(true);setMessage('Showing group in visitor menu…');
  try{const ids=current.filter(item=>item.usedByDsa).map(item=>item.id),limits=Object.fromEntries(current.map(item=>[item.id,item.visibleLimit]));
   await client.groups({operation:'configure',ids:[...ids,menu.id],limits,expected:groupExpected});
   await reload();setMessage(menu.name+' is now in the visitor menu. Choose it to add this link.');
  }catch(error){setMessage(error.message||'The group could not be shown.');}finally{setBusy(false);}
 }
 async function create(event){
  event.preventDefault();if(!name.trim()||busy)return;setBusy(true);setMessage('Creating group…');
  try{const result=await client.groups({operation:'create',name:name.trim(),expected:groupExpected});
   const value=await reload();setName('');setAdding(false);const group=value.menus.find(menu=>menu.id===result.createdId);
   if(group&&publicRecord){await client.save(group,{operation:'add',source_kind:kind,source_id:id,parent_id:0,expected:group.expected});await reload();setMessage('New group created and link added.');}
   else setMessage('New group created.');
  }catch(error){setMessage(error.message||'The new group could not be confirmed.');}finally{setBusy(false);}
 }
 return <div className="kiwe-menu-placement">
  <button ref={launcher} type="button" className="kiwe-card-switch" role="switch" aria-checked={included} disabled={!enabled} onClick={show}><span>{label}</span><span className="kiwe-card-switch-track" aria-hidden="true"/></button>
  <dialog ref={modal} className="kiwe-menu-placement-dialog" aria-modal="true" aria-label={label} onCancel={event=>{event.preventDefault();close();}}>
   <div className="kiwe-menu-placement-head"><h2>{label}</h2><button type="button" disabled={busy} onClick={close}>Close</button></div>
   <div className="kiwe-menu-placement-body">
    <p>Choose where this link appears in the visitor DSA menu. A WordPress menu group must be shown there first.</p>
    {loaded&&<MenuGroupButtons menus={current} source={source} busy={busy} onToggle={toggle} onActivate={activate}/>}
    {loaded&&!current.length&&<p>No groups yet. Create one to place this link.</p>}
    {loaded&&(adding?<form onSubmit={create}><label>New group name<input autoFocus value={name} maxLength="80" required onChange={event=>setName(event.target.value)}/></label><button disabled={busy||!name.trim()}>Create group{publicRecord?' and add link':''}</button></form>:<button type="button" onClick={()=>setAdding(true)} disabled={busy||current.length>=100||current.filter(menu=>menu.usedByDsa).length>=10}>+ New group</button>)}
    <p role="status">{message}</p>
   </div>
  </dialog>
 </div>;
}
