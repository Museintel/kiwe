import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { request as httpRequest } from 'node:http';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
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

async function fixture(t, { wordpressLink, hostingProvider, keyProvider, agencyReader, contextWordPress, homepageWordPress, homepageProvider, bricksWordPress, bricksCompiler, inventoryWordPress, publicationWordPress } = {}) {
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
    server = createStartServer({ projects, startOrigin, checkoutOrigin, issuer, purchaseToken: checkoutCredential, authorizeMcp, wordpressLink, hostingProvider, keyProvider, agencyReader, contextWordPress, contextClock: () => clock, homepageWordPress, homepageProvider, bricksWordPress, bricksCompiler, inventoryWordPress, publicationWordPress });
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

export { cookie, fixture, issuer, resource, purchase, startOrigin, checkoutOrigin };
