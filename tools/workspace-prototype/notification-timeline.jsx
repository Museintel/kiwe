import React from 'react';
import {notificationPresentation} from './notification-presentation.mjs';

/** Both current updates and saved history share the Daily stacked-card grammar. */
export function NotificationTimeline({items,history=false,safeLink,deliveryLabels,deliveryStates}){
 return <div className="kiwe-status-stack kiwe-notification-timeline" aria-label={history?'Notification history':'Recent notifications'}>{items.map(item=>{
  const status=notificationPresentation(item),url=safeLink(item.actionUrl);
  return <article className="wf-tile kiwe-status-card" data-tone={status.tone} key={item.id}>
   <div className="kiwe-notification-meta"><span className="kiwe-status-label" data-tone={status.tone}><span className="kiwe-status-dot" aria-hidden="true"/>{status.label}</span><span className="wf-meta"><time dateTime={new Date(item.createdAt*1000).toISOString()}>{new Date(item.createdAt*1000).toLocaleString()}</time>{history?' · '+(item.readAt?'Seen':'New'):''}</span></div>
   <h2>{item.title}</h2><p>{item.message}</p>
   {history&&<details className="kiwe-notification-delivery"><summary>Delivery details</summary><div className="wf-delivery"><small>Kiwe · Saved</small>{(item.deliveries||[]).map(delivery=><small key={delivery.channel}>{deliveryLabels[delivery.channel]||'Delivery'} · {deliveryStates[delivery.status]||'Status unavailable'}<br/>{delivery.detail}</small>)}</div></details>}
   {url&&<div className="wf-actions"><a href={url}>{item.actionLabel||'Open update'} ↗</a></div>}
  </article>;
 })}</div>;
}
