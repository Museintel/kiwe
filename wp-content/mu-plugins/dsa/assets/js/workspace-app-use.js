(function(){
 'use strict';
 const config=window.KIWE_WORKSPACE_APP_USE;
 if(!config||!config.endpoint||!config.nonce)return;
 let last=0;
 function record(){
  if(document.visibilityState==='hidden'||!((window.matchMedia&&window.matchMedia('(display-mode: standalone)').matches)||navigator.standalone===true))return;
  const now=Date.now(),key='kiwe_workspace_app_use:'+config.actor;
  try{last=Math.max(last,Number(sessionStorage.getItem(key))||0);}catch(error){}
  if(now-last<300000)return;
  let endpoint;try{endpoint=new URL(config.endpoint,location.href);if(endpoint.origin!==location.origin)return;}catch(error){return;}
  let visitorId='';try{visitorId=localStorage.getItem('dsa_permission_visitor')||'';}catch(error){}
  if(!/^[a-zA-Z0-9_-]{8,80}$/.test(visitorId)){
   if(!window.crypto||!window.crypto.getRandomValues)return;
   visitorId='p'+Array.from(window.crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');
   try{localStorage.setItem('dsa_permission_visitor',visitorId);}catch(error){}
  }
  last=now;try{sessionStorage.setItem(key,String(now));}catch(error){}
  const platform=/iPad|iPhone|iPod/i.test(navigator.userAgent||'')?'ios':/android/i.test(navigator.userAgent||'')?'android':'desktop';
  fetch(endpoint.href,{method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,headers:{'Content-Type':'application/json','X-WP-Nonce':config.nonce,'X-Kiwe-Mutation':'1'},body:JSON.stringify({event:'opened',visitorId,platform})}).then(response=>{if(!response.ok)throw Error('Unavailable');return response.json();}).then(value=>{if(value.ok!==true)throw Error('Unconfirmed');}).catch(()=>{if(last===now)last=0;try{if(sessionStorage.getItem(key)===String(now))sessionStorage.removeItem(key);}catch(error){}});
 }
 record();window.addEventListener('pageshow',record);document.addEventListener('visibilitychange',record);
})();
