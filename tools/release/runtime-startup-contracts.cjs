const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), assert = require('node:assert/strict'), { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const native = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'test-runtime-startup.php')], { encoding: 'utf8' });
if (native.status !== 0) throw new Error(native.stdout + '\n' + native.stderr);
const proof = JSON.parse(native.stdout.trim());
assert.equal(proof.passed, true);
const early = spawnSync(process.execPath, [path.join(__dirname, 'early-startup-behavior.cjs')], { encoding: 'utf8' });
if (early.status !== 0) throw new Error(early.stdout + '\n' + early.stderr);
const earlyProof = JSON.parse(early.stdout.trim());
assert.equal(earlyProof.passed, true);
const source = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/js/surface.js'), 'utf8');
function fn(name) { const a = source.indexOf('\tfunction ' + name + '('), b = source.indexOf('\n\tfunction ', a + 1); assert(a >= 0 && b > a, name); return source.slice(a, b); }
let checks = 0;
function check(ok, label) { assert(ok, label); checks++; }
async function run() {
  const adapter = await import('data:text/javascript;base64,' + Buffer.from(fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/js/modules/commerce-panels.js'), 'utf8')).toString('base64'));
  const snapshot = { available: true, runtimeOnly: true, count: 2, total: '$133.00', productTotal: '$100.00', cartUrl: 'https://site.test/cart/', checkoutUrl: 'https://site.test/buy/', items: [{ title: 'Native item', quantity: 2, subtotal: '$120.00' }] };
  for (const visualProfile of ['legacy', 'prototype']) {
    const pending = adapter.renderCart({ visualProfile, cart: snapshot, settings: { cartShowDelivery: true } });
    check(pending.includes('data-dsa-cart-panel') && pending.includes('aria-busy="true"') && pending.includes('Refreshing your cart'), visualProfile + ' renders an accessible pending panel replaceable by the existing cart response');
    check(!pending.includes('$133.00') && !pending.includes('$100.00') && !pending.includes('data-dsa-checkout-open'), visualProfile + ' does not present snapshot amounts as a fresh payable quote');
    check(pending.includes('href="https://site.test/cart/" data-dsa-full-navigation>View cart'), visualProfile + ' offers the native cart fallback without a polling/retry loop');
    const full = adapter.renderCart({ visualProfile, cart: { ...snapshot, runtimeOnly: false, total: '$151.00', totalsSummary: { lines: [{ type: 'subtotal', label: 'Products', amount: '$100.00' }, { type: 'shipping', label: 'Delivery', amount: '$51.00' }], total: '$151.00' } }, settings: { cartShowDelivery: true } });
    check(!full.includes('Refreshing your cart') && full.includes('$151.00') && full.includes('data-dsa-checkout-open'), visualProfile + ' full response restores authoritative total, detail and Checkout');
    check(!adapter.renderCart({ visualProfile, cart: { ...snapshot, count: 0, items: [] } }).includes('Refreshing your cart'), visualProfile + ' neutral empty cart remains directly readable');
    check(!adapter.renderCart({ visualProfile, cart: { ...snapshot, available: false } }).includes('Refreshing your cart'), visualProfile + ' unavailable Woo retains its existing presentation');
  }
  const context = { aiNotificationHistory: [{ id: 'cart-discount:before', type: 'discount_applied', discountSignature: 'prior' }], aiInsightMemory: { interacted: {} }, aiNotificationQueue: [], cartOfferMap: cart => Object.fromEntries((cart.upsells || []).map(offer => [offer.triggerId + ':' + offer.id, offer])), discountSignature: value => value ? 'full' : '', aiStringHash: value => value, visibleAiPopoutCards: () => [], saveAiNotificationHistory() {}, saveAiInsightMemory() {}, hideAiPopout() {} };
  vm.createContext(context);
  vm.runInContext(fn('reconcileAiCartState'), context);
  context.reconcileAiCartState({}, { runtimeOnly: true, upsells: [] });
  check(context.aiNotificationHistory.length === 1, 'Partial startup does not erase an applied-discount receipt because details were deferred');
  context.reconcileAiCartState({}, { upsells: [], discountSummary: null });
  check(context.aiNotificationHistory.length === 0, 'A full native cart response still retires a discount receipt no longer valid');
  const cartContext = { phonekey: { cart: snapshot }, cartAppliedSequence: 0, cartStateInitialized: true, firstCartConfettiPlayedForCart: false, firstCartConfettiQueued: false, overlayRoot: { hidden: false, querySelector: selector => selector === '[data-dsa-cart-panel]' ? {} : null }, checkoutState: { contract: {} }, commerce: { settings: { cartShowDelivery: false } }, debugLog() {}, playFirstCartConfetti() {}, reconcileAiCartState() {}, updateDockBadge() {}, syncAiInsights() {}, remindPendingCheckoutActions() {}, emitCartAiNotifications() {}, screenLabel: () => 'Cart', getSurfaceModule: () => ({}), renderCartPanel: () => 'full-cart-panel', replaceOverlayContent(html) { this.rendered = html; }, bindCartPanel() {}, refreshUniversalAddToCartEnhancers() {}, isCurrentCheckoutPage: () => false };
  vm.createContext(cartContext);
  vm.runInContext(fn('applyCartPayload'), cartContext);
  const authoritative = { available: true, count: 3, total: '$151.00', productTotal: '$120.00', items: [{ key: 'new-session-item', productId: 11, variationId: 0, quantity: 3 }], discountSummary: { hasDiscount: false }, productDiscountSummary: { total: '$120.00' } };
  cartContext.applyCartPayload({ cart: authoritative }, { sequence: 2, rerender: true, cartMutation: true });
  check(cartContext.phonekey.cart === authoritative && !cartContext.phonekey.cart.runtimeOnly && cartContext.phonekey.cart.items[0].key === 'new-session-item', 'Full mutation payload replaces the startup flag and keeps its native item identity');
  check(cartContext.cartAppliedSequence === 2 && cartContext.checkoutState.contract.cartTotal === '$120.00' && cartContext.checkoutState.contract.discountSummary === authoritative.productDiscountSummary, 'Existing mutation sequencing and product-only Checkout contract survive startup deferral');
  cartContext.applyCartPayload({ cart: snapshot }, { sequence: 1, rerender: true });
  check(cartContext.phonekey.cart === authoritative && cartContext.cartAppliedSequence === 2, 'An older cart response cannot restore a partial snapshot after a newer mutation');
  console.log(JSON.stringify({ passed: true, phpChecks: proof.checks.length, jsChecks: checks, earlyChecks: earlyProof.checks, scope: proof.scope }));
}
run().catch(error => { console.error(error); process.exitCode = 1; });
