import {statusPresentation} from './status-presentation.mjs';

/** Operational meaning comes from native record status, never wording or list position. */
export function notificationPresentation(item){
 if(['admin_new_order','order_status'].includes(item.type)&&item.recordStatus)return statusPresentation('order',item.recordStatus);
 if(item.type==='admin_abandoned_cart')return {tone:'waiting',label:'Saved cart'};
 if(['admin_new_comment','admin_guest_application','admin_guest_submission','admin_form_submission'].includes(item.type))return {tone:'waiting',label:item.kicker||'Needs review'};
 return {tone:'neutral',label:item.kicker||'Update'};
}
