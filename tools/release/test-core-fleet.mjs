import assert from 'node:assert/strict';
import test from 'node:test';
import { publicVersion, rollout } from './rollout-core-fleet.mjs';

const target = '8.0.0-rc.494';
const sites = [{ domain: 'store.example', firstScroll: true }, { domain: 'publisher.example' }];

function fixture() {
  const states = new Map(sites.map((site, index) => [site.domain, {
    domain: site.domain,
    current: index === 0 ? '8.0.0-rc.493' : '8.0.0-rc.492',
    target,
    ready: true,
    settingsChange: index === 0,
    apps: index === 0 ? { store: { version: '1.1.16', active: true } } : { guest: { version: '1.1.3', active: true } },
  }]));
  const checkpoints = new Map();
  const log = [];
  async function siteTask(site, action) {
    const slot = states.get(site.domain);
    log.push([site.domain, action]);
    if (action === 'backup') checkpoints.set(site.domain, structuredClone(slot));
    if (action === 'apply') slot.current = target;
    if (action === 'settings') slot.settingsChange = false;
    if (action === 'rollback') states.set(site.domain, structuredClone(checkpoints.get(site.domain)));
    return structuredClone(states.get(site.domain));
  }
  const purge = async site => log.push([site.domain, 'purge']);
  const probe = async (site, expected) => {
    assert.equal(states.get(site.domain).current, expected);
    return { version: expected };
  };
  return { log, states, siteTask, purge, probe };
}

test('reads version evidence from ordinary visitor HTML', () => {
  assert.equal(publicVersion('<script id="dsa-boot-seed">window.DSA.boot={version:"8.0.0-rc.494",loadedAt:0}</script>'), target);
  assert.equal(publicVersion('<!-- DSA Surface 8.0.0-rc.493 -->'), '8.0.0-rc.493');
  assert.equal(publicVersion('An unrelated page mentions 8.0.0-rc.494.'), null);
});

test('backs up the whole fleet before changing Core and keeps each App selection', async () => {
  const f = fixture();
  const result = await rollout(sites, target, f.siteTask, f.purge, f.probe);
  assert.deepEqual(result.map(site => site.version), [target, target]);
  const actions = f.log.map(([domain, action]) => domain + ' ' + action);
  assert.ok(actions.indexOf('publisher.example backup') < actions.indexOf('store.example apply'));
  assert.ok(actions.includes('store.example settings'));
  assert.ok(!actions.includes('publisher.example settings'));
  assert.deepEqual(Object.keys(f.states.get('publisher.example').apps), ['guest']);
});

test('a failed preflight leaves every active package in place', async () => {
  const f = fixture();
  f.states.get('publisher.example').ready = false;
  await assert.rejects(rollout(sites, target, f.siteTask, f.purge, f.probe), /failed preflight/);
  assert.deepEqual(f.log.map(([, action]) => action), ['inspect', 'inspect']);
});

test('a stale preflight homepage is purged before the fleet switches', async () => {
  const f = fixture();
  let stale = true;
  const probe = async (site, expected) => {
    if (site.domain === 'store.example' && stale) throw Error('stale visitor cache');
    return f.probe(site, expected);
  };
  const purge = async site => {
    if (site.domain === 'store.example') stale = false;
    await f.purge(site);
  };
  await rollout(sites, target, f.siteTask, purge, probe);
  const actions = f.log.map(([, action]) => action);
  assert.ok(actions.indexOf('purge') < actions.indexOf('backup'));
});

test('a stale public home fails the release and restores both attempted sites', async () => {
  const f = fixture();
  const probe = async (site, expected) => {
    if (site.domain === 'publisher.example' && expected === target) throw Error('cached homepage still serves its old Core');
    return f.probe(site, expected);
  };
  await assert.rejects(rollout(sites, target, f.siteTask, f.purge, probe), /previous releases were verified/);
  assert.equal(f.states.get('store.example').current, '8.0.0-rc.493');
  assert.equal(f.states.get('publisher.example').current, '8.0.0-rc.492');
  assert.deepEqual(f.log.filter(([, action]) => action === 'rollback').map(([domain]) => domain),
    ['publisher.example', 'store.example']);
});
