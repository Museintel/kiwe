const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { createServer } = require('node:http');
const { once } = require('node:events');
const path = require('node:path');
const { chromium } = require(process.env.KIWE_PLAYWRIGHT || 'playwright');

const fixture = path.join(__dirname, 'fixtures/consent-browser.php');
const controller = process.env.KIWE_CONSENT_CONTROLLER || path.resolve(__dirname, '../../wp-content/mu-plugins/dsa/includes/MCP/HTTP_Controller.php');
const prior = process.argv.includes('--verify-prior-block');
const checks = [];
function check(condition, label) { assert(condition, label); checks.push(label); }
const pause = () => new Promise(resolve => setTimeout(resolve, 50));
async function port(server) {
 server.listen(0, '127.0.0.1');
 await once(server, 'listening');
 return server.address().port;
}
async function close(server) {
 if (server.listening) await new Promise(resolve => server.close(resolve));
}

async function run() {
 const callbacks = [];
 const receiver = createServer((req, res) => {
  callbacks.push({url:req.url, method:req.method, referer:req.headers.referer, cookie:req.headers.cookie});
  res.end('Callback received');
 });
 const source = createServer();
 let php, browser;
 try {
  const callback = 'http://127.0.0.1:' + await port(receiver) + '/callback?retained=1';
  const sourcePort = await port(source);
  await close(source);
  const site = 'http://localhost:' + sourcePort;
  const reviewUrl = site + '/admin-post.php?action=kiwe_mcp_authorize';
  php = spawn(process.env.KIWE_PHP || 'php', ['-n','-S','127.0.0.1:' + sourcePort,fixture], {
   env:{...process.env,KIWE_CONSENT_CALLBACK:callback,KIWE_CONSENT_CONTROLLER:controller},
   stdio:'ignore',windowsHide:true,
  });
  let ready = false;
  for (let i = 0; i < 50 && !ready; ++i) {
   try { ready = (await fetch(reviewUrl,{signal:AbortSignal.timeout(1000)})).ok; } catch {}
   if (!ready) await pause();
  }
  assert(ready,'The isolated consent server did not start.');
  browser = await chromium.launch({headless:true,...(process.env.KIWE_CHROME ? {executablePath:process.env.KIWE_CHROME} : {})});
  const context = await browser.newContext();
  const page = await context.newPage();
  const violations = [];
  page.on('console', message => { if (message.text().includes('form-action')) violations.push(message.text()); });
  const form = await page.goto(reviewUrl);
  check(form.headers()['content-security-policy'].includes("form-action 'self'"),'The approval form stays same-origin');
  if (prior) {
   const blocked = page.waitForEvent('console', {predicate: message => message.text().includes('form-action'), timeout:8000});
   await page.getByRole('button',{name:'Allow connection'}).click({noWaitAfter:true});
   await blocked;
   check(callbacks.length===0,'Chromium blocks the original cross-origin form redirect');
   return;
  }
  const post = page.waitForResponse(response => response.request().method() === 'POST', {timeout:8000});
  await page.getByRole('button',{name:'Allow connection'}).click({noWaitAfter:true});
  const response = await post;
  const isCallback = value => value.origin === new URL(callback).origin && value.pathname === '/callback';
  await page.waitForURL(isCallback, {timeout:8000});
  check(response.status()===200 && !response.headers().location && response.headers()['content-security-policy'].includes("form-action 'none'"),'The approval POST finishes on a protected receipt');
  check(/no-store/.test(response.headers()['cache-control']) && response.headers()['referrer-policy']==='no-referrer','The receipt prevents caching and referrer disclosure');
  check(callbacks.length===1 && violations.length===0,'Chromium follows the approved return once without a CSP violation');
  const values = new URL(callbacks[0].url,callback).searchParams;
  check(values.get('state')==='synthetic-state' && values.get('iss')===site && values.get('code')==='synthetic-code' && values.get('retained')==='1','The registered query and exact OAuth return values survive');
  check(callbacks[0].method==='GET' && !callbacks[0].referer && !callbacks[0].cookie,'The callback receives a GET without the form body, referrer or site cookie');
  await context.close();

  const manual = await browser.newContext({javaScriptEnabled:false});
  const manualPage = await manual.newPage();
  await manualPage.goto(reviewUrl);
  await manualPage.getByRole('button',{name:'Cancel'}).click();
  check(new URL(manualPage.url()).origin === site,'The no-script browser can remain on the native receipt');
  await manualPage.getByRole('link',{name:'Return to your AI application'}).click();
  await manualPage.waitForURL(isCallback, {timeout:8000});
  const cancelled = new URL(callbacks[1].url,callback).searchParams;
  check(callbacks.length===2 && cancelled.get('error')==='access_denied' && !cancelled.has('code'),'Manual return delivers cancellation without an authorization code');
  await manual.close();
 } finally {
  if (browser) await browser.close();
  if (php && php.exitCode === null) php.kill();
  await close(source);
  await close(receiver);
 }
}
run().then(()=>console.log(JSON.stringify({passed:true,checks:checks.length,labels:checks}))).catch(error=>{console.error(error.stack);process.exitCode=1;});
