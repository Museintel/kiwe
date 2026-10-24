import {publishContextProgress} from './context-progress-event.mjs';
/** Private transport shared only by the three signed-in task screens. No polling or replay. */
export function workflowClient(config, fetcher=globalThis.fetch, origin=globalThis.location?.origin) {
  const endpoint=new URL(config.endpoint,origin);
  if(endpoint.origin!==origin||!config.nonce)throw Error('Open this workspace on your signed-in website.');
  return async function request(path='',body,query={},signal) {
    const url=new URL(endpoint);
    if(path){
      if(url.searchParams.has('rest_route'))url.searchParams.set('rest_route',url.searchParams.get('rest_route').replace(/\/$/,'')+path);
      else url.pathname=url.pathname.replace(/\/$/,'')+path;
    }
    for(const key of ['view','tab','page','id','objectId','search','sort','stock','kind','mode','operation','editorExpected','group','selectedPage','class','attribute','status','type','channel','role','author','from','to','scope','days','form','before','filter'])if(query[key]!==undefined)url.searchParams.set(key,String(query[key]));
    const controller=new AbortController(),abort=()=>controller.abort();
    signal?.addEventListener('abort',abort,{once:true});if(signal?.aborted)abort();
    const timeout=setTimeout(abort,Number.isSafeInteger(config.timeoutMs)?Math.min(60000,Math.max(15000,config.timeoutMs)):15000);
    try {
      const response=await fetcher(url.href,{method:body===undefined?'GET':'POST',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{'X-WP-Nonce':config.nonce,...(body===undefined?{}:{'Content-Type':'application/json','X-Kiwe-Mutation':'1'})},...(body===undefined?{}:{body:JSON.stringify(body)})});
      if(!response.headers.get('Content-Type')?.includes('application/json'))throw Error('The website returned an unreadable response. Your edits are still here. Check the saved version before trying again.');
      const value=await response.json();
      if(!response.ok||value?.code)throw Error(value?.message||'The request could not be confirmed. Refresh and review.');
      if(!value||typeof value!=='object'||Array.isArray(value))throw Error('The website returned an incomplete response. Refresh and review.');
      publishContextProgress(response); return value;
    } catch(error) {if(error?.name==='AbortError')throw Error('The request timed out. Check the saved version before trying again.');throw error;}
    finally {clearTimeout(timeout);signal?.removeEventListener('abort',abort);}
  };
}
export function checkRecord(value,titleKey='title') {
  if(!Number.isSafeInteger(value?.id)||value.id<1||typeof value[titleKey]!=='string'||!/^[a-f0-9]{64}$/.test(value.expected))throw Error('The saved record was incomplete. Refresh and review it.');
  return value;
}
export function checkWorkspace(value,kind) {
  if(kind==='notification'){
    if(!value.config||!Array.isArray(value.config.topics)||!Array.isArray(value.config.channels)||!Array.isArray(value.preferences?.topics)||!Array.isArray(value.preferences?.channels)||!Array.isArray(value.preferences?.inboxTopics)||!/^[a-f0-9]{64}$/.test(value.expected)||!Array.isArray(value.notifications)||value.notifications.length>40)throw Error('Notifications could not be read completely.');
  }else{
    if(!Number.isInteger(value.page)||typeof value.hasNext!=='boolean')throw Error('The work queue was incomplete.');
    const items=kind==='guest'?value.items:value.products;
    if(!Array.isArray(items)||items.length>20)throw Error('The work queue exceeded its page limit.');
    for(const item of items)checkRecord(item,kind==='guest'&&value.view!=='applications'?'title':'name');
    if(kind==='guest'&&(!Array.isArray(value.views)||!Array.isArray(value.categories)||!Array.isArray(value.submitTypes)||!Array.isArray(value.reviewTypes)||!Array.isArray(value.publishTypes)))throw Error('Guest permissions could not be read.');
    if(kind==='commerce'&&(!Array.isArray(value.orders)||value.orders.length>20||!Array.isArray(value.productCategories)||!Array.isArray(value.productTags)))throw Error('Store permissions could not be read.');
  }
  return value;
}
