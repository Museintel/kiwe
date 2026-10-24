import React,{useEffect,useState} from 'react';
const cards=[
 {key:'cart_show_delivery',title:'Show delivery details in Cart',summary:'Off by default. Show delivery and the full current total below products near Checkout. Product totals at the top always exclude delivery.'},
 {key:'cart_show_fbt',title:'Show frequently bought together',summary:'On by default. Show available companions and related products configured for the items in the cart. Nothing appears when there are no suitable products.'}
];
function checked(value){if(typeof value?.canEdit!=='boolean'||!value.switches||!/^[a-f0-9]{64}$/.test(value.expected)||cards.some(c=>typeof value.switches[c.key]!=='boolean'))throw Error('Cart display settings were incomplete. Reload before making a change.');return value;}
export function OfferPolicy({work,onClose}){
 const [record,setRecord]=useState(null),[error,setError]=useState('');
 useEffect(()=>{const controller=new AbortController();setRecord(null);setError('');work.request('/offer-policy',undefined,{},controller.signal).then(v=>{if(!controller.signal.aborted)setRecord(checked(v));}).catch(e=>{if(!controller.signal.aborted)setError(e.message);});return()=>controller.abort();},[work.epoch]);
 async function toggle(key,value){const next=await work.mutate('/offer-policy',{key,value,expected:record.expected,acceptEffect:true},checked);if(next)setRecord(next);}
 const busy=work.busy||work.blocked;
 return <section className="wf-detail"><button disabled={busy} onClick={onClose}>Back to store</button><h2>Cart, on your terms.</h2><p>Choose what customers see in Cart. Add companions and set pair discounts in each product’s Cart offers &amp; discounts task; they become available automatically.</p>{error&&<p role="alert">{error}</p>}{!record&&!error&&<p role="status">Reading Cart display settings…</p>}{record&&<><div className="kiwe-settings-sections">{cards.map(card=><article className="kiwe-settings-section" key={card.key}><h3>{card.title}</h3><p>{card.summary}</p>{record.canEdit?<label className="wf-choice"><input type="checkbox" checked={record.switches[card.key]} disabled={busy} onChange={e=>toggle(card.key,e.target.checked)}/>{card.title}</label>:<p>{record.switches[card.key]?'On':'Off'}</p>}</article>)}</div>{!record.canEdit&&<p className="wf-notice">Your client Administrator manages Cart display. You can prepare companions and discounts inside each product.</p>}</>}</section>;
}
