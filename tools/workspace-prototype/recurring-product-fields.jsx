import React from 'react';

export function recurringDraft(terms){
  if(terms===undefined||terms===null)return terms;
  return {
    renewalPrice:Number.isSafeInteger(terms.renewalMinor)?(terms.renewalMinor/100).toFixed(2):'',
    intervalMonths:String(terms.intervalMonths??''),
    firstTermMonths:String(terms.firstTermMonths??''),
  };
}

export function recurringPayload(draft,saved){
  if(draft===undefined||JSON.stringify(draft)===JSON.stringify(recurringDraft(saved)))return {};
  if(draft===null)return {subscriptionTerms:null};
  const price=/^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(draft.renewalPrice);
  const intervalMonths=Number(draft.intervalMonths);
  const firstTermMonths=Number(draft.firstTermMonths);
  const renewalMinor=price?Number(price[1])*100+Number((price[2]||'').padEnd(2,'0')):0;
  if(!renewalMinor||![intervalMonths,firstTermMonths].every(months=>Number.isInteger(months)&&months>=1&&months<=12)){
    throw Error('Enter a renewal price above ₹0 and billing periods between one and twelve months.');
  }
  return {subscriptionTerms:{renewalMinor,intervalMonths,firstTermMonths}};
}

export function RecurringProductFields({value,onChange,canSet,variation=false}){
  if(value===undefined)return null;
  return <fieldset className="kiwe-settings-section">
    <legend>Billing</legend>
    <label>Purchase type
      <select value={value?'recurring':'one-time'} onChange={event=>onChange(
        event.target.value==='recurring'?{renewalPrice:'',intervalMonths:'1',firstTermMonths:'1'}:null,
      )}>
        <option value="one-time">One-time purchase</option>
        <option value="recurring" disabled={!canSet&&!value}>Recurring subscription</option>
      </select>
    </label>
    {!canSet&&<p className="wf-small">{variation?'Keep this variation virtual and disabled to set recurring terms.':'Set the product to Virtual in Delivery and keep it as a draft to set recurring terms.'}</p>}
    {value&&<>
      <p className="wf-small">The checkout price covers the first term. The renewal price applies after that term ends.</p>
      <label>Renewal price (₹)<input inputMode="decimal" pattern="[0-9]{1,7}([.][0-9]{1,2})?" required value={value.renewalPrice} onChange={event=>onChange({...value,renewalPrice:event.target.value})}/></label>
      <label>First term (months)<input type="number" min="1" max="12" step="1" required value={value.firstTermMonths} onChange={event=>onChange({...value,firstTermMonths:event.target.value})}/></label>
      <label>Renew every (months)<input type="number" min="1" max="12" step="1" required value={value.intervalMonths} onChange={event=>onChange({...value,intervalMonths:event.target.value})}/></label>
    </>}
  </fieldset>;
}
