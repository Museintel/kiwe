import React,{useState} from 'react';
import {workflowClient} from './workflow-client.mjs';
import {HandoverCard,handoverValid} from './seo-handover.jsx';
import './seo-handover-settings.css';
function migrationValid(value){
 if(value?.schema!=='kiwe.seo-metadata-migration.v1'||!/^[a-f0-9]{64}$/.test(value.expected)||!value.source||!Array.isArray(value.supportedFields)||!Array.isArray(value.notImported))throw Error('SEO migration inventory was incomplete. Refresh and review.');
 for(const scope of ['post','term']){const item=value.scopes?.[scope];if(!item||typeof item.canStart!=='boolean'||typeof item.canRollback!=='boolean'||!Number.isSafeInteger(item.source?.records)||item.source.records<0||!Number.isSafeInteger(item.rollbackRecords)||item.rollbackRecords<0)throw Error('SEO import scopes were incomplete. Refresh and review.');}
 return value;
}

function MigrationCard({value,disabled,onRun}){
 if(!value)return null;
 const job=value.active;
 return <article className="ai-migration"><span className="ai-kicker">SEO CONTINUITY</span><h3>Keep your saved SEO.</h3><p>Copy supported Rank Math fields into empty Kiwe fields. Existing Kiwe values and source records stay in place.</p><p className="ai-small">{value.source.pluginActive?'Rank Math remains the frontend SEO authority until the website manager completes the handover review.':'Review website defaults and frontend output before completing the handover.'} This import covers {value.supportedFields.join(', ')}.</p>{job?<><p role="status"><strong>{job.operation} · {job.status}</strong> — {job.scanned} checked, {job.records} records, {job.fields} fields, {job.conflicts} conflicts, {job.failed} failed.</p>{['queued','running','stalled'].includes(job.status)&&<button type="button" disabled={disabled} onClick={()=>onRun('resume',job.scope)}>Resume migration</button>}</>:<div className="ai-migration-scopes">{[['post','Content'],['term','Categories and tags']].map(([scope,label])=>{const item=value.scopes[scope];return <section key={scope} aria-label={label+' SEO migration'}><h4>{label}</h4><p className="ai-small">{item.source.records} source records · {item.rollbackRecords} retained undo journals</p>{item.lastImport?.conflicts>0&&<p className="ai-small">Newer edits were kept. Review those records before retrying undo.</p>}<div className="ai-toolbar">{item.canStart&&<button type="button" disabled={disabled} onClick={()=>onRun('start',scope)}>Import {label.toLowerCase()} SEO</button>}{item.canRollback&&<button type="button" disabled={disabled} onClick={()=>onRun('rollback',scope)}>Undo {label.toLowerCase()} import</button>}{!item.canStart&&!item.canRollback&&<span className="ai-small">No action available in this scope.</span>}</div></section>;})}</div>}<p className="ai-small">Other handover steps: {value.notImported.join(', ')}. Undo preserves newer edits and retains unresolved journals.</p></article>;
}

export function SEOHandover({config}){
 const request=workflowClient({...config,endpoint:config.handoverEndpoint});
 const [open,setOpen]=useState(false),[migration,setMigration]=useState(null),[handover,setHandover]=useState(null),[busy,setBusy]=useState(false),[error,setError]=useState('');
 async function load(){setBusy(true);setError('');try{const [m,h]=await Promise.all([request('/seo-migration'),request('/seo-handover')]);setMigration(migrationValid(m));setHandover(handoverValid(h));setOpen(true);}catch(e){setError(e.message);}finally{setBusy(false);}}
 async function run(kind,operation,scope='post'){if(busy||!confirm('Apply this reviewed SEO '+operation+'? Source settings and newer edits remain protected.'))return;setBusy(true);setError('');try{if(kind==='handover')setHandover(handoverValid(await request('/seo-handover',{operation,confirmed:true,expected:handover.expected})));else setMigration(migrationValid(await request('/seo-migration',{operation,scope,confirmed:true,expected:migration.expected})));}catch(e){setError(e.message);}finally{setBusy(false);}}
 if(!config.handoverEndpoint)return null;
 return <section className="seo-handover-settings"><button type="button" disabled={busy} onClick={()=>open?setOpen(false):load()}>{open?'Close SEO handover':'SEO provider handover'}</button>{error&&<p role="alert">{error}</p>}{open&&<><HandoverCard value={handover} disabled={busy} onRun={operation=>run('handover',operation)} request={request}/><MigrationCard value={migration} disabled={busy} onRun={(operation,scope)=>run('migration',operation,scope)}/></>}</section>;
}
