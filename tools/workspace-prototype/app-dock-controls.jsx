import React from 'react';
import {ChoiceField} from './choice-field.jsx';

const placement={orientation:[['auto','Automatic'],['vertical','Vertical'],['horizontal','Horizontal']],vertical_edge:[['left','Left'],['right','Right']],vertical_position:[['center','Centre'],['bottom','Bottom']],horizontal_edge:[['top','Top'],['bottom','Bottom']],horizontal_position:[['left','Left'],['center','Centre'],['right','Right']],horizontal_vertical_position:[['center','Centre'],['bottom','Bottom']]};
export function AppDockControls({tab,draft,data,change,disabled,navigate,identityUrl}){
 const dock=draft.dock,style=draft.style;
 const toggle=(group,key,label,help)=> <label className="kiwe-app-toggle"><span><strong>{label}</strong>{help&&<small>{help}</small>}</span><input type="checkbox" role="switch" checked={!!draft[group][key]} onChange={e=>change(group,key,e.target.checked)}/></label>;
 const choice=(group,key,label,options,help)=> <ChoiceField label={label} value={draft[group][key]} options={options} onChange={value=>change(group,key,value)} help={help}/>;
 function reorder(id,offset){const order=[...dock.item_order],index=order.indexOf(id),target=index+offset;if(target<0||target>=order.length)return;[order[index],order[target]]=[order[target],order[index]];change('dock','item_order',order);}
 const items=dock.item_order.filter(id=>data.labels[id]&&(id!=='cart'||data.woo));
 return tab==='dock'?<section id="app-settings-dock" role="tabpanel" className="kiwe-settings-section">
  <h2>Dock</h2><p>Choose visitor shortcuts, their order and the one you want to highlight.</p>
  {toggle('dock','visible','Show the Dock','Header and footer actions keep working when the Dock is hidden.')}
  <fieldset className="kiwe-app-highlight"><legend>Buttons and highlight</legend><label className="kiwe-app-no-highlight"><input type="radio" name="dock-highlight" checked={!dock.focus_item} onChange={()=>change('dock','focus_item','')}/>No highlight</label>
   <div className="kiwe-app-dock-list">{items.map((id,index)=>{const unavailable=id==='cart'&&!data.woo;return <div className="kiwe-app-dock-row" key={id}><div><strong>{data.labels[id]}</strong>{id==='ai'&&<small>{data.woo?'Account, order and cart notifications.':'Account and website notifications.'}</small>}{unavailable&&<small>Available when WooCommerce is active.</small>}</div><label className="kiwe-app-highlight-choice"><input type="radio" name="dock-highlight" aria-label={'Highlight '+data.labels[id]} checked={dock.focus_item===id} disabled={disabled||unavailable||!dock.enabled_items[id]} onChange={()=>change('dock','focus_item',id)}/>Highlight</label><label className="kiwe-app-dock-visible"><span className="kiwe-app-sr">Show {data.labels[id]}</span><input type="checkbox" role="switch" checked={!!dock.enabled_items[id]} disabled={disabled||unavailable} onChange={e=>{change('dock','enabled_items',{...dock.enabled_items,[id]:e.target.checked});if(!e.target.checked&&dock.focus_item===id)change('dock','focus_item','');}}/></label><div className="kiwe-app-dock-move"><button type="button" aria-label={'Move '+data.labels[id]+' up'} disabled={disabled||index===0} onClick={()=>reorder(id,-1)}>↑</button><button type="button" aria-label={'Move '+data.labels[id]+' down'} disabled={disabled||index===items.length-1} onClick={()=>reorder(id,1)}>↓</button></div></div>;})}</div>
  </fieldset>
  <p><a href={data.navigationUrl} onClick={navigate}>Choose Dock menu groups and links in Organize → Navigation ↗</a> Each group uses its menu name.</p>
  {data.woo&&<><h3>Cart</h3>{toggle('commerce','cart_surface_enabled','Open the cart as a DSA')}{draft.commerce.cart_surface_enabled&&toggle('commerce','cart_quantity_controls','Allow cart quantity changes')}</>}
 </section>:<section id="app-settings-appearance" role="tabpanel" className="kiwe-settings-section">
  <h2>Appearance</h2><p>Choose a shared look for your Dock and DSA screens. The light / dark button is managed in the Dock tab.</p>
  {choice('style','active_theme_id','Theme',data.themes.map(theme=>[theme.id,theme.name,theme.description]))}
  <h3>Dock appearance</h3>
  {choice('dock','presentation','Presentation',[['dock','Floating Dock'],['navbar','Navigation bar']])}
  {choice('dock','shape','Shape',[['pill','Pill'],['box','Rounded box'],['square','Square']])}
  {choice('dock','material','Background',[['glass','Glass'],['solid','Solid']])}
  {dock.focus_item&&dock.presentation==='dock'&&<ChoiceField label="Highlighted button layout" value={dock.split_style?'separate':'together'} options={[[ 'together','Inside the Dock'],['separate','Separate from the other buttons']]} onChange={value=>change('dock','split_style',value==='separate')} help="Adds a gap around the highlighted button. Your selected shape applies to both parts."/>}
  <dl className="kiwe-app-palette"><div><dt>Highlight colour</dt><dd><span className="kiwe-app-colour-sample" style={{background:'var(--kiwe-color-brand)'}} aria-hidden="true"/>Brand colour</dd></div><div><dt>Hover colour</dt><dd><span className="kiwe-app-colour-sample" style={{background:'var(--kiwe-color-accent)'}} aria-hidden="true"/>Accent colour</dd></div></dl>
  <p>Colours follow your website’s brand.{identityUrl&&<> <a href={identityUrl} onClick={navigate}>Update brand colours in Design Context ↗</a></>}</p>
  <h3>DSA screens</h3>
  {choice('style','mode','Style',[['sheet','Sheet'],['classic','Full screen']])}
  {style.mode==='sheet'&&<>
   {choice('style','sheet_position','Open from',[['bottom','Bottom'],['right','Right'],['left','Left']])}
   {choice('style','sheet_animation','Movement',[['slide','Smooth'],['soft','Gentle'],['snap','Spring']])}
   {choice('style','sheet_backdrop','Behind the sheet',[['blur','Blur the page'],['fade','Dim the page']])}
   {choice('style','sheet_spacing','Spacing',[['edge','To the edge'],['inset','Inset']])}
   <ChoiceField label="Speed" value={String(style.sheet_duration_ms)} options={[[ '180','Fast'],['320','Standard'],['500','Slow'],...([180,320,500].includes(style.sheet_duration_ms)?[]:[[String(style.sheet_duration_ms),'Current custom speed']])]} onChange={value=>change('style','sheet_duration_ms',Number(value))}/>
   <div className="kiwe-app-fields"><label>{style.sheet_position==='bottom'?'Maximum height (%)':'Maximum width (pixels)'}<input type="number" min={style.sheet_position==='bottom'?45:280} max={style.sheet_position==='bottom'?96:1200} step="1" value={style[style.sheet_position==='bottom'?'sheet_max_height':'sheet_side_max_width']} onChange={e=>change('style',style.sheet_position==='bottom'?'sheet_max_height':'sheet_side_max_width',Number(e.target.value))}/></label>{style.sheet_position==='bottom'&&style.sheet_spacing==='inset'&&<label>Sheet width (%)<input type="number" min="50" max="90" step="1" value={style.sheet_width_percent} onChange={e=>change('style','sheet_width_percent',Number(e.target.value))}/></label>}</div>
  </>}
  <h3>Dock placement</h3>
  {['desktop','tablet','mobile'].map(device=><fieldset className="kiwe-app-placement" key={device}><legend>{{desktop:'Desktop',tablet:'Tablet',mobile:'Phone'}[device]}</legend>{choice('dock',device+'_orientation','Direction',placement.orientation)}{(dock[device+'_orientation']==='vertical'?['vertical_edge','vertical_position']:dock[device+'_orientation']==='horizontal'?['horizontal_edge','horizontal_position','horizontal_vertical_position']:[]).map(key=><React.Fragment key={key}>{choice('dock',device+'_'+key,({vertical_edge:'Side',vertical_position:'Position',horizontal_edge:'Edge',horizontal_position:'Alignment',horizontal_vertical_position:'Vertical position'})[key],placement[key])}</React.Fragment>)}</fieldset>)}
  {toggle('dock','hide_frontend_admin_bar','Hide the WordPress toolbar on the website','Only affects the toolbar shown to signed-in staff on public pages.')}
 </section>;
}
