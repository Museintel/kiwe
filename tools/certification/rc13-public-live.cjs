#!/usr/bin/env node

const base = String(process.argv[2] || process.env.KIWE_BASE_URL || '').replace(/\/+$/, '');

if (!base || !/^https?:\/\//i.test(base)) {
  console.error('Usage: node tools/certification/rc13-public-live.cjs https://example.com');
  process.exit(2);
}

const checks = [];

function record(label, passed, detail = '') {
  checks.push({ label, passed: Boolean(passed), detail: String(detail || '') });
}

async function request(path, options = {}) {
  const response = await fetch(base + path, {
    redirect: 'manual',
    cache: 'no-store',
    ...options,
    headers: {
      Accept: 'application/json',
      'Cache-Control': 'no-cache',
      ...(options.headers || {}),
    },
  });
  const text = await response.text();
  let json = null;
  try { json = JSON.parse(text); } catch (error) {}
  return { response, text, json };
}

async function run() {
  const home = await request('/', { headers: { Accept: 'text/html' } });
  const versionMatch = home.text.match(/surface\.js\?ver=([0-9.]+)/i);
  record('frontend document responds', home.response.status === 200, home.response.status);
  record('deployed Surface version is discoverable', Boolean(versionMatch), versionMatch ? versionMatch[1] : 'missing');

  const assetPath = versionMatch
    ? `/wp-content/mu-plugins/dsa/assets/js/surface.js?ver=${encodeURIComponent(versionMatch[1])}`
    : '/wp-content/mu-plugins/dsa/assets/js/surface.js';
  const asset = await request(assetPath, { headers: { Accept: 'application/javascript' } });
  record('Surface asset responds', asset.response.status === 200, asset.response.status);
  record('Surface carries nonce recovery', asset.text.includes('function refreshRestNonce()') && asset.text.includes('function runtimeHeaders('), `bytes=${asset.text.length}`);

  const hydration = await request('/wp-json/dsa/v1/runtime/hydrate');
  const hydrationCache = hydration.response.headers.get('cache-control') || '';
  record('runtime hydration responds', hydration.response.status === 200 && hydration.json && hydration.json.version, hydration.response.status);
  record('runtime hydration is private/no-store', /private/i.test(hydrationCache) && /no-store/i.test(hydrationCache), hydrationCache);
  record('runtime hydration issues a nonce', Boolean(hydration.json && hydration.json.nonce), 'nonce value redacted');

	const ajaxHydration = await request('/wp-admin/admin-ajax.php?action=dsa_runtime_hydrate');
	const ajaxHydrationCache = ajaxHydration.response.headers.get('cache-control') || '';
	record('cache-safe browser hydration responds', ajaxHydration.response.status === 200 && ajaxHydration.json && ajaxHydration.json.version, ajaxHydration.response.status);
	record('cache-safe browser hydration is private/no-store', /private/i.test(ajaxHydrationCache) && /no-store/i.test(ajaxHydrationCache), ajaxHydrationCache);

  const nonce = await request('/wp-json/dsa/v1/cart/nonce');
  record('fresh nonce endpoint responds', nonce.response.status === 200 && nonce.json && nonce.json.ok && nonce.json.nonce, nonce.response.status);

  const cart = await request('/wp-json/dsa/v1/cart');
  record('anonymous cart responds', cart.response.status === 200 && cart.json && cart.json.cart, cart.response.status);

  const search = await request('/wp-json/dsa/v1/search?q=a&scope=all&limit=2');
  const searchShape = search.json
    && Array.isArray(search.json.products)
    && Array.isArray(search.json.posts)
    && Array.isArray(search.json.authors)
    && search.json.families
    && typeof search.json.families.products === 'boolean'
    && typeof search.json.families.posts === 'boolean'
    && typeof search.json.families.authors === 'boolean'
    && typeof search.json.alphabetEnabled === 'boolean';
  record('public search responds without WP nonce', search.response.status === 200 && searchShape, `${search.response.status}:scope=${search.json && search.json.scope || ''}`);

	const searchIndex = await request('/wp-json/dsa/v1/search?q=&scope=all&limit=6');
	const enabledFamilies = searchIndex.json && searchIndex.json.families
		? Object.entries(searchIndex.json.families).filter(([, enabled]) => enabled).map(([family]) => family).join(',')
		: 'undeclared';
	record(
		'Search publishes capability state for deployment diagnosis',
		searchIndex.response.status === 200
			&& searchIndex.json
			&& searchIndex.json.families
			&& typeof searchIndex.json.alphabetEnabled === 'boolean',
		`families=${enabledFamilies};alphabet=${searchIndex.json && searchIndex.json.alphabetEnabled === true ? 'on' : 'off'}`
	);

  const profile = await request('/wp-json/dsa/v1/apex-profile');
  record('public APEX profile responds', profile.response.status === 200 && profile.json, profile.response.status);

  const crossSiteRead = await request('/wp-json/dsa/v1/cart', { headers: { Origin: 'https://example.invalid' } });
  record('cross-site cart read is rejected', [401, 403].includes(crossSiteRead.response.status) && crossSiteRead.json && crossSiteRead.json.code === 'rest_forbidden', `${crossSiteRead.response.status}:${crossSiteRead.json && crossSiteRead.json.code || ''}`);

  const unprovedMutation = await request('/wp-json/dsa/v1/cart/add', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ productId: 0, quantity: 1 }),
  });
  record('mutation without Kiwe proof is rejected', unprovedMutation.response.status === 403 && unprovedMutation.json && unprovedMutation.json.code === 'dsa_mutation_proof_missing', `${unprovedMutation.response.status}:${unprovedMutation.json && unprovedMutation.json.code || ''}`);

  const crossSiteMutation = await request('/wp-json/dsa/v1/cart/add', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Kiwe-Mutation': '1', Origin: 'https://example.invalid' },
    body: JSON.stringify({ productId: 0, quantity: 1 }),
  });
  record('cross-site proved mutation is rejected', crossSiteMutation.response.status === 403 && crossSiteMutation.json && crossSiteMutation.json.code === 'dsa_cross_site_mutation', `${crossSiteMutation.response.status}:${crossSiteMutation.json && crossSiteMutation.json.code || ''}`);

  for (const check of checks) {
    console.log(`${check.passed ? 'PASS' : 'FAIL'} ${check.label}${check.detail ? ` - ${check.detail}` : ''}`);
  }

  const failures = checks.filter((check) => !check.passed);
  console.log(`${checks.length - failures.length}/${checks.length} RC13 public live checks passed for ${base}.`);
  if (failures.length) process.exit(1);
}

run().catch((error) => {
  console.error(`RC13 live preflight failed: ${error && error.message ? error.message : error}`);
  process.exit(1);
});
