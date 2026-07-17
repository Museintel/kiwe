import fs from 'node:fs';
import path from 'node:path';

const REQUIRED_ROOT_KEYS = [
  'schema',
  'siteGraphSchema',
  'target',
  'queries',
  'dynamicFields',
  'launchers',
  'menuContext',
  'assumptions',
  'requiresHumanReview'
];

const KNOWN_DSA_MODULES = new Set([
  'menu',
  'search',
  'profile',
  'links',
  'saved',
  'cart',
  'checkout',
  'theme',
  'ai',
  'notifications',
  'ios-install',
  'games'
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
  '{term_description}'
]);

function readJson(file, findings, label) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    findings.push({
      level: 'fail',
      message: `${label} is not valid JSON: ${error && error.message ? error.message : String(error)}`,
      file
    });
    return null;
  }
}

function rel(root, file) {
  return path.relative(root, file).replace(/\\/g, '/');
}

function isPlainObject(value) {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

function add(findings, level, message, file = '') {
  findings.push({ level, message, file });
}

function findBindingPath(target) {
  const resolved = path.resolve(target || '.');
  if (fs.existsSync(resolved) && fs.statSync(resolved).isFile()) {
    return {
      root: path.dirname(resolved),
      bindingPath: resolved
    };
  }

  const nested = path.join(resolved, 'bricks-bindings', 'kiwe-bindings.json');
  if (fs.existsSync(nested)) {
    return {
      root: resolved,
      bindingPath: nested
    };
  }

  const direct = path.join(resolved, 'kiwe-bindings.json');
  if (fs.existsSync(direct)) {
    return {
      root: resolved,
      bindingPath: direct
    };
  }

  return {
    root: resolved,
    bindingPath: ''
  };
}

function readWebsiteText(root) {
  const candidates = [
    path.join(root, 'website', 'bricks-paste.html'),
    path.join(root, 'bricks-paste.html')
  ];

  for (const file of candidates) {
    if (fs.existsSync(file)) {
      return {
        file,
        text: fs.readFileSync(file, 'utf8')
      };
    }
  }

  return { file: '', text: '' };
}

function selectorAppears(selector, websiteText) {
  if (!selector || !websiteText) return true;
  const raw = String(selector).trim();
  if (!raw) return true;

  const idMatch = raw.match(/^#([A-Za-z][\w:-]*)$/);
  if (idMatch) {
    return new RegExp(`\\bid\\s*=\\s*["']${escapeRegExp(idMatch[1])}["']`, 'i').test(websiteText);
  }

  const dataBindingMatch = raw.match(/\[data-kiwe-binding\s*=\s*['"]([^'"]+)['"]\]/i);
  if (dataBindingMatch) {
    return new RegExp(`\\bdata-kiwe-binding\\s*=\\s*["']${escapeRegExp(dataBindingMatch[1])}["']`, 'i').test(websiteText);
  }

  const dataLauncherMatch = raw.match(/\[data-dsa-open-module\s*=\s*['"]([^'"]+)['"]\]/i);
  if (dataLauncherMatch) {
    return new RegExp(`\\bdata-dsa-open-module\\s*=\\s*["']${escapeRegExp(dataLauncherMatch[1])}["']`, 'i').test(websiteText);
  }

  return true;
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function extractDynamicTags(value, out = new Set()) {
  if (typeof value === 'string') {
    for (const match of value.matchAll(/\{[^{}]+\}/g)) {
      out.add(match[0]);
    }
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
    taxonomies: new Map(),
    queryTypes: new Set(),
    dynamicTags: new Set(COMMON_DYNAMIC_TAGS),
    dsaModules: new Set(KNOWN_DSA_MODULES)
  };

  if (!siteGraph || !isPlainObject(siteGraph)) return index;

  for (const item of asArray(siteGraph.wordpress && siteGraph.wordpress.postTypes)) {
    if (item && item.name) index.postTypes.add(String(item.name));
  }

  const addTaxonomyTerms = (taxonomy, terms) => {
    if (!taxonomy) return;
    const key = String(taxonomy);
    if (!index.taxonomies.has(key)) index.taxonomies.set(key, new Set());
    const set = index.taxonomies.get(key);
    for (const term of asArray(terms)) {
      if (term && Number.isFinite(Number(term.id))) set.add(String(Number(term.id)));
    }
  };

  for (const taxonomy of asArray(siteGraph.wordpress && siteGraph.wordpress.taxonomies)) {
    addTaxonomyTerms(taxonomy && taxonomy.name, taxonomy && taxonomy.terms);
  }
  addTaxonomyTerms('product_cat', siteGraph.woocommerce && siteGraph.woocommerce.productCategories);
  addTaxonomyTerms('product_tag', siteGraph.woocommerce && siteGraph.woocommerce.productTags);

  for (const item of asArray(siteGraph.bricks && siteGraph.bricks.queryLoopTypes)) {
    if (item && item.objectType) index.queryTypes.add(String(item.objectType));
  }

  for (const item of asArray(siteGraph.bricks && siteGraph.bricks.dynamicTags)) {
    const name = typeof item === 'string' ? item : (item && (item.name || item.tag));
    if (!name) continue;
    const text = String(name).trim();
    index.dynamicTags.add(text.startsWith('{') ? text : `{${text.replace(/[{}]/g, '')}}`);
  }
  for (const tag of asArray(siteGraph.bricks && siteGraph.bricks.kiweDynamicTags)) {
    const text = String(tag || '').trim();
    if (text) index.dynamicTags.add(text.startsWith('{') ? text : `{${text.replace(/[{}]/g, '')}}`);
  }

  const modules = siteGraph.kiwe && siteGraph.kiwe.modules;
  if (modules && isPlainObject(modules)) {
    for (const item of asArray(modules.items || modules.modules || modules.registered)) {
      const id = item && (item.id || item.module || item.key);
      if (id) index.dsaModules.add(String(id));
    }
  }

  return index;
}

function validateRoot(binding, findings, bindingRel) {
  if (!isPlainObject(binding)) {
    add(findings, 'fail', 'kiwe-bindings.json must contain a JSON object.', bindingRel);
    return;
  }

  for (const key of REQUIRED_ROOT_KEYS) {
    if (!(key in binding)) add(findings, 'fail', `kiwe-bindings.json missing required key: ${key}`, bindingRel);
  }
  if (binding.schema !== 'kiwe.bricks-bindings.v1') {
    add(findings, 'fail', 'kiwe-bindings.json schema must be kiwe.bricks-bindings.v1.', bindingRel);
  }
  if (binding.siteGraphSchema !== 'kiwe.site-graph.v1') {
    add(findings, 'warn', 'kiwe-bindings.json should declare siteGraphSchema: kiwe.site-graph.v1.', bindingRel);
  }

  const target = binding.target || {};
  if (!isPlainObject(target)) {
    add(findings, 'fail', 'target must be an object.', bindingRel);
  } else {
    if (target.builder !== 'bricks') add(findings, 'warn', 'target.builder should be "bricks".', bindingRel);
    if (target.mode !== 'binding-plan') add(findings, 'warn', 'target.mode should be "binding-plan".', bindingRel);
    const authority = String(target.applyAuthority || '');
    if (!authority) {
      add(findings, 'fail', 'target.applyAuthority is required.', bindingRel);
    } else if (!/(human|review|adapter|trusted|manual)/i.test(authority)) {
      add(findings, 'warn', 'target.applyAuthority should clearly indicate human review or a trusted Kiwe/Bricks adapter.', bindingRel);
    }
    if (/(auto|direct|mutat|write|save|publish)/i.test(authority) && !/(human|review|adapter|trusted)/i.test(authority)) {
      add(findings, 'fail', 'target.applyAuthority must not claim direct write/save/publish authority from the binding plan itself.', bindingRel);
    }
  }

  for (const key of ['queries', 'dynamicFields', 'launchers', 'menuContext', 'assumptions', 'requiresHumanReview']) {
    if (key in binding && !Array.isArray(binding[key])) {
      add(findings, 'fail', `${key} must be an array.`, bindingRel);
    }
  }
}

function validateQueries(binding, findings, bindingRel, index, websiteText) {
  const seen = new Set();
  const queries = asArray(binding.queries);

  for (const [position, query] of queries.entries()) {
    const prefix = `queries[${position}]`;
    if (!isPlainObject(query)) {
      add(findings, 'fail', `${prefix} must be an object.`, bindingRel);
      continue;
    }
    const id = String(query.id || '');
    if (!/^[a-z0-9][a-z0-9_-]*$/.test(id)) {
      add(findings, 'fail', `${prefix}.id must be a stable slug-like id.`, bindingRel);
    } else if (seen.has(id)) {
      add(findings, 'fail', `Duplicate query id "${id}".`, bindingRel);
    } else {
      seen.add(id);
    }
    if (!query.label) add(findings, 'warn', `${prefix}.label is missing.`, bindingRel);
    if (!query.selector) {
      add(findings, 'warn', `${prefix}.selector is missing.`, bindingRel);
    } else if (!selectorAppears(query.selector, websiteText)) {
      add(findings, 'warn', `${prefix}.selector "${query.selector}" was not found in website/bricks-paste.html.`, bindingRel);
    }

    const bricks = isPlainObject(query.bricks) ? query.bricks : null;
    if (!bricks) {
      add(findings, 'fail', `${prefix}.bricks must be an object.`, bindingRel);
      continue;
    }

    const objectType = String(bricks.objectType || '');
    if (!objectType) {
      add(findings, 'fail', `${prefix}.bricks.objectType is required.`, bindingRel);
    } else if (index.queryTypes.size && !index.queryTypes.has(objectType)) {
      add(findings, 'warn', `${prefix}.bricks.objectType "${objectType}" is not present in the supplied Site Graph Bricks query-loop types.`, bindingRel);
    }

    if (objectType === 'post') {
      const postTypes = asArray(bricks.post_type);
      if (!postTypes.length) {
        add(findings, 'warn', `${prefix}.bricks.post_type should be set for post/product loops.`, bindingRel);
      }
      if (index.postTypes.size) {
        for (const postType of postTypes) {
          if (!index.postTypes.has(String(postType))) {
            add(findings, 'warn', `${prefix}.bricks.post_type "${postType}" is not present in the supplied Site Graph public post types.`, bindingRel);
          }
        }
      }
    }

    if ('posts_per_page' in bricks) {
      const count = Number(bricks.posts_per_page);
      if (!Number.isInteger(count) || count < 1 || count > 50) {
        add(findings, 'warn', `${prefix}.bricks.posts_per_page should be an integer between 1 and 50.`, bindingRel);
      }
    }

    for (const field of ['tax_query', 'tax_query_not']) {
      for (const value of asArray(bricks[field])) {
        const text = String(value || '');
        const match = text.match(/^([a-z0-9_-]+)::(\d+)$/i);
        if (!match) {
          add(findings, 'fail', `${prefix}.bricks.${field} value "${text}" must use taxonomy::term_id.`, bindingRel);
          continue;
        }
        const [, taxonomy, termId] = match;
        if (index.taxonomies.size) {
          if (!index.taxonomies.has(taxonomy)) {
            add(findings, 'warn', `${prefix}.bricks.${field} taxonomy "${taxonomy}" is not present in the supplied Site Graph.`, bindingRel);
          } else if (!index.taxonomies.get(taxonomy).has(String(Number(termId)))) {
            add(findings, 'warn', `${prefix}.bricks.${field} term "${text}" is not present in the supplied Site Graph.`, bindingRel);
          }
        }
      }
    }

    if (bricks.queryEditor || bricks.useQueryEditor) {
      add(findings, 'warn', `${prefix} uses Bricks query editor code. Prefer native query-loop settings; put this under requiresHumanReview unless absolutely necessary.`, bindingRel);
    }

    validateDynamicTagValues(query.bindings || {}, `${prefix}.bindings`, findings, bindingRel, index);
  }
}

function validateDynamicTagValues(value, pathLabel, findings, bindingRel, index) {
  const tags = extractDynamicTags(value);
  if (!tags.size) return;
  if (!index.hasGraph) return;

  for (const tag of tags) {
    const base = tag.replace(/\s+@[^}]+/g, '').replace(/:[^}:]+(?=})/g, '');
    const candidates = new Set([tag, base]);
    let found = false;
    for (const candidate of candidates) {
      if (index.dynamicTags.has(candidate)) {
        found = true;
        break;
      }
    }
    if (!found) {
      add(findings, 'warn', `${pathLabel} references dynamic tag ${tag}, which was not found in the supplied Site Graph dynamic tags.`, bindingRel);
    }
  }
}

