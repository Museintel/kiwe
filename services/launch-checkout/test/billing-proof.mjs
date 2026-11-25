import assert from 'node:assert/strict';
import { createHmac, randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { readFile, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';
import { createStartServer } from '../../start-projects/src/app.mjs';
import { openProjects } from '../../start-projects/src/projects.mjs';

export async function startForCheckout(checkoutOrigin) {
  const probe = createServer().listen(0, '127.0.0.1');
  await once(probe, 'listening');
  const port = probe.address().port;
  await new Promise((resolve, reject) => probe.close(error => error ? reject(error) : resolve()));
  const origin = 'http://127.0.0.1:' + port;
  const purchaseToken = randomBytes(32).toString('base64url');
  const pilotAccount = 'key:isolated-owner';
  let fixtureTime = null;
  const projects = openProjects(':memory:', { launchWebsite: checkoutOrigin, now: () => fixtureTime ?? Date.now() });
  const server = createStartServer({
    projects, startOrigin: origin, checkoutOrigin, pilotPurchaseToken: purchaseToken,
    browserLogin: { pilotAccount, authorizationOrigin: 'https://key.kiwelaunch.com' },
  });
  server.listen(port, '127.0.0.1');
  await once(server, 'listening');
  return {
    origin, purchaseToken, projects, pilotAccount,
    confirmManagedSite(accountId, projectId, readyAt) {
      const project = projects.getProject(accountId, { projectId });
      const target = { projectId, revision: project.revision };
      const domain = project.domainSetup?.status === 'registered' ? project.domain : 'partner-fixture.example.invalid';
      fixtureTime = readyAt * 1000;
      try {
        projects.prepareManagedSite(accountId, target);
        projects.reserveManagedSite(accountId, target, domain);
        projects.saveManagedStatus(accountId, target, 'ready');
        projects.confirmSiteLink({ ...target, accountId }, {
          url: 'https://' + domain, coreVersion: '8.0.0-rc.498', wordpressVersion: '7.1.5',
        });
      } finally {
        fixtureTime = null;
      }
    },
    async close() {
      await new Promise(resolve => server.close(resolve));
      projects.close();
    },
  };
}

export async function verifyBilling({ origin, data, page, direct, directEmail, directPassword, accountId, quote, tools, start, clientPlan }) {
  const merchant = 'acc_KiweTestFixture';
  const pathState = path.join(data, 'gateway.json');
  const gateway = async () => JSON.parse(await readFile(pathState, 'utf8'));
  const save = state => writeFile(pathState, JSON.stringify(state));
  const provider = await gateway();
  provider.lose_link = true;
  await save(provider);
  const status = async () => (await tools('kiwe_launch_billing_status', { accountId })).billing;
  const time = () => Math.floor(Date.now() / 1000);
  async function event(name, payload, id, { valid = true, at = time(), account = merchant } = {}) {
    const raw = JSON.stringify({ account_id: account, event: name, created_at: at, payload });
    const signature = valid ? createHmac('sha256', 'Kiwe checkout isolated webhook fixture secret').update(raw).digest('hex') : '0'.repeat(64);
    return (await fetch(origin + '/launch-api/razorpay.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Razorpay-Signature': signature, 'X-Razorpay-Event-Id': id },
      body: raw,
    })).status;
  }
  const providerHandoffs = new Map();
  async function interceptRazorpayRedirect(view) {
    await view.route(origin + '/checkout/', async route => {
      const request = route.request();
      const action = new URLSearchParams(request.postData() ?? '').get('action');
      if (request.method() !== 'POST' || !['pay-first-year', 'renewal-consent'].includes(action)) return route.continue();
      const response = await route.fetch({ maxRedirects: 0 });
      const destination = response.headers().location ?? '';
      if (response.status() === 303 && destination.startsWith('https://rzp.io/')) {
        providerHandoffs.set(view, destination);
        return route.fulfill({
          contentType: 'text/html', body: '<!doctype html><title>Razorpay fixture</title><h1>Razorpay fixture</h1>',
        });
      }
      return route.fulfill({ response });
    });
  }
  async function expectRazorpay(view) {
    try {
      await view.getByRole('heading', { name: 'Razorpay fixture' }).waitFor({ timeout: 8000 });
    } catch (error) {
      const alert = await view.getByRole('alert').allTextContents();
      throw new Error('Razorpay fixture did not open. ' + JSON.stringify({ url: view.url(), title: await view.title(), alert }), { cause: error });
    }
  }
  await interceptRazorpayRedirect(page);
  await interceptRazorpayRedirect(direct);

  const foreign = await fetch(start.origin + '/projects/api/purchases', {
    method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + start.purchaseToken },
    body: JSON.stringify({ accountId: start.pilotAccount, accountType: 'client' }),
  });
  assert.equal(foreign.status, 403, 'The commercial broker cannot act as the existing pilot owner');
  assert.equal((await fetch(start.origin + '/projects')).status, 401);
  assert.equal(Object.keys((await gateway()).subscriptions).length, 0);

  await page.locator('input[name=payment_consent]').check();
  await page.getByRole('button', { name: 'Pay first year with Razorpay' }).click();
  await page.getByRole('alert').getByText('still confirming the payment link', { exact: false }).waitFor();
  assert.equal(Object.keys((await gateway()).links).length, 1);
  await page.locator('input[name=payment_consent]').check();
  await page.getByRole('button', { name: 'Recover payment link' }).click();
  await expectRazorpay(page);
  const opened = await gateway();
  assert.equal(Object.keys(opened.links).length, 1, 'Recovery uses the same Razorpay reference');
  const link = Object.values(opened.links)[0];
  assert.equal(providerHandoffs.get(page), link.short_url);
  assert.equal(link.amount, quote.upfrontMinor);
  assert.equal(link.accept_partial, false);
  assert.deepEqual(link.notify, { email: false, sms: false });
  assert.equal(start.projects.listProjects(accountId).length, 0);
  await page.goto(origin + '/checkout/?razorpay_payment_id=pay_UntrustedCallback');
  await page.getByRole('heading', { name: 'First-year payment', exact: true }).waitFor();
  assert.equal((await status()).paid, false, 'Browser callback identifiers grant no access');

  const payment = { id: 'pay_AnnualFixture', status: 'captured', amount: quote.upfrontMinor, currency: 'INR',
    amount_refunded: 0, order_id: 'order_AnnualFixture', created_at: time() };
  Object.assign(link, { status: 'paid', amount_paid: quote.upfrontMinor, order_id: payment.order_id });
  opened.payments = { ...opened.payments, [payment.id]: payment };
  await save(opened);
  const paid = { payment_link: { entity: link }, payment: { entity: payment } };
  assert.equal(await event('payment_link.paid', paid, 'annual', { valid: false }), 401);
  assert.equal(await event('payment_link.paid', paid, 'annual', { account: 'acc_AnotherMerchant' }), 403);
  assert.equal((await status()).paid, false);
  const incorrect = { ...paid, payment: { entity: { ...payment, amount: payment.amount - 1 } } };
  assert.equal(await event('payment_link.paid', incorrect, 'wrong-price'), 409);
  const paidAt = time();
  assert.equal(await event('payment_link.paid', paid, 'annual', { at: paidAt }), 200);
  assert.equal(await event('payment_link.paid', paid, 'annual', { at: paidAt }), 200);
  assert.equal(await event('payment_link.paid', paid, 'annual-repeat', { at: paidAt }), 200);
  assert.equal((await status()).paid, true);
  const refundedPayment = { ...payment, amount_refunded: 20000 };
  const partialSnapshot = { ...paid, payment: { entity: refundedPayment } };
  assert.equal(await event('payment_link.paid', partialSnapshot, 'annual-with-refund'), 200);
  assert.equal(await event('payment_link.paid', paid, 'annual-stale-refund'), 200);
  assert.equal((await status()).refunded, 20000, 'An older payment snapshot cannot reduce the confirmed refund');
  assert.equal(Object.keys((await gateway()).subscriptions).length, 0, 'Initial payment does not enrol monthly renewals');

  await writeFile(path.join(data, 'lose-start'), 'isolated interruption');
  const firstDelivery = await tools('kiwe_launch_reconcile_billing', {});
  assert.equal(firstDelivery.retry, 1);
  assert.equal(start.projects.listProjects(accountId).length, 1, JSON.stringify(firstDelivery));
  assert.equal((await status()).projectId, null);
  await page.reload();
  await page.getByRole('button', { name: 'Open Kiwe Start' }).click();
  await page.waitForURL(new RegExp('^' + start.origin.replaceAll('.', '\\.') + '/projects/[a-f0-9-]{36}$'));
  const purchasedProjectId = page.url().split('/').at(-1);
  assert.equal((await status()).projectId, purchasedProjectId);
  assert.equal((await status()).refunded, 20000);
  assert.equal(start.projects.listProjects(accountId).length, 1, 'Retry preserves the same project');
  await page.goto(start.origin + '/projects');
  await page.getByRole('link', { name: 'MCP access' }).click();
  await page.getByRole('button', { name: 'Create token' }).click();
  const mcpToken = await page.getByLabel('Access token').inputValue();
  const projectsResponse = await fetch(start.origin + '/projects/mcp', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: 'Bearer ' + mcpToken },
    body: JSON.stringify({ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'kiwe_start_projects', arguments: {} } }),
  });
  assert.equal(projectsResponse.status, 200);
  const mcpBody = await projectsResponse.text();
  assert.ok(mcpBody.includes(purchasedProjectId));
  assert.ok(!mcpBody.includes(start.pilotAccount));

  await page.goto(origin + '/checkout/');
  await page.locator('input[name=renewal_consent]').check();
  await page.getByRole('button', { name: 'Approve renewal with Razorpay' }).click();
  await expectRazorpay(page);
  const subscription = Object.values((await gateway()).subscriptions)[0];
  assert.equal(providerHandoffs.get(page), subscription.short_url);
  const confirmation = await status();
  assert.equal(subscription.plan_id, 'plan_ReferralFixture');
  assert.equal(subscription.start_at, confirmation.firstTermEnd);
  assert.equal(subscription.total_count, 120);
  assert.equal(subscription.customer_notify, false);

  const point = time();
  const subEvent = state => ({ subscription: { entity: { ...subscription, status: state } } });
  assert.equal(await event('subscription.pending', subEvent('pending'), 'renewal-failed', { at: point + 1 }), 200);
  assert.equal(await event('subscription.authenticated', subEvent('authenticated'), 'renewal-stale', { at: point }), 200);
  assert.equal((await status()).mandate.status, 'pending');
  assert.equal((await status()).renewals.length, 0);
  const firstStart = subscription.start_at;
  const secondStart = firstStart + 31 * 86400;
  const secondEnd = secondStart + 30 * 86400;
  const renewal = { id: 'pay_MonthlyFixture', invoice_id: 'inv_MonthlyFixture', order_id: 'order_MonthlyFixture',
    amount: quote.renewalMinor, currency: 'INR', status: 'captured', amount_refunded: 0 };
  const nextRenewal = { ...renewal, id: 'pay_NextMonthFixture', invoice_id: 'inv_NextMonthFixture', order_id: 'order_NextMonthFixture' };
  const invoice = (payment, billing_start, billing_end) => ({
    id: payment.invoice_id, payment_id: payment.id, order_id: payment.order_id, subscription_id: subscription.id,
    status: 'paid', amount: payment.amount, amount_paid: payment.amount, currency: payment.currency, billing_start, billing_end,
  });
  const charged = payment => ({
    subscription: { entity: { ...subscription, status: 'active', current_start: secondStart, current_end: secondEnd } },
    payment: { entity: payment },
  });
  const fixture = await gateway();
  fixture.invoices = { [nextRenewal.invoice_id]: invoice(nextRenewal, secondStart, secondEnd) };
  await save(fixture);
  assert.equal(await event('subscription.charged', charged(nextRenewal), 'next-renewal-paid', { at: point + 3 }), 200);
  const delayed = () => event('subscription.charged', charged(renewal), 'renewal-paid', { at: point + 2 });
  assert.equal(await delayed(), 503, 'An unavailable invoice leaves the webhook eligible for retry');
  fixture.invoices[renewal.invoice_id] = { ...invoice(renewal, firstStart, secondStart), subscription_id: 'sub_UnrelatedFixture' };
  await save(fixture);
  assert.equal(await delayed(), 409, 'An invoice from another subscription does not settle this month');
  assert.deepEqual((await status()).renewals.map(row => row.start), [secondStart]);
  fixture.invoices[renewal.invoice_id] = invoice(renewal, firstStart, secondStart);
  await save(fixture);
  assert.equal(await delayed(), 200, 'A delayed charge uses its own invoice period');
  assert.equal(await delayed(), 200);
  assert.equal(await event('subscription.charged', charged(renewal), 'renewal-repeat', { at: point + 2 }), 200);
  const refund = { id: 'rfnd_PartialFixture', payment_id: renewal.id, amount: 5000, currency: 'INR', status: 'processed' };
  assert.equal(await event('refund.processed', { refund: { entity: refund } }, 'renewal-refund'), 200);
  const history = (await status()).renewals;
  assert.deepEqual(history, [
    { amount: quote.renewalMinor, start: secondStart, end: secondEnd, refunded: 0 },
    { amount: quote.renewalMinor, start: firstStart, end: secondStart, refunded: 5000 },
  ]);
  await page.goto(origin + '/checkout/');
  await page.getByRole('button', { name: 'Cancel automatic renewal' }).click();
  await page.getByText('Automatic renewal is cancelled', { exact: false }).waitFor();
  assert.equal((await status()).mandate.status, 'cancelled');
  assert.equal((await status()).projectId, purchasedProjectId);

  await direct.goto(origin + '/checkout/#sign-in');
  const signIn = direct.locator('#sign-in');
  await signIn.getByLabel('Your email').fill(directEmail);
  await signIn.getByLabel('Password', { exact: true }).fill(directPassword);
  await signIn.getByRole('button', { name: 'Sign in' }).click();
  await direct.locator('input[name=payment_consent]').check();
  await direct.getByRole('button', { name: 'Pay first year with Razorpay' }).click();
  await expectRazorpay(direct);
  const next = await gateway();
  const directLink = Object.values(next.links).find(item => item.id !== link.id);
  const directPayment = { id: 'pay_DirectFixture', status: 'captured', amount: clientPlan.direct.upfrontMinor,
    currency: 'INR', amount_refunded: 0, order_id: 'order_DirectFixture' };
  Object.assign(directLink, { status: 'paid', amount_paid: directPayment.amount, order_id: directPayment.order_id });
  next.payments[directPayment.id] = directPayment;
  await save(next);
  const reversed = { id: 'rfnd_DirectFixture', payment_id: directPayment.id, amount: directPayment.amount, currency: 'INR', status: 'processed' };
  assert.equal(await event('refund.processed', { refund: { entity: reversed } }, 'direct-refund-first'), 200);
  await direct.goto(origin + '/checkout/');
  await direct.getByRole('button', { name: 'Check payment and renewal status' }).click();
  await direct.getByRole('heading', { name: 'First-year payment refunded' }).waitFor();
  assert.equal(await event('payment_link.paid', { payment_link: { entity: directLink }, payment: { entity: directPayment } }, 'direct-paid-late'), 200);
  assert.equal(await direct.getByRole('button', { name: 'Open Kiwe Start' }).count(), 0);
  assert.equal((await tools('kiwe_launch_reconcile_billing', {})).delivered, 0);
  return { event, payment, purchaseId: link.reference_id, projectId: purchasedProjectId };
}
