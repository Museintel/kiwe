import React from 'react';
import {GuestApplications,GuestReviews,GuestContributions} from './guests.jsx';
import NotificationsWorkspace from './notifications.jsx';
import {ContentWorkspace,CategoryWorkspace,NavigationWorkspace,MediaWorkspace,OrdersWorkspace,ActivityWorkspace,ContextArea} from './workspace-areas.jsx';
export default function TaskRouter(props){const id=props.module.id;if(['posts','pages','products','services'].includes(id))return <ContentWorkspace {...props} type={id}/>;if(['search','store'].includes(id))return <ContextArea {...props} area={id}/>;const Component={categories:CategoryWorkspace,navigation:NavigationWorkspace,media:MediaWorkspace,orders:OrdersWorkspace,activity:ActivityWorkspace,notifications:NotificationsWorkspace,guests:GuestApplications,reviews:GuestReviews,contributions:GuestContributions}[id];return Component?<Component {...props}/>:<p>This task is unavailable.</p>;}
