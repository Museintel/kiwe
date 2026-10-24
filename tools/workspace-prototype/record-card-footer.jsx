import React from 'react';
import {SeoButton} from './seo-editor.jsx';
import {ContentArchiveAction} from './content-archive.jsx';
import {TrashRecordAction} from './trash-record-action.jsx';

/** One public-preview and SEO action row for native content cards. */
export function RecordCardFooter({viewUrl,scope,id,seoEndpoint,nonce,canSeo=true,seoDisabled=false,onSeoSaved,analyticsUrl,builderUrl,archiveEndpoint,canExport=false,label,trashEndpoint,canTrash=false,trashType,onTrashed,trashDisabled=false}){
  if(!viewUrl&&!(canSeo&&seoEndpoint)&&!analyticsUrl&&!builderUrl&&!(canExport&&archiveEndpoint)&&!(canTrash&&trashEndpoint))return null;
  return <div className="kiwe-record-card-footer">
    {viewUrl&&<a href={viewUrl} target="_blank" rel="noopener noreferrer">View on website ↗</a>}
    {builderUrl&&<a href={builderUrl} target="_blank" rel="noopener noreferrer">Edit with Bricks ↗</a>}
    {analyticsUrl&&<a href={`${analyticsUrl}${analyticsUrl.includes('?')?'&':'?'}scope=${encodeURIComponent(scope)}&id=${encodeURIComponent(id)}`}>Analytics ↗</a>}
    {canSeo&&seoEndpoint&&<SeoButton endpoint={seoEndpoint} nonce={nonce} scope={scope} id={id} disabled={seoDisabled} onSaved={onSeoSaved}/>}
    {canExport&&archiveEndpoint&&<ContentArchiveAction endpoint={archiveEndpoint} nonce={nonce} scope={scope} id={id} label={label} disabled={seoDisabled}/>}
    {canTrash&&trashEndpoint&&<TrashRecordAction endpoint={trashEndpoint} nonce={nonce} id={id} type={trashType||scope} disabled={seoDisabled||trashDisabled} onTrashed={onTrashed}/>}
  </div>;
}
