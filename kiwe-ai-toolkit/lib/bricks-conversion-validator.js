import fs from 'node:fs';
import path from 'node:path';
import { validateBindings } from './binding-validator.js';

const SCHEMA = 'kiwe.bricks-conversion.v1';

const KNOWN_BRICKS_ELEMENTS = new Set([
  'section',
  'container',
  'block',
  'div',
  'heading',
  'text-basic',
  'text',
  'text-link',
  'rich-text',
  'button',
  'icon',
  'image',
  'svg',
  'video',
  'audio',
  'divider',
  'form',
  'code',
  'accordion',
  'accordion-nested',
  'tabs',
  'tabs-nested',
  'slider',
  'carousel',
  'post-title',
  'post-excerpt',
  'post-content',
  'post-featured-image',
  'posts',
  'query-results-summary',
  'filter-search',
  'product-title',
  'product-price',
  'product-add-to-cart',
  'product-short-description',
  'product-images',
  'product-upsells',
  'product-related',
  'woocommerce-breadcrumbs',
  'woocommerce-mini-cart'
]);

const OFFICIAL_SEAM_ROLES = new Set([
  'section',
  'container',
  'hero',
  'lead',
  'eyebrow',
  'label',
  'caption',
  'hint',
  'micro',
  'card',
  'media',
  'avatar',
  'button',
  'badge',
  'chip',
  'nav',
  'navigation',
  'actions',
  'form',
  'field',
  'input',
  'textarea',
  'select',
  'search',
  'tabs',
  'tab',
  'tab-panel',
  'rail',
  'reel',
  'grid',
  'stack',
  'cluster',
  'modal',
  'dialog',
  'toast',
  'testimonial',
  'price',
  'progress',
  'skeleton',
  'table',
  'row',
  'cell',
  'footer',
  'aside',
  'region'
]);

const COMMON_DYNAMIC_TAGS = new Set([
  '{post_title}',
  '{post_content}',
  '{post_excerpt}',
  '{post_date}',
  '{post_url}',
  '{post_id}',
  '{post_author}',
  '{featured_image}',
  '{author_name}',
  '{author_url}',
  '{author_bio}',
  '{author_avatar}',
  '{site_title}',
  '{site_tagline}',
  '{site_url}',
  '{term_name}',
  '{term_description}',
  '{woo_product_title}',
  '{woo_product_price}',
  '{woo_product_regular_price}',
  '{woo_product_sale_price}',
  '{woo_product_rating}',
  '{woo_product_sku}',
  '{woo_product_stock}',
  '{woo_product_weight}',
  '{woo_product_url}',
  '{kiwe_site_logo}',
  '{kiwe_site_logo_inverse}'
]);

const SAFE_INTERACTION_ACTIONS = new Set([
  'show',
  'hide',
  'click',
  'setAttribute',
  'removeAttribute',
  'toggleAttribute',
  'toggleOffCanvas',
  'loadMore',
  'loadMoreGallery',
  'startAnimation',
  'scrollTo',
  'openAddress',
  'closeAddress',
  'clearForm',
  'storageAdd',
  'storageRemove',
  'storageCount'
]);

function isPlainObject(value) {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

function add(findings, level, message, file = '', pathPointer = '') {
  findings.push({ level, message, file, path: pathPointer });
}

function rel(root, file) {
  return path.relative(root, file).replace(/\\/g, '/');
}

function readJson(file, findings, label) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    add(findings, 'fail', `${label} is not valid JSON: ${error && error.message ? error.message : String(error)}`, file);
    return null;
  }
}

function findConversionPath(target) {
  const resolved = path.resolve(target || '.');
  if (fs.existsSync(resolved) && fs.statSync(resolved).isFile()) {
    return { root: path.dirname(resolved), conversionPath: resolved };
  }

  const candidates = [
    path.join(resolved, 'bricks-conversion', 'kiwe-bricks-conversion.json'),
    path.join(resolved, 'kiwe-bricks-conversion.json'),
    path.join(resolved, 'bricks-conversion.json')
  ];
  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) {
      return { root: resolved, conversionPath: candidate };
    }
  }
  return { root: resolved, conversionPath: '' };
}

function readTextIfExists(file) {
  return fs.existsSync(file) && fs.statSync(file).isFile() ? fs.readFileSync(file, 'utf8') : '';
}

