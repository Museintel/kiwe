import React,{useEffect,useRef} from 'react';

/** Native modal: bounded detail, Escape/Close guard and browser-owned focus trap. */
export function WorkspaceDialog({title,children,onClose,busy=false,dirty=false,className=''}){
 const dialog=useRef(null),opener=useRef(null);
 useEffect(()=>{opener.current=document.activeElement;dialog.current?.showModal();return()=>{dialog.current?.close();opener.current?.isConnected&&opener.current.focus();};},[]);
 useEffect(()=>{const modal=dialog.current;if(!busy&&modal?.open&&!modal.contains(document.activeElement))modal.querySelector('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),a[href]')?.focus();},[busy,title]);
 function close(){if(busy)return;if(dirty&&!window.confirm('Discard unsaved changes in this window?'))return;onClose();}
 return <dialog ref={dialog} className={'kiwe-workspace-dialog '+className} aria-label={title} onCancel={event=>{event.preventDefault();close();}}><div className="kiwe-dialog-heading"><h2>{title}</h2><button type="button" disabled={busy} onClick={close}>Close</button></div><div className="kiwe-dialog-body">{children}</div></dialog>;
}
