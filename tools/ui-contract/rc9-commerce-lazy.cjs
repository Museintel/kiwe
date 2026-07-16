const fs = require('fs');
const path = require('path');

(async () => {
  const root = path.resolve(__dirname, '..', '..');
  const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
  const core = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
  const moduleSource = read('wp-content/mu-plugins/dsa/assets/js/modules/commerce-panels.js');
  const css = read('wp-content/mu-plugins/dsa/assets/css/surface.css');
  const checkoutService = read('wp-content/mu-plugins/dsa/includes/Commerce/Checkout_Service.php');
  const assets = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
  const checks = [];
  const check = (name, pass, detail = '') => checks.push({ name, pass: Boolean(pass), detail });

  check('Cart and Checkout share one lazy presentation module', assets.includes("'cart' =>") && assets.includes("'checkout' =>") && (assets.match(/commerce-panels\.js/g) || []).length === 2);
  check('Core Cart delegates rendering after first import', core.includes("presentationModules.get( 'cart' )") && core.includes('adapter.renderCart'));
  check('Core Checkout delegates rendering after first import', core.includes("presentationModules.get( 'checkout' )") && core.includes('adapter.renderCheckout'));
  check('Checkout refresh can hydrate its lazy placeholder', core.includes('module && hydrateLazyPresentation( module )'));
  check('Heavy commerce helpers left the persistent shell', !/function render(?:DiscountSummary|CheckoutNotices|CheckoutField|CartRecommendations|CartRecommendationCard|CartPanelItem)\s*\(/.test(core));
  check('Persistent shell is below the RC9A raw budget', Buffer.byteLength(core) < 365000, `${Buffer.byteLength(core)} bytes`);
  check('Presentation module exports Cart and Checkout', moduleSource.includes('export function renderCart') && moduleSource.includes('export function renderCheckout'));
  check('Presentation module is pure and mutation-free', !/\b(?:fetch|dsaPost|XMLHttpRequest|localStorage|sessionStorage)\s*\(/.test(moduleSource) && !moduleSource.includes('window.') && !moduleSource.includes('document.'));
  check('Woo and checkout authority remain in core', core.includes("dsaPost( '/cart/add'") && core.includes("dsaPost( '/checkout'") && core.includes('applyWooCartFragments'));
  check('Checkout drafts prime Woo session and durable customer addresses', checkoutService.includes('$this->prime_customer_session( $draft );') && checkoutService.includes('$this->persist_customer_address_drafts( $draft, $definitions );'));
  check('Durable checkout address persistence is address-group scoped', checkoutService.includes('address_group_ready_for_persistence') && checkoutService.includes('address_group_has_validation_errors') && checkoutService.includes('persist_customer_address_group'));
  check('Checkout address persistence keeps full checkout validation separate', checkoutService.includes('persist_customer_addresses( $draft )') && checkoutService.includes("if ( $validate )"));
  check('Sheet checkout starts at scroll origin', /\.dsa-theme-sheet \.dsa-checkout-panel\s*\{[\s\S]*?align-content:\s*start;[\s\S]*?min-height:\s*auto;/.test(css) && /\.dsa-theme-sheet \.dsa-overlay-root > \.dsa-panel\s*\{[\s\S]*?scroll-padding-top:\s*max\(24px, var\(--dsa-screen-gutter\)\);/.test(css));

  const url = 'data:text/javascript;base64,' + Buffer.from(moduleSource).toString('base64');
  const adapter = await import(url);
  const cartHtml = adapter.renderCart({
    label: 'Cart',
    cart: { available: true, count: 1, total: '$12.00', checkoutUrl: '/checkout', items: [{ key: 'abc', title: '<Test>', quantity: 1, maxQuantity: 3, subtotal: '$12.00', productId: 7 }] },
    settings: { cartQuantityControls: true, fbtEnabled: false }, routes: {}, complements: [],
  });
  check('Cart fixture renders canonical semantics', cartHtml.includes('data-dsa-cart-panel') && cartHtml.includes('data-dsa-cart-quantity="abc"') && cartHtml.includes('&lt;Test&gt;') && !cartHtml.includes('<Test>'));

  const checkoutHtml = adapter.renderCheckout({
    checkoutState: { loading: false, errors: {}, notices: [], returnToPage: false, contract: { available: true, groups: { billing: [{ key: 'billing_email', type: 'email', label: 'Email', value: 'a@example.com', required: true }, { key: 'billing_address_1', type: 'text', label: 'Street address', value: '', required: true }], shipping: [{ key: 'shipping_address_1', type: 'text', label: 'Shipping street address', value: '', required: false }], order: [{ key: 'order_comments', type: 'textarea', label: 'Order notes', value: '', required: false }] }, cartTotal: '$12.00', needsShipping: true } },
    settings: {}, routes: {},
  });
  check('Checkout fixture renders canonical form contract', checkoutHtml.includes('data-dsa-checkout-panel') && checkoutHtml.includes('name="billing_email"') && checkoutHtml.includes('name="billing_address_1"') && checkoutHtml.includes('name="shipping_address_1"') && checkoutHtml.includes('name="order_comments"') && checkoutHtml.includes('data-dsa-checkout-form'));

  for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}${item.detail ? ` :: ${item.detail}` : ''}`);
  const failed = checks.filter((item) => !item.pass);
  console.log(`\n${checks.length - failed.length}/${checks.length} RC9A commerce lazy contracts passed.`);
  if (failed.length) process.exit(1);
})().catch((error) => { console.error(error); process.exit(1); });
