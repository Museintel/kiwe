import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { request as httpRequest } from 'node:http';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { generateKeyPair, SignJWT } from 'jose';
import { createStartServer } from '../src/app.mjs';
import { createMcpAuthorization } from '../src/auth.mjs';
import { openProjects, projectScope } from '../src/projects.mjs';

const startOrigin = 'https://start.example.test';
const checkoutOrigin = 'https://launch.example.test';
const issuer = 'https://account.example.test';
const resource = startOrigin + '/projects/mcp';

function socketFetch(socketPath) {
  return async (input, init) => {
    const request = new Request(input, init);
    const url = new URL(request.url);
    const body = request.body ? Buffer.from(await request.arrayBuffer()) : null;
    return new Promise((resolve, reject) => {
      const wire = httpRequest({
        socketPath, method: request.method, path: url.pathname + url.search,
        headers: Object.fromEntries(request.headers), signal: request.signal,
      }, (reply) => {
        const chunks = [];
        reply.on('data', (chunk) => chunks.push(chunk));
        reply.on('error', reject);
        reply.on('end', () => resolve(new Response(Buffer.concat(chunks), {
          status: reply.statusCode, headers: reply.headers,
        })));
      });
      wire.on('error', reject);
      wire.end(body);
    });
  };
}

function purchase(entitlementId, extras = {}) {
  return {
    entitlementId, purchaseId: 'order-' + entitlementId, accountId: 'buyer-one',
    accountType: 'client', planId: 'Kiwe', hostingIncluded: false, ...extras,
  };
}

