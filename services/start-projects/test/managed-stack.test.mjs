import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { createKeyProvider } from '../src/key-connection.mjs';
import { createManagedBricksInstaller } from '../src/managed-bricks.mjs';
import { createManagedCommerceInstaller } from '../src/managed-commerce.mjs';
import { clientPurchase, cookie, fixture } from './fixture.mjs';

const site = 'managed-stack.hostingersite.com';

test('the managed commerce installer preserves active plugins and resumes missing packages', async () => {
  const domainsDirectory = path.resolve('managed-fixture');
  const original = { name: 'woo-razorpay', version: '4.8.8', status: 'active' };
  const installed = [original];
  const writes = [];
  const install = createManagedCommerceInstaller({
    domainsDirectory, resolvePath: async value => value,
    async run(wp, args) {
      assert.equal(wp, 'wp');
      assert.equal(args[0], '--path=' + path.join(domainsDirectory, site, 'public_html'));
      const [area, task, slug, version] = args.slice(3);
      if (area === 'option') return { stdout: 'https://' + site + '\n' };
      if (task === 'list') return { stdout: JSON.stringify(installed) };
      if (task === 'verify-checksums') {
        assert.deepEqual([slug, version], ['shiprocket', '--strict']);
        return { stdout: 'Verified' };
      }
      assert.equal(task, 'install');
      assert.equal(slug, 'shiprocket');
      assert.equal(version, '--version=2.0.9');
      assert.ok(!args.includes('--activate'));
      installed.push({ name: slug, version: '2.0.9', status: 'inactive' });
      writes.push(slug);
      return { stdout: 'Installed' };
    },
  });
  assert.deepEqual(await install(site), installed);
  assert.deepEqual(await install(site), installed);
  assert.deepEqual(writes, ['shiprocket']);
  await assert.rejects(install('../outside.example'), /exact managed site domain/);
});