function readWebsiteText(root) {
  const candidates = [
    path.join(root, 'website', 'bricks-paste.html'),
    path.join(root, 'bricks-paste.html')
  ];
  for (const file of candidates) {
    const text = readTextIfExists(file);
    if (text) return { file, text };
  }
  return { file: '', text: '' };
}

function readNotesText(root) {
  const candidates = [
    path.join(root, 'bricks-conversion', 'BRICKS-CONVERSION-NOTES.md'),
    path.join(root, 'BRICKS-CONVERSION-NOTES.md')
  ];
  for (const file of candidates) {
    const text = readTextIfExists(file);
    if (text) return { file, text };
  }
  return { file: '', text: '' };
}

function findBindingsPath(root) {
  const candidates = [
    path.join(root, 'bricks-bindings', 'kiwe-bindings.json'),
    path.join(root, 'kiwe-bindings.json')
  ];
  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) return candidate;
  }
  return '';
}

function extractClassTokens(html, pattern) {
  const out = new Set();
  for (const match of String(html || '').matchAll(/\bclass\s*=\s*["']([^"']+)["']/gi)) {
    for (const cls of String(match[1] || '').split(/\s+/)) {
      const clean = cls.trim();
      if (clean && (!pattern || pattern.test(clean))) out.add(clean);
    }
  }
  return out;
}

function extractDataRoles(html) {
  const out = [];
  for (const match of String(html || '').matchAll(/\bdata-role\s*=\s*["']([^"']+)["']/gi)) {
    out.push(String(match[1] || '').trim());
  }
  return out;
}

function extractLaunchers(html) {
  const out = new Set();
  for (const match of String(html || '').matchAll(/\bdata-dsa-open-module\s*=\s*["']([^"']+)["']/gi)) {
    out.add(String(match[1] || '').trim());
  }
  return out;
}

function extractQueryTemplates(html) {
  const out = new Set();
  for (const match of String(html || '').matchAll(/\bdata-kiwe-query-template\s*=\s*["']([^"']+)["']/gi)) {
    out.add(String(match[1] || '').trim());
  }
  return out;
}

function extractDynamicTags(value, out = new Set()) {
  if (typeof value === 'string') {
    for (const match of value.matchAll(/\{[A-Za-z_][A-Za-z0-9_.:-]{0,120}\}/g)) out.add(match[0]);
  } else if (Array.isArray(value)) {
    for (const item of value) extractDynamicTags(item, out);
  } else if (isPlainObject(value)) {
    for (const item of Object.values(value)) extractDynamicTags(item, out);
  }
  return out;
}

function graphIndex(siteGraph) {
  const index = {
    hasGraph: Boolean(siteGraph),
    postTypes: new Set(),
    queryTypes: new Set(),
    dynamicTags: new Set(COMMON_DYNAMIC_TAGS),
    taxonomies: new Map()
  };
  if (!isPlainObject(siteGraph)) return index;

  for (const item of asArray(siteGraph.wordpress && siteGraph.wordpress.postTypes)) {
    const name = item && (item.name || item.slug);
    if (name) index.postTypes.add(String(name));
  }
  for (const item of asArray(siteGraph.customContent && siteGraph.customContent.postTypes)) {
    const name = item && (item.name || item.slug);
    if (name) index.postTypes.add(String(name));
  }
  for (const item of asArray(siteGraph.bricks && siteGraph.bricks.queryLoopTypes)) {
    const type = item && (item.objectType || item.type || item.name);
    if (type) index.queryTypes.add(String(type));
  }
  for (const item of asArray(siteGraph.bricks && siteGraph.bricks.dynamicTags)) {
    const tag = typeof item === 'string' ? item : (item && (item.name || item.tag));
    if (tag) index.dynamicTags.add(wrapDynamicTag(tag));
  }
  for (const item of asArray(siteGraph.bricks && siteGraph.bricks.kiweDynamicTags)) {
    if (item) index.dynamicTags.add(wrapDynamicTag(item));
  }

  const addTerms = (taxonomy, terms) => {
    if (!taxonomy) return;
    const key = String(taxonomy);
    if (!index.taxonomies.has(key)) index.taxonomies.set(key, new Set());
    const set = index.taxonomies.get(key);
    for (const term of asArray(terms)) {
      if (term && Number.isFinite(Number(term.id))) set.add(String(Number(term.id)));
      if (term && term.slug) set.add(String(term.slug));
    }
  };
  for (const taxonomy of asArray(siteGraph.wordpress && siteGraph.wordpress.taxonomies)) {
    addTerms(taxonomy && (taxonomy.name || taxonomy.slug), taxonomy && taxonomy.terms);
  }
  for (const taxonomy of asArray(siteGraph.customContent && siteGraph.customContent.taxonomies)) {
    addTerms(taxonomy && (taxonomy.name || taxonomy.slug), taxonomy && taxonomy.terms);
  }
  addTerms('product_cat', siteGraph.woocommerce && siteGraph.woocommerce.productCategories);
  addTerms('product_tag', siteGraph.woocommerce && siteGraph.woocommerce.productTags);

  return index;
}

