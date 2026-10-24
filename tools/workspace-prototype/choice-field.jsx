import React from 'react';
import './choice-field.css';

/** Small, finite settings choices stay visible without opening a dropdown. */
export function ChoiceField({label,value,options,onChange,disabled=false,help}){
 const name=React.useId();
 return <fieldset className="kiwe-choice-field" disabled={disabled}><legend>{label}</legend>{help&&<p>{help}</p>}<div className="kiwe-choice-options">{options.map(([id,text,hint])=><label key={id}><input type="radio" name={name} value={id} checked={value===id} onChange={()=>onChange(id)}/><span>{text}{hint&&<small>{hint}</small>}</span></label>)}</div></fieldset>;
}
