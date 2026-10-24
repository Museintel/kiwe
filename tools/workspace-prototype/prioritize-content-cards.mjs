/** Stable visual priority inside a bounded WordPress result page. The query remains WordPress-owned. */
export function prioritizeContentCards(items,menus,describe){
 const membership=new Set();
 for(const menu of menus||[]){
  if(menu.usedByDsa===false)continue;
  for(const item of menu.items||[])membership.add(item.object+':'+item.objectId);
 }
 return [...(items||[])].map((item,order)=>({item,order,info:describe(item)})).sort((a,b)=>{
  const priority=entry=>Number(membership.has(entry.info.object+':'+entry.info.id))*2+Number(entry.info.indexable);
  return priority(b)-priority(a)||a.order-b.order;
 }).map(entry=>entry.item);
}
