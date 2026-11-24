import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { createWordPressLink } from '../src/wordpress-link.mjs';
import { createKeyProvider } from '../src/key-connection.mjs';
import { publicAddress, siteUrl } from '../src/site-network.mjs';
import { cookie, fixture, purchase, startOrigin } from './fixture.mjs';
import { browserIdentity } from './browser-identity.mjs';

const origin = 'https://existing.example';
const code = 'kiwe_mcp_code_c_' + '2'.repeat(32) + '.' + 'B'.repeat(43);
const clientId = 'cl_' + '1'.repeat(32);
const token = 'kiwe_mcp_access_g_' + '3'.repeat(32) + '.' + 'X'.repeat(43);
const scopes = ['mcp.read', 'mcp.site.connect'];

function nativeFixture() {
  const events = [];
  let authorized = true;
  let redirect;
  let authorize;
  const send = async (targetOrigin, address, options) => {
    assert.equal(targetOrigin, origin);
    const url = new URL(address);
    assert.equal(url.origin, origin);
    events.push(url.pathname);
    const response = (data) => ({ type: 'application/json', body: JSON.stringify(data) });
    switch (url.pathname) {
      case '/.well-known/oauth-authorization-server':
        return response({
          issuer: origin, authorization_endpoint: origin + '/authorize?action=connect',
          registration_endpoint: origin + '/register', token_endpoint: origin + '/token',
          revocation_endpoint: origin + '/revoke', scopes_supported: scopes,
        });
      case '/.well-known/oauth-protected-resource':
        return response({
          resource: origin + '/wp-json/kiwe-mcp/v1/mcp', authorization_servers: [origin],
          scopes_supported: scopes,
        });
      case '/register': {
        const submitted = JSON.parse(options.body);
        assert.equal(submitted.client_name, 'Kiwe Start website connection');
        assert.deepEqual(submitted.grant_types, ['authorization_code']);
        [redirect] = submitted.redirect_uris;
        return response({ client_id: clientId });
      }
      case '/token': {
        const args = new URLSearchParams(options.body);
        assert.equal(args.get('client_id'), clientId);
        assert.equal(args.get('code'), code);
        assert.equal(args.get('redirect_uri'), redirect);
        assert.equal(createHash('sha256').update(args.get('code_verifier')).digest('base64url'),
          authorize.searchParams.get('code_challenge'));
        return response({ token_type: 'Bearer', scope: scopes.join(' '), access_token: token });
      }
      case '/wp-json/kiwe-mcp/v1/mcp': {
        assert.equal(options.headers.Authorization, 'Bearer ' + token);
        const message = JSON.parse(options.body);
        assert.equal(message.method, 'tools/call');
        assert.deepEqual(message.params.arguments, { operation: 'mcp.site.connect.read', arguments: {} });
        const packet = authorized
          ? { jsonrpc: '2.0', id: message.id, result: { structuredContent: {
              website: origin, coreVersion: '8.0.0-rc.495', wordpressVersion: '7.1.5',
            } } }
          : { jsonrpc: '2.0', id: message.id, result: { isError: true } };
        return { type: 'text/event-stream', body: 'data: ' + JSON.stringify(packet) + '\n\n' };
      }
      case '/revoke': {
        const args = new URLSearchParams(options.body);
        assert.equal(args.get('token'), token);
        assert.equal(args.get('client_id'), clientId);
        return response([]);
      }
      default:
        throw new Error('Unexpected website endpoint');
    }
  };
  const wordpress = createWordPressLink(send);
  return {
    wordpress, events,
    decision(link, allow = true, callbackIssuer = origin) {
      authorize = new URL(link);
      assert.equal(authorize.searchParams.get('action'), 'connect');
      assert.equal(authorize.searchParams.get('scope'), scopes.join(' '));
      authorized = allow;
      const query = new URLSearchParams({ code, state: authorize.searchParams.get('state'), iss: callbackIssuer });
      return '/projects/site-return?' + query.toString();
    },
  };
}

async function newProject(f, entitlementId, domain = 'existing.example', extras = {}) {
  const { projectId } = f.seedLegacyPurchase(purchase(entitlementId, extras));
  const session = cookie(await f.redeem(await f.ticket(entitlementId, 'buyer-one')));
  const endpoint = '/projects/' + projectId;
  const response = await f.form(endpoint + '/domain', { domain, hosting: 'existing', revision: '1' }, { cookie: session });
  assert.equal(response.status, 303);
  return { projectId, endpoint, session };
}