async function fixture(t) {
  const checkoutCredential = randomBytes(32).toString('base64url');
  const directory = await mkdtemp(path.join(tmpdir(), 'kiwe-start-test-'));
  const filename = path.join(directory, 'projects.sqlite');
  const useSocket = process.env.KIWE_START_TEST_SOCKET === '1';
  const socket = path.join(directory, 'app.sock');
  const fetchHttp = useSocket ? socketFetch(socket) : fetch;
  const { privateKey, publicKey } = await generateKeyPair('ES256');
  const authorizeMcp = createMcpAuthorization({ keys: publicKey, issuer, resource });
  let clock = Date.now();
  let projects;
  let server;
  let url;
  async function start() {
    projects = openProjects(filename, { now: () => clock });
    server = createStartServer({ projects, startOrigin, checkoutOrigin, issuer, purchaseToken: checkoutCredential, authorizeMcp });
    if (useSocket) server.listen(socket);
    else server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    url = useSocket ? 'http://localhost' : 'http://127.0.0.1:' + server.address().port;
  }
  async function stop() {
    if (!server) return;
    await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
    projects.close();
    server = null;
  }
  t.after(async () => {
    await stop();
    await rm(directory, { recursive: true, force: true });
  });
  await start();

  function request(route, init) {
    return fetchHttp(url + route, { redirect: 'manual', signal: AbortSignal.timeout(10000), ...init });
  }
  async function postReceipt(route, data, token = checkoutCredential) {
    return request('/projects/api/' + route, {
      method: 'POST', headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
  }
  function form(route, fields, { origin = startOrigin, cookie } = {}) {
    const headers = { Origin: origin };
    if (cookie) headers.Cookie = cookie;
    return request(route, { method: 'POST', headers, body: new URLSearchParams(fields) });
  }
  async function ticket(entitlementId, accountId) {
    const response = await postReceipt('handoffs', { entitlementId, accountId });
    assert.equal(response.status, 201, await response.clone().text());
    return (await response.json()).ticket;
  }
  function redeem(value, origin = checkoutOrigin) {
    return form('/projects/open', { ticket: value }, { origin });
  }
  async function token(accountId, { aud = resource, scope = projectScope, expiry = '3m' } = {}) {
    return new SignJWT({ scope }).setProtectedHeader({ alg: 'ES256' }).setIssuer(issuer).setAudience(aud)
      .setSubject(accountId).setIssuedAt().setExpirationTime(expiry).sign(privateKey);
  }
  async function connect(accountId) {
    const transport = new StreamableHTTPClientTransport(new URL(url + '/projects/mcp'), {
      requestInit: { headers: { Authorization: 'Bearer ' + await token(accountId) } },
      fetch: fetchHttp,
    });
    const client = new Client({ name: 'Kiwe qualification', version: '1.0.0' });
    await client.connect(transport);
    t.after(() => client.close());
    return client;
  }
  return {
    request, postReceipt, form, ticket, redeem, token, connect,
    advance(ms) { clock += ms; },
    async restart() { await stop(); await start(); },
  };
}

function cookie(response) {
  const value = response.headers.get('set-cookie');
  assert.match(value, /^__Host-kiwe-start=/);
  assert.match(value, /HttpOnly; SameSite=Lax; Secure/);
  return value.split(';')[0];
}

test('one paid entitlement opens one durable project and checkout retries keep ownership', async (t) => {
  const f = await fixture(t);
  assert.equal((await f.postReceipt('purchases', purchase('one'), 'untrusted')).status, 401);
  assert.equal((await f.postReceipt('purchases', { ...purchase('one'), user: 'someone-else' })).status, 400);
  const confirmations = await Promise.all(Array.from({ length: 4 }, () => f.postReceipt('purchases', purchase('one'))));
  assert.deepEqual(confirmations.map((response) => response.status).sort(), [200, 200, 200, 201]);
  const projects = await Promise.all(confirmations.map((response) => response.json()));
  const id = projects[0].projectId;
  assert.ok(projects.every((item) => item.projectId === id));
  assert.equal((await f.postReceipt('purchases', purchase('one', { accountId: 'different-account' }))).status, 409);
  assert.equal((await f.postReceipt('handoffs', { entitlementId: 'one', accountId: 'different-account' })).status, 404);

  const handoff = await f.ticket('one', 'buyer-one');
  assert.equal((await f.redeem(handoff, 'https://other.example.test')).status, 403);
  const tabs = await Promise.all([f.redeem(handoff), f.redeem(handoff)]);
  assert.deepEqual(tabs.map((response) => response.status).sort(), [303, 410]);
  const opened = tabs.find((response) => response.status === 303);
  const session = cookie(opened);
  assert.equal(opened.headers.get('location'), '/projects/' + id);
  const initialPage = await f.request('/projects/' + id, { headers: { Cookie: session } });
  assert.match(await initialPage.text(), /<label for="domain">Domain<\/label>/);
  assert.equal((await f.form('/projects/' + id + '/domain',
    { domain: 'Buyer.Example.', hosting: 'existing', revision: '1' }, { cookie: session })).status, 303);

  await f.restart();
  const project = await f.request('/projects/' + id, { headers: { Cookie: session } });
  assert.equal(project.status, 200);
  assert.match(await project.text(), /value="buyer\.example"/);
  const again = await f.postReceipt('purchases', purchase('one'));
  assert.equal((await again.json()).projectId, id);
  const loggedOut = await f.form('/projects/sign-out', {}, { cookie: session });
  assert.equal(loggedOut.status, 303);
  assert.equal((await f.request('/projects', { headers: { Cookie: session } })).status, 401);
  const newSession = cookie(await f.redeem(await f.ticket('one', 'buyer-one')));
  assert.match(await (await f.request('/projects', { headers: { Cookie: newSession } })).text(), /buyer\.example/);
});

test('Start web and MCP work on the same projects for single buyers and agencies', async (t) => {
  const f = await fixture(t);
  const agency = { accountId: 'studio', accountType: 'agency', hostingIncluded: true };
  const agencyOne = (await (await f.postReceipt('purchases', purchase('agency-a', agency))).json()).projectId;
  const agencyTwo = (await (await f.postReceipt('purchases', purchase('agency-b', agency))).json()).projectId;
  const clientId = (await (await f.postReceipt('purchases', purchase('client'))).json()).projectId;
  const studio = await f.connect('studio');
  const customer = await f.connect('buyer-one');
  const tools = await studio.listTools();
  assert.deepEqual(tools.tools.map(({ name }) => name), ['kiwe_start_projects', 'kiwe_start_project', 'kiwe_start_set_domain']);
  const agencyProjects = await studio.callTool({ name: 'kiwe_start_projects' });
  assert.deepEqual(agencyProjects.structuredContent.projects.map(({ id }) => id), [agencyOne, agencyTwo]);
  assert.ok((await customer.callTool({ name: 'kiwe_start_project', arguments: { projectId: agencyOne } })).isError);
  const update = await studio.callTool({
    name: 'kiwe_start_set_domain',
    arguments: { projectId: agencyOne, revision: 1, hosting: 'included', domain: 'Studio.Example.' },
  });
  assert.equal(update.structuredContent.project.next, 'provision_site');
  assert.equal(update.structuredContent.project.domain, 'studio.example');
  const studioCookie = cookie(await f.redeem(await f.ticket('agency-a', 'studio')));
  const agencyPage = await f.request('/projects/' + agencyOne, { headers: { Cookie: studioCookie } });
  assert.match(await agencyPage.text(), /value="studio\.example"/);
  assert.equal((await f.request('/projects/' + clientId, { headers: { Cookie: studioCookie } })).status, 404);

  const clientCookie = cookie(await f.redeem(await f.ticket('client', 'buyer-one')));
  assert.equal((await f.form('/projects/' + clientId + '/domain',
    { domain: 'münchen.example', revision: '1', hosting: 'existing' }, { cookie: clientCookie })).status, 303);
  const fromWeb = await customer.callTool({ name: 'kiwe_start_project', arguments: { projectId: clientId } });
  assert.equal(fromWeb.structuredContent.project.domain, 'xn--mnchen-3ya.example');
  assert.equal(fromWeb.structuredContent.project.next, 'connect_site');
  const disallowed = await customer.callTool({
    name: 'kiwe_start_set_domain',
    arguments: { projectId: clientId, revision: 2, domain: 'client.example', hosting: 'included' },
  });
  assert.ok(disallowed.isError);
});

test('Start rejects expired handoffs and conflicting or untrusted domain writes', async (t) => {
  const f = await fixture(t);
  const id = (await (await f.postReceipt('purchases', purchase('guard'))).json()).projectId;
  const expired = await f.ticket('guard', 'buyer-one');
  f.advance(5 * 60 * 1000);
  assert.equal((await f.redeem(expired)).status, 410);

  const session = cookie(await f.redeem(await f.ticket('guard', 'buyer-one')));
  const pathName = '/projects/' + id + '/domain';
  const fields = { domain: 'valid.example', revision: '1', hosting: 'existing' };
  assert.equal((await f.form(pathName, fields, { cookie: session, origin: checkoutOrigin })).status, 403);
  for (const invalid of ['https://example.com/path', 'user@example.com', '127.0.0.1', 'example..com', 'foo.-example.com']) {
    assert.equal((await f.form(pathName, { ...fields, domain: invalid }, { cookie: session })).status, 400);
  }
  const simultaneous = await Promise.all([
    f.form(pathName, fields, { cookie: session }),
    f.form(pathName, { ...fields, domain: 'second.example' }, { cookie: session }),
  ]);
  assert.deepEqual(simultaneous.map((r) => r.status).sort(), [303, 409]);
  const saved = simultaneous[0].status === 303 ? fields : { ...fields, domain: 'second.example' };
  assert.equal((await f.form(pathName, saved, { cookie: session })).status, 303);
  f.advance(8 * 60 * 60 * 1000);
  assert.equal((await f.request('/projects', { headers: { Cookie: session } })).status, 401);
});

test('MCP requires a current audience-bound access token with the project scope', async (t) => {
  const f = await fixture(t);
  const origin = '/projects/mcp';
  const unauthenticated = await f.request(origin, { method: 'POST' });
  assert.equal(unauthenticated.status, 401);
  assert.match(unauthenticated.headers.get('www-authenticate'), /resource_metadata=/);
  const metadata = await (await f.request('/.well-known/oauth-protected-resource' + origin)).json();
  assert.deepEqual(metadata.authorization_servers, [issuer]);
  assert.deepEqual(metadata.scopes_supported, [projectScope]);
  assert.equal(metadata.resource, resource);

  for (const params of [
    { aud: 'https://elsewhere.example.test' }, { scope: 'read-other' }, { expiry: '1 second ago' },
  ]) {
    const response = await f.request(origin, {
      method: 'POST', headers: { Authorization: 'Bearer ' + await f.token('buyer-one', params) },
    });
    assert.equal(response.status, 401);
  }
  const crossOrigin = await f.request(origin, {
    method: 'POST', headers: { Authorization: 'Bearer ' + await f.token('buyer-one'), Origin: checkoutOrigin },
  });
  assert.equal(crossOrigin.status, 403);
  const unregistered = await f.connect('unregistered');
  const projects = await unregistered.callTool({ name: 'kiwe_start_projects' });
  assert.deepEqual(projects.structuredContent.projects, []);
});
