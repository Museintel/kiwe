import React,{useEffect,useState} from 'react';
import '../../wp-content/mu-plugins/dsa/assets/css/context-journey.css';

/** Saved native projection. Never a browser percentage, counter, poll or copied form. */
export function ContextProgress({value:initial,onNavigate}){
  const [value,setValue]=useState(initial);
  useEffect(()=>{const update=event=>setValue(event.detail);window.addEventListener('kiwe:context-progress',update);return()=>window.removeEventListener('kiwe:context-progress',update);},[]);
  useEffect(()=>setValue(initial),[initial]);
  if(!value||!Number.isInteger(value.percent)||value.percent<0||value.percent>100)return null;
  return <aside className="kiwe-context-progress" aria-label="Design Context progress">
    <div><a href={value.url} onClick={onNavigate}>Design Context <strong>{value.percent}%</strong></a><span>{value.ready} of {value.total} setup checks{value.later>0?` · ${value.later} for later`:''}</span></div>
    <progress max="100" value={value.percent} aria-label="Design Context completion">{value.percent}%</progress>
    <a className="kiwe-context-resume" href={value.resumeUrl} onClick={onNavigate}>{value.next?'Resume setup ↗':'Review context ↗'}</a>
  </aside>;
}
