import {publishContextProgress} from './context-progress-event.mjs';
/** Same-origin private transport for the native Identity surface. */
export function identityClient(config, fetcher = globalThis.fetch, origin = globalThis.location?.origin) {
  const endpoint = new URL(config.endpoint, origin);
  if (endpoint.origin !== origin || !config.nonce) throw new Error('Open business identity on your signed-in website.');
  async function request(body, signal) {
    const controller = new AbortController(), abort=()=>controller.abort(); signal?.addEventListener('abort',abort,{once:true}); if(signal?.aborted)controller.abort();
    const timeout=setTimeout(abort,15000);
    try {
      const response=await fetcher(endpoint.href,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{'X-WP-Nonce':config.nonce,...(body?{'Content-Type':'application/json','X-Kiwe-Mutation':'1'}:{})},...(body?{body:JSON.stringify(body)}:{})});
      if(!response.headers.get('Content-Type')?.includes('application/json'))throw new Error('The website returned an unreadable response. Your edits are still here; reload identity to review it.');
      const value=await response.json(); if(!response.ok)throw new Error(value.message||'Identity could not be confirmed. Reload and review.'); publishContextProgress(response); return value;
    }finally{clearTimeout(timeout);signal?.removeEventListener('abort',abort);}
  }
  function projection(value){
    const text=['siteName','tagline','industry','industrySector','siteType','phone','email'];
    if(!value||text.some(key=>typeof value[key]!=='string')||['logoId','logoInverseId','siteIconId','squareLogoId','roundLogoId'].some(key=>!Number.isInteger(value[key])||value[key]<0)||['logoUrl','logoInverseUrl','siteIconUrl','squareLogoUrl','roundLogoUrl','expected'].some(key=>typeof value[key]!=='string'||(key==='expected'&&!/^[a-f0-9]{64}$/.test(value[key]))))throw new Error('The identity response was incomplete. Reload and review it.');
    if(!value.activities||Object.keys(value.activities).length!==5||['commerce','blog','publication','physical_store','service_area'].some(key=>typeof value.activities[key]!=='boolean'))throw new Error('Business activities could not be confirmed. Reload and review.');
    return value;
  }
  return {read:async signal=>projection(await request(undefined,signal)),save:async(body)=>projection(await request(body))};
}
