const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync(require('path').join(__dirname, '../../wp-content/mu-plugins/dsa/assets/js/surface.js'), 'utf8');
const start = source.indexOf('\tfunction addProductToCart('), end = source.indexOf('\tfunction openCheckoutSurface(', start);
assert(start > 0 && end > start);
let checks = 0;
function check(label, pass) { assert(pass, label); checks++; }
function fixture({ available = true, reject = false, defer = false } = {}) {
  const handlers = {}, calls = [], notices = [], updates = [], status = { textContent: '', closest: () => null };
  let resolve;
  const document = { addEventListener(name, fn) { handlers[name] = fn; }, querySelector(selector) { if (selector === '[') throw Error('Invalid selector'); return status; } };
  const commerce = { available, current: { id: 42 } };
  const context = { document, commerce, console, Promise, Number, Error, activeOverlayPanel: '',
    closestEventTarget: event => event.target, announce: text => notices.push(text), surfaceFeedback() {},
    enqueueCartMutation: fn => Promise.resolve().then(fn), nextCartSequence: () => 1,
    dsaPost: (route, payload) => { calls.push({ route, payload }); return reject ? Promise.reject(Error('Out of stock.')) : defer ? new Promise(r => { resolve = r; }) : Promise.resolve({ cart: { count: payload.quantity } }); },
    applyCartPayload: (...args) => updates.push(args), applyWooCartFragments: () => updates.push('fragments'),
  };
  vm.runInNewContext(source.slice(start, end), context);
  function trigger(attributes = {}) {
    const attrs = { 'data-kiwe-add-to-cart': '123', 'data-kiwe-cart-status-target': '#status', ...attributes };
    return { disabled: false, tagName: 'BUTTON', getAttribute: name => Object.hasOwn(attrs, name) ? attrs[name] : null,
      setAttribute: (name, value) => { attrs[name] = value; }, removeAttribute: name => { delete attrs[name]; }, closest: () => null,
      click() { click(this); }, attrs };
  }
  function click(target) { const e = { target, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; } }; handlers.click(e); return e; }
  return { handlers, calls, notices, updates, status, commerce, trigger, click, resolve: () => resolve({ cart: { count: 1 } }) };
}
const settle = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  let f = fixture(), t = f.trigger({ 'data-kiwe-cart-quantity': '3' });
  check('No request or session work before activation', f.calls.length === 0);
  const e = f.click(t); f.click(t); await settle();
  check('One native route request despite repeated click', f.calls.length === 1 && f.calls[0].route === '/cart/add');
  check('Product and requested quantity reach shared cart owner', f.calls[0].payload.productId === 123 && f.calls[0].payload.quantity === 3);
  check('Native handlers cannot double-add', e.prevented && e.stopped);
  check('Existing cart and Woo fragments are reconciled', f.updates.length === 2);
  check('Success announced and busy state restored', f.status.textContent === 'Added to cart.' && !t.disabled && t.getAttribute('aria-busy') === null);
  f = fixture(); f.click(f.trigger({ 'data-kiwe-add-to-cart': 'current' })); await settle();
  check('Current product and default quantity resolve', f.calls[0].payload.productId === 42 && f.calls[0].payload.quantity === 1);
  f = fixture(); f.click(f.trigger({ 'data-kiwe-add-to-cart': '987' })); await settle();
  check('Concrete variation IDs use the same native route', f.calls[0].payload.productId === 987);
  for (const attrs of [ { 'data-kiwe-add-to-cart': '0' }, { 'data-kiwe-add-to-cart': '1e3' }, { 'data-kiwe-add-to-cart': '9007199254740992' }, { 'data-kiwe-add-to-cart': '' }, { 'data-kiwe-cart-quantity': '0' }, { 'data-kiwe-cart-quantity': '100' }, { 'data-kiwe-cart-quantity': '1.5' }, { 'data-kiwe-cart-quantity': '' } ]) {
    f = fixture(); f.click(f.trigger(attrs)); await settle(); check('Invalid ID/quantity performs no mutation: ' + JSON.stringify(attrs), !f.calls.length && f.notices.length === 1);
  }
  f = fixture(); f.commerce.current = {}; f.click(f.trigger({ 'data-kiwe-add-to-cart': 'current' })); await settle();
  check('Missing current context cannot infer unrelated products', !f.calls.length);
  f = fixture({ available: false }); const native = f.click(f.trigger()); await settle();
  check('Non-Woo preserves native link/form fallback', !native.prevented && !f.calls.length);
  f = fixture(); t = f.trigger(); t.disabled = true; f.click(t); await settle(); check('Disabled control cannot mutate', !f.calls.length);
  f = fixture(); t = f.trigger({ 'aria-disabled': 'true' }); f.click(t); await settle(); check('ARIA disabled cannot mutate', !f.calls.length);
  f = fixture(); t = f.trigger(); t.closest = () => ({}); f.click(t); await settle(); check('Internal cart markup is not intercepted', !f.calls.length);
  f = fixture({ reject: true }); t = f.trigger({ 'aria-busy': 'false' }); f.click(t); await settle();
  check('Native stock error visible and control reusable', f.status.textContent === 'Out of stock.' && !t.disabled && t.getAttribute('aria-busy') === 'false');
  f = fixture(); f.click(f.trigger({ 'data-kiwe-cart-status-target': '[' })); await settle(); check('Invalid optional status selector cannot break mutation or announcement', f.calls.length === 1 && f.notices.includes('Added to cart.'));
  f = fixture(); t = f.trigger(); t.tagName = 'DIV'; f.handlers.keydown({ target: t, key: ' ', preventDefault() {} }); await settle(); check('Custom role button supports keyboard activation', f.calls.length === 1);
  f = fixture(); t = f.trigger(); f.handlers.keydown({ target: t, key: 'Enter', preventDefault() {} }); await settle(); check('Native button keyboard is left to browser', !f.calls.length);
  console.log(JSON.stringify({ passed: true, checks }));
})().catch(error => { console.error(error); process.exit(1); });
