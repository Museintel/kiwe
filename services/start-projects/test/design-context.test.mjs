import assert from 'node:assert/strict';
import { createHash, randomBytes, randomUUID } from 'node:crypto';
import test from 'node:test';
import { createWordPressLink } from '../src/wordpress-link.mjs';
import { cookie, fixture, purchase } from './fixture.mjs';

const scope = 'mcp.start.context';
const nativeCode = 'kiwe_mcp_code_c_' + '1'.repeat(32) + '.' + 'A'.repeat(43);
const operation = 'mcp.start.context.change';
const field = 'identity.description';
const hash = value => createHash('sha256').update(value).digest('hex');

function nativeFixture() {
  const configurations = new Map();
  const writes = [];
  let revoked = 0;
  const at = (origin) => {
    if (!configurations.has(origin)) {
      configurations.set(origin, {
        name: 'Verified site business',
        story: 'First approved description on ' + new URL(origin).hostname,
        grants: new Set(), approvals: new Map(), registrations: new Map(), intents: new Map(),
      });
    }
    return configurations.get(origin);
  };
  const json = data => ({ type: 'application/json', body: JSON.stringify(data) });
  const link = createWordPressLink(async (origin, address, options) => {
    const url = new URL(address);
    const site = at(origin);
    assert.equal(url.origin, origin);
    if (url.pathname === '/.well-known/oauth-authorization-server') {
      return json({
        issuer: origin, authorization_endpoint: origin + '/approve', registration_endpoint: origin + '/register',
        token_endpoint: origin + '/exchange', revocation_endpoint: origin + '/revoke', scopes_supported: ['mcp.read', scope],
      });
    }
    if (url.pathname === '/.well-known/oauth-protected-resource') {
      return json({
        resource: origin + '/wp-json/kiwe-mcp/v1/mcp', authorization_servers: [origin], scopes_supported: ['mcp.read', scope],
      });
    }
    if (url.pathname === '/register') {
      const data = JSON.parse(options.body);
      assert.equal(data.client_name, 'Kiwe Start Design Context');
      assert.equal(new URL(data.redirect_uris[0]).pathname, '/projects/context-return');
      const id = 'cl_' + randomBytes(16).toString('hex');
      site.registrations.set(id, data.redirect_uris[0]);
      return json({ client_id: id });
    }
    if (url.pathname === '/exchange') {
      const entries = new URLSearchParams(options.body);
      const clientId = entries.get('client_id');
      assert.equal(entries.get('code'), nativeCode);
      assert.equal(entries.get('redirect_uri'), site.registrations.get(clientId));
      assert.equal(createHash('sha256').update(entries.get('code_verifier')).digest('base64url'), site.approvals.get(clientId));
      const token = 'kiwe_mcp_access_g_' + randomBytes(16).toString('hex') + '.' + randomBytes(32).toString('base64url');
      site.grants.add(token);
      return json({ access_token: token, scope: 'mcp.read ' + scope, expires_in: 900 });
    }
    if (url.pathname === '/revoke') {
      assert.ok(site.grants.delete(new URLSearchParams(options.body).get('token')));
      revoked++;
      return json({});
    }
    assert.equal(url.pathname, '/wp-json/kiwe-mcp/v1/mcp');
    assert.ok(site.grants.has(options.headers.Authorization.slice('Bearer '.length)));
    const rpc = JSON.parse(options.body);
    const args = rpc.params.arguments;
    let result;
    if (rpc.params.name === 'kiwe-mcp-read') {
      assert.equal(args.operation, 'mcp.start.context.read');
      const area = args.arguments.area;
      const definition = area === 'story'
        ? { path: field, value: site.story, label: 'What you do', type: 'textarea' }
        : { path: 'siteName', value: site.name, label: 'Site name', type: 'text' };
      result = {
        schema: 'kiwe.mcp-setup.v1', area, expected: hash(definition.value),
        fields: [{
          path: definition.path, label: definition.label, needsNativeRead: false,
          definitionJson: JSON.stringify({ type: definition.type, maxLength: 2000 }),
          valueJson: JSON.stringify(definition.value),
        }],
      };
    } else if (rpc.params.name === 'kiwe-mcp-prepare-change') {
      assert.equal(args.operation, operation);
      assert.equal(args.arguments.expected, hash(site.story));
      assert.equal(args.arguments.field, field);
      const id = 'i_' + args.requestId.replaceAll('-', '');
      if (!site.intents.has(id)) site.intents.set(id, args.arguments.value);
      result = { id, operation, state: 'prepared', approvalRequired: false };
    } else if (rpc.params.name === 'kiwe-mcp-apply-change') {
      assert.ok(site.intents.has(args.intent));
      site.story = site.intents.get(args.intent);
      writes.push(origin);
      result = { id: args.intent, operation, state: 'complete' };
    } else assert.fail('Unexpected native tool.');
    return json({ jsonrpc: '2.0', id: rpc.id, result: { structuredContent: result } });
  });

  return {
    link, at, writes, revoked: () => revoked,
    approve(authorizationUrl, issuer) {
      const url = new URL(authorizationUrl);
      const client = url.searchParams.get('client_id');
      assert.equal(url.searchParams.get('scope'), 'mcp.read ' + scope);
      at(url.origin).approvals.set(client, url.searchParams.get('code_challenge'));
      return '/projects/context-return?' + new URLSearchParams({
        state: url.searchParams.get('state'), iss: issuer ?? url.origin, code: nativeCode,
      });
    },
  };
}

