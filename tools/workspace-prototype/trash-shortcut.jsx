import React from 'react';
/** A native type-specific recovery entrance, shown only for admitted types. */
export function TrashShortcut({config,type,disabled=false,onNavigate}){const url=config.trashUrls?.[type];return url?<a className="kiwe-secondary-action kiwe-trash-shortcut" href={url} aria-disabled={disabled||undefined} onClick={event=>{if(disabled)event.preventDefault();else onNavigate?.(event);}}>Trash ↗</a>:null;}
