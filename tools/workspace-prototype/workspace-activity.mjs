/** Visit receipts are presentation state, never a business record or a subscription. */
const visiting=new Set();
export function activitySection(url=globalThis.location?.href){
  try{const q=new URL(url).searchParams,page=q.get('page');if(page==='kiwe-store')return q.get('task')==='saved-carts'?'saved-carts':(['orders','products','saved-carts'].includes(q.get('area'))?q.get('area'):'');
    if(page==='kiwe-team')return q.get('team_view')==='accounts'?'users':'';
    if(page==='kiwe-guests')return !q.has('area')||q.get('area')==='daily'?'guests':'';
    if(page==='kiwe-notifications'&&q.get('view')==='campaigns')return 'campaigns';
    if(page==='kiwe-app-settings'&&q.get('app_view')==='activity')return 'app';
    return {'kiwe-posts':'posts','kiwe-pages':'pages','kiwe-services':'services','kiwe-templates':'templates','kiwe-context-library':'media','kiwe-feedback':'feedback','kiwe-forms':'forms'}[page]||'';
  }catch{return '';}
}
export function recordWorkspaceVisit(response,renderedSection){
  if(typeof document==='undefined'||document.visibilityState==='hidden')return;
  try{
    const raw=response.headers?.get?.('X-Kiwe-Activity');if(!raw||raw.length>8192)return;
    const value=JSON.parse(atob(raw)),section=typeof renderedSection==='string'?renderedSection:activitySection(),snapshot=value.snapshots?.[section];
    if(!section||!Number.isSafeInteger(value.actor)||value.actor<1||!snapshot||!Number.isSafeInteger(snapshot.count)||snapshot.count<1||typeof value.nonce!=='string')return;
    const endpoint=new URL(value.endpoint,location.origin);if(endpoint.origin!==location.origin)return;
    const key=[value.actor,section,snapshot.realm,snapshot.epoch,snapshot.ceiling].join(':');if(visiting.has(key))return;
    visiting.add(key);
    // Wait until the successful native response has had a chance to render.
    requestAnimationFrame(()=>requestAnimationFrame(()=>{
      if(document.visibilityState==='hidden'){visiting.delete(key);return;}
      fetch(endpoint.href,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'Content-Type':'application/json','X-WP-Nonce':value.nonce,'X-Kiwe-Mutation':'1'},body:JSON.stringify(snapshot)})
        .then(async result=>{const receipt=await result.json();if(!result.ok||receipt.seen!==true)throw Error('Visit was not confirmed.');window.dispatchEvent(new CustomEvent('kiwe:workspace-seen',{detail:{actor:value.actor,section}}));})
        .catch(()=>visiting.delete(key));
    }));
  }catch{} // A failed badge update must not discard or replay a user's work.
}
