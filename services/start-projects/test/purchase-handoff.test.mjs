import assert from 'node:assert/strict';
import test from 'node:test';
import { projectScope } from '../src/projects.mjs';
import { cookie, fixture, issuer, resource, purchase, checkoutOrigin } from './fixture.mjs';

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
  assert.deepEqual(tools.tools.map(({ name }) => name), ['kiwe_start_projects', 'kiwe_start_project', 'kiwe_start_set_domain', 'kiwe_start_connect_site',
    'kiwe_start_agency_workspace', 'kiwe_start_set_agency_home', 'kiwe_start_verify_agency_home',
    'kiwe_start_context', 'kiwe_start_open_context', 'kiwe_start_prepare_context_change',
    'kiwe_start_homepage', 'kiwe_start_open_homepage', 'kiwe_start_submit_homepage_design', 'kiwe_start_revise_homepage',
    'kiwe_start_document', 'kiwe_start_open_document', 'kiwe_start_submit_document_design', 'kiwe_start_revise_document',
    'kiwe_start_website', 'kiwe_start_refresh_website']);
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

test('monthly Client includes hosting and the Agency entitlement opens a private workspace with either option', async (t) => {
  const f = await fixture(t);
  const client = { planId: 'kiwe-client-monthly', accountType: 'client' };
  assert.equal((await f.postReceipt('purchases', purchase('monthly-client', client))).status, 400);
  assert.equal((await f.postReceipt('purchases', purchase('wrong-agency', { planId: 'kiwe-agency-monthly' }))).status, 400);
  const withHosting = await f.postReceipt('purchases', purchase('monthly-client', { ...client, hostingIncluded: true }));
  assert.equal(withHosting.status, 201);
  const clientId = (await withHosting.json()).projectId;
  const buyerCookie = cookie(await f.redeem(await f.ticket('monthly-client', 'buyer-one')));
  assert.equal((await f.form('/projects/' + clientId + '/domain', {
    domain: 'my-site.example', hosting: 'existing', revision: '1',
  }, { cookie: buyerCookie })).status, 303);

  const agency = { accountType: 'agency', planId: 'kiwe-agency-monthly', accountId: 'agency-one' };
  for (const hostingIncluded of [false, true]) {
    const entitlement = 'agency-monthly-' + hostingIncluded;
    assert.equal((await f.postReceipt('purchases', purchase(entitlement, { ...agency, hostingIncluded }))).status, 201);
    const signIn = await f.redeem(await f.ticket(entitlement, 'agency-one'));
    assert.equal(signIn.status, 303);
    const home = await f.request('/projects', { headers: { Cookie: cookie(signIn) } });
    assert.match(await home.text(), /Agency workspace settings/);
  }
  const app = await f.connect('agency-one');
  const overview = await app.callTool({ name: 'kiwe_start_projects', arguments: {} });
  assert.deepEqual(overview.structuredContent.projects.map(project => project.hostingIncluded), [false, true]);
});

test('an agency reserves three site slots across the browser, MCP and purchase service', async (t) => {
  const f = await fixture(t);
  const details = { accountType: 'agency', planId: 'kiwe-agency-monthly', accountId: 'site-agency' };
  const receipt = purchase('site-agency-activation', details);
  const first = (await (await f.postReceipt('purchases', receipt)).json()).projectId;
  const session = cookie(await f.redeem(await f.ticket(receipt.entitlementId, details.accountId)));
  const client = await f.connect(details.accountId);
  const addSite = (domain) => client.callTool({ name: 'kiwe_start_add_agency_site', arguments: { domain } });
  const addPath = '/projects/agency/sites';
  const form = (domain, extra) => f.form(addPath, { domain }, { cookie: session, ...extra });
  const dashboard = () => f.request('/projects', { headers: { Cookie: session } });
  assert.match(await (await dashboard()).text(), /1 of 3 site slots reserved/);
  assert.equal((await form('one.example', { origin: checkoutOrigin })).status, 403);
  assert.equal((await form('one.example', { cookie: '' })).status, 401);
  const [submitted, added] = await Promise.all([form('One.Example.'), addSite('one.example')]);
  assert.equal(submitted.headers.get('location'), '/projects/' + first);
  assert.equal(added.structuredContent.project.id, first);
  assert.equal(added.structuredContent.project.next, 'connect_site');
  assert.equal(added.structuredContent.project.hostingIncluded, false);

  const paid = purchase('agency-hosted-one', { ...details, hostingIncluded: true });
  assert.equal((await f.postReceipt('purchases', paid)).status, 201);
  const last = await addSite('other.example');
  assert.equal(last.structuredContent.project.hostingIncluded, false);
  assert.equal((await form('fourth.example')).status, 409);
  assert.equal((await addSite('fourth.example')).isError, true);
  assert.equal((await f.postReceipt('purchases', purchase('extra', { ...details, planId: 'older-agency' }))).status, 409);
  assert.equal((await f.postReceipt('purchases', purchase('extra-hosted', { ...details, hostingIncluded: true }))).status, 409);
  assert.equal((await f.postReceipt('purchases', paid)).status, 200);
  assert.equal((await addSite('Other.Example')).structuredContent.project.id, last.structuredContent.project.id);
  const state = await client.callTool({ name: 'kiwe_start_agency_workspace' });
  assert.deepEqual(state.structuredContent.sites, { used: 3, limit: 3 });
  const html = await (await dashboard()).text();
  assert.match(html, /3 of 3 site slots reserved/);
  assert.doesNotMatch(html, /<button[^>]*>Add site/);
  const stranger = await f.connect('different-account');
  assert.doesNotMatch(JSON.stringify((await stranger.listTools()).tools), /kiwe_start_add_agency_site/);
  await f.restart();
  assert.equal((await form('another.example')).status, 409);
});
