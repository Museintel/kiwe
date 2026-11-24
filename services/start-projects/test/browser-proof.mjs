import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { clientPurchase } from './fixture.mjs';

const source = fileURLToPath(new URL('../', import.meta.url));
const browserSource = process.env.KIWE_START_TEST_UI_HARNESS ?? fileURLToPath(new URL('../../../tools/ui-contract/', import.meta.url));
const req = createRequire(path.join(source, 'package.json'));
const { generateKeyPair, exportJWK, SignJWT } = req('jose');
const { Client } = req('@modelcontextprotocol/sdk/client/index.js');
const { StreamableHTTPClientTransport } = req('@modelcontextprotocol/sdk/client/streamableHttp.js');
const { chromium } = createRequire(path.join(browserSource, 'package.json'))('playwright');

const directory = await mkdtemp(path.join(tmpdir(), 'kiwe-start-browser-'));
const purchaseToken = randomBytes(32).toString('base64url');
const { privateKey, publicKey } = await generateKeyPair('ES256');
const key = { ...await exportJWK(publicKey), kid: 'test-issuer', alg: 'ES256' };
const checkoutPages = new Map();
const checkout = createServer((request, response) => {
  if (request.url === '/jwks') {
    response.writeHead(200, { 'Content-Type': 'application/json' });
    return response.end(JSON.stringify({ keys: [key] }));
  }
  response.writeHead(checkoutPages.has(request.url) ? 200 : 404, { 'Content-Type': 'text/html; charset=utf-8' });
  response.end(checkoutPages.get(request.url));
});

async function listen(server) {
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  return server.address().port;
}
const close = (server) => new Promise(resolve => server.close(resolve));
const checkoutOrigin = 'http://127.0.0.1:' + await listen(checkout);
const issuer = checkoutOrigin + '/account/';
const reservation = createServer();
const port = await listen(reservation);
await close(reservation);
const startOrigin = 'http://127.0.0.1:' + port;
const resource = startOrigin + '/projects/mcp';
const child = spawn(process.execPath, ['src/server.mjs'], {
  cwd: source, windowsHide: true,
  env: {
    SystemRoot: process.env.SystemRoot,
    TEMP: directory, TMP: directory,
    PORT: String(port),
    KIWE_START_ORIGIN: startOrigin,
    KIWE_START_CHECKOUT_ORIGIN: checkoutOrigin,
    KIWE_START_PURCHASE_TOKEN: purchaseToken,
    KIWE_START_OAUTH_ISSUER: issuer,
    KIWE_START_OAUTH_JWKS: checkoutOrigin + '/jwks',
    KIWE_START_DATABASE: path.join(directory, 'projects.sqlite'),
  },
  stdio: ['ignore', 'ignore', 'inherit'],
});
let browser;
async function post(pathname, payload) {
  const response = await fetch(startOrigin + '/projects/api/' + pathname, {
    method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + purchaseToken },
    body: JSON.stringify(payload), signal: AbortSignal.timeout(5000),
  });
  assert.equal(response.status, 201, await response.clone().text());
  return response.json();
}

try {
  let ready = false;
  for (let attempt = 0; attempt < 60 && child.exitCode === null; attempt++) {
    try {
      const probe = await fetch(startOrigin + '/projects', { signal: AbortSignal.timeout(500) });
      if (probe.status === 401) { ready = true; break; }
    } catch {}
    await new Promise(resolve => setTimeout(resolve, 150));
  }
  assert.equal(ready, true, 'Start service did not open via its standard entry point.');
  browser = await chromium.launch({
    executablePath: process.env.KIWE_START_TEST_CHROME,
    chromiumSandbox: true, headless: true,
  });
  const results = [];
  for (const [width, channel] of [[390, 'direct'], [1440, 'referred']]) {
    const accountId = 'test-' + channel;
    const entitlementId = 'site-' + channel;
    const domain = channel === 'direct' ? { mode: 'register', name: 'direct-bakery.in', category: 'standard' }
      : { mode: 'owned', name: 'referred-shop.example' };
    const referralId = channel === 'referred' ? 'test-referral' : null;
    const { projectId } = await post('purchases', clientPurchase(entitlementId, { accountId, referralId, domain }));
    const { ticket, action } = await post('handoffs', { entitlementId, accountId });
    const checkoutPath = '/' + channel;
    checkoutPages.set(checkoutPath, `<!doctype html><html lang="en"><title>Checkout fixture</title>
<form method="post" action="${action}"><input type="hidden" name="ticket" value="${ticket}"><button>Open Kiwe Start</button></form>`);
    const page = await browser.newPage({ viewport: { width, height: 840 } });
    await page.route('**/*', route => {
      const origin = new URL(route.request().url()).origin;
      return origin === checkoutOrigin || origin === startOrigin ? route.continue() : route.abort();
    });
    await page.goto(checkoutOrigin + checkoutPath, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Open Kiwe Start' }).click();
    await page.getByRole('heading', { name: domain.name, exact: true }).waitFor({ timeout: 8000 });
    assert.equal(page.url(), startOrigin + '/projects/' + projectId);
    await page.getByText('Domain saved.', { exact: false }).waitFor({ timeout: 8000 });
    assert.equal(await page.getByRole('button', { name: 'Save domain' }).count(), 0);
    const widthFits = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
    assert.equal(widthFits, true);
    await page.close();

    const token = await new SignJWT({ scope: 'start:projects' }).setProtectedHeader({ alg: 'ES256', kid: key.kid })
      .setIssuer(issuer).setAudience(resource).setSubject(accountId).setIssuedAt().setExpirationTime('2m').sign(privateKey);
    const client = new Client({ name: 'Browser proof', version: '1.0.0' });
    await client.connect(new StreamableHTTPClientTransport(new URL(resource), {
      requestInit: { headers: { Authorization: 'Bearer ' + token } },
    }));
    try {
      const result = await client.callTool({ name: 'kiwe_start_project', arguments: { projectId } });
      assert.equal(result.structuredContent.project.domain, domain.name);
      assert.equal(result.structuredContent.project.hosting, 'included');
      assert.equal(result.structuredContent.project.next, 'provision_site');
    } finally {
      await client.close();
    }
    results.push({ width, channel, browserHandoff: true, checkoutDomainSaved: true, widthFits, verifiedMcpReadback: true });
  }
  console.log(JSON.stringify(results));
} finally {
  await browser?.close();
  if (child.exitCode === null) {
    child.kill();
    await once(child, 'exit');
  }
  await close(checkout);
  await rm(directory, { recursive: true, force: true });
}
