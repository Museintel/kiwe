/** Presentation only: native status and capabilities still own every action. */
const orders={pending:['waiting','Pending payment'],processing:['waiting','Processing'],'on-hold':['hold','On hold'],completed:['success','Completed'],cancelled:['danger','Cancelled'],failed:['danger','Failed'],refunded:['info','Refunded'],'checkout-draft':['neutral','Checkout draft']};
const campaigns={draft:['neutral','Draft'],queued:['waiting','Queued'],scheduled:['waiting','Scheduled'],running:['waiting','Sending'],processing:['waiting','Sending'],complete:['success','Complete'],completed:['success','Completed'],cancelled:['danger','Cancelled'],failed:['danger','Failed'],stalled:['danger','Needs attention'],partial:['waiting','Partly delivered'],unknown:['waiting','Outcome uncertain']};
const feedback={approve:['success','Approved'],'1':['success','Approved'],hold:['waiting','Pending'],'0':['waiting','Pending'],spam:['danger','Spam'],trash:['neutral','In Trash']};
const carts={active:['neutral','Active'],abandoned:['waiting','Abandoned'],converted:['success','Converted'],cleared:['neutral','Cleared']};
const stock={instock:['success','In stock'],outofstock:['danger','Out of stock'],onbackorder:['hold','On backorder']};
const app={ready:['success','App alerts ready'],known:['info','Known app user'],unknown:['waiting','Contact unknown']};
const users={verified:['success','Fully verified'],partial:['waiting','Partly verified'],unverified:['danger','Not verified'],unavailable:['neutral','Verification unavailable']};
export function statusPresentation(domain,status,nativeLabel=''){
 const entry=({order:orders,cart:carts,campaign:campaigns,feedback,stock,app,user:users}[domain]||{})[status];
 return {tone:entry?.[0]||'neutral',label:nativeLabel||entry?.[1]||String(status||'Status unavailable').replace(/[-_]/g,' ')};
}
