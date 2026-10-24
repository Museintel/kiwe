const fs=require('node:fs/promises'),path=require('node:path'),http=require('node:http'),crypto=require('node:crypto');
const{build}=require('esbuild');
const{chromium}=require(process.env.KIWE_PLAYWRIGHT||'playwright');

(async()=>{
 const out=path.resolve(process.argv[2]||'');
 if(!process.argv[2]||out.startsWith(path.resolve(__dirname,'../..')+path.sep))throw Error('Choose an external evidence directory.');
 const checks=[],check=(ok,label)=>{if(!ok)throw Error(label);checks.push(label);},expected='a'.repeat(64);
 const built=await build({absWorkingDir:__dirname,entryPoints:['taxonomy-app-entry.jsx'],bundle:true,format:'iife',platform:'browser',target:['es2020'],minify:true,write:false,outfile:'tag-groups-test.js'});
 const appJs=built.outputFiles.find(file=>file.path.endsWith('.js')).contents;
 const appCss=await fs.readFile(path.join(__dirname,'../../wp-content/mu-plugins/dsa/assets/css/taxonomy-app.css'));
 const definitions=[
  {id:'post_tag',label:'Post tags',objectType:'post',postType:'post',hierarchical:false,canManage:true},
  {id:'product_tag',label:'Product tags',objectType:'product',postType:'product',hierarchical:false,canManage:true},
 ];
 let groups=[
  {id:1,kind:'post',name:'Editorial',expected},
  {id:2,kind:'product',name:'Seasonal',expected},
 ];
 const tags={
  post:[
   {id:31,taxonomy:'post_tag',name:'Opinion',slug:'opinion',parent:0,description:'',count:3,indexable:true,objectType:'post',label:'Post tags',expected},
   {id:32,taxonomy:'post_tag',name:'Interview',slug:'interview',parent:0,description:'',count:2,indexable:true,objectType:'post',label:'Post tags',expected},
  ],
  product:[
   {id:41,taxonomy:'product_tag',name:'Gift ready',slug:'gift-ready',parent:0,description:'',count:4,indexable:true,objectType:'product',label:'Product tags',expected},
  ],
 };
 const membership=new Map([[32,1],[41,2]]),writes=[];
 function send(res,value,status=200){res.writeHead(status,{'Content-Type':'application/json','Cache-Control':'no-store'});res.end(JSON.stringify(value));}
 function groupExpected(group){return crypto.createHash('sha256').update(JSON.stringify([group.id,group.kind,group.name])).digest('hex');}
 const server=http.createServer(async(req,res)=>{
  const origin='http://127.0.0.1:'+server.address().port,url=new URL(req.url,origin),route=url.searchParams.get('rest_route')||'';
  if(route){
   check(req.headers['x-wp-nonce']==='n','REST request keeps the signed-in nonce');
   let body;
   if(req.method==='POST'){
    let raw='';for await(const chunk of req)raw+=chunk;body=JSON.parse(raw||'{}');
    check(req.headers['x-kiwe-mutation']==='1','Tag group mutation keeps the explicit mutation proof');
    writes.push({route,body});
   }
   if(route.startsWith('/dsa/v1/workspace/tag-groups')){
    const kind=body?.kind||url.searchParams.get('kind')||'post';
    if(route.endsWith('/tags')){
     const groupId=Number(url.searchParams.get('groupId')||0),page=Number(url.searchParams.get('page')||1),search=(url.searchParams.get('search')||'').toLowerCase();
     const items=tags[kind].filter(item=>(membership.get(item.id)||0)===groupId&&item.name.toLowerCase().includes(search)).map(item=>({id:item.id,name:item.name,count:item.count}));
     return send(res,{kind,groupId,page,hasNext:false,items});
    }
    if(route.endsWith('/move')){
     for(const id of body.tagIds)body.targetId?membership.set(id,body.targetId):membership.delete(id);
     return send(res,{requested:body.tagIds.length,moved:body.tagIds.length,failed:0,outcomes:body.tagIds.map(tagId=>({tagId,status:'moved'}))});
    }
    if(req.method==='POST'&&/\/tag-groups\/[1-9][0-9]*$/.test(route)){
     const id=Number(route.split('/').at(-1));groups=groups.map(group=>group.id===id&&group.kind===kind?{...group,name:body.name}:group);
     const saved=groups.find(group=>group.id===id&&group.kind===kind);saved.expected=groupExpected(saved);return send(res,saved);
    }
    if(req.method==='POST'){
     const id=Math.max(0,...groups.map(group=>group.id))+1,next={id,kind,name:body.name,expected:''};next.expected=groupExpected(next);groups.push(next);return send(res,next);
    }
    return send(res,{kind,groups:groups.filter(group=>group.kind===kind)});
   }
   if(route.startsWith('/dsa/v1/workspace/taxonomies')){
    if(route.endsWith('/post_tag'))return send(res,{items:tags.post,page:1,has_next:false,search:'',taxonomy:definitions[0],menus:[]});
    if(route.endsWith('/product_tag'))return send(res,{items:tags.product,page:1,has_next:false,search:'',taxonomy:definitions[1],menus:[]});
    return send(res,{taxonomies:definitions});
   }
  }
  if(url.pathname==='/tags'){
   const config={endpoint:origin+'/?rest_route=/dsa/v1/workspace/taxonomies&kind=tags',tagGroupEndpoint:origin+'/?rest_route=/dsa/v1/workspace/tag-groups',tagKinds:['post','product'],seoEndpoint:origin+'/?rest_route=/dsa/v1/workspace/seo',view:'tags',nonce:'n',home:origin,siteName:'Studio',siteTagline:'Made for people',siteUrl:origin+'/website',profileUrl:origin+'/?kiwe-screen=profile'};
   res.setHeader('Content-Type','text/html');res.end('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div id="kiwe-taxonomy-app"></div><script>window.KIWE_TAXONOMY_APP='+JSON.stringify(config)+'</script><link rel="stylesheet" href="/app.css"><script src="/app.js"></script>');return;
  }
  if(url.pathname==='/app.js'||url.pathname==='/app.css'){res.setHeader('Content-Type',url.pathname.endsWith('.css')?'text/css':'text/javascript');res.end(url.pathname.endsWith('.css')?appCss:appJs);return;}
  res.writeHead(404);res.end();
 });
 await fs.mkdir(out,{recursive:true});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const base='http://127.0.0.1:'+server.address().port,browser=await chromium.launch({headless:true,executablePath:process.env.KIWE_CHROME||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'}),errors=[];
 try{
  const page=await browser.newPage({viewport:{width:1365,height:900}});page.on('pageerror',error=>errors.push(error.message));page.on('dialog',dialog=>dialog.accept());
  await page.goto(base+'/tags');await page.getByRole('heading',{name:'Tags.'}).waitFor();
  check(await page.getByRole('button',{name:'Post tags',exact:true}).isVisible()&&await page.getByRole('button',{name:'Product tags',exact:true}).isVisible()&&await page.getByRole('button',{name:'Tag categories',exact:true}).isVisible(),'Tags exposes native Post, Product and folder views');
  check(await page.locator('.native-tax-card h2',{hasText:'Opinion'}).count()===1&&await page.getByRole('button',{name:'SEO'}).count()===0&&await page.getByRole('switch',{name:/Indexable/}).count()===0,'Everyday tag cards stay focused on naming and organization');
  await page.getByRole('button',{name:'Tag categories',exact:true}).click();await page.getByRole('navigation',{name:'Tag category types'}).waitFor();
  check(await page.getByRole('button',{name:'Post tag categories',exact:true}).isVisible()&&await page.getByRole('button',{name:'Product tag categories',exact:true}).isVisible(),'Tag folders keep post and product authority separate');
  await page.getByRole('button',{name:'+ New category'}).click();const create=page.getByRole('dialog',{name:'New tag category'});await create.getByLabel('Name').fill('Campaigns');await create.getByRole('button',{name:'Create category'}).click();
  await page.getByRole('heading',{name:'Campaigns',exact:true}).waitFor();await page.getByRole('button',{name:'Close',exact:true}).click();check(await page.locator('.native-tax-card h2',{hasText:'Campaigns'}).count()===1,'A Kiwe folder can be created without creating a duplicate taxonomy');
  await page.locator('.native-tax-card h2',{hasText:'Ungrouped'}).click();await page.getByText('Opinion',{exact:true}).waitFor();await page.getByRole('button',{name:'Select all on this page'}).click();await page.getByRole('button',{name:'Move 1 selected'}).click();
  const move=page.getByRole('dialog',{name:'Move selected tags'});await move.getByRole('radio',{name:'Campaigns'}).check();await move.getByRole('button',{name:'Move tags'}).click();await move.waitFor({state:'hidden'});
  check(membership.get(31)===3&&await page.getByText('1 tags moved. Their post or product assignments stayed unchanged.').isVisible(),'Moving a tag changes only its Kiwe folder membership');
  await page.getByRole('button',{name:'Close',exact:true}).click();await page.locator('.native-tax-card h2',{hasText:'Campaigns'}).click();await page.getByText('Opinion',{exact:true}).waitFor();
  await page.getByLabel('Category name').fill('Campaign planning');await page.getByRole('button',{name:'Save name'}).click();await page.getByText('Category name saved.').waitFor();await page.getByRole('button',{name:'Close',exact:true}).click();check(await page.locator('.native-tax-card h2',{hasText:'Campaign planning'}).count()===1,'Tag folder rename has exact readback in the browser workflow');
  await page.getByRole('button',{name:'Product tag categories',exact:true}).click();await page.locator('.native-tax-card h2',{hasText:'Seasonal'}).waitFor();check(await page.locator('.native-tax-card h2',{hasText:'Campaign planning'}).count()===0,'Product folders do not leak post folder records');
  for(const width of [768,390]){await page.setViewportSize({width,height:900});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Tag groups fit '+width+'px');await page.screenshot({path:path.join(out,'tag-groups-'+width+'.png'),fullPage:true});}
  check(errors.length===0,'Tag group browser flow has no React runtime errors');
  await fs.writeFile(path.join(out,'browser.json'),JSON.stringify({passed:true,scope:'Actual React tag UI with synthetic same-origin WordPress I/O',checks,writeCount:writes.length},null,2));
  console.log(JSON.stringify({passed:true,checks:checks.length,out}));
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
