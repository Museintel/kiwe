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
  const inbox=Number.isSafeInteger(Number(config.inboxUnreadCount))?Math.max(0,Number(config.inboxUnreadCount)):0;
  const total=inbox;
  let websiteUrl=config.siteUrl;
  if(previewUrl&&typeof location!=='undefined'){
    try{const target=new URL(previewUrl,location.origin),website=new URL(config.siteUrl,location.origin);
      if(target.origin===website.origin&&['http:','https:'].includes(target.protocol))websiteUrl=target.href;
    }catch{}
  }
  return <div className="kiwe-context-chrome"><header className="kiwe-screen-header">
    <div className="kiwe-screen-brand"><WorkspaceBrand config={config} onNavigate={onNavigate}/></div>
    <nav className="kiwe-screen-header-actions" aria-label="Account and website">
      {websiteUrl&&<a href={websiteUrl} onClick={onNavigate}>View website ↗</a>}
      {config.notificationUrl&&<a className="kiwe-screen-bell" href={config.notificationUrl} onClick={onNavigate} aria-label={`Notifications, ${inbox} new update${inbox===1?'':'s'}`} title="Notifications"><svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/></svg>{total>0&&<b aria-hidden="true">{total}</b>}</a>}
      {config.profileUrl&&<a className="kiwe-screen-account" href={config.profileUrl} onClick={onNavigate}>Account</a>}
    </nav>
  </header><ContextProgress value={config.contextProgress} onNavigate={onNavigate}/></div>;
}
