const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../../wp-content/mu-plugins/dsa/assets/js/workspace-app-use.js'),'utf8');let checks=0;
function check(value,label){assert(value,label);checks++;}
function storage(initial={}){const map=new Map(Object.entries(initial));return{getItem:key=>map.get(key)||null,setItem:(key,value)=>map.set(key,String(value)),removeItem:key=>map.delete(key)};}
function page(options={}){
 const calls=[],listeners={},now={value:1000000},session=options.session||storage(),local=options.local||storage({'dsa_permission_visitor':'existing-device-123'});
 const context={URL,Uint8Array,Date:{now:()=>now.value},location:{href:'https://site.test/wp-admin/admin.php?page=kiwe-workspace',origin:'https://site.test'},navigator:{userAgent:options.ios?'iPhone Safari':'Chrome',standalone:!!options.ios},localStorage:local,sessionStorage:session,document:{visibilityState:options.hidden?'hidden':'visible',addEventListener:(type,fn)=>listeners[type]=fn},fetch:(url,args)=>{calls.push({url,args});return Promise.resolve({ok:!options.failure,json:()=>Promise.resolve({ok:!options.failure})});}};
 context.window={KIWE_WORKSPACE_APP_USE:{endpoint:options.external?'https://other.test/app':'https://site.test/wp-json/dsa/v1/app/activity',nonce:'synthetic-nonce',actor:7},matchMedia:()=>({matches:options.standalone!==false}),crypto:{getRandomValues:bytes=>bytes.fill(4)},addEventListener:(type,fn)=>listeners[type]=fn};vm.runInNewContext(source,context);return{calls,listeners,now,session,local,context};
}
(async()=>{
 let p=page();await new Promise(setImmediate);check(p.calls.length===1,'An installed staff page records one functional app opening');const call=p.calls[0],payload=JSON.parse(call.args.body);check(payload.visitorId==='existing-device-123'&&payload.event==='opened'&&!('userId'in payload),'Existing device identity is reused; native authentication owns the account');check(call.args.credentials==='same-origin'&&call.args.cache==='no-store'&&call.args.keepalive===true,'Native private transport remains nonblocking and survives ordinary navigation');
 p.listeners.pageshow();p.listeners.visibilitychange();check(p.calls.length===1,'Repeated page/show activity is throttled');p.now.value+=300001;p.listeners.visibilitychange();await new Promise(setImmediate);check(p.calls.length===2,'Foreground return records use after the existing five-minute interval');
 let next=page({session:p.session,local:p.local});check(!next.calls.length,'A subsequent private document shares the throttle');
 for(const options of [{standalone:false},{hidden:true},{external:true}])check(page(options).calls.length===0,'No ordinary-browser, hidden-tab or cross-origin request');
 p=page({ios:true,standalone:false});check(JSON.parse(p.calls[0].args.body).platform==='ios','iPhone standalone detection keeps the existing platform contract');
 p=page({local:storage()});check(/^p[0-9a-f]{32}$/.test(JSON.parse(p.calls[0].args.body).visitorId),'First private launch allocates only a bounded device identifier');
 p=page({failure:true});await new Promise(setImmediate);p.listeners.pageshow();check(p.calls.length===2,'An unsuccessful observation is retryable without blocking UI');
 console.log(JSON.stringify({passed:true,cleanup:true,checks,scope:'Actual small private PWA script; native endpoint and physical installation are separately qualified'}));
})().catch(error=>{console.log(JSON.stringify({passed:false,cleanup:true,checks,error:error.message}));process.exitCode=1;});