test('managed Bricks checks the approved archive, exact version and native license', async t => {
  const directory = await mkdtemp(path.join(tmpdir(), 'kiwe-bricks-fixture-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const archive = path.join(directory, 'test.zip');
  const artifact = 'fixture bytes';
  await writeFile(archive, artifact);
  const hash = createHash('sha256').update(artifact).digest('hex');
  const writes = [];
  let theme;
  let status = 'pending';
  const settings = {
    archive, sha256: hash, version: '2.4.6',
    async resolveSite(domain) {
      assert.equal(domain, site);
      return async ([area, task], _env, loadTheme) => {
        if (area === 'eval') {
          assert.equal(loadTheme, true);
          return status;
        }
        if (task === 'list') return JSON.stringify(theme ? [theme] : []);
        writes.push(task);
        if (task === 'install') theme = { name: 'bricks', version: '2.4.6', status: 'inactive' };
        if (task === 'activate') theme.status = 'active';
      };
    },
  };
  await assert.rejects(createManagedBricksInstaller({ ...settings, sha256: '0'.repeat(64) })(site), /hash check/);
  const prepare = createManagedBricksInstaller(settings);
  assert.equal(await prepare(site), false);
  status = 'licensed';
  assert.equal(await prepare(site), true);
  assert.deepEqual(writes, ['install', 'activate']);
});

test('Start provisions the default sender and opens client pairing only with explicit choice', async t => {
  const buyer = 'launch:' + '1'.repeat(32);
  const origin = 'https://' + site;
  const keyOrigin = 'https://key.example.test';
  const managedToken = 'm'.repeat(43);
  const setupCode = 'S'.repeat(43);
  const pairUrl = keyOrigin + '/start/pair/' + 'i'.repeat(43);
  let wordpress = false;
  let paired = false;
  let lastWorkspace;
  const keyProvider = createKeyProvider({
    origin: keyOrigin, token: 't'.repeat(43), managedToken,
    async http(url, init) {
      const request = JSON.parse(init.body);
      assert.equal(request.origin, origin);
      assert.equal(request.accountType, 'client');
      assert.equal(init.headers.Authorization, 'Bearer ' + managedToken);
      lastWorkspace ??= request.workspaceId;
      assert.equal(request.workspaceId, lastWorkspace);
      if (url.endsWith('/pair')) {
        assert.equal(request.phone, '+12025550103');
        paired = true;
        return Response.json({ origin, pairingUrl: pairUrl });
      }
      assert.ok(url.endsWith('/managed-sites'));
      return Response.json({
        origin, approvalUrl: keyOrigin + '/start/connect/' + '1'.repeat(8) + '-1111-4111-8111-' + '1'.repeat(12),
        state: 'sender_ready', sender: 'account_primary', siteAuthenticated: wordpress,
        pairingPending: paired, setupCode: wordpress ? null : setupCode,
      });
    },
  });
  const f = await fixture(t, {
    hostingProvider: {
      reserve: async () => site, status: async () => 'completed', wordpressReady: async () => true,
      prepareWordPress: async (domain, retail) => assert.ok(domain === site && retail),
      connectKey: async (domain, code) => {
        assert.equal(domain, site);
        assert.equal(code, setupCode);
        wordpress = true;
      },
    },
    keyProvider,
    wordpressLink: {
      async begin(domain, redirect, state) {
        assert.equal(domain, site);
        return { origin, issuer: origin, redirect, authorizationUrl: origin + '/authorize?state=' + state };
      },
      async verify() { return { url: origin, coreVersion: '8.0.0-rc.498', wordpressVersion: '7.1.5' }; },
    },
  });
  const payment = await f.postReceipt('purchases', clientPurchase('managed-default', { accountId: buyer }));
  assert.equal(payment.status, 201);
  const { projectId } = await payment.json();
  const session = cookie(await f.redeem(await f.ticket('managed-default', buyer)));
  const mcp = await f.connect(buyer);
  const project = async () => (await mcp.callTool({ name: 'kiwe_start_project', arguments: { projectId } })).structuredContent.project;
  const target = { projectId, revision: (await project()).revision };
  const provisioned = await mcp.callTool({ name: 'kiwe_start_provision_site', arguments: target });
  assert.equal(provisioned.structuredContent.project.managedSite.status, 'ready', provisioned.content?.[0]?.text);
  assert.ok(!JSON.stringify(provisioned).includes(setupCode));
  const authorization = await mcp.callTool({ name: 'kiwe_start_connect_site', arguments: target });
  const link = new URL(authorization.structuredContent.authorizationUrl);
  const callback = await f.request('/projects/site-return?' + new URLSearchParams({
    state: link.searchParams.get('state'), iss: origin, code: 'kiwe_mcp_code_c_' + '1'.repeat(32) + '.' + 'A'.repeat(43),
  }), { headers: { Cookie: session } });
  assert.equal(callback.status, 303);
  target.revision = (await project()).revision;
  const verified = await mcp.callTool({ name: 'kiwe_start_connect_key', arguments: target });
  assert.equal(verified.structuredContent.project.key.siteAuthenticated, true);
  assert.equal(verified.structuredContent.approvalUrl, null);
  const route = '/projects/' + projectId + '/key-pair';
  const page = await f.request('/projects/' + projectId, { headers: { Cookie: session } });
  assert.match(await page.text(), /Use your own WhatsApp number/);
  const choice = { revision: target.revision, phone: '+12025550103' };
  assert.equal((await f.form(route, choice, { cookie: session })).status, 400);
  assert.equal(paired, false);
  const outsider = await f.connect('launch:' + '2'.repeat(32));
  assert.equal((await outsider.callTool({
    name: 'kiwe_start_pair_whatsapp', arguments: { ...target, phone: choice.phone, useForMessages: true },
  })).isError, true);
  const approval = await f.form(route, { ...choice, useForMessages: 'yes' }, { cookie: session });
  assert.equal(approval.status, 303);
  assert.equal(approval.headers.get('location'), pairUrl);
  assert.equal(approval.headers.get('referrer-policy'), 'no-referrer');
  assert.equal(paired, true);
});
