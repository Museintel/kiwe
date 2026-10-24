import React,{useState} from 'react';
import {SeoButton} from './seo-editor.jsx';
export function chooseImages({title,multiple=false,onSelect,onError}) {
 if(!window.wp?.media){onError('The media library could not open. Reload this screen and try again.');return;}
 const frame=window.wp.media({title,button:{text:'Use selected image'+(multiple?'s':'')},library:{type:'image'},multiple});
 frame.on('select',()=>{const selected=frame.state().get('selection').toJSON().map(item=>({id:Number(item.id),url:item.sizes?.medium?.url||item.url||'',fullUrl:item.url||'',alt:item.alt||''}));onSelect(selected);});frame.open();
}
export function MediaField({label,value=[],multiple=false,disabled=false,onChange,seoEndpoint='',nonce=''}) {
 const [error,setError]=useState('');
 function choose(){chooseImages({title:label,multiple,onError:setError,onSelect:items=>{if(multiple&&items.length>20){setError('Choose up to 20 gallery images.');return;}setError('');onChange(multiple?items:items.slice(0,1));}});}
 function move(index,step){const next=[...value];[next[index],next[index+step]]=[next[index+step],next[index]];onChange(next);}
 const items=value.filter(item=>item.id>0),first=items[0];
 return <section className={'kiwe-editor-media kiwe-editor-media--'+(multiple?'gallery':'single')} aria-label={label}>
  {multiple?<><div className="kiwe-editor-media-heading"><h3>{label}</h3><button type="button" disabled={disabled} onClick={choose}>{items.length?'Change':'Add'} {label.toLowerCase()}</button></div><div className="kiwe-editor-images">{items.map((item,index)=><div className="kiwe-editor-image" key={item.id}><img src={item.url} alt={label+' '+(index+1)}/><div className="kiwe-editor-image-actions"><button type="button" aria-label={'Move image '+(index+1)+' earlier'} disabled={disabled||index===0} onClick={()=>move(index,-1)}>←</button><button type="button" aria-label={'Move image '+(index+1)+' later'} disabled={disabled||index===items.length-1} onClick={()=>move(index,1)}>→</button><SeoButton endpoint={seoEndpoint} nonce={nonce} scope="media" id={item.id}/><button type="button" disabled={disabled} aria-label={'Remove '+label+' '+(index+1)} onClick={()=>onChange(value.filter((_,i)=>i!==index))}>Remove</button></div></div>)}</div></>:<><div className="kiwe-editor-media-preview">{first?<img src={first.url} alt={label}/>:<span aria-hidden="true">＋</span>}</div><div className="kiwe-editor-media-main"><h3>{label}</h3><div className="kiwe-editor-media-actions"><button type="button" disabled={disabled} aria-label={(first?'Change ':'Add ')+label.toLowerCase()} onClick={choose}>{first?'Change image':'Add image'}</button>{first&&<><SeoButton endpoint={seoEndpoint} nonce={nonce} scope="media" id={first.id}/><button type="button" disabled={disabled} aria-label={'Remove '+label+' 1'} onClick={()=>onChange([])}>Remove</button></>}</div></div></>}
  {error&&<p role="alert">{error}</p>}
 </section>;
}
export function InlineImage({disabled,onInsert}){
 const [error,setError]=useState('');
 const escape=value=>String(value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
 return <div><button type="button" disabled={disabled} onClick={()=>chooseImages({title:'Image in article',onError:setError,onSelect:items=>{const item=items[0];if(!item)return;let url;try{url=new URL(item.fullUrl);}catch{return;}if(!['https:','http:'].includes(url.protocol))return;setError('');onInsert('<figure class="wp-block-image"><img class="wp-image-'+item.id+'" src="'+escape(url.href)+'" alt="'+escape(item.alt)+'" /></figure>');}})}>Add image to article</button>{error&&<p role="alert">{error}</p>}</div>;
}
