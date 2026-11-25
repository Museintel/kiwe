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
import { startForCheckout, verifyBilling } from './billing-proof.mjs';
import { verifyPartners } from './partner-proof.mjs';

const require = createRequire(new URL('../../start-projects/package.json', import.meta.url));
const harness = process.env.KIWE_LAUNCH_UI_HARNESS ?? fileURLToPath(new URL('../../../tools/ui-contract', import.meta.url));
const { chromium } = createRequire(path.join(harness, 'package.json'))('playwright');
const { Client } = require('@modelcontextprotocol/sdk/client/index.js');
const { StdioClientTransport } = require('@modelcontextprotocol/sdk/client/stdio.js');
const app = fileURLToPath(new URL('../../../public/kiwelaunch.com/', import.meta.url));
const service = fileURLToPath(new URL('../', import.meta.url));
const root = await mkdtemp(path.join(tmpdir(), 'kiwe-checkout-test-'));
const data = path.join(root, 'data');
const portProbe = createServer().listen(0, '127.0.0.1');
await once(portProbe, 'listening');
const port = portProbe.address().port;
await new Promise((resolve, reject) => portProbe.close(error => error ? reject(error) : resolve()));
const origin = 'http://127.0.0.1:' + port;
const php = process.env.KIWE_LAUNCH_PHP ?? 'php';
const phpArgs = process.env.KIWE_LAUNCH_PHP_CONFIG ? ['-c', process.env.KIWE_LAUNCH_PHP_CONFIG] : [];
const start = await startForCheckout(origin);
const env = { ...process.env, KIWE_LAUNCH_PHP: php, KIWE_LAUNCH_CHECKOUT_ROOT: root, KIWE_CHECKOUT_TEST_ORIGIN: origin,
  KIWE_BILLING_TEST_START_ORIGIN: start.origin, KIWE_BILLING_TEST_START_TOKEN: start.purchaseToken };
const password = 'Saffron test pass phrase 48';
const resetPassword = 'Updated test pass phrase 52';
let browser;
let server;
let mcp;

async function justMail(email, purpose) {
  const rows = (await readFile(path.join(data, 'mail.jsonl'), 'utf8')).trim().split(/\r?\n/).map(line => JSON.parse(line));
  const sent = rows.filter(row => row.email === email && row.purpose === purpose).at(-1);
  assert.ok(sent, 'The requested account email was captured');
  return sent.body.split('\n').find(line => line.startsWith(origin + '/checkout/#verify='));
}

async function newBuyer(page, email) {
  const form = page.locator('form:has(input[name="action"][value="signup"])');
  await form.getByLabel('Your email').fill(email);
  await form.getByRole('button', { name: 'Send my verification link' }).click();
  await page.getByRole('status').getByText('Check your email', { exact: false }).waitFor();
  const link = await justMail(email.toLowerCase(), 'signup');
  await page.goto(link);
  await page.getByRole('heading', { name: 'Create your password' }).waitFor();
  assert.equal(new URL(page.url()).hash, '', 'Email secrets are removed from the address bar');
  await savePassword(page, password);
  return link;
}

async function savePassword(page, candidate) {
  await page.getByLabel('Password', { exact: true }).fill(candidate);
  await page.getByLabel('Re-enter password').fill(candidate);
  await page.getByRole('button', { name: 'Save password and continue' }).click();
  await page.getByRole('status').getByText('verified Kiwe Launch account', { exact: false }).waitFor();
}

async function fillProfile(page) {
  const fields = {
    'Full name': 'Fixture Buyer', 'Phone number': '+919876500000', 'Street address': 'Synthetic Road 10',
    City: 'Mumbai', 'State or region': 'Maharashtra', 'Postal code': '400001',
  };
  for (const [label, value] of Object.entries(fields)) await page.getByLabel(label, { exact: true }).fill(value);
}

async function chooseDomain(page, domain, mode = 'register') {
  await page.getByLabel('Domain name').fill(domain);
  await page.locator('input[name="mode"][value="' + mode + '"]').check();
  if (mode === 'owned') await page.getByLabel('I own the domain entered above').check();
  await page.getByRole('button', { name: 'Check domain and save details' }).click();
}

async function operation(name, args) {
  const result = await mcp.callTool({ name, arguments: args });
  assert.ok(!result.isError, name);
  return result.structuredContent;
}

