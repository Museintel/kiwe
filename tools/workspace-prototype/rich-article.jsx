import React,{useEffect,useRef,useState} from 'react';
import {chooseImages} from './native-media.jsx';
import {SeoButton} from './seo-editor.jsx';
import {WorkspaceDialog} from './workspace-dialog.jsx';

let underlineRegistered=false;
function enableWordPressFormats(){
 const rich=window.wp?.richText,editor=window.wp?.blockEditor;
 if(underlineRegistered||!rich?.registerFormatType||!rich?.toggleFormat||!editor?.RichTextToolbarButton)return;
 if(rich.getFormatType?.('kiwe/underline')){underlineRegistered=true;return;}
 rich.registerFormatType('kiwe/underline',{title:'Underline',tagName:'u',className:null,edit:({isActive,value,onChange})=><editor.RichTextToolbarButton icon="editor-underline" title="Underline" shortcut={{type:'primary',character:'u'}} isActive={isActive} onClick={()=>onChange(rich.toggleFormat(value,{type:'kiwe/underline'}))}/>});
 underlineRegistered=true;
}

function InsertControls({disabled,canUpload,fallback,onError,seoEndpoint,nonce}){
 const api=window.wp,blocks=api.blocks,data=api.data;
 const [level,setLevel]=useState(2),[open,setOpen]=useState(false);
 // Inside BlockEditorProvider these hooks see its own registry and insertion point.
 const point=data?.useSelect?data.useSelect(select=>select('core/block-editor')?.getBlockInsertionPoint?.(),[]):null;
 const selectedImage=data?.useSelect?data.useSelect(select=>{const block=select('core/block-editor')?.getSelectedBlock?.();return block?.name==='core/image'?Number(block.attributes?.id||0):0;},[]):0;
 const actions=data?.useDispatch?data.useDispatch('core/block-editor'):null;
 const insert=block=>{actions?.insertBlock?actions.insertBlock(block,point?.index,point?.rootClientId):fallback(block);setOpen(false);};
 function image(){chooseImages({title:'Image inside article',onError,onSelect:items=>{const item=items[0];if(!item)return;let url;try{url=new URL(item.fullUrl);}catch{onError('That image has no usable website address.');return;}if(!['https:','http:'].includes(url.protocol)){onError('That image has no usable website address.');return;}onError('');insert(blocks.createBlock('core/image',{id:item.id,url:url.href,alt:item.alt||''}));}});}
 return <div className="native-rich-actions"><button type="button" disabled={disabled} aria-haspopup="dialog" onClick={()=>setOpen(true)}>+ Insert</button>{selectedImage>0&&<SeoButton endpoint={seoEndpoint} nonce={nonce} scope="media" id={selectedImage}/>} {open&&<WorkspaceDialog title="Insert into article" onClose={()=>setOpen(false)}><div className="native-block-choices" role="group" aria-label="Article blocks"><button type="button" onClick={()=>insert(blocks.createBlock('core/paragraph'))}>Paragraph</button><button type="button" onClick={()=>insert(blocks.createBlock('core/list'))}>List</button><button type="button" onClick={()=>insert(blocks.createBlock('core/quote'))}>Quote</button><button type="button" onClick={()=>insert(blocks.createBlock('core/table'))}>Table</button><div className="native-heading-choice"><label>Heading level<select aria-label="Heading level" value={level} onChange={event=>setLevel(Number(event.target.value))}>{[2,3,4,5,6].map(value=><option key={value} value={value}>H{value}</option>)}</select></label><button type="button" onClick={()=>insert(blocks.createBlock('core/heading',{level}))}>Heading</button></div>{canUpload&&<button type="button" onClick={()=>{setOpen(false);image();}}>Image in article</button>}</div></WorkspaceDialog>}</div>;
}

