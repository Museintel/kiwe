import React,{useEffect,useState} from 'react';
import {MediaField} from './native-media.jsx';
import {checkRecord} from './workflow-client.mjs';
import {useProductDraftState} from './native-product-dirty.jsx';
export function NutritionEditor({item,work,onDirtyChange}){
 const [record,setRecord]=useState(null),[image,setImage]=useState({id:0,url:''}),[error,setError]=useState('');const path='/products/'+item.id+'/nutrition',dirty=!!record&&image.id!==record.imageId;
 useEffect(()=>{const controller=new AbortController();setRecord(null);setError('');work.request(path,undefined,{},controller.signal).then(v=>{if(controller.signal.aborted)return;checkRecord(v,'name');setRecord(v);setImage(v.image);}).catch(e=>{if(!controller.signal.aborted)setError(e.message);});return()=>controller.abort();},[item.id,work.epoch]);
 useProductDraftState(dirty,onDirtyChange);
 async function save(e){e.preventDefault();const next=await work.mutate(path,{imageId:image.id,expected:record.expected},v=>checkRecord(v,'name'));if(next){setRecord(next);setImage(next.image);}}
 return <section className="wf-detail"><h2>Nutrition information.</h2><p>Attach the nutrition-label image for {item.name}. This uses Kiwe’s existing product label field, which your designer can connect to the public product page.</p>{error&&<p role="alert">{error}</p>}{!record&&!error&&<p role="status">Opening this product’s label…</p>}{record&&<form onSubmit={save}><fieldset disabled={work.busy||work.blocked}><MediaField label="Nutrition label" value={image.id?[image]:[]} disabled={work.busy||work.blocked||!record.canUpload} onChange={images=>setImage(images[0]||{id:0,url:''})}/><p className="wf-small">Removing the label detaches it from this product. The media file, product image and gallery remain. Your storefront must use the existing nutrition-image binding to display it.</p><div className="wf-actions"><button disabled={!dirty}>Save nutrition label</button>{dirty&&<button type="button" onClick={()=>setImage(record.image)}>Discard label edits</button>}</div></fieldset></form>}</section>;
}
