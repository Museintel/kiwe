const fs=require('node:fs/promises'),path=require('node:path'),http=require('node:http'),crypto=require('node:crypto');
const {build}=require('esbuild');
const {chromium}=require(process.env.KIWE_PLAYWRIGHT||'playwright');
const out=path.resolve(process.argv[2]||'');
if(!process.argv[2]||out.startsWith(path.resolve(__dirname,'../..')+path.sep))throw Error('Choose an evidence directory outside the repository.');
const checks=[];function check(ok,label){if(!ok)throw Error(label);checks.push(label);}
const proof=value=>crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
const readyChannels=[{id:'email',label:'Email',ready:true,titleLimit:160,bodyLimit:4000},{id:'sms',label:'SMS',ready:true,titleLimit:160,bodyLimit:480},{id:'whatsapp',label:'WhatsApp',ready:false,titleLimit:160,bodyLimit:1100},{id:'app',label:'Browser / PWA',ready:true,titleLimit:80,bodyLimit:240}];
let groups=[{id:'sms',name:'SMS',smart:true,description:'Verified phone with an SMS choice.',expected:''},{id:'email',name:'Email',smart:true,description:'Verified email with an email choice.',expected:''},{id:'whatsapp',name:'WhatsApp',smart:true,description:'Verified phone with a WhatsApp choice.',expected:''},{id:'app',name:'Browser / PWA',smart:true,description:'Enrolled devices with permission.',expected:''}];
let drafts=[],delivery={active:null,history:[]},writes=[],mode='normal';
const catalog=()=>({types:[{id:'newsletter',label:'Newsletter',topic:'newsletter',source:''},{id:'article',label:'News / article',topic:'new_post',source:'post'}],channels:readyChannels,enabled:true,groups,limit:500});
function bodyOf(d){return {name:d.name,type:d.type,sourceId:d.sourceId,group:d.group,channels:d.channels,copies:d.copies,scheduledAt:d.scheduledAt};}
function json(res,value,status=200){res.writeHead(status,{'Content-Type':'application/json','Cache-Control':'no-store'});res.end(JSON.stringify(value));}
(async()=>{
 await fs.mkdir(out,{recursive:true});
 const built=await build({absWorkingDir:__dirname,stdin:{contents:"import React from 'react';import{createRoot}from'react-dom/client';import{NativeCampaignWorkspace}from'./native-campaigns.jsx';createRoot(document.getElementById('root')).render(<NativeCampaignWorkspace config={window.TEST_CAMPAIGNS}/>);",loader:'jsx',resolveDir:__dirname},bundle:true,minify:true,format:'iife',write:false,outfile:'app.js',define:{'process.env.NODE_ENV':'"production"'}});
 const assets=new Map(built.outputFiles.map(file=>['/'+path.basename(file.path),file.contents]));
 const server=http.createServer(async(req,res)=>{
  const origin='http://127.0.0.1:'+server.address().port,url=new URL(req.url,origin),route=url.searchParams.get('rest_route')||'';
  if(route){
   if(req.headers['x-wp-nonce']!=='synthetic-rest-nonce')return json(res,{code:'bad_nonce',message:'Missing nonce'},403);
   let body={};if(req.method==='POST'){let raw='';for await(const part of req)raw+=part;body=JSON.parse(raw);writes.push({route,body,headers:req.headers});if(req.headers['x-kiwe-mutation']!=='1')return json(res,{code:'bad_mutation',message:'Missing mutation proof'},403);}
   if(route==='/dsa/v1/workspace/user-groups'){
    if(req.method==='POST'){const group={id:'g-'+String(groups.length).padStart(16,'0'),name:body.name,smart:false,description:'Your selected WordPress accounts.',expected:proof(body.name)};groups=[...groups,group];return json(res,group);}
    return json(res,{groups,limit:500});
   }
   if(route.startsWith('/dsa/v1/workspace/user-groups/')){
    const id=route.split('/').pop(),group=groups.find(g=>g.id===id);
    if(!group)return json(res,{code:'missing',message:'Group missing'},409);
    return json(res,{group,items:[],page:1,hasNext:false,count:0,limited:false,groups});
   }
   if(route==='/dsa/v1/workspace/campaigns'){
    if(req.method==='GET'){
     if(url.searchParams.get('view')==='automations')return json(res,{posts:{audience:{counts:{email:1},sampled:false},delivery:{active:null,history:[]}},cart:null});
     if(url.searchParams.get('id')){const draft=drafts.find(d=>d.id===url.searchParams.get('id'));return draft?json(res,{draft,catalog:catalog()}):json(res,{code:'missing',message:'Campaign missing'},409);}
     return json(res,{items:drafts.filter(d=>(!url.searchParams.get('status')||d.status===url.searchParams.get('status'))&&(!url.searchParams.get('search')||d.name.toLowerCase().includes(url.searchParams.get('search').toLowerCase()))),catalog:catalog(),delivery});
    }
    if(body.operation==='save'){
     const old=body.id?drafts.find(d=>d.id===body.id):null;
     if(body.id&&(!old||body.expected!==old.expected))return json(res,{code:'stale',message:'Draft changed. Reload.'},409);
     const draft={...bodyOf(body),id:old?.id||'c-'+String(drafts.length+1).padStart(16,'0'),status:'draft',author:1,created:old?.created||1700000000,updated:1700000001};draft.expected=proof(draft);drafts=old?drafts.map(d=>d.id===old.id?draft:d):[draft,...drafts];return json(res,draft);
    }
    const old=drafts.find(d=>d.id===body.id);
    if(!old||body.expected!==old.expected)return json(res,{code:'stale',message:'Reload this campaign.'},409);
    if(body.operation==='archive'){drafts=drafts.filter(d=>d.id!==old.id);return json(res,{archived:true});}
    const review={count:2,registered:2,anonymous:0,total:4,counts:Object.fromEntries(old.channels.map(c=>[c,c==='email'?2:0])),excluded:{noConsent:1,unverified:1,noDevice:0,unavailable:0,banned:0,duplicate:0},limited:false,enabled:true,copies:Object.fromEntries(old.channels.map(c=>[c,{...old.copies[c],body:old.copies[c].body+'\n\nhttps://example.test/\n{personal unsubscribe link}',segments:c==='sms'?{encoding:'GSM-7',units:220,segments:2}:null}])),expected:proof(old.id+old.expected+'two')};
    if(body.operation==='preview')return json(res,review);
    if(body.operation==='queue'){
     if(mode==='stale'){mode='normal';return json(res,{code:'stale',message:'The reachable audience changed. Review again.'},409);}
     if(body.confirmed!==true||body.audienceExpected!==review.expected)return json(res,{code:'stale',message:'Review the audience again.'},409);
     const next={...old,status:'queued',updated:1700000002,jobId:'job-fixture'};next.expected=proof(next);drafts=drafts.map(d=>d.id===old.id?next:d);delivery={active:{id:'job-fixture',subject:next.name,status:'queued',count:2,attempted:0,sent:0,skipped:0,unknown:0,expected:proof('job-fixture')},history:[]};return json(res,{queued:true,job:delivery.active,draft:next,message:'Campaign queued.'});
    }
    if(body.operation==='cancel'){delivery.history=[{...delivery.active,status:'cancelled'}];delivery.active=null;return json(res,{ok:true,message:'Remaining recipients cancelled.'});}
    return json(res,{code:'operation',message:'Unknown operation'},400);
   }
   return json(res,{code:'missing',message:'Unknown route'},404);
  }
  if(url.pathname==='/'){res.writeHead(200,{'Content-Type':'text/html'});res.end('<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/app.css"></head><body class="kiwe-campaign-app"><div id="root"></div><script>window.TEST_CAMPAIGNS='+JSON.stringify({campaignEndpoint:origin+'/?rest_route=/dsa/v1/workspace/campaigns',userGroupsEndpoint:origin+'/?rest_route=/dsa/v1/workspace/user-groups',nonce:'synthetic-rest-nonce',home:origin,siteUrl:origin,siteName:'Sample studio'})+'</script><script src="/app.js"></script></body></html>');return;}
  if(assets.has(url.pathname)){res.writeHead(200,{'Content-Type':url.pathname.endsWith('.css')?'text/css':'text/javascript'});res.end(assets.get(url.pathname));return;}
  res.writeHead(404);res.end();
 });
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const base='http://127.0.0.1:'+server.address().port;
 const browser=await chromium.launch({headless:true,...(process.env.KIWE_CHROME?{executablePath:process.env.KIWE_CHROME}:{})});
 const errors=[],foreign=[];
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}});page.on('pageerror',error=>errors.push(error.message));page.on('request',request=>{if(!request.url().startsWith(base))foreign.push(request.url());});
  await page.goto(base);await page.getByRole('heading',{name:'Campaigns.'}).waitFor();
  check(await page.getByRole('button',{name:'+ New campaign'}).isVisible()&&await page.getByRole('combobox',{name:'Sort campaigns'}).isVisible(),'Campaigns follow the shared title, tabs, action and filter layout');
  await page.getByRole('button',{name:'+ New campaign'}).click();const editor=page.getByRole('dialog',{name:'New campaign'});await editor.getByLabel('Campaign name').fill('A new Kiwe story');await editor.getByLabel('Email subject').fill('A new Kiwe story');await editor.getByRole('textbox',{name:/Message/}).fill('A carefully written article for readers.');
  check(await editor.getByRole('button',{name:'Create / edit'}).isVisible()&&await editor.getByRole('button',{name:'Audience'}).isDisabled(),'Campaign journey begins with a saveable draft');
  await editor.getByRole('button',{name:'Save draft & continue'}).click();const draftDialog=page.getByRole('dialog',{name:'A new Kiwe story'});await draftDialog.getByRole('button',{name:'Review reach & messages'}).waitFor();check(drafts.length===1&&drafts[0].status==='draft'&&writes[0].headers['x-kiwe-mutation']==='1','Draft saves once with native mutation proof');
  await draftDialog.getByRole('button',{name:'Review reach & messages'}).click();await draftDialog.getByText('reachable recipients').waitFor();check(await draftDialog.getByText('without topic consent').isVisible()&&await draftDialog.getByText('personal unsubscribe link',{exact:false}).isVisible(),'Review shows reach, exclusions and final channel writing');await page.screenshot({path:path.join(out,'campaign-review-1440.png'),fullPage:true});
  check(await draftDialog.getByRole('button',{name:'Queue campaign'}).isDisabled(),'Sending requires an explicit reach and writing confirmation');
  await draftDialog.getByRole('checkbox',{name:/I reviewed the messages and reach/}).check();await draftDialog.getByRole('button',{name:'Queue campaign'}).dblclick();await draftDialog.getByText('This campaign is queued.').waitFor();check(writes.filter(entry=>entry.body.operation==='queue').length===1&&drafts[0].status==='queued','Double click commits one reviewed queue write');
  await draftDialog.getByRole('button',{name:'Close'}).click();await page.getByRole('region',{name:'Active campaign'}).waitFor();check(await page.getByRole('button',{name:'Cancel remaining recipients'}).isVisible(),'Queued delivery appears with bounded status and cancel control');
  await page.getByRole('button',{name:'Groups',exact:true}).click();await page.getByRole('heading',{name:'Browser / PWA'}).waitFor();check(await page.getByRole('heading',{name:'SMS'}).isVisible()&&await page.getByRole('heading',{name:'WhatsApp'}).isVisible(),'Channel audiences are shared with the campaign Groups tab');
  await page.getByRole('button',{name:'+ New group'}).click();const group=page.getByRole('dialog',{name:'New user group'});await group.getByLabel('Name').fill('Readers of this story');await group.getByRole('button',{name:'Create group'}).click();await page.getByRole('heading',{name:'Readers of this story'}).waitFor();check(groups.length===5&&writes.some(entry=>entry.route==='/dsa/v1/workspace/user-groups'&&entry.body.name==='Readers of this story'),'New group uses the shared group service');
  await page.getByRole('button',{name:'Automations'}).click();await page.getByRole('heading',{name:'New article'}).waitFor();check(await page.getByText(/First publication alerts follow/).isVisible(),'Publication automation remains available without WooCommerce');
  await page.getByRole('button',{name:'Campaigns',exact:true}).click();await page.getByRole('button',{name:'+ New campaign'}).click();const another=page.getByRole('dialog',{name:'New campaign'});await another.getByLabel('Campaign name').fill('Unused draft');await another.getByLabel('Email subject').fill('Unused draft');await another.getByRole('textbox',{name:/Message/}).fill('A second unsent campaign.');await another.getByRole('button',{name:'Save draft & continue'}).click();const savedAnother=page.getByRole('dialog',{name:'Unused draft'});await savedAnother.getByRole('button',{name:'Archive draft'}).click();await savedAnother.getByRole('button',{name:'Confirm archive'}).click();check(drafts.length===1&&drafts[0].name==='A new Kiwe story','Explicit archive removes an unused draft without touching delivery history');
  for(const width of [1440,768,390,360]){await page.setViewportSize({width,height:900});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow at '+width);await page.screenshot({path:path.join(out,'campaign-'+width+'.png'),fullPage:true});}
  check(!errors.length&&!foreign.length,'No browser errors or external requests');
  const result={passed:true,scope:'Real Chrome/React against synthetic HTTP; no WordPress account or live delivery',checks:checks.length,labels:checks,sourceHashes:Object.fromEntries(await Promise.all(['native-campaigns.jsx','native-campaigns.css','native-user-groups.jsx'].map(async name=>[name,proof(await fs.readFile(path.join(__dirname,name)))])))};
  await fs.writeFile(path.join(out,'browser.json'),JSON.stringify(result,null,2));console.log(JSON.stringify({passed:true,checks:checks.length,out}));
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
