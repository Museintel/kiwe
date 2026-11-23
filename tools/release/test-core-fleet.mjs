import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { publicVersion, rollout, validateTargets } from './rollout-core-fleet.mjs';

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

test('production and staging destinations cannot be mixed', () => {
  const hosts = ['ascendants.in', 'dharwadpedha.com', 'evergreenbasin.com', 'nationalchikki.com'];
  const approved = hosts.map((domain, index) => ({ domain, sshAlias: 'target-' + index,
    wordpressRoot: '/home/fixtures/' + domain, hostingerUsername: 'u1234', ...(index ? { firstScroll: true, wpUser: 'operator' } : {}) }));
  assert.doesNotThrow(() => validateTargets(approved, 'production'));
  assert.throws(() => validateTargets(approved, 'staging'), /cannot contain production/);
  assert.throws(() => validateTargets(approved.slice(1), 'production'), /four approved domains/);
  assert.throws(() => validateTargets([{ ...approved[0], firstScroll: true, wpUser: 'operator' }, ...approved.slice(1)], 'production'), /Keep Ascendants/);
  const staging = approved.map(site => ({ ...site, domain: 'lab.' + site.domain }));
  assert.doesNotThrow(() => validateTargets(staging, 'staging'));
});

test('file recovery runs without WordPress and excludes unrelated files and data', async t => {
  const root = await mkdtemp(path.join(tmpdir(), 'kiwe-fleet-rehearsal-'));
  t.after(() => rm(root, { recursive: true, force: true }));
  const wordpress = path.join(root, 'wordpress');
  const mu = path.join(wordpress, 'wp-content', 'mu-plugins');
  const privateRoot = path.join(root, 'kiwe-private-releases', 'site-1234567890abcdef');
  const work = path.join(privateRoot, 'core-fleet-10000000-0000-4000-8000-000000000000');
  const backup = path.join(privateRoot, 'kiwe-backup-20000000-0000-4000-8000-000000000000');
  const script = fileURLToPath(new URL('./core-fleet-recovery.php', import.meta.url));
  const previous = '8.0.0-rc.493';
  const buildCore = async (directory, ver) => {
    await mkdir(path.join(directory, 'dsa', 'assets'), { recursive: true });
    await writeFile(path.join(directory, 'dsa', 'dsa.php'), `<?php define('DSA_VERSION','${ver}');`);
    await writeFile(path.join(directory, 'dsa', 'assets', 'dock.js'), 'asset ' + ver);
    await writeFile(path.join(directory, 'dsa.php'), 'loader ' + ver);
    await writeFile(path.join(directory, 'kiwe-incident-guard.php'), 'guard ' + ver);
  };
  const php = (args) => execFileSync(process.env.KIWE_PHP || 'php', args, { encoding: 'utf8' }).trim();
  await buildCore(backup, previous);
  await buildCore(mu, '8.0.0-rc.495');
  await mkdir(work);
  await writeFile(path.join(mu, 'plugin-unrelated.php'), 'unrelated');
  await writeFile(path.join(wordpress, 'latest-orders.fixture'), 'new order since backup');
  const fingerprint = php(['-r', 'require $argv[1]; echo kiwe_fleet_fingerprint($argv[2]);', script, backup]);
  await writeFile(path.join(work, 'checkpoint.json'), JSON.stringify({ backup, current: previous, startedTarget: target,
    fingerprint, wordpressRoot: wordpress, muRoot: mu }));
  assert.throws(() => php([script, work, wordpress]), error =>
    error.status === 1 && /no longer belong/.test(JSON.parse(error.stdout).error));
  assert.match(await readFile(path.join(mu, 'dsa', 'dsa.php'), 'utf8'), /rc\.495/);
  await buildCore(mu, target);
  await writeFile(path.join(mu, 'dsa.php'), '<?php invalid PHP !');
  assert.deepEqual(JSON.parse(php([script, work, wordpress])), { filesRestored: true });
  assert.deepEqual(JSON.parse(php([script, work, wordpress])), { filesRestored: false });
  assert.equal(await readFile(path.join(mu, 'dsa', 'assets', 'dock.js'), 'utf8'), 'asset ' + previous);
  assert.equal(await readFile(path.join(mu, 'plugin-unrelated.php'), 'utf8'), 'unrelated');
  assert.equal(await readFile(path.join(wordpress, 'latest-orders.fixture'), 'utf8'), 'new order since backup');
});
