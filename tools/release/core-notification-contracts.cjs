'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=path.resolve(__dirname,'../..'),read=file=>fs.readFileSync(path.join(root,file),'utf8');
const source=read('wp-content/mu-plugins/dsa/assets/js/surface.js');
function fn(name){
 const start=source.indexOf('\tfunction '+name+'(');
 assert(start>=0,'Missing actual runtime function '+name);
 const end=source.indexOf('\n\tfunction ',start+1);
 return source.slice(start,end<0?source.length:end);
}
const stored=new Map([['dsa_ai_notification_history_v1',JSON.stringify([{id:'old-private',title:'Must not migrate'}])]]),listeners={};
let postCalls=0,lastPost=null;
let getCalls=0,nextResponse=()=>Promise.resolve({ok:true,notifications:[],unreadCount:0});
const context={
 phonekey:{user:{loggedIn:true,id:1,canUseNotifications:true,inboxUnreadCount:2}},
 aiInsightMemory:{interacted:{},shown:{}},checkoutReminderKeys:new Set(),loadAiInsightMemory:()=>({interacted:{},shown:{}}),aiInsights:[],aiNotificationHistory:[],aiNotificationQueue:[],nativeNotificationActor:'',nativeInboxUnread:0,nativeInboxLastRead:0,nativeInboxRequest:null,nativeInboxStatus:'',
 activeOverlayPanel:'search',overlayRoot:{hidden:false},document:{visibilityState:'visible'},Promise,Date,Number,String,Array,Boolean,
 window:{sessionStorage:{getItem:key=>stored.get(key),setItem:(key,value)=>stored.set(key,value),removeItem:key=>stored.delete(key)},addEventListener:(event,fn)=>listeners[event]=fn},
 aiTrayOpen:true,presentationModules:{get:()=>({renderInbox:value=>value})},withUiProfile:value=>value,screenTheme:()=>"",screenLabel:(id,label)=>label,currentVisualProfile:()=>"",
 hideAiPopout(){},updateDockBadge(){},activeAiInsights:()=>[],publishAiNotificationState(){},rerenderAiInsightInbox(){},
 dsaGet(){getCalls++;return nextResponse();},
 dsaPost(url,body){postCalls++;lastPost={url,body};return Promise.resolve({ok:true,unreadCount:0});},
 recordAiNotification(item){context.aiNotificationHistory.unshift(item);},
};
vm.createContext(context);
for(const name of ['notificationActorKey','isAiNotificationDismissible','normalizeAiNotificationDismissible','loadAiNotificationHistory','saveAiNotificationHistory','resetNotificationActor','pendingNotificationActions','unreadAiNotificationCount','markNotificationSpaceViewed','refreshNativeNotificationInbox','initializeAdminNotificationInbox','renderAiInsightInbox','aiPresentationPayload'])vm.runInContext(fn(name),context);
(async()=>{
 context.resetNotificationActor();
 assert.equal(context.aiNotificationHistory.length,0,'Unscoped legacy private history never migrates');
 context.aiNotificationHistory=[{id:'local-cart',title:'Cart saved',notification:true},{id:'push-order-1',serverId:'order-1',title:'Private order',notification:true}];
 context.saveAiNotificationHistory();
 assert(!stored.has('dsa_ai_notification_history_v1'),'Legacy storage retired');
 assert.equal(JSON.parse(stored.get('dsa_notification_history_v2:user:1')).length,1,'Private server notice never persists in browser storage');
 context.phonekey.user={loggedIn:true,id:2,canUseNotifications:true,inboxUnreadCount:0};
 context.resetNotificationActor();assert.equal(context.aiNotificationHistory.length,0,'Other account sees no previous history');
 context.phonekey.user={loggedIn:true,id:1,canUseNotifications:true,inboxUnreadCount:2};
 context.resetNotificationActor();assert.equal(context.aiNotificationHistory[0].id,'local-cart','Local history resumes only for its actor');
 let resolve;
 nextResponse=()=>new Promise(done=>resolve=done);
 const pending=context.refreshNativeNotificationInbox(true);
 context.phonekey.user={loggedIn:true,id:2,canUseNotifications:true,inboxUnreadCount:0};context.resetNotificationActor();
 resolve({ok:true,notifications:[{id:'private-race',title:'Owner order'}],unreadCount:1});await pending;
 assert.equal(context.aiNotificationHistory.length,0,'Old-account response cannot populate a new session');
 nextResponse=()=>Promise.resolve({ok:true,notifications:[{id:'account-2',type:'account_update',title:'Your update',createdAt:1}],unreadCount:1});
 await context.refreshNativeNotificationInbox(true);
 assert.equal(context.aiNotificationHistory[0].serverId,'account-2','Own private read populates current inbox');
 assert.equal(context.nativeInboxUnread,1,'Native unread count comes from the server');
 const before=getCalls;await context.refreshNativeNotificationInbox(false);assert.equal(getCalls,before,'Repeat reads are throttled');
 context.initializeAdminNotificationInbox();listeners.focus();assert.equal(getCalls,before,'Focus elsewhere does not poll inbox');
 context.phonekey.user={loggedIn:false,id:0};context.resetNotificationActor();
 await context.refreshNativeNotificationInbox(true);
 assert.equal(getCalls,before,'Anonymous viewer never requests private account notices');
 assert.equal(context.aiNotificationHistory.length,0,'Logout clears private actor history');
 context.phonekey.user={loggedIn:true,id:2,canUseNotifications:true};context.resetNotificationActor();
 context.aiNotificationHistory=[{serverId:'stale-private',id:'push-stale-private',title:'Old role data'}];
 nextResponse=()=>Promise.reject(Error('403'));
 await context.refreshNativeNotificationInbox(true);
 assert.equal(context.aiNotificationHistory.length,0,'Rejected read clears old private notice data');
 assert(context.nativeInboxStatus.includes('could not be refreshed'),'Failed read has visible recovery feedback');
 context.activeOverlayPanel='ai';context.aiNotificationHistory=[{id:'local-new',read:false}];context.nativeInboxUnread=2;
 await context.markNotificationSpaceViewed(['account-2'],'user:2');
 assert.equal(postCalls,1);assert.equal(lastPost.url,'/admin-notifications');assert.equal(context.nativeInboxUnread,0,'A confirmed visit clears the badge');assert(context.aiNotificationHistory[0].read,'Local visible updates become seen');
 context.document.visibilityState='hidden';await context.markNotificationSpaceViewed(['hidden'],'user:2');assert.equal(postCalls,1,'Hidden surfaces cannot clear updates');context.document.visibilityState='visible';
 nextResponse=()=>Promise.resolve({ok:true,notifications:[{id:'seen',readAt:1,title:'Earlier update'}],unreadCount:0,visitIds:['seen']});await context.refreshNativeNotificationInbox(true);assert.equal(context.nativeInboxUnread,0,'Visible history never substitutes for a zero badge');assert.equal(context.aiNotificationHistory[0].read,true,'Seen history is retained as seen');
 context.activeAiInsights=()=>[{id:'welcome',title:'Welcome back'}];assert.equal(context.aiPresentationPayload('Notifications').unread,0,'A welcome tip does not become an unread update');assert.equal(context.renderAiInsightInbox().unread,0,'Both Dock themes use the native unread count');
 const init=fn('initializeAdminNotificationInbox');
 assert(!init.includes('setInterval'),'No continuous notification polling');
 const event=read('wp-content/mu-plugins/dsa/includes/Notifications/Admin_Event_Notification_Service.php');
 assert(event.includes('if ( $visitor_sources )'),'Inbox hooks do not activate tracking');
 assert(event.includes("if ( ! empty( $event['inboxOnly'] ) ) return;"),'Inbox-only reconciliation cannot enter gateways');
 const admin=read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
 assert(!/name="permissions\[(?:enabled|notifications_enabled|notification_preferences_enabled)\]"/.test(admin),'No notification master pause switches');
 console.log('PASS native notification actor isolation, bounded reads, consent separation and refresh recovery');
})().catch(error=>{console.error(error);process.exitCode=1;});
