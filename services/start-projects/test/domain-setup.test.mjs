import assert from 'node:assert/strict';
import test from 'node:test';
import { createHostingerProvider } from '../src/managed-hosting.mjs';
import { clientPurchase, cookie, fixture } from './fixture.mjs';

const buyer = 'launch:' + '1'.repeat(32);
const otherBuyer = 'launch:' + '2'.repeat(32);

async function project(f, order) {
  const response = await f.postReceipt('purchases', order);
  assert.equal(response.status, 201);
  const { projectId } = await response.json();
  const session = cookie(await f.redeem(await f.ticket(order.entitlementId, order.accountId)));
  return { projectId, endpoint: '/projects/' + projectId, session, revision: 1 };
}

test('registered .in hosting waits for the matched payment and Hostinger, then uses the exact domain', async (t) => {
  const domain = 'owned-catalogue-fixture.in';
  const origin = 'https://' + domain;
  let occupied = true;
  let status;
  let healthy = false;
  let submissions = 0;
  const paths = [];
  const hostingProvider = createHostingerProvider({
    token: 'fixture-provider-token', orderId: 501, username: 'u100000000', adminEmail: 'operator@example.test',
    async http(address, init) {
      const url = new URL(address);
      const path = url.pathname.replace('/api/hosting/v1', '');
      paths.push(path);
      if (path === '/websites') {
        assert.equal(url.searchParams.get('domain'), domain);
        return Response.json({ data: occupied ? [{ domain }] : [{ domain: 'shop.' + domain }] });
      }
      const job = { domain, username: 'u100000000', type: 'wordpress', status };
      if (path === '/onboardings') {
        assert.equal(url.searchParams.get('domain'), domain);
        assert.equal(url.searchParams.get('order_id'), '501');
        return Response.json({ data: status ? [job] : [] });
      }
      assert.equal(path, '/orders/501/onboardings');
      assert.equal(JSON.parse(init.body).domain, domain);
      assert.equal(init.method, 'POST');
      submissions++;
      return Response.json({ ...job, status: status = 'running' }, { status: 201 });
    },
    async probe(site, address) {
      assert.equal(site, origin);
      assert.equal(new URL(address).origin, origin);
      return { body: JSON.stringify({ home: healthy ? origin : 'https://other.example', namespaces: ['wp/v2'] }) };
    },
  });
  const f = await fixture(t, { hostingProvider });
  const selection = { mode: 'register', name: domain, category: 'standard' };
  const order = clientPurchase('paid-registry', { accountId: buyer, domain: selection });
  const { projectId, endpoint, session, revision: initialRevision } = await project(f, order);
  assert.equal((await f.form(endpoint + '/site-setup', { revision: initialRevision, mode: 'services' }, { cookie: session })).status, 303);
  const revision = initialRevision + 1;
  const web = () => f.form(endpoint + '/hosting', { revision }, { cookie: session });
  const pending = await f.request(endpoint, { headers: { Cookie: session } });
  assert.match(await pending.text(), /Hosting opens when Hostinger confirms registration/);
  assert.equal((await web()).status, 409);
  assert.deepEqual(paths, []);

  const receipt = { entitlementId: order.entitlementId, purchaseId: order.purchaseId, accountId: buyer, domain };
  assert.equal((await f.postReceipt('domain-registration', receipt, await f.token(buyer))).status, 401);
  assert.equal((await f.postReceipt('domain-registration', { ...receipt, accountId: otherBuyer })).status, 404);
  assert.equal((await f.postReceipt('domain-registration', { ...receipt, purchaseId: 'wrong-payment' })).status, 404);
  assert.equal((await f.postReceipt('domain-registration', { ...receipt, domain: 'wrong.in' })).status, 409);
  for (const repeat of [1, 2]) {
    const accepted = await f.postReceipt('domain-registration', receipt);
    assert.equal(accepted.status, 200, String(repeat));
    assert.deepEqual(await accepted.json(), { projectId });
  }
  const second = clientPurchase('different-paid-order', { accountId: otherBuyer, domain: selection });
  await project(f, second);
  assert.equal((await f.postReceipt('domain-registration', { entitlementId: second.entitlementId, purchaseId: second.purchaseId,
    accountId: otherBuyer, domain })).status, 409);
  assert.equal((await web()).status, 409);
  assert.equal(submissions, 0);
  occupied = false;
  const client = await f.connect(buyer);
  const provision = () => client.callTool({ name: 'kiwe_start_provision_site', arguments: { projectId, revision } });
  const [posted, started] = await Promise.all([web(), provision()]);
  assert.equal(posted.status, 303);
  assert.equal(started.structuredContent.project.managedSite.status, 'provisioning');
  assert.equal(submissions, 1);
  status = 'completed';
  const waiting = (await provision()).structuredContent.project;
  assert.equal(waiting.managedSite.url, null);
  healthy = true;
  const ready = (await provision()).structuredContent.project;
  assert.equal(ready.managedSite.url, origin);
  assert.equal(ready.next, 'activate_kiwe');
  assert.deepEqual(ready.domainSetup, { mode: 'register', status: 'registered' });
  const html = await (await f.request(endpoint, { headers: { Cookie: session } })).text();
  assert.match(html, /WordPress is ready on your <a href="https:\/\/owned-catalogue-fixture\.in">owned-catalogue-fixture\.in/);
});

