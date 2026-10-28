import React,{useEffect,useRef} from 'react';
import {WorkspaceBrand} from './workspace-brand.jsx';
import {ContextProgress} from './context-progress.jsx';
import './workspace-screen.css';

/** Shared exit contract for private tasks: an open editor is not itself a lock. */
export function useWorkspaceNavigationGuard(dirty=false,busy=false){
  const approved=useRef(false);
  useEffect(()=>{
    if(!dirty&&!busy)return;
    const guard=event=>{if(approved.current)return;event.preventDefault();event.returnValue='';};
    window.addEventListener('beforeunload',guard);
    return()=>window.removeEventListener('beforeunload',guard);
  },[dirty,busy]);
  return event=>{
    if(busy){event.preventDefault();return;}
    if(dirty&&!window.confirm('Leave this workspace and discard unsaved edits?')){event.preventDefault();return;}
    approved.current=true;
    setTimeout(()=>{approved.current=false;},0);
  };
}

/** One site-owned header and navigation grammar for every private React task. */
export function WorkspaceHeader({config,onNavigate,previewUrl}){
	const menu=useRef(null);
	useEffect(()=>{const close=event=>{if(!menu.current?.open)return;if(event.type==='keydown'){if(event.key!=='Escape')return;menu.current.open=false;menu.current.querySelector('summary')?.focus();}else if(!menu.current.contains(event.target))menu.current.open=false;};document.addEventListener('pointerdown',close);document.addEventListener('keydown',close);return()=>{document.removeEventListener('pointerdown',close);document.removeEventListener('keydown',close);};},[]);
  const inbox=Number.isSafeInteger(Number(config.inboxUnreadCount))?Math.max(0,Number(config.inboxUnreadCount)):0;
  const total=inbox;
  let websiteUrl=config.siteUrl;
  if(previewUrl&&typeof location!=='undefined'){
    try{const target=new URL(previewUrl,location.origin),website=new URL(config.siteUrl,location.origin);
      if(target.origin===website.origin&&['http:','https:'].includes(target.protocol))websiteUrl=target.href;
    }catch{}
  }
  const accountLinks=Array.isArray(config.accountLinks)?config.accountLinks:(config.profileUrl?[{label:'Account',url:config.profileUrl}]:[]);
  return <div className="kiwe-context-chrome"><header className="kiwe-workspace-header">
    <div className="kiwe-workspace-brand"><WorkspaceBrand config={config} onNavigate={onNavigate}/></div>
    <nav className="kiwe-workspace-header-actions" aria-label="Account and website">
      {websiteUrl&&<a className="kiwe-workspace-site-link" href={websiteUrl} onClick={onNavigate}><span>View website</span><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M14 3h7v7M21 3l-11 11M10 3H3v18h18v-7"/></svg></a>}
      {config.notificationUrl&&<a className="kiwe-workspace-bell" href={config.notificationUrl} onClick={onNavigate} aria-label={`Notifications, ${inbox} new update${inbox===1?'':'s'}`} title="Notifications"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/></svg>{total>0&&<b aria-hidden="true">{total}</b>}</a>}
      {accountLinks.length>0&&<details className="kiwe-account-menu" ref={menu}><summary aria-label="Account menu"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg><span>Account</span><span aria-hidden="true">⌄</span></summary><nav aria-label="Account settings">{accountLinks.map(link=><a key={link.url} href={link.url} onClick={onNavigate}>{link.label}</a>)}</nav></details>}
    </nav>
  </header>{config.contextProgress&&<ContextProgress value={config.contextProgress} onNavigate={onNavigate}/>}</div>;
}
