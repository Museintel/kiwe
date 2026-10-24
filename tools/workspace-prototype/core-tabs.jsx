import React from 'react';
import './core-tabs.css';

/** Shared contextual journey navigation. Each task supplies the meaning of its tabs. */
export function CoreTabs({tabs,active,onChange,label='Work journey'}){
 return <nav className="kiwe-core-tabs" aria-label={label} role="tablist">
  {tabs.map(tab=>tab.href
   ? <a key={tab.id} href={tab.href} role="tab" aria-current={active===tab.id?'page':undefined} aria-disabled={tab.disabled?'true':undefined} onClick={event=>{if(tab.disabled)event.preventDefault();else onChange?.(tab.id,event);}}>{tab.label}</a>
   : <button key={tab.id} type="button" role="tab" aria-selected={active===tab.id} aria-controls={tab.panelId} disabled={tab.disabled} onClick={()=>onChange?.(tab.id)}>{tab.label}</button>)}
 </nav>;
}
