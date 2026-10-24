#!/usr/bin/env node
// Disposable lab only. Credentials arrive in this process environment and are never printed.
const {chromium}=require(process.env.KIWE_PLAYWRIGHT||'playwright');
const fs=require('node:fs/promises');
const path=require('node:path');
const host='plum-giraffe-539524.hostingersite.com';
const origin='https://'+host;
const out=path.resolve(process.argv[2]||'');
if(!process.argv[2]||out.startsWith(path.resolve(__dirname,'../..')+path.sep))throw Error('Choose an external evidence directory.');
if(!process.env.KIWE_LAB_LOGIN||!process.env.KIWE_LAB_PASSWORD)throw Error('Provide private lab login in process environment.');
const labels=[];function check(ok,label){if(!ok)throw Error(label);labels.push(label);}
(async()=>{
 await fs.mkdir(out,{recursive:true});
 const browser=await chromium.launch({headless:true,...(process.env.KIWE_CHROME?{executablePath:process.env.KIWE_CHROME}:{})});
 try{
  const page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto(origin+'/wp-login.php?redirect_to='+encodeURIComponent(origin+'/wp-admin/admin.php?page=kiwe-workspace'),{waitUntil:'domcontentloaded',timeout:30000});
  await page.locator('#user_login').fill(process.env.KIWE_LAB_LOGIN,{timeout:10000});
  await page.locator('#user_pass').fill(process.env.KIWE_LAB_PASSWORD);
  await page.locator('#wp-submit').click();
  await page.getByRole('heading',{name:'Make yourself at home.'}).waitFor({timeout:20000});
  check(new URL(page.url()).hostname===host,'client session stays on disposable lab');
  check(await page.locator('.kiwe-shell-tiles').count()===1&&await page.getByRole('heading',{name:'Daily work'}).count()===0,'one Home task canvas');
  check(await page.locator('.kiwe-shell-tiles').getByRole('link',{name:/Website & Design Context/}).count()===1,'one Design Context entrance');
  const bell=page.locator('.kiwe-shell-bell');check(await bell.isVisible(),'private header exposes notifications bell');
  const homeCount=Number((await bell.locator('.kiwe-shell-bell-count').textContent().catch(()=>''))||0);
  check(await page.locator('#kiwe-shell-main .notice,#kiwe-shell-main .update-nag').count()===0,'administrative alerts stay out of Home canvas');
  await page.setViewportSize({width:390,height:844});
  check(await page.locator('.kiwe-shell-tiles').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)===2&&await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'phone Home keeps two bento columns without overflow');
  await page.screenshot({path:path.join(out,'home-phone.png'),fullPage:false});
  await bell.click();
  await page.getByRole('heading',{name:'Stay in the loop.'}).waitFor({timeout:20000});
  const alertCount=Number((await page.locator('#website-alerts > h2').textContent())?.match(/\d+/)?.[0]||0);
  check(homeCount===alertCount,'bell count matches WordPress/Kiwe alerts in Notifications ('+homeCount+'/'+alertCount+')');
  check(await page.locator('.workspace-site-brand').count()===1&&await page.getByText('kiwe.',{exact:true}).count()===0,'Notifications header uses website branding');
  await page.goto(origin+'/wp-admin/admin.php?page=kiwe-workspace',{waitUntil:'domcontentloaded'});
  await page.getByRole('heading',{name:'Make yourself at home.'}).waitFor();
  await page.getByRole('link',{name:'Account',exact:true}).click();
  check(new URL(page.url()).hostname===host&&new URL(page.url()).searchParams.get('kiwe-screen')==='profile','Account opens the public Profile route');
  try{await page.locator('[data-dsa-overlay-root]:not([hidden]) [role="dialog"]').waitFor({timeout:12000});}catch(error){const state=await page.evaluate(()=>({surface:!!document.querySelector('[data-dsa-surface]'),root:!!document.querySelector('[data-dsa-overlay-root]'),dsa:!!window.DSA,script:[...document.scripts].some(script=>script.src.includes('/surface.js')),profile:location.search.includes('kiwe-screen=profile'),dialog:document.querySelectorAll('[role="dialog"]').length}));throw Error('Profile DSA did not open: '+JSON.stringify(state));}
  const profilePanel=await page.locator('[data-dsa-profile-panel]').count(),securityPanel=await page.locator('[data-dsa-phonekey-auth]').count();
  check(profilePanel>0||securityPanel>0,'Profile DSA or its required Key security gate is visible after hydration');
  await page.goto(origin+'/wp-admin/admin.php?page=kiwe-posts',{waitUntil:'domcontentloaded'});
  await page.getByRole('heading',{name:'Posts.'}).waitFor({timeout:20000});
  const cardLink=page.getByRole('link',{name:'View on website ↗'}).first();await cardLink.waitFor({timeout:20000});
  const href=await cardLink.getAttribute('href');check(new URL(href,origin).origin===origin,'published card uses a same-site WordPress permalink');
  const card=cardLink.locator('xpath=..');await card.locator('button.native-post-open').click();
  check((await page.getByRole('link',{name:'View published post ↗'}).getAttribute('href'))===href,'published editor retains its public view shortcut');
  const publicPost=await page.request.get(href,{timeout:20000});check(publicPost.status()===200&&(publicPost.headers()['content-type']||'').includes('text/html'),'published shortcut resolves to a live public article');
  check(!errors.length,'no client JavaScript errors');
  const result={passed:true,scope:'Authenticated disposable WordPress lab; no post mutation',checks:labels.length,labels,adminAlertCount:homeCount,accountPanel:profilePanel?'profile':'key-security'};
  await fs.writeFile(path.join(out,'browser.json'),JSON.stringify(result,null,2));
  console.log(JSON.stringify(result));
 }finally{await browser.close();}
})().catch(error=>{console.error('RC164 lab browser acceptance failed: '+error.message);process.exitCode=1;});
