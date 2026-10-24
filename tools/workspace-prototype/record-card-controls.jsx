import React,{useState} from 'react';
import {MenuPlacementControl} from './menu-placement-control.jsx';

/** The private card is a view of one SEO owner and the configured WordPress menu. */
export function RecordCardControls({record,menus=[],endpoint,navigationEndpoint,nonce,onSaved}){
 const [busy,setBusy]=useState(false),[error,setError]=useState('');
 if(!record)return null;
 async function change(body){
  if(busy||!endpoint||!nonce)return;
  setBusy(true);setError('');
  const url=new URL(endpoint,location.origin);
  if(url.origin!==location.origin){setBusy(false);setError('Open this task on your signed-in website.');return;}
  const suffix='/'+encodeURIComponent(record.kind)+'/'+record.id;
  if(url.searchParams.has('rest_route'))url.searchParams.set('rest_route',url.searchParams.get('rest_route').replace(/\/$/,'')+suffix);
  else url.pathname=url.pathname.replace(/\/$/,'')+suffix;
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
  try{
   const response=await fetch(url.href,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{'Content-Type':'application/json','X-WP-Nonce':nonce,'X-Kiwe-Mutation':'1'},body:JSON.stringify({...body,expected:record.expected})});
   if(!response.headers.get('Content-Type')?.includes('application/json'))throw new Error('The website response could not be read. Reload and review.');
   const result=await response.json();
   if(!response.ok)throw new Error(result.message||'The change could not be confirmed. Reload and review.');
   if(result.record?.id!==record.id||result.record?.kind!==record.kind||!/^[a-f0-9]{64}$/.test(result.record.expected))throw new Error('The saved state could not be confirmed. Reload and review.');
   onSaved(result);
  }catch(reason){setError(reason.message||'The change could not be confirmed. Reload and review.');}
  finally{clearTimeout(timeout);setBusy(false);}
 }
 return <div className="kiwe-record-controls" aria-label="Search and visitor menu choices"><button type="button" className="kiwe-card-switch" role="switch" disabled={busy||!record.canIndex} aria-checked={!record.noindex} onClick={()=>change({operation:'index',noindex:!record.noindex})}><span>Indexable</span><span className="kiwe-card-switch-track" aria-hidden="true"/></button>{record.canMenu&&navigationEndpoint&&<MenuPlacementControl kind={record.kind} id={record.id} object={record.menuObject} publicRecord={record.public} menus={menus} endpoint={navigationEndpoint} nonce={nonce} enabled={!busy} onChange={next=>onSaved({record,menus:next})}/>}{error&&<span role="alert">{error}</span>}</div>;
}