const checkoutSiteProof = {
  async begin(domain, redirect, state, verifier) {
    const origin = 'https://' + domain;
    return { origin, issuer: origin, redirect, verifier, authorizationUrl: origin + '/consent?state=' + state };
  },
  async verify(connection, code) {
    assert.equal(code, nativeCode);
    return { url: connection.origin, coreVersion: '8.0.0-rc.495', wordpressVersion: '7.1.5' };
  },
};
const key = { async prepare({ origin, projectId }) {
  return {
    origin, state: 'sender_ready', sender: 'account_primary', siteAuthenticated: true, pairingPending: false,
    approvalUrl: 'https://key.example.test/start/connect/' + projectId,
  };
} };
const call = (client, tool, input) => client.callTool({ name: 'kiwe_start_' + tool, arguments: input });

async function connectProject(f, client, accountId, title, domain, accountType = 'agency') {
  const purchaseResult = await f.postReceipt('purchases', purchase(title, { accountId, accountType }));
  const { projectId } = await purchaseResult.json();
  const session = cookie(await f.redeem(await f.ticket(title, accountId)));
  const endpoint = '/projects/' + projectId;
  const context = endpoint + '/context';
  assert.equal((await f.form(endpoint + '/domain', { domain, hosting: 'existing', revision: '1' }, { cookie: session })).status, 303);
  assert.equal((await call(client, 'open_context', { projectId, revision: 2 })).isError, true);
  const started = await call(client, 'connect_site', { projectId, revision: 2 });
  const auth = new URL(started.structuredContent.authorizationUrl);
  assert.equal((await f.request('/projects/site-return?' + new URLSearchParams({
    code: nativeCode, state: auth.searchParams.get('state'), iss: auth.origin,
  }), { headers: { Cookie: session } })).status, 303);
  const input = { projectId, revision: 3 };
  assert.equal((await call(client, 'connect_key', input)).structuredContent.project.next, 'fill_design_context');
  return { input, context, session, origin: 'https://' + domain };
}

