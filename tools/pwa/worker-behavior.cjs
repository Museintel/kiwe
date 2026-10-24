const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const before = process.argv[2];
function render(mode = 'on', scope = '/shop-a/', format = 'worker') {
  const result = spawnSync('php', [path.join(__dirname, 'render-worker.php'), format, mode, scope], {encoding:'utf8'});
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stderr, '');
  return result.stdout;
}
const rendered = render();
const config = JSON.parse(rendered.match(/const KIWE_PWA = (.*);/)[1]);
function body(source) {
  const start = source.indexOf('const KIWE_PWA = ');
  const end = source.indexOf('\n\t\t<?php', start);
  return source.slice(start, end < 0 ? undefined : end).replace(/^const KIWE_PWA = .*;$/m, 'const KIWE_PWA = fixtureConfig;');
}
const script = body(before ? fs.readFileSync(before, 'utf8') : rendered);
function storage() {
  const buckets = new Map();
  const key = value => typeof value === 'string' ? value : value.url;
  return {
    buckets,
    async keys() { return [...buckets.keys()]; },
    async delete(name) { return buckets.delete(name); },
    async open(name) {
      if (!buckets.has(name)) buckets.set(name, new Map());
      const entries = buckets.get(name);
      return {
        async match(value) { return entries.get(key(value))?.clone(); },
        async put(value, response) { entries.set(key(value), response.clone()); },
        async keys() { return [...entries.keys()].map(url => ({url})); },
        async delete(value) { return entries.delete(key(value)); },
        async add() { throw Error('Unexpected eager asset download'); },
      };
    },
    async match(value) { for (const name of buckets.keys()) { const match = await (await this.open(name)).match(value); if (match) return match; } },
  };
}
function worker(overrides = {}, shared = storage()) {
  const events = {}, calls = [], state = {online:true, unregistered:false, claimed:false, skipped:false};
  const cfg = {...config, ...overrides};
  const context = vm.createContext({fixtureConfig:cfg, URL, Headers, Request, Response, Promise, Date, console,
    caches:shared,
    fetch: async (request, options) => {
      calls.push({url:typeof request === 'string' ? request : request.url, options});
      if (!state.online) throw Error('Offline');
      return state.response ? state.response.clone() : new Response('network', {headers:{'Content-Type':'application/javascript'}});
    },
    self:{location:{origin:new URL(cfg.home).origin}, navigator:{connection:{}},
      addEventListener(name, fn) { events[name] = fn; },
      skipWaiting() { state.skipped = true; return Promise.resolve(); },
      clients:{claim() { state.claimed = true; return Promise.resolve(); }, async matchAll() { return state.clients || []; }},
      registration:{async unregister() { state.unregistered = true; return true; }, async showNotification() {}},
    },
  });
  vm.runInContext(script, context);
  async function dispatch(name, extras = {}) {
    let response, handled = false;
    const pending = [];
    events[name]({waitUntil(value) { pending.push(value); }, respondWith(value) { handled = true; response = Promise.resolve(value).then(value => ({value}), error => ({error})); }, ...extras});
    const result = response ? await response : {};
    await Promise.all(pending);
    return {handled, ...result};
  }
  function request(url, mode = 'cors', destination = '', method = 'GET') { return dispatch('fetch', {request:{url, mode, destination, method, headers:new Headers()}}); }
  return {context, state, calls, config:cfg, caches:shared, dispatch, request};
}
const checks = [];
async function check(name, fn) {
  try { await fn(); checks.push({name, passed:true}); console.log('PASS ' + name); }
  catch (error) { checks.push({name, passed:false}); console.error('FAIL ' + name + ': ' + error.message); }
}
(async () => {
  await check('Active and disabled PHP endpoints explicitly disable WordPress/LiteSpeed page caching', () => {
    for (const mode of ['on','off','disabled']) assert.deepEqual(JSON.parse(render(mode,'/shop-a/','boundary')), {pageCacheDisabled:true,litespeedDisabled:true});
  });
  await check('PHP emits distinct site cache namespaces and matching public configuration', () => {
    const other = JSON.parse(render('on','/shop-b/').match(/const KIWE_PWA = (.*);/)[1]);
    assert.notEqual(config.cachePrefix, other.cachePrefix);
    assert.equal(JSON.parse(render('on','/shop-a/','config')).cachePrefix, config.cachePrefix);
  });
  for (const endpoint of ['wp-json/dsa/v1/account', '?rest_route=/dsa/v1/phonekey', 'wp-json/wp/v2/users/me']) {
    await check('Offline API never replays an existing cache entry: ' + endpoint, async () => {
      const w = worker(); const url = config.home + endpoint;
      await (await w.caches.open('unrelated-cache')).put(url, new Response('old private result'));
      w.state.online = false;
      const result = await w.request(url);
      assert(result.handled && result.error); assert.equal(w.calls[0].options.cache, 'no-store');
    });
  }
  await check('Install downloads no unused modules', async () => {
    const w = worker(); await w.dispatch('install'); assert(w.state.skipped); assert.equal((await w.caches.keys()).length, 0); assert.equal(w.calls.length, 0);
  });
  await check('Only the current listed static asset is stored and available offline', async () => {
    const w = worker(); const url = config.assets[1];
    assert.equal(await (await w.request(url)).value.text(), 'network');
    assert(await (await w.caches.open(config.cache)).match(url));
    w.state.online = false; assert.equal(await (await w.request(url)).value.text(), 'network');
    assert((await w.request(url.replace('ver=', 'ver=old-'))).error);
    assert((await w.request(url + '&private=1')).error);
  });
  for (const headers of [{'Content-Type':'text/html'}, {'Content-Type':'application/javascript','Cache-Control':'private'}, {'Content-Type':'application/javascript','Cache-Control':'no-store'}]) {
    await check('Static cache rejects response policy ' + JSON.stringify(headers), async () => {
      const w = worker(); w.state.response = new Response('not reusable', {headers}); await w.request(config.assets[1]);
      assert.equal(await (await w.caches.open(config.cache)).match(config.assets[1]), undefined);
    });
  }
  await check('Failed static requests cannot read another cache bucket', async () => {
    const w = worker(); await (await w.caches.open('other')).put(config.assets[1], new Response('other'));
    w.state.online = false; assert.equal((await w.request(config.assets[1])).value, undefined);
  });
  await check('Offline article images replay only the editorial media cache', async () => {
    const w = worker(); const url = config.home + 'wp-content/uploads/synthetic.png';
    await (await w.caches.open(config.mediaCache)).put(url, new Response('image', {headers:{'Content-Type':'image/png'}}));
    w.state.online = false; assert.equal(await (await w.request(url,'no-cors','image')).value.text(), 'image');
    assert.equal((await w.request(url + '?private=1','no-cors','image')).handled, false);
  });
  await check('Normal online images are never added to editorial media', async () => {
    const w = worker(); const url = config.home + 'photo.png'; await w.request(url,'no-cors','image');
    assert.equal(await (await w.caches.open(config.mediaCache)).match(url), undefined);
  });
  await check('Offline media policy rejects private images and size excess', async () => {
    const w = worker(); w.state.response = new Response('private', {headers:{'Content-Type':'image/png','Cache-Control':'private'}});
    await w.context.cacheEditorialMedia({url:config.home + 'private.png'});
    assert.equal((await (await w.caches.open(config.mediaCache)).keys()).length, 0);
    w.state.response = new Response(new Uint8Array(3145729), {headers:{'Content-Type':'image/png'}});
    await w.context.cacheEditorialMedia({url:config.home + 'large.png'});
    assert.equal((await (await w.caches.open(config.mediaCache)).keys()).length, 0);
  });
  await check('Public editorial contract creates a bounded offline document and image', async () => {
    const w = worker(); const value = config.home + 'story/';
    w.state.response = new Response(JSON.stringify({offlineReady:true, content:{html:'<p>Public story</p>'}, document:{title:'Test story'}, media:[]}), {headers:{'Content-Type':'application/json', 'X-Kiwe-Offline-Policy':'public-editorial-v1','Cache-Control':'public, max-age=900'}});
    await w.context.cacheEditorial(value, {headers:new Headers()});
    const calls = w.calls.length; await w.context.cacheEditorial(value, {headers:new Headers()}); assert.equal(w.calls.length, calls);
    w.state.online = false; const response = (await w.request(value,'navigate')).value;
    assert.equal(response.status, 200); assert((await response.text()).includes('Public story')); assert(response.headers.get('Content-Security-Policy').includes("default-src 'none'"));
  });
  for (const route of ['wp-admin/', 'wp-login.php', 'my-account/', 'cart/', 'checkout/', '?kiwe-auth=1', 'story/?preview=true']) {
    await check('Private or query navigation has only a reconnect screen: ' + route, async () => {
      const w = worker(); w.state.online = false; const result = await w.request(config.home + route,'navigate');
      assert.equal(result.value.status, 503); const html = await result.value.text(); assert(html.includes('Try again')); assert(!html.includes('Public story'));
      assert.equal(result.value.headers.get('Cache-Control'), 'no-store');
    });
  }
  await check('Network HTTP errors are preserved, not converted into cached success', async () => {
    const w = worker(); w.state.response = new Response('upstream failed',{status:503}); const result = await w.request(config.home+'story/','navigate');
    assert.equal(result.value.status,503); assert.equal(await result.value.text(),'upstream failed'); assert.equal(w.calls.length,1);
  });
  await check('Worker ignores writes and cross-origin requests', async () => {
    const w = worker(); assert.equal((await w.request(config.home+'wp-json/dsa/v1/account','cors','','POST')).handled,false);
    assert.equal((await w.request('https://another.test/photo.png','no-cors','image')).handled,false); assert.equal(w.calls.length,0);
  });
  await check('Upgrade removes only obsolete cache generations for this site', async () => {
    const w = worker(); const names = [config.cache, config.pushMetaCache, config.cachePrefix+'assets-old', 'kiwe-pwa-v2-other-assets-old', 'unrelated'];
    for (const name of names) await w.caches.open(name);
    await w.dispatch('activate'); assert(w.state.claimed); assert(!w.state.unregistered);
    assert.deepEqual(await w.caches.keys(), names.filter(name => name !== config.cachePrefix+'assets-old'));
  });
  await check('Disabling offline editorial retires its documents and media only', async () => {
    const w = worker({offlineEditorialEnabled:false});
    for (const name of [config.cache,config.editorialCache,config.mediaCache,config.pushMetaCache]) await w.caches.open(name);
    await w.dispatch('activate'); assert.deepEqual(await w.caches.keys(), [config.cache,config.pushMetaCache]);
  });
  await check('Disabled endpoint emits a working retirement script', async () => {
    const disabled = JSON.parse(render('disabled').match(/const KIWE_PWA = (.*);/)[1]); assert.equal(disabled.enabled,false);
    const off = JSON.parse(render('off').match(/const KIWE_PWA = (.*);/)[1]); assert.equal(off.enabled,true,'An obsolete worker off flag cannot disable core App support');
    const w = worker(disabled); for (const name of [config.cache,config.pushMetaCache,'unrelated']) await w.caches.open(name);
    await w.dispatch('install'); await w.dispatch('activate'); assert(w.state.claimed && w.state.unregistered);
    assert.deepEqual(await w.caches.keys(),['unrelated']); assert.equal((await w.request(config.home,'navigate')).handled,false);
  });
  await check('Renewal messages reject a different site path and preserve the scoped key', async () => {
    const w = worker(); const data = {type:'KIWE_PUSH_RENEWAL', endpoint:'https://push.example.test/synthetic', renewalToken:'synthetic-only'};
    await w.dispatch('message', {data, source:{url:'https://example.test/shop-b/'}}); assert.equal((await w.caches.keys()).length,0);
    await w.dispatch('message', {data, source:{url:config.home}}); assert.equal((await w.context.readPushRenewal()).token,'synthetic-only');
    await w.dispatch('message', {data:{type:'KIWE_PUSH_RENEWAL_CLEAR'}, source:{url:config.home}}); assert.equal(await w.context.readPushRenewal(),null);
  });
  await check('Client retirement does not unregister sibling or unrelated workers', async () => {
    const source = fs.readFileSync(before ? before.replace('before-worker.php','before-surface.js') : path.join(root,'wp-content/mu-plugins/dsa/assets/js/surface.js'),'utf8');
    const fn = source.slice(source.indexOf('\tfunction retireKiweServiceWorker() {'),source.indexOf('\tfunction publishAppAdoptionState() {'));
    const calls = [], make = (scope, scriptURL) => ({scope,active:{scriptURL}, async update(){calls.push('update '+scope);}, async unregister(){calls.push('unregister '+scope);}});
    const shared = storage(); await shared.open(config.cache); await shared.open('unrelated');
    const context = vm.createContext({URL,pwaConfig:{scope:'/shop-a/',cachePrefix:config.cachePrefix},window:{location:{href:config.home},caches:shared,navigator:{serviceWorker:{async getRegistrations(){return [make(config.home, config.home+'?dsa_service_worker=1'),make('https://example.test/shop-b/','https://example.test/shop-b/?dsa_service_worker=1'),make('https://example.test/','https://example.test/custom.js')];}}}}});
    vm.runInContext(fn+'\nretireKiweServiceWorker();',context); await new Promise(resolve=>setImmediate(resolve));
    assert.deepEqual(calls,['update '+config.home]); assert.deepEqual(await shared.keys(),['unrelated']);
  });
  const failed = checks.filter(check=>!check.passed).length;
  console.log(`${checks.length-failed}/${checks.length} PWA behavior checks passed.`); if (failed) process.exitCode = 1;
})().catch(error=>{console.error(error);process.exitCode=1;});