function validateDynamicFields(binding, findings, bindingRel, index, websiteText) {
  for (const [position, field] of asArray(binding.dynamicFields).entries()) {
    const prefix = `dynamicFields[${position}]`;
    if (!isPlainObject(field)) {
      add(findings, 'fail', `${prefix} must be an object.`, bindingRel);
      continue;
    }
    if (!field.selector) add(findings, 'warn', `${prefix}.selector is missing.`, bindingRel);
    else if (!selectorAppears(field.selector, websiteText)) add(findings, 'warn', `${prefix}.selector "${field.selector}" was not found in website/bricks-paste.html.`, bindingRel);
    if (!field.field) add(findings, 'warn', `${prefix}.field is missing.`, bindingRel);
    if (!field.tag) add(findings, 'warn', `${prefix}.tag is missing.`, bindingRel);
    validateDynamicTagValues(field.tag || '', `${prefix}.tag`, findings, bindingRel, index);
  }
}

function validateLaunchers(binding, findings, bindingRel, index, websiteText) {
  for (const [position, launcher] of asArray(binding.launchers).entries()) {
    const prefix = `launchers[${position}]`;
    if (!isPlainObject(launcher)) {
      add(findings, 'fail', `${prefix} must be an object.`, bindingRel);
      continue;
    }
    if (!launcher.selector) add(findings, 'warn', `${prefix}.selector is missing.`, bindingRel);
    else if (!selectorAppears(launcher.selector, websiteText)) add(findings, 'warn', `${prefix}.selector "${launcher.selector}" was not found in website/bricks-paste.html.`, bindingRel);
    if (launcher.attribute !== 'data-dsa-open-module') {
      add(findings, 'fail', `${prefix}.attribute must be data-dsa-open-module.`, bindingRel);
    }
    const value = String(launcher.value || '');
    if (!value) {
      add(findings, 'fail', `${prefix}.value is required.`, bindingRel);
    } else if (!index.dsaModules.has(value) && !KNOWN_DSA_MODULES.has(value)) {
      add(findings, 'warn', `${prefix}.value "${value}" is not a known Kiwe module in the supplied Site Graph/toolkit vocabulary.`, bindingRel);
    }
  }
}