test('native Design Context edits are shared across the browser and MCP and isolated to the approved site', async (t) => {
  const native = nativeFixture();
  const f = await fixture(t, { wordpressLink: checkoutSiteProof, contextWordPress: native.link, keyProvider: key });
  const agency = await f.connect('agency');
  const client = await f.connect('outsider');
  const one = await connectProject(f, agency, 'agency', 'first', 'first.example.test');
  const two = await connectProject(f, agency, 'agency', 'second', 'second.example.test');
  const outside = await connectProject(f, client, 'outsider', 'external', 'external.example.test', 'client');
  const scopeInput = { ...one.input, area: 'story' };
  const read = () => call(agency, 'context', scopeInput);
  const request = (endpoint, session) => f.request(endpoint, { headers: { Cookie: session } });

  assert.equal((await read()).structuredContent.status, 'needs_authorization');
  assert.equal((await call(client, 'context', scopeInput)).isError, true);
  const approval = await call(agency, 'open_context', one.input);
  const url = approval.structuredContent.authorizationUrl;
  const browserApproval = await f.form(one.context + '/authorize', { revision: '3' }, { cookie: one.session });
  assert.equal(browserApproval.headers.get('location'), url);
  assert.equal((await request(native.approve(url, two.origin), one.session)).status, 409);
  const callback = native.approve(url);
  assert.equal((await request(callback, one.session)).headers.get('location'), one.context);
  assert.equal((await request(callback, one.session)).status, 409);

  const second = (await call(agency, 'context', { ...two.input, area: 'story' })).structuredContent;
  assert.equal(second.status, 'needs_authorization');
  assert.equal(native.at(two.origin).grants.size, 0);
  const view = (await read()).structuredContent.section;
  assert.match(view.fields[0].value, /first\.example\.test/);
  assert.match(await (await request(one.context + '?area=story', one.session)).text(), /What you do/);
  assert.equal((await f.form(one.context + '?area=story', {
    revision: '3', area: 'story', field, expected: view.expected, reviewId: randomUUID(), value: 'Human-entered brief',
  }, { cookie: one.session })).status, 303);
  assert.equal((await read()).structuredContent.section.fields[0].value, 'Human-entered brief');

  const edit = { ...scopeInput, field, expected: hash('Human-entered brief'), value: 'Human-approved revision' };
  const prepared = (await call(agency, 'prepare_context_change', edit)).structuredContent;
  const review = new URL(prepared.reviewUrl).pathname;
  assert.equal((await request(review, outside.session)).status, 404);
  assert.match(await (await request(review, one.session)).text(), /Human-approved revision/);
  assert.deepEqual(native.writes, [one.origin]);
  assert.equal((await f.form(review, {}, { cookie: one.session, origin: outside.origin })).status, 403);
  assert.equal((await f.form(review, {}, { cookie: one.session })).status, 303);
  assert.equal((await f.form(review, {}, { cookie: one.session })).status, 410);
  assert.deepEqual(native.writes, [one.origin, one.origin]);
  assert.equal(native.at(one.origin).story, edit.value);
  assert.match(native.at(two.origin).story, /second\.example\.test/);
  assert.ok(!JSON.stringify(await read()).includes([...native.at(one.origin).grants][0]));
  assert.equal((await f.form(one.context + '/close', {}, { cookie: one.session })).status, 303);
  assert.equal(native.revoked(), 1);
  assert.equal((await read()).structuredContent.status, 'needs_authorization');
});

test('a WordPress-side edit invalidates an older Start review and consent expires', async (t) => {
  const native = nativeFixture();
  const f = await fixture(t, { wordpressLink: checkoutSiteProof, contextWordPress: native.link, keyProvider: key });
  const client = await f.connect('buyer-one');
  const site = await connectProject(f, client, 'buyer-one', 'owned', 'owned.example.test', 'client');
  const approval = (await call(client, 'open_context', site.input)).structuredContent.authorizationUrl;
  await f.request(native.approve(approval), { headers: { Cookie: site.session } });
  const args = { ...site.input, area: 'story' };
  const { section } = (await call(client, 'context', args)).structuredContent;
  const review = (await call(client, 'prepare_context_change', {
    ...args, field, value: 'Proposed in Start', expected: section.expected,
  })).structuredContent.reviewUrl;
  native.at(site.origin).story = 'Saved directly in WordPress';
  assert.equal((await f.form(new URL(review).pathname, {}, { cookie: site.session })).status, 409);
  assert.deepEqual(native.writes, []);
  assert.equal(native.at(site.origin).story, 'Saved directly in WordPress');
  f.advance(901_000);
  assert.equal((await call(client, 'context', args)).structuredContent.status, 'needs_authorization');
});