function FormattingControls({disabled,onError}){
 const [linkOpen,setLinkOpen]=useState(false),[linkUrl,setLinkUrl]=useState(''),selectionRef=useRef(null);
 function findSelection(){
  const canvas=document.querySelector('.native-rich-canvas');
  const iframe=canvas?.querySelector('iframe');
  for(const doc of [iframe?.contentDocument,document].filter(Boolean)){
   const selection=doc.getSelection?.(),node=selection?.anchorNode,element=node?.nodeType===1?node:node?.parentElement;
   const editable=element?.closest?.('[contenteditable="true"]');
   if(selection?.rangeCount&&editable&&(doc===document?canvas?.contains(editable):iframe?.contentDocument?.body?.contains(editable)))return {doc,range:selection.getRangeAt(0).cloneRange(),editable};
  }
  return null;
 }
 function remember(){selectionRef.current=findSelection()||selectionRef.current;}
 function apply(command,value){
  if(disabled)return;
  const target=findSelection()||selectionRef.current;
  if(!target){onError('Place the cursor in your article or select some text first.');return;}
  try{
   target.editable.focus();
   const selection=target.doc.getSelection();selection.removeAllRanges();selection.addRange(target.range);
   if(!target.doc.execCommand(command,false,value||null))throw Error('The formatting action was unavailable.');
   target.editable.dispatchEvent(new (target.doc.defaultView?.Event||Event)('input',{bubbles:true}));
   onError('');selectionRef.current=findSelection();
  }catch{onError('That formatting could not be applied. Select the text and try again.');}
 }
 function link(event){
  event.preventDefault();
  let url;try{url=new URL(linkUrl.trim());if(!['https:','http:'].includes(url.protocol))throw Error();}catch{onError('Enter a complete http or https link.');return;}
  apply('createLink',url.href);setLinkOpen(false);setLinkUrl('');
 }
 return <div className="native-format-tools" role="toolbar" aria-label="Format article text">
  {[['bold','B','Bold'],['italic','I','Italic'],['underline','U','Underline'],['strikeThrough','S','Strikethrough']].map(([command,label,title])=><button key={command} type="button" title={title} aria-label={title} className={'native-format-'+command} disabled={disabled} onMouseDown={event=>event.preventDefault()} onClick={()=>apply(command)}>{label}</button>)}
  <button type="button" disabled={disabled} onMouseDown={event=>{event.preventDefault();remember();}} onClick={()=>setLinkOpen(value=>!value)}>Link</button>
  <button type="button" disabled={disabled} onMouseDown={event=>event.preventDefault()} onClick={()=>apply('unlink')}>Remove link</button>
  {linkOpen&&<div className="native-format-link" onKeyDown={event=>{if(event.key==='Enter')link(event);}}><label>Link address<input type="url" placeholder="https://…" value={linkUrl} onChange={event=>setLinkUrl(event.target.value)}/></label><button type="button" onClick={link}>Apply link</button><button type="button" onClick={()=>setLinkOpen(false)}>Cancel</button></div>}
 </div>;
}

/** WordPress owns the block grammar; its editor assets load only on Posts. */
export function RichArticle({content,onChange,disabled,canUpload,seoEndpoint,nonce}){
 const api=window.wp||{},blocksApi=api.blocks||{},editorApi=api.blockEditor||{};
 enableWordPressFormats();
 const ready=typeof blocksApi.parse==='function'&&typeof blocksApi.serialize==='function'&&typeof blocksApi.createBlock==='function'&&!!editorApi.BlockEditorProvider&&!!editorApi.BlockTools&&!!editorApi.BlockList&&!!editorApi.WritingFlow;
 const blank=()=>[blocksApi.createBlock('core/paragraph')];
 const [blocks,setBlocks]=useState(()=>ready?(content?blocksApi.parse(content):blank()):[]),[error,setError]=useState(''),emitted=useRef(null);
 useEffect(()=>{if(ready&&content!==emitted.current)setBlocks(content?blocksApi.parse(content):blank());},[content,ready]);
 if(!ready)return <div className="native-article-unavailable" role="alert">The visual article editor could not load. Reload this screen before editing or saving the article.</div>;
 const commit=next=>{if(disabled)return;setBlocks(next);const emptyParagraph=next.length===1&&next[0].name==='core/paragraph'&&!next[0].attributes?.content&&!next[0].innerBlocks?.length;const serialized=emptyParagraph?'':blocksApi.serialize(next);emitted.current=serialized;
  // Gutenberg can serialize a parsed legacy article differently on mount.
  // That normalization is not a writer edit and must not dirty the journey.
  const original=content?blocksApi.serialize(blocksApi.parse(content)):'';
  if(serialized!==content&&serialized!==original)onChange(serialized);
 };
 const append=block=>commit([...blocks,block]);
 return <section className="native-rich-article" aria-label="Article content"><div className="native-rich-head"><div><h3>Write your article</h3><p>Write with WordPress blocks and format text as you go.</p></div></div><editorApi.BlockEditorProvider value={blocks} onInput={commit} onChange={commit} settings={{allowedBlockTypes:['core/paragraph','core/heading','core/image','core/gallery','core/list','core/quote','core/pullquote','core/table','core/buttons','core/code','core/preformatted','core/separator','core/freeform'],hasFixedToolbar:true}}><div className="native-article-toolbar"><FormattingControls disabled={disabled} onError={setError}/><InsertControls disabled={disabled} canUpload={canUpload} fallback={append} onError={setError} seoEndpoint={seoEndpoint} nonce={nonce}/></div><div className="native-rich-canvas" inert={disabled?'':undefined}><editorApi.BlockTools><editorApi.WritingFlow className="editor-styles-wrapper"><editorApi.BlockList/></editorApi.WritingFlow></editorApi.BlockTools></div></editorApi.BlockEditorProvider>{error&&<p role="alert">{error}</p>}</section>;
}
