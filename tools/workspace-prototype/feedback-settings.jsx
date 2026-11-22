import React from 'react';
import {ChoiceField} from './choice-field.jsx';

const discussionFields=[
 ['newPosts','Allow comments on new posts','Existing posts keep their individual setting.'],
 ['siteWide','Allow website-wide comments','One shared conversation on Home, Contact, archives and other pages. Posts and products keep their own conversations.'],
 ['accountRequired','Require an account to comment','Everyone can read approved comments. PhoneKey guides people who need to sign in.'],
 ['moderateAll','Approve every new comment before it appears','Applies even when a contact is verified.'],
 ['previousApproval','Approve someone’s first contribution','People without a previously approved comment wait for moderation.'],
 ['threaded','Allow replies','Replies stay inside the original conversation.'],
];
const reviewFields=[
 ['enabled','Accept product reviews','Each product also has its own Allow reviews choice. Existing approved reviews remain readable.'],
 ['stars','Offer star ratings','Show one to five stars with a product review.'],
 ['required','Require a star rating','Replies do not add another product rating.'],
 ['buyersOnly','Only verified buyers can review products','Uses the verified customer account and WooCommerce’s purchase records.'],
 ['buyerLabel','Show the verified-purchase badge','Shown only when the purchase belongs to that customer.'],
];
function Choice({field,label,help,policy,busy,save,operation}){
 return <section className="kiwe-settings-section"><ChoiceField label={label} help={help} value={policy.values[field]?'on':'off'} options={[[ 'on','On'],['off','Off']]} disabled={busy||policy.canEdit===false} onChange={value=>save(operation,field,value==='on',policy.expected)}/></section>;
}
export function FeedbackSettings({data,busy,save}){
 return <div className="kiwe-settings-sections">{data.discussion?.values&&<><h2>Comments and website feedback</h2>{discussionFields.map(([field,label,help])=><Choice key={field} {...{field,label,help,busy,save}} policy={data.discussion} operation="discussion"/>)}{data.discussion.values.threaded&&<section className="kiwe-settings-section"><h3>Reply levels</h3><label>How many levels can a conversation have?<input type="number" min="1" max="10" disabled={busy||data.discussion.canEdit===false} key={data.discussion.expected} defaultValue={data.discussion.values.threadDepth} onKeyDown={event=>{if(event.key==='Enter'){event.preventDefault();event.currentTarget.blur();}}} onBlur={event=>{const value=Number(event.target.value);if(value!==data.discussion.values.threadDepth&&Number.isInteger(value)&&value>=1&&value<=10)save('discussion','threadDepth',value,data.discussion.expected);}}/></label></section>}</>}{data.reviews&&<><h2>Product reviews</h2>{reviewFields.filter(([field])=>field==='enabled'||data.reviews.values.enabled&&(field!=='required'||data.reviews.values.stars)).map(([field,label,help])=><Choice key={field} {...{field,label,help,busy,save}} policy={data.reviews} operation="reviews"/>)}</>}</div>;
}
