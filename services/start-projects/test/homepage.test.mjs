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
      assert.ok(['mcp.design.homepage.read','mcp.design.document.read'].includes(args.operation));
      assert.deepEqual(args.arguments, {});
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
async function wordpressAuthorization(response) {
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('location'), null);
  assert.match(response.headers.get('content-security-policy'), /form-action 'self'$/);
  const link = (await response.text()).match(/href="([^"]+)">Open WordPress authorization/);
  assert.ok(link);
  return link[1].replaceAll('&amp;', '&');
}

async function connect(f, client) {
  const { projectId } = f.seedLegacyPurchase(purchase('homepage'));
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
  const handoff = await wordpressAuthorization(opened);
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

function conversionFixture() {
  const operation = 'mcp.bricks.import.';
  const job = 'f'.repeat(32);
  let archive;
  let approved = false;
  let revision = 1;
  let phase = 'review';
  let uploads = 0;
  let begins = 0;
  const intents = new Map();
  const scoped = {
    async begin(domain, redirect, state, verifier, scope) {
      const origin = 'https://' + domain;
      assert.equal(scope, 'mcp.bricks.import');
      return { origin, issuer: origin, redirect, verifier, authorizationUrl: origin + '/authorize?state=' + state };
    },
    async exchange(connection, confirmation, scope) {
      assert.equal(new URL(connection.redirect).pathname, '/projects/bricks-return');
      assert.equal(confirmation, code);
      assert.equal(scope, 'mcp.bricks.import');
      return { token: 'fictional-conversion-grant', expiresIn: 900 };
    },
    async revoke() {},
    async call(connection, token, name, args) {
      assert.equal(token, 'fictional-conversion-grant');
      if (name === 'kiwe-mcp-read') {
        assert.equal(args.operation, operation + 'status');
        assert.equal(args.arguments.job, job);
        return receipt(connection.origin);
      }
      if (name === 'kiwe-mcp-prepare-change') {
        const item = intents.get(args.requestId);
        if (item) return project(item, connection.origin);
        const id = 'i_' + fingerprint(args.requestId).slice(0, 32);
        const itemData = { id, operation: args.operation, state: 'prepared', result: null };
        intents.set(args.requestId, itemData);
        if (itemData.operation === operation + 'stage') archive = args.arguments.archiveSha256;
        else assert.equal(args.arguments.archiveSha256, archive);
        return project(itemData, connection.origin);
      }
      const item = [...intents.values()].find(record => record.id === args.intent);
      assert.ok(item);
      if (name === 'kiwe-mcp-apply-change') {
        if (item.operation === operation + 'begin') {
          assert.equal(approved, true);
          begins++;
          revision++;
          phase = 'media';
        } else {
          assert.equal(item.operation, operation + 'next');
          phase = { media: 'styles', styles: 'templates', templates: 'complete' }[phase];
          revision++;
        }
        item.state = 'complete';
        item.result = receipt(connection.origin);
      } else assert.equal(name, 'kiwe-mcp-change-status');
      return project(item, connection.origin);
    },
    async upload(connection, token, id, bytes) {
      assert.equal(token, 'fictional-conversion-grant');
      const item = [...intents.values()].find(record => record.id === id);
      assert.equal(item.operation, operation + 'stage');
      assert.equal(fingerprint(bytes), archive);
      uploads++;
      item.state = 'complete';
      item.result = receipt(connection.origin);
      // The server persisted the native job and the HTTP reply was lost.
      throw new Error('Lost upload response');
    },
  };

  function receipt(origin) {
    return { resultJson: JSON.stringify({
      schema: 'kiwe.mcp-bricks-import.v1', job, archiveSha256: archive, status: phase, revision,
      nativeReviewUrl: origin + '/wp-admin/admin.php?page=kiwe-framework&compiler-import=' + job + '#kiwe-compiler-import',
      templateTotal: 1, templatesDone: phase === 'complete' ? 1 : 0,
      blockerCount: 0, needsAttention: false, publishesWebsite: false,
      drafts: phase === 'complete' ? [{ id: 81, title: 'Homepage draft' }] : [],
    }) };
  }

  function project(item, origin) {
    const isApproval = item.operation === operation + 'begin';
    return { ...item, approved: isApproval && approved,
      approvalUrl: isApproval ? origin + '/wp-admin/admin-post.php?action=kiwe_mcp_change_review&intent=' + item.id : '' };
  }

  return { target: scoped, approve: () => { approved = true; }, uploads: () => uploads, begins: () => begins };
}

function publicationFixture(document) {
  let current = 'draft_ready';
  let native;
  let approvals = 0;
  const scope = document ? 'mcp.bricks.document' : 'mcp.bricks.homepage';
  const packet = (origin, status, keys) => ({ resultJson: JSON.stringify({
    ...(document
      ? { schema: 'kiwe.bricks-document-publication.v1', placementId: status === 'draft_ready' ? 0 : 21, url: origin + '/contact/' }
      : { schema: 'kiwe.bricks-homepage-publication.v1', pageId: status === 'draft_ready' ? 0 : 19, url: origin + '/',
          ...(status === 'published' ? { blogUrl: origin + '/blog/' } : {}) }),
    status, ...keys,
  }) });
  return {
    approve() { native.approved = true; },
    writes: () => approvals,
    target: {
      async begin(domain, redirect, state, _verifier, granted) {
        const origin = 'https://' + domain;
        assert.equal(granted, scope);
        assert.equal(new URL(redirect).pathname, '/projects/homepage-publish-return');
        return { origin, issuer: origin, authorizationUrl: origin + '/approve-publication?state=' + state };
      },
      async exchange(_connection, confirmation, granted) {
        assert.equal(confirmation, code);
        assert.equal(granted, scope);
        return { token: 'fixture-publication-grant', expiresIn: 900 };
      },
      async revoke() {},
      async call({ origin }, token, name, input) {
        assert.equal(token, 'fixture-publication-grant');
        if (name === 'kiwe-mcp-read') {
          assert.equal(input.operation, scope + '.status');
          return packet(origin, current, input.arguments);
        }
        if (name === 'kiwe-mcp-prepare-change') {
          assert.ok([scope + '.publish', scope + '.restore'].includes(input.operation));
          if (document) assert.equal(input.arguments.target, document);
          assert.equal(input.arguments.draftId, 81);
          native = { id: 'i_' + fingerprint(input.requestId).slice(0,32), operation: input.operation,
            state: 'prepared', approvalRequired: true, approved: false, keys: input.arguments };
          native.approvalUrl = origin + '/wp-admin/admin-post.php?action=kiwe_mcp_change_review&intent=' + native.id;
        } else if (name === 'kiwe-mcp-apply-change') {
          assert.equal(native.approved, true);
          assert.equal(input.intent, native.id);
          approvals++;
          current = native.operation.endsWith('.restore') ? 'restored' : 'published';
          native.state = 'complete';
          native.result = packet(origin, current, native.keys);
        } else {
          assert.equal(name, 'kiwe-mcp-change-status');
          assert.equal(input.intent, native.id);
        }
        return { ...native };
      },
    },
  };
}

test('the Pages and Templates dashboard reviews, converts, publishes and restores one exact homepage', async (t) => {
  const native = wordpress();
  const conversion = conversionFixture();
  const publication = publicationFixture();
  let compiles = 0;
  const compiler = { async compile({ files }) {
    compiles++;
    assert.ok(!files['preview.js']);
    assert.match(files['seam/seam-map.json'], /index\.html/);
    return { bytes: Buffer.from('fixture-only-seam-archive') };
  } };
  const f = await fixture(t, {
    wordpressLink: native.oauth, homepageWordPress: native.oauth, keyProvider,
    bricksWordPress: conversion.target, bricksCompiler: compiler, publicationWordPress: publication.target,
  });
  const owner = await f.connect('buyer-one');
  const foreign = await f.connect('someone-else');
  const project = await connect(f, owner);
  const mutate = (action, fields) => f.form(project.home + '/' + action, fields, { cookie: project.session });
  const projectPages = '/projects/' + project.projectId + '/pages';
  assert.match(await (await f.request(projectPages, project.options)).text(), /Generate homepage/);
  const opened = await mutate('request', { revision: '3' });
  await f.request(approveUrl(await wordpressAuthorization(opened), '/projects/homepage-return'), project.options);
  const prior = (await mcp(owner, 'homepage', { projectId: project.projectId })).structuredContent.draft;
  const draft = (await mcp(owner, 'submit_homepage_design', {
    projectId: project.projectId, draftId: prior.id, design,
  })).structuredContent.draft;
  const action = { ...project.input, draftId: draft.id };
  assert.equal((await mcp(owner, 'convert_homepage', action)).isError, true);
  assert.equal((await mutate('approve', { draftId: draft.id, sha256: draft.sourceSha256 })).status, 303);
  assert.match(await (await f.request(projectPages, project.options)).text(), /action="[^"]+\/homepage\/convert"/);
  assert.equal((await mcp(foreign, 'convert_homepage', action)).isError, true);

  const convertForm = await mutate('convert', { draftId: draft.id, revision: '3' });
  const convertLink = await wordpressAuthorization(convertForm);
  const approvals = await Promise.all([mcp(owner, 'convert_homepage', action), mcp(owner, 'convert_homepage', action)]);
  for (const approval of approvals) assert.equal(approval.structuredContent.authorizationUrl, convertLink);
  const callback = approveUrl(convertLink, '/projects/bricks-return');
  const lost = await f.request(callback, project.options);
  assert.equal(lost.status, 409);
  assert.equal(conversion.uploads(), 1);
  assert.equal((await f.request(callback, project.options)).status, 409);

  const [pending, same] = await Promise.all([mcp(owner, 'resume_bricks', action), mcp(owner, 'resume_bricks', action)]);
  assert.equal(pending.structuredContent.conversion.status, 'approval_required');
  assert.deepEqual(pending.structuredContent, same.structuredContent);
  assert.equal(conversion.uploads(), 1);
  assert.equal(compiles, 1);
  assert.equal(conversion.begins(), 0);
  assert.match(await (await f.request(project.home, project.options)).text(), /Approve draft import in WordPress/);

  conversion.approve();
  assert.equal((await mutate('continue-import', { draftId: draft.id, revision: '3' })).status, 303);
  const desktop = (await mcp(owner, 'website', { projectId: project.projectId })).structuredContent;
  assert.equal(desktop.conversion.status, 'draft_ready');
  assert.match(desktop.conversion.builderUrl, /post=81&action=edit$/);
  assert.equal(desktop.items[0].designStatus, 'Private Bricks draft');
  assert.equal(conversion.begins(), 1);
  assert.equal((await mcp(owner, 'resume_bricks', action)).structuredContent.conversion.status, 'draft_ready');
  assert.equal(conversion.begins(), 1);
  await f.restart();
  const reopened = await f.connect('buyer-one');
  const page = await (await f.request(projectPages, project.options)).text();
  assert.match(page, /Publish homepage/);
  const publish = { ...action, action: 'publish' };
  assert.equal((await mcp(await f.connect('someone-else'), 'prepare_homepage_publication', publish)).isError, true);
  const publishForm = await mutate('publication', { draftId: draft.id, revision: '3', action: 'publish' });
  assert.ok((await publishForm.clone().text()).includes(`href="${project.home}#publication">Back to design review`));
  const publishLink = await wordpressAuthorization(publishForm);
  assert.equal((await mcp(reopened, 'prepare_homepage_publication', publish)).structuredContent.authorizationUrl, publishLink);
  const publishCallback = approveUrl(publishLink, '/projects/homepage-publish-return');
  assert.equal((await f.request(publishCallback, project.options)).headers.get('location'), project.home + '#publication');
  const waiting = (await mcp(reopened, 'complete_homepage_publication', publish)).structuredContent.publication;
  assert.equal(waiting.status, 'approval_required');
  assert.equal(publication.writes(), 0);
  publication.approve();
  const result = (await mcp(reopened, 'complete_homepage_publication', publish)).structuredContent.publication;
  assert.equal(result.status, 'published');
  assert.equal(result.pageId, 19);
  assert.equal(publication.writes(), 1);
  const publishedHomepage = await (await f.request(project.home, project.options)).text();
  assert.match(publishedHomepage, /View homepage/);
  assert.match(publishedHomepage, /href="https:\/\/alder\.example\.test\/blog\/"[^>]*>View Blog/);
  assert.equal((await mcp(reopened, 'complete_homepage_publication', publish)).structuredContent.publication.status, 'published');
  assert.equal(publication.writes(), 1);

  const restore = { ...action, action: 'restore' };
  const restoreForm = await mutate('publication', { draftId: draft.id, revision: '3', action: 'restore' });
  assert.match(await restoreForm.clone().text(), /prepare the review to restore the previous design/);
  const restoreLink = await wordpressAuthorization(restoreForm);
  const openedRestore = (await mcp(reopened, 'prepare_homepage_publication', restore)).structuredContent;
  assert.equal(openedRestore.authorizationUrl, restoreLink);
  assert.equal((await f.request(approveUrl(restoreLink, '/projects/homepage-publish-return'), project.options)).status, 303);
  publication.approve();
  assert.equal((await mutate('finish-publication', { draftId: draft.id, revision: '3', action: 'restore' })).status, 303);
  assert.match(await (await f.request(project.home, project.options)).text(), /previous homepage was restored/);
  assert.equal(publication.writes(), 2);
});

test('the website dashboard labels only native records as published and shows Woo routes for an active store', async (t) => {
  const native = wordpress();
  let released = false;
  const inventory = {
    ...native.oauth,
    async begin(domain, redirect, state, verifier, scope) {
      assert.equal(scope, 'mcp.design.read');
      return native.oauth.begin(domain, redirect, state, verifier);
    },
    async exchange(_connection, confirmation) {
      assert.equal(confirmation, code);
      return { token: 'fixture-inventory' };
    },
    async call(connection, token, _name, args) {
      assert.equal(token, 'fixture-inventory');
      assert.equal(args.operation, 'mcp.design.website.read');
      const origin = connection.origin;
      const item = (id, title, uri) => ({ id, title, url: origin + uri, editUrl: origin + '/wp-admin/post.php?post=' + id + '&action=edit' });
      return { resultJson: JSON.stringify({
        schema: 'kiwe.start-website-inventory.v1', website: origin, commerce: true, bricks: true,
        homeMode: 'page', records: { home: item(22, 'Home', '/'), checkout: item(24, 'Checkout', '/checkout/') },
        templates: { header: [{ id: 27, title: 'Header', editUrl: origin + '/wp-admin/post.php?post=27&action=edit' }], footer: [] },
        otherPages: [item(25, 'Our story', '/our-story/')],
      }) };
    },
    async revoke() { released = true; },
  };
  const f = await fixture(t, { wordpressLink: native.oauth, keyProvider, inventoryWordPress: inventory });
  const client = await f.connect('buyer-one');
  const project = await connect(f, client);
  const page = '/projects/' + project.projectId + '/pages';
  const review = await f.form(page + '/refresh', { revision: '3' }, { cookie: project.session });
  assert.equal(review.status, 200);
  assert.equal(review.headers.get('location'), null);
  assert.match(review.headers.get('content-security-policy'), /form-action 'self'$/);
  const response = await mcp(client, 'refresh_website', project.input);
  const { authorizationUrl } = response.structuredContent;
  assert.ok((await review.text()).includes(`href="${authorizationUrl}">Open WordPress authorization`));
  const accepted = await f.request(approveUrl(authorizationUrl, '/projects/website-return'), project.options);
  assert.equal(accepted.headers.get('location'), page);
  assert.equal(released, true);
  const home = (await mcp(client, 'website', { projectId: project.projectId })).structuredContent;
  assert.equal(home.items.find(item => item.id === 'home').status, 'Published page');
  assert.equal(home.items.find(item => item.id === 'checkout').status, 'Published page');
  assert.equal(home.items.find(item => item.id === 'thank-you').status, 'Native route');
  assert.equal(home.items.find(item => item.id === 'footer').status, 'Review in WordPress');
  assert.match(await (await f.request(page, project.options)).text(), /Our story/);
});

test('one document keeps its direction, approval, exact import and publication separate from other rows', async (t) => {
  const native = wordpress();
  const conversion = conversionFixture();
  const publication = publicationFixture('contact');
  const inventory = {
    ...native.oauth,
    async exchange() { return { token: 'inventory' }; },
    async call({ origin }, _t, _name, args) {
      assert.equal(args.operation, 'mcp.design.website.read');
      return { resultJson: JSON.stringify({
        schema: 'kiwe.start-website-inventory.v1', website: origin, commerce: true, bricks: true,
        homeMode: 'page', records: {}, templates: { header: [], footer: [], 'post-archive': [] }, otherPages: [],
      }) };
    },
  };
  const f = await fixture(t, {
    wordpressLink: native.oauth, homepageWordPress: native.oauth, keyProvider, inventoryWordPress: inventory,
    bricksWordPress: conversion.target, publicationWordPress: publication.target,
    bricksCompiler: { async compile({ files, templateType, document }) {
      assert.equal(templateType, 'content');
      assert.equal(document, true);
      assert.equal(JSON.parse(files['authoring/start-homepage-receipt.json']).document, 'contact');
      assert.equal('preview.js' in files, false);
      return { bytes: Buffer.from('verified fixture: ' + fingerprint(JSON.stringify(files))) };
    } },
  });
  const owner = await f.connect('buyer-one');
  const outsider = await f.connect('buyer-two');
  const project = await connect(f, owner);
  const { projectId } = project;
  const list = '/projects/' + projectId + '/pages';
  const at = (target) => list + '/' + target;
  const start = (tool, args) => mcp(owner, tool, args);
  const open = async (name, input, route) => {
    const result = await start(name, input);
    assert.equal(result.isError, undefined, JSON.stringify(result));
    return f.request(approveUrl(result.structuredContent.authorizationUrl, route), project.options);
  };
  const post = (url, fields) => f.form(url, fields, { cookie: project.session });
  const read = async (target) => (await start('document', { projectId, target })).structuredContent;
  const input = { ...project.input, target: 'contact' };
  assert.equal((await open('refresh_website', project.input, '/projects/website-return')).status, 303);
  assert.equal((await start('open_document', input)).isError, true, 'Require approved homepage direction first.');
  assert.match(await (await f.request(at('contact'), project.options)).text(), /Approve the homepage/);
  assert.equal((await open('open_homepage', project.input, '/projects/homepage-return')).status, 303);
  const home = (await start('homepage', { projectId })).structuredContent.draft;
  const homeReview = (await start('submit_homepage_design', { projectId, draftId: home.id, design })).structuredContent.draft;
  assert.equal((await post(project.home + '/approve', { draftId: home.id, sha256: homeReview.sourceSha256 })).status, 303);

  for (const target of ['contact', 'blog']) {
    const response = await open('open_document', { ...input, target, feedback: 'Keep the same typography.' }, '/projects/homepage-return');
    assert.equal(response.headers.get('location'), at(target));
  }
  const waiting = (await read('contact')).draft;
  assert.deepEqual(waiting.brief.style, design);
  const proposal = { projectId, target: 'contact', draftId: waiting.id, design: { layout: 'centered', sections: ['description'], heading: 'Talk to us' } };
  assert.equal((await mcp(outsider, 'submit_document_design', proposal)).isError, true);
  assert.equal((await start('submit_document_design', { ...proposal, target: 'blog' })).isError, true);
  const review = (await start('submit_document_design', proposal)).structuredContent.draft;
  assert.equal((await read('blog')).draft.status, 'waiting_for_design');
  assert.equal((await f.request(at('contact') + '/source', project.options)).status, 409);
  const preview = await f.request(at('contact') + '/preview?draft=' + waiting.id, project.options);
  assert.match(preview.headers.get('content-security-policy'), /sandbox allow-scripts/);
  assert.match(await preview.text(), /Talk to us/);
  assert.equal((await post(at('blog') + '/approve', { draftId: waiting.id, sha256: review.sourceSha256 })).status, 409);
  assert.equal((await post(at('contact') + '/approve', { draftId: waiting.id, sha256: review.sourceSha256 })).status, 303);
  const compile = { ...input, draftId: waiting.id };
  const contactFields = { draftId: waiting.id, revision: String(input.revision) };
  const documentConvert = await wordpressAuthorization(await post(at('contact') + '/convert', contactFields));
  assert.equal((await start('convert_document', compile)).structuredContent.authorizationUrl, documentConvert);
  assert.equal((await f.request(approveUrl(documentConvert, '/projects/bricks-return'), project.options)).status, 409, 'Simulated lost native upload reply.');
  await start('resume_bricks', compile);
  conversion.approve();
  assert.equal((await start('resume_bricks', compile)).structuredContent.conversion.status, 'draft_ready');
  const documentPublish = await wordpressAuthorization(await post(at('contact') + '/publication', { ...contactFields, action: 'publish' }));
  assert.equal((await start('prepare_document_publication', { ...compile, action: 'publish' })).structuredContent.authorizationUrl, documentPublish);
  const documentReturn = await f.request(approveUrl(documentPublish, '/projects/homepage-publish-return'), project.options);
  assert.equal(documentReturn.headers.get('location'), at('contact') + '#publication');
  assert.equal((await start('complete_document_publication', { ...compile, action: 'publish' })).structuredContent.publication.status, 'approval_required');
  assert.equal(publication.writes(), 0);
  publication.approve();
  assert.equal((await start('complete_document_publication', { ...compile, action: 'publish' })).structuredContent.publication.status, 'published');
  assert.equal((await start('website', { projectId })).structuredContent.items.find(row => row.id === 'contact').designStatus, 'Published');
  assert.equal((await open('prepare_document_publication', { ...compile, action: 'restore' }, '/projects/homepage-publish-return')).status, 303);
  publication.approve();
  assert.equal((await start('complete_document_publication', { ...compile, action: 'restore' })).structuredContent.publication.status, 'restored');
  const regenerated = await start('revise_document', { ...compile, regenerate: true, feedback: 'A new layout for contact.' });
  assert.equal(regenerated.structuredContent.previousDesign, null);
  assert.notEqual(regenerated.structuredContent.draft.id, waiting.id);
  assert.equal((await start('homepage', { projectId })).structuredContent.draft.id, home.id);
  assert.equal((await read('blog')).draft.status, 'waiting_for_design');
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
  const ownerPage = await f.request(project.home, project.options);
  assert.match(await ownerPage.text(), /href="https:\/\/alder\.example\.test\/wp-admin\/admin\.php\?page=kiwe-design-context">Open Design Context in WordPress/);
  const requested = await f.form(project.home + '/request', { revision: '3', feedback: 'Use warm colors' }, { cookie: project.session });
  assert.equal(calls, 0);
  const { authorizationUrl } = (await mcp(owner, 'open_homepage', { ...project.input, feedback: 'Use warm colors' })).structuredContent;
  assert.equal(await wordpressAuthorization(requested), authorizationUrl);
  const accepted = await f.request(approveUrl(authorizationUrl, '/projects/homepage-return'), project.options);
  assert.equal(accepted.status, 303);
  assert.equal(calls, 1);
  const status = await mcp(owner, 'homepage', { projectId: project.projectId });
  assert.equal(status.structuredContent.draft.status, 'review');
});
