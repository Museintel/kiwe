import assert from 'node:assert/strict';
import { createHash, randomBytes, randomUUID } from 'node:crypto';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { createKeyPilotLogin } from '../src/key-pilot-login.mjs';
import { createPublicGuide } from '../src/public-guide.mjs';
import { fixture, startOrigin, checkoutOrigin, purchase } from './fixture.mjs';

test('a verified Key owner opens the pilot agency and delegates a short-lived MCP connection', async (t) => {
  const origin = 'https://key.example.test';
  const accountId = 'key:' + randomUUID();
  const token = randomBytes(32).toString('base64url');
  const code = randomBytes(32).toString('base64url');
  const approval = origin + '/start/sign-in/' + randomUUID();
  let details;
  let replyAccount = 'key:' + randomUUID();
  const browserLogin = createKeyPilotLogin({
    origin, accountId, token,
    async http(address, request) {
      assert.equal(request.headers.Authorization, 'Bearer ' + token);
      assert.equal(request.redirect, 'error');
      if (address === origin + '/integrations/start/sign-ins') {
        details = JSON.parse(request.body);
        return Response.json({ approvalUrl: approval });
      }
      assert.equal(address, origin + '/integrations/start/sign-ins/redeem');
      const proof = JSON.parse(request.body);
      assert.equal(proof.code, code);
      assert.equal(proof.state, details.state);
      assert.equal(createHash('sha256').update(proof.verifier).digest('base64url'), details.challenge);
      return Response.json({ accountId: replyAccount });
    },
  });
  const f = await fixture(t, { browserLogin });
  assert.equal((await f.postReceipt('purchases', purchase('pilot-cannot-purchase'))).status, 404);
  assert.equal((await f.request('/.well-known/oauth-protected-resource/projects/mcp')).status, 404);
  assert.match(await (await f.request('/login/')).text(), /Continue with Key/);
  assert.equal((await f.request('/projects')).status, 401);
  assert.equal((await f.form('/projects/sign-in', {}, { origin: checkoutOrigin })).status, 403);
  async function attempt() {
    const result = await f.form('/projects/sign-in', {});
    assert.equal(result.headers.get('location'), approval);
    const callback = '/projects/login/return?' + new URLSearchParams({ state: details.state, code, iss: origin });
    const binding = result.headers.getSetCookie()[0].split(';')[0];
    return { callback, binding };
  }
  const first = await attempt();
  assert.equal((await f.request(first.callback)).status, 410);
  assert.equal((await f.request(first.callback, { headers: { Cookie: first.binding } })).status, 401);
  replyAccount = accountId;
  const approved = await attempt();
  const returned = await f.request(approved.callback, { headers: { Cookie: approved.binding } });
  assert.equal(returned.status, 303);
  assert.equal(returned.headers.get('location'), '/projects');
  const session = returned.headers.getSetCookie().find(value => value.startsWith('__Host-kiwe-start=')).split(';')[0];
  assert.equal((await f.request(approved.callback, { headers: { Cookie: approved.binding } })).status, 410);
  const dashboard = await f.request('/projects', { headers: { Cookie: session } });
  assert.match(await dashboard.text(), /MCP access/);
  assert.equal((await f.form('/projects/mcp-access', {}, { cookie: session, origin: checkoutOrigin })).status, 403);
  async function issue() {
    const page = await f.form('/projects/mcp-access', {}, { cookie: session });
    assert.equal(page.status, 201);
    assert.equal(page.headers.get('referrer-policy'), 'no-referrer');
    return (await page.text()).match(/value="(kiwe_start_pilot_[a-zA-Z0-9_-]{43})"/)[1];
  }
  const firstToken = await issue();
  const query = (credential) => f.request('/projects/mcp', { method: 'POST', headers: { Authorization: 'Bearer ' + credential } });
  const access = await issue();
  assert.equal((await query(firstToken)).status, 401);
  const client = await f.connectBearer(access);
  const projects = await client.callTool({ name: 'kiwe_start_projects' });
  assert.equal(projects.structuredContent.projects.length, 1);
  assert.equal(projects.structuredContent.projects[0].accountType, 'agency');
  assert.equal((await query(await f.token(accountId))).status, 401);
  f.advance(20 * 60 * 1000);
  assert.equal((await query(access)).status, 401);
  const last = await issue();
  assert.equal((await f.form('/projects/sign-out', {}, { cookie: session })).status, 303);
  assert.equal((await query(last)).status, 401);
});

test('a hosted public guide keeps its index and versions alongside protected Start projects', async (t) => {
  const directory = await mkdtemp(path.join(tmpdir(), 'kiwe-guide-test-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  await mkdir(path.join(directory, 'v', '9.11-test'), { recursive: true });
  await writeFile(path.join(directory, 'index.html'), 'Published guide');
  await writeFile(path.join(directory, 'v', '9.11-test', 'index.html'), 'Versioned guide');
  await writeFile(path.join(directory, '.env'), 'private configuration');
  const publicGuide = await createPublicGuide(directory);
  const f = await fixture(t, { publicGuide });
  assert.equal(await (await f.request('/')).text(), 'Published guide');
  assert.equal(await (await f.request('/v/9.11-test/')).text(), 'Versioned guide');
  assert.equal((await f.request('/v/9.11-test/', { method: 'HEAD' })).status, 200);
  assert.equal((await f.request('/.env')).status, 404);
  assert.equal((await f.request('/projects')).status, 401);
  assert.equal((await f.request('/v/9.11-test/', { method: 'POST' })).status, 404);
});
