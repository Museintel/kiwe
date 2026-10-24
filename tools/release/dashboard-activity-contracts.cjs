const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs'),vm=require('node:vm'),cp=require('node:child_process');
const root=path.resolve(__dirname,'../..'),base=path.join(root,'tools/workspace-prototype'),{buildSync}=require(path.join(base,'node_modules/esbuild'));
let checks=0;const check=(value,label)=>{assert(value,label);checks++;};
(async()=>{
 const {notificationPresentation}=await import('../workspace-prototype/notification-presentation.mjs');
 const {savedCartEntry}=await import('../workspace-prototype/saved-cart-entry.mjs');
 for(const [status,tone]of [['completed','success'],['cancelled','danger'],['failed','danger'],['processing','waiting'],['on-hold','hold'],['refunded','info'],['custom','neutral']])check(notificationPresentation({type:'admin_new_order',recordStatus:status}).tone===tone,'Native status owns notification colour: '+status);
 check(notificationPresentation({type:'admin_new_order',title:'Successful paid completed green order'}).tone==='neutral','Notification prose cannot manufacture success');
 check(notificationPresentation({type:'admin_abandoned_cart'}).tone==='waiting','A cart waiting for contact is not a completed order');
 for(const search of ['?cart=41','?cart=999','?cart=0','?cart=-1','?cart=2x','?cart=1.2','?cart=9007199254740992','']){
  const entry=savedCartEntry(search);check(entry.query.id===0&&entry.query.status==='abandoned'&&entry.query.page===1,'Notification entry leaves the bounded collection open: '+search);
 }
 check(savedCartEntry('?cart=41').reviewId===41,'The notification still opens its intended review');
 check(savedCartEntry('?cart=1.2').reviewId===0&&savedCartEntry('?cart=9007199254740992').reviewId===0,'Malformed or unsafe IDs cannot become review targets');
 const bundled=buildSync({absWorkingDir:base,entryPoints:['notification-timeline.jsx'],bundle:true,platform:'node',format:'cjs',write:false,external:['react']}).outputFiles[0].text;
 const context={module:{exports:{}},exports:{},require:name=>name==='react'?require(path.join(base,'node_modules/react')):require(name),Date};context.exports=context.module.exports;vm.runInNewContext(bundled,context);
 const React=require(path.join(base,'node_modules/react')),{renderToStaticMarkup}=require(path.join(base,'node_modules/react-dom/server'));
 const items=[{id:'one',type:'admin_new_order',recordStatus:'completed',title:'Accepted order',message:'Review fulfilment.',actionUrl:'/orders',actionLabel:'Review order',createdAt:1792630800},{id:'two',type:'admin_abandoned_cart',title:'Unknown cart',message:'Contact not yet known.',createdAt:1792630700,deliveries:[{channel:'email',status:'failed',detail:'Unavailable'}]}];
 const render=history=>renderToStaticMarkup(React.createElement(context.module.exports.NotificationTimeline,{items,history,safeLink:v=>v,deliveryLabels:{email:'Email'},deliveryStates:{failed:'Failed'}}));
 const current=render(false),history=render(true);check(current.includes('kiwe-status-stack')&&!current.includes('wf-grid'),'Updates render as a stack, without featured bento placement');
 check(current.indexOf('Accepted order')<current.indexOf('Unknown cart'),'Rendering preserves native chronology');
 check(current.includes('data-tone="success"')&&current.includes('data-tone="waiting"'),'Actual cards render the semantic status tones');
 check(current.includes('Review order')&&!current.includes('Delivery details'),'Current activity keeps its action and hides secondary delivery data');
 check(history.includes('Notification history')&&history.includes('<details')&&history.includes('Email')&&history.includes('Failed'),'History retains optional delivery receipts in a disclosure');
 const proof=cp.spawnSync('php',['tools/release/test-notification-presentation.php'],{cwd:root,encoding:'utf8',windowsHide:true});assert.equal(proof.status,0,proof.stderr||proof.stdout);const native=JSON.parse(proof.stdout);check(native.passed,'Native notification projection');checks+=native.checks.length;
 console.log(JSON.stringify({passed:true,checks,nativeWordPress:'Existing role/inbox gates plus named lab review required'}));
})().catch(error=>{console.error(error);process.exitCode=1;});
