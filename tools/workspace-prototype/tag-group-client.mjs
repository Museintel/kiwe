/** Same-site client for Kiwe-only folders around native WordPress tags. */
export function tagGroupClient(config,fetcher=globalThis.fetch,origin=globalThis.location?.origin){
 const endpoint=new URL(config.tagGroupEndpoint,origin);
 if(endpoint.origin!==origin||!config.nonce)throw new Error('Open tag groups on your signed-in website.');
 async function request(path='',body,query={},signal){
  const url=new URL(endpoint);
  if(path){if(url.searchParams.has('rest_route'))url.searchParams.set('rest_route',url.searchParams.get('rest_route').replace(/\/$/,'')+path);else url.pathname=url.pathname.replace(/\/$/,'')+path;}
  Object.entries(query).forEach(([key,value])=>url.searchParams.set(key,String(value)));
  const controller=new AbortController(),abort=()=>controller.abort();signal?.addEventListener('abort',abort,{once:true});if(signal?.aborted)controller.abort();const timeout=setTimeout(abort,15000);
  try{const response=await fetcher(url.href,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{'X-WP-Nonce':config.nonce,...(body?{'Content-Type':'application/json','X-Kiwe-Mutation':'1'}:{})},...(body?{body:JSON.stringify(body)}:{})});if(!response.headers.get('Content-Type')?.includes('application/json'))throw new Error('The website returned an unreadable response. Reload before retrying.');const value=await response.json();if(!response.ok)throw new Error(value.message||'The tag group change could not be confirmed. Reload and review.');return value;}
  finally{clearTimeout(timeout);signal?.removeEventListener('abort',abort);}
 }
 function group(value){if(!value||!Number.isSafeInteger(value.id)||value.id<1||!['post','product'].includes(value.kind)||typeof value.name!=='string'||!/^[a-f0-9]{64}$/.test(value.expected))throw new Error('The tag group response was incomplete.');return value;}
 return {
  list:async(kind,signal)=>{const value=await request('',undefined,{kind},signal);if(value?.kind!==kind||!Array.isArray(value.groups)||value.groups.length>100)throw new Error('The tag groups could not be read.');value.groups.forEach(group);return value.groups;},
  bundle:async(kind,groupId)=>{const value=await request('/bundle',undefined,{kind,groupId});if(value?.kind!==kind||value.groupId!==groupId||!Array.isArray(value.tagIds)||value.tagIds.length>100||value.tagIds.some(id=>!Number.isSafeInteger(id)||id<1)||new Set(value.tagIds).size!==value.tagIds.length)throw new Error('The tag group selection could not be confirmed. Refresh and review.');return value.tagIds;},
  tags:async(kind,groupId,page,search='',signal)=>{const value=await request('/tags',undefined,{kind,groupId,page,search},signal);if(value?.kind!==kind||value.groupId!==groupId||value.page!==page||typeof value.hasNext!=='boolean'||!Array.isArray(value.items)||value.items.length>20)throw new Error('The grouped tags could not be read.');value.items.forEach(item=>{if(!Number.isSafeInteger(item.id)||item.id<1||typeof item.name!=='string'||!Number.isInteger(item.count))throw new Error('A tag record was incomplete.');});return value;},
  create:async(kind,name)=>group(await request('',{kind,name})),
  rename:async(item,name)=>group(await request('/'+item.id,{kind:item.kind,name,expected:item.expected})),
  move:async(kind,sourceId,targetId,tagIds)=>{const value=await request('/move',{kind,sourceId,targetId,tagIds});if(!value||value.requested!==tagIds.length||!Number.isInteger(value.moved)||!Number.isInteger(value.failed)||value.moved+value.failed!==value.requested||!Array.isArray(value.outcomes)||value.outcomes.length!==value.requested)throw new Error('The tag move receipt was incomplete. Reload and review.');return value;}
 };
}