test('a WordPress owner approval connects the same purchase in web and MCP', async (t) => {
  const native = nativeFixture();
  const f = await fixture(t, { wordpressLink: native.wordpress });
  const { projectId, endpoint, session } = await newProject(f, 'site-one');
  const options = { headers: { Cookie: session } };
  const legacyContext = endpoint + '/context?area=identity';
  assert.equal((await f.request(legacyContext)).status, 401);
  assert.equal((await f.request(legacyContext, options)).headers.get('location'), endpoint);
  const client = await f.connect('buyer-one');
  const outsider = await f.connect('someone-else');
  const input = { projectId, revision: 2 };
  const begin = (reader) => reader.callTool({ name: 'kiwe_start_connect_site', arguments: input });
  assert.equal((await begin(outsider)).isError, true);
  const [web, mcp] = await Promise.all([f.form(endpoint + '/connect', { revision: '2' }, { cookie: session }), begin(client)]);
  assert.equal(web.status, 200);
  assert.match(await web.text(), /Open WordPress authorization/);
  const link = mcp.structuredContent.authorizationUrl;
  assert.equal(native.events.filter((item) => item === '/register').length, 1);

  const wrongIssuer = native.decision(link, true, 'https://different.example');
  assert.equal((await f.request(wrongIssuer)).status, 410);
  const response = await f.request(native.decision(link), { headers: { Cookie: session } });
  assert.equal(response.status, 303);
  assert.equal(response.headers.get('location'), endpoint);
  assert.equal(response.headers.get('referrer-policy'), 'no-referrer');
  assert.equal((await f.request(native.decision(link))).status, 410);
  assert.equal(native.events.filter((item) => item === '/revoke').length, 1);

  const read = () => client.callTool({ name: 'kiwe_start_project', arguments: { projectId } });
  const connected = (await read()).structuredContent.project;
  assert.equal(connected.next, 'connect_key');
  assert.equal(connected.site.url, origin);
  assert.equal(connected.site.wordpressVersion, '7.1.5');
  const contextUrl = origin + '/wp-admin/admin.php?page=kiwe-design-context';
  assert.equal(connected.site.designContextUrl, contextUrl);
  const dashboard = await (await f.request(endpoint, options)).text();
  assert.match(dashboard, /Website verified/);
  assert.ok(dashboard.includes(`href="${contextUrl}">Open Design Context in WordPress`));
  const moved = await f.request(legacyContext, options);
  assert.equal(moved.status, 303);
  assert.equal(moved.headers.get('location'), contextUrl);
  for (const suffix of ['/context', '/context/authorize', '/context/close', '/context/review/' + projectId]) {
    assert.equal((await f.form(endpoint + suffix, { revision: '3' }, { cookie: session })).status, 404);
  }
  assert.equal((await f.request('/projects/context-return', options)).status, 404);
  await f.restart();
  assert.match(await (await f.request(endpoint, { headers: { Cookie: session } })).text(), /Website verified/);

  const resumedClient = await f.connect('buyer-one');
  const another = await newProject(f, 'duplicate-site');
  const duplicate = await resumedClient.callTool({
    name: 'kiwe_start_connect_site', arguments: { projectId: another.projectId, revision: 2 },
  });
  assert.equal((await f.request(native.decision(duplicate.structuredContent.authorizationUrl))).status, 409);
  const duplicateState = await resumedClient.callTool({ name: 'kiwe_start_project', arguments: { projectId: another.projectId } });
  assert.equal(duplicateState.structuredContent.project.site, null);

  const changed = await f.form(endpoint + '/domain', { domain: 'new.example', hosting: 'existing', revision: '3' }, { cookie: session });
  assert.equal(changed.status, 303);
  assert.doesNotMatch(await (await f.request(endpoint, { headers: { Cookie: session } })).text(), /Website verified/);
  assert.equal((await f.request(legacyContext, options)).headers.get('location'), endpoint);
});

