import React,{useEffect,useState} from 'react';

export function CheckoutFields({saved,onSaved,work,disabled,onDirtyChange}){
 const [draft,setDraft]=useState(saved.checkoutFields),[notice,setNotice]=useState('');
 const dirty=JSON.stringify(draft)!==JSON.stringify(saved.checkoutFields);
 useEffect(()=>{setDraft(saved.checkoutFields);},[saved.checkoutFields]);
 useEffect(()=>{onDirtyChange(dirty);return()=>onDirtyChange(false);},[dirty]);
 async function save(event){
  event.preventDefault();if(disabled||!dirty)return;
  const next=await work.mutate('/checkout-policy',{operation:'checkoutFields',fields:draft,expected:saved.expected},value=>{
   if(!value?.checkoutFields||Object.keys(draft).some(key=>value.checkoutFields[key]!==draft[key])||typeof value.expected!=='string')throw Error('Checkout fields could not be confirmed. Reload before retrying.');return value;
  });
  if(next){onSaved(next);setNotice('Checkout fields saved.');}
 }
 return <section className="kiwe-settings-section kiwe-policy-card" aria-label="Checkout fields"><h2>Customer details</h2><p>Choose what customers provide at checkout. These are WooCommerce’s own field settings, shared with Kiwe checkout.</p><form onSubmit={save}><fieldset disabled={disabled||!saved.canEditGuestCheckout}>{Object.entries({phone:'Phone number',company:'Company',address_2:'Apartment, suite or address line 2'}).map(([key,label])=><fieldset className="kiwe-checkout-field-choice" key={key}><legend>{label}</legend><div className="kiwe-checkout-choices">{['required','optional','hidden'].map(choice=><label key={choice}><input type="radio" name={'checkout-'+key} value={choice} checked={draft[key]===choice} onChange={()=>{setDraft(old=>({...old,[key]:choice}));setNotice('');}}/>{choice==='required'?'Required':choice==='optional'?'Optional':'Hidden'}</label>)}</div></fieldset>)}<p>Name, email and delivery-address requirements follow the customer’s country, shipping method and payment provider.</p><button className="wf-primary" disabled={!dirty}>Save customer details</button></fieldset></form>{notice&&<p role="status">{notice}</p>}</section>;
}

export function CheckoutDetails({value}){
 if(!value)return <p>Checkout details are unavailable for this older cart.</p>;
 const sources={dsa:'Saved from Kiwe checkout',woocommerce:'Saved from the checkout page','store-api':'Saved from WooCommerce checkout','active-session':'Recovered from a matching active checkout session','saved-contact':'Previously saved contact'};
 const show=(fields,title)=><section><h4>{title}</h4>{fields&&Object.values(fields).some(Boolean)?<dl className="kiwe-cart-contact-details">{Object.entries({first_name:'First name',last_name:'Last name',email:'Email',phone:'Phone',company:'Company',address_1:'Street address',address_2:'Address line 2',city:'City',state:'State / region',postcode:'Postcode',country:'Country'}).map(([key,label])=>fields[key]?<React.Fragment key={key}><dt>{label}</dt><dd>{key==='phone'?<a href={'tel:'+fields[key]}>{fields[key]}</a>:key==='email'?<a href={'mailto:'+fields[key]}>{fields[key]}</a>:fields[key]}</dd></React.Fragment>:null)}</dl>:<p>No address was saved.</p>}</section>;
 return <section className="kiwe-cart-checkout-details"><h3>Customer details</h3><p>{sources[value.source]||'Saved checkout details'}{value.capturedAt?' · '+value.capturedAt:''}</p>{value.available?<>{show(value.billing,'Billing and contact')}{value.differentShipping===true?show(value.shipping,'Delivery address'):value.differentShipping===false?<p>Delivery uses the billing address.</p>:null}</>:<p>No contact or address was saved.</p>}{value.source==='saved-contact'&&<p>An address snapshot is unavailable for this older cart.</p>}<small>These details may be incomplete. A checkout visit does not mean the customer finished the form. Saved contact details do not grant permission to send marketing.</small></section>;
}