function wrapDynamicTag(tag) {
  const text = String(tag || '').trim();
  return text.startsWith('{') ? text : `{${text.replace(/[{}]/g, '')}}`;
}

function collectElements(value, out = []) {
  if (Array.isArray(value)) {
    for (const item of value) collectElements(item, out);
  } else if (isPlainObject(value)) {
    if (typeof value.name === 'string' && typeof value.id === 'string') out.push(value);
    if (Array.isArray(value.children)) collectElements(value.children, out);
  }
  return out;
}

function collectQueriesFromElements(elements) {
  const queries = [];
  for (const element of elements) {
    const settings = isPlainObject(element.settings) ? element.settings : {};
    if (isPlainObject(settings.query)) queries.push({ element, query: settings.query });
    if (isPlainObject(settings._query)) queries.push({ element, query: settings._query });
    if (isPlainObject(element.query)) queries.push({ element, query: element.query });
  }
  return queries;
}

function collectInteractions(elements) {
  const interactions = [];
  for (const element of elements) {
    const settings = isPlainObject(element.settings) ? element.settings : {};
    if ('_interactions' in settings) interactions.push({ element, value: settings._interactions });
  }
  return interactions;
}

function collectConditions(elements) {
  const conditions = [];
  for (const element of elements) {
    const settings = isPlainObject(element.settings) ? element.settings : {};
    if ('_conditions' in settings) conditions.push({ element, value: settings._conditions });
  }
  return conditions;
}

function validateRoot(conversion, findings, conversionRel) {
  if (!isPlainObject(conversion)) {
    add(findings, 'fail', 'Bricks conversion file must contain a JSON object.', conversionRel);
    return;
  }
  for (const key of ['schema', 'source', 'target', 'conversion', 'elements', 'fidelity', 'report']) {
    if (!(key in conversion)) add(findings, 'fail', `Missing required root key: ${key}`, conversionRel);
  }
  if (conversion.schema !== SCHEMA) {
    add(findings, 'fail', `schema must be ${SCHEMA}.`, conversionRel, '$.schema');
  }
  const target = conversion.target || {};
  if (!isPlainObject(target)) {
    add(findings, 'fail', 'target must be an object.', conversionRel, '$.target');
  } else {
    if (target.builder !== 'bricks') add(findings, 'fail', 'target.builder must be "bricks".', conversionRel, '$.target.builder');
    if (!String(target.format || '').toLowerCase().includes('bricks')) add(findings, 'fail', 'target.format must identify a Bricks element JSON artifact.', conversionRel, '$.target.format');
    const authority = String(target.applyAuthority || '');
    if (!authority) {
      add(findings, 'fail', 'target.applyAuthority is required and must point to human review or a trusted Kiwe staging adapter.', conversionRel, '$.target.applyAuthority');
    } else if (/(auto|direct|save|publish|mutat|write)/i.test(authority) && !/(human|review|trusted|adapter|staging)/i.test(authority)) {
      add(findings, 'fail', 'target.applyAuthority must not claim direct unsupervised Bricks/WordPress write authority.', conversionRel, '$.target.applyAuthority');
    }
  }
  const conv = conversion.conversion || {};
  if (!isPlainObject(conv)) {
    add(findings, 'fail', 'conversion must be an object.', conversionRel, '$.conversion');
  } else {
    const converter = String(conv.converter || '');
    if (!/(bricks-native|kiwe-fallback|ai-authored|manual)/i.test(converter)) {
      add(findings, 'warn', 'conversion.converter should identify bricks-native, kiwe-fallback, ai-authored, or manual.', conversionRel, '$.conversion.converter');
    }
    if (conv.containsExecutableJs === true) {
      add(findings, 'warn', 'Source contains executable JavaScript. Keep it preview-only or map behavior to safe Bricks interactions/manual review.', conversionRel, '$.conversion.containsExecutableJs');
    }
  }
  if (!Array.isArray(conversion.elements)) {
    add(findings, 'fail', 'elements must be a top-level array of Bricks flat elements.', conversionRel, '$.elements');
  }
  const fidelity = conversion.fidelity || {};
  if (!isPlainObject(fidelity)) {
    add(findings, 'fail', 'fidelity must be an object.', conversionRel, '$.fidelity');
  } else {
    if (!Array.isArray(fidelity.sourceSelectors) || fidelity.sourceSelectors.length === 0) {
      add(findings, 'fail', 'fidelity.sourceSelectors must map the important source sections/selectors to Bricks element IDs.', conversionRel, '$.fidelity.sourceSelectors');
    }
    for (const key of ['elementMapping', 'dynamicIntent', 'interactions', 'conditions', 'unsupported']) {
      if (key in fidelity && !Array.isArray(fidelity[key])) {
        add(findings, 'fail', `fidelity.${key} must be an array when present.`, conversionRel, `$.fidelity.${key}`);
      }
    }
  }
  const report = conversion.report || {};
  if (!isPlainObject(report)) {
    add(findings, 'fail', 'report must be an object.', conversionRel, '$.report');
  } else if (!Array.isArray(report.manualReview)) {
    add(findings, 'fail', 'report.manualReview must be an array, even when empty.', conversionRel, '$.report.manualReview');
  }
}