test('the same verified project resumes Key approval and readiness in the browser and MCP', async (t) => {
  const native = nativeFixture();
  const keyOrigin = 'https://key.example.test';
  const approvalUrl = keyOrigin + '/start/connect/12345678-1234-4abc-8def-123456789012';
  let keyStatus = { state: 'awaiting_approval', sender: null, siteAuthenticated: false, pairingPending: false };
  const requests = [];
  let link = approvalUrl;
  const provider = createKeyProvider({ origin: keyOrigin, token: 'test-only', http: async (address, options) => {
    assert.equal(address, keyOrigin + '/integrations/start/requests');
    assert.equal(options.redirect, 'error');
    assert.equal(options.headers.Authorization, 'Bearer test-only');
    const row = JSON.parse(options.body);
    requests.push(row);
    return Response.json({ origin: row.origin, ...keyStatus, approvalUrl: link });
  } });
  const f = await fixture(t, { wordpressLink: native.wordpress, keyProvider: provider });
  const { projectId, endpoint, session } = await newProject(f, 'key-agency', 'existing.example', { accountType: 'agency' });
  const client = await f.connect('buyer-one');
  const other = await f.connect('another-buyer');
  const key = (actor, revision) => actor.callTool({
    name: 'kiwe_start_connect_key', arguments: { projectId, revision },
  });
  assert.equal((await key(client, 2)).isError, true);
  assert.equal(requests.length, 0);
  const begin = await client.callTool({ name: 'kiwe_start_connect_site', arguments: { projectId, revision: 2 } });
  await f.request(native.decision(begin.structuredContent.authorizationUrl), { headers: { Cookie: session } });
  assert.equal((await key(other, 3)).isError, true);
  assert.equal((await key(client, 2)).isError, true);
  assert.equal(requests.length, 0);
  const surface = () => f.request(endpoint, { headers: { Cookie: session } });
  assert.match(await (await surface()).text(), /Connect Key/);
  const browser = await f.form(endpoint + '/key', { revision: '3' }, { cookie: session });
  assert.equal(browser.status, 303);
  assert.equal(browser.headers.get('location'), approvalUrl);
  assert.equal(requests[0].origin, origin);
  assert.equal(requests[0].accountType, 'agency');
  assert.match(requests[0].workspaceId, /^[a-f0-9-]{36}$/);

  const pending = await key(client, 3);
  assert.equal(pending.structuredContent.approvalUrl, approvalUrl);
  assert.deepEqual(requests[1], requests[0]);
  assert.equal(pending.structuredContent.project.next, 'connect_key');

  link = 'https://unexpected.example.test/start/connect/12345678-1234-4abc-8def-123456789012';
  assert.equal((await key(client, 3)).isError, true);
  link = approvalUrl;
  keyStatus = { state: 'sender_ready', sender: 'account_primary', siteAuthenticated: false, pairingPending: false };
  const waiting = await key(client, 3);
  assert.equal(waiting.structuredContent.project.next, 'connect_key');
  assert.match(await (await surface()).text(), /one-time WordPress setup code/);

  await f.restart();
  const resumed = await f.connect('buyer-one');
  keyStatus.siteAuthenticated = true;
  keyStatus.pairingPending = true;
  const checked = await key(resumed, 3);
  assert.equal(checked.isError, undefined);
  assert.equal(checked.structuredContent.project.next, 'fill_design_context');
  assert.deepEqual(requests.at(-1), requests[0]);
  const html = await (await surface()).text();
  assert.match(html, /Next, fill in Design Context in WordPress/);
  assert.match(html, /client number is waiting/);
  const domain = await f.form(endpoint + '/domain', { domain: 'other.example', hosting: 'existing', revision: '3' }, { cookie: session });
  assert.equal(domain.status, 409);
});

test('a non-owner native approval cannot change the Start project', async (t) => {
  const native = nativeFixture();
  const f = await fixture(t, { wordpressLink: native.wordpress });
  const { projectId, endpoint, session } = await newProject(f, 'site-editor');
  const client = await f.connect('buyer-one');
  const prepared = await client.callTool({ name: 'kiwe_start_connect_site', arguments: { projectId, revision: 2 } });
  const denied = await f.request(native.decision(prepared.structuredContent.authorizationUrl, false));
  assert.equal(denied.status, 403);
  assert.equal(native.events.filter((item) => item === '/revoke').length, 1);
  assert.doesNotMatch(await (await f.request(endpoint, { headers: { Cookie: session } })).text(), /Website verified/);
  const saved = (await client.callTool({ name: 'kiwe_start_project', arguments: { projectId } })).structuredContent.project;
  assert.equal(saved.next, 'connect_site');
  assert.equal(saved.site, null);
});

test('a domain edit during native proof verification cannot attach an older site', async (t) => {
  const native = nativeFixture();
  let release;
  let entered;
  const confirmStarted = new Promise((resolve) => { entered = resolve; });
  const confirmation = new Promise((resolve) => { release = resolve; });
  const wordpress = {
    begin: native.wordpress.begin,
    async verify(...args) {
      entered();
      await confirmation;
      return native.wordpress.verify(...args);
    },
  };
  const f = await fixture(t, { wordpressLink: wordpress });
  const { projectId, endpoint, session } = await newProject(f, 'site-moving');
  const client = await f.connect('buyer-one');
  const prepared = await client.callTool({ name: 'kiwe_start_connect_site', arguments: { projectId, revision: 2 } });
  const callback = f.request(native.decision(prepared.structuredContent.authorizationUrl));
  try {
    await confirmStarted;
    assert.equal((await f.form(endpoint + '/domain',
      { domain: 'updated.example', hosting: 'existing', revision: '2' }, { cookie: session })).status, 303);
  } finally {
    release();
  }
  assert.equal((await callback).status, 409);
  assert.doesNotMatch(await (await f.request(endpoint, { headers: { Cookie: session } })).text(), /Website verified/);
});

