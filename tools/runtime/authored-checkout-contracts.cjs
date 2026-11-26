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
 const coupon = {...control(), addEventListener() {}, querySelector: () => ({value: 'FIXTURE'})};
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
   DSA: {readCheckoutReview: async () => ({...review})},
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
 await settle();
 assert.equal(address.textContent, 'Demo Street');
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
 const apply = control();
 apply.hasAttribute = name => name === 'data-kiwe-checkout-apply-coupon';
 await click(apply);
 assert.equal(total.textContent, '₹275');
 assert.equal(renewal.getAttribute('aria-pressed'), 'false');
 assert.equal(requests[0][1].coupon_code, 'FIXTURE');
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
 console.log('Bricks checkout bindings, consent, coupon refresh and native payment dispatch passed.');
}

run().catch(error => { console.error(error); process.exitCode = 1; });
