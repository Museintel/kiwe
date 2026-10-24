import React from 'react';

/** Independent context membership uses the same accessible switch as record cards. */
export function ContextMark({label,selected,disabled,onChange}){
 return <button type="button" className="kiwe-card-switch" role="switch" aria-checked={!!selected} disabled={disabled} onClick={()=>onChange(!selected)}><span>{label}</span><span className="kiwe-card-switch-track" aria-hidden="true"/></button>;
}
