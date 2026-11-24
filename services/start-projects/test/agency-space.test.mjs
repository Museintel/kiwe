import assert from 'node:assert/strict';
import test from 'node:test';
import { cookie, fixture, purchase } from './fixture.mjs';

async function project(f, entitlementId, accountId, accountType) {
  const receipt = f.seedLegacyPurchase(purchase(entitlementId, { accountId, accountType }));
  return {
    projectId: receipt.projectId,
    session: cookie(await f.redeem(await f.ticket(entitlementId, accountId))),
  };
}

const call = (client, name, args = {}) => client.callTool({ name: 'kiwe_start_' + name, arguments: args });

test('standalone buyers stay under Kiwe Launch and each agency gets its own verified home', async (t) => {
  const served = new Map();
  const reads = [];
  const f = await fixture(t, { agencyReader: async (origin, url) => {
    assert.equal(url, origin + '/.well-known/kiwe-agency.txt');
    reads.push(url);
    return { type: 'text/plain; charset=utf-8', body: served.get(url) ?? '' };
  } });
  const direct = await project(f, 'direct', 'direct-buyer', 'client');
  const one = await project(f, 'first-site', 'agency-buyer', 'agency');
  const two = await project(f, 'second-site', 'agency-buyer', 'agency');
  const other = await project(f, 'other-agency', 'other-buyer', 'agency');
  const directClient = await f.connect('direct-buyer');
  const agency = await f.connect('agency-buyer');
  const outsider = await f.connect('other-buyer');
  const opts = (session) => ({ headers: { Cookie: session } });
  const mine = (client) => call(client, 'projects');
  const body = { name: 'Trail Studio', domain: 'trail-agency.example.test' };

  const [standalone] = (await mine(directClient)).structuredContent.projects;
  assert.equal(standalone.id, direct.projectId);
  assert.equal(standalone.workspace.type, 'kiwe_launch');
  assert.equal(standalone.workspace.name, 'Kiwe Launch');
  assert.equal((await call(directClient, 'set_agency_home', body)).isError, true);
  assert.equal((await f.request('/projects/agency', opts(direct.session))).status, 404);
  assert.doesNotMatch(await (await f.request('/projects', opts(direct.session))).text(), /Agency workspace/);

  const listings = (await mine(agency)).structuredContent.projects;
  assert.deepEqual(listings.map(x => x.id), [one.projectId, two.projectId]);
  assert.ok(listings.every(x => x.workspace.website === null && x.workspace.type === 'agency'));
  assert.equal((await call(agency, 'set_agency_home', { ...body, domain: '127.0.0.1' })).isError, true);
  const response = await f.form('/projects/agency', body, { cookie: one.session });
  assert.equal(response.status, 303);
  const pending = (await call(agency, 'agency_workspace')).structuredContent.pending;
  assert.equal(pending.website, 'https://' + body.domain);
  assert.match(await (await f.request('/projects/agency', opts(two.session))).text(), new RegExp(pending.proofText));
  assert.equal((await call(outsider, 'verify_agency_home', { attemptId: pending.attemptId })).isError, true);
  assert.equal((await call(agency, 'verify_agency_home', { attemptId: pending.attemptId })).isError, true);
  assert.equal(reads.length, 1);
  served.set(pending.proofUrl, pending.proofText + '\n');
  assert.equal((await f.form('/projects/agency/verify', { attemptId: pending.attemptId }, { cookie: one.session })).status, 303);
  const confirmed = (await mine(agency)).structuredContent.projects;
  assert.ok(confirmed.every(x => x.workspace.website === pending.website && x.workspace.name === body.name));
  assert.equal((await mine(outsider)).structuredContent.projects[0].workspace.website, null);
  assert.doesNotMatch(await (await f.request('/projects', opts(other.session))).text(), /Trail Studio/);
  assert.match(await (await f.request('/projects', opts(one.session))).text(), /Trail Studio/);

  await f.restart();
  const restarted = await f.connect('agency-buyer');
  const workspace = (await call(restarted, 'agency_workspace')).structuredContent;
  assert.equal(workspace.workspace.website, pending.website);
  assert.equal(workspace.pending, null);

  const otherAttempt = (await call(await f.connect('other-buyer'), 'set_agency_home', body)).structuredContent.pending;
  served.set(otherAttempt.proofUrl, otherAttempt.proofText);
  assert.equal((await f.form('/projects/agency/verify', { attemptId: otherAttempt.attemptId }, { cookie: other.session })).status, 409);
  assert.equal((await call(restarted, 'agency_workspace')).structuredContent.workspace.website, pending.website);
});

test('an agency website change preserves its active home until the new proof completes', async (t) => {
  const served = new Map();
  const f = await fixture(t, { agencyReader: async (_origin, url) => ({ type: 'text/plain', body: served.get(url) }) });
  const { session } = await project(f, 'one', 'agency', 'agency');
  const client = await f.connect('agency');
  const prepare = (domain) => call(client, 'set_agency_home', { name: 'Agency space', domain });
  const one = (await prepare('one.example.test')).structuredContent.pending;
  served.set(one.proofUrl, one.proofText);
  assert.equal((await f.form('/projects/agency/verify', { attemptId: one.attemptId }, { cookie: session })).status, 303);
  const two = (await prepare('two.example.test')).structuredContent;
  assert.equal(two.workspace.website, one.website);
  f.advance(24 * 60 * 60 * 1000 + 1);
  assert.equal((await call(client, 'verify_agency_home', { attemptId: two.pending.attemptId })).isError, true);
  assert.equal((await call(client, 'agency_workspace')).structuredContent.workspace.website, one.website);
});
