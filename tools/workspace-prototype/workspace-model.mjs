// Synthetic presentation model only. Native adapters must map through their documented owners.
const admin = ['administrator'], editorial = [...admin,'editor','author'], commerce = [...admin,'shop_manager'];
export const modules = [
  {id:'brand',title:'Your website',detail:'Your identity, always connected',color:'apricot',roles:admin,group:'business'},
  {id:'posts',title:'Posts',detail:'Write your next story',color:'blue',roles:editorial,group:'today'},
  {id:'pages',title:'Pages',detail:'Give every page a purpose',color:'purple',roles:admin,group:'today'},
  {id:'products',title:'Products',detail:'Your collection & inventory',color:'green',roles:commerce,group:'today'},
  {id:'orders',title:'Orders',detail:'From your shop to their door',color:'blue',roles:commerce,group:'today'},
  {id:'categories',title:'Categories',detail:'Organize, rename & connect',color:'rose',roles:[...admin,'shop_manager'],group:'business'},
  {id:'media',title:'Media',detail:'Choose what tells your story',color:'purple',roles:[...editorial,'shop_manager'],group:'today'},
  {id:'team',title:'People',detail:'Your users & public team',color:'yellow',roles:admin,group:'business'},
  {id:'navigation',title:'Navigation',detail:'A clear path through your site',color:'rose',roles:admin,group:'business'},
  {id:'services',title:'Services',detail:'What your business offers',color:'apricot',roles:admin,group:'business'},
  {id:'search',title:'Search & sharing',detail:'Help people discover you',color:'blue',roles:admin,group:'business'},
  {id:'store',title:'Store setup',detail:'Selling, shipping & tax',color:'green',roles:commerce,group:'business'},
  {id:'activity',title:'Comments & reviews',detail:'Conversations & testimonials',color:'ink',roles:admin,group:'today'},
];
modules.push({id:'notifications',title:'Notifications',detail:'Your updates & delivery choices',color:'ink',roles:[...editorial,'shop_manager','contributor'],group:'today'});
modules.push({id:'guests',title:'Guest applications',detail:'Welcome new contributors',color:'yellow',roles:admin,group:'today'},{id:'reviews',title:'Review contributions',detail:'Articles & product proposals',color:'purple',roles:[...admin,'editor','shop_manager'],group:'today'},{id:'contributions',title:'My contributions',detail:'Your voice & your progress',color:'apricot',roles:['contributor'],group:'today'});
export const roleNames={administrator:'Client Administrator',author:'Author',editor:'Editor',shop_manager:'Shop manager',contributor:'Guest (WordPress Contributor)'};
const address={first_name:'',last_name:'',company:'',address_1:'',address_2:'',city:'',postcode:'',country:'IN',state:'',phone:''};
const people=[['Asha','Founder & maker','Administrator'],['Ravi','Our kitchen lead','Editor'],['Meera','Customer care','Shop manager'],['Kabir','Recipes & stories','Author'],['Nila','Creative direction','Editor'],['Omar','Community contributor','Contributor']];
export const initial = {
  brand:{name:'Saffron & Co.',tagline:'A little sweetness. A lot of heart.',logo:'',darkLogo:'',icon:'',siteType:'ecommerce',industry:'Food and beverage'},
  team:people.map(([name,job,accountRole],index)=>({id:index+1,name,job,accountRole,roleSource:accountRole==='Shop manager'?'WooCommerce':'WordPress',eligible:true,selected:index<2,first_name:name,last_name:'',nickname:name.toLowerCase(),username:name.toLowerCase()+'.sample',email:name.toLowerCase()+'@example.test',website:'',bio:'',locale:'en_US',linkedin:'',facebook:'',portrait:'',team_order:index,founder_title:index===0?'Founder':'',registered:'2026-09-01',keyStatus:'Not connected in preview',billing:{...address,first_name:name,email:name.toLowerCase()+'@example.test'},shipping:{...address,first_name:name}})),
  categories:[{id:1,name:'Our story',kind:'post',description:'The people and ideas behind our business.',indexable:true,seoTitle:'Our story',seoDescription:''},{id:2,name:'Behind the scenes',kind:'post',description:'A little look inside our kitchen.',indexable:true,seoTitle:'',seoDescription:''},{id:3,name:'News',kind:'post',description:'Fresh from our studio.',indexable:true,seoTitle:'',seoDescription:''},{id:4,name:'Gift boxes',kind:'product',description:'Little things worth celebrating.',indexable:true,seoTitle:'',seoDescription:''},{id:5,name:'Everyday favourites',kind:'product',description:'Something sweet, any day.',indexable:true,seoTitle:'',seoDescription:''}],
  posts:[{id:1,title:'The story behind our signature recipe',categoryId:1,status:'Draft',authorId:4,body:'Some recipes are more than a list of ingredients. They are a little piece of home, passed from one pair of hands to another.',excerpt:'A recipe with a story.',seoTitle:'',seoDescription:'',imageId:0},{id:2,title:'Made by hand, every morning',categoryId:2,status:'Published',authorId:2,body:'Take a look inside our kitchen.',excerpt:'Behind the scenes at Saffron & Co.',seoTitle:'',seoDescription:'',imageId:2}],
  pages:[{id:1,title:'Our story',status:'Published',body:'A small team with a love for making things by hand.',purpose:'Introduce our business',cta:'Meet the team',seoTitle:'',seoDescription:''},{id:2,title:'Shop',status:'Published',body:'Find a little something for someone you love.',purpose:'Sell products',cta:'Shop the collection',seoTitle:'',seoDescription:''},{id:3,title:'Journal',status:'Published',body:'Stories, ideas and a little inspiration.',purpose:'Share our stories',cta:'Read the latest',seoTitle:'',seoDescription:''},{id:4,title:'Contact',status:'Draft',body:'We would love to hear from you.',purpose:'Receive enquiries',cta:'Get in touch',seoTitle:'',seoDescription:''}],
  products:[{id:1,name:'Signature sweet box',price:'480',stock:'24',sku:'SAMPLE-BOX',categoryId:4,status:'Published',description:'A collection of handmade favourites.',seoTitle:'',seoDescription:'',weight:'0.5',imageId:1},{id:2,name:'Celebration hamper',price:'1250',stock:'3',sku:'SAMPLE-HAMPER',categoryId:4,status:'Published',description:'Made for a special occasion.',seoTitle:'',seoDescription:'',weight:'1',imageId:3}],
  orders:[{id:1001,customer:'Sample customer A',status:'Processing',items:[{productId:1,quantity:2,unitPrice:480}],total:960,note:'Gift wrap, please.',address:'Sample delivery address',payment:'Payment recorded — sample'},{id:1002,customer:'Sample customer B',status:'On hold',items:[{productId:2,quantity:1,unitPrice:1250}],total:1250,note:'',address:'Sample collection order',payment:'Awaiting payment — sample'},{id:1003,customer:'Sample customer C',status:'Completed',items:[{productId:1,quantity:1,unitPrice:480}],total:480,note:'',address:'Sample delivery address',payment:'Payment recorded — sample'}],
  media:[{id:1,name:'Our packaging',kind:'Image',selected:true,color:'green',alt:'A gift box wrapped in cream paper',caption:'Made with care',description:'Our signature packaging.',url:''},{id:2,name:'In the kitchen',kind:'Image',selected:true,color:'apricot',alt:'Our team preparing a fresh batch',caption:'A little behind the scenes',description:'',url:''},{id:3,name:'Festive collection',kind:'Image',selected:false,color:'purple',alt:'A collection of seasonal gift boxes',caption:'',description:'',url:''},{id:4,name:'Product guide',kind:'PDF',selected:false,color:'yellow',alt:'',caption:'Our product guide',description:'A sample document in the library.',url:''}],
  navigation:[{id:1,type:'pages',objectId:1,label:'',dsa:true},{id:2,type:'pages',objectId:2,label:'',dsa:true},{id:3,type:'pages',objectId:3,label:'',dsa:true},{id:4,type:'pages',objectId:4,label:'',dsa:false}],
  services:[{id:1,name:'Celebration gifting',description:'Thoughtful gifts for your special moments.',price:'',category:'Gifting',status:'Published',imageId:1},{id:2,name:'Corporate hampers',description:'Made personal, for your people.',price:'',category:'Business',status:'Draft',imageId:3}],
  activity:[{id:1,kind:'Comment',author:'Sample reader',text:'Loved the story behind this recipe.',status:'Pending',selected:false,roles:[...admin,'editor'],target:'posts',targetId:2},{id:2,kind:'Review',author:'Sample customer',text:'Beautifully packed and a lovely gift.',status:'Approved',selected:false,roles:commerce,target:'products',targetId:1},{id:3,kind:'Update',author:'Your workspace',text:'Your recipe story is ready to continue.',status:'Unread',roles:editorial,target:'posts',targetId:1}],
  context:{description:'Handmade sweets and thoughtful gifts.',story:'Small batches. Familiar flavours. Made to be shared.',mission:'Make everyday moments feel special.',vision:'A thoughtful gift for every occasion.',values:'Care, craft and connection',usp:'Made by hand, close to home',audience:'People choosing thoughtful gifts',locations:'India',goals:'Grow our gift collection',phone:'',email:'hello@example.test',whatsapp:'',instagram:'',facebook:'',linkedin:'',youtube:'',address_1:'',address_2:'',city:'',state:'',postcode:'',country:'IN',timezone:'Asia/Kolkata',showLocation:false,brandColors:'#293f2e, #efb295, #f7f6f2',mood:'Warm, welcoming and thoughtful',preferences:'Confident typography. Plenty of space.',references:'',indexable:true,seoGoal:'Sales',seoDescription:'Handmade sweets and thoughtful gifts for your people.',sharingImage:0,sitemap:true,aiSeo:false,currency:'INR',currencyPosition:'left',priceDecimals:2,weightUnit:'kg',dimensionUnit:'cm',taxEnabled:false,pricesIncludeTax:false,sellingMode:'specific',sellingCountries:'IN',shippingMode:'specific',shippingCountries:'IN',servicesEnabled:true,teamEnabled:true},
};
export const categoryName=(data,id)=>data.categories.find(item=>item.id===Number(id))?.name||'Uncategorized';
export const recordName=(data,type,id)=>{const item=data[type]?.find(item=>item.id===Number(id));return item?.title||item?.name||'Unavailable record';};
export const menuName=(data,item)=>item.label||recordName(data,item.type,item.objectId);
export const recordsFor=(data,type,role)=>['posts','media'].includes(type)&&role==='author'?data[type].filter(item=>item.authorId===4):data[type]||[];
export const permittedCategories=(data,role)=>data.categories.filter(item=>role==='shop_manager'?item.kind==='product':role==='editor'?item.kind==='post':true);
const f=(key,label,type='text',extra={})=>({key,label,type,...extra});
export const contextFields={
  story:[f('description','What you do','textarea'),f('story','Your story','textarea'),f('mission','Mission','textarea'),f('vision','Vision','textarea'),f('values','Values','textarea'),f('usp','What makes you different','textarea'),f('audience','Your audience','textarea'),f('locations','Locations served'),f('goals','Business goals','textarea')],
  contact:[f('email','Public email','email'),f('phone','Public phone','tel'),f('whatsapp','WhatsApp','tel'),f('instagram','Instagram','url'),f('facebook','Facebook','url'),f('linkedin','LinkedIn','url'),f('youtube','YouTube','url'),f('address_1','Street address'),f('address_2','Address line 2'),f('city','City'),f('state','State / region'),f('postcode','Postcode'),f('country','Country code'),f('timezone','Timezone'),f('showLocation','Show public location','boolean')],
  style:[f('brandColors','Brand colors'),f('mood','Visual mood','textarea'),f('preferences','Design preferences','textarea'),f('references','Reference websites','textarea')],
  search:[f('seoGoal','Your search goal','select',{options:['Sales','Traffic','Leads']}),f('seoDescription','Site description','textarea'),f('indexable','Allow search indexing','boolean'),f('sitemap','Include sitemap','boolean'),f('aiSeo','Use approved AI SEO allowance','boolean'),f('sharingImage','Default sharing image','media')],
  store:[f('currency','Currency','select',{options:['INR','USD','GBP','EUR']}),f('currencyPosition','Currency position','select',{options:['left','right','left_space','right_space']}),f('priceDecimals','Price decimals','number',{min:0,max:6}),f('weightUnit','Weight unit','select',{options:['kg','g','lbs','oz']}),f('dimensionUnit','Dimension unit','select',{options:['cm','m','mm','in','yd']}),f('sellingMode','Where you sell','select',{options:['all','specific','all_except']}),f('sellingCountries','Selling country codes'),f('shippingMode','Where you ship','select',{options:['all','specific','disabled']}),f('shippingCountries','Shipping country codes'),f('taxEnabled','Calculate tax','boolean'),f('pricesIncludeTax','Prices include tax','boolean')],
};
export const profileFields={
  'WordPress profile':[f('name','Display name','text',{required:true}),f('first_name','First name'),f('last_name','Last name'),f('nickname','Nickname'),f('email','Account email','email',{required:true}),f('website','Website','url'),f('bio','Biography','textarea'),f('locale','Language','select',{options:['en_US','en_GB','hi_IN']})],
  'Kiwe public profile':[f('job','Team title'),f('team_order','Team display order','number',{min:0,max:9999,step:'1'}),f('founder_title','Founder title'),f('linkedin','LinkedIn','url'),f('facebook','Facebook','url'),f('portrait','Profile portrait','image')],
  'WooCommerce billing':[...Object.keys(address),'email'].map(key=>f('billing.'+key,({address_1:'Address line 1',address_2:'Address line 2',first_name:'First name',last_name:'Last name',postcode:'Postcode / ZIP',state:'State / region',country:'Country code'})[key]||key[0].toUpperCase()+key.slice(1),key==='email'?'email':key==='phone'?'tel':'text')),
  'WooCommerce shipping':Object.keys(address).map(key=>f('shipping.'+key,({address_1:'Address line 1',address_2:'Address line 2',first_name:'First name',last_name:'Last name',postcode:'Postcode / ZIP',state:'State / region',country:'Country code'})[key]||key[0].toUpperCase()+key.slice(1),key==='phone'?'tel':'text')),
};
export const readField=(object,key)=>key.split('.').reduce((value,part)=>value?.[part],object);
export function writeField(object,key,value){const copy=structuredClone(object);const parts=key.split('.');const leaf=parts.pop();let target=copy;for(const part of parts) target=target[part]??=( {} );target[leaf]=value;return copy;}