test('owned domain proof is tenant-specific, renewable and keeps the running temporary site', async (t) => {
  const temp = 'verified-fixture.hostingersite.com';
  const domain = 'client-owned.example';
  let records = [];
  let lookups = 0;
  const f = await fixture(t, {
    hostingProvider: { reserve: async () => temp, status: async () => 'completed', wordpressReady: async () => true },
    async resolveDomainTxt(name) {
      assert.equal(name, '_kiwe-site.' + domain);
      lookups++;
      return records;
    },
  });
  const selected = { mode: 'owned', name: domain };
  const order = clientPurchase('owned-domain', { accountId: buyer, domain: selected });
  const { projectId, endpoint, session, revision: initialRevision } = await project(f, order);
  assert.equal((await f.form(endpoint + '/site-setup', { revision: initialRevision, mode: 'ecommerce' }, { cookie: session })).status, 303);
  const revision = initialRevision + 1;
  let activeSession = session;
  const other = await project(f, clientPurchase('other-owned-domain', { accountId: otherBuyer, domain: selected }));
  const firstClient = await f.connect(buyer);
  const secondClient = await f.connect(otherBuyer);
  const dns = (client, id, action) => client.callTool({ name: 'kiwe_start_domain_dns',
    arguments: { projectId: id, revision: id === projectId ? revision : other.revision, action } });
  const route = endpoint + '/domain-dns';
  const form = (action, origin) => f.form(route, { revision, action }, { cookie: activeSession, origin });
  assert.equal((await dns(secondClient, projectId, 'prepare')).isError, true);
  assert.equal((await form('prepare', 'https://other.example')).status, 403);
  assert.equal(lookups, 0);
  assert.equal((await f.form(endpoint + '/hosting', { revision }, { cookie: session })).status, 303);
  assert.equal((await form('prepare')).status, 303);
  const prepared = (await dns(firstClient, projectId, 'prepare')).structuredContent.project.domainSetup;
  assert.equal(prepared.status, 'awaiting_dns');
  assert.equal(prepared.record.name, '_kiwe-site.' + domain);
  const html = await (await f.request(endpoint, { headers: { Cookie: session } })).text();
  assert.ok(html.includes(prepared.record.value));
  assert.match(html, /Keep your existing email, verification and website records/);
  const unrelated = (await dns(secondClient, other.projectId, 'prepare')).structuredContent.project.domainSetup;
  assert.notEqual(unrelated.record.value, prepared.record.value);
  records = [[unrelated.record.value]];
  assert.equal((await dns(firstClient, projectId, 'verify')).isError, true);
  f.advance(49 * 60 * 60 * 1000);
  await f.restart();
  activeSession = cookie(await f.redeem(await f.ticket(order.entitlementId, buyer)));
  const resumed = await f.connect(buyer);
  assert.equal((await dns(resumed, projectId, 'verify')).isError, true);
  const renewed = (await dns(resumed, projectId, 'prepare')).structuredContent.project.domainSetup;
  records = [[prepared.record.value]];
  assert.equal((await dns(resumed, projectId, 'verify')).isError, true);
  records = [[renewed.record.value.slice(0, 8), renewed.record.value.slice(8)]];
  assert.equal((await form('verify')).status, 303);
  const verified = (await dns(resumed, projectId, 'verify')).structuredContent.project;
  assert.deepEqual(verified.domainSetup, { mode: 'owned', status: 'ownership_verified' });
  assert.equal(verified.managedSite.url, 'https://' + temp);
  assert.equal(verified.site, null);
  assert.equal(lookups, 3);
});
