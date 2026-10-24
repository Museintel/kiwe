import React,{useEffect,useRef,useState} from 'react';
import {navigationClient} from './navigation-client.mjs';
import {WorkspaceHeader} from './workspace-chrome.jsx';
import './native-navigation.css';

/** WordPress nav menus remain the only visitor-link inventory. */
export function NativeNavigation({config,embedded=false}){
 const [client]=useState(()=>navigationClient(config));
 const [data,setData]=useState(null),[state,setState]=useState('loading'),[message,setMessage]=useState('');
 const [dialog,setDialog]=useState(''),[name,setName]=useState(''),[search,setSearch]=useState(''),[page,setPage]=useState(1);
 const [menuSearch,setMenuSearch]=useState(''),[menuApplied,setMenuApplied]=useState(''),[menuVisibility,setMenuVisibility]=useState('all');
 const [target,setTarget]=useState(null),[limitEdit,setLimitEdit]=useState({}),[contentsEdit,setContentsEdit]=useState({}),[searchBusy,setSearchBusy]=useState(false);
 const [returnToLinks,setReturnToLinks]=useState(false),[pendingRemove,setPendingRemove]=useState('');
 const [contextMenuId,setContextMenuId]=useState(null),[editingMenuId,setEditingMenuId]=useState(null);
 const modal=useRef(null),searchRun=useRef(0);

 async function refresh(query={},silent=false){
  if(!silent)setState('loading');
  try{const next=await client.list(query);setData(next);setState('ready');return next;}
  catch(error){setState('error');setMessage(error.message||'Frontend navigation could not be loaded.');return null;}
 }
 useEffect(()=>{
  const controller=new AbortController();
  client.list({source_kind:config.sourceKind,source_id:config.sourceId},controller.signal).then(value=>{
   if(controller.signal.aborted)return;
   setData(value);setState('ready');
   if(config.sourceId&&config.sourceKind){setTarget({kind:config.sourceKind,id:Number(config.sourceId)});setDialog('links');}
  }).catch(error=>{if(!controller.signal.aborted){setState('error');setMessage(error.message||'Frontend navigation could not be loaded.');}});
  return()=>controller.abort();
 },[client]);
 useEffect(()=>{const node=modal.current;if(dialog&&node&&!node.open)node.showModal();if(!dialog&&node?.open)node.close();},[dialog]);
 useEffect(()=>{
  if(dialog!=='links'||target||search.trim().length<3){setSearchBusy(false);return;}
  const run=++searchRun.current;
  const timer=setTimeout(()=>client.list({search:search.trim(),page}).then(value=>{
   if(run===searchRun.current){setData(value);setSearchBusy(false);}
  }).catch(error=>{if(run===searchRun.current){setMessage(error.message||'Search could not be completed.');setSearchBusy(false);}}),350);
  return()=>clearTimeout(timer);
 },[search,page,dialog,target,client]);

 const menus=data?.menus||[],visible=menus.filter(menu=>menu.usedByDsa),shownMenus=menus.filter(menu=>(menuVisibility==='all'||(menuVisibility==='shown')===menu.usedByDsa)&&(!menuApplied||`${menu.name} ${menu.items.map(item=>item.label||item.title).join(' ')}`.toLocaleLowerCase().includes(menuApplied.toLocaleLowerCase())));
 const currentQuery=()=>target?{source_kind:target.kind,source_id:target.id}:search.trim().length>=3?{search:search.trim(),page}:{};
 function close(){if(state==='saving')return;setDialog('');setTarget(null);setContextMenuId(null);setEditingMenuId(null);setSearch('');setName('');setPage(1);setMessage('');setReturnToLinks(false);setSearchBusy(false);}
 function openLinks(selected=null,menuId=null){
  const kind=selected&&!(data?.kinds?.[selected.kind])&&data?.kinds?.service?'service':selected?.kind;
  const focus=selected?{...selected,kind}:null;
  setTarget(focus);setContextMenuId(menuId);setSearch('');setPage(1);setDialog('links');setMessage('');setPendingRemove('');
  if(focus)client.list({source_kind:focus.kind,source_id:focus.id}).then(setData).catch(error=>setMessage(error.message||'The destination could not be loaded.'));
 }
 async function groupChange(body){
  setState('saving');setMessage('Saving navigation…');
  try{const saved=await client.groups({...body,expected:data.groupExpected});
   if(!await refresh(currentQuery(),true))return null;
   setMessage('Frontend navigation saved.');
   if(body.operation==='create'){setName('');setDialog(returnToLinks?'links':'');setReturnToLinks(false);}
   if(body.operation==='rename'||body.operation==='delete'){setDialog('');setName('');setEditingMenuId(null);}
   return saved;
  }catch(error){setState('ready');setMessage(error.message||'The menu category change could not be confirmed.');return null;}
 }
 async function configure(menu,changes){
  const ids=visible.map(item=>item.id),limits=Object.fromEntries(menus.map(item=>[item.id,item.visibleLimit]));
  if(changes.visible!==undefined){const current=ids.indexOf(menu.id);if(changes.visible&&current<0)ids.push(menu.id);if(!changes.visible&&current>=0)ids.splice(current,1);}
  if(changes.move){const current=ids.indexOf(menu.id),next=current+changes.move;if(next<0||next>=ids.length)return;[ids[current],ids[next]]=[ids[next],ids[current]];}
  if(changes.limit!==undefined)limits[menu.id]=changes.limit;
  await groupChange({operation:'configure',ids,limits});
 }
 async function linkChange(menu,source,include){
  setState('saving');setMessage(include?'Adding link…':'Removing link…');
  try{const item=menu.items.find(entry=>entry.object===source.object&&entry.objectId===source.id);
   await client.save(menu,{operation:include?'add':'remove',source_kind:source.kind,source_id:source.id,parent_id:0,item_id:item?.id||0,expected:menu.expected});
   await refresh(currentQuery(),true);setMessage(include?'Link added to '+menu.name+'.':'Link removed from '+menu.name+'.');
  }catch(error){setState('ready');setMessage(error.message||'The link change could not be confirmed.');}
 }
 async function removeLink(menu,item){
  setState('saving');setMessage('Removing link…');
  try{await client.save(menu,{operation:'remove',item_id:item.id,expected:menu.expected});
   await refresh({},true);setPendingRemove('');setMessage('Link removed from '+menu.name+'.');
  }catch(error){setState('ready');setMessage(error.message||'The link could not be removed.');}
 }
 async function labelChange(menu,item,label){
  setState('saving');setMessage('Saving label…');
  try{await client.save(menu,{operation:'label',item_id:item.id,label,expected:menu.expected});
   await refresh({},true);setMessage('Link label saved.');
  }catch(error){setState('ready');setMessage(error.message||'The label could not be saved.');}
 }
 const matches=[];
 if(dialog==='links'&&data&&!searchBusy)for(const group of Object.values(data.sources||{}))for(const source of group.items||[])if(!target||(source.kind===target.kind&&source.id===target.id))matches.push(source);

 const ContentTag=embedded?'section':'main',TitleTag=embedded?'h2':'h1';
 return <div className={'native-nav-shell kiwe-screen'+(embedded?' native-nav-embedded':'')}>
  {!embedded&&<><a className="native-nav-skip" href="#navigation-main">Skip to Frontend navigation</a><WorkspaceHeader config={config}/></>}
  <ContentTag id="navigation-main" className="native-nav-content">
    <p className="native-nav-eyebrow">MAKE IT EASY TO EXPLORE</p><TitleTag>{embedded?'Menu categories.':'Navigation.'}</TitleTag>
    <p>Organize the menu categories and links visitors use to explore your website. Each menu category remains a native WordPress menu.</p>
    <div className="kiwe-task-tabs native-nav-tabs"><nav aria-label="Navigation views"><button type="button" aria-pressed="true">Menu categories</button></nav><button type="button" className="kiwe-new-action" disabled={state==='saving'||menus.length>=100||visible.length>=10} onClick={()=>{setMessage('');setReturnToLinks(false);setDialog('group');}}>+ New menu category</button></div>
    <div className="kiwe-task-filters native-nav-filters"><form role="search" onSubmit={event=>{event.preventDefault();setMenuApplied(menuSearch.trim());}}><label>Find a menu category<input type="search" maxLength="80" value={menuSearch} onChange={event=>setMenuSearch(event.target.value)}/></label><button type="submit" disabled={state==='saving'}>Search</button></form><label>Show<select value={menuVisibility} disabled={state==='saving'} onChange={event=>setMenuVisibility(event.target.value)}><option value="all">All menu categories</option><option value="shown">Shown to visitors</option><option value="hidden">Not shown</option></select></label><span className="native-nav-count">{visible.length} {visible.length===1?'category':'categories'} shown to visitors</span></div>
   {state==='loading'&&!data&&<p role="status">Opening navigation…</p>}
   {state==='error'&&<p role="alert">{message}</p>}{!data&&state==='error'&&<button onClick={()=>refresh()}>Try again</button>}
   {data&&<>
     <div className="native-nav-groups">{shownMenus.map(menu=><section key={menu.id} className={'native-nav-group '+(menu.usedByDsa?'is-visible':'is-hidden')}>
     <div className="native-nav-group-heading"><div><small>MENU CATEGORY</small><div className="native-nav-group-title"><h2>{menu.name}</h2><button type="button" className="native-nav-rename" aria-label={'Rename menu category '+menu.name} title={'Rename '+menu.name} disabled={state==='saving'} onClick={()=>{setEditingMenuId(menu.id);setName(menu.name);setMessage('');setDialog('rename');}}>✎</button></div>
      <p>{menu.items.length} {menu.items.length===1?'link':'links'} · {menu.visibleLimit} link limit{menu.themeLocations.length?' · also used in '+menu.themeLocations.join(', '):''}</p>
     </div><button type="button" className="native-nav-visibility" role="switch" aria-checked={menu.usedByDsa}
      disabled={state==='saving'||(!menu.usedByDsa&&visible.length>=10)} onClick={()=>configure(menu,{visible:!menu.usedByDsa})}>Show in Dock menu <span aria-hidden="true"/></button></div>
     <form className="native-nav-page-contents" onSubmit={async event=>{event.preventDefault();const choice=contentsEdit[menu.id]||menu.pageContents;if(await groupChange({operation:'contents',id:menu.id,enabled:choice.enabled,title:choice.title})){setContentsEdit(old=>{const next={...old};delete next[menu.id];return next;});}}}>
      <label><input type="checkbox" disabled={state==='saving'} checked={(contentsEdit[menu.id]||menu.pageContents).enabled} onChange={event=>setContentsEdit(old=>({...old,[menu.id]:{...(old[menu.id]||menu.pageContents),enabled:event.target.checked}}))}/>Show page contents</label>
      {(contentsEdit[menu.id]||menu.pageContents).enabled&&<label>Page contents title<input maxLength={160} required disabled={state==='saving'} value={(contentsEdit[menu.id]||menu.pageContents).title} onChange={event=>setContentsEdit(old=>({...old,[menu.id]:{...(old[menu.id]||menu.pageContents),title:event.target.value}}))}/></label>}
      <small>Adds a guide to the current page’s headings inside this menu group when it is shown in the Dock.</small>
      {contentsEdit[menu.id]&&JSON.stringify(contentsEdit[menu.id])!==JSON.stringify(menu.pageContents)&&<button disabled={state==='saving'}>Save page contents</button>}
     </form>
     <div className="native-nav-group-actions">
      {menu.usedByDsa&&<><button type="button" disabled={state==='saving'||menu.position===0} onClick={()=>configure(menu,{move:-1})}>Move up</button><button type="button" disabled={state==='saving'||menu.position===visible.length-1} onClick={()=>configure(menu,{move:1})}>Move down</button></>}
      <label>Maximum links <input type="number" min="1" max="200" value={limitEdit[menu.id]??menu.visibleLimit}
       onChange={event=>setLimitEdit(old=>({...old,[menu.id]:event.target.value}))}
       onBlur={()=>{const raw=limitEdit[menu.id];if(raw===undefined)return;const limit=Number(raw);if(Number.isInteger(limit)&&limit>=1&&limit<=200&&limit!==menu.visibleLimit)configure(menu,{limit});setLimitEdit(old=>{const next={...old};delete next[menu.id];return next;});}}/></label>
      <button type="button" onClick={()=>openLinks(null,menu.id)} disabled={state==='saving'||menu.items.length>=menu.visibleLimit}>+ New link</button>
      <button type="button" className="native-nav-delete" disabled={state==='saving'||!!menu.themeLocations.length} title={menu.themeLocations.length?'Move this menu category out of the website theme before deleting it':'Delete this menu category'} onClick={()=>{setEditingMenuId(menu.id);setMessage('');setDialog('delete');}}>Delete menu category</button>
     </div>
     <ul className="native-nav-links">{menu.items.map(item=>{
      const key=menu.id+':'+item.id,children=menu.items.some(child=>child.parentId===item.id);
      return <li key={item.id}><div><strong>{item.label||item.title}</strong><small>{item.object==='custom'?'Custom link':item.object.replaceAll('_',' ')}{item.parentId?' · nested link':''}</small></div>
       <div><a href={item.url} target="_blank" rel="noreferrer">View ↗</a>
        {item.object!=='custom'&&<button type="button" disabled={state==='saving'} onClick={()=>openLinks({kind:item.object,id:item.objectId})}>Menu categories</button>}
        <details><summary>Label</summary><form onSubmit={event=>{event.preventDefault();labelChange(menu,item,event.currentTarget.elements.label.value);}}><input name="label" defaultValue={item.label} placeholder="Follow source title" maxLength="200"/><button disabled={state==='saving'}>Save</button></form></details>
        {pendingRemove===key?<span className="native-nav-remove-confirm">Remove this link? <button type="button" onClick={()=>setPendingRemove('')}>Cancel</button><button type="button" disabled={state==='saving'} onClick={()=>removeLink(menu,item)}>Remove</button></span>
         :<button type="button" disabled={state==='saving'||children} title={children?'Move nested links first':'Remove this menu link'} onClick={()=>setPendingRemove(key)}>Remove</button>}
        {children&&<small>Move nested links before removing</small>}
       </div></li>;
     })}</ul>
     {!menu.items.length&&<p className="native-nav-empty">No links yet. Add a page, post, product, service or category.</p>}
     {menu.items.length>=menu.visibleLimit&&<p className="native-nav-cap">At this menu category’s link limit. Raise it deliberately or choose another category.</p>}
    </section>)}</div>
     {!menus.length&&<p>No menu categories yet. Create the first one above.</p>}{menus.length>0&&!shownMenus.length&&<p>No menu categories match this view.</p>}
    <p role="status" className="native-nav-message">{message}</p>
   </>}
  </ContentTag>
  <dialog ref={modal} className="native-nav-dialog" aria-modal="true" aria-label={dialog==='group'?'New menu category':dialog==='rename'?'Rename menu category':dialog==='delete'?'Delete menu category':'Add a link'} onCancel={event=>{event.preventDefault();close();}}>
   <div className="native-nav-dialog-head"><h2>{dialog==='group'?'New menu category':dialog==='rename'?'Rename menu category':dialog==='delete'?'Delete menu category':target?'Menu placement':contextMenuId?'Add a link to '+(menus.find(menu=>menu.id===contextMenuId)?.name||'menu category'):'Add a link'}</h2><button type="button" disabled={state==='saving'} onClick={close}>Close</button></div>
   {dialog==='rename'?<form className="native-nav-dialog-body" onSubmit={event=>{event.preventDefault();if(name.trim())groupChange({operation:'rename',id:editingMenuId,name:name.trim()});}}>
    <label>Menu category name<input autoFocus value={name} maxLength="80" required onChange={event=>setName(event.target.value)}/></label>
    <div className="native-nav-dialog-actions"><button type="button" onClick={close}>Cancel</button><button disabled={state==='saving'||!name.trim()}>Save name</button></div><p role="status">{message}</p>
   </form>:dialog==='delete'?<div className="native-nav-dialog-body"><p>Delete “{menus.find(menu=>menu.id===editingMenuId)?.name}” and its {menus.find(menu=>menu.id===editingMenuId)?.items.length||0} links from WordPress? The linked pages, posts and categories stay on your website. This cannot be undone.</p>
    <div className="native-nav-dialog-actions"><button type="button" onClick={close}>Cancel</button><button type="button" className="native-nav-delete-confirm" disabled={state==='saving'} onClick={()=>{const menu=menus.find(item=>item.id===editingMenuId);if(menu)groupChange({operation:'delete',id:menu.id,menu_expected:menu.expected});}}>Delete menu category</button></div><p role="status">{message}</p>
   </div>:dialog==='group'?<form className="native-nav-dialog-body" onSubmit={event=>{event.preventDefault();if(name.trim())groupChange({operation:'create',name:name.trim()});}}>
    <p>Give this part of your visitor menu a clear name. It appears after your current menu categories and starts visible in the DSA menu.</p>
    <label>Menu category name<input autoFocus value={name} maxLength="80" required onChange={event=>setName(event.target.value)}/></label>
    <div className="native-nav-dialog-actions"><button disabled={state==='saving'||!name.trim()}>Create menu category</button></div><p role="status">{message}</p>
   </form>:<div className="native-nav-dialog-body">
    <p>{contextMenuId?'Find a page, post, product, service or category to add here.':'Find a page, post, product, service or category. Choose its menu category.'}</p>
    {!contextMenuId&&<button type="button" disabled={state==='saving'||menus.length>=100||visible.length>=10} onClick={()=>{setReturnToLinks(true);setDialog('group');setMessage('');}}>+ New menu category</button>}
    {!target&&<label>Search by name<input autoFocus type="search" value={search} onChange={event=>{setPage(1);setSearchBusy(true);setSearch(event.target.value);}} placeholder="Start typing a name"/></label>}
    {target&&<strong>{matches[0]?.title||'Selected destination'}</strong>}
    {searchBusy&&<p role="status">Searching destinations…</p>}
    {(target||search.trim().length>=3)&&!searchBusy&&<div className="native-nav-results">{matches.map(source=><article key={source.kind+':'+source.id}>
     <strong>{source.title}</strong><small>{data.kinds[source.kind]||source.kind}</small>
     {menus.filter(menu=>!contextMenuId||menu.id===contextMenuId).map(menu=>{const included=menu.items.some(item=>item.object===source.object&&item.objectId===source.id),full=menu.items.length>=menu.visibleLimit;
      if(contextMenuId)return <button key={menu.id} type="button" disabled={state==='saving'||included||full} onClick={()=>linkChange(menu,source,true)}>{included?'Added':full?'Category full':'Add'}</button>;
      return menu.usedByDsa?<button key={menu.id} type="button" role="switch" aria-checked={included} disabled={state==='saving'||(!included&&full)} onClick={()=>linkChange(menu,source,!included)}>{included?'✓ ':'+ '}{menu.name}{!included&&full?' · full':''}</button>
       :<button key={menu.id} type="button" disabled={state==='saving'||visible.length>=10} onClick={()=>configure(menu,{visible:true})}>Show {menu.name} in visitor menu</button>;
     })}
    </article>)}{!matches.length&&<p>No matching destinations on this page.</p>}</div>}
    {!target&&search.trim().length>=3&&(page>1||Object.values(data.sources||{}).some(group=>group.has_next))&&<div className="native-nav-dialog-actions"><button type="button" disabled={page<=1||searchBusy} onClick={()=>{setSearchBusy(true);setPage(page-1);}}>Previous</button><span>Page {page}</span><button type="button" disabled={searchBusy||!Object.values(data.sources||{}).some(group=>group.has_next)} onClick={()=>{setSearchBusy(true);setPage(page+1);}}>Next</button></div>}
    <p role="status">{message}</p>
   </div>}
  </dialog>
 </div>;
}
