import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';
import {writeFile} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {promisify} from 'node:util';

const {chromium} = createRequire(join(process.env.KIWE_PLAYWRIGHT, 'package.json'))('playwright');
const run = promisify(execFile);
const fixtureScript = fileURLToPath(new URL('./test-wordpress-kiwe-shopper-checkout.php', import.meta.url));
async function native(phase) {
  const {stdout} = await run(process.env.KIWE_PHP, ['-c', process.env.KIWE_INI, fixtureScript, phase], {windowsHide:true, timeout:60000});
  return JSON.parse(stdout);
}
const gateway = 'kiwe_razorpay_subscription';
const consent = '[name="kiwe_subscription_consent"]';
const quote = '[name="kiwe_subscription_quote"]';
const checks = [];
const pass = description => checks.push(description);
const fixture = await native('seed');
const origin = new URL(fixture.origin).origin;
assert.equal(origin, 'http://127.0.0.1:64549');
const api = path => new URL(path, origin).href;
let browser;
try {
  browser = await chromium.launch({executablePath:process.env.KIWE_CHROME, headless:true});
  async function client(user) {
    const context = await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});
    if (user) await context.addCookies([fixture.users[user]]);
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    return page;
  }
  async function add(page, vary = '', ordinary = false) {
    const ids = fixture.products;
    const path = new URL('/', origin);
    if (vary) {
      path.searchParams.set('add-to-cart', ids.variable_id);
      path.searchParams.set('variation_id', vary === 'member' ? ids.member_variant_id : ids.variant_id);
      path.searchParams.set('attribute_size', vary === 'member' ? 'large' : 'small');
    } else {
      path.searchParams.set('add-to-cart', ordinary ? ids.simple_id : ids.product_id);
    }
    assert.ok((await page.request.get(path.href)).ok());
  }
  async function checkout(page) {
    await page.goto(fixture.checkout, {waitUntil:'domcontentloaded'});
    const form = page.locator('form.checkout');
    assert.equal(await form.count(), 1, 'Expected classic checkout at ' + new URL(page.url()).pathname);
    await page.locator('form.checkout .woocommerce-checkout-payment').waitFor();
  }
  async function data(page, method = gateway) {
    const nonce = page.locator('form.checkout [name="woocommerce-process-checkout-nonce"]');
    return {
      'woocommerce-process-checkout-nonce':await nonce.inputValue(), payment_method:method,
      billing_first_name:'Test', billing_last_name:'Shopper', billing_email:fixture.guestEmail,
      billing_phone:'9999900123', billing_address_1:'1 Fixture Street', billing_city:'Mumbai',
      billing_state:'MH', billing_postcode:'400001', billing_country:'IN', terms:'on',
    };
  }
  async function submit(page, overrides = {}) {
    const response = await page.request.post(api('/?wc-ajax=checkout'), {form:{...await data(page),...overrides}});
    assert.equal(response.status(), 200);
    return response.json();
  }
  async function withConsent(page) {
    return {kiwe_subscription_quote:await page.locator(quote).inputValue(), kiwe_subscription_consent:'yes'};
  }

  const guest = await client();
  await add(guest);
  await checkout(guest);
  assert.equal(await guest.locator(consent).count(), 0);
  assert.match((await submit(guest)).messages, /sign in to an active account/i);
  pass('The classic checkout requires an active customer account for a subscription.');
  const guestOrder = await client();
  await add(guestOrder, '', true);
  await checkout(guestOrder);
  assert.equal(await guestOrder.locator(consent).count(), 0);
  assert.equal((await submit(guestOrder, {payment_method:'cod'})).result, 'success');
  assert.equal(await native('stock'), 4);
  pass('Ordinary guest COD completes through the original Woo gateway.');

  const suspended = await client('suspended');
  await add(suspended);
  await checkout(suspended);
  assert.equal(await suspended.locator(consent).count(), 0);
  const denied = await submit(suspended);
  assert.equal(denied.result, 'failure');
  pass('A suspended account cannot agree to a recurring transaction.');

  const shopper = await client('shopper');
  await add(shopper);
  await add(shopper, '', true);
  await add(shopper, 'ordinary');
  await checkout(shopper);
  await shopper.locator(consent).waitFor();
  assert.match(await shopper.locator('.payment_box').innerText(), /8,748\.00.*12 months.*700\.00/s);
  assert.equal(await shopper.locator(consent).isChecked(), false);
  const before = await withConsent(shopper);
  const missing = await submit(shopper);
  assert.equal(missing.result, 'failure');
  assert.match(missing.messages, /agree to continue/i);
  const changedGateway = await submit(shopper, {...before, payment_method:'cod'});
  assert.equal(changedGateway.result, 'failure');
  assert.match(changedGateway.messages, /subscription checkout/i);
  pass('A mixed cart shows the upfront total and recurring schedule, requires consent and excludes one-time payment methods.');

  await shopper.locator(consent).check();
  const security = await shopper.evaluate(() => window.wc_checkout_params.apply_coupon_nonce);
  const refreshed = shopper.waitForResponse(response => response.url().includes('wc-ajax=update_order_review'));
  const coupon = await shopper.request.post(api('/?wc-ajax=apply_coupon'), {form:{coupon_code:fixture.coupon, security}});
  assert.ok(coupon.ok());
  await shopper.evaluate(() => window.jQuery(document.body).trigger('update_checkout'));
  await refreshed;
  await shopper.waitForFunction(old => document.querySelector('input[name="kiwe_subscription_quote"]')?.value !== old, before.kiwe_subscription_quote);
  assert.equal(await shopper.locator(consent).isChecked(), false);
  assert.match(await shopper.locator('.payment_box').innerText(), /8,738\.00.*700\.00/s);
  const stale = await submit(shopper, before);
  assert.equal(stale.result, 'failure');
  assert.match(stale.messages, /review the current total/i);
  pass('A changed coupon refreshes the quote and clears the mobile checkbox. An old quote is rejected by the server.');

  await native('wrong-plan');
  const otherPlan = await submit(shopper, await withConsent(shopper));
  assert.equal(otherPlan.result, 'failure');
  assert.deepEqual(await native('inspect'), []);
  await native('correct-plan');
  pass('A mismatched Razorpay renewal price is rejected before beginning provider approval.');
  const placed = await submit(shopper, await withConsent(shopper));
  assert.equal(placed.result, 'success');
  assert.match(placed.redirect, /^https:\/\/rzp\.io\/rzp\/OfflineFixture1$/);
  let attempts = await native('inspect');
  assert.equal(attempts.length, 1);
  const first = attempts[0];
  assert.equal(first.upfront, 873800);
  assert.equal(first.firstCharge, 873800);
  assert.equal(first.renewal, 70000);
  assert.equal(first.firstTerm, 12);
  assert.equal(first.lines, 3);
  assert.equal(first.coupons, 1);
  assert.equal(first.cycles, 12);
  assert.ok(first.savedBeforeProvider && first.ownedConsent && first.awaiting && first.known && first.expiry);
  assert.equal(first.notified, false);
  assert.equal(await native('stock'), 3);
  pass('The real Woo order persists all three items, coupon and exact buyer-approved prices before asking Razorpay to authorize.');
  await shopper.goto(first.url, {waitUntil:'domcontentloaded'});
  const continuation = shopper.getByRole('link', {name:'Continue subscription approval'});
  assert.equal(await continuation.getAttribute('href'), placed.redirect);
  const other = await client('other');
  await other.goto(first.url, {waitUntil:'domcontentloaded'});
  assert.equal(await other.getByText('Continue subscription approval').count(), 0);
  await other.goto(fixture.orderPay, {waitUntil:'domcontentloaded'});
  assert.equal(await other.locator('form#order_review button#place_order').count(), 0);
  pass('Only the order owner can resume approval. A pending recurring order exposes no native one-time pay button.');

  await add(other);
  const restAuth = {'X-WP-Nonce':fixture.nonces.other};
  const storeCart = await other.request.get(api('/wp-json/wc/store/v1/cart'), {headers:restAuth});
  assert.ok(storeCart.ok());
  assert.equal((await storeCart.json()).items.length, 1);
  const blockRequest = method => other.request.post(api('/wp-json/wc/store/v1/checkout'), {
    headers:{...restAuth, Nonce:storeCart.headers().nonce},
    data:{payment_method:method, billing_address:{first_name:'Test', last_name:'Shopper', email:fixture.guestEmail,
      phone:'9999900123', address_1:'1 Fixture Street', city:'Mumbai', state:'MH', postcode:'400001', country:'IN'}},
  });
  const disabledMethod = await blockRequest('cod');
  assert.equal((await disabledMethod.json()).code, 'woocommerce_rest_checkout_payment_method_disabled');
  const block = await blockRequest(gateway);
  assert.equal((await block.json()).code, 'kiwe_subscription_checkout_required');
  assert.equal(block.status(), 409);
  assert.equal((await native('inspect')).length, 1);
  pass('Woo Checkout Blocks reject a recurring cart without creating a provider payment.');

  const sameShopper = await client('shopper');
  await add(sameShopper);
  await checkout(sameShopper);
  const duplicate = await submit(sameShopper, await withConsent(sameShopper));
  assert.equal(duplicate.result, 'failure');
  assert.match(duplicate.messages, /your order/i);
  assert.equal((await native('inspect')).length, 1);
  pass('Retrying the checkout in another browser profile links to the same order without another Razorpay request.');

  const timeout = await client('timeout');
  await add(timeout, 'member');
  await add(timeout, '', true);
  await checkout(timeout);
  const lost = await submit(timeout, await withConsent(timeout));
  assert.equal(lost.result, 'success');
  assert.equal(new URL(lost.redirect).origin, origin);
  attempts = await native('inspect');
  assert.equal(attempts.length, 2);
  assert.ok(attempts[1].awaiting && attempts[1].savedBeforeProvider && !attempts[1].known);
  assert.equal(await native('stock'), 2);
  await timeout.goto(lost.redirect, {waitUntil:'domcontentloaded'});
  assert.equal(await timeout.getByText('Your subscription order is recorded.', {exact:false}).count(), 1);
  await add(timeout, 'member');
  await checkout(timeout);
  assert.match((await submit(timeout, await withConsent(timeout))).messages, /your order/i);
  assert.equal((await native('inspect')).length, 2);
  pass('A timeout after provider acceptance preserves one recoverable order, including for a recurring variation.');
  assert.deepEqual(await native('expire'), {status:'cancelled', remote:'expired'});
  assert.equal(await native('stock'), 3);
  await timeout.goto(lost.redirect, {waitUntil:'domcontentloaded'});
  assert.equal(await timeout.getByText('Continue subscription approval').count(), 0);
  pass('MCP recovers the matching mandate. Provider-confirmed expiry cancels its unpaid Woo order and releases its one-time inventory.');

  assert.deepEqual(await native('authorize'), {response:503, order:'on-hold'});
  const captured = await native('capture');
  assert.equal(captured.response, 200);
  assert.ok(['processing','completed'].includes(captured.order));
  assert.deepEqual(await native('capture'), captured);
  assert.equal(await native('stock'), 3);
  assert.equal((await native('inspect'))[0].settled, true);
  await shopper.reload({waitUntil:'domcontentloaded'});
  assert.equal(await shopper.getByText('Continue subscription approval').count(), 0);
  pass('A signed authorization-only result leaves the order on hold. A verified captured invoice settles that same order and replay is safe.');

  const beforeClose = await client();
  await add(beforeClose, '', true);
  await checkout(beforeClose);
  const openOrder = await data(beforeClose, 'cod');
  await other.goto(fixture.ordinaryOrderPay, {waitUntil:'domcontentloaded'});
  assert.equal(await other.locator('form#order_review button#place_order').count(), 1);
  await native('store-off');
  const rejected = await beforeClose.request.post(api('/?wc-ajax=checkout'), {form:openOrder});
  assert.equal((await rejected.json()).result, 'failure');
  await other.reload({waitUntil:'domcontentloaded'});
  assert.equal(await other.locator('form#order_review button#place_order').count(), 0);
  const closed = await client();
  await add(closed, '', true);
  await add(closed);
  const closedCart = await closed.request.get(api('/wp-json/wc/store/v1/cart'));
  assert.deepEqual((await closedCart.json()).items, []);
  assert.equal(await native('stock'), 3);
  assert.equal((await native('inspect')).length, 2);
  pass('With Store off, neither product type can enter a fresh cart, a previously loaded checkout cannot place its order and pending order-pay is closed.');

  await native('store-on');
  await add(closed, '', true);
  await checkout(closed);
  assert.equal((await submit(closed, {payment_method:'cod'})).result, 'success');
  assert.equal(await native('stock'), 2);
  await add(closed);
  const reopened = await closed.request.get(api('/wp-json/wc/store/v1/cart'));
  assert.ok((await reopened.json()).items.some(item => item.id === fixture.products.product_id));
  pass('Turning Store back on restores ordinary checkout and recurring product availability.');
} finally {
  if (browser) await browser.close();
  assert.deepEqual(await native('cleanup'), {cleaned:true});
}

const proof = {passed:true, cleaned:true, provider:'simulated Razorpay API and signed test events', browser:'390 px mobile Chrome viewport', checks};
await writeFile(join(process.env.KIWE_CHECKOUT_LAB, 'shopper-checkout-proof.json'), JSON.stringify(proof, null, 2) + '\n');
console.log(JSON.stringify(proof));
