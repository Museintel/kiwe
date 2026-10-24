import React,{useEffect,useState} from 'react';
import {WorkspaceDialog} from './workspace-dialog.jsx';
const copy=value=>JSON.parse(JSON.stringify(value));
function checked(value){
 if(value?.schema!==1||!/^[a-f0-9]{64}$/.test(value.expected)||typeof value.editable!=='boolean'||!value.settings?.sitemap||!value.settings?.news||!Array.isArray(value.variables)||!Array.isArray(value.postTypes)||!Array.isArray(value.taxonomies))throw Error('Website defaults could not be read. Refresh before saving.');
 return value;
}
export function SeoDefaults({request,onClose,onSaved}){
 const [saved,setSaved]=useState(null),[draft,setDraft]=useState(null),[scope,setScope]=useState('home'),[busy,setBusy]=useState(true),[blocked,setBlocked]=useState(false),[error,setError]=useState('');
 const dirty=saved&&JSON.stringify(saved.settings)!==JSON.stringify(draft);
 function accept(value){const next=checked(value);setSaved(next);setDraft(copy(next.settings));setBlocked(false);}
 async function refresh(){setBusy(true);setError('');try{accept(await request('/defaults'));}catch(reason){setError(reason.message);setBlocked(true);}finally{setBusy(false);}}
 useEffect(()=>{refresh();},[]);
 function change(key,value){setDraft(previous=>({...previous,[key]:value}));}
 const [area,key]=scope.split(':'),rule=key?draft?.[area]?.[key]||{}:draft?.[area]||{},robots=rule.robots||[];
 function field(name,value){setDraft(previous=>{const next=copy(previous),target=key?(next[area][key]||={}):next[area];if(value===undefined)delete target[name];else target[name]=value;return next;});}
 function indexing(values){return values.includes('noindex')?'noindex':values.includes('index')?'index':'';}
 function withIndex(values,value){return [...values.filter(item=>!['index','noindex'].includes(item)),...(value?[value]:[])];}
 async function save(event){event.preventDefault();if(busy||blocked||!saved.editable)return;setBusy(true);setError('');try{accept(await request('/defaults',{expected:saved.expected,settings:draft}));onSaved('Website SEO defaults saved.');}catch(reason){setError(reason.message+' Refresh saved defaults before trying again.');setBlocked(true);}finally{setBusy(false);}}
 const disabled=busy||blocked||!saved?.editable;
 const visibility=(label,values,onChange)=><label>{label}<select disabled={disabled} value={indexing(values)} onChange={event=>onChange(withIndex(values,event.target.value))}><option value="">Use default</option><option value="index">Allow indexing</option><option value="noindex">Hide from search</option></select></label>;
 const toggle=(label,value,onChange)=><label className="seo-default-switch"><span>{label}</span><input disabled={disabled} type="checkbox" checked={value} onChange={event=>onChange(event.target.checked)}/></label>;
 return <WorkspaceDialog title="Website SEO defaults" busy={busy} dirty={dirty} onClose={onClose} className="seo-defaults-dialog">
  {error&&<p role="alert" className="seo-error">{error}</p>}{busy&&!saved&&<p role="status">Reading website defaults…</p>}
  {saved&&draft&&<form onSubmit={save}>
   {!saved.editable&&<p className="seo-message">Your active SEO plugin manages website defaults. A reviewed handover is required before Kiwe takes over.</p>}{saved.editable&&!saved.enabled&&<p className="seo-message">SEO is paused. Your defaults remain saved.</p>}
   <nav className="seo-tabs" aria-label="Website defaults sections">{[['home','Website'],['postTypes','Content'],['taxonomies','Categories & tags'],['archives','Archives'],['sitemap','Sitemaps']].map(([id,label])=><button key={id} type="button" disabled={busy||(id==='postTypes'&&!saved.postTypes.length)||(id==='taxonomies'&&!saved.taxonomies.length)} aria-current={area===id||id==='home'&&area==='blog'?'page':undefined} onClick={()=>setScope(id==='postTypes'?'postTypes:'+saved.postTypes[0]?.id:id==='taxonomies'?'taxonomies:'+saved.taxonomies[0]?.id:id==='archives'?'archives:author':id)}>{label}</button>)}</nav>
   <div className="seo-default-fields">{scope!=='sitemap'?<>
    {['home','blog'].includes(area)&&<label>Website area<select value={scope} disabled={busy} onChange={event=>setScope(event.target.value)}><option value="home">Homepage</option><option value="blog">Blog listing</option></select></label>}
    {['postTypes','taxonomies'].includes(area)&&<label>Content type<select value={key} disabled={busy} onChange={event=>setScope(area+':'+event.target.value)}>{(area==='postTypes'?saved.postTypes:saved.taxonomies).map(item=><option key={item.id} value={item.id}>{item.label}</option>)}</select></label>}
    {area==='archives'&&<label>Archive<select value={key} disabled={busy} onChange={event=>setScope('archives:'+event.target.value)}><option value="author">Authors</option><option value="date">Dates</option>{saved.postTypes.filter(item=>!['post','page'].includes(item.id)).map(item=><option key={item.id} value={item.id}>{item.label}</option>)}</select></label>}
    {area==='archives'&&['author','date'].includes(key)&&toggle('Redirect this archive to the homepage',!!draft.policies?.[key==='date'?'redirectDateArchives':'redirectAuthorArchives'],value=>change('policies',{...draft.policies,[key==='date'?'redirectDateArchives':'redirectAuthorArchives']:value}))}
    {scope==='home'&&<section className="seo-default-help" aria-label="Website verification"><strong>Website verification</strong><p>Existing search-service verification codes remain with this website after an SEO handover.</p>{[['google','Google'],['bing','Bing'],['pinterest','Pinterest']].map(([id,label])=><label key={id}>{label} verification code<input disabled={disabled} value={draft.verification?.[id]||''} maxLength={id==='google'?200:32} onChange={event=>change('verification',{...draft.verification,[id]:event.target.value})}/></label>)}</section>}
    <label>SEO title template<input disabled={disabled} value={rule.title||''} maxLength="1000" placeholder="%title% %sep% %sitename%" onChange={event=>field('title',event.target.value||undefined)}/></label>
    <label>Search description template<textarea disabled={disabled} value={rule.description||''} maxLength="1000" placeholder="%excerpt%" onChange={event=>field('description',event.target.value||undefined)}/></label>
    {visibility('Search visibility',robots,value=>field('robots',value))}
    {['postTypes','taxonomies'].includes(area)&&toggle('Include in sitemap',rule.sitemap!==false,value=>field('sitemap',value))}
    {area==='taxonomies'&&toggle('Hide empty category and tag pages from search',!!draft.policies?.noindexEmptyTerms,value=>change('policies',{...draft.policies,noindexEmptyTerms:value}))}
    {scope==='home'&&<section className="seo-default-help" aria-label="Media attachment pages"><strong>Media attachment pages</strong>{toggle('Redirect attachment pages to their public parent',!!draft.policies?.redirectAttachments,value=>change('policies',{...draft.policies,redirectAttachments:value}))}<p>When there is no public parent, visitors go to the fallback address or your homepage. Original media files stay available.</p>{draft.policies?.redirectAttachments&&<label>Fallback address<input disabled={disabled} type="url" value={draft.policies?.attachmentFallback||''} placeholder="Use this website’s homepage" onChange={event=>change('policies',{...draft.policies,attachmentFallback:event.target.value})}/></label>}</section>}
    {scope==='home'&&<><label>Title separator<input disabled={disabled} maxLength="5" required value={draft.separator} onChange={event=>change('separator',event.target.value)}/></label><label>Default sharing image URL<input disabled={disabled} type="url" value={draft.social.image} onChange={event=>change('social',{...draft.social,image:event.target.value})}/></label>{visibility('Default search visibility',draft.globalRobots,value=>change('globalRobots',value))}</>}
    <p className="seo-default-help">Individual saved SEO fields take precedence. Available variables: {saved.variables.map(value=>'%'+value+'%').join(', ')}.</p>
   </>:<>
    {toggle('Enable sitemap',draft.sitemap.enabled,value=>change('sitemap',{...draft.sitemap,enabled:value}))}
    <label>URLs per sitemap<input disabled={disabled} type="number" min="50" max="1000" required value={draft.sitemap.pageSize} onChange={event=>change('sitemap',{...draft.sitemap,pageSize:Number(event.target.value)})}/></label>
    {toggle('Include author archives',draft.sitemap.authors,value=>change('sitemap',{...draft.sitemap,authors:value}))}
    <section className="seo-default-help" aria-label="News sitemap"><strong>News sitemap</strong><p>{saved.newsAutomatic?'Enabled automatically for News or publication.':saved.newsConfiguration?.enabled?'Existing News configuration is retained.':'Choose News or publication as your website type in Design Context to enable News discovery.'}</p>{saved.newsConfiguration?.enabled&&<p>{saved.newsConfiguration.publication} · {saved.newsConfiguration.language}</p>}{saved.identityUrl&&<a href={saved.identityUrl}>Review website type and identity ↗</a>}<p>News discovery includes published, indexable articles from the last two days. Older articles stay in the regular sitemap.</p></section>
    <p className="seo-default-help"><a href={saved.sitemapUrl} target="_blank" rel="noreferrer">View sitemap ↗</a>{saved.newsConfiguration?.enabled&&saved.enabled&&<> · <a href={saved.newsUrl} target="_blank" rel="noreferrer">View news sitemap ↗</a></>}</p>
   </>}</div>
   <div className="seo-default-actions"><button type="submit" disabled={disabled||!dirty}>Save defaults</button><button type="button" disabled={busy} onClick={()=>{if(!dirty||confirm('Discard unsaved defaults and refresh?'))refresh();}}>Refresh saved defaults</button></div>
  </form>}{!saved&&!busy&&<button type="button" onClick={refresh}>Try again</button>}
 </WorkspaceDialog>;
}
