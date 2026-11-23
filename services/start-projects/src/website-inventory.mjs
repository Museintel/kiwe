import { randomBytes } from 'node:crypto';
import { z } from 'zod';
import { claimInput, parse, siteLinkInput, StartError } from './projects.mjs';
import { createWordPressLink } from './wordpress-link.mjs';

export const inventoryReturn = '/projects/website-return';
const nonce = () => randomBytes(32).toString('base64url');
const scope = 'mcp.design.read';
const unavailable = () => new StartError(409, 'inventory_unavailable', 'Open a new WordPress authorization to read this website.');

const record = z.strictObject({ id: z.number().int().positive(), title: z.string(), url: z.url(), editUrl: z.string() });
const template = record.omit({ url: true });
const schema = z.strictObject({
  schema: z.literal('kiwe.start-website-inventory.v1'), website: z.url(), commerce: z.boolean(),
  bricks: z.boolean(), homeMode: z.enum(['page','posts']),
  records: z.partialRecord(z.enum(['home','blog','about','contact','privacy','shop','cart','checkout','account']), record),
  templates: z.strictObject({ header: z.array(template).max(3), footer: z.array(template).max(3) }),
  otherPages: z.array(record).max(24),
});

function trustedLink(website, address, admin) {
  try {
    const url = new URL(address);
    if (url.origin === website && !url.username && !url.password
        && (!admin || url.pathname.startsWith('/wp-admin/'))) return url.href;
  } catch {}
  return '';
}

function snapshot(source, website) {
  const value = schema.parse(JSON.parse(source));
  if (value.website !== website) throw unavailable();
  const page = item => ({ ...item, url: trustedLink(website, item.url, false), editUrl: trustedLink(website, item.editUrl, true) });
  return {
    ...value, records: Object.fromEntries(Object.entries(value.records).map(([key, item]) => [key, page(item)])),
    otherPages: value.otherPages.map(page),
    templates: Object.fromEntries(Object.entries(value.templates).map(([key, items]) => [key,
      items.map(item => ({ ...item, editUrl: trustedLink(website, item.editUrl, true) })),
    ])),
  };
}

const siteItems = [
  ['home','Home','Page'], ['header','Header','Template'], ['footer','Footer','Template'],
  ['about','About','Page'], ['contact','Contact','Page'], ['blog','Blog','Page'],
  ['single-post','Single post','Template'], ['post-archive','Post archives','Template'],
  ['search','Search results','Template'], ['not-found','Page not found','Template'],
  ['privacy','Privacy policy','Page'], ['terms','Terms','Page'],
];
const storeItems = [
  ['shop','Shop','WooCommerce'], ['product-category','Product categories','Template'],
  ['single-product','Single product','Template'], ['cart','Cart','WooCommerce'],
  ['checkout','Checkout','WooCommerce'], ['order-payment','Order payment','WooCommerce'],
  ['thank-you','Thank you','WooCommerce'], ['account','My account and orders','WooCommerce'],
];

