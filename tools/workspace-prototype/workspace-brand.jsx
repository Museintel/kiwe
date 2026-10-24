import React from 'react';
import './workspace-brand.css';

/** The same native site identity projection is used by each private React task. */
export function WorkspaceBrand({config,onNavigate,showEdit=true}){
  const name=config.siteName||'Your website';
  return <>
    <a className="workspace-site-brand" href={config.home} aria-label={name+' home'} onClick={onNavigate}>
      {config.siteLogo?<img src={config.siteLogo} alt=""/>:<strong>{name}</strong>}
    </a>
    {(config.siteTagline||showEdit&&(config.contextUrl||config.identityUrl))&&<div className="workspace-brand-meta">
      {config.siteTagline&&<span className="workspace-site-tagline" title={config.siteTagline}>{config.siteTagline}</span>}
      {showEdit&&(config.contextUrl||config.identityUrl)&&<a className="workspace-site-edit" href={config.contextUrl||config.identityUrl} aria-label="Edit website identity" title="Website & Design Context" onClick={onNavigate}>
        <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>
      </a>}
    </div>}
  </>;
}
