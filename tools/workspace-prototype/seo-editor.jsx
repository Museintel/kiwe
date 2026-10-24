import React,{useEffect,useRef,useState} from 'react';
import './seo-editor.css';

const labels={seoTitle:'SEO title',metaDescription:'Search description',excerpt:'Reader excerpt',searchPhrases:'Search intent phrases',title:'Media title',alt:'Alternative text',caption:'Caption',description:'Media description'};
const limits={seoTitle:200,metaDescription:320,excerpt:1000,searchPhrases:500,title:200,alt:300,caption:1000,description:3000};

function endpointUrl(base,path,params){
 const url=new URL(base,globalThis.location?.origin);
 if(url.origin!==globalThis.location?.origin)throw Error('Open SEO on your signed-in website.');
 if(url.searchParams.has('rest_route'))url.searchParams.set('rest_route',url.searchParams.get('rest_route').replace(/\/$/,'')+path);
 else url.pathname=url.pathname.replace(/\/$/,'')+path;
 Object.entries(params||{}).forEach(([key,value])=>url.searchParams.set(key,String(value)));
 return url.href;
}
export async function seoRequest(endpoint,nonce,path,body,params){
 if(!endpoint||!nonce)throw Error('SEO is unavailable for this account.');
 const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),25000);
 try{const response=await fetch(endpointUrl(endpoint,path,params),{method:body===undefined?'GET':'POST',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{'X-WP-Nonce':nonce,...body===undefined?{}:{'Content-Type':'application/json','X-Kiwe-Mutation':'1'}},...body===undefined?{}:{body:JSON.stringify(body)}});
  if(!response.headers.get('content-type')?.includes('application/json'))throw Error('The website returned an unreadable SEO response.');
  const data=await response.json();if(!response.ok||data?.code)throw Error(data?.message||'SEO could not be saved. Refresh and review.');return data;
 }catch(error){throw error?.name==='AbortError'?Error('SEO timed out. Refresh the native record before retrying.'):error;}finally{clearTimeout(timer);}
}

const normalize=(current,scope)=>scope==='media'?{title:current.title||'',alt:current.alt||'',caption:current.caption||'',description:current.description||''}:['term','page','product','service'].includes(scope)?{seoTitle:current.seoTitle||'',metaDescription:current.metaDescription||'',searchPhrases:Array.isArray(current.searchPhrases)?current.searchPhrases.join(', '):''}:{seoTitle:current.seoTitle||'',metaDescription:current.metaDescription||'',excerpt:current.excerpt||'',searchPhrases:Array.isArray(current.searchPhrases)?current.searchPhrases.join(', '):''};
const saveFields=(fields)=>({...fields,...Object.hasOwn(fields,'searchPhrases')?{searchPhrases:fields.searchPhrases.split(',').map(value=>value.trim()).filter(Boolean).slice(0,10)}:{}});

