import assert from 'node:assert/strict';
import path from 'node:path';
import test from 'node:test';
import { pathToFileURL } from 'node:url';
import { createKeyProvider } from '../src/key-connection.mjs';
import { clientPurchase, cookie, fixture as startFixture } from './fixture.mjs';

const keySource = process.env.KIWE_KEY_FIXTURE_SOURCE;
if (!keySource || !path.isAbsolute(keySource)) throw new Error('Set KIWE_KEY_FIXTURE_SOURCE to the qualified private Key checkout.');
const { fixture: keyFixture, open } = await import(pathToFileURL(path.join(keySource, 'test', 'start-fixture.mjs')));

test('a DNS-verified move needs a new WordPress authorization and a reviewed Key transition', async (t) => {
  const temp = 'move-source.hostingersite.com';
  const target = 'owned.qualification.invalid';
  const temporaryOrigin = 'https://' + temp;
  const finalOrigin = 'https://' + target;
  const buyer = 'launch:' + '1'.repeat(32);
  const gateway = await keyFixture(t, { managed: true });
  const policy = gateway.config.startIntegration.managed;
  const keyProvider = createKeyProvider({
    origin: gateway.config.publicBaseUrl,
    token: gateway.config.startIntegration.token,
    managedToken: policy.token,
    http: async (url, options) => {
      const reply = await gateway.http(new URL(url).pathname, options);
      return new Response(reply.text, { status: reply.status, headers: reply.headers });
    },
  });
  let credential;
  let finalCredential;
  let setupPasses = 0;
  let dnsProof;
  const browserApprovals = [];
  const f = await startFixture(t, {
    keyProvider,
    resolveDomainTxt: async (name) => {
      assert.equal(name, '_kiwe-site.' + target);
      return [[dnsProof]];
    },
    hostingProvider: {
      reserve: async () => temp, status: async () => 'completed', wordpressReady: async () => true,
      async connectKey(domain, setupCode, settings) {
        assert.ok([temp, target].includes(domain));
        if (domain === target) assert.deepEqual(settings, { enableAuth: false });
        const redeemed = await gateway.exchange(setupCode, 'https://' + domain);
        assert.equal(redeemed.status, 200);
        const connected = redeemed.json();
        assert.equal((await gateway.proof(connected, open())).status, 200);
        if (domain === temp) credential = connected;
        else {
          finalCredential = connected;
          setupPasses++;
        }
      },
    },
    wordpressLink: {
      async begin(domain, redirect, state) {
        browserApprovals.push(domain);
        const origin = 'https://' + domain;
        return { origin, issuer: origin, redirect, authorizationUrl: origin + '/authorize?state=' + state };
      },
      async verify(connection) { return { url: connection.origin, coreVersion: '8.0.0-rc.498', wordpressVersion: '7.1.5' }; },
    },
  });
  const order = clientPurchase('own-domain-cutover', { accountId: buyer, domain: { mode: 'owned', name: target } });
  const paid = await f.postReceipt('purchases', order);
  assert.equal(paid.status, 201);
  const { projectId } = await paid.json();
  const session = cookie(await f.redeem(await f.ticket(order.entitlementId, buyer)));
  const client = await f.connect(buyer);
  const call = (name, args) => client.callTool({ name: 'kiwe_start_' + name, arguments: { projectId, ...args } });
  const provision = await call('provision_site', { revision: 1 });
  assert.equal(provision.structuredContent?.project.managedSite.url, temporaryOrigin);
  const approval = await call('connect_site', { revision: 1 });
  const approved = await f.request('/projects/site-return?' + new URLSearchParams({
    iss: temporaryOrigin, state: new URL(approval.structuredContent.authorizationUrl).searchParams.get('state'),
    code: 'kiwe_mcp_code_c_' + 'a'.repeat(32) + '.' + 'Z'.repeat(43),
  }), { headers: { Cookie: session } });
  assert.equal(approved.status, 303);
  const linked = (await call('project')).structuredContent.project;
  const revision = linked.revision;
  dnsProof = (await call('domain_dns', { revision, action: 'prepare' })).structuredContent.project.domainSetup.record.value;
  const verified = (await call('domain_dns', { revision, action: 'verify' })).structuredContent.project;
  assert.equal(verified.domainSetup.status, 'ownership_verified');
  assert.equal(verified.site.url, temporaryOrigin);
  assert.equal((await call('connect_site', { revision })).isError, true);
  assert.deepEqual(browserApprovals, [temp]);

  const binding = gateway.store.startRequest(projectId);
  const managed = { projectId, workspaceId: binding.workspaceId, accountType: binding.accountType, origin: finalOrigin };
  assert.equal((await gateway.manage(managed)).status, 403);
  policy.origins.push(finalOrigin);
  assert.equal((await gateway.manage(managed)).status, 409);
  assert.equal((await gateway.proof({ ...credential, origin: finalOrigin }, open())).status, 403);
  assert.equal((await gateway.proof(credential, open())).status, 200);

  assert.equal((await call('verify_domain_key', { revision })).isError, true);
  const review = await f.postReceipt('domain-moves', { entitlementId: order.entitlementId, accountId: buyer,
    purchaseId: order.purchaseId, domain: target, sourceUrl: temporaryOrigin, recoverySha256: 'a'.repeat(64) });
  assert.equal(review.status, 200);
  assert.equal((await call('verify_domain_key', { revision })).isError, true);
  const destination = await call('verify_domain_wordpress', { revision });
  const accepted = await f.request('/projects/site-return?' + new URLSearchParams({
    iss: finalOrigin, state: new URL(destination.structuredContent.authorizationUrl).searchParams.get('state'),
    code: 'kiwe_mcp_code_c_' + 'b'.repeat(32) + '.' + 'Y'.repeat(43),
  }), { headers: { Cookie: session } });
  assert.equal(accepted.status, 303);
  const outsider = await f.connect('launch:' + '2'.repeat(32));
  assert.equal((await outsider.callTool({ name: 'kiwe_start_verify_domain_key', arguments: { projectId, revision } })).isError, true);
  const pending = await f.request('/projects/' + projectId, { headers: { Cookie: session } });
  assert.match(await pending.text(), /Verify WhatsApp connection/);

  const keyProof = await call('verify_domain_key', { revision });
  assert.equal(keyProof.isError, undefined, keyProof.content?.[0]?.text);
  assert.deepEqual(keyProof.structuredContent.project.domainMove, { status: 'key_verified', targetUrl: finalOrigin });
  assert.equal(keyProof.structuredContent.project.site.url, temporaryOrigin);
  assert.equal(keyProof.structuredContent.project.managedSite.url, temporaryOrigin);
  assert.equal(finalCredential.keyId, credential.keyId);
  assert.notEqual(finalCredential.secret, credential.secret);
  assert.equal((await gateway.proof(finalCredential, open())).status, 200);
  assert.equal((await gateway.proof(credential, open())).status, 200);
  assert.doesNotMatch(JSON.stringify(keyProof), new RegExp(finalCredential.secret));
  const browser = await f.form('/projects/' + projectId + '/verify-domain-key', { revision }, { cookie: session });
  assert.equal(browser.status, 303);
  await f.restart();
  const reconnected = await f.connect(buyer);
  const saved = await reconnected.callTool({ name: 'kiwe_start_project', arguments: { projectId } });
  assert.equal(saved.structuredContent.project.domainMove.status, 'key_verified');
  assert.deepEqual(browserApprovals, [temp, target]);
  assert.equal(setupPasses, 1);
  assert.equal(gateway.store.sitesFor(gateway.owner.id).length, 1);
  assert.equal(gateway.store.sitesFor(gateway.owner.id).every((site) => site.transactionCount === 0), true);
});
