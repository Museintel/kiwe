import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { cp, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { setTimeout as wait } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import { clientPlan } from '../../start-projects/src/client-plan.mjs';

const require = createRequire(new URL('../../start-projects/package.json', import.meta.url));
const browserRoot = process.env.KIWE_LAUNCH_UI_HARNESS ?? fileURLToPath(new URL('../../../tools/ui-contract', import.meta.url));
const { chromium } = createRequire(path.join(browserRoot, 'package.json'))('playwright');
const { Client } = require('@modelcontextprotocol/sdk/client/index.js');
const { StdioClientTransport } = require('@modelcontextprotocol/sdk/client/stdio.js');
const webRoot = fileURLToPath(new URL('../../../public/kiwelaunch.com/', import.meta.url));
const office = await mkdtemp(path.join(tmpdir(), 'kiwe-launch-test-'));
const portProbe = createServer().listen(0, '127.0.0.1');
await once(portProbe, 'listening');
const address = portProbe.address().port;
await new Promise((resolve, reject) => portProbe.close(error => error ? reject(error) : resolve()));
const origin = 'http://127.0.0.1:' + address;
const php = process.env.KIWE_LAUNCH_PHP ?? 'php';
const phpConfig = process.env.KIWE_LAUNCH_PHP_CONFIG ? ['-c', process.env.KIWE_LAUNCH_PHP_CONFIG] : [];
const proofDir = process.env.KIWE_LAUNCH_PROOF_DIR;
let server;
let browser;
let officeClient;

const sample = {
  kind: 'demo', name: 'Launch Test', email: 'demo-proof@example.invalid', business: 'Example Studio',
  phone: '', message: 'Rehearse the Appsite demo.', consent: 'yes',
};

async function submit(fields, requestOrigin = origin) {
  return fetch(origin + '/launch-api/intake.php', {
    method: 'POST',
    headers: { Accept: 'application/json', Origin: requestOrigin },
    body: new URLSearchParams(fields),
  });
}

try {
  await mkdir(path.join(office, 'data'));
  await cp(fileURLToPath(new URL('../inquiries.php', import.meta.url)), path.join(office, 'inquiries.php'));
  await writeFile(path.join(office, 'data', 'network-key'), randomBytes(32), { mode: 0o600 });
  if (proofDir) await mkdir(proofDir, { recursive: true });
  server = spawn(php, [...phpConfig, '-S', '127.0.0.1:' + address, '-t', webRoot], {
    env: { ...process.env, KIWE_LAUNCH_OFFICE: office, KIWE_LAUNCH_ORIGIN: origin },
    stdio: 'ignore',
    windowsHide: true,
  });
  let ready = false;
  for (let attempt = 0; attempt < 50; attempt += 1) {
    if (await fetch(origin).then(response => response.ok).catch(() => false)) { ready = true; break; }
    await wait(100);
  }
  assert.ok(ready, 'PHP preview starts');

  const home = await readFile(path.join(webRoot, 'index.html'), 'utf8');
  const money = minor => new Intl.NumberFormat('en-IN').format(minor / 100);
  for (const amount of [clientPlan.direct.upfrontMinor, clientPlan.direct.renewalMinor,
    clientPlan.referred.upfrontMinor, clientPlan.referred.renewalMinor]) {
    assert.ok(home.includes('₹' + money(amount)), 'Marketing uses the same price as the Start catalogue');
  }
  assert.equal((await fetch(origin + '/launch-api/intake.php')).status, 405);
  assert.equal((await submit(sample, 'https://outside.example')).status, 403);
  assert.equal((await submit({ ...sample, consent: '' })).status, 422);
  assert.equal((await submit({ ...sample, email: 'bad' })).status, 422);

  browser = await chromium.launch({ executablePath: process.env.KIWE_LAUNCH_CHROME, headless: true });
  for (const [width, height] of [[320, 480], [320, 568], [390, 844], [768, 1024], [844, 390], [1024, 600], [1280, 720], [1440, 900]]) {
    const page = await browser.newPage({ viewport: { width, height }, reducedMotion: 'reduce' });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(origin, { waitUntil: 'load' });
    await page.getByRole('heading', { name: /Launch faster/ }).waitFor();
    assert.equal(await page.locator('.looping').count(), 0, 'Reduced motion starts with static rails');
    await page.locator('img:not(.loop-copy img)').evaluateAll(async images => {
      for (const image of images) {
        image.loading = 'eager';
        await image.decode();
      }
    });
    await page.evaluate(() => document.fonts.ready);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), width, 'Homepage fits at ' + width);
    const fit = await page.locator('.logo-set:not(.loop-copy) li').evaluateAll(items => items.every(item => {
      const viewport = item.closest('.logo-window').getBoundingClientRect();
      const logo = item.getBoundingClientRect();
      return logo.left >= viewport.left - 1 && logo.right <= viewport.right + 1;
    }));
    assert.equal(await page.locator('.logo-set:not(.loop-copy) li').count(), 5);
    assert.ok(fit, 'All five static logos are visible at ' + width);
    const loaded = await page.evaluate(() => {
      const powered = document.querySelector('.powered').getBoundingClientRect();
      const headline = document.querySelector('#hero-title').getBoundingClientRect();
      return { scroll: window.scrollY, railTop: powered.top, railBottom: powered.bottom, headlineBottom: headline.bottom };
    });
    assert.equal(loaded.scroll, 0);
    assert.ok(loaded.railTop >= 0 && loaded.railBottom <= height && loaded.headlineBottom <= height,
      'The hero headline and full powered-by rail fit at ' + width + '×' + height + ': ' + JSON.stringify(loaded));
    assert.equal(errors.length, 0);
    if (proofDir && (width === 390 || width === 1440)) {
      await page.screenshot({ path: path.join(proofDir, 'home-' + width + '.png'), fullPage: true });
      await page.screenshot({ path: path.join(proofDir, 'hero-' + width + '.png') });
    }
    if (width === 1440) {
      await page.emulateMedia({ reducedMotion: 'no-preference' });
      await page.locator('.logo-track.looping').waitFor();
      await page.getByRole('button', { name: 'Pause motion' }).first().click();
      assert.equal(await page.locator('.logo-track').evaluate(el => getComputedStyle(el).animationPlayState), 'paused');
      await page.getByRole('button', { name: 'Resume motion' }).first().click();
      await page.getByLabel('Your name').fill(sample.name);
      await page.getByLabel('Work email').fill(sample.email);
      await page.getByLabel('Business name').fill(sample.business);
      await page.getByLabel('What are you building?').fill(sample.message);
      await page.getByRole('checkbox').check();
      await page.getByRole('button', { name: 'Request a demo call' }).click();
      await page.getByText('Your demo request is in.', { exact: false }).waitFor();
    }
    if (width === 390) {
      await page.locator('.nav-mobile summary').click();
      await page.getByRole('navigation', { name: 'Mobile navigation' }).getByText('Become a partner').click();
      await page.getByRole('heading', { name: 'Help good businesses get online.' }).waitFor();
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), width, 'Partner page fits');
      const form = page.locator('form');
      await form.getByLabel('Your name').fill('Partner Test');
      await form.getByLabel('Work email').fill('partner-proof@example.invalid');
      await form.getByLabel('Business or freelance name').fill('Example Partner');
      await form.getByRole('checkbox').check();
      await form.getByRole('button', { name: 'Register my interest' }).click();
      await form.getByText('Your interest is registered.', { exact: false }).waitFor();
      if (proofDir) await page.screenshot({ path: path.join(proofDir, 'partner-390.png'), fullPage: true });
    }
    await page.close();
  }

  assert.equal((await submit(sample)).status, 200, 'An identical retry succeeds');
  officeClient = new Client({ name: 'launch-fixture', version: '1.0.0' });
  await officeClient.connect(new StdioClientTransport({
    command: process.execPath,
    args: [fileURLToPath(new URL('../mcp.mjs', import.meta.url))],
    env: {
      ...process.env,
      KIWE_LAUNCH_OFFICE: office,
      KIWE_LAUNCH_PHP: php,
    },
  }));
  const result = await officeClient.callTool({ name: 'kiwe_launch_inquiries', arguments: { limit: 10 } });
  assert.equal(result.isError, undefined);
  assert.deepEqual(result.structuredContent.inquiries.map(row => row.email).sort(), [
    'demo-proof@example.invalid', 'partner-proof@example.invalid',
  ]);
  assert.ok(result.structuredContent.inquiries.every(row => !('network' in row)));
  const partner = await officeClient.callTool({ name: 'kiwe_launch_inquiries', arguments: { kind: 'partner' } });
  assert.equal(partner.structuredContent.inquiries.length, 1);
  process.stdout.write('Launch layouts, price parity, demo and partner forms, duplicate handling and private MCP readback passed.\n');
} finally {
  await officeClient?.close();
  await browser?.close();
  if (server && server.exitCode === null && server.signalCode === null) {
    const exited = once(server, 'exit');
    server.kill();
    await exited;
  }
  if (path.dirname(office) === path.resolve(tmpdir()) && path.basename(office).startsWith('kiwe-launch-test-')) {
    await rm(office, { recursive: true, force: true, maxRetries: 3 });
  }
}
