const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),source=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/js/surface.js'),'utf8');
function fn(name){const a=source.indexOf('\tfunction '+name+'('),b=source.indexOf('\n\tfunction ',a+1);assert(a>=0&&b>a,name);return source.slice(a,b);}
let checks=0;const check=(ok,label)=>{assert(ok,label);checks++;};
const shown=[],cards=[],dismissed=[],stored=new Map();
const c={phonekey:{cart:{upsells:[]},user:{}},notificationConfig:{},notificationPreferences:{topics:[]},protectedFlow:{},data:{},commerce:{available:true,current:{isCheckout:false},routes:{checkoutUrl:'https://shop.test/buy-now/'}},
 aiInsights:[],aiNotificationHistory:[],aiNotificationQueue:[],aiInsightMemory:{interacted:{},shown:{}},nativeInboxUnread:0,checkoutReminderKeys:new Set(),activeOverlayPanel:'ai',overlayRoot:{hidden:false,querySelector:()=>null},
 document:{visibilityState:'visible'},window:{location:{href:'https://shop.test/',pathname:'/'},sessionStorage:{getItem:k=>stored.get(k),setItem:(k,v)=>stored.set(k,v)}},
 buildReturningUserInsight:()=>null,saveAiNotificationHistory(){},saveAiInsightMemory(){},publishAiNotificationState(){},updateDockBadge(){},rerenderAiInsightInbox(){},
 visibleAiPopoutCards:()=>cards,hideAiPopout:(next,immediate,card)=>dismissed.push(card.dataset.dsaAiInsight),queueAiPopout:(item,context)=>shown.push({item,context}),
 isSameUrlPath:(a,b)=>new URL(a).origin===new URL(b).origin&&new URL(a).pathname===new URL(b).pathname,
 dsaPost:()=>Promise.resolve({ok:true,unreadCount:0}),notificationActorKey:()=> 'guest',Promise,Date,Number,String,Array,Boolean,Set};