test('the website transport admits only the selected HTTPS origin and public addresses', () => {
  for (const address of ['127.0.0.1', '10.0.0.1', '192.168.2.1', '172.16.2.1', 'fc00::1', '::1', '::ffff:192.168.2.1', '2001:db8::1']) {
    assert.equal(publicAddress(address), false, address);
  }
  for (const address of ['1.1.1.1', '2606:4700:4700::1111']) assert.equal(publicAddress(address), true, address);
  assert.equal(siteUrl(origin, origin + '/authorize').origin, origin);
  for (const url of ['http://existing.example', 'https://other.example', 'https://user@existing.example']) {
    assert.throws(() => siteUrl(origin, url));
  }
});

test('an agency client uses their own identity and can open only their verified site', async (t) => {
  const native = nativeFixture();
  const identity = await browserIdentity();
  const f = await fixture(t, { wordpressLink: native.wordpress, browserLogin: identity.provider });
  const { projectId, endpoint, session } = await newProject(f, 'client-access-one', 'existing.example', {
    accountType: 'agency', planId: 'kiwe-agency-monthly',
  });
  const other = await newProject(f, 'client-access-other', 'other.example', {
    accountType: 'agency', planId: 'kiwe-agency-monthly',
  });
  const email = 'member@example.test';
  const fields = { email, revision: '2' };
  assert.equal((await f.form(endpoint + '/invite-client', fields, { cookie: session })).status, 409);

  const agency = await f.connect('buyer-one');
  const actor = await f.connect('client-one');
  const permission = await agency.callTool({ name: 'kiwe_start_connect_site', arguments: { projectId, revision: 2 } });
  const approved = await f.request(native.decision(permission.structuredContent.authorizationUrl), { headers: { Cookie: session } });
  assert.equal(approved.status, 303);
  const now = await agency.callTool({ name: 'kiwe_start_project', arguments: { projectId } });
  fields.revision = String(now.structuredContent.project.revision);
  assert.equal((await f.form(endpoint + '/invite-client', { ...fields, revision: '1' }, { cookie: session })).status, 409);
  const invited = await f.form(endpoint + '/invite-client', fields, { cookie: session });
  assert.equal(invited.status, 201);
  assert.equal(invited.headers.get('cache-control'), 'no-store');
  const inviteCode = (await invited.text()).match(/<code>([A-Za-z0-9_-]{43})<\/code>/)?.[1];
  assert.ok(inviteCode);

  const detail = { accountId: 'client-one', verifiedEmail: email, inviteCode };
  assert.equal((await f.postReceipt('client-handoffs', detail, 'wrong-authority')).status, 401);
  assert.equal((await f.postReceipt('client-handoffs', { ...detail, verifiedEmail: 'other@example.test' })).status, 404);
  assert.equal((await f.postReceipt('client-handoffs', { ...detail, accountId: 'buyer-one' })).status, 404);
  const accepted = await f.postReceipt('client-handoffs', detail);
  assert.equal(accepted.status, 201);
  const first = await accepted.json();
  assert.equal(first.projectId, projectId);
  assert.equal((await f.postReceipt('client-handoffs', detail)).status, 404);
  const clientSession = cookie(await f.redeem(first.ticket));
  assert.equal((await f.redeem(first.ticket)).status, 410);

  const clientProjects = await actor.callTool({ name: 'kiwe_start_projects', arguments: {} });
  assert.deepEqual(clientProjects.structuredContent.projects.map(project => project.id), [projectId]);
  const nativeProject = clientProjects.structuredContent.projects[0];
  assert.equal(nativeProject.access, 'client');
  assert.equal(nativeProject.key, null);
  assert.equal(nativeProject.workspace.type, 'kiwe_launch');
  const contextUrl = origin + '/wp-admin/admin.php?page=kiwe-design-context';
  assert.equal(nativeProject.site.designContextUrl, contextUrl);
  const clientOptions = { headers: { Cookie: clientSession } };
  const browser = await f.request(endpoint, clientOptions);
  assert.equal(browser.status, 200);
  assert.ok((await browser.text()).includes(`href="${contextUrl}">Open Design Context in WordPress`));
  assert.equal((await f.request(endpoint + '/context', clientOptions)).headers.get('location'), contextUrl);
  assert.equal((await f.request(other.endpoint + '/context', clientOptions)).status, 404);
  assert.equal((await f.request(other.endpoint, { headers: { Cookie: clientSession } })).status, 404);
  assert.equal((await f.form(endpoint + '/domain', { ...fields, domain: 'different.example', hosting: 'existing' }, { cookie: clientSession })).status, 404);
  const clientTools = await actor.listTools();
  assert.ok(!clientTools.tools.some(tool => ['kiwe_start_invite_client', 'kiwe_start_revoke_client'].includes(tool.name)));
  assert.equal((await f.form(endpoint + '/revoke-client', {}, { cookie: clientSession })).status, 404);
  assert.equal((await agency.callTool({ name: 'kiwe_start_projects', arguments: {} })).structuredContent.projects.length, 2);

  await f.restart();
  const reopen = await f.postReceipt('client-handoffs', { accountId: 'client-one' });
  assert.equal(reopen.status, 201);
  const repeat = await reopen.json();
  assert.equal(repeat.projectId, projectId);
  const reopened = cookie(await f.redeem(repeat.ticket));
  assert.equal((await f.request(endpoint, { headers: { Cookie: reopened } })).status, 200);

  const pending = await f.postReceipt('client-handoffs', { accountId: 'client-one', projectId });
  assert.equal(pending.status, 201);
  const signedInAgain = await f.connect('buyer-one');
  const revoke = await signedInAgain.callTool({ name: 'kiwe_start_revoke_client', arguments: { projectId } });
  assert.equal(revoke.structuredContent.revoked, true);
  assert.equal((await f.redeem((await pending.json()).ticket)).status, 410);
  assert.equal((await f.request(endpoint, { headers: { Cookie: reopened } })).status, 404);
  assert.equal((await f.request(endpoint + '/context', { headers: { Cookie: reopened } })).status, 404);
  assert.equal((await f.postReceipt('client-handoffs', { accountId: 'client-one' })).status, 404);

  const newInvitation = (await signedInAgain.callTool({
    name: 'kiwe_start_invite_client', arguments: { projectId, email, revision: Number(fields.revision) },
  })).structuredContent.inviteCode;
  const directSignIn = async (verified) => {
    const attempt = await f.form('/projects/sign-in', { inviteCode: newInvitation });
    const callback = await identity.callback(attempt, { accountId: 'client-one', email, verified });
    return f.request(callback.path, { headers: { Cookie: callback.binding } });
  };
  assert.equal((await directSignIn(false)).status, 404);
  const direct = await directSignIn(true);
  assert.equal(direct.status, 303, await direct.clone().text());
  assert.equal(direct.headers.get('location'), endpoint);
  const directSession = direct.headers.getSetCookie().find(value => value.startsWith('__Host-kiwe-start=')).split(';')[0];
  assert.equal((await f.request(endpoint, { headers: { Cookie: directSession } })).status, 200);
  assert.equal((await f.request(other.endpoint, { headers: { Cookie: directSession } })).status, 404);
  assert.equal((await directSignIn(true)).status, 404);
});

