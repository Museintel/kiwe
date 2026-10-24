// Deterministic sample transitions. Production must call the existing WP services.
export const canReviewType=(role,type)=>type==='posts'?['administrator','editor'].includes(role):type==='products'&&['administrator','shop_manager'].includes(role);
export const canCreateContent=(role,type)=>type==='posts'?['administrator','author'].includes(role):type==='products'?['administrator','shop_manager'].includes(role):role==='administrator';
export const reviewRecords=(data,role)=>['posts','products'].flatMap(type=>canReviewType(role,type)&&(type!=='products'||data.siteProfile==='commerce')?data[type].filter(item=>item.guestSubmission).map(item=>({...item,type})):[]);
export const ownContributions=data=>['posts','products'].flatMap(type=>data[type].filter(item=>item.guestSubmission&&item.authorId===8).map(item=>({...item,type})));
const nextId=items=>Math.max(0,...items.map(item=>item.id))+1;
function event(data,{topic,title,text,target,roles}){return {...data,notifications:[{id:nextId(data.notifications),topic,title,text,target,roles,color:'purple',when:'Just now · sample',readBy:[]},...data.notifications].slice(0,40)};}
export function decideApplication(data,role,id,decision,reason=''){
 if(role!=='administrator')throw Error('Only the client Administrator can review Guest applications.');
 const item=data.guestApplications.find(item=>item.id===id);
 if(!item||item.status!=='Pending'||!['Approved','Denied'].includes(decision))throw Error('This application is no longer pending.');
 if(decision==='Approved'&&!item.verified)throw Error('A verified account is required.');
 const next={...data,guestApplications:data.guestApplications.map(item=>item.id===id?{...item,status:decision,reason:decision==='Denied'?reason.trim():'',accountRole:decision==='Approved'?'Contributor (Guest)':item.accountRole}:item)};
 // Only the recipient represented by the active Guest preview receives its private update.
 return id===8?event(next,{topic:'guest_post_status',title:'Your Guest application was '+decision.toLowerCase(),text:reason||'Your application has been reviewed.',target:'contributions',roles:['contributor']}):next;
}
export function createContribution(data,role,input){
 const account=data.guestApplications.find(item=>item.id===8),type=input.type;
 if(role!=='contributor'||account?.status!=='Approved')throw Error('Approved Guest access is required.');
 if(!['posts','products'].includes(type)||!account.intents.includes(type)||!data.guestSettings[type]||(type==='products'&&data.siteProfile!=='commerce'))throw Error('This contribution type is unavailable.');
 const title=input.title.trim(),body=input.body.trim(),status=input.status==='Draft'?'Draft':'Pending';
 if(title.length<5||body.length<(type==='products'?20:50)||body.length>100000)throw Error('Add a clear title and enough detail for review.');
 if(type==='products'&&input.price!==''&&(!Number.isFinite(Number(input.price))||Number(input.price)<0))throw Error('Use a valid non-negative proposed price.');
 const common={id:nextId(data[type]),status,authorId:8,guestSubmission:true,categoryId:0,imageId:0,seoTitle:'',seoDescription:''};
 const record=type==='posts'?{...common,title,body,excerpt:''}:{...common,name:title,description:body,price:input.price,stock:'0',weight:'0',sku:''};
 const next={...data,[type]:[...data[type],record]};
 return status==='Pending'?event(next,{topic:type==='posts'?'admin_guest_submission':'admin_guest_product_submission',title:type==='posts'?'A Guest article is ready for review':'A Guest product proposal is ready for review',text:title,target:'reviews',roles:type==='posts'?['administrator','editor']:['administrator','shop_manager']}):next;
}
export function reviewContribution(data,role,type,id,patch,status){
 if(!canReviewType(role,type)||(type==='products'&&data.siteProfile!=='commerce'))throw Error('You cannot review this contribution.');
 const original=data[type].find(item=>item.id===id);
 if(!original?.guestSubmission||!['Pending','Draft'].includes(original.status)||!['Draft','Published'].includes(status))throw Error('Refresh this contribution before reviewing it.');
 const name=type==='posts'?'title':'name',body=type==='posts'?'body':'description';
 if(!String(patch[name]||'').trim()||!String(patch[body]||'').trim())throw Error('Add a title and content before saving.');
 const category=data.categories.find(item=>item.id===Number(patch.categoryId));
 if(!category||category.kind!==(type==='posts'?'post':'product'))throw Error('Choose a matching category.');
 const record={...original,[name]:patch[name].trim(),[body]:patch[body],categoryId:category.id,status};
 const next={...data,[type]:data[type].map(item=>item.id===id?record:item)};
 return original.authorId===8?event(next,{topic:type==='posts'?'guest_post_status':'guest_product_status',title:status==='Published'?'Your contribution was published':'Your contribution is with the editor',text:record[name],target:'contributions',roles:['contributor']}):next;
}
