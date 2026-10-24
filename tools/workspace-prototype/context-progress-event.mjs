import {recordWorkspaceVisit} from './workspace-activity.mjs';
/** Share confirmed native presentation receipts; no polling or business replay. */
export function publishContextProgress(response,renderedSection){
  recordWorkspaceVisit(response,renderedSection);
  if(typeof window==='undefined'||typeof CustomEvent==='undefined')return;
  try{
    const value=JSON.parse(response.headers?.get?.('X-Kiwe-Context-Progress')||'null');
    if(!value||!['percent','ready','total','later'].every(key=>Number.isSafeInteger(value[key])&&value[key]>=0)||value.percent>100||value.ready>value.total||value.total>1000)return;
    if(!['url','resumeUrl'].every(key=>new URL(value[key],location.origin).origin===location.origin))return;
    window.dispatchEvent(new CustomEvent('kiwe:context-progress',{detail:value}));
  }catch{} // Optional presentation must never turn a confirmed save into failure.
}