function validateElements(elements, findings, conversionRel, siteIndex) {
  const byId = new Map();
  const rootElements = [];
  elements.forEach((element, index) => {
    const pointer = `$.elements[${index}]`;
    if (!isPlainObject(element)) {
      add(findings, 'fail', 'Every Bricks element must be an object.', conversionRel, pointer);
      return;
    }
    const id = String(element.id || '');
    const name = String(element.name || '');
    if (!id) add(findings, 'fail', 'Bricks element is missing id.', conversionRel, `${pointer}.id`);
    if (!name) add(findings, 'fail', 'Bricks element is missing name.', conversionRel, `${pointer}.name`);
    if (id && byId.has(id)) add(findings, 'fail', `Duplicate Bricks element id "${id}".`, conversionRel, `${pointer}.id`);
    if (id) byId.set(id, element);
    if (name && !KNOWN_BRICKS_ELEMENTS.has(name)) {
      add(findings, 'warn', `Unknown Bricks element "${name}". Confirm this exists in the target Bricks version/context.`, conversionRel, `${pointer}.name`);
    }
    if ('settings' in element && !isPlainObject(element.settings)) {
      add(findings, 'fail', 'Bricks element settings must be an object when present.', conversionRel, `${pointer}.settings`);
    }
    const parent = String(element.parent || '');
    if (!parent || parent === '0') rootElements.push(element);
    if (parent && parent !== '0' && !byId.has(parent)) {
      // Parent may appear later in rare AI-authored output; second pass below will catch after map is complete.
    }
  });

  elements.forEach((element, index) => {
    if (!isPlainObject(element)) return;
    const parent = String(element.parent || '');
    if (parent && parent !== '0' && !byId.has(parent)) {
      add(findings, 'fail', `Element parent "${parent}" does not exist.`, conversionRel, `$.elements[${index}].parent`);
    }
    if (Array.isArray(element.children)) {
      for (const childId of element.children) {
        if (!byId.has(String(childId))) {
          add(findings, 'fail', `Element children reference missing child "${childId}".`, conversionRel, `$.elements[${index}].children`);
        }
      }
    }
  });
  if (rootElements.length === 0 && elements.length > 0) {
    add(findings, 'fail', 'Bricks conversion has elements but no root element.', conversionRel, '$.elements');
  }

  for (const { element, value } of collectConditions(elements)) {
    if (!Array.isArray(value)) {
      add(findings, 'fail', `Element "${element.id}" has _conditions but it is not an array.`, conversionRel, '$.elements');
    }
  }

  for (const { element, value } of collectInteractions(elements)) {
    if (!Array.isArray(value)) {
      add(findings, 'fail', `Element "${element.id}" has _interactions but it is not an array.`, conversionRel, '$.elements');
      continue;
    }
    for (const item of value) {
      if (!isPlainObject(item)) continue;
      const action = String(item.action || item.actionType || '');
      if (action === 'javascript') {
        add(findings, 'fail', `Element "${element.id}" uses Bricks javascript interaction action. Put custom JS in manual review or replace it with a safe Bricks/Kiwe action.`, conversionRel, '$.elements');
      } else if (action && !SAFE_INTERACTION_ACTIONS.has(action)) {
        add(findings, 'warn', `Element "${element.id}" uses unknown interaction action "${action}". Verify against /ai/bricks/context.`, conversionRel, '$.elements');
      }
    }
  }

  for (const { element, query } of collectQueriesFromElements(elements)) {
    const objectType = String(query.objectType || query.object_type || query.type || '');
    if (siteIndex.hasGraph && objectType && siteIndex.queryTypes.size && !siteIndex.queryTypes.has(objectType)) {
      add(findings, 'warn', `Element "${element.id}" uses query objectType "${objectType}" not listed in Site Graph Bricks queryLoopTypes.`, conversionRel, '$.elements');
    }
    const postTypes = []
      .concat(asArray(query.post_type))
      .concat(asArray(query.postType))
      .concat(typeof query.post_type === 'string' ? [query.post_type] : [])
      .concat(typeof query.postType === 'string' ? [query.postType] : []);
    for (const postType of postTypes) {
      if (siteIndex.hasGraph && siteIndex.postTypes.size && !siteIndex.postTypes.has(String(postType))) {
        add(findings, 'fail', `Element "${element.id}" query uses post type "${postType}" missing from Site Graph.`, conversionRel, '$.elements');
      }
    }
  }
}

