import React,{useEffect,useRef,useState} from 'react';
import {StatusLabel} from './status-label.jsx';
import {statusPresentation} from './status-presentation.mjs';
import './feedback-cards.css';

const dateLabel=value=>value?new Date(value*1000).toLocaleString():'';
export function feedbackFilters(hasStore){return [['all','Everything'],['post','Posts'],...(hasStore?[['product','Products']]:[]),['website','Website']];}
export function FeedbackCard({item,onAction,busy,canCurate,actorKey,publicName,onDirtyChange,focused=false}){
 const [replyOpen,setReplyOpen]=useState(false),[text,setText]=useState(''),[name,setName]=useState(publicName||''),[pending,setPending]=useState(null);
 const sending=useRef(false),dirty=!!text.trim()||!!pending;
 useEffect(()=>{onDirtyChange?.(item.id,dirty);return()=>onDirtyChange?.(item.id,false);},[item.id,dirty,onDirtyChange]);
 const status=(next,label)=>{if(!confirm(label+' this contribution? Its public visibility follows the original WordPress status.'))return;onAction({operation:'status',id:item.id,status:next,expected:item.expected,acceptEffect:true});};
 async function reply(event){
  event.preventDefault();if(busy||sending.current)return;sending.current=true;
  try{
   const body=pending||{operation:'reply',id:item.id,expected:item.expected,text,name,sendId:crypto.randomUUID(),actorKey};setPending(body);
   const result=await onAction(body);if(result?.ok){setPending(null);setText('');setReplyOpen(false);}
  }finally{sending.current=false;}
 }
 const social=item.social;
 const reaction=(kind,enabled)=>onAction({operation:'reaction',id:item.id,kind,enabled,expected:social.moderation.expected,actorKey});
 return <article id={'feedback-'+item.id} className={'continuity-card feedback kiwe-status-card '+(item.selected?'chosen ':'')+(focused?'feedback-focused':'')} data-tone={item.tone||statusPresentation('feedback',item.status).tone}>
  <div className="feedback-meta"><small>{item.context==='product'?'PRODUCT':item.context==='website'?'WEBSITE':'POST'} · {item.parent?'REPLY':item.sourceType==='review'?'REVIEW':'COMMENT'}</small><StatusLabel domain="feedback" status={item.status}/>{item.verified&&<span>Verified contact</span>}{item.createdAt>0&&<time dateTime={new Date(item.createdAt*1000).toISOString()}>{dateLabel(item.createdAt)}</time>}</div>
  <h2>{item.author}</h2>
  {item.purchase&&<p className="feedback-purchase">{item.purchase.purchases>0?`${item.purchase.purchases} previous purchase${item.purchase.purchases===1?'':'s'}`:'No previous paid orders'}{item.purchase.lastPurchaseAt>0&&<> · Latest {dateLabel(item.purchase.lastPurchaseAt)}</>}</p>}
  {item.rating>0&&<p aria-label={item.rating+' out of 5 stars'}>{'★'.repeat(item.rating)}{'☆'.repeat(5-item.rating)}</p>}
  <blockquote>{item.quote}</blockquote>{item.text&&item.text!==item.quote&&<details><summary>Read full contribution</summary><p className="feedback-full-text">{item.text}</p></details>}
  <p className="continuity-source">On {item.sourceTitle}{item.parent>0&&<> · Reply to #{item.parent}</>}</p>
  {Number.isInteger(item.likes)&&<p>{item.likes} like{item.likes===1?'':'s'}{item.reports>0&&<> · {item.reports} report{item.reports===1?'':'s'} to review</>}{social?.hearted&&<> · Website-team heart</>}{social?.pinned&&<> · Pinned</>}</p>}
  <div className="continuity-actions">
   {item.status!=='approve'&&<button disabled={busy} onClick={()=>status('approve','Approve')}>Approve</button>}{item.status==='approve'&&<button disabled={busy} onClick={()=>status('hold','Move to pending')}>Move to pending</button>}
   {!['spam','trash'].includes(item.status)&&<button className="continuity-secondary" disabled={busy} onClick={()=>status('spam','Mark as spam')}>Spam</button>}{item.status!=='trash'&&<button className="continuity-secondary" disabled={busy} onClick={()=>status('trash','Move to trash')}>Trash</button>}{['spam','trash'].includes(item.status)&&<button disabled={busy} onClick={()=>status('hold','Restore as pending')}>Restore to pending</button>}
   {item.canReply&&<button className="continuity-secondary" disabled={busy} aria-expanded={replyOpen} onClick={()=>setReplyOpen(open=>!open)}>Reply</button>}
   {social&&<button className="continuity-secondary" disabled={busy} aria-pressed={social.hearted} onClick={()=>reaction('heart',!social.hearted)}>{social.hearted?'Remove team heart':'Give team heart'}</button>}
   {social&&!item.parent&&<button className="continuity-secondary" disabled={busy} aria-pressed={social.pinned} onClick={()=>reaction('pin',!social.pinned)}>{social.pinned?'Unpin':'Pin'}</button>}
   {item.reports>0&&<button className="continuity-secondary" disabled={busy} onClick={()=>onAction({operation:'reports_reviewed',id:item.id,expected:item.reportsExpected,actorKey})}>Mark reports reviewed</button>}
  </div>
  {replyOpen&&<form className="feedback-reply" onSubmit={reply}>
   <label>Reply as<input autoComplete="nickname" required minLength={2} maxLength={60} disabled={busy||!!pending} value={name} onChange={e=>setName(e.target.value)} aria-describedby={'reply-name-'+item.id}/></label><small id={'reply-name-'+item.id}>This name is public. Use your name or the business name, without a phone number or email.</small>
   <label>Your reply<textarea rows={4} required maxLength={5000} disabled={busy||!!pending} value={text} onChange={e=>setText(e.target.value)}/></label>
   {pending&&<p role="status">If sending was interrupted, retry this same reply. A confirmed Send is recorded only once.</p>}
   <button disabled={busy||!text.trim()||name.trim().length<2}>{pending?'Confirm this reply':'Send reply'}</button>
   {!pending&&<button type="button" className="continuity-secondary" disabled={busy} onClick={()=>{setText('');setReplyOpen(false);}}>Discard reply</button>}
  </form>}
  {canCurate&&social&&(social.hearted||social.pinned)&&item.public&&<p>Eligible for live testimonials when its source is selected in Design Context → Website plan.</p>}
  {item.url&&<a href={item.url} target="_blank" rel="noreferrer">Read original ↗</a>}
 </article>;
}

const fields=[
 ['newPosts','Allow comments on new posts','Existing posts keep their own open or closed setting.'],
 ['accountRequired','Require an account to send a comment','Comments always remain readable. PhoneKey guides visitors through the existing sign-in or subscription flow.'],
 ['moderateAll','Review every new contribution before publishing','WordPress moderation and spam decisions always apply, including to verified contacts.'],
 ['previousApproval','Require a previously approved contribution','A first contribution waits for moderation when this native WordPress rule is enabled.'],
 ['threaded','Allow replies','Replies stay within the original conversation and do not increase its top-level count.'],
];
export function FeedbackSettings({policy,busy,onAction}){
 const [values,setValues]=useState(policy.values);useEffect(()=>setValues(policy.values),[policy.expected]);
 const save=field=>onAction({operation:'policy',field,value:values[field],expected:policy.expected});
 const changed=field=>values[field]!==policy.values[field];
 return <details className="feedback-settings"><summary>Discussion settings</summary><p>These controls save the original WordPress discussion settings. Product-review rules stay in the Store App. Verified contact does not bypass manual moderation or spam protection.</p>
  {fields.map(([field,label,help])=><section key={field}><fieldset disabled={busy}><legend>{label}</legend><p>{help}</p>{[[true,'On'],[false,'Off']].map(([value,title])=><label className="feedback-radio" key={title}><input type="radio" name={'discussion-'+field} checked={values[field]===value} onChange={()=>setValues(old=>({...old,[field]:value}))}/>{title}</label>)}</fieldset><button type="button" disabled={busy||!changed(field)} onClick={()=>save(field)}>Save {label.toLowerCase()}</button></section>)}
  {values.threaded&&<section><label>Reply depth<input type="number" min={1} max={10} value={values.threadDepth} disabled={busy} onChange={e=>setValues(old=>({...old,threadDepth:Number(e.target.value)}))}/></label><button type="button" disabled={busy||!changed('threadDepth')} onClick={()=>save('threadDepth')}>Save reply depth</button></section>}
  <section><label>Site-wide feedback page<select value={values.sitePage} disabled={busy} onChange={e=>setValues(old=>({...old,sitePage:Number(e.target.value)}))}><option value={0}>Do not use site-wide feedback</option>{values.sitePage>0&&!policy.pages.some(page=>page.id===values.sitePage)&&<option value={values.sitePage}>Current page is unavailable</option>}{policy.pages.map(page=><option key={page.id} value={page.id}>{page.title}{page.open?'':' · new comments closed'}</option>)}</select></label><p>Choose an existing public page for conversations outside a post or product. No page or review target is created automatically.</p><button type="button" disabled={busy||!changed('sitePage')} onClick={()=>save('sitePage')}>Save feedback page</button></section>
 </details>;
}