test('a client invitation is specific to the verified site revision', async (t) => {
  const native = nativeFixture();
  const f = await fixture(t, { wordpressLink: native.wordpress });
  const { projectId, endpoint, session } = await newProject(f, 'client-access-revision', 'existing.example', {
    accountType: 'agency', planId: 'kiwe-agency-monthly',
  });
  const agency = await f.connect('buyer-one');
  const permission = await agency.callTool({ name: 'kiwe_start_connect_site', arguments: { projectId, revision: 2 } });
  assert.equal((await f.request(native.decision(permission.structuredContent.authorizationUrl), { headers: { Cookie: session } })).status, 303);
  const email = 'client@example.test';
  const current = await agency.callTool({ name: 'kiwe_start_project', arguments: { projectId } });
  const { revision } = current.structuredContent.project;
  const invite = await agency.callTool({ name: 'kiwe_start_invite_client', arguments: { projectId, revision, email } });
  assert.ok(invite.structuredContent.inviteCode);
  assert.equal((await f.form(endpoint + '/domain', { domain: 'replacement.example', hosting: 'existing', revision: String(revision) }, { cookie: session })).status, 303);
  assert.equal((await f.postReceipt('client-handoffs', {
    accountId: 'client-one', verifiedEmail: email, inviteCode: invite.structuredContent.inviteCode,
  })).status, 404);
});