try {
  await mkdir(path.join(data, 'sessions'), { recursive: true });
  for (const file of ['boot.php', 'store.php', 'providers.php', 'schema.sql', 'web.php', 'view.php', 'operator.php',
    'billing.php', 'billing-providers.php', 'billing-schema.sql', 'billing-view.php', 'registration.php',
    'partners.php', 'partner-schema.sql', 'partner-view.php']) {
    await cp(path.join(service, file), path.join(root, file));
  }
  await cp(path.join(service, 'test', 'settings-fixture.php'), path.join(root, 'settings.php'));
  await cp(path.join(service, 'test', 'billing-fixture.php'), path.join(root, 'billing-fixture.php'));
  await writeFile(path.join(root, 'client-plan.json'), JSON.stringify(clientPlan));
  await writeFile(path.join(data, 'rate-key'), randomBytes(32), { mode: 0o600 });
  await writeFile(path.join(data, 'gateway.json'), JSON.stringify({ links: {}, payments: {}, subscriptions: {} }));
  server = spawn(php, [...phpArgs, '-S', '127.0.0.1:' + port, '-t', app], { windowsHide: true, env, stdio: 'ignore' });
  let ready = false;
  for (let retry = 0; retry < 50; retry += 1) {
    if (await fetch(origin + '/checkout/').then(res => res.ok).catch(() => false)) { ready = true; break; }
    await wait(100);
  }
  assert.ok(ready);
  const noCsrf = await fetch(origin + '/checkout/', {
    method: 'POST', headers: { Origin: origin }, body: new URLSearchParams({ action: 'signup', email: 'untested@example.invalid' }),
  });
  assert.equal(noCsrf.status, 403);

  mcp = new Client({ name: 'launch-checkout-fixture', version: '1.0.0' });
  await mcp.connect(new StdioClientTransport({
    command: process.execPath,
    args: [fileURLToPath(new URL('../../launch-office/mcp.mjs', import.meta.url))],
    env,
  }));
  const registration = await mcp.callTool({ name: 'kiwe_launch_reconcile_domain',
    arguments: { purchaseId: 'kiwe_' + '1'.repeat(32), domain: 'eligible.in' } });
  assert.ok(registration.isError);
  assert.match(registration.content[0].text, /Paid domain registration is disabled/);
  const move = await mcp.callTool({ name: 'kiwe_launch_review_domain_move', arguments: {
    purchaseId: 'kiwe_' + '1'.repeat(32), domain: 'owned.example', sourceUrl: 'https://unrelated.example',
    recoverySha256: '9'.repeat(64),
  } });
  assert.ok(move.isError);
  assert.match(move.content[0].text, /Provide the exact temporary site/);
  assert.equal((await operation('kiwe_launch_check_domain', { domain: 'test.in' })).status, 'eligible');
  assert.equal((await operation('kiwe_launch_check_domain', { domain: 'Test.COM.' })).status, 'excluded');
  assert.equal((await operation('kiwe_launch_check_domain', { domain: 'restricted.in' })).status, 'restricted');

  browser = await chromium.launch({ executablePath: process.env.KIWE_LAUNCH_CHROME, headless: true });
  const direct = await browser.newPage({ viewport: { width: 390, height: 880 } });
  const humanErrors = [];
  direct.on('pageerror', error => humanErrors.push(error.message));
  const screen = await direct.goto(origin + '/checkout/');
  assert.match(screen.headers()['cache-control'], /no-store/);
  const session = (await direct.context().cookies()).find(cookie => cookie.name === 'kiwe_launch_checkout');
  assert.ok(session?.httpOnly);
  assert.equal(session.sameSite, 'Lax');
  assert.equal(session.path, '/checkout/');
  assert.equal(await direct.evaluate(() => document.documentElement.scrollWidth), 390);
  assert.equal(await direct.locator('aside h2').innerText(), '₹8,400');
  const directLink = await newBuyer(direct, 'buyer@example.invalid');
  await fillProfile(direct);
  await chooseDomain(direct, 'premium.in');
  await direct.getByRole('alert').getByText('premium domain', { exact: false }).waitFor();
  await chooseDomain(direct, 'taken.in');
  await direct.getByRole('alert').getByText('already registered', { exact: false }).waitFor();
  await chooseDomain(direct, 'taken.in', 'owned');
  await direct.getByRole('heading', { name: 'Saved checkout' }).waitFor();
  await direct.getByText('You’ll connect your existing domain', { exact: false }).waitFor();
  assert.deepEqual(humanErrors, []);

  const otherDevice = await browser.newPage();
  await otherDevice.goto(directLink);
  await otherDevice.getByRole('alert').getByText('invalid or expired', { exact: false }).waitFor();
  await otherDevice.getByText('Reset your password', { exact: true }).click();
  await otherDevice.locator('#recover').getByLabel('Your email').fill('buyer@example.invalid');
  await otherDevice.getByRole('button', { name: 'Send my reset link' }).click();
  await otherDevice.getByRole('status').getByText('Check your email', { exact: false }).waitFor();
  await otherDevice.goto(await justMail('buyer@example.invalid', 'reset'));
  await otherDevice.getByRole('heading', { name: 'Choose a new password' }).waitFor();
  await savePassword(otherDevice, resetPassword);
  await direct.reload();
  await direct.getByRole('heading', { name: 'Create your account' }).waitFor();
  await direct.locator('#sign-in summary').click();
  await direct.locator('#sign-in').getByLabel('Your email').fill('buyer@example.invalid');
  await direct.locator('#sign-in').getByLabel('Password', { exact: true }).fill(resetPassword);
  await direct.locator('#sign-in').getByRole('button', { name: 'Sign in' }).click();
  await direct.getByRole('heading', { name: 'Saved checkout' }).waitFor();
  assert.equal(await direct.getByLabel('Domain name').inputValue(), 'taken.in');
  await direct.getByRole('button', { name: 'Sign out' }).click();
  await direct.getByRole('heading', { name: 'Create your account' }).waitFor();

  const partner = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await partner.goto(origin + '/checkout/?tab=partner');
  await newBuyer(partner, 'partner@example.invalid');
  assert.equal(await partner.getByRole('button', { name: 'Create referral link' }).count(), 0);
  await partner.getByRole('button', { name: 'Join the Partner Program' }).click();
  await partner.getByText('Your partner account is ready', { exact: false }).waitFor();
  const enrolment = await operation('kiwe_launch_enrol_partner', { email: 'partner@example.invalid' });
  assert.equal(enrolment.account.partner, true);
  await partner.getByLabel('Client email').fill('referred@example.invalid');
  await partner.getByLabel('Client WhatsApp number').fill('+919876500001');
  await partner.getByLabel('Preferred domain').fill('eligible.in');
  await partner.getByRole('checkbox').check();
  await partner.getByRole('button', { name: 'Create referral link' }).click();
  const linkInput = partner.getByLabel('Private referral link');
  await linkInput.waitFor();
  const link = await linkInput.inputValue();
  assert.match(link, /\/checkout\/#ref=[a-f0-9]{64}$/);

  const mismatched = await browser.newPage();
  await mismatched.goto(link);
  await mismatched.getByText('Use the email you shared', { exact: false }).waitFor();
  await newBuyer(mismatched, 'other@example.invalid');
  assert.equal(await mismatched.getByLabel('Domain name').inputValue(), '');
  assert.equal(await mismatched.locator('aside h2').innerText(), '₹8,400');

  const referred = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await referred.goto(link);
  await referred.getByText('Use the email you shared', { exact: false }).waitFor();
  assert.equal(await referred.getByText('referred@example.invalid', { exact: true }).count(), 0);
  await newBuyer(referred, 'Referred@example.invalid');
  assert.equal(await referred.getByLabel('Domain name').inputValue(), 'eligible.in');
  assert.equal(await referred.getByLabel(/WhatsApp number/).inputValue(), '+919876500001');
  assert.equal(await referred.getByLabel('Phone number', { exact: true }).inputValue(), '+919876500001');
  assert.equal(await referred.locator('aside h2').innerText(), '₹7,200');
  await fillProfile(referred);
  await chooseDomain(referred, 'eligible.in');
  await referred.getByRole('heading', { name: 'Saved checkout' }).waitFor();
  await referred.getByText('registry requires identity verification', { exact: false }).waitFor();
  assert.equal(await referred.evaluate(() => document.documentElement.scrollWidth), 1440);

  const { DatabaseSync } = await import('node:sqlite');
  const db = new DatabaseSync(path.join(data, 'checkout.sqlite'), { readOnly: true });
  const account = db.prepare('SELECT id FROM checkout_accounts WHERE email = ?').get('referred@example.invalid');
  assert.equal(db.prepare('SELECT COUNT(*) AS count FROM checkout_referrals WHERE buyer_id IS NOT NULL').get().count, 1);
  db.close();
  const { quote } = await operation('kiwe_launch_checkout_quote', { accountId: account.id });
  assert.equal(quote.upfrontMinor, clientPlan.referred.upfrontMinor);
  assert.equal(quote.renewalMinor, clientPlan.referred.renewalMinor);
  assert.deepEqual(quote.domain, { mode: 'register', name: 'eligible.in', category: 'standard' });
  await partner.reload();
  await partner.getByText('Email verified', { exact: true }).waitFor();
  if (process.env.KIWE_LAUNCH_PROOF_DIR) {
    const proof = process.env.KIWE_LAUNCH_PROOF_DIR;
    await mkdir(proof, { recursive: true });
    await referred.screenshot({ path: path.join(proof, 'checkout-1440.png'), fullPage: true });
    await partner.screenshot({ path: path.join(proof, 'partner-links-1440.png'), fullPage: true });
    await direct.screenshot({ path: path.join(proof, 'signup-390.png'), fullPage: true });
  }
  const paid = await verifyBilling({ origin, data, page: referred, direct, directEmail: 'buyer@example.invalid', directPassword: resetPassword,
    accountId: account.id, quote, tools: operation, start, clientPlan });
  await verifyPartners({ origin, data, partner, anotherPartner: mismatched, buyer: referred, start, paid,
    buyerId: account.id, partnerId: enrolment.account.id, call: (name, args) => mcp.callTool({ name, arguments: args }) });
  process.stdout.write('Verified checkout, Razorpay billing and retries, renewal consent, refunds and same-account Kiwe Start handoff passed.\n');
  process.stdout.write('Partner service proof, commission adjustments, private withdrawals and payout readback passed.\n');
} finally {
  await mcp?.close();
  await browser?.close();
  if (server && server.exitCode === null && server.signalCode === null) {
    const exited = once(server, 'exit');
    server.kill();
    await exited;
  }
  await start.close();
  if (path.dirname(root) === path.resolve(tmpdir()) && path.basename(root).startsWith('kiwe-checkout-test-')) {
    await rm(root, { recursive: true, force: true, maxRetries: 3 });
  }
}