export function SeoEditorPanel({endpoint,nonce,scope,id,taxonomy='',initial,onSaved,onDirtyChange,aiReady=true,showThumbnail=true,showGuidance=true}){
 const [item,setItem]=useState(initial||null),[draft,setDraft]=useState(initial?normalize(initial.current,scope):null),[busy,setBusy]=useState(false),[error,setError]=useState(''),[message,setMessage]=useState(''),[generated,setGenerated]=useState(false),[providerReady,setProviderReady]=useState(aiReady);
 const baseline=useRef(initial?JSON.stringify(normalize(initial.current,scope)):'');
 const path=scope==='term'?'/term/'+taxonomy+'/'+id:'/object/'+id;
 useEffect(()=>{let live=true;if(initial){setItem(initial);setProviderReady(aiReady);const next=normalize(initial.current,scope);setDraft(next);baseline.current=JSON.stringify(next);return;}setBusy(true);seoRequest(endpoint,nonce,scope==='term'?path:'/objects',undefined,scope==='term'?{}:{scope,item_id:id}).then(result=>{if(!live)return;const found=result?.items?.[0];if(!found||found.id!==id)throw Error('This SEO record is unavailable.');const next=normalize(found.current,scope);setItem(found);setDraft(next);setProviderReady(result.aiReady===true);baseline.current=JSON.stringify(next);}).catch(reason=>{if(live)setError(reason.message);}).finally(()=>{if(live)setBusy(false);});return()=>{live=false;};},[endpoint,nonce,scope,id,taxonomy,initial?.expected,aiReady]);
 async function ai(){if(!item||busy)return;setBusy(true);setError('');setMessage('');try{const result=await seoRequest(endpoint,nonce,path+'/proposal',{scope,expected:item.expected,...generated?{previous:saveFields(draft)}:{}});if(!result?.proposal?.fields)throw Error('Kiwe AI returned no usable suggestion.');const offered=Object.fromEntries(Object.entries(result.proposal.fields).filter(([key])=>Object.hasOwn(draft,key)));const next={...draft,...offered};if(Array.isArray(next.searchPhrases))next.searchPhrases=next.searchPhrases.join(', ');setDraft(next);setGenerated(true);setMessage(result.proposal.reason||'Suggestion ready. Review each field and save when it reads correctly.');}catch(reason){setError(reason.message);}finally{setBusy(false);}}
 async function save(){if(!item||!draft||busy)return;setBusy(true);setError('');setMessage('');try{const result=await seoRequest(endpoint,nonce,path+'/save',{scope,expected:item.expected,fields:saveFields(draft)});if(!result.saved||!result.item)throw Error('The native SEO save could not be confirmed.');const next=normalize(result.item.current,scope);setItem(result.item);setDraft(next);baseline.current=JSON.stringify(next);setGenerated(false);setMessage('SEO saved to the website.');onSaved?.(result.item);}catch(reason){setError(reason.message+' Refresh this record before retrying.');}finally{setBusy(false);}}
 const dirty=!!draft&&JSON.stringify(draft)!==baseline.current;
 useEffect(()=>{onDirtyChange?.(dirty);},[dirty,onDirtyChange]);
 return <div className="kiwe-seo-editor" aria-busy={busy}>{showThumbnail&&item?.thumbnail&&<img className="kiwe-seo-thumbnail" src={item.thumbnail} alt="" loading="lazy"/>}{!item&&!error&&<p role="status">Loading saved SEO fields…</p>}{draft&&<><div className="kiwe-seo-fields">{Object.entries(draft).map(([key,value])=><label key={key}>{labels[key]||key}{['metaDescription','excerpt','caption','description'].includes(key)?<textarea rows={key==='description'?4:3} maxLength={limits[key]} disabled={busy} value={value} onChange={e=>setDraft({...draft,[key]:e.target.value})}/>:<input maxLength={limits[key]} disabled={busy} value={value} onChange={e=>setDraft({...draft,[key]:e.target.value})}/>}</label>)}</div><div className="kiwe-seo-actions"><button type="button" disabled={busy||!providerReady} onClick={ai}>{busy?'Working…':generated?'Kiwe AI · try again':'Kiwe AI'}</button><button type="button" disabled={busy||!dirty} onClick={save}>Save SEO</button></div>{showGuidance&&<small>Kiwe AI uses this record and approved site context. Review its suggestions before saving; search appearance and ranking are decided by search engines.</small>}</>}{showGuidance?<>{message&&<p role="status">{message}</p>}{error&&<p role="alert">{error}</p>}</>:<p className="seo-card-feedback" role={error?'alert':'status'}>{error||message||' '}</p>}</div>;
}

export function SeoButton({endpoint,nonce,scope,id,taxonomy='',disabled=false,onSaved}){
 const [open,setOpen]=useState(false),[dirty,setDirty]=useState(false),dialog=useRef(null),launcher=useRef(null);
 useEffect(()=>{const node=dialog.current;if(open&&node&&!node.open)node.showModal();if(!open&&node?.open)node.close();},[open]);
 function close(){if(dirty&&!window.confirm('Discard unsaved SEO edits?'))return;setOpen(false);setDirty(false);requestAnimationFrame(()=>launcher.current?.focus());}
 if(!endpoint||!nonce||!id)return null;
 return <><button type="button" ref={launcher} className="kiwe-seo-open" disabled={disabled} onClick={()=>setOpen(true)}>SEO</button>{open&&<dialog ref={dialog} className="kiwe-seo-dialog" aria-label="SEO" onCancel={event=>{event.preventDefault();close();}}><div className="kiwe-seo-dialog-head"><h2>SEO</h2><button type="button" onClick={close}>Close</button></div><SeoEditorPanel endpoint={endpoint} nonce={nonce} scope={scope} id={id} taxonomy={taxonomy} onSaved={onSaved} onDirtyChange={setDirty}/></dialog>}</>;
}
