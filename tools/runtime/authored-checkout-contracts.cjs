const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../wp-content/mu-plugins/dsa/assets/js/checkout-experience.js'), 'utf8');
function control(data = {}) {
 const attributes = {};
 return {
  dataset: data, hidden: false, disabled: false, textContent: '',
  hasAttribute: name => name === 'data-kiwe-checkout-consent' ? 'kiweCheckoutConsent' in data : name === 'data-kiwe-checkout-pay' && 'kiweCheckoutPay' in data,
  setAttribute: (name, value) => { attributes[name] = value; },
  toggleAttribute: (name, present) => { if (present) attributes[name] = ''; else delete attributes[name]; },
  getAttribute: name => attributes[name],
 };
}

async function run() {
 const base = 'https://store.example';
 const requests = [];
 const receipt = [];
 const events = {};
 const address = control({kiweCheckoutValue: 'address'});
 const total = control({kiweCheckoutValue: 'total'});
 const status = control();
 const upi = control({kiweCheckoutPay: 'upi'});
 const subscription = control({kiweCheckoutPay: 'subscription'});
 const cod = control({kiweCheckoutPay: 'cod'});
 const renewal = control({kiweCheckoutConsent: 'renewal'});
 const terms = control({kiweCheckoutConsent: 'terms'});
 const couponEvents = {};
 const coupon = {
  ...control(),
  matches: selector => selector === '.brxe-woocommerce-checkout-coupon',
  addEventListener: (name, handler, capture) => { couponEvents[name] = {handler, capture}; },
  querySelector: () => ({value: 'FIXTURE'}),
 };
 const page = {
  ...control(),
  addEventListener: (name, handler) => { events[name] = handler; },
  querySelectorAll(selector) {
   if (selector === '[data-kiwe-checkout-pay]') return [upi, subscription, cod];
   if (selector === '[data-kiwe-checkout-consent]') return [renewal, terms];
   return [address, total];
  },
  querySelector: selector => selector === '[data-kiwe-checkout-status]' ? status : coupon,
  contains: () => true,
 };
 const review = {
  available: true, address: 'Demo Street', total: '₹300', empty: false, subscriptionQuote: 'quote1',
  methods: {subscription: 'kiwe_razorpay_subscription'}, shipping: [], fields: {billing_email: 'test@example.invalid'},
  checkoutNonce: 'fixture', checkoutUrl: '/?wc-ajax=checkout', couponNonce: 'fixture', couponUrl: '/?wc-ajax=apply_coupon',
  termsRequired: true, coupons: true,
 };
 let reviewFails = false;
 let reviewReads = 0;
 const settle = async () => { for (let i = 0; i < 25; i++) await Promise.resolve(); };
 async function click(button) {
  events.click({target: {closest: () => button}, preventDefault() {}});
  await settle();
 }
 const context = {
  document: {querySelector: () => page},
  window: {
   location: {href: base + '/checkout/', origin: base, assign: url => receipt.push(url)},
   addEventListener: (name, handler) => { events[name] = handler; },
   DSA: {readCheckoutReview: async () => {
    reviewReads++;
    if (reviewFails) throw new Error('Temporary review failure');
    return {...review};
   }},
  },
  URL, URLSearchParams,
  DOMParser: class { parseFromString(text) { return {body: {textContent: text}}; } },
  fetch: async (url, options) => {
   requests.push([url.href, Object.fromEntries(options.body)]);
   if (url.href.endsWith('apply_coupon')) {
    review.total = '₹275';
    review.subscriptionQuote = 'quote2';
    return {ok: true, text: async () => 'Coupon applied'};
   }
   return {json: async () => ({result: 'success', redirect: '/checkout/order-received/123/'})};
  },
 };
 vm.runInNewContext(source, context);
 assert.equal(page.getAttribute('data-kiwe-checkout-ready'), undefined);
 assert.equal(upi.hidden, true);
 assert.equal(subscription.hidden, true);
 await settle();
 assert.equal(address.textContent, 'Demo Street');
 assert.equal(status.textContent, '');
 assert.equal(upi.hidden, true);
 assert.equal(cod.hidden, true);
 assert.equal(subscription.hidden, false);
 await click(subscription);
 assert.equal(requests.length, 0);
 assert.match(status.textContent, /Accept the terms/);
 await click(terms);
 await click(subscription);
 assert.equal(requests.length, 0);
 assert.match(status.textContent, /agree to automatic renewals/);
 await click(renewal);
 let capturedCoupon = false;
 assert.equal(couponEvents.click.capture, true);
 couponEvents.click.handler({
  target: {closest: selector => selector === 'button[name="apply_coupon"]'},
  preventDefault() {},
  stopImmediatePropagation() { capturedCoupon = true; },
 });
 await settle();
 assert.equal(capturedCoupon, true);
 assert.equal(total.textContent, '₹275');
 assert.equal(renewal.getAttribute('aria-pressed'), 'false');
 assert.equal(requests[0][1].coupon_code, 'FIXTURE');
 assert.equal(requests[0][1].billing_email, 'test@example.invalid');
 await click(renewal);
 await click(subscription);
 assert.equal(requests[1][1].payment_method, 'kiwe_razorpay_subscription');
 assert.equal(requests[1][1].kiwe_subscription_quote, 'quote2');
 assert.equal(receipt[0], base + '/checkout/order-received/123/');
 review.address = 'Edited Street';
 review.methods = {razorpay: 'razorpay', upi: 'razorpay', cod: 'cod'};
 review.subscriptionQuote = '';
 await events['dsa:checkout:draft-applied']();
 assert.equal(address.textContent, 'Edited Street');
 assert.equal(subscription.hidden, true);
 assert.equal(upi.hidden, false);
 await click(upi);
 assert.equal(requests[2][1].payment_method, 'razorpay');
 assert.equal(requests[2][1].kiwe_checkout_method, 'upi');
 assert.equal(requests[2][1].kiwe_subscription_consent, undefined);
 await click(cod);
 assert.equal(requests[3][1].payment_method, 'cod');
 assert.equal(requests[3][1].terms, 'on');
 let completeCartUpdate;
 const cartUpdate = new Promise(resolve => { completeCartUpdate = resolve; });
 events['dsa:checkout:cart-updating']({detail: cartUpdate});
 assert.equal(upi.hidden, false);
 assert.equal(upi.disabled, true);
 await click(upi);
 assert.equal(requests.length, 4);
 review.total = '₹550';
 const beforeCartUpdate = reviewReads;
 events['dsa:checkout:cart-updated']();
 assert.equal(reviewReads, beforeCartUpdate);
 completeCartUpdate();
 await settle();
 assert.equal(reviewReads, beforeCartUpdate + 1);
 assert.equal(total.textContent, '₹550');
 assert.equal(upi.disabled, false);
 reviewFails = true;
 events['dsa:checkout:cart-updated']();
 await settle();
 assert.equal(upi.hidden, true);
 assert.equal(cod.hidden, true);
 assert.match(status.textContent, /could not load/);
 console.log('Bricks checkout bindings, payment visibility, live cart review, consent and native dispatch passed.');
}

run().catch(error => { console.error(error); process.exitCode = 1; });
