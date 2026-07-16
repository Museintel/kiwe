#!/usr/bin/env node

const base = String(process.argv[2] || process.env.KIWE_BASE_URL || '').replace(/\/+$/, '');
if (!base || !/^https?:\/\//i.test(base)) {
  console.error('Usage: node tools/certification/rc14-public-live.cjs https://example.com');
  process.exit(2);
}

const checks = [];
const record = (label, passed, detail = '') => checks.push({ label, passed: Boolean(passed), detail: String(detail || '') });

async function request(path, accept) {
  const response = await fetch(base + path, {
    redirect: 'manual',
    cache: 'no-store',
    headers: { Accept: accept || '*/*', 'Cache-Control': 'no-cache, no-store' },
  });
  return { response, text: await response.text() };
}

async function run() {
  const home = await request('/', 'text/html');
  const version = (home.text.match(/surface\.js\?ver=([0-9.]+)/i) || [])[1] || '';
  const canonical = home.text.match(/<link[^>]+rel=["']canonical["'][^>]*>/gi) || [];
  record('public document is HTML', home.response.status === 200 && /text\/html/i.test(home.response.headers.get('content-type') || ''), home.response.status);
  record('deployed Kiwe version is discoverable', Boolean(version), version || 'missing');
  record('Surface copy is protected from snippets', /data-dsa-session-home[^>]*data-nosnippet/i.test(home.text) && /data-dsa-surface[^>]*data-nosnippet/i.test(home.text), 'home+surface');
  record('document has at most one canonical declaration', canonical.length <= 1, `count=${canonical.length}`);
  record('production frontend diagnostics are disabled', !/"debug"\s*:\s*\{\s*"enabled"\s*:\s*true/i.test(home.text) && !/"console"\s*:\s*true/i.test(home.text), 'Kiwe > Developer');

  const robots = await request('/robots.txt', 'text/plain');
  record('robots response remains outside the Surface shell', robots.response.status === 200 && !/dsa-surface|dsa-initial-preloader/i.test(robots.text), robots.response.status);

  const feed = await request('/feed/', 'application/rss+xml');
  record('feed response remains outside the Surface shell', feed.response.status === 200 && !/dsa-surface|dsa-initial-preloader/i.test(feed.text), feed.response.status);

  const worker = await request('/?dsa_service_worker=1', 'application/javascript');
  record('service worker is JavaScript', worker.response.status === 200 && /javascript/i.test(worker.response.headers.get('content-type') || ''), worker.response.headers.get('content-type') || '');
  record('service worker is allowed at root scope', (worker.response.headers.get('service-worker-allowed') || '') === '/', worker.response.headers.get('service-worker-allowed') || 'missing');
  record('service worker carries the deployed version', Boolean(version) && worker.text.includes(version), `bytes=${worker.text.length}`);

  const hydrate = await request('/wp-json/dsa/v1/runtime/hydrate', 'application/json');
  const hydrateCache = hydrate.response.headers.get('cache-control') || '';
  record('personalized hydration is private/no-store', hydrate.response.status === 200 && /private/i.test(hydrateCache) && /no-store/i.test(hydrateCache), hydrateCache);

  const asset = await request(`/wp-content/mu-plugins/dsa/assets/js/surface.js${version ? `?ver=${version}` : ''}`, 'application/javascript');
  record('versioned Surface asset is JavaScript', asset.response.status === 200 && /javascript/i.test(asset.response.headers.get('content-type') || ''), asset.response.headers.get('content-type') || '');

  for (const check of checks) console.log(`${check.passed ? 'PASS' : 'FAIL'} ${check.label}${check.detail ? ` - ${check.detail}` : ''}`);
  const failures = checks.filter((check) => !check.passed);
  console.log(`${checks.length - failures.length}/${checks.length} RC14 public checks passed for ${base}.`);
  if (failures.length) process.exit(1);
}

run().catch((error) => {
  console.error(`RC14 public certification stopped: ${error && error.message ? error.message : error}`);
  process.exit(1);
});
