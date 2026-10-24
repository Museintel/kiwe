/** Same-origin private transport for Categories and service groups. */
export function taxonomyClient(config, fetcher = globalThis.fetch, origin = globalThis.location?.origin) {
  const endpoint = new URL(config.endpoint, origin);
  if (endpoint.origin !== origin || !config.nonce) throw new Error('Open content groups on your signed-in website.');
  async function request(path='', body, query, signal) {
    const url = new URL(endpoint);
    if (path) {
      if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + path);
      else url.pathname = url.pathname.replace(/\/$/, '') + path;
    }
    for (const key of ['page','search','objectsPage','targetsPage','targetSearch']) if (query?.[key] !== undefined) url.searchParams.set(key, query[key]);
    const controller = new AbortController(), abort=()=>controller.abort(); signal?.addEventListener('abort',abort,{once:true}); if(signal?.aborted)controller.abort(); const timeout=setTimeout(abort,15000);
    try { const response=await fetcher(url.href,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{'X-WP-Nonce':config.nonce,...(body?{'Content-Type':'application/json','X-Kiwe-Mutation':'1'}:{})},...(body?{body:JSON.stringify(body)}:{})}); if(!response.headers.get('Content-Type')?.includes('application/json'))throw new Error('The website returned an unreadable response. Your edits are still here; reload before retrying.'); const value=await response.json(); if(!response.ok)throw new Error(value.message||'The request could not be confirmed. Reload and review.'); return value; }
    finally { clearTimeout(timeout); signal?.removeEventListener('abort',abort); }
  }
  const definition=value=>{if(!value||typeof value.id!=='string'||!value.id||typeof value.label!=='string'||!['post','product','service'].includes(value.objectType)||typeof value.postType!=='string'||typeof value.hierarchical!=='boolean'||value.canManage!==true)throw new Error('The content group definition was incomplete. Reload the workspace.');return value;};
  const term=value=>{if(!value||!Number.isSafeInteger(value.id)||value.id<1||typeof value.taxonomy!=='string'||typeof value.name!=='string'||typeof value.slug!=='string'||!Number.isInteger(value.parent)||typeof value.description!=='string'||!Number.isInteger(value.count)||typeof value.indexable!=='boolean'||!['post','product','service'].includes(value.objectType)||typeof value.label!=='string'||!/^[a-f0-9]{64}$/.test(value.expected))throw new Error('The group response was incomplete. Reload and review it.');return value;};
  const listing=value=>{if(!value||!Array.isArray(value.items)||value.items.length>20||typeof value.page!=='number'||typeof value.has_next!=='boolean'||typeof value.search!=='string'||!value.taxonomy||!Array.isArray(value.menus)||value.menus.length>10)throw new Error('The group list was incomplete. Try loading it again.');definition(value.taxonomy);value.items.forEach(term);if(new Set(value.items.map(item=>item.id)).size!==value.items.length)throw new Error('The group list contained repeated records.');for(const menu of value.menus)if(!Number.isSafeInteger(menu.id)||!Array.isArray(menu.items)||!/^[a-f0-9]{64}$/.test(menu.expected))throw new Error('The DSA menu list was incomplete.');return value;};
  const object=value=>{if(!value||!Number.isSafeInteger(value.id)||value.id<1||typeof value.title!=='string'||typeof value.status!=='string'||typeof value.type!=='string'||typeof value.excerpt!=='string'||typeof value.modifiedGmt!=='string'||!/^[a-f0-9]{64}$/.test(value.expected))throw new Error('The assigned item response was incomplete. Reload and review it.');return value;};
  const detail=value=>{if(!value||!value.term||!Array.isArray(value.objects)||value.objects.length>20||!Array.isArray(value.targets)||typeof value.objects_has_next!=='boolean'||!Number.isInteger(value.objects_page)||value.objects_page<1||!Number.isInteger(value.targets_page)||value.targets_page<1||typeof value.targets_search!=='string'||typeof value.targets_has_next!=='boolean'||value.targets.length>20)throw new Error('The group detail was incomplete. Reload and review it.');term(value.term);value.objects.forEach(object);value.targets.forEach(term);return value;};
  return {
    definitions:async signal=>{const value=await request('',undefined,undefined,signal);if(!value||!Array.isArray(value.taxonomies)||value.taxonomies.length>20)throw new Error('The content group list was incomplete.');value.taxonomies.forEach(definition);return value.taxonomies;},
    list:async(taxonomy,query,signal)=>listing(await request('/'+encodeURIComponent(taxonomy),undefined,query,signal)),
    detail:async(taxonomy,id,query,signal)=>{if(!Number.isSafeInteger(id)||id<1)throw new Error('Choose an available group.');return detail(await request('/'+encodeURIComponent(taxonomy)+'/'+id,undefined,query,signal));},
    create:async(taxonomy,name,parent)=>term(await request('/'+encodeURIComponent(taxonomy),{name,parent:parent||0})),
    rename:async(termValue,name)=>term(await request('/'+encodeURIComponent(termValue.taxonomy)+'/'+termValue.id,{operation:'rename',name,expected:termValue.expected})),
    indexing:async(termValue,indexable)=>term(await request('/'+encodeURIComponent(termValue.taxonomy)+'/'+termValue.id,{operation:'indexing',indexable,expected:termValue.expected})),
    menu:async(termValue,menu,included)=>term(await request('/'+encodeURIComponent(termValue.taxonomy)+'/'+termValue.id,{operation:'menu',included,menuId:menu.id,menuExpected:menu.expected,expected:termValue.expected})),
    move:async(termValue,targetId,objectIds)=>{const value=await request('/'+encodeURIComponent(termValue.taxonomy)+'/'+termValue.id+'/move',{targetId,objectIds});if(!value||value.requested!==new Set(objectIds).size||!Number.isInteger(value.moved)||value.moved<0||!Number.isInteger(value.failed)||value.failed<0||value.moved+value.failed!==value.requested||!Array.isArray(value.outcomes)||value.outcomes.length!==value.requested)throw new Error('The move outcome was incomplete. Reload and review before retrying.');return value;}
  };
}