test('a client edits assigned Design Context with their own native consent and loses it when access is revoked', async (t) => {
  const native = nativeFixture();
  const f = await fixture(t, { wordpressLink: checkoutSiteProof, contextWordPress: native.link, keyProvider: key });
  const agency = await f.connect('agency');
  const client = await f.connect('client-account');
  const assigned = await connectProject(f, agency, 'agency', 'assigned', 'assigned.example.test');
  const other = await connectProject(f, agency, 'agency', 'unassigned', 'unassigned.example.test');
  const email = 'client@example.test';
  const inviteClient = async () => {
    const invitation = await call(agency, 'invite_client', { ...assigned.input, email });
    const opened = await f.postReceipt('client-handoffs', {
      accountId: 'client-account', inviteCode: invitation.structuredContent.inviteCode, verifiedEmail: email,
    });
    return cookie(await f.redeem((await opened.json()).ticket));
  };
  const session = await inviteClient();
  const browser = (path) => f.request(path, { headers: { Cookie: session } });
  const scopeInput = { ...assigned.input, area: 'story' };
  const actorRead = () => call(client, 'context', scopeInput);
  const ownerRead = () => call(agency, 'context', scopeInput);
  const ownerUrl = (await call(agency, 'open_context', assigned.input)).structuredContent.authorizationUrl;
  assert.equal((await f.request(native.approve(ownerUrl), { headers: { Cookie: assigned.session } })).status, 303);
  assert.equal((await ownerRead()).structuredContent.status, 'ready');
  assert.equal((await actorRead()).structuredContent.status, 'needs_authorization');
  assert.equal((await browser(other.context)).status, 404);
  const clientPage = await browser(assigned.context + '?area=story');
  assert.match(clientPage.headers.get('content-security-policy'), /form-action 'self' https:\/\/assigned\.example\.test/);
  assert.doesNotMatch(await clientPage.text(), /First approved description/);

  const approval = await f.form(assigned.context + '/authorize', { revision: String(assigned.input.revision) }, { cookie: session });
  const clientUrl = approval.headers.get('location');
  assert.notEqual(clientUrl, ownerUrl);
  assert.equal((await browser(native.approve(clientUrl))).status, 303);
  const { section } = (await actorRead()).structuredContent;
  const edits = { ...scopeInput, field, value: 'Written by the invited client', expected: section.expected };
  const { reviewUrl } = (await call(client, 'prepare_context_change', edits)).structuredContent;
  assert.equal((await f.form(new URL(reviewUrl).pathname, {}, { cookie: session })).status, 303);
  assert.equal((await ownerRead()).structuredContent.section.fields[0].value, edits.value);
  assert.deepEqual(native.writes, [assigned.origin]);

  const stale = (await call(client, 'prepare_context_change', { ...edits, expected: hash(edits.value), value: 'Previous consent' })).structuredContent;
  assert.equal((await call(agency, 'revoke_client', { projectId: assigned.input.projectId })).isError, undefined);
  assert.equal((await actorRead()).isError, true);
  assert.equal((await browser(assigned.context)).status, 404);
  assert.equal((await ownerRead()).structuredContent.status, 'ready');
  const newSession = await inviteClient();
  assert.equal((await actorRead()).structuredContent.status, 'needs_authorization');
  assert.equal((await f.form(new URL(stale.reviewUrl).pathname, {}, { cookie: newSession })).status, 409);
  assert.deepEqual(native.writes, [assigned.origin]);
});
