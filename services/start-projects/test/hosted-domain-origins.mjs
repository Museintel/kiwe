import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
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
      async connectKey(domain, setupCode) {
        assert.equal(domain, temp);
        const redeemed = await gateway.exchange(setupCode, temporaryOrigin);
        assert.equal(redeemed.status, 200);
        credential = redeemed.json();
        assert.equal((await gateway.proof(credential, open())).status, 200);
      },
    },
    wordpressLink: {
      async begin(domain, redirect, state) {
        browserApprovals.push(domain);
        return { origin: temporaryOrigin, issuer: temporaryOrigin, redirect,
          authorizationUrl: temporaryOrigin + '/authorize?state=' + state };
      },
      async verify() { return { url: temporaryOrigin, coreVersion: '8.0.0-rc.498', wordpressVersion: '7.1.5' }; },
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

  // A separate, explicitly approved Key test enrollment can prove the final origin with fresh credentials.
  const finalSetup = await gateway.manage({ ...managed, projectId: randomUUID() });
  assert.equal(finalSetup.status, 200);
  const finalCredential = await gateway.exchange(finalSetup.json().setupCode, finalOrigin);
  assert.equal(finalCredential.status, 200);
  assert.notEqual(finalCredential.json().keyId, credential.keyId);
  assert.equal((await gateway.proof(finalCredential.json(), open())).status, 200);
  assert.equal(gateway.store.sitesFor(gateway.owner.id).every((site) => site.transactionCount === 0), true);
});
