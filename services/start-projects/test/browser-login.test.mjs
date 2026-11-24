import assert from 'node:assert/strict';
import test from 'node:test';
import { browserIdentity } from './browser-identity.mjs';
import { checkoutOrigin, cookie, fixture, issuer, purchase } from './fixture.mjs';

test('a verified Start login reaches the same entitled account as MCP and replaces its old session', async (t) => {
  const identity = await browserIdentity();
  const f = await fixture(t, { browserLogin: identity.provider });
  const receipt = purchase('agency-sign-in', { accountType: 'agency', planId: 'kiwe-agency-monthly' });
  const project = f.seedLegacyPurchase(receipt);
  const before = cookie(await f.redeem(await f.ticket(receipt.entitlementId, receipt.accountId)));
  const screen = await f.request('/login/');
  assert.equal(screen.status, 200);
  assert.match(screen.headers.get('content-security-policy'), new RegExp("form-action 'self' " + issuer));
  assert.match(await screen.text(), /Continue to sign in/);
  assert.equal((await f.form('/projects/sign-in', {}, { origin: checkoutOrigin })).status, 403);
  const started = await f.form('/projects/sign-in', {}, { cookie: before });
  assert.equal(started.status, 303);
  const returned = await identity.callback(started);
  await f.restart();
  assert.equal((await f.request(returned.path)).status, 410);
  assert.equal(identity.replies.length, 0);
  const login = await f.request(returned.path, { headers: { Cookie: before + '; ' + returned.binding } });
  assert.equal(login.status, 303, await login.clone().text());
  assert.equal(login.headers.get('referrer-policy'), 'no-referrer');
  assert.equal(login.headers.get('location'), '/projects');
  const cookies = login.headers.getSetCookie();
  const session = cookies.find(value => value.startsWith('__Host-kiwe-start=')).split(';')[0];
  assert.match(cookies[0], /Max-Age=0/);
  assert.equal((await f.request('/projects', { headers: { Cookie: before } })).status, 401);
  assert.match(await (await f.request('/projects', { headers: { Cookie: session } })).text(), new RegExp(project.projectId));
  const app = await f.connect('buyer-one');
  const result = await app.callTool({ name: 'kiwe_start_projects' });
  assert.deepEqual(result.structuredContent.projects.map(({ id }) => id), [project.projectId]);
  assert.equal((await f.request(returned.path, { headers: { Cookie: returned.binding } })).status, 410);
  assert.equal(identity.replies.length, 1);
  assert.equal((await f.form('/projects/sign-out', {}, { cookie: session })).headers.get('location'), '/login/');
  assert.equal((await f.request('/projects', { headers: { Cookie: session } })).status, 401);
});

test('expired login transactions and assertions for another session or client do not open a dashboard', async (t) => {
  const identity = await browserIdentity();
  const f = await fixture(t, { browserLogin: identity.provider });
  for (const assertion of [{ nonce: 'different-session' }, { audience: 'other-client' }]) {
    const initiated = await identity.callback(await f.form('/projects/sign-in', {}), assertion);
    const returned = await f.request(initiated.path, { headers: { Cookie: initiated.binding } });
    assert.equal(returned.status, 401);
    assert.match(await returned.text(), /Sign-in could not be verified/);
    assert.ok(returned.headers.getSetCookie().every(value => !value.startsWith('__Host-kiwe-start=')));
  }
  const expired = await identity.callback(await f.form('/projects/sign-in', {}));
  f.advance(10 * 60 * 1000);
  assert.equal((await f.request(expired.path, { headers: { Cookie: expired.binding } })).status, 410);
});
