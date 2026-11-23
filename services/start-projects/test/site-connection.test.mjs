import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { createWordPressLink } from '../src/wordpress-link.mjs';
import { publicAddress, siteUrl } from '../src/site-network.mjs';
import { cookie, fixture, purchase, startOrigin } from './fixture.mjs';

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

async function newProject(f, entitlementId, domain = 'existing.example') {
  const accepted = await f.postReceipt('purchases', purchase(entitlementId));
  const { projectId } = await accepted.json();
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
  assert.match(await (await f.request(endpoint, { headers: { Cookie: session } })).text(), /Website verified/);
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