function validateSourceParity({ conversion, conversionText, website, bindingsPath, siteGraphPath, findings, conversionRel, siteIndex, root }) {
  if (!website.text) {
    add(findings, 'warn', 'website/bricks-paste.html was not found, so source-to-conversion parity could not be fully checked.', conversionRel);
    return;
  }

  if (/\bdata-dsa-(?:surface|screen|sheet|dock|cart-panel|profile-panel)\b/i.test(website.text)) {
    add(findings, 'fail', 'website/bricks-paste.html contains AppShell/DSA shell markup. Bricks conversion must be page-only.', rel(root, website.file));
  }

  for (const role of extractDataRoles(website.text)) {
    const normalized = role.toLowerCase();
    if (normalized && !OFFICIAL_SEAM_ROLES.has(normalized)) {
      add(findings, 'fail', `Unsupported Seam data-role "${role}" in source page artifact.`, rel(root, website.file));
    }
  }

  const seamClasses = Array.from(extractClassTokens(website.text, /^seam-/));
  if (seamClasses.length > 0) {
    const missing = seamClasses.filter((cls) => !conversionText.includes(cls));
    if (missing.length === seamClasses.length) {
      add(findings, 'fail', 'No Seam classes from the source page are preserved in the Bricks conversion package.', conversionRel);
    } else if (missing.length) {
      add(findings, 'warn', `Some source Seam classes are not visible in the conversion package: ${missing.slice(0, 12).join(', ')}${missing.length > 12 ? ', ...' : ''}`, conversionRel);
    }
  }

  const launchers = Array.from(extractLaunchers(website.text));
  for (const launcher of launchers) {
    if (!conversionText.includes('data-dsa-open-module') || !conversionText.includes(launcher)) {
      add(findings, 'fail', `Source launcher data-dsa-open-module="${launcher}" was not preserved in the Bricks conversion package.`, conversionRel);
    }
  }

  const queryTemplates = Array.from(extractQueryTemplates(website.text));
  if (queryTemplates.length) {
    const queries = collectQueriesFromElements(asArray(conversion.elements));
    const dynamicIntent = asArray(conversion.fidelity && conversion.fidelity.dynamicIntent);
    if (!queries.length && !dynamicIntent.length) {
      add(findings, 'fail', 'Source contains data-kiwe-query-template markers but conversion has no Bricks query settings or fidelity.dynamicIntent.', conversionRel);
    }
    for (const template of queryTemplates) {
      if (!conversionText.includes(template)) {
        add(findings, 'warn', `Source query template "${template}" should be named in the conversion package.`, conversionRel);
      }
    }
  }

  const sourceHasScript = /<script\b|on[a-z]+\s*=|javascript:/i.test(website.text);
  if (sourceHasScript && !(conversion.conversion && conversion.conversion.containsExecutableJs === true) && !/unsupported|manualReview|manual review/i.test(conversionText)) {
    add(findings, 'fail', 'Source has executable behavior, but conversion did not flag containsExecutableJs or manual review.', conversionRel);
  }

  const dynamicTags = Array.from(extractDynamicTags(conversion));
  if (dynamicTags.length && !siteGraphPath) {
    add(findings, 'warn', 'Bricks dynamic tags are present but no Site Graph was supplied, so tags could not be verified against the target site.', conversionRel);
  }
  if (siteGraphPath) {
    for (const tag of dynamicTags) {
      if (!siteIndex.dynamicTags.has(tag)) {
        add(findings, 'warn', `Dynamic tag "${tag}" is not listed in Site Graph dynamic tags or common safe tags. Verify with /ai/bricks/context.`, conversionRel);
      }
    }
  }

  if (bindingsPath) {
    const bindingResult = validateBindings(bindingsPath, { siteGraphPath, optional: false });
    if (!bindingResult.ok) {
      add(findings, 'fail', 'Linked bricks-bindings/kiwe-bindings.json did not pass validate-bindings.', rel(root, bindingsPath));
    } else {
      add(findings, 'info', 'Linked Bricks binding plan passed validate-bindings.', rel(root, bindingsPath));
    }
  }
}