vm.createContext(c);
for(const name of ['hydrateCommerceContext','buildAiInsights','activeAiInsights','pendingNotificationActions','unreadAiNotificationCount','markAiNotificationsRead','rerenderAiInsightInbox','markNotificationSpaceViewed','cartOfferKey','cartOfferMap','aiStringHash','discountSignature','reconcileAiCartState','emitCartAiNotifications','positiveDiscountLabel','isCurrentCheckoutPage','remindPendingCheckoutActions'])vm.runInContext(fn(name),c);
const offer={id:22,triggerId:11,state:'pending',title:'Companion',triggerTitle:'Main product',discount:10,offerLabel:'10% off',discountScopeLabel:'on both items'};
const cart=state=>({upsells:[{...offer,state}]});
async function run(){
 c.commerce.current={id:44,isCheckout:true,type:'page'};c.hydrateCommerceContext({available:true,current:{id:0,type:'page',isCheckout:false},cart:{count:3}});check(c.commerce.current.id===44&&c.isCurrentCheckoutPage()&&c.commerce.cart.count===3,'private hydration retains native checkout identity while updating cart state');
 c.commerce.current={id:55,type:'product',isCheckout:false};c.hydrateCommerceContext({current:{id:0,type:'page',isCheckout:false},cart:{count:1}});check(c.commerce.current.id===55&&!c.isCurrentCheckoutPage()&&c.commerce.cart.count===1,'private hydration retains the current product and normal-page classification');
 c.hydrateCommerceContext([]);check(c.commerce.current.id===55,'malformed private context does not erase page identity');
 c.aiTrayOpen=true;c.aiNotificationHistory=[{id:'saving',read:false},{id:'pending',pendingAction:true,action:'apply',read:false},{id:'required',requiredAction:true,action:'confirm',read:false},{id:'private',serverId:'private',read:false}];const inbox={innerHTML:''};c.overlayRoot.querySelector=selector=>selector==='[data-dsa-ai-insights]'?inbox:null;c.renderAiInsightInbox=()=>c.aiNotificationHistory.map(x=>x.read?'Seen':'Pending').join(',');c.bindAiInsightActions=()=>{};c.rerenderAiInsightInbox();check(c.aiNotificationHistory[0].read&&!c.aiNotificationHistory[1].read&&!c.aiNotificationHistory[2].read&&!c.aiNotificationHistory[3].read,'foreground render sees only passive local updates, preserving actions and native private receipt authority');
 c.aiNotificationHistory[0].read=false;c.document.visibilityState='hidden';c.rerenderAiInsightInbox();check(!c.aiNotificationHistory[0].read,'background inbox render does not consume local arrivals');c.document.visibilityState='visible';c.aiTrayOpen=false;c.rerenderAiInsightInbox();check(!c.aiNotificationHistory[0].read,'collapsed notifications remain unread');c.aiTrayOpen=true;c.overlayRoot.hidden=true;c.rerenderAiInsightInbox();check(!c.aiNotificationHistory[0].read,'closed inbox does not consume local arrivals');c.overlayRoot.hidden=false;c.overlayRoot.querySelector=()=>null;c.aiNotificationHistory=[];
 c.phonekey.cart=cart('pending');c.aiInsights=c.buildAiInsights();
 check(c.aiInsights.length===1&&c.aiInsights[0].pendingAction&&c.aiInsights[0].action==='add_and_claim_cart_offer','one current actionable companion notification');
 check(c.unreadAiNotificationCount()===1,'pending companion is counted even without a history notification');
 c.aiNotificationHistory=[{id:'passive',read:false},{id:'required',pendingAction:true,action:'confirm',read:false}];c.nativeInboxUnread=2;
 check(c.unreadAiNotificationCount()===5,'passive, native and distinct pending actions share one count');
 c.markAiNotificationsRead();check(c.aiNotificationHistory[0].read&&!c.aiNotificationHistory[1].read&&c.unreadAiNotificationCount()===4,'mark seen clears passive notices only');
 c.phonekey.user={loggedIn:true,id:1};await c.markNotificationSpaceViewed([], 'guest');check(c.unreadAiNotificationCount()===2,'inbox visit retains pending actions after native receipt');
 c.aiNotificationHistory=[{id:'explicit-required',requiredAction:true,action:'confirm',read:false}];c.nativeInboxUnread=0;c.aiInsightMemory.interacted={};c.markAiNotificationsRead();check(!c.aiNotificationHistory[0].read&&c.unreadAiNotificationCount()===2,'explicit required actions also survive a passive visit');c.aiInsightMemory.interacted['explicit-required']=Date.now();check(c.unreadAiNotificationCount()===1,'a fulfilled explicit action no longer adds a reminder badge');
 c.aiNotificationHistory=[];c.phonekey.cart=cart('eligible');c.aiInsights=c.buildAiInsights();
 check(c.aiInsights.length===1&&c.aiInsights[0].action==='claim_cart_offer'&&c.unreadAiNotificationCount()===1,'pair ready to apply replaces its companion action without doubling count');
 c.emitCartAiNotifications(cart('pending'),cart('eligible'));check(c.aiNotificationHistory.length===0&&shown.length===1&&shown[0].item.action==='claim_cart_offer','eligible transition opens the actual Apply action without separate pair history');
 c.emitCartAiNotifications(cart('eligible'),cart('eligible'));check(shown.length===1,'repeated cart payload does not repeat an eligible popup');
 c.commerce.current.isCheckout=true;shown.length=0;c.remindPendingCheckoutActions();c.remindPendingCheckoutActions();
 check(shown.length===1&&shown[0].context==='checkout','native checkout reminds once per action in that page lifecycle');
 c.phonekey.cart=cart('applied');c.aiInsights=c.buildAiInsights();c.remindPendingCheckoutActions();check(c.unreadAiNotificationCount()===0&&shown.length===1,'applied bonus removes pending count and stops reminders');
 c.phonekey.cart={upsells:[]};c.aiInsights=c.buildAiInsights();check(c.unreadAiNotificationCount()===0,'empty or no longer eligible cart has no pending badge');
 c.aiNotificationHistory=[{id:'cart-paired:11:22',type:'cart_pair'},{id:'ordinary',read:true}];c.aiNotificationQueue=[{insight:{id:'cart-offer:11:22:eligible'}},{insight:{id:'ordinary'}}];cards.push({dataset:{dsaAiInsight:'cart-offer:11:22:eligible'}},{dataset:{dsaAiInsight:'cart-paired:11:22'}});
 c.reconcileAiCartState(cart('eligible'),{upsells:[]});check(c.aiNotificationHistory.length===1&&c.aiNotificationHistory[0].id==='ordinary','obsolete duplicate pair history retires while ordinary history remains');
 check(c.aiNotificationQueue.length===1&&dismissed.length===2,'queued and cloned stale offer popups all retire after removal');
 c.phonekey.cart={upsells:[{...offer,discount:0,offerLabel:''}]};c.aiInsights=c.buildAiInsights();check(c.aiInsights[0].actionLabel==='Add companion'&&!c.aiInsights[0].title.includes('save'),'zero discount never promises a saving');
 c.commerce.current.isCheckout=false;check(!c.isCurrentCheckoutPage(),'Checkout DSA on a normal page does not count as native checkout');
 c.commerce.current={};c.window.location={href:'https://shop.test/buy-now/',pathname:'/buy-now/'};check(c.isCurrentCheckoutPage(),'configured renamed Woo checkout route is recognized');
 c.window.location={href:'https://shop.test/checkout/',pathname:'/checkout/'};check(!c.isCurrentCheckoutPage(),'an unconfigured lookalike checkout path is not assumed');
 c.commerce.current.isCheckout=false;c.window.location={href:'https://shop.test/buy-now/order-received/99/',pathname:'/buy-now/order-received/99/'};check(!c.isCurrentCheckoutPage(),'native confirmation endpoint never receives purchase reminders');
 c.commerce.available=false;c.commerce.current.isCheckout=true;check(!c.isCurrentCheckoutPage(),'non-Woo never activates checkout reminders');
 const adapter=await import('data:text/javascript;base64,'+Buffer.from(fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/js/modules/ai-panel.js'),'utf8')).toString('base64'));
 for(const visualProfile of ['legacy','prototype']){const html=adapter.renderInbox({visualProfile,open:true,unread:1,items:[c.aiInsights[0]]});check(html.includes('Action available')&&html.includes('Add companion')&&!html.includes('>Seen<'),'pending benefit is clearly labelled in '+visualProfile);}
 const admin=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Admin/Admin.php'),'utf8');check(!/name="commerce\[(?:upsell_banner_enabled|cart_upsell_discounts_enabled|fbt_enabled)\]"/.test(admin),'retired activation switches cannot reappear in technical forms');
 const ui=fs.readFileSync(path.join(root,'tools/workspace-prototype/native-workflow.jsx'),'utf8');check(!ui.includes('Store offer settings')&&!ui.includes("setShowOfferPolicy('offers')"),'Store offers entry removed from workspace');
 const sprite=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/icons/lucide/sprite.svg'),'utf8');check(sprite.includes('<symbol id="bell"'),'Dock bell resolves to a supplied sprite symbol');
 console.log(JSON.stringify({passed:true,checks,scope:'Executable current shipped notification owners and both presentation adapters; native Woo and Chrome are separate acceptance'}));
}
run().catch(error=>{console.error(error);process.exitCode=1;});
