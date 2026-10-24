import {StatusLabel} from './status-label.jsx';
import React,{useEffect,useState} from 'react';
import {checkRecord} from './workflow-client.mjs';
import {CoreTabs} from './core-tabs.jsx';
import {WorkspaceDialog} from './workspace-dialog.jsx';
import {RefundTask} from './native-order-refund.jsx';
function reviewed(value){
 checkRecord(value,'number');
 if(!Array.isArray(value.items)||value.items.length>100||!Array.isArray(value.notes)||value.notes.length>20||!Array.isArray(value.charges)||value.charges.length>100||!Array.isArray(value.shippingMethods)||!['canComplete','canHold','canCancel','canRecordPayment','canAddNote'].every(key=>typeof value[key]==='boolean'))throw Error('The order review was incomplete. Refresh its saved state.');
 return value;
}
function OrderAddressCard({kind,address={}}){
 const fields=[['Name',[address.first_name,address.last_name].filter(Boolean).join(' ')],['Company',address.company],['Street',[address.address_1,address.address_2].filter(Boolean).join(', ')],['City',address.city],['Region',address.state],['Postal code',address.postcode],['Country',address.country],...(kind==='billing'?[['Email',address.email],['Phone',address.phone]]:[])].filter(([,value])=>!!value);
 return <article className="wf-tile wf-order-address" aria-label={kind==='shipping'?'Delivery address':'Billing contact'}><span className="wf-meta">{kind==='shipping'?'DELIVERY':'BILLING'}</span><h3>{kind==='shipping'?'Delivery address':'Billing contact'}</h3>{fields.length?<dl>{fields.map(([label,value])=><div className={label==='Email'?'wf-order-wide':undefined} key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>:<p>No {kind==='shipping'?'delivery address':'billing contact'} recorded.</p>}</article>;
}
const actions={
 complete:{label:'Complete fulfilment',confirm:'I have fulfilled every item in this order.',description:'Woo will run the existing completion actions and customer notifications.'},
 hold:{label:'Place on hold',confirm:'Keep this unpaid order on hold.',description:'Woo may reserve stock and run its existing on-hold notifications.'},
 cancel:{label:'Cancel unpaid order',confirm:'Cancel this unpaid order.',description:'Woo will run cancellation and stock-release actions. This does not issue a refund.'},
 payment:{label:'Record payment received',confirm:'I have verified receipt of this offline payment.',description:'Record a receipt for this order. Woo updates payment date, stock and its native order status.'},
 note:{label:'New private note',confirm:'Save this note for store staff.',description:'This note stays in the native order history.'}
};
export function OrderTask({id,work,onClose,onSaved,onDirtyChange=()=>{}}){
 const [order,setOrder]=useState(null),[error,setError]=useState(''),[tab,setTab]=useState('summary'),[operation,setOperation]=useState(null),[confirmed,setConfirmed]=useState(false),[note,setNote]=useState(''),[reference,setReference]=useState(''),[refreshing,setRefreshing]=useState(false),[freshReview,setFreshReview]=useState(false),[refundDirty,setRefundDirty]=useState(false);
 const dirty=refundDirty||(!!operation&&(confirmed||!!note||!!reference));
 useEffect(()=>{onDirtyChange(dirty);return()=>onDirtyChange(false);},[dirty]);
 useEffect(()=>{const controller=new AbortController();work.request('/orders/'+id,undefined,{},controller.signal).then(value=>{if(!controller.signal.aborted)setOrder(reviewed(value));}).catch(e=>{if(!controller.signal.aborted)setError(e.message);});return()=>controller.abort();},[id]);
 function closeAction(){setOperation(null);setConfirmed(false);setNote('');setReference('');}
 async function refresh(){
  setRefreshing(true);setError('');
  try{const value=reviewed(await work.request('/orders/'+id));setOrder(value);setConfirmed(false);setFreshReview(true);onSaved(value);work.refresh();}
  catch(e){setError(e.message);}finally{setRefreshing(false);}
 }
 async function save(e){
  e.preventDefault();if(!confirmed||!operation||!canAct)return;
  const next=await work.mutate('/orders/'+id+'/actions',{operation,expected:order.expected,confirmed:true,...(operation==='note'?{note}:{}),...(operation==='payment'?{reference}:{})},reviewed);
  if(next){setOrder(next);onSaved(next);closeAction();}
 }
 const disabled=work.busy||work.blocked||refreshing;
 const canAct=order&&!!({complete:order.canComplete,hold:order.canHold,cancel:order.canCancel,payment:order.canRecordPayment,note:order.canAddNote}[operation]);
 const open=key=>{setOperation(key);setConfirmed(false);setNote('');setReference('');setFreshReview(false);};
 return <section className="wf-detail wf-order-detail" aria-label="Order fulfilment">
  <header><h2>Order {order?'#'+order.number:'details'}</h2><button type="button" disabled={work.busy||refreshing} onClick={onClose}>Back to orders</button></header>
  {error&&<p role="alert">{error}</p>}{!order&&!error&&<p role="status">Opening this order…</p>}
  {order&&<>
   <CoreTabs label="Order journey" active={tab} onChange={setTab} tabs={[['summary','Overview'],['delivery','Fulfilment'],['payment','Payment'],['notes','Timeline']].map(([id,label])=>({id,label,disabled:work.busy||refreshing,panelId:'order-'+id}))}/>
   <div className="wf-order-actions"><button type="button" className="wf-refresh" disabled={work.busy||refreshing} onClick={refresh}>Refresh saved order</button><StatusLabel domain="order" status={order.status} label={order.statusLabel}/></div>
   {tab==='summary'&&<section id="order-summary" role="tabpanel">
    <div className="wf-order-summary" aria-label="Order summary"><article className="wf-tile wf-order-total"><span className="wf-meta">ORDER TOTAL</span><strong>{order.currency} {order.total}</strong><small>{order.created?new Date(order.created).toLocaleString():''}</small></article><article className="wf-tile wf-order-payment"><span className="wf-meta">PAYMENT & DELIVERY</span><h3>{order.paymentMethod||'Payment method not recorded'}</h3><p>{order.paidAt?'Receipt recorded':'No receipt date recorded'}</p>{order.shippingMethods.length>0&&<p>Delivery: {order.shippingMethods.join(' · ')}</p>}</article></div>
    <h3>Purchased items</h3><div className="wf-grid wf-order-items">{order.items.map(item=><article className="wf-tile" key={item.id}><span className="wf-meta">QUANTITY {item.quantity}</span><h3>{item.name}</h3>{item.productId>0&&<small>Product #{item.productId}{item.variationId?' · Variation #'+item.variationId:''}</small>}<p>{order.currency} {item.total} · tax {item.tax}</p></article>)}</div>
    {!!order.charges.length&&<dl className="wf-order-charges">{order.charges.map(item=><div key={item.id}><dt>{item.name||item.type}</dt><dd>{order.currency} {item.amount}{item.type==='coupon'?' discount':''}</dd></div>)}</dl>}
    {order.itemsTruncated&&<p role="alert">This order exceeds the 100-line review limit. Use its native order editor.</p>}
    {order.customerNote&&<article className="wf-tile wf-order-note"><span className="wf-meta">FROM YOUR CUSTOMER</span><h3>Customer instructions</h3><p>{order.customerNote}</p></article>}
   </section>}
   {tab==='delivery'&&<section id="order-delivery" role="tabpanel">
    <div className="wf-order-addresses" aria-label="Order addresses"><OrderAddressCard kind="shipping" address={order.shipping}/><OrderAddressCard kind="billing" address={order.billing}/></div>
    <div className="wf-order-actions">{order.canComplete&&<button type="button" className="wf-order-complete" disabled={disabled} onClick={()=>open('complete')}>Complete fulfilment</button>}{order.canHold&&<button type="button" className="wf-refresh" disabled={disabled} onClick={()=>open('hold')}>Place on hold</button>}{order.canCancel&&<button type="button" className="wf-refresh" disabled={disabled} onClick={()=>open('cancel')}>Cancel unpaid order</button>}</div>
    {!order.canComplete&&!order.canHold&&!order.canCancel&&<p className="wf-notice">{order.status==='completed'?'This order is completed.':'Review the saved status and payment before changing fulfilment.'}</p>}
   </section>}
   {tab==='payment'&&<section id="order-payment" role="tabpanel">
    <article className="wf-tile wf-order-note"><span className="wf-meta">NATIVE PAYMENT</span><h3>{order.paymentMethod||'Payment method not recorded'}</h3><dl className="wf-order-charges"><div><dt>Order total</dt><dd>{order.currency} {order.total}</dd></div><div><dt>Receipt date</dt><dd>{order.paidAt?new Date(order.paidAt).toLocaleString():'Not recorded'}</dd></div><div><dt>Reference</dt><dd>{order.transactionId||'Not recorded'}</dd></div><div><dt>Refunded</dt><dd>{order.currency} {order.totalRefunded}</dd></div></dl></article>
    <div className="wf-order-actions">{order.canRecordPayment&&<button type="button" className="wf-order-complete" disabled={disabled} onClick={()=>open('payment')}>Record payment received</button>}{order.paymentUrl&&<a className="wf-refresh" href={order.paymentUrl} target="_blank" rel="noopener noreferrer">Customer payment page ↗</a>}</div>
    {order.paymentOwner==='gateway'&&<p className="wf-notice">Capture and settlement are managed by this order’s payment gateway.</p>}
    <RefundTask order={order} work={work} refresh={refresh} refreshing={refreshing} onDirtyChange={setRefundDirty} onSaved={next=>{setOrder(reviewed(next));onSaved(next);}}/>
   </section>}
   {tab==='notes'&&<section id="order-notes" role="tabpanel"><div className="kiwe-task-tabs"><strong>Saved order activity</strong>{order.canAddNote&&<button type="button" className="kiwe-new-action" disabled={disabled} onClick={()=>open('note')}>+ New private note</button>}</div><ol className="wf-order-timeline">{order.notes.map(item=><li key={item.id}><span className="wf-meta">{item.customer?'CUSTOMER NOTE':'STORE NOTE'}</span><p>{item.body}</p><small>{item.author} · {item.date?new Date(item.date).toLocaleString():''}</small></li>)}</ol>{!order.notes.length&&<p>No saved notes yet.</p>}{order.notesHaveMore&&<p>The latest 20 notes are shown. Earlier notes remain in Woo.</p>}</section>}
   {operation&&<WorkspaceDialog title={actions[operation].label} busy={work.busy||refreshing} dirty={dirty} onClose={closeAction}><form className="wf-order-action-dialog" onSubmit={save}>
    {work.blocked&&<div role="alert"><p>{work.message}</p><button type="button" className="wf-refresh" disabled={work.busy||refreshing} onClick={refresh}>Refresh saved state</button><p>Review the saved result before confirming another action.</p></div>}
    <p>Order #{order.number} · {order.currency} {order.total} · {order.status}</p><p>{actions[operation].description}</p>
    {freshReview&&<div className="wf-notice" role="status">{operation==='payment'&&order.paidAt?<p>Payment receipt saved: {new Date(order.paidAt).toLocaleString()}{order.transactionId?' · '+order.transactionId:''}.</p>:<p>Saved order refreshed. Review its current result before confirming.</p>}{!canAct&&<p>This action is no longer available for the saved order.</p>}{operation==='note'&&order.notes.length>0&&<><strong>Latest saved notes</strong>{order.notes.slice(0,3).map(item=><p key={item.id}>{item.body}</p>)}</>}</div>}
    {operation==='note'&&<label>Private note<textarea maxLength={2000} value={note} disabled={disabled} onChange={e=>{setNote(e.target.value);setConfirmed(false);}}/></label>}
    {operation==='payment'&&<label>Receipt reference (optional)<input maxLength={120} value={reference} disabled={disabled} onChange={e=>{setReference(e.target.value);setConfirmed(false);}}/></label>}
    <label className="wf-choice"><input type="checkbox" checked={confirmed} disabled={disabled||!canAct} onChange={e=>setConfirmed(e.target.checked)}/>{actions[operation].confirm}</label>
    <button className="wf-order-complete" disabled={disabled||!canAct||!confirmed||(operation==='note'&&!note.trim())}>{actions[operation].label}</button>
   </form></WorkspaceDialog>}
  </>}
 </section>;
}
