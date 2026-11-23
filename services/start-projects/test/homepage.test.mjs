import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import { cookie, fixture, purchase } from './fixture.mjs';
import { fingerprint } from '../src/homepage-source.mjs';
import { createHomepageProvider } from '../src/homepage.mjs';

const code = 'kiwe_mcp_code_c_' + '1'.repeat(32) + '.' + 'Z'.repeat(43);
const design = {
  layout: 'split', type: 'serif', palette: { paper: '#faf3e6', ink: '#163127', accent: '#325d45' },
  heroMediaId: null, hideDockOnHero: true,
};

const evidence = origin => {
  const rows = (type, names) => names.map((title, index) => ({
    id: index + 11, title, url: origin + '/' + type + '/' + index,
    image: type === 'product' ? origin + '/media/product-' + index + '.webp' : '', alt: title,
  }));
  return {
    schema: 'kiwe.site-graph.v1', revision: 'sha256:' + 'a'.repeat(64),
    authority: { source: origin, readOnly: true, publicDataOnly: true, mayMutateWordPress: false },
    siteGraph: {
      bricks: { queryLoopTypes: ['post', 'term'], dynamicTags: [
        '{site_title}', '{site_tagline}', '{kiwe_business_description}', '{kiwe_business_usp}',
        '{post_title}', '{post_url}', '{featured_image}', '{term_name}', '{term_url}',
      ].map(name => ({ name })) },
      woocommerce: { active: true }, kiwe: { version: '8.0.0-rc.495', modules: { items: [{ id: 'search' }, { id: 'cart' }] } },
    },
    seamDesignContext: {
      identity: { siteName: 'Alder & Finch', tagline: 'Everyday objects made by hand', description: 'We make wooden kitchenware in small runs.' },
      audience: { primary: 'People looking for durable kitchen tools' }, about: { usp: 'The workshop shapes each piece from locally sourced timber.' },
      seo: { primaryGoal: 'buy' },
    },
    dynamicBindings: { schema: 'kiwe.dynamic-bindings.v1', fields: {
      'identity.siteName': { tag: '{site_title}', kind: 'text' }, 'identity.tagline': { tag: '{site_tagline}', kind: 'text' },
      'identity.description': { tag: '{kiwe_business_description}', kind: 'text' }, 'about.usp': { tag: '{kiwe_business_usp}', kind: 'text' },
    } },
    catalog: {
      products: rows('product', ['Maple serving spoon', 'Walnut board', 'Ash mixing paddle']),
      productCategories: rows('category', ['Serving tools', 'Boards']),
      posts: rows('blog', ['How we finish timber', 'Care for your cutting board']), media: [],
    },
  };
};

function wordpress() {
  let seen = 0;
  let revoked = 0;
  const oauth = {
    async begin(domain, redirect, state, verifier, scope) {
      const origin = 'https://' + domain;
      if (scope) assert.equal(scope, 'mcp.design.read');
      return { origin, issuer: origin, verifier, redirect, authorizationUrl: origin + '/authorize?state=' + state };
    },
    async verify(connection, confirmation) {
      assert.equal(confirmation, code);
      return { url: connection.origin, coreVersion: '8.0.0-rc.495', wordpressVersion: '7.1.5' };
    },
    async exchange(connection, confirmation, scope) {
      assert.equal(scope, 'mcp.design.read');
      assert.equal(new URL(connection.redirect).pathname, '/projects/homepage-return');
      assert.equal(confirmation, code);
      return { token: 'isolated-test-credential' };
    },
    async call(connection, token, name, args) {
      assert.equal(token, 'isolated-test-credential');
      assert.equal(name, 'kiwe-mcp-read');
      assert.deepEqual(args, { operation: 'mcp.design.homepage.read', arguments: {} });
      seen += 1;
      return { resultJson: JSON.stringify(evidence(connection.origin)) };
    },
    async revoke() { revoked += 1; },
  };
  return { oauth, seen: () => seen, revoked: () => revoked };
}

const keyProvider = { async prepare({ origin, projectId }) {
  return {
    origin, state: 'sender_ready', sender: 'account_primary', siteAuthenticated: true, pairingPending: false,
    approvalUrl: 'https://key.example.test/start/connect/' + projectId,
  };
} };

