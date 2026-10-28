const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const earlySource = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/js/startup-hydration.js'), 'utf8');
const source = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/js/surface.js'), 'utf8');
function fn(name) { const a = source.indexOf('\tfunction ' + name + '('), b = source.indexOf('\n\tfunction ', a + 1); assert(a >= 0 && b > a, name); return source.slice(a, b); }
let checks = 0;
function check(ok, why) { assert(ok, why); checks++; }
const endpoint = 'https://site.test/wp-admin/admin-ajax.php?action=dsa_runtime_hydrate';
function early(options = {}) {
  const calls = [], listeners = {}, aborts = [];
  const owner = options.owner || { boot: { version: 'candidate' } };
  const window = { DSA: owner, location: { href: 'https://site.test/shop/', origin: 'https://site.test' }, fetch: (...args) => { calls.push(args); return options.fail ? Promise.reject(Error('network')) : Promise.resolve(options.response || { ok: true }); }, addEventListener: (name, listener, settings) => { listeners[name] = { listener, settings }; } };
  const context = { window, document: { currentScript: { getAttribute: () => options.endpoint === undefined ? endpoint : options.endpoint } }, URL, Date, Math, AbortController: class { constructor() { this.signal = {}; } abort() { aborts.push(true); } } };
  vm.createContext(context); vm.runInContext(earlySource, context);
  return { context, window, owner, calls, listeners, aborts };
}
function consumer(entry) {
  const calls = [], context = { window: { DSA: { startupHydration: entry } }, data: { version: 'candidate' }, hydrationConfig: { endpoint }, Date, Promise, Error, noStoreUrl: x => x + '&fresh=1', runtimeHeaders: () => ({ private: true }), fetch: (...args) => { calls.push(args); return Promise.resolve({ fresh: true }); } };
  vm.createContext(context); vm.runInContext(fn('requestRuntimeHydration'), context);
  return { context, calls };
}
async function run() {
  const first = early();
  check(first.calls.length === 1 && first.owner.startupHydration.version === 'candidate', 'Head starts exactly one private read before the main script');
  const [url, options] = first.calls[0];
  check(new URL(url).origin === 'https://site.test' && new URL(url).searchParams.has('_dsa_rt'), 'Private request uses the native same-origin endpoint and cache buster');
  check(options.method === 'GET' && options.credentials === 'same-origin' && options.cache === 'no-store' && options.headers['Cache-Control'].includes('no-store') && options.signal, 'Private session, no-store and abort boundaries remain explicit');
  vm.runInContext(earlySource, first.context);
  check(first.calls.length === 1, 'A repeated inline seed does not duplicate an outstanding read');
  const use = consumer(first.owner.startupHydration);
  check((await use.context.requestRuntimeHydration()).ok && use.calls.length === 0 && !use.context.window.DSA.startupHydration, 'Main runtime consumes and removes the one private response');
  check((await use.context.requestRuntimeHydration()).fresh && use.calls.length === 1, 'A later refresh performs a fresh native request');
  for (const invalid of ['https://other.test/private', 'javascript:alert(1)', 'file:///etc/private', '']) check(early({ endpoint: invalid }).calls.length === 0, 'No early request for an invalid or cross-origin endpoint');
  check(early({ owner: { refreshRuntime() {} } }).calls.length === 0, 'An already booted runtime remains its own request owner');
  const rejected = early({ fail: true });
  check((await rejected.owner.startupHydration.response).failed, 'An early network failure settles before the deferred consumer exists');
  await assert.rejects(consumer(rejected.owner.startupHydration).context.requestRuntimeHydration()); checks++;
  first.listeners.pagehide.listener();
  check(!first.owner.startupHydration && first.aborts.length === 1 && first.listeners.pagehide.settings.once, 'Leaving a document aborts and removes its pending private read');
  for (const change of [{ version: 'old' }, { endpoint: 'https://site.test/other' }, { startedAt: Date.now() - 31000 }, { startedAt: Date.now() + 10000 }, { response: null }]) {
    const c = consumer({ endpoint, version: 'candidate', startedAt: Date.now(), response: Promise.resolve({ response: { old: true } }), ...change });
    check((await c.context.requestRuntimeHydration()).fresh && c.calls.length === 1 && !c.context.window.DSA.startupHydration, 'Stale, mismatched or incomplete startup state is discarded');
  }
  const retry = consumer(early().owner.startupHydration);
  check((await retry.context.requestRuntimeHydration(true)).fresh && retry.calls.length === 1, 'Nonce recovery never replays the first response');
  for (const scenario of [{ confirmed: true, failed: false, loggedIn: false, reads: 0 }, { confirmed: true, failed: false, loggedIn: true, reads: 1 }, { confirmed: false, failed: false, loggedIn: false, reads: 1 }, { confirmed: true, failed: true, loggedIn: false, reads: 1 }]) {
    const reads = [], merged = [], accepted = [], intents = [];
    const c = { window: { localStorage: { getItem: () => '[{"key":"local"}]' } }, savedStorageKey: 'existing', savedItems: [], savedServerRevision: 0, runtimeIdentityConfirmed: scenario.confirmed, runtimeHydrationFailed: scenario.failed, phonekey: { user: { loggedIn: scenario.loggedIn } }, Promise, JSON, mergeSavedItems: items => merged.push(...items), acceptSavedServerItems: items => accepted.push(...items), consumePendingSavedIntent: () => intents.push(true), dsaGet: url => { reads.push(url); return Promise.resolve({ items: [{ key: 'server' }] }); } };
    vm.createContext(c); vm.runInContext(fn('initializeSavedItems'), c); await c.initializeSavedItems();
    check(reads.length === scenario.reads && intents.length === 1 && merged[0].key === 'local', 'Local Saved shelf and pending action survive every identity boundary');
    check(scenario.reads ? accepted.some(x => x.key === 'server') : accepted.length === 0, 'Only a freshly confirmed guest skips the always-empty server shelf');
  }
  console.log(JSON.stringify({ passed: true, checks, scope: 'Actual head request, one-use consumer, stale/failed/navigation boundaries and native-identity Saved decisions' }));
}
run().catch(error => { console.error(error); process.exitCode = 1; });