function validateNotes(root, findings) {
  const notes = readNotesText(root);
  if (!notes.text) {
    add(findings, 'fail', 'Bricks conversion output must include bricks-conversion/BRICKS-CONVERSION-NOTES.md.');
    return;
  }
  const text = notes.text.toLowerCase();
  for (const [needle, message] of [
    ['no mutation', 'Notes should explicitly state the conversion package does not mutate WordPress/Bricks by itself.'],
    ['site graph', 'Notes should identify whether Site Graph/Bricks context was used or unavailable.'],
    ['dynamic', 'Notes should explain dynamic tag/query-loop intent.'],
    ['manual review', 'Notes should list manual review requirements, even if none remain.']
  ]) {
    if (!text.includes(needle)) add(findings, 'warn', message, rel(root, notes.file));
  }
}

export function validateBricksConversion(target = '.', options = {}) {
  const findings = [];
  const { root, conversionPath } = findConversionPath(target);
  if (!conversionPath) {
    if (options.optional) {
      return {
        ok: true,
        schema: 'kiwe.bricks-conversion-validation.v1',
        target: path.resolve(target || '.'),
        optional: true,
        findings: [{ level: 'info', message: 'No Bricks conversion package found; optional validation skipped.' }],
        summary: { fail: 0, warn: 0, info: 1 }
      };
    }
    return {
      ok: false,
      schema: 'kiwe.bricks-conversion-validation.v1',
      target: path.resolve(target || '.'),
      findings: [{ level: 'fail', message: 'Missing bricks-conversion/kiwe-bricks-conversion.json.' }],
      summary: { fail: 1, warn: 0, info: 0 }
    };
  }

  const conversionRel = rel(root, conversionPath);
  const conversion = readJson(conversionPath, findings, 'kiwe-bricks-conversion.json');
  const conversionText = conversion ? JSON.stringify(conversion) : '';
  const siteGraphPath = options.siteGraphPath ? path.resolve(options.siteGraphPath) : '';
  const siteGraph = siteGraphPath && fs.existsSync(siteGraphPath) ? readJson(siteGraphPath, findings, 'Site Graph') : null;
  if (siteGraphPath && !fs.existsSync(siteGraphPath)) {
    add(findings, 'fail', `Site Graph file was not found: ${siteGraphPath}`);
  }
  const siteIndex = graphIndex(siteGraph);

  if (conversion) {
    validateRoot(conversion, findings, conversionRel);
    validateElements(asArray(conversion.elements), findings, conversionRel, siteIndex);
    validateSourceParity({
      conversion,
      conversionText,
      website: readWebsiteText(root),
      bindingsPath: findBindingsPath(root),
      siteGraphPath,
      findings,
      conversionRel,
      siteIndex,
      root
    });
  }
  validateNotes(root, findings);

  const summary = findings.reduce(
    (acc, item) => {
      const level = item.level || 'info';
      acc[level] = (acc[level] || 0) + 1;
      return acc;
    },
    { fail: 0, warn: 0, info: 0 }
  );

  return {
    ok: summary.fail === 0,
    schema: 'kiwe.bricks-conversion-validation.v1',
    target: path.resolve(target || '.'),
    conversionPath,
    siteGraphPath,
    findings,
    summary
  };
}