const mcp = (client, tool, input) => client.callTool({ name: 'kiwe_start_' + tool, arguments: input });
const approveUrl = (link, route, issuer) => {
  const url = new URL(link);
  return route + '?' + new URLSearchParams({ state: url.searchParams.get('state'), code, iss: issuer || url.origin });
};
async function connect(f, client) {
  const receipt = await f.postReceipt('purchases', purchase('homepage'));
  const { projectId } = await receipt.json();
  const session = cookie(await f.redeem(await f.ticket('homepage', 'buyer-one')));
  const website = '/projects/' + projectId;
  const home = website + '/homepage';
  const options = { headers: { Cookie: session } };
  assert.equal((await f.form(website + '/domain', { domain: 'alder.example.test', hosting: 'existing', revision: '1' }, { cookie: session })).status, 303);
  assert.equal((await mcp(client, 'open_homepage', { projectId, revision: 2 })).isError, true);
  const prepared = await mcp(client, 'connect_site', { projectId, revision: 2 });
  assert.equal((await f.request(approveUrl(prepared.structuredContent.authorizationUrl, '/projects/site-return'), options)).status, 303);
  assert.equal((await mcp(client, 'connect_key', { projectId, revision: 3 })).structuredContent.project.next, 'fill_design_context');
  return { projectId, input: { projectId, revision: 3 }, session, options, home };
}

function unzipStored(buffer) {
  const out = {};
  let i = 0;
  while (buffer.readUInt32LE(i) === 0x04034b50) {
    const size = buffer.readUInt32LE(i + 18);
    const length = buffer.readUInt16LE(i + 26);
    const filename = buffer.subarray(i + 30, i + 30 + length).toString('utf8');
    const offset = i + 30 + length + buffer.readUInt16LE(i + 28);
    out[filename] = buffer.subarray(offset, offset + size).toString('utf8');
    i = offset + size;
  }
  return out;
}

