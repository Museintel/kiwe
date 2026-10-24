import React,{useEffect,useRef,useState} from 'react';

/** WordPress owns this classic editor; React owns only its empty mounting node. */
export function LegacyVisualEditor({initial,onTouch,readContent,disabled,editorId='kiwe-product-description',label='Product description',heading='Description',help='Format text visually. Use Code only when preserving a special shortcode or custom layout.',toolbar='formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo'}){
 const host=useRef(null),touched=useRef(onTouch),[ready,setReady]=useState(false);
 touched.current=onTouch;
 useEffect(()=>{
  const node=host.current,api=window.wp?.editor;
  if(!node||typeof api?.initialize!=='function'||typeof api?.getContent!=='function'||typeof api?.remove!=='function'||!window.tinymce)return;
  const id=editorId,textarea=document.createElement('textarea');
  textarea.id=id;textarea.value=initial;textarea.rows=10;textarea.setAttribute('aria-label',label);node.append(textarea);
  const sourceChanged=()=>touched.current();textarea.addEventListener('input',sourceChanged);
  try {
   api.initialize(id,{tinymce:{wpautop:false,toolbar1:toolbar,toolbar2:'',setup(editor){editor.on('input change undo redo',()=>touched.current());}},quicktags:true,mediaButtons:false});
   readContent.current=()=>api.getContent(id);
   setReady(true);
  } catch(error){textarea.removeEventListener('input',sourceChanged);textarea.remove();return;}
  return ()=>{readContent.current=null;textarea.removeEventListener('input',sourceChanged);api.remove(id);textarea.remove();};
 },[initial,readContent,editorId,label,toolbar]);
 useEffect(()=>{const editor=window.tinymce?.get(editorId);if(editor?.setMode)editor.setMode(disabled?'readonly':'design');},[disabled,ready,editorId]);
 return <section className="wf-visual-editor" aria-label={label}><h3>{heading}</h3><p className="wf-small">{help}</p><div ref={host}/>{!ready&&<p className="wf-notice" role="alert">The WordPress visual editor is unavailable. Reload before saving content.</p>}</section>;
}
