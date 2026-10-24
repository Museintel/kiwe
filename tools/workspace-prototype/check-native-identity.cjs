const fs=require('node:fs/promises'),path=require('node:path'),http=require('node:http'),crypto=require('node:crypto');
const {build}=require('esbuild');const {chromium}=require(process.env.KIWE_PLAYWRIGHT||'playwright');
const out=path.resolve(process.argv[2]||'');
if(!process.argv[2]||out.startsWith(path.resolve(__dirname,'../..')+path.sep))throw Error('Choose external evidence directory.');
const checks=[];function check(ok,label){if(!ok)throw Error(label);checks.push(label);}
const identity={siteName:'Sample studio',tagline:'Ideas into useful things',industry:'Design studio',industrySector:'professional_services',siteType:'service',logoId:12,logoInverseId:13,siteIconId:14,squareLogoId:0,roundLogoId:0,activities:{commerce:false,blog:false,publication:false,physical_store:false,service_area:false},phone:'+91 90000 00000',email:'hello@example.test'};
function fingerprint(){return crypto.createHash('sha256').update(JSON.stringify([identity.siteName,identity.tagline,identity.industry,identity.industrySector,identity.siteType,identity.logoId,identity.logoInverseId,identity.siteIconId,identity.phone,identity.email,identity.squareLogoId,identity.roundLogoId,identity.activities])).digest('hex');}
function projection(){return {...identity,logoUrl:'',logoInverseUrl:'',siteIconUrl:'',squareLogoUrl:'',roundLogoUrl:'',expected:fingerprint()};}
let mode='normal',writes=0,lastBody=null,lastHeaders=null;
(async()=>{
 await fs.mkdir(out,{recursive:true});
 const built=await build({absWorkingDir:__dirname,stdin:{contents:"import React from 'react';import{createRoot}from'react-dom/client';import{NativeIdentity}from'./native-identity.jsx';createRoot(document.getElementById('kiwe-identity-app')).render(<NativeIdentity config={window.KIWE_IDENTITY_APP}/>);",loader:'jsx',resolveDir:__dirname},bundle:true,minify:true,format:'iife',write:false,outfile:'app.js',define:{'process.env.NODE_ENV':'"production"'}});
 const assets=new Map(built.outputFiles.map(file=>['/'+path.basename(file.path),file.contents]));
 const server=http.createServer(async(req,res)=>{
  res.setHeader('Cache-Control','no-store');const origin='http://127.0.0.1:'+server.address().port,url=new URL(req.url,origin),route=url.searchParams.get('rest_route');
  if(route==='/dsa/v1/workspace/identity'){
   res.setHeader('Content-Type','application/json');
   if(req.method==='POST'){
    writes++;lastHeaders=req.headers;let raw='';for await(const chunk of req)raw+=chunk;lastBody=JSON.parse(raw);
    if(mode==='conflict'){mode='normal';identity.tagline='Edited in another screen';res.writeHead(409);res.end(JSON.stringify({message:'Business identity changed in another screen. Reload and review it before saving.'}));return;}
    if(lastBody.expected!==fingerprint()){res.writeHead(409);res.end(JSON.stringify({message:'Stale identity'}));return;}
    const changes=lastBody.changes||{};
    for(const key of ['siteName','tagline','industry','industrySector','siteType'])if(Object.hasOwn(changes,key))identity[key]=String(changes[key]??'').trim();
    for(const key of ['logoId','logoInverseId','siteIconId','squareLogoId','roundLogoId'])if(Object.hasOwn(changes,key))identity[key]=Number(changes[key]||0);
    if(Object.hasOwn(changes,'activities'))identity.activities={...changes.activities};
    if(mode==='lost-response'){mode='normal';res.setHeader('Content-Type','text/html');res.writeHead(503);res.end('<h1>Service unavailable</h1>');return;}
    res.end(JSON.stringify(projection()));return;
   }
   if(req.method==='GET'){res.end(JSON.stringify(projection()));return;}
   res.writeHead(405);res.end(JSON.stringify({message:'Method not allowed'}));return;
  }
  if(url.pathname==='/'){res.setHeader('Content-Type','text/html');res.end('<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/app.css"></head><body class="kiwe-identity-app"><div id="kiwe-identity-app"></div><script>window.KIWE_IDENTITY_APP='+JSON.stringify({endpoint:origin+'/?rest_route=/dsa/v1/workspace/identity',nonce:'synthetic-rest-nonce',home:origin,siteName:'Sample studio',areas:{story:origin+'/story',contact:origin+'/contact',brand:origin+'/brand','website-plan':origin+'/website-plan',services:origin+'/services'}})+'</script><script src="/app.js"></script></body></html>');return;}
  if(assets.has(url.pathname)){res.setHeader('Content-Type',url.pathname.endsWith('.css')?'text/css':'text/javascript');res.end(assets.get(url.pathname));return;}
  res.writeHead(404);res.end();
 });
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const base='http://127.0.0.1:'+server.address().port;
 const browser=await chromium.launch({headless:true,...(process.env.KIWE_CHROME?{executablePath:process.env.KIWE_CHROME}:{})});
 const errors=[],foreign=[];
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}});page.on('pageerror',error=>errors.push(error.message));page.on('request',req=>{if(!req.url().startsWith(base))foreign.push(req.url());});
  await page.goto(base);await page.getByLabel('Website name').waitFor();
  check(await page.getByRole('heading',{name:'Your identity.'}).isVisible(),'identity shell renders as a native task surface');const areas=page.getByRole('navigation',{name:'Website and Design Context'});check(await areas.getByRole('link',{name:'Story & audience'}).isVisible()&&await areas.getByRole('link',{name:'Contact & location'}).isVisible()&&await areas.getByRole('link',{name:'Services'}).isVisible(),'Identity keeps direct navigation to its native Design Context sections');
  check(await page.getByLabel('Website name').inputValue()==='Sample studio'&&await page.getByLabel('Short tagline').inputValue()==='Ideas into useful things','identity projection populates native values');
  check(await page.getByText('Public contact',{exact:true}).isVisible()&&await page.getByText('+91 90000 00000 · hello@example.test',{exact:true}).isVisible(),'public contact is visible without exposing account fields');
  const emptyImages=page.locator('.identity-image').filter({hasText:'No image selected'});check(await emptyImages.count()===2&&!(await emptyImages.allTextContents()).some(text=>/\b0\b/.test(text)),'optional image controls do not render a numeric empty value');
  await page.getByLabel('Website name').fill('Updated studio');await page.getByLabel('Short tagline').fill('A clearer promise');
  check(await page.getByRole('button',{name:'Save identity'}).isEnabled(),'identity edits enable one explicit save');
  await page.getByRole('button',{name:'Save identity'}).click();await page.getByText('Saved to your website.',{exact:true}).waitFor();
  check(writes===1&&identity.siteName==='Updated studio'&&identity.tagline==='A clearer promise','identity save writes native fields and confirms readback');
  check(lastHeaders['x-wp-nonce']==='synthetic-rest-nonce'&&lastHeaders['x-kiwe-mutation']==='1'&&Object.keys(lastBody).sort().join(',')==='changes,expected'&&Object.keys(lastBody.changes).sort().join(',')==='siteName,tagline','nonce mutation proof and selective identity payload');
  await page.getByLabel('Sell products online',{exact:true}).check();await page.getByLabel('Publish news',{exact:true}).check();
  check(!identity.activities.commerce&&!identity.activities.publication,'activity choices remain local until the explicit save');
  await page.getByRole('button',{name:'Save identity'}).click();await page.getByText('Saved to your website.',{exact:true}).waitFor();
  check(identity.activities.commerce&&identity.activities.publication&&Object.keys(lastBody.changes).join(',')==='activities','mixed store/news activities save through the same selective identity boundary');
  mode='conflict';await page.getByLabel('Short tagline').fill('My reviewed promise');await page.getByRole('button',{name:'Save identity'}).click();await page.getByRole('button',{name:'Reload and review'}).waitFor();
  check(await page.getByLabel('Short tagline').inputValue()==='My reviewed promise'&&await page.getByText('Business identity changed in another screen. Reload and review it before saving.',{exact:true}).isVisible(),'stale identity conflict preserves the draft');
  await page.getByRole('button',{name:'Reload and review'}).click();await page.getByLabel('Short tagline').waitFor();check(await page.getByLabel('Short tagline').inputValue()==='Edited in another screen','reload reads the current website identity');
  await page.getByLabel('Short tagline').fill('My reviewed promise');await page.getByRole('button',{name:'Save identity'}).click();await page.getByText('Saved to your website.',{exact:true}).waitFor();check(identity.tagline==='My reviewed promise','reviewed save uses a fresh fingerprint');
  mode='lost-response';await page.getByLabel('Industry or category').fill('Digital studio');await page.getByRole('button',{name:'Save identity'}).click();await page.getByRole('button',{name:'Reload and review'}).waitFor();check(identity.industry==='Digital studio'&&await page.getByLabel('Industry or category').inputValue()==='Digital studio','non-JSON 503 after write keeps edits and does not claim success');
  await page.getByRole('button',{name:'Reload and review'}).click();await page.locator('.identity-image').first().getByRole('button',{name:'Remove'}).click();await page.getByRole('button',{name:'Save identity'}).click();await page.getByText('Saved to your website.',{exact:true}).waitFor();check(identity.logoId===0&&Object.keys(lastBody.changes).join(',')==='logoId'&&await page.getByText(/identity setup marked incomplete/).isVisible(),'main logo removal is an atomic native change and clearly marks incomplete setup');
  for(const width of [1440,820,390,360]){await page.setViewportSize({width,height:1000});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow at '+width);await page.screenshot({path:path.join(out,'identity-'+width+'.png'),fullPage:true});}
  check(!errors.length&&!foreign.length,'no browser errors or external requests');
  const record={passed:true,scope:'Real React client with synthetic HTTP/WordPress I/O; not native WordPress acceptance',checks:checks.length,labels:checks,sourceHashes:Object.fromEntries(await Promise.all(['native-identity.jsx','native-identity.css','identity-client.mjs'].map(async file=>[file,crypto.createHash('sha256').update(await fs.readFile(path.join(__dirname,file))).digest('hex')])))};
  await fs.writeFile(path.join(out,'browser.json'),JSON.stringify(record,null,2));console.log(JSON.stringify({passed:true,checks:checks.length,out}));
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