test('a connected designer makes one homepage, and only the owner can approve its exact downloadable revision', async (t) => {
  const native = wordpress();
  const f = await fixture(t, { wordpressLink: native.oauth, homepageWordPress: native.oauth, keyProvider });
  const owner = await f.connect('buyer-one');
  const other = await f.connect('buyer-two');
  const project = await connect(f, owner);
  const read = () => mcp(owner, 'homepage', { projectId: project.projectId });
  const mutate = (action, fields) => f.form(project.home + '/' + action, fields, { cookie: project.session });
  assert.equal((await mcp(other, 'homepage', { projectId: project.projectId })).isError, true);
  assert.equal((await read()).structuredContent.draft, null);
  const opened = await mutate('request', { revision: '3', feedback: 'Warm editorial style' });
  assert.equal(opened.status, 303);
  const handoff = opened.headers.get('location');
  assert.equal((await f.request(approveUrl(handoff, '/projects/homepage-return', 'https://foreign.example.test'), project.options)).status, 409);
  const callback = approveUrl(handoff, '/projects/homepage-return');
  assert.equal((await f.request(callback, project.options)).headers.get('location'), project.home);
  assert.equal((await f.request(callback, project.options)).status, 409);
  assert.equal(native.seen(), 1);
  assert.equal(native.revoked(), 1);

  const pending = (await read()).structuredContent.draft;
  assert.equal(pending.status, 'waiting_for_design');
  assert.equal(pending.brief.products[0].title, 'Maple serving spoon');
  assert.doesNotMatch(JSON.stringify(pending), /isolated-test-credential/);
  const input = { projectId: project.projectId, draftId: pending.id, design };
  assert.equal((await mcp(other, 'submit_homepage_design', input)).isError, true);
  assert.equal((await mcp(owner, 'submit_homepage_design', { ...input, design: { ...design, heroMediaId: 400 } })).isError, true);
  const result = await mcp(owner, 'submit_homepage_design', input);
  const draft = result.structuredContent.draft;
  assert.deepEqual(draft.bindings, { productLoop: true, categoryLoop: true, editorialLoop: true });
  assert.equal(draft.status, 'review');
  assert.equal((await f.request(project.home + '/source', project.options)).status, 409);
  const viewport = await f.request(project.home + '/preview?draft=' + draft.id, project.options);
  assert.match(viewport.headers.get('content-security-policy'), /^sandbox allow-scripts;/);
  assert.match(await viewport.text(), /Everyday objects made by hand/);
  assert.equal((await mutate('approve', { draftId: draft.id, sha256: 'a'.repeat(64) })).status, 409);
  assert.equal((await mutate('approve', { draftId: draft.id, sha256: draft.sourceSha256 })).status, 303);

  const approved = (await read()).structuredContent.draft;
  const url = new URL(approved.sourceUrl);
  const bytes = await f.request(url.pathname + url.search, { headers: { Authorization: 'Bearer ' + await f.token('buyer-one') } });
  assert.equal(bytes.headers.get('x-kiwe-source-sha256'), draft.sourceSha256);
  const zip = Buffer.from(await bytes.arrayBuffer());
  const files = unzipStored(zip);
  assert.equal(fingerprint(JSON.stringify(files)), draft.sourceSha256);
  const map = JSON.parse(files['seam/seam-map.json']);
  assert.equal(map.documents.length, 1);
  assert.equal(map.documents[0].path, 'index.html');
  assert.equal(map.documents[0].stitches.filter(stitch => stitch.kind === 'collection').length, 3);
  assert.match(files['index.html'], /data-dsa-hide-dock/);

  const seamUrl = new URL(approved.seamSourceUrl);
  const prepared = await f.request(seamUrl.pathname + seamUrl.search, project.options);
  assert.equal(prepared.headers.get('x-kiwe-approved-source-sha256'), draft.sourceSha256);
  const seam = unzipStored(Buffer.from(await prepared.arrayBuffer()));
  assert.equal(Object.hasOwn(seam, 'preview.js'), false);
  assert.doesNotMatch(seam['index.html'], /<script\b/);
  assert.equal(seam['styles.css'], files['styles.css']);
  assert.equal(JSON.parse(seam['seam/seam-map.json']).sources[0].sha256, fingerprint(seam['index.html']));
  assert.equal(JSON.parse(seam['authoring/start-homepage-receipt.json']).approvedSourceSha256, draft.sourceSha256);

  const destination = process.env.KIWE_TEST_HOMEPAGE_OUTPUT;
  if (destination) {
    await mkdir(destination, { recursive: true });
    for (const [name, content] of Object.entries(seam)) {
      const filename = path.join(destination, name);
      await mkdir(path.dirname(filename), { recursive: true });
      await writeFile(filename, content);
    }
  }

  const revised = (await mcp(owner, 'revise_homepage', {
    ...project.input, draftId: draft.id, feedback: 'Try a centered heading and keep the same business facts.',
  })).structuredContent;
  assert.equal(revised.draft.version, 2);
  assert.equal(revised.draft.brief.name, pending.brief.name);
  assert.deepEqual(revised.previousDesign, design);
  assert.equal(native.seen(), 1);
  assert.equal((await mutate('approve', { draftId: draft.id, sha256: draft.sourceSha256 })).status, 409);
  assert.equal((await f.request(url.pathname + url.search, project.options)).headers.get('x-kiwe-source-sha256'), draft.sourceSha256);
  await f.restart();
  const next = await f.connect('buyer-one');
  const resumed = await mcp(next, 'homepage', { projectId: project.projectId });
  assert.equal(resumed.structuredContent.draft.id, revised.draft.id);
  assert.deepEqual(resumed.structuredContent.previousDesign, design);
});

test('a configured browser generator receives only the public brief and can fill the current request', async (t) => {
  const native = wordpress();
  let calls = 0;
  const provider = createHomepageProvider({
    endpoint: 'https://generator.example.test/homepage', token: 'test-only-generator-key',
    http: async (endpoint, options) => {
      calls++;
      assert.equal(endpoint, 'https://generator.example.test/homepage');
      assert.equal(options.redirect, 'error');
      const supplied = JSON.parse(options.body);
      assert.equal(supplied.brief.name, 'Alder & Finch');
      assert.equal(supplied.brief.website, 'https://alder.example.test');
      assert.doesNotMatch(options.body, /isolated-test-credential/);
      return Response.json(design);
    },
  });
  const f = await fixture(t, { wordpressLink: native.oauth, homepageWordPress: native.oauth, keyProvider, homepageProvider: provider });
  const owner = await f.connect('buyer-one');
  const project = await connect(f, owner);
  const requested = await f.form(project.home + '/request', { revision: '3', feedback: 'Use warm colors' }, { cookie: project.session });
  assert.equal(calls, 0);
  const accepted = await f.request(approveUrl(requested.headers.get('location'), '/projects/homepage-return'), project.options);
  assert.equal(accepted.status, 303);
  assert.equal(calls, 1);
  const status = await mcp(owner, 'homepage', { projectId: project.projectId });
  assert.equal(status.structuredContent.draft.status, 'review');
});
