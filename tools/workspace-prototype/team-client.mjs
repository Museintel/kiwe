import {publishContextProgress} from './context-progress-event.mjs';
// WordPress localizes scalar bootstrap values as strings, including a missing ID as "0".
export function initialAccountId(value) {
  const id = typeof value === 'string' || typeof value === 'number' ? Number(value) : 0;
  return Number.isSafeInteger(id) && id > 0 ? id : 0;
}
/** Same-origin private transport. A failed mutation is never automatically retried. */
export function teamClient(config, fetcher = globalThis.fetch, origin = globalThis.location?.origin) {
  const endpoint = new URL(config.endpoint, origin);
  if (endpoint.origin !== origin || !config.nonce) throw new Error('Open the team workspace on your signed-in website.');
  async function request(id = '', body, query, signal) {
    const url = new URL(endpoint);
    // rest_url can use ?rest_route= on installations without pretty permalinks.
    if (id) {
      if (!Number.isSafeInteger(id) || id < 1) throw new Error('Choose an available person.');
      if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + '/' + id);
      else url.pathname = url.pathname.replace(/\/$/, '') + '/' + id;
    }
    for (const key of ['team_page', 'team_search', 'team_view','team_sort','team_role','team_app','team_account','profile_section','account_task','recipient_search']) if (query?.[key] !== undefined) url.searchParams.set(key, query[key]);
    const controller = new AbortController();
    const abort = () => controller.abort();
    signal?.addEventListener('abort', abort, {once:true});
    if (signal?.aborted) controller.abort();
    const timeout = setTimeout(abort, body?.operation==='remove_account' ? 30000 : 15000);
    try {
      const response = await fetcher(url.href, {method:body ? 'POST' : 'GET', credentials:'same-origin', cache:'no-store', redirect:'error', signal:controller.signal, headers:{'X-WP-Nonce':config.nonce, ...(body ? {'Content-Type':'application/json','X-Kiwe-Mutation':'1'} : {})}, ...(body ? {body:JSON.stringify(body)} : {})});
      if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('The website returned an unreadable response. Reload this person to review their current details.');
      const value = await response.json();
      if (!response.ok) throw new Error(value.message || 'The request could not be confirmed. Reload and review.');
      publishContextProgress(response,query?.team_view==='accounts'?'users':''); return value;
    } finally { clearTimeout(timeout); signal?.removeEventListener('abort', abort); }
  }
  function person(value,id) {
    const wordpressKeys=['firstName','lastName','nickname','email','website','bio','locale','username','registered'],kiweKeys=['teamTitle','teamMember','teamOrder','founderTitle','linkedin','facebook','avatarUrl'],addressKeys=['first_name','last_name','company','address_1','address_2','city','state','postcode','country','phone','email'];
    const section=(object,keys)=>object&&Object.keys(object).sort().join(',')===keys.slice().sort().join(',')&&keys.every(key=>typeof object[key]==='string');
    const profile=value?.profile,commerce=profile?.woocommerce,account=value?.account;
    if(!value||!Number.isSafeInteger(value.id)||value.id<1||(id&&value.id!==id)||typeof value.name!=='string'||typeof value.title!=='string'||typeof value.selected!=='boolean'||!Number.isInteger(value.order)||value.order<0||value.order>9999||!/^[a-f0-9]{64}$/.test(value.expected)||!Array.isArray(value.roles)||value.roles.some(role=>!role||['id','label','source'].some(key=>typeof role[key]!=='string'))||!account||typeof account.canChangeRole!=='boolean'||typeof account.canChangeEmail!=='boolean'||typeof account.canSendReset!=='boolean'||typeof account.canRemove!=='boolean'||(account.canSetPassword!==undefined&&typeof account.canSetPassword!=='boolean')||(account.canVerify!==undefined&&typeof account.canVerify!=='boolean')||(account.canSuspend!==undefined&&typeof account.canSuspend!=='boolean')||!['single_site','network_owner'].includes(account.removalMode)||typeof account.role!=='string'||!/^[a-f0-9]{64}$/.test(account.expected)||!/^[a-f0-9]{64}$/.test(account.identityExpected)||!profile||Object.keys(profile).sort().join(',')!=='kiwe,woocommerce,wordpress'||!section(profile.wordpress,wordpressKeys)||!profile.kiwe||Object.keys(profile.kiwe).sort().join(',')!==kiweKeys.slice().sort().join(',')||typeof profile.kiwe.teamMember!=='boolean'||!Number.isInteger(profile.kiwe.teamOrder)||!section(commerce?.billing,addressKeys)||!section(commerce?.shipping,addressKeys))throw new Error('The team response was incomplete. Reload and review this person before saving.');
    if(value.security&&(!['verified','partial','unverified','unavailable'].includes(value.security.verification)||typeof value.security.joinSource!=='string'||typeof value.security.suspended!=='boolean'||typeof value.security.lastActivity!=='string'))throw new Error('The account security state could not be read.');
    return value;
  }
  return {
    profile:async(id,section)=>request(id,undefined,{profile_section:section}),
    saveProfile:async(value,values)=>request(value.id,{operation:'profile',section:value.section,expected:value.expected,values}),
    createAccount:async input=>{const value=await request('',{operation:'invite',...input});if(!value||typeof value.login!=='string'||typeof value.notificationRequested!=='boolean')throw new Error('The account result was incomplete. Reload the account directory.');return {...value,person:person(value.person)};},
    saveRole:async(personValue,role)=>person(await request(personValue.id,{operation:'role',role,expected:personValue.account.expected}),personValue.id),
    saveLoginEmail:async(personValue,email,confirmEmail)=>person(await request(personValue.id,{operation:'login_email',expected:personValue.account.identityExpected,email,confirmEmail}),personValue.id),
    sendPasswordReset:async personValue=>{const value=await request(personValue.id,{operation:'password_reset',expected:personValue.account.identityExpected});if(value?.notificationRequested!==true)return Promise.reject(new Error('WordPress did not confirm the reset request.'));return person(value.person,personValue.id);},
    setPassword:async(personValue,password,confirmPassword)=>{const value=await request(personValue.id,{operation:'set_password',expected:personValue.account.identityExpected,password,confirmPassword});if(value?.changed!==true)throw Error('WordPress did not confirm the password change.');return person(value.person,personValue.id);},
    sendVerification:async personValue=>{const value=await request(personValue.id,{operation:'verify_email',expected:personValue.account.identityExpected});if(value?.notificationRequested!==true)throw Error('PhoneKey did not confirm the verification request.');return person(value.person,personValue.id);},
    setSuspension:async(personValue,suspended)=>person(await request(personValue.id,{operation:'suspend',expected:personValue.account.identityExpected,suspended}),personValue.id),
    removalPlan:async(personValue,search='')=>{const value=await request(personValue.id,undefined,{account_task:'remove',recipient_search:search});if(!value||value.id!==personValue.id||typeof value.username!=='string'||typeof value.name!=='string'||typeof value.hasOrders!=='boolean'||typeof value.blockedReason!=='string'||!/^[a-f0-9]{64}$/.test(value.expected)||!Array.isArray(value.recipients)||value.recipients.length>12||value.recipients.some(item=>!Number.isSafeInteger(item?.id)||item.id<1||typeof item.label!=='string'))throw new Error('The removal plan was incomplete. Reload this person.');return value;},
    removeAccount:async(plan,recipientId,confirmLogin)=>{const value=await request(plan.id,{operation:'remove_account',expected:plan.expected,reassignTo:recipientId,confirmLogin,confirmTransfer:true});if(value?.removed!==true||value.id!==plan.id||value.reassignedTo!==recipientId)throw new Error('WordPress did not confirm removal. Reload the account directory before retrying.');return value;},
    list:async(query,signal)=>{const value=await request('',undefined,query,signal),policy=value?.accountPolicy;if(!value||!Array.isArray(value.people)||value.people.length>12||typeof value.hasNext!=='boolean'||!policy||typeof policy.canCreate!=='boolean'||typeof policy.canChangeRoles!=='boolean'||!Array.isArray(policy.roles)||policy.roles.some(role=>!role||['id','label','description'].some(key=>typeof role[key]!=='string')))throw new Error('The team list was incomplete. Try loading it again.');value.people.forEach(value=>person(value));if(new Set(value.people.map(person=>person.id)).size!==value.people.length)throw new Error('The team list contained repeated people. Try loading it again.');return value;},
    read:async id=>person(await request(id),id),
    save:async(personValue,draft)=>person(await request(personValue.id,{expected:personValue.expected,team_member:draft.selected?'1':'0',team_title:draft.title,team_order:String(draft.order)}),personValue.id)
  };
}
