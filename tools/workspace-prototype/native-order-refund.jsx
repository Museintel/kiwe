import React,{useEffect,useState} from 'react';
import {CoreTabs} from './core-tabs.jsx';
import {WorkspaceDialog} from './workspace-dialog.jsx';

function sumLines(lines,precision){
 let total=0n;
 for(const row of lines)for(const value of [row.total,...Object.values(row.taxes)]){
  if(!/^(0|[1-9]\d*)(?:\.\d+)?$/.test(value))return '';
  const [whole,fraction='']=value.split('.');if(fraction.length>precision)return '';
  total+=BigInt(whole+fraction.padEnd(precision,'0'));
 }
 const value=total.toString().padStart(precision+1,'0');return precision?value.slice(0,-precision)+'.'+value.slice(-precision):value;
}
export function RefundTask({order,work,refresh,onSaved,onDirtyChange,refreshing}){
 const state=order.refund||{},[open,setOpen]=useState(false),[intent,setIntent]=useState(''),[tab,setTab]=useState('amount'),[mode,setMode]=useState('manual'),[amount,setAmount]=useState(''),[reason,setReason]=useState(''),[reference,setReference]=useState(''),[lines,setLines]=useState({}),[restock,setRestock]=useState(false),[confirmed,setConfirmed]=useState(false),[resolution,setResolution]=useState('not_sent'),[refundId,setRefundId]=useState(''),[stockReviewed,setStockReviewed]=useState(false);
 const resolving=open==='resolve',dirty=!!open&&(!!amount||!!reason||!!reference||confirmed||Object.keys(lines).length>0||stockReviewed);
 useEffect(()=>{onDirtyChange(dirty);return()=>onDirtyChange(false);},[dirty]);
 const busy=work.busy||refreshing,disabled=busy||work.blocked;
 const chosen=Object.values(lines),allocated=tab==='items',total=allocated?sumLines(chosen,state.precision):amount;
 const matching=state.guard?.intent===intent||state.history?.some(row=>row.intent===intent);
 const canCreate=state.canCreate&&!matching&&(mode!=='gateway'||state.canGateway);
 const canAct=resolving?state.canResolve&&state.guard?.intent===intent:canCreate;
 const close=()=>{setOpen(false);setConfirmed(false);};
 function start(type){
  setOpen(type);setIntent(type==='resolve'?state.guard.intent:crypto.randomUUID());setTab('amount');setMode(state.canGateway?'gateway':'manual');setAmount('');setReason('');setReference('');setLines({});setRestock(false);setConfirmed(false);setResolution('not_sent');setRefundId('');setStockReviewed(false);
 }
 const changed=(setter,value)=>{setter(value);setConfirmed(false);};
 function choose(item,checked){
  setLines(value=>{const next={...value};if(checked)next[item.id]={id:item.id,quantity:0,total:'0',taxes:Object.fromEntries(Object.keys(item.taxes).map(key=>[key,'0']))};else delete next[item.id];return next;});setConfirmed(false);
 }
 function editLine(id,key,value,rate){
  setLines(current=>({...current,[id]:{...current[id],...(key==='taxes'?{taxes:{...current[id].taxes,[rate]:value}}:{[key]:key==='quantity'?(value===''?0:Number(value)):value})}}));setConfirmed(false);
 }
 async function save(e){
  e.preventDefault();if(!confirmed||!canAct||disabled)return;
  const body=resolving?{operation:'resolve',expected:order.expected,confirmed:true,intent,resolution,reference,...(resolution==='recorded'?{refundId:Number(refundId)}:{}),stockReviewed}:{operation:'create',expected:order.expected,confirmed:true,intent,mode,amount:total,reason,reference,restock:allocated&&restock,lines:allocated?chosen:[]};
  const next=await work.mutate('/orders/'+order.id+'/refunds',body,value=>{if(!value.refund||!Array.isArray(value.refund.history)||!Array.isArray(value.refund.lines))throw Error('Refresh this order to verify the refund.');return value;});
  if(next){onSaved(next);close();}
 }
 const valid=resolving?!!reference.trim()&&(resolution!=='recorded'||Number(refundId)>0)&&(!state.guard?.restock||stockReviewed):!!total&&Number(total)>0&&Number(total)<=Number(state.remaining)&&!!reason.trim()&&(mode!=='manual'||!!reference.trim())&&(!allocated||chosen.length>0);
 return <section className="wf-order-refunds" aria-label="Refunds">
  <div className="kiwe-task-tabs"><h3>Refunds</h3>{state.canCreate&&<button type="button" className="kiwe-new-action" disabled={busy} onClick={()=>start('create')}>+ New refund</button>}</div>
  <p className="wf-order-refund-balance">Available to refund <strong>{order.currency} {state.remaining||'0'}</strong></p>
  {state.blocked&&<div className="wf-notice" role="status"><strong>A refund needs review.</strong><p>Check the payment provider and saved refund records before sending any more money.</p>{state.canResolve&&<button type="button" disabled={busy} onClick={()=>start('resolve')}>Review refund outcome</button>}</div>}
  {!state.canCreate&&!state.blocked&&!state.history?.length&&<p>No refundable payment is recorded for this order.</p>}
  <ol className="wf-order-timeline">{(state.history||[]).map(row=><li key={row.id}><span className="wf-meta">REFUND #{row.id}</span><h4>{row.currency} {row.amount}</h4><p>{row.reason}</p><small>{row.gateway?'Submitted through gateway':'Recorded as returned elsewhere'}{row.reference?' · '+row.reference:''} · {row.date?new Date(row.date).toLocaleString():''}</small></li>)}</ol>
  {state.historyHasMore&&<p>The latest 20 refunds are shown. Earlier records remain in Woo.</p>}
  {open&&<WorkspaceDialog title={resolving?'Review refund outcome':'New refund'} busy={busy} dirty={dirty} onClose={close}><form className="wf-refund-form" onSubmit={save}>
   {work.blocked&&<div className="wf-notice" role="alert"><p>{work.message}</p><button type="button" disabled={busy} onClick={refresh}>Refresh saved state</button></div>}
   {!canAct&&<p className="wf-notice" role="status">This refund has already been attempted or is unavailable. Review the saved history before starting another task.</p>}
   <p>Order #{order.number} · {order.currency} {resolving?state.guard?.amount:state.remaining} {resolving?'under review':'available'}</p>
   {resolving?<>
    <label>Verified outcome<select value={resolution} disabled={disabled} onChange={e=>changed(setResolution,e.target.value)}><option value="not_sent">No money was returned</option><option value="recorded">Match an existing Woo refund</option><option value="record_elsewhere">Record money confirmed as returned</option></select></label>
    {resolution==='recorded'&&<label>Saved refund<select value={refundId} disabled={disabled} onChange={e=>changed(setRefundId,e.target.value)}><option value="">Choose a native refund</option>{(state.history||[]).map(row=><option key={row.id} value={row.id}>#{row.id} · {row.currency} {row.amount}</option>)}</select></label>}
    {resolution==='record_elsewhere'&&<p>Only the verified return is recorded. No gateway request or stock increase will be made.</p>}
    <label>Gateway or return reference<input maxLength={120} value={reference} disabled={disabled} onChange={e=>changed(setReference,e.target.value)}/></label>
    {state.guard?.restock&&<label className="wf-choice"><input type="checkbox" checked={stockReviewed} disabled={disabled} onChange={e=>changed(setStockReviewed,e.target.checked)}/>I checked inventory and corrected any stock changes.</label>}
   </>:<>
    <CoreTabs label="Refund allocation" active={tab} onChange={key=>{setTab(key);setConfirmed(false);if(key==='amount')setRestock(false);}} tabs={[{id:'amount',label:'Amount',disabled:busy,panelId:'refund-amount'},{id:'items',label:'Items',disabled:busy,panelId:'refund-items'}]}/>
    <label>Return method<select value={mode} disabled={disabled} onChange={e=>changed(setMode,e.target.value)}>{state.canGateway&&<option value="gateway">Send through {order.paymentMethod||'payment gateway'}</option>}<option value="manual">Record money returned elsewhere</option></select></label>
    {mode==='manual'&&<label>Return reference<input maxLength={120} value={reference} disabled={disabled} onChange={e=>changed(setReference,e.target.value)}/></label>}
    {!allocated&&<div id="refund-amount" role="tabpanel"><label>Refund amount ({order.currency})<input inputMode="decimal" value={total} disabled={disabled} onChange={e=>changed(setAmount,e.target.value)}/></label></div>}
    {allocated&&<p>Refund amount <strong>{order.currency} {total||'0'}</strong></p>}
    {allocated&&<div id="refund-items" role="tabpanel" className="wf-refund-lines">{(state.lines||[]).filter(row=>row.supported).map(row=><article className="wf-refund-line" key={row.id}><label className="wf-choice"><input type="checkbox" disabled={disabled} checked={!!lines[row.id]} onChange={e=>choose(row,e.target.checked)}/>{row.name}</label>{lines[row.id]&&<div className="wf-refund-line-fields">{row.type==='line_item'&&<label>Returned quantity (up to {row.quantity})<input type="number" min="0" max={row.quantity} step="1" value={lines[row.id].quantity} disabled={disabled} onChange={e=>editLine(row.id,'quantity',e.target.value)}/></label>}<label>Amount before tax (up to {row.total})<input inputMode="decimal" value={lines[row.id].total} disabled={disabled} onChange={e=>editLine(row.id,'total',e.target.value)}/></label>{Object.entries(row.taxes).map(([rate,value])=><label key={rate}>Tax #{rate} (up to {value})<input inputMode="decimal" value={lines[row.id].taxes[rate]} disabled={disabled} onChange={e=>editLine(row.id,'taxes',e.target.value,rate)}/></label>)}</div>}</article>)}</div>}
    {allocated&&<label className="wf-choice"><input type="checkbox" checked={restock} disabled={disabled} onChange={e=>changed(setRestock,e.target.checked)}/>Return the selected quantities to stock.</label>}
    <label>Reason<textarea maxLength={1000} value={reason} disabled={disabled} onChange={e=>changed(setReason,e.target.value)}/></label>
   </>}
   <label className="wf-choice"><input type="checkbox" checked={confirmed} disabled={disabled||!canAct||!valid} onChange={e=>setConfirmed(e.target.checked)}/>{resolving?'I verified this outcome with the provider and native records.':mode==='gateway'?'Send this reviewed refund through the payment gateway.':'I have already returned this money outside the gateway.'}</label>
   <button className="kiwe-new-action" disabled={disabled||!canAct||!valid||!confirmed}>{resolving?'Save verified outcome':mode==='gateway'?'Send refund':'Record refund'}</button>
  </form></WorkspaceDialog>}
 </section>;
}
