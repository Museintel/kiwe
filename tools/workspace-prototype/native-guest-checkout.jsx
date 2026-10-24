import React,{useState} from 'react';

export function GuestCheckout({saved,onSaved,work,disabled}){
 const [notice,setNotice]=useState('');
 async function change(enabled){
  setNotice('');
  const next=await work.mutate('/checkout-policy',{operation:'guestCheckout',enabled,expected:saved.expected},value=>{
   if(typeof value?.guestCheckout!=='boolean'||value.guestCheckout!==enabled||typeof value.expected!=='string')throw Error('Guest checkout could not be confirmed. Reload before retrying.');
   return value;
  });
  if(next){onSaved(next);setNotice(enabled?'Guest checkout is on. Shoppers can order without creating an account.':'Guest checkout is off. Shoppers must sign in and verify their account.');}
 }
 return <article className="kiwe-policy-card" aria-label="Guest checkout"><h2>Let guests check out.</h2><p>Customers can order without creating an account. This is your WooCommerce guest-checkout setting.</p>{saved.canEditGuestCheckout?<label className="kiwe-policy-check"><input type="checkbox" checked={saved.guestCheckout} disabled={disabled} onChange={event=>change(event.target.checked)}/>Allow checkout without an account</label>:<p>{saved.guestCheckout?'Guest checkout is on.':'Customers must sign in to check out.'} Your site owner manages this choice.</p>}{saved.verifiedContactRequired&&<p role="status">A store integration requires verified contact before purchasing, including when guest checkout is on.</p>}{notice&&<p role="status">{notice}</p>}</article>;
}