// Remaining native context declarations have task-level editing homes, not another profile form.
Object.assign(initial.brand,{industrySector:''});
Object.assign(initial.context,{needs:'Thoughtful gifts with a personal touch',founderId:1,whatsappSameAsPhone:false,commerceEnabled:true,excludedSellingCountries:'',expectedProductCount:12,minPrice:'',maxPrice:'',hasBundles:false,shippingModel:'mixed',typicalShippingCharge:'',gstNumber:'',fssaiLicense:'',manufacturingAddress:'',showGstOnProducts:false,showFssaiOnProducts:false,showManufacturingAddress:false,legalName:'Saffron & Co.',foundedYear:'',proofPoints:'',searchIntent:'Find thoughtful handmade gifts',showBlogRailOnHome:true,highlightBestsellers:true,servicesUseForNavigation:true});
contextFields.story.push(f('needs','Audience needs','textarea'),f('founderId','Founder','person'));
contextFields.contact.push(f('whatsappSameAsPhone','Use public phone for WhatsApp','boolean'));
contextFields.search.push(f('legalName','Legal business name'),f('foundedYear','Founded year','number',{min:1000,max:9999}),f('proofPoints','Business proof points','textarea'),f('searchIntent','Search intent','textarea'));
contextFields.store.push(f('commerceEnabled','Store in website plan','boolean'),f('excludedSellingCountries','Excluded country codes'),f('expectedProductCount','Expected product count','number',{min:0}),f('minPrice','Expected minimum price','number',{min:0}),f('maxPrice','Expected maximum price','number',{min:0}),f('hasBundles','Offer bundles','boolean'),f('shippingModel','Shipping plan','select',{options:['free','flat','calculated','pickup','mixed']}),f('typicalShippingCharge','Typical shipping charge','number',{min:0}));
contextFields.regulatory=[f('gstNumber','GST number'),f('fssaiLicense','FSSAI license'),f('manufacturingAddress','Manufacturing address','textarea'),f('showGstOnProducts','Show GST on products','boolean'),f('showFssaiOnProducts','Show FSSAI on products','boolean'),f('showManufacturingAddress','Show manufacturing address','boolean')];
initial.shippingZones=[];initial.taxRates=[];
initial.orders=initial.orders.map(order=>({...order,items:order.items.map(line=>({...line,name:initial.products.find(item=>item.id===line.productId).name}))}));