export function websiteChecklist(project, inventory, homepage, conversion, canCompile, publication = null) {
  const base = '/projects/' + project.id;
  const native = project.site.url + '/wp-admin/';
  const draft = homepage?.draft;
  const rows = (inventory?.commerce ? [...siteItems, ...storeItems] : siteItems).map(([id, name, type]) => {
    const page = inventory?.records[id];
    const templates = inventory?.templates[id] ?? [];
    const endpoint = ['order-payment','thank-you'].includes(id) && Boolean(inventory?.records.checkout);
    const nativeBlogHome = inventory?.homeMode === 'posts' && ['home','blog'].includes(id);
    const status = page ? 'Published page' : templates.length ? 'Published templates available'
      : endpoint || nativeBlogHome ? 'Native route' : inventory ? 'Review in WordPress' : 'Site inventory pending';
    const links = [];
    const convert = id === 'home' && draft?.status === 'approved' && canCompile
      && (!conversion || (!conversion.sessionActive && !conversion.reviewUrl && !conversion.recoveryRequired))
      ? { href: base + '/homepage/convert', draftId: draft.id, revision: project.revision } : null;
    if (id === 'home' && homepage) {
      const label = !draft ? 'Generate homepage' : conversion?.status === 'draft_ready' ? 'Review Bricks draft' : 'Open homepage draft';
      links.push({ label, href: base + '/homepage' });
      if (conversion?.status === 'draft_ready') links.push({ label: publication?.status === 'published' ? 'Homepage publication' : 'Publish homepage', href: base + '/homepage#publication' });
    }
    if (page?.editUrl) links.push({ label: 'Edit in WordPress', href: page.editUrl });
    if (templates.length === 1 && templates[0].editUrl) links.push({ label: 'Open in Bricks', href: templates[0].editUrl });
    if (!links.length || templates.length > 1) links.push({
      label: type === 'WooCommerce' ? 'Open WooCommerce settings' : 'Open in WordPress',
      href: type === 'WooCommerce' ? native + 'admin.php?page=wc-settings&tab=advanced'
        : native + (type === 'Template' ? 'edit.php?post_type=bricks_template' : 'edit.php?post_type=page'),
    });
    const importStatus = {
      draft_ready: 'Private Bricks draft', approval_required: 'WordPress approval pending',
      importing: 'Import in progress', native_review: 'Review import in WordPress',
    };
    const designStatus = id !== 'home' || !draft ? null : importStatus[conversion?.status] ??
      (draft.status === 'approved' ? 'Approved preview' : draft.status === 'review' ? 'Preview ready' : 'Waiting for design');
    return { id, name, type, status, designStatus, links, ...(convert ? { convert } : {}) };
  });
  for (const page of inventory?.otherPages ?? []) rows.push({
    id: 'page-' + page.id, name: page.title || 'Page ' + page.id, type: 'Page', status: 'Published page',
    designStatus: null, links: page.editUrl ? [{ label: 'Edit in WordPress', href: page.editUrl }] : [],
  });
  return rows;
}

export function createWebsiteInventory(projects, startOrigin, wordpress = createWordPressLink(), now = Date.now) {
  const flows = new Map();

  function target(accountId, details) {
    const project = projects.getProject(accountId, { projectId: details.projectId });
    if (!project.site || (details.revision !== undefined && project.revision !== details.revision)) throw unavailable();
    return project;
  }

  return {
    status(accountId, input) {
      const details = parse(claimInput, input);
      const project = target(accountId, details);
      return { projectId: project.id, website: project.site.url, inventory: projects.getWebsiteInventory(accountId, project.id) };
    },

    async begin(accountId, input) {
      const details = parse(siteLinkInput, input);
      const project = target(accountId, details);
      const previous = [...flows.values()].find(flow =>
        flow.projectId === project.id && flow.revision === project.revision && flow.expiresAt > now());
      if (previous) return previous.connection.authorizationUrl;
      const state = nonce();
      const connection = await wordpress.begin(project.domain, startOrigin + inventoryReturn, state, nonce(), scope, 'Kiwe Start Pages and Templates');
      if (connection.origin !== project.site.url) throw unavailable();
      for (const [id, flow] of flows) if (flow.expiresAt <= now()) flows.delete(id);
      flows.set(state, { ...details, accountId, connection, expiresAt: now() + 600000 });
      return connection.authorizationUrl;
    },

    async complete({ state, iss, code, error }) {
      const flow = /^[A-Za-z0-9_-]{43}$/.test(state ?? '') ? flows.get(state) : undefined;
      if (!flow || flow.expiresAt <= now() || flow.connection.issuer !== iss) throw unavailable();
      flows.delete(state);
      if (error || !/^kiwe_mcp_code_c_[a-f0-9]{32}\.[A-Za-z0-9_-]{43}$/.test(code ?? '')) throw unavailable();
      const project = target(flow.accountId, flow);
      let grant;
      try {
        grant = await wordpress.exchange(flow.connection, code, scope);
        const reply = await wordpress.call(flow.connection, grant.token, 'kiwe-mcp-read', {
          operation: 'mcp.design.website.read', arguments: {},
        });
        if (typeof reply?.resultJson !== 'string' || Buffer.byteLength(reply.resultJson) > 24000) throw unavailable();
        const receipt = snapshot(reply.resultJson, project.site.url);
        projects.saveWebsiteInventory(flow.accountId, { ...flow, snapshot: receipt });
      } catch (cause) {
        if (cause instanceof StartError) throw cause;
        throw unavailable();
      } finally {
        if (grant) await wordpress.revoke(flow.connection, grant.token);
      }
      return { accountId: flow.accountId, projectId: project.id };
    },
  };
}
