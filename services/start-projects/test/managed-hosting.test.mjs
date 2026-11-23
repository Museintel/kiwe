import assert from 'node:assert/strict';
import test from 'node:test';
import { createHostingerProvider } from '../src/managed-hosting.mjs';
import { cookie, fixture, purchase } from './fixture.mjs';

const temp = 'provisioning-test.hostingersite.com';
const hostAccount = 'u100000000';
const orderId = 201;

async function hostedProject(f, entitlementId, allowed = true) {
  const paid = await f.postReceipt('purchases', purchase(entitlementId, { hostingIncluded: allowed }));
  const { projectId } = await paid.json();
  const session = cookie(await f.redeem(await f.ticket(entitlementId, 'buyer-one')));
  const endpoint = '/projects/' + projectId;
  const domain = await f.form(endpoint + '/domain', {
    domain: 'kiwe-customer.example', hosting: allowed ? 'included' : 'existing', revision: '1',
  }, { cookie: session });
  assert.equal(domain.status, 303);
  return { projectId, endpoint, session };
}

test('one managed plan opens one hosting setup across web, MCP and restart', async (t) => {
  const requests = [];
  let status;
  let healthy = false;
  const provider = createHostingerProvider({
    token: 'test-only-credential', orderId, username: hostAccount, adminEmail: 'operator@example.test',
    async http(address, init) {
      const url = new URL(address);
      assert.equal(url.origin, 'https://developers.hostinger.com');
      assert.equal(init.headers.Authorization, 'Bearer test-only-credential');
      assert.equal(init.redirect, 'error');
      const path = url.pathname.replace('/api/hosting/v1', '');
      requests.push(path);
      if (path === '/domains/free-subdomains') {
        assert.equal(init.method, 'POST');
        return Response.json({ domain: temp });
      }
      if (path === '/onboardings') {
        assert.equal(url.searchParams.get('order_id'), String(orderId));
        assert.equal(url.searchParams.get('domain'), temp);
        return Response.json({ data: status ? [{ domain: temp, username: hostAccount, type: 'wordpress', status }] : [] });
      }
      if (path === '/orders/' + orderId + '/onboardings') {
        const request = JSON.parse(init.body);
        assert.equal(request.domain, temp);
        assert.equal(request.type, 'wordpress');
        assert.match(request.wordpress.admin.user, /^kiwe_owner_[a-f0-9]+$/);
        assert.equal(request.wordpress.admin.email, 'operator@example.test');
        assert.ok(request.wordpress.admin.password.length >= 30);
        status = 'running';
        return Response.json({ domain: temp, username: hostAccount, type: 'wordpress', status }, { status: 201 });
      }
      assert.fail('Unexpected Hostinger API endpoint.');
    },
    async probe(origin, url) {
      assert.equal(origin, 'https://' + temp);
      assert.equal(new URL(url).searchParams.get('_fields'), 'home,namespaces');
      return { body: JSON.stringify({ home: origin, namespaces: healthy ? ['wp/v2'] : [] }) };
    },
  });

  const f = await fixture(t, { hostingProvider: provider });
  const { projectId, endpoint, session } = await hostedProject(f, 'included');
  const step = (client) => client.callTool({
    name: 'kiwe_start_provision_site', arguments: { projectId, revision: 2 },
  });
  const outsider = await f.connect('buyer-two');
  assert.equal((await step(outsider)).isError, true);
  assert.deepEqual(requests, []);
  const client = await f.connect('buyer-one');
  const web = () => f.form(endpoint + '/hosting', { revision: '2' }, { cookie: session });
  const effects = await Promise.all([web(), step(client), step(client)]);
  assert.equal(effects[0].status, 303);
  assert.equal(effects[1].structuredContent.project.managedSite.status, 'provisioning');
  assert.match(await (await f.request(endpoint, { headers: { Cookie: session } })).text(), /Hosting setup is in progress/);
  assert.equal(requests.filter((path) => path === '/domains/free-subdomains').length, 1);
  assert.equal(requests.filter((path) => path.startsWith('/orders/')).length, 1);
  assert.equal((await f.form(endpoint + '/domain', {
    domain: 'different.example', hosting: 'included', revision: '2',
  }, { cookie: session })).status, 409);

  status = 'completed';
  await f.restart();
  const resumed = await f.connect('buyer-one');
  const pending = (await step(resumed)).structuredContent.project;
  assert.equal(pending.managedSite.status, 'provisioning');
  assert.equal(pending.managedSite.url, null);
  healthy = true;
  const ready = (await step(resumed)).structuredContent.project;
  assert.equal(ready.managedSite.status, 'ready');
  assert.equal(ready.managedSite.url, 'https://' + temp);
  assert.equal(ready.domain, 'kiwe-customer.example');
  assert.equal(ready.next, 'activate_kiwe');
  assert.match(await (await f.request(endpoint, { headers: { Cookie: session } })).text(), /WordPress is ready/);
  await web();
  assert.equal(requests.filter((path) => path.startsWith('/orders/')).length, 1);
});

test('a lost provider response is reconciled on the saved temporary domain', async (t) => {
  let submissions = 0;
  let providerStatus;
  const provider = {
    reserve: async () => temp,
    status: async (domain) => { assert.equal(domain, temp); return providerStatus; },
    start: async (domain) => {
      assert.equal(domain, temp);
      submissions++;
      providerStatus = 'running';
      throw new Error('provider connection interrupted');
    },
    wordpressReady: async () => false,
  };
  const f = await fixture(t, { hostingProvider: provider });
  const { endpoint, session } = await hostedProject(f, 'retry');
  const web = () => f.form(endpoint + '/hosting', { revision: '2' }, { cookie: session });
  assert.equal((await web()).status, 502);
  assert.equal((await web()).status, 303);
  assert.equal(submissions, 1);

  const existing = await hostedProject(f, 'existing-only', false);
  assert.equal((await f.form(existing.endpoint + '/hosting', { revision: '2' }, {
    cookie: existing.session,
  })).status, 409);
  assert.equal(submissions, 1);
});
