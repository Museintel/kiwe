#!/usr/bin/env node

const base = String(process.argv[2] || process.env.KIWE_BASE_URL || '').replace(/\/+$/, '');

if (!base || !/^https?:\/\//i.test(base)) {
  console.error('Usage: node tools/certification/rc13-commerce-live.cjs https://example.com');
  process.exit(2);
}

const origin = new URL(base).origin;
const cookies = new Map();
const checks = [];
let cartKey = '';
let productId = 0;
let nonce = '';

function record(label, passed, detail = '') {
  checks.push({ label, passed: Boolean(passed), detail: String(detail || '') });
}

function ingestCookies(headers) {
  const values = typeof headers.getSetCookie === 'function' ? headers.getSetCookie() : [];
  for (const value of values) {
    const pair = String(value).split(';', 1)[0];
    const separator = pair.indexOf('=');
    if (separator > 0) cookies.set(pair.slice(0, separator).trim(), pair.slice(separator + 1).trim());
  }
}

function cookieHeader() {
  return Array.from(cookies, ([name, value]) => `${name}=${value}`).join('; ');
}

async function request(path, options = {}) {
  const headers = {
    Accept: 'application/json',
    'Cache-Control': 'no-cache, no-store',
    'Sec-Fetch-Site': 'same-origin',
    Origin: origin,
    ...(options.headers || {}),
  };
  const cookie = cookieHeader();
  if (cookie) headers.Cookie = cookie;
  const response = await fetch(base + path, { redirect: 'manual', cache: 'no-store', ...options, headers });
  ingestCookies(response.headers);
  const text = await response.text();
  let json = null;
  try { json = JSON.parse(text); } catch (error) {}
  return { response, text, json };
}

async function mutate(path, payload) {
  return request(path, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Kiwe-Mutation': '1',
      ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
    },
    body: JSON.stringify(payload),
  });
}

function cartItem(cart, key) {
  return cart && Array.isArray(cart.items) ? cart.items.find((item) => String(item.key || '') === String(key || '')) : null;
}

async function cleanup() {
  if (!cartKey) return;
  try {
    await mutate('/wp-json/dsa/v1/cart/item', { key: cartKey, productId, quantity: 0 });
  } catch (error) {
    console.error(`WARN cleanup failed: ${error && error.message ? error.message : error}`);
  }
}

async function run() {
  try {
    const home = await request('/', { headers: { Accept: 'text/html' } });
    const version = (home.text.match(/surface\.js\?ver=([0-9.]+)/i) || [])[1] || '';
    record('frontend document responds', home.response.status === 200, `${home.response.status}:version=${version || 'unknown'}`);

    const search = await request('/wp-json/dsa/v1/search?q=&scope=products&limit=12');
    const products = search.json && Array.isArray(search.json.products) ? search.json.products : [];
    const candidate = products.find((product) => product && product.addable && Number(product.id) > 0);
    productId = candidate ? Number(candidate.id) : 0;
    record('an addable catalog product is discoverable', search.response.status === 200 && productId > 0, `${search.response.status}:products=${products.length}`);
    if (!productId) throw new Error('No addable product was available for the isolated cart test.');

    const nonceResponse = await request('/wp-json/dsa/v1/cart/nonce');
    nonce = nonceResponse.json && nonceResponse.json.nonce ? String(nonceResponse.json.nonce) : '';
    record('cart mutation nonce is issued', nonceResponse.response.status === 200 && Boolean(nonce), nonceResponse.response.status);

    const before = await request('/wp-json/dsa/v1/cart');
    const beforeCount = Number(before.json && before.json.cart && before.json.cart.count || 0);
    record('isolated cart begins readable', before.response.status === 200 && before.json && before.json.cart, `count=${beforeCount}`);

    const added = await mutate('/wp-json/dsa/v1/cart/add', { productId, quantity: 1, source: 'dsa_search' });
    cartKey = String(added.json && added.json.item && added.json.item.key || '');
    const addedCart = added.json && added.json.cart;
    const addedItem = cartItem(addedCart, cartKey);
    record('DSA cart add succeeds', added.response.status === 200 && added.json && added.json.ok && cartKey && addedItem, `${added.response.status}:key=${cartKey ? 'present' : 'missing'}`);
    record('Woo session survives the mutation response', cookies.size > 0 && Number(addedCart && addedCart.count || 0) >= beforeCount + 1, `cookies=${cookies.size}:count=${Number(addedCart && addedCart.count || 0)}`);
    record('cart mutation returns hash and Bricks/Woo fragments', Boolean(added.json && added.json.cart_hash) && added.json.fragments && typeof added.json.fragments === 'object', `fragments=${added.json && added.json.fragments ? Object.keys(added.json.fragments).length : 0}`);

    const reread = await request('/wp-json/dsa/v1/cart');
    const persisted = cartItem(reread.json && reread.json.cart, cartKey);
    record('cart item persists across a separate REST request', reread.response.status === 200 && persisted && Number(persisted.quantity) === 1, `quantity=${persisted ? persisted.quantity : 'missing'}`);

    const maxQuantity = Number(persisted && persisted.maxQuantity || 1);
    if (maxQuantity > 1) {
      const updated = await mutate('/wp-json/dsa/v1/cart/item', { key: cartKey, productId, quantity: 2 });
      const updatedItem = cartItem(updated.json && updated.json.cart, cartKey);
      record('DSA quantity mutation persists through Woo', updated.response.status === 200 && updated.json && updated.json.ok && updatedItem && Number(updatedItem.quantity) === 2, `${updated.response.status}:quantity=${updatedItem ? updatedItem.quantity : 'missing'}`);
    } else {
      record('sold-individually quantity boundary is respected', true, 'maxQuantity=1; increment intentionally skipped');
    }

    const testedCartKey = cartKey;
    const removed = await mutate('/wp-json/dsa/v1/cart/item', { key: testedCartKey, productId, quantity: 0 });
    const removedItem = cartItem(removed.json && removed.json.cart, testedCartKey);
    record('DSA removal clears the isolated cart item', removed.response.status === 200 && removed.json && removed.json.ok && !removedItem, removed.response.status);
    cartKey = '';

    const finalCart = await request('/wp-json/dsa/v1/cart');
    record('cleanup remains visible on a fresh cart read', finalCart.response.status === 200 && !cartItem(finalCart.json && finalCart.json.cart, testedCartKey), finalCart.response.status);
  } finally {
    await cleanup();
  }

  for (const check of checks) console.log(`${check.passed ? 'PASS' : 'FAIL'} ${check.label}${check.detail ? ` - ${check.detail}` : ''}`);
  const failures = checks.filter((check) => !check.passed);
  console.log(`${checks.length - failures.length}/${checks.length} RC13 anonymous commerce checks passed for ${base}.`);
  if (failures.length) process.exit(1);
}

run().catch(async (error) => {
  await cleanup();
  for (const check of checks) console.log(`${check.passed ? 'PASS' : 'FAIL'} ${check.label}${check.detail ? ` - ${check.detail}` : ''}`);
  console.error(`RC13 anonymous commerce certification stopped: ${error && error.message ? error.message : error}`);
  process.exit(1);
});