function validateMenuContext(binding, findings, bindingRel, websiteText) {
  for (const [position, item] of asArray(binding.menuContext).entries()) {
    const prefix = `menuContext[${position}]`;
    if (!isPlainObject(item)) {
      add(findings, 'fail', `${prefix} must be an object.`, bindingRel);
      continue;
    }
    if (!item.label) add(findings, 'warn', `${prefix}.label is missing.`, bindingRel);
    if (item.id && websiteText && !selectorAppears(`#${item.id}`, websiteText)) {
      add(findings, 'warn', `${prefix}.id "${item.id}" was not found in website/bricks-paste.html.`, bindingRel);
    }
    if (item.selector && /hidden|sr-only|display\s*:\s*none/i.test(String(item.selector))) {
      add(findings, 'warn', `${prefix}.selector looks hidden. Menu context should come from visible sections/headings/Seam semantics, not duplicate hidden navigation data.`, bindingRel);
    }
  }
}

function validateReviewDiscipline(binding, findings, bindingRel) {
  const review = asArray(binding.requiresHumanReview);
  const text = JSON.stringify(binding);
  if (/(unknown|guess|placeholder|todo|tbd|not sure|assume)/i.test(text) && review.length === 0) {
    add(findings, 'warn', 'Binding plan contains uncertainty language but requiresHumanReview is empty.', bindingRel);
  }
  if (/(fetch\s*\(|XMLHttpRequest|localStorage|sessionStorage|serviceWorker|checkout session|stripe|razorpay|paypal)/i.test(text)) {
    add(findings, 'warn', 'Binding plan references runtime/payment/storage code. Dynamic bindings must remain a Bricks/Kiwe/Woo plan, not a custom app runtime.', bindingRel);
  }
}

export function validateBindings(target = '.', options = {}) {
  const located = findBindingPath(target);
  const findings = [];
  const root = located.root;

  if (!located.bindingPath) {
    add(findings, options.optional ? 'info' : 'fail', 'No bricks-bindings/kiwe-bindings.json file found.', '');
    return result(root, '', '', findings);
  }

  const bindingRel = rel(root, located.bindingPath);
  const binding = readJson(located.bindingPath, findings, 'kiwe-bindings.json');
  const siteGraphPath = options.siteGraphPath ? path.resolve(options.siteGraphPath) : '';
  const siteGraph = siteGraphPath && fs.existsSync(siteGraphPath) ? readJson(siteGraphPath, findings, 'Site Graph') : null;
  if (options.siteGraphPath && !fs.existsSync(siteGraphPath)) {
    add(findings, 'fail', `Site Graph file not found: ${siteGraphPath}`, '');
  }
  if (!siteGraphPath) {
    add(findings, 'warn', 'No --site-graph supplied. Structural validation ran, but term IDs, post types, dynamic tags, and Bricks query-loop types could not be verified against the target site.', bindingRel);
  } else if (siteGraph && siteGraph.schema !== 'kiwe.site-graph.v1') {
    add(findings, 'fail', 'Site Graph schema must be kiwe.site-graph.v1.', siteGraphPath);
  }

  const website = readWebsiteText(root);
  const index = graphIndex(siteGraph);

  if (binding) {
    validateRoot(binding, findings, bindingRel);
    validateQueries(binding, findings, bindingRel, index, website.text);
    validateDynamicFields(binding, findings, bindingRel, index, website.text);
    validateLaunchers(binding, findings, bindingRel, index, website.text);
    validateMenuContext(binding, findings, bindingRel, website.text);
    validateReviewDiscipline(binding, findings, bindingRel);
  }

  return result(root, located.bindingPath, siteGraphPath, findings);
}

function result(root, bindingPath, siteGraphPath, findings) {
  const counts = findings.reduce((acc, finding) => {
    acc[finding.level] = (acc[finding.level] || 0) + 1;
    return acc;
  }, {});

  return {
    ok: !findings.some((finding) => finding.level === 'fail'),
    root,
    bindingPath,
    siteGraphPath,
    counts,
    findings
  };
}
