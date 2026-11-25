import assert from 'node:assert/strict';
import test from 'node:test';
import { clientPurchase, cookie, fixture } from './fixture.mjs';

test('one reviewed domain move can verify WordPress without switching the managed project', async (t) => {
  const buyer = 'launch:' + '1'.repeat(32);
  const stranger = 'launch:' + '2'.repeat(32);
  const temp = 'move-source.hostingersite.com';
  const target = 'moved-shop.example';
  const sourceUrl = 'https://' + temp;
  const targetUrl = 'https://' + target;
  let record;
  let wrongProof = false;
  let approvals = 0;
  const f = await fixture(t, {
    hostingProvider: { reserve: async () => temp, status: async () => 'completed', wordpressReady: async () => true },
    resolveDomainTxt: async (name) => {
      assert.equal(name, '_kiwe-site.' + target);
      return [[record]];
    },
    wordpressLink: {
      async begin(domain, redirect, state) {
        assert.ok([target, temp].includes(domain));
        approvals++;
        const origin = 'https://' + domain;
        return { origin, issuer: origin, redirect, authorizationUrl: origin + '/authorize?state=' + state };
      },
      async verify(connection) {
        return { url: wrongProof ? sourceUrl : connection.origin, coreVersion: '8.0.0-rc.498', wordpressVersion: '7.1.5' };
      },
    },
  });
  const paid = clientPurchase('approved-move', { accountId: buyer, domain: { mode: 'owned', name: target } });
  const response = await f.postReceipt('purchases', paid);
  assert.equal(response.status, 201);
  const { projectId } = await response.json();
  const endpoint = '/projects/' + projectId;
  const session = cookie(await f.redeem(await f.ticket(paid.entitlementId, buyer)));
  const client = await f.connect(buyer);
  const denied = await f.connect(stranger);
  const call = (name, args = {}, actor = client) => actor.callTool({ name: 'kiwe_start_' + name, arguments: { projectId, ...args } });
  const read = async (actor = client) => (await call('project', {}, actor)).structuredContent?.project;
  const callback = (url, issuer = url.origin) => f.request('/projects/site-return?' + new URLSearchParams({
    state: url.searchParams.get('state'), iss: issuer, code: 'kiwe_mcp_code_c_' + '1'.repeat(32) + '.' + 'A'.repeat(43),
  }), { headers: { Cookie: session } });
  const review = { entitlementId: paid.entitlementId, accountId: buyer, purchaseId: paid.purchaseId,
    domain: target, sourceUrl, recoverySha256: 'a'.repeat(64) };
  const submit = (body = review, authority) => f.postReceipt('domain-moves', body, authority);
  assert.equal((await submit()).status, 409);
  assert.equal((await submit(review, await f.token(buyer))).status, 401);
  assert.equal((await submit({ ...review, accountId: stranger })).status, 404);
  assert.equal((await submit({ ...review, purchaseId: 'another-payment' })).status, 404);
  assert.equal((await call('provision_site', { revision: 1 })).structuredContent.project.managedSite.url, sourceUrl);
  const connect = await call('connect_site', { revision: 1 });
  assert.equal((await callback(new URL(connect.structuredContent.authorizationUrl))).status, 303);
  const original = await read();
  assert.equal(original.site.url, sourceUrl);
  const { revision } = original;
  assert.equal((await call('verify_domain_wordpress', { revision })).isError, true);
  assert.equal((await submit()).status, 409);
  record = (await call('domain_dns', { revision, action: 'prepare' })).structuredContent.project.domainSetup.record.value;
  assert.equal((await call('domain_dns', { revision, action: 'verify' })).structuredContent.project.domainSetup.status, 'ownership_verified');
  assert.equal((await submit({ ...review, sourceUrl: 'https://different.hostingersite.com' })).status, 409);
  assert.equal((await submit({ ...review, domain: 'unrelated.example' })).status, 409);
  const accepted = await submit();
  assert.deepEqual(await accepted.json(), { projectId, domainMove: { status: 'awaiting_wordpress', targetUrl } });
  assert.equal((await submit()).status, 200);
  assert.equal((await submit({ ...review, recoverySha256: 'b'.repeat(64) })).status, 409);
  assert.equal((await call('verify_domain_wordpress', { revision }, denied)).isError, true);
  assert.equal((await call('verify_domain_wordpress', { revision: revision + 1 })).isError, true);
  const browserForm = () => f.form(endpoint + '/connect-domain', { revision }, { cookie: session });
  const page = await f.request(endpoint, { headers: { Cookie: session } });
  assert.match(await page.text(), /Verify WordPress at your domain/);
  const [web, mcp] = await Promise.all([browserForm(), call('verify_domain_wordpress', { revision })]);
  assert.equal(web.status, 200);
  const move = new URL(mcp.structuredContent.authorizationUrl);
  assert.equal(move.origin, targetUrl);
  assert.match(await web.text(), new RegExp(move.searchParams.get('state')));
  assert.equal(approvals, 2);
  assert.equal((await callback(move, sourceUrl)).status, 410);
  wrongProof = true;
  assert.equal((await callback(move)).status, 409);
  assert.deepEqual((await read()).domainMove, { status: 'awaiting_wordpress', targetUrl });
  wrongProof = false;
  const newLink = new URL((await call('verify_domain_wordpress', { revision })).structuredContent.authorizationUrl);
  assert.equal((await callback(newLink)).status, 303);
  assert.equal((await callback(newLink)).status, 410);
  await f.restart();
  const verified = await read(await f.connect(buyer));
  assert.deepEqual(verified.domainMove, { status: 'wordpress_verified', targetUrl });
  assert.deepEqual(verified.site, original.site);
  assert.deepEqual(verified.managedSite, original.managedSite);
  assert.equal(verified.revision, revision);
  assert.doesNotMatch(JSON.stringify(verified), /aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/);
});