initial.media=initial.media.map((item,index)=>({...item,authorId:index===2?4:index===1?2:1}));

Object.assign(initial.context,{x:'',pinterest:''});
contextFields.contact.push(f('x','X','url'),f('pinterest','Pinterest','url'));

// Source-linked categories illustrate the existing service taxonomy adapter.
initial.categories.push({id:6,name:'Gifting',kind:'service',description:'Personal celebrations.',indexable:true,seoTitle:'',seoDescription:''},{id:7,name:'Business',kind:'service',description:'Gifts for your people.',indexable:true,seoTitle:'',seoDescription:''});
initial.services=initial.services.map((item,index)=>{const {category,...record}=item;return {...record,categoryId:index===0?6:7};});
initial.pages=initial.pages.map(item=>({...item,indexable:item.status==='Published'}));
initial.activity=initial.activity.filter(item=>item.kind!=='Update');
export const categorySource=kind=>({post:'posts',product:'products',service:'services'})[kind];
export const notificationTopics=[
 {id:'admin_new_order',label:'New store orders',detail:'An order needs your attention.',roles:commerce},
 {id:'admin_new_comment',label:'New comments',detail:'A conversation is waiting for review.',roles:admin},
 {id:'admin_guest_application',label:'Guest applications',detail:'Someone would like to contribute.',roles:admin},
 {id:'admin_guest_submission',label:'Guest submissions',detail:'An article is ready for editorial review.',roles:[...admin,'editor']},
 {id:'admin_guest_product_submission',label:'Guest product proposals',detail:'A product proposal needs store review.',roles:commerce},
 {id:'editorial_post_status',label:'Article status',detail:'Updates to your own articles.',roles:['author']},
 {id:'securetrack_incident',label:'SecureTrack incidents',detail:'Important alerts under the existing security policy.',roles:admin},
 {id:'admin_live_visitor',label:'Live visitors',detail:'Visitor logging is off in this sample site profile.',roles:admin,available:false},
 {id:'admin_visitor_summary',label:'Visitor summary',detail:'Visitor analytics is off in this sample site profile.',roles:admin,available:false},
];
export const notificationChannels=[
 {id:'app',label:'Browser / PWA',detail:'This device · permission needed when connected.',available:true},
 {id:'email',label:'Email',detail:'Your verified account email.',available:true},
 {id:'whatsapp',label:'WhatsApp',detail:'Your verified number through Key.kiwe.',available:true},
 {id:'sms',label:'SMS',detail:'A provider has not been connected.',available:false},
];
initial.notifications=[
 {id:1,title:'An order is ready to fulfil',text:'Sample order #1001 is waiting for your team.',target:'orders',roles:commerce,color:'green',when:'Today · sample',readBy:[]},
 {id:2,title:'A reader joined the conversation',text:'A comment on Made by hand is waiting for review.',target:'activity',roles:[...admin,'editor'],color:'purple',when:'Today · sample',readBy:[]},
 {id:3,title:'Your story is ready to continue',text:'Pick up the story behind our signature recipe.',target:'posts',roles:editorial,color:'apricot',when:'Earlier · sample',readBy:['administrator','editor']},
];
notificationTopics.push(
 {id:'guest_post_status',label:'Guest approval & article decisions',detail:'Your application approval, decline and article publication updates.',roles:['contributor']},
 {id:'guest_product_status',label:'Your product proposals',detail:'Review and publishing updates for your own proposals.',roles:['contributor'],site:'commerce'},
 {id:'newsletter',label:'Newsletter',detail:'Highlights from the publication you follow.',site:'publication'},
 {id:'new_post',label:'New posts',detail:'Stories from categories you follow.',site:'publication'},
 {id:'new_offer',label:'Offers and coupons',detail:'Offers for your own account.',site:'commerce'},
 {id:'price_change',label:'Sale and price changes',detail:'Price changes to products you follow.',site:'commerce'},
 {id:'stock_update',label:'Stock updates',detail:'Products you follow are available again.',site:'commerce'},
 {id:'new_product',label:'New products',detail:'New arrivals in categories you follow.',site:'commerce'},
 {id:'order_status',label:'Your order status',detail:'Progress on your own orders.',site:'commerce'},
 {id:'cart_reminder',label:'Saved cart reminders',detail:'Reminders about your own unfinished cart.',site:'commerce'}
);
for(const topic of notificationTopics){topic.group=topic.roles?'work':'personal';topic.roles??=Object.keys(roleNames);if(['admin_new_order','admin_guest_product_submission'].includes(topic.id))topic.site='commerce';}
initial.siteProfile='commerce';
initial.guestSettings={posts:true,products:true};
initial.guestApplications=[{id:7,name:'Esha',about:'I would like to share stories about local food.',intents:['posts'],verified:true,status:'Pending',accountRole:'Subscriber',reason:''},{id:8,name:'Noor',about:'Food stories and gifts made by small businesses.',intents:['posts','products'],verified:true,status:'Approved',accountRole:'Contributor (Guest)',reason:''},{id:9,name:'Dev',about:'Product stories from our workshop.',intents:['products'],verified:true,status:'Pending',accountRole:'Customer',reason:''}];
initial.posts.push({id:3,title:'A Guest story from our neighbourhood',body:'A thoughtful story about local food, small businesses and the people who bring a neighbourhood together.',categoryId:3,status:'Pending',authorId:8,guestSubmission:true,imageId:0,excerpt:'',seoTitle:'',seoDescription:''});
initial.products.push({id:3,name:'A Guest handmade gift proposal',description:'A sample proposal for a handmade seasonal gift for review by the shop team.',categoryId:4,status:'Pending',authorId:8,guestSubmission:true,price:'350',stock:'0',sku:'',weight:'0',imageId:0,seoTitle:'',seoDescription:''});
export const topicsFor=(data,role)=>notificationTopics.filter(topic=>topic.roles.includes(role)&&(!topic.site||topic.site===data.siteProfile));
export const modulesFor=(data,role)=>modules.filter(module=>module.roles.includes(role)&&(data.siteProfile==='commerce'||!['products','orders','store'].includes(module.id))&&(module.id!=='reviews'||role!=='shop_manager'||data.siteProfile==='commerce'));
initial.notificationPreferences=Object.fromEntries(Object.keys(roleNames).map(role=>[role,{channels:['email'],topicChannels:{},topics:topicsFor(initial,role).filter(topic=>topic.group==='work'&&topic.available!==false).map(topic=>topic.id)}]));
export const notificationsFor=(data,role)=>data.notifications.filter(item=>item.roles.includes(role)&&modulesFor(data,role).some(module=>module.id===item.target));
export const notificationUnread=(data,role)=>notificationsFor(data,role).filter(item=>!item.readBy.includes(role)).length;

export const menuItemsFor=data=>data.navigation.filter(item=>item.dsa&&(!['pages','services'].includes(item.type)||data[item.type].find(record=>record.id===item.objectId)?.status==='Published'));
