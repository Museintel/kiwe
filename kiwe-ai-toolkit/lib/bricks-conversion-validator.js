import fs from 'node:fs';
import path from 'node:path';
import { validateBindings } from './binding-validator.js';
import { officialFrameworkCssVariableNames } from './framework-profile-validator.js';

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
  'html',
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
  '{kiwe_site_logo_inverse}',
  '{kiwe_store_phone_url}',
  '{kiwe_store_email_url}',
  '{kiwe_whatsapp_url}',
  '{kiwe_directions_url}'
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

const KIWE_CAPABILITY_ATTRIBUTES = [
  'data-kiwe-save',
  'data-kiwe-save-id',
  'data-kiwe-save-title',
  'data-kiwe-save-url',
  'data-kiwe-save-image',
  'data-kiwe-notifications',
  'data-kiwe-notification-status-target',
  'data-kiwe-notification-topic',
  'data-dsa-native-notification-request',
  'data-kiwe-theme-toggle',
  'data-kiwe-theme-status-target',
  'data-kiwe-contact',
  'data-kiwe-contact-message',
  'data-kiwe-social',
  'data-kiwe-query-template',
  'data-kiwe-binding'
];

const RESPONSIVE_LAYOUT_KEY_RE = /^_(?:cssCustom|direction|display|grid|gridItem|gridTemplate|gridAuto|align|justify|place|flex|gap|rowGap|columnGap|order|width|widthMin|widthMax|height|heightMin|heightMax|minWidth|maxWidth|minHeight|maxHeight|aspectRatio|margin|padding|position|top|right|bottom|left|zIndex|overflow|masonry)[A-Za-z0-9_]*:[a-z][a-z0-9_-]{1,48}(?::[a-z-]+)?$/i;

const COMPLEX_LAYOUT_RE = /\b(?:bento|campaign-grid|masonry|editorial-grid)\b|grid-template-(?:columns|rows|areas)\s*:|grid-auto-(?:columns|rows|flow)\s*:|grid-column\s*:|grid-row\s*:|@media[\s\S]{0,1600}(?:grid-template|grid-column|grid-row|flex-direction|\.nc-section-head|\.seam-spread)/i;

const BRICKS_LAYOUT_ELEMENT_NAMES = new Set(['container', 'div', 'section', 'block']);

const SEMANTIC_HTML_ELEMENT_NAME_MISUSE = new Set([
  'nav',
  'main',
  'article',
  'aside',
  'header',
  'footer',
  'figure',
  'figcaption',
  'ul',
  'ol',
  'li',
  'a',
  'span',
  'p'
]);

const BRICKS_IMPORT_METHODS = new Set([
  'review-only',
  'bricks-clipboard-json',
  'bricks-admin-template-upload',
  'kiwe-staging-executor'
]);

const NATIVE_STYLE_CONTROL_RE = /^_(?:typography|background|gradient|border|boxShadow|transform|transformOrigin|cssFilters|cssTransition|display|grid(?:Template|Auto|Item)?[A-Za-z0-9_]*|justifyItemsGrid|alignItemsGrid|justifyContentGrid|alignContentGrid|direction|alignSelf|alignItems|justifyContent|flexWrap|flexGrow|flexShrink|flexBasis|columnGap|rowGap|gap|width|widthMin|widthMax|height|heightMin|heightMax|margin|padding|position|top|right|bottom|left|zIndex|overflow|objectFit|objectPosition|opacity|isolation|mixBlendMode|pointerEvents|perspective|perspectiveOrigin|color|textAlign|font|lineHeight|letterSpacing)(?::|$)/;
const MAPPABLE_CSS_DECLARATION_RE = /\b(?:display|flex(?:-direction|-wrap|-grow|-shrink|-basis)?|align-items|align-self|justify-content|justify-items|align-content|gap|row-gap|column-gap|grid-template-columns|grid-template-rows|grid-auto-flow|grid-auto-columns|grid-auto-rows|grid-column|grid-row|width|max-width|min-width|height|max-height|min-height|aspect-ratio|margin(?:-(?:top|right|bottom|left))?|padding(?:-(?:top|right|bottom|left))?|position|top|right|bottom|left|z-index|overflow|opacity|background(?:-color|-image|-size|-position|-repeat)?|color|border(?:-(?:radius|color|width|style))?|box-shadow|font(?:-(?:family|size|weight|style))?|line-height|letter-spacing|text-align|text-transform|transform|filter|transition)\s*:/gi;
const TOKEN_OWNED_NATIVE_CONTROL_RE = /^_(?:typography|border|boxShadow|transform|grid(?:Template|Auto|Item)?[A-Za-z0-9_]*|columnGap|rowGap|gap|width|widthMin|widthMax|height|heightMin|heightMax|margin|padding|top|right|bottom|left|font|lineHeight|letterSpacing)(?::|$)/;
const TOKEN_OWNED_NESTED_KEY_RE = /^(?:font-size|fontSize|line-height|lineHeight|letter-spacing|letterSpacing|top|right|bottom|left|width|height|widthMin|widthMax|heightMin|heightMax|minWidth|maxWidth|minHeight|maxHeight|radius|offsetX|offsetY|blur|spread|translateX|translateY|translateZ|x|y|gap|rowGap|columnGap)$/i;
const LITERAL_LENGTH_RE = /-?(?:\d*\.)?\d+(?:px|rem|em|ch|ex|cap|ic|lh|rlh|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc)\b/i;
const SELF_CLAMP_LENGTH_RE = /clamp\(\s*(-?(?:\d*\.)?\d+(?:px|rem|em|ch|ex|cap|ic|lh|rlh|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc)\b)\s*,\s*\1\s*,\s*\1\s*\)/i;
const TOKEN_FINDING_LIMIT = 40;
const TOKEN_OWNED_COLOR_CONTROL_RE = /^_(?:typography|background|gradient|border|boxShadow|cssFilters|color|fill|stroke|cssCustom)(?::|$)/;
const TOKEN_OWNED_COLOR_NESTED_KEY_RE = /^(?:color|background|backgroundColor|background-color|backgroundImage|background-image|gradient|raw|hex|rgb|hsl|hue|saturation|lightness|fill|stroke|borderColor|border-color|shadowColor|shadow-color)$/i;
const COLOR_LITERAL_RE = /#[0-9a-fA-F]{3,8}\b|\b(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color)\s*\([^)]*\)|(?<![-\w])(?:white|black)(?![-\w])/gi;
const COLOR_FINDING_LIMIT = 40;
const CSS_VAR_FINDING_LIMIT = 40;

const CUSTOM_CSS_HEAVY_BYTES = 12000;
const CUSTOM_CSS_NATIVE_STYLE_MIN_CONTROLS = 60;
const MAPPABLE_CSS_DECLARATION_MIN = 40;
const MAPPABLE_CSS_NATIVE_STYLE_RATIO = 0.45;
const LARGE_CLIPBOARD_ELEMENT_COUNT = 180;
const TEMPLATE_UPLOAD_CUSTOM_CSS_BYTES = 2500;
const TEMPLATE_UPLOAD_MAPPABLE_CSS_DECLARATION_MIN = 12;
const TEMPLATE_UPLOAD_MIN_ELEMENT_NATIVE_CONTROLS_PER_ELEMENT = 1.15;
const TEMPLATE_UPLOAD_MAX_CLASS_ONLY_ELEMENT_RATIO = 0.25;
const SUPPORTED_TEMPLATE_BRICKS_VERSION_RE = /^2\.3(?:\.|$)/;
const REVIEW_ONLY_CODE_ELEMENT_ALLOWANCE_RE = /\b(?:review-only|manual-review|unsupported|code-exception)\b/i;
const TEMPLATE_UPLOAD_SAFE_CLASS_PREFIX_RE = /^(?:kiwe|seam|dsa|sf|nc|bv|bio|appsite)-/i;
const BRICKS_COMPILE_UNSAFE_CONTROL_RE = /^_(?:minWidth|maxWidth|minHeight|maxHeight)(?::|$)/;
const BRICKS_FONT_FAMILY_TOKEN_RE = /var\(\s*--/i;
const SEMANTIC_HEADING_TAG_RE = /^h[1-6]$/i;
const SEMANTIC_HEADING_TYPE_TOKEN_RE = /var\(\s*--(?:kiwe|seam)-type-h[1-6]\b/i;
const OFFICIAL_FRAMEWORK_CSS_VARIABLES = officialFrameworkCssVariableNames();
const TEMPLATE_UPLOAD_GENERIC_CLASS_ALLOWLIST = new Set([
  'is-active',
  'is-current',
  'is-disabled',
  'is-loading',
  'is-empty',
  'is-hidden'
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

function isCollisionSafeTemplateClassName(name) {
  const value = String(name || '').trim();
  if (!value) return true;
  if (TEMPLATE_UPLOAD_GENERIC_CLASS_ALLOWLIST.has(value)) return true;
  return TEMPLATE_UPLOAD_SAFE_CLASS_PREFIX_RE.test(value);
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

function resolvePackageFile(root, relPath) {
  const rootPath = path.resolve(root || '.');
  const fullPath = path.resolve(rootPath, String(relPath || ''));
  return fullPath === rootPath || fullPath.startsWith(`${rootPath}${path.sep}`) ? fullPath : '';
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
    path.join(root, 'framework', 'FRAMEWORK-NOTES.md'),
    path.join(root, 'README.md'),
    path.join(root, 'LOCAL-VALIDATION.json'),
    path.join(root, 'BRICKS-CONVERSION-AUDIT.md'),
    path.join(root, 'BRICKS-CONVERSION-AUDIT.json'),
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

function extractCapabilityAttributes(html) {
  const out = new Map();
  const names = KIWE_CAPABILITY_ATTRIBUTES.map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
  const pattern = new RegExp(`\\b(${names})(?:\\s*=\\s*["']([^"']*)["'])?`, 'gi');
  for (const match of String(html || '').matchAll(pattern)) {
    const name = String(match[1] || '').trim();
    const value = String(match[2] || '').trim();
    const key = `${name}=${value}`;
    if (name) out.set(key, { name, value });
  }
  return Array.from(out.values());
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

function collectResponsiveLayoutOverrides(elements) {
  const overrides = [];
  for (const element of elements) {
    const settings = isPlainObject(element.settings) ? element.settings : {};
    for (const [key, value] of Object.entries(settings)) {
      if (RESPONSIVE_LAYOUT_KEY_RE.test(key)) {
        overrides.push({
          element,
          key,
          value,
          classes: String(settings._cssClasses || ''),
          cssId: String(settings._cssId || '')
        });
      }
    }
  }
  return overrides;
}

function hasComplexLayoutEvidence(text) {
  return COMPLEX_LAYOUT_RE.test(String(text || ''));
}

function fidelityMentions(fidelity, pattern) {
  return pattern.test(JSON.stringify(fidelity || {}));
}

function isBricksLayoutElement(element) {
  return BRICKS_LAYOUT_ELEMENT_NAMES.has(String(element && element.name || '').toLowerCase());
}

function elementSettings(element) {
  return isPlainObject(element && element.settings) ? element.settings : {};
}

function collectCustomCssBuckets(value, out = [], trail = '$') {
  if (Array.isArray(value)) {
    value.forEach((item, index) => collectCustomCssBuckets(item, out, `${trail}[${index}]`));
  } else if (isPlainObject(value)) {
    for (const [key, item] of Object.entries(value)) {
      const nextTrail = `${trail}.${key}`;
      if ((/^_cssCustom(?::|$)/.test(key) || key === 'customCss') && typeof item === 'string' && item.trim()) {
        out.push({ path: nextTrail, text: item });
      }
      collectCustomCssBuckets(item, out, nextTrail);
    }
  }
  return out;
}

function collectNativeStyleControlsFromItems(items) {
  const controls = [];
  for (const element of asArray(items)) {
    const settings = elementSettings(element);
    for (const key of Object.keys(settings)) {
      if (NATIVE_STYLE_CONTROL_RE.test(key) && !/^_cssCustom(?::|$)/.test(key)) {
        controls.push({ element, key });
      }
    }
  }
  return controls;
}

function collectStyledTemplateGlobalClasses(globalClasses) {
  return asArray(globalClasses)
    .filter((globalClass) => countNativeStyleControlsOnItem(globalClass) > 0)
    .map((globalClass) => ({
      id: String(globalClass.id || ''),
      name: String(globalClass.name || ''),
      controls: countNativeStyleControlsOnItem(globalClass)
    }));
}

function countNativeStyleControlsOnItem(item) {
  const settings = elementSettings(item);
  let count = 0;
  for (const key of Object.keys(settings)) {
    if (NATIVE_STYLE_CONTROL_RE.test(key) && !/^_cssCustom(?::|$)/.test(key)) count += 1;
  }
  return count;
}

function collectTemplateEditabilityStats(templateElements) {
  const elements = asArray(templateElements);
  const elementNativeControls = elements.reduce((sum, element) => sum + countNativeStyleControlsOnItem(element), 0);
  const classOnlyElements = elements.filter((element) => {
    const settings = elementSettings(element);
    return countNativeStyleControlsOnItem(element) === 0 && asArray(settings._cssGlobalClasses).length > 0;
  }).length;
  const elementCount = elements.length;
  return {
    elementCount,
    elementNativeControls,
    classOnlyElements,
    elementNativeControlsPerElement: elementCount ? elementNativeControls / elementCount : 0,
    classOnlyElementRatio: elementCount ? classOnlyElements / elementCount : 0
  };
}

function hasLiteralLength(value) {
  return typeof value === 'string' && LITERAL_LENGTH_RE.test(value);
}

function collectDeclaredCssVariables(value, out = new Set()) {
  if (Array.isArray(value)) {
    value.forEach((item) => collectDeclaredCssVariables(item, out));
    return out;
  }

  if (!isPlainObject(value)) return out;

  const rawName = value.name || value.variable || value.key || value.id;
  if (typeof rawName === 'string') {
    const clean = rawName.trim().replace(/^--/, '');
    if (/^(?:kiwe|seam|[a-z][a-z0-9]*)-[a-z0-9][a-z0-9-]*$/i.test(clean)) {
      out.add(clean);
      out.add(`--${clean}`);
    }
  }

  for (const item of Object.values(value)) collectDeclaredCssVariables(item, out);
  return out;
}

function collectRuntimeCodeElements(items) {
  const findings = [];
  asArray(items).forEach((item, index) => {
    if (!isPlainObject(item)) return;
    if (String(item.name || '').toLowerCase() !== 'code') return;

    const settings = elementSettings(item);
    const label = String(item.id || item.label || item.name || `item-${index}`);
    const reviewText = JSON.stringify({
      classes: settings._cssClasses || '',
      attributes: settings._attributes || [],
      kiwe: item.kiwe || {}
    });
    const hasReviewOnlyAllowance = REVIEW_ONLY_CODE_ELEMENT_ALLOWANCE_RE.test(reviewText);
    const runtimeKeys = [];

    for (const [key, value] of Object.entries(settings)) {
      const keyName = String(key);
      if (/^(?:code|css|cssCode|javascriptCode|js|html|php|executeCode)$/i.test(keyName) && String(value || '').trim() !== '') {
        runtimeKeys.push(keyName);
      }
      if (keyName === 'executeCode' && value === true) {
        runtimeKeys.push(keyName);
      }
    }

    if (runtimeKeys.length && !hasReviewOnlyAllowance) {
      findings.push({
        label,
        keys: Array.from(new Set(runtimeKeys)),
        path: `$.content/header/footer[${index}].settings`,
      });
    }
  });
  return findings;
}

function collectTemplateVariableNameFindings(templateData) {
  const findings = [];
  for (const lane of ['global_variables', 'globalVariables']) {
    const variables = asArray(templateData?.[lane]);
    variables.forEach((variable, index) => {
      if (!isPlainObject(variable)) return;
      const name = String(variable.name || '').trim();
      if (name.startsWith('--')) {
        findings.push({
          lane,
          index,
          name,
          path: `$.${lane}[${index}].name`
        });
      }
      const value = String(variable.value || '').trim();
      for (const call of collectCssVariableCalls(value)) {
        if (!call.hasFallback) continue;
        findings.push({
          lane,
          index,
          name,
          variable: call.name,
          value,
          path: `$.${lane}[${index}].value`,
          type: 'variable-value-has-fallback'
        });
      }
    });
  }
  return findings;
}

function collectBricksCompilerUnsafeControls(items) {
  const findings = [];
  asArray(items).forEach((item, index) => {
    const settings = elementSettings(item);
    const label = String(item?.id || item?.name || item?.label || `item-${index}`);
    const isSemanticHeading = String(item?.name || '').toLowerCase() === 'heading' && SEMANTIC_HEADING_TAG_RE.test(String(settings.tag || ''));
    for (const [key, value] of Object.entries(settings)) {
      if (BRICKS_COMPILE_UNSAFE_CONTROL_RE.test(key)) {
        findings.push({
          type: 'unsupported-control',
          label,
          key,
          value,
          path: `$.content/header/footer/global_classes[${index}].settings.${key}`
        });
      }
      if ((key === '_typography' || /^_typography:/.test(key)) && isPlainObject(value)) {
        const fontSize = value['font-size'] ?? value.fontSize ?? value.font_size;
        if (isSemanticHeading && typeof fontSize === 'string' && SEMANTIC_HEADING_TYPE_TOKEN_RE.test(fontSize)) {
          findings.push({
            type: 'semantic-heading-font-size-lock',
            label,
            key,
            value: fontSize,
            tag: String(settings.tag || ''),
            path: `$.content/header/footer/global_classes[${index}].settings.${key}.font-size`
          });
        }
        const fontFamily = value['font-family'] ?? value.fontFamily ?? value.font_family;
        if (typeof fontFamily === 'string' && BRICKS_FONT_FAMILY_TOKEN_RE.test(fontFamily)) {
          findings.push({
            type: 'font-family-token',
            label,
            key,
            value: fontFamily,
            path: `$.content/header/footer/global_classes[${index}].settings.${key}.font-family`
          });
        }
      }
      if ((key === '_background' || /^_background:/.test(key)) && isPlainObject(value) && typeof value.color === 'string') {
        findings.push({
          type: 'color-shape',
          label,
          key,
          value: value.color,
          path: `$.content/header/footer/global_classes[${index}].settings.${key}.color`,
          expected: '_background.color.raw'
        });
      }
      if ((key === '_background' || /^_background:/.test(key)) && isPlainObject(value) && isPlainObject(value.color) && typeof value.color.raw === 'string' && /gradient\(/i.test(value.color.raw)) {
        findings.push({
          type: 'background-gradient-color',
          label,
          key,
          value: value.color.raw,
          path: `$.content/header/footer/global_classes[${index}].settings.${key}.color.raw`,
          expected: '_gradient'
        });
      }
      if ((key === '_border' || /^_border:/.test(key)) && isPlainObject(value) && typeof value.color === 'string') {
        findings.push({
          type: 'color-shape',
          label,
          key,
          value: value.color,
          path: `$.content/header/footer/global_classes[${index}].settings.${key}.color`,
          expected: '_border.color.raw'
        });
      }
      if ((key === '_border' || /^_border:/.test(key)) && isPlainObject(value) && isPlainObject(value.radius)) {
        const invalidRadiusKeys = ['topLeft', 'topRight', 'bottomRight', 'bottomLeft'].filter((radiusKey) =>
          Object.prototype.hasOwnProperty.call(value.radius, radiusKey)
        );
        if (invalidRadiusKeys.length) {
          findings.push({
            type: 'radius-shape',
            label,
            key,
            value: invalidRadiusKeys.join(', '),
            path: `$.content/header/footer/global_classes[${index}].settings.${key}.radius`,
            expected: '_border.radius.top/right/bottom/left'
          });
        }
      }
      if ((key === '_typography' || /^_typography:/.test(key)) && isPlainObject(value) && typeof value.color === 'string') {
        findings.push({
          type: 'color-shape',
          label,
          key,
          value: value.color,
          path: `$.content/header/footer/global_classes[${index}].settings.${key}.color`,
          expected: '_typography.color.raw'
        });
      }
    }
  });
  return findings;
}

function settingHasAttribute(settings, name, valuePattern = null) {
  const wanted = String(name || '').toLowerCase();
  return asArray(settings && settings._attributes).some((attribute) => {
    if (!isPlainObject(attribute)) return false;
    if (String(attribute.name || '').toLowerCase() !== wanted) return false;
    return valuePattern ? valuePattern.test(String(attribute.value || '')) : true;
  });
}

function collectImplicitBricksLayoutControls(items) {
  const findings = [];
  asArray(items).forEach((item, index) => {
    if (!isPlainObject(item)) return;
    const settings = elementSettings(item);
    const label = String(item.id || item.name || item.label || `item-${index}`);
    const classes = String(settings._cssClasses || '');
    const isLayout = isBricksLayoutElement(item);
    const display = String(settings._display || '').toLowerCase();
    const isRail = /\bseam-horizontal-rail\b/.test(classes) || settingHasAttribute(settings, 'data-flow', /^horizontal-rail$/i);

    if (isLayout && display === 'flex' && !Object.prototype.hasOwnProperty.call(settings, '_direction')) {
      findings.push({
        type: 'missing-flex-direction',
        label,
        path: `$.content/header/footer/global_classes[${index}].settings._direction`
      });
    }

    if (isLayout && display === 'grid' && !(Array.isArray(item.children) && item.children.length === 0)) {
      const hasColumns = Object.keys(settings).some((key) => /^_grid(?:TemplateColumns|AutoColumns)(?::|$)/.test(key));
      if (!hasColumns) {
        findings.push({
          type: 'missing-grid-columns',
          label,
          path: `$.content/header/footer/global_classes[${index}].settings._gridTemplateColumns`
        });
      }
    }

    if (isRail) {
      if (display !== 'flex') {
        findings.push({
          type: 'rail-missing-flex-display',
          label,
          path: `$.content/header/footer/global_classes[${index}].settings._display`
        });
      }
      if (String(settings._direction || '').toLowerCase() !== 'row') {
        findings.push({
          type: 'rail-missing-row-direction',
          label,
          path: `$.content/header/footer/global_classes[${index}].settings._direction`
        });
      }
      if (!/(auto|scroll)/i.test(String(settings._overflow || ''))) {
        findings.push({
          type: 'rail-missing-overflow',
          label,
          path: `$.content/header/footer/global_classes[${index}].settings._overflow`
        });
      }
      const hasGap = Object.prototype.hasOwnProperty.call(settings, '_columnGap') || Object.prototype.hasOwnProperty.call(settings, '_gap');
      if (!hasGap) {
        findings.push({
          type: 'rail-missing-gap',
          label,
          path: `$.content/header/footer/global_classes[${index}].settings._columnGap`
        });
      }
    }
  });
  return findings;
}

function usesDeclaredProjectVariable(value, declaredVariables = new Set()) {
  if (typeof value !== 'string') return false;
  for (const match of value.matchAll(/var\(\s*--([a-z][a-z0-9]*-[a-z0-9][a-z0-9-]*)/gi)) {
    const name = String(match[1] || '').trim();
    if (isOfficialFrameworkVariable(`--${name}`)) return true;
    if (declaredVariables.has(name) || declaredVariables.has(`--${name}`)) return true;
  }
  return false;
}

function extractCssFunctionCalls(value, functionName) {
  const text = String(value || '');
  const lower = text.toLowerCase();
  const needle = `${String(functionName).toLowerCase()}(`;
  const calls = [];
  let index = 0;
  while ((index = lower.indexOf(needle, index)) !== -1) {
    let depth = 0;
    let end = -1;
    for (let i = index; i < text.length; i += 1) {
      if (text[i] === '(') depth += 1;
      if (text[i] === ')') {
        depth -= 1;
        if (depth === 0) {
          end = i;
          break;
        }
      }
    }
    if (end === -1) break;
    calls.push(text.slice(index + needle.length, end));
    index = end + 1;
  }
  return calls;
}

function splitCssArgs(value) {
  const text = String(value || '');
  const args = [];
  let depth = 0;
  let start = 0;
  for (let i = 0; i < text.length; i += 1) {
    if (text[i] === '(') depth += 1;
    if (text[i] === ')') depth -= 1;
    if (text[i] === ',' && depth === 0) {
      args.push(text.slice(start, i).trim());
      start = i + 1;
    }
  }
  args.push(text.slice(start).trim());
  return args;
}

function collectCssVariableCalls(value) {
  return extractCssFunctionCalls(value, 'var').map((call) => {
    const args = splitCssArgs(call);
    const name = String(args[0] || '').trim();
    return {
      name,
      hasFallback: args.length >= 2 && args.slice(1).join(',').trim() !== ''
    };
  }).filter((item) => /^--[a-z][a-z0-9_-]*$/i.test(item.name));
}

function normalizeCssVariableName(name) {
  const clean = String(name || '').trim().replace(/^--/, '');
  return /^[a-z][a-z0-9_-]*$/i.test(clean) ? `--${clean}` : '';
}

function isReservedFrameworkVariableName(name) {
  return /^--(?:kiwe|seam)-/i.test(String(name || ''));
}

function isOfficialFrameworkVariable(name) {
  const normalized = normalizeCssVariableName(name).toLowerCase();
  return normalized ? OFFICIAL_FRAMEWORK_CSS_VARIABLES.has(normalized) : false;
}

function usesOfficialFrameworkVariable(value) {
  return collectCssVariableCalls(value).some((call) => isOfficialFrameworkVariable(call.name));
}

function collectNativeOwnedCssVariableCalls(value, out = [], trail = '$', parentOwned = false) {
  if (Array.isArray(value)) {
    value.forEach((item, index) => collectNativeOwnedCssVariableCalls(item, out, `${trail}[${index}]`, parentOwned));
    return out;
  }

  if (isPlainObject(value)) {
    for (const [key, item] of Object.entries(value)) {
      const owned = parentOwned || NATIVE_STYLE_CONTROL_RE.test(String(key || '')) || TOKEN_OWNED_NESTED_KEY_RE.test(String(key || '')) || TOKEN_OWNED_COLOR_NESTED_KEY_RE.test(String(key || ''));
      collectNativeOwnedCssVariableCalls(item, out, `${trail}.${key}`, owned);
    }
    return out;
  }

  if (!parentOwned || typeof value !== 'string') return out;

  for (const call of collectCssVariableCalls(value)) {
    const variable = normalizeCssVariableName(call.name);
    if (variable) out.push({ path: trail, value: String(value), variable, hasFallback: call.hasFallback });
  }

  return out;
}

function collectTemplateDeclaredVariableNames(templateData) {
  const names = new Set();
  for (const lane of ['global_variables', 'globalVariables']) {
    asArray(templateData?.[lane]).forEach((variable) => {
      if (!isPlainObject(variable)) return;
      const normalized = normalizeCssVariableName(variable.name || variable.variable || variable.key || variable.id);
      if (normalized) names.add(normalized);
    });
  }
  return names;
}

function collectFrameworkProjectVariableNamesFromProfile(profile) {
  const names = new Set();
  const project = profile?.settings?.tokens?.project || profile?.tokens?.project || profile?.project || {};
  asArray(project?.variables).forEach((variable) => {
    if (!isPlainObject(variable)) return;
    const normalized = normalizeCssVariableName(variable.name || variable.variable || variable.key || variable.id);
    if (normalized) names.add(normalized);
  });
  return names;
}

function collectFrameworkProjectVariableNamesFromMetadata(metadata) {
  const names = new Set();
  const framework = metadata?.kiwe?.frameworkProfile || metadata?.frameworkProfile || {};
  for (const key of ['projectVariables', 'variables', 'requiredVariables']) {
    asArray(framework?.[key]).forEach((variable) => {
      const normalized = normalizeCssVariableName(isPlainObject(variable) ? (variable.name || variable.variable || variable.key || variable.id) : variable);
      if (normalized) names.add(normalized);
    });
  }
  return names;
}

function collectFrameworkProjectVariableProof(root, templateData) {
  const names = collectFrameworkProjectVariableNamesFromMetadata(templateData);
  const candidatePaths = [];
  const metaPath = templateData?.kiwe?.frameworkProfile?.path || templateData?.frameworkProfile?.path;
  if (typeof metaPath === 'string' && metaPath.trim()) candidatePaths.push(metaPath.trim());
  candidatePaths.push('framework/kiwe-framework-profile.json', 'kiwe-framework-profile.json');

  for (const candidate of candidatePaths) {
    const profilePath = resolvePackageFile(root, candidate);
    if (!profilePath || !fs.existsSync(profilePath) || !fs.statSync(profilePath).isFile()) continue;
    try {
      const profile = JSON.parse(fs.readFileSync(profilePath, 'utf8'));
      collectFrameworkProjectVariableNamesFromProfile(profile).forEach((name) => names.add(name));
    } catch {
      // The main JSON reader reports canonical JSON errors elsewhere. This proof
      // collector stays non-fatal and lets the missing-proof failure explain the
      // actionable Bricks import problem.
    }
  }

  return names;
}

function validateProjectVariableFrameworkProof(root, templateData, templateStyleItems, findings, file) {
  const projectUsage = [];
  const unknownReservedUsage = [];
  asArray(templateStyleItems).forEach((item, index) => {
    if (!isPlainObject(item)) return;
    const label = item.id || item.name || item.label || `item-${index}`;
    const values = collectNativeOwnedCssVariableCalls(elementSettings(item), [], `$.content/header/footer/global_classes[${index}].settings`, false);
    values.forEach((value) => {
      if (isOfficialFrameworkVariable(value.variable)) return;
      if (isReservedFrameworkVariableName(value.variable)) {
        unknownReservedUsage.push({ label, ...value });
        return;
      }
      projectUsage.push({ label, ...value });
    });
  });

  const unknownReserved = [...new Set(unknownReservedUsage.map((item) => item.variable))].sort();
  if (unknownReserved.length) {
    const firstUse = unknownReservedUsage.find((item) => unknownReserved.includes(item.variable));
    add(
      findings,
      'fail',
      `Bricks template uses ${unknownReserved.length} reserved-looking Framework variable(s) that are not in the Kiwe universal token registry: ${unknownReserved.slice(0, 20).join(', ')}${unknownReserved.length > 20 ? ', ...' : ''}. Do not invent --kiwe-* or --seam-* variables. Map to an existing official token, declare a collision-safe project variable such as --nc-*, or formally add the new token to Kiwe's universal registry before SEAM Compiler validation can pass.`,
      file,
      firstUse?.path || '$.content'
    );
  }

  const required = [...new Set(projectUsage.map((item) => item.variable))].sort();
  if (!required.length) return;

  const proof = collectFrameworkProjectVariableProof(root, templateData);
  const missing = required.filter((name) => !proof.has(name));
  if (!missing.length) return;

  const templateDeclared = collectTemplateDeclaredVariableNames(templateData);
  const templateOnly = missing.filter((name) => templateDeclared.has(name));
  const firstUse = projectUsage.find((item) => missing.includes(item.variable));
  add(
    findings,
    'fail',
    `Bricks template consumes ${required.length} project CSS variable(s) in native element controls, but Framework-profile proof is missing for ${missing.length}: ${missing.slice(0, 20).join(', ')}${missing.length > 20 ? ', ...' : ''}. ${templateOnly.length ? `These variable(s) appear only in the template globalVariables lane (${templateOnly.slice(0, 12).join(', ')}${templateOnly.length > 12 ? ', ...' : ''}), but Bricks My Templates import does not reliably install template-local globalVariables into the site variable manager. ` : ''}SEAM Compiler must pair project variables with Kiwe > Framework profile output/push proof, or use only official --kiwe-/--seam- variables already installed by the Framework.`,
    file,
    firstUse?.path || '$.content'
  );
}

function validateSelfContainedNativeValues(templateStyleItems, findings, file) {
  const unresolved = [];
  asArray(templateStyleItems).forEach((item, index) => {
    if (!isPlainObject(item)) return;
    const label = item.id || item.name || item.label || `item-${index}`;
    collectNativeOwnedCssVariableCalls(elementSettings(item), [], `$.content/header/footer/global_classes[${index}].settings`, false)
      .filter((value) => !isOfficialFrameworkVariable(value.variable))
      .forEach((value) => unresolved.push({ label, ...value }));
  });
  unresolved.slice(0, CSS_VAR_FINDING_LIMIT).forEach((item) => {
    add(
      findings,
      'fail',
      `Raw self-contained Bricks template still references CSS variable "${item.variable}" in native style "${item.path}" on "${item.label}". The Convert stage must resolve source/local variables to literal native Bricks values; variable consumption belongs to the separate Seam Framework stage.`,
      file,
      item.path
    );
  });
  if (unresolved.length > CSS_VAR_FINDING_LIMIT) {
    add(findings, 'fail', `Raw self-contained Bricks template contains ${unresolved.length - CSS_VAR_FINDING_LIMIT} additional unresolved native CSS-variable references.`, file, '$.content');
  }
}

function collectCssVariablesWithFallback(value, out = [], trail = '$', parentOwned = false) {
  if (Array.isArray(value)) {
    value.forEach((item, index) => collectCssVariablesWithFallback(item, out, `${trail}[${index}]`, parentOwned));
    return out;
  }

  if (isPlainObject(value)) {
    for (const [key, item] of Object.entries(value)) {
      const owned = parentOwned || NATIVE_STYLE_CONTROL_RE.test(String(key || '')) || TOKEN_OWNED_NESTED_KEY_RE.test(String(key || '')) || TOKEN_OWNED_COLOR_NESTED_KEY_RE.test(String(key || ''));
      collectCssVariablesWithFallback(item, out, `${trail}.${key}`, owned);
    }
    return out;
  }

  if (!parentOwned || typeof value !== 'string') return out;

  for (const call of collectCssVariableCalls(value)) {
    if (call.hasFallback) {
      out.push({ path: trail, value: String(value), variable: call.name });
    }
  }

  return out;
}

function validateCssVariableFallbacks(items, findings, file, pathPointer) {
  const findingsToAdd = [];
  asArray(items).forEach((item, index) => {
    if (!isPlainObject(item)) return;
    const label = item.id || item.name || item.label || `item-${index}`;
    const settings = elementSettings(item);
    const values = collectCssVariablesWithFallback(settings, [], `${pathPointer}[${index}].settings`, false);
    for (const value of values) findingsToAdd.push({ label, ...value });
  });

  findingsToAdd.slice(0, CSS_VAR_FINDING_LIMIT).forEach((item) => {
    add(
      findings,
      'fail',
      `Bricks native style "${item.path}" on "${item.label}" references "${item.variable}" with an inline fallback in "${item.value}". SeamFlow template render-owner settings must consume bare Framework/project variables only, e.g. var(${item.variable}). Put the actual value in the paired Kiwe Framework profile / Bricks variable push so missing profile setup fails visibly instead of silently rendering from hidden fallback values.`,
      file,
      item.path
    );
  });
  if (findingsToAdd.length > CSS_VAR_FINDING_LIMIT) {
    add(
      findings,
      'fail',
      `Bricks native styles contain ${findingsToAdd.length - CSS_VAR_FINDING_LIMIT} additional CSS variable references with inline fallbacks beyond the first ${CSS_VAR_FINDING_LIMIT}. Remove fallbacks from Bricks render-owner settings and define those values in the paired Framework profile, then rerun /audit /bricksconversion.`,
      file,
      pathPointer
    );
  }
}

function parseSimpleCssLength(value) {
  const match = String(value || '').trim().match(/^(-?(?:\d+|\d*\.\d+))(px|rem|em|ch|ex|cap|ic|lh|rlh|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc)$/i);
  return match ? { value: Number(match[1]), unit: match[2].toLowerCase() } : null;
}

function isValidKiweFluidClampArgs(args) {
  if (!Array.isArray(args) || args.length !== 3) return false;
  const min = parseSimpleCssLength(args[0]);
  const max = parseSimpleCssLength(args[2]);
  if (!min || !max || min.unit !== max.unit || min.value === max.value) return false;
  const unit = min.unit.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const preferred = String(args[1] || '').trim();
  return new RegExp(`^calc\\(\\s*-?(?:\\d+|\\d*\\.\\d+)${unit}\\s*[+-]\\s*-?(?:\\d+|\\d*\\.\\d+)vw\\s*\\)$`, 'i').test(preferred);
}

function hasValidKiweFluidClamp(value) {
  return extractCssFunctionCalls(value, 'clamp').some((call) => isValidKiweFluidClampArgs(splitCssArgs(call)));
}

function cssFunctionRanges(value, functionName) {
  const text = String(value || '');
  const lower = text.toLowerCase();
  const needle = `${String(functionName).toLowerCase()}(`;
  const ranges = [];
  let index = 0;
  while ((index = lower.indexOf(needle, index)) !== -1) {
    let depth = 0;
    let end = -1;
    for (let i = index; i < text.length; i += 1) {
      if (text[i] === '(') depth += 1;
      if (text[i] === ')') {
        depth -= 1;
        if (depth === 0) {
          end = i;
          break;
        }
      }
    }
    if (end === -1) break;
    ranges.push({ start: index, end });
    index = end + 1;
  }
  return ranges;
}

function indexInsideRanges(index, ranges) {
  return ranges.some((range) => index >= range.start && index <= range.end);
}

function collectDirectColorLiterals(value) {
  if (typeof value !== 'string') return [];
  const varRanges = cssFunctionRanges(value, 'var');
  const literals = [];
  COLOR_LITERAL_RE.lastIndex = 0;
  let match;
  while ((match = COLOR_LITERAL_RE.exec(value))) {
    const literal = String(match[0] || '').trim();
    if (!literal) continue;
    if (indexInsideRanges(match.index, varRanges)) continue;
    literals.push(literal);
  }
  return literals;
}

function colorOwnedChild(parentOwned, key) {
  return parentOwned || TOKEN_OWNED_COLOR_CONTROL_RE.test(String(key || '')) || TOKEN_OWNED_COLOR_NESTED_KEY_RE.test(String(key || ''));
}

function collectUntokenizedNativeColorValues(value, out = [], trail = '$', parentOwned = false) {
  if (Array.isArray(value)) {
    value.forEach((item, index) => collectUntokenizedNativeColorValues(item, out, `${trail}[${index}]`, parentOwned));
    return out;
  }

  if (isPlainObject(value)) {
    for (const [key, item] of Object.entries(value)) {
      const owned = colorOwnedChild(parentOwned, key);
      collectUntokenizedNativeColorValues(item, out, `${trail}.${key}`, owned);
    }
    return out;
  }

  if (parentOwned && typeof value === 'string') {
    const literals = collectDirectColorLiterals(value);
    if (literals.length) {
      out.push({ path: trail, value: String(value), literals });
    }
  }
  return out;
}

function validateTokenizedNativeColors(items, findings, file, pathPointer) {
  const findingsToAdd = [];
  asArray(items).forEach((item, index) => {
    if (!isPlainObject(item)) return;
    const label = item.id || item.name || item.label || `item-${index}`;
    const settings = elementSettings(item);
    const values = collectUntokenizedNativeColorValues(settings, [], `${pathPointer}[${index}].settings`, false);
    for (const value of values) {
      findingsToAdd.push({ label, ...value });
    }
  });

  findingsToAdd.slice(0, COLOR_FINDING_LIMIT).forEach((item) => {
    add(
      findings,
      'fail',
      `Bricks native style "${item.path}" on "${item.label}" uses direct color literal(s) "${item.literals.join(', ')}". Framework-mode SEAM Compiler output must be fully token integrated: component colors, backgrounds, gradients, borders, shadows, fills, and local CSS variables must consume bare var(--kiwe-*), var(--seam-*), or declared project variables from the Framework profile/globalVariables. Literal colors are allowed at the Framework/global-variable definition layer, but not as direct component styling, CSS-variable fallbacks, color: #fff, or --pack-bg: #f5b942.`,
      file,
      item.path
    );
  });
  if (findingsToAdd.length > COLOR_FINDING_LIMIT) {
    add(
      findings,
      'fail',
      `Bricks native styles contain ${findingsToAdd.length - COLOR_FINDING_LIMIT} additional untokenized direct color values beyond the first ${COLOR_FINDING_LIMIT}. Fix with official Kiwe/Seam tokens or declared project variables, then rerun /audit /bricksconversion.`,
      file,
      pathPointer
    );
  }
}

function hasNoOpClamp(value) {
  return SELF_CLAMP_LENGTH_RE.test(value) || extractCssFunctionCalls(value, 'clamp').some((call) => {
    const args = splitCssArgs(call);
    if (args.length !== 3) return false;
    if (args[0] === args[1] && args[1] === args[2]) return true;
    const min = parseSimpleCssLength(args[0]);
    const max = parseSimpleCssLength(args[2]);
    return Boolean(min && max && min.unit === max.unit && min.value === max.value);
  });
}

function hasTokenizedLength(value, declaredVariables = new Set()) {
  if (typeof value !== 'string') return false;
  if (hasNoOpClamp(value)) return false;
  return usesOfficialFrameworkVariable(value) || usesDeclaredProjectVariable(value, declaredVariables) || hasValidKiweFluidClamp(value);
}

function tokenOwnedChild(parentOwned, key) {
  return parentOwned || TOKEN_OWNED_NATIVE_CONTROL_RE.test(String(key || '')) || TOKEN_OWNED_NESTED_KEY_RE.test(String(key || ''));
}

function collectUntokenizedNativeLengthValues(value, out = [], trail = '$', parentOwned = false, declaredVariables = new Set()) {
  if (Array.isArray(value)) {
    value.forEach((item, index) => collectUntokenizedNativeLengthValues(item, out, `${trail}[${index}]`, parentOwned, declaredVariables));
    return out;
  }

  if (isPlainObject(value)) {
    for (const [key, item] of Object.entries(value)) {
      const owned = tokenOwnedChild(parentOwned, key);
      collectUntokenizedNativeLengthValues(item, out, `${trail}.${key}`, owned, declaredVariables);
    }
    return out;
  }

  if (parentOwned && hasLiteralLength(value) && !hasTokenizedLength(value, declaredVariables)) {
    out.push({ path: trail, value: String(value) });
  }
  return out;
}

function validateTokenizedNativeLengths(items, findings, file, pathPointer, declaredVariables = new Set()) {
  const findingsToAdd = [];
  asArray(items).forEach((item, index) => {
    if (!isPlainObject(item)) return;
    const label = item.id || item.name || item.label || `item-${index}`;
    const settings = elementSettings(item);
    const values = collectUntokenizedNativeLengthValues(settings, [], `${pathPointer}[${index}].settings`, false, declaredVariables);
    for (const value of values) {
      findingsToAdd.push({ label, ...value });
    }
  });

  findingsToAdd.slice(0, TOKEN_FINDING_LIMIT).forEach((item) => {
    add(
      findings,
      'fail',
      `Bricks native style "${item.path}" on "${item.label}" uses literal length "${item.value}". Framework-mode SEAM Compiler output must follow the Kiwe token ladder for spacing, sizing, radius, type, shadow, transform, and responsive layout controls: use an official var(--kiwe-*)/var(--seam-*) token when the meaning and property domain match; otherwise use a declared project variable; otherwise use a real fluid clamp() only when source responsive states prove different min/max values. Plain values are valid only at the named token definition layer for roles such as fixed primitive, geometry input, content limit, or responsive guard. No-op clamps such as clamp(22px, 22px, 22px) do not count as tokenization.`,
      file,
      item.path
    );
  });
  if (findingsToAdd.length > TOKEN_FINDING_LIMIT) {
    add(
      findings,
      'fail',
      `Bricks native styles contain ${findingsToAdd.length - TOKEN_FINDING_LIMIT} additional untokenized literal length values beyond the first ${TOKEN_FINDING_LIMIT}. Fix with official tokens, declared project variables, or real fluid clamps from proven responsive states, then rerun /audit /bricksconversion.`,
      file,
      pathPointer
    );
  }
}

function countMappableCssDeclarations(cssText) {
  const text = String(cssText || '');
  MAPPABLE_CSS_DECLARATION_RE.lastIndex = 0;
  let count = 0;
  while (MAPPABLE_CSS_DECLARATION_RE.exec(text)) count += 1;
  return count;
}

function isLikelyBricksTemplateExport(value) {
  if (!isPlainObject(value) || value.schema === SCHEMA || Array.isArray(value.elements)) return false;
  return (
    Array.isArray(value.content) ||
    Array.isArray(value.header) ||
    Array.isArray(value.footer) ||
    'templateType' in value ||
    'pageSettings' in value ||
    'bundles' in value
  );
}

function findNativeTemplatePath(target) {
  const resolved = path.resolve(target || '.');
  if (fs.existsSync(resolved) && fs.statSync(resolved).isFile()) {
    try {
      const data = JSON.parse(fs.readFileSync(resolved, 'utf8'));
      if (isLikelyBricksTemplateExport(data)) return { root: path.dirname(resolved), templatePath: resolved };
    } catch {
      return { root: path.dirname(resolved), templatePath: '' };
    }
  }

  const candidates = [];
  const templateDir = path.join(resolved, 'bricks-template');
  if (fs.existsSync(templateDir) && fs.statSync(templateDir).isDirectory()) {
    for (const fileName of fs.readdirSync(templateDir)) {
      if (/\.json$/i.test(fileName)) candidates.push(path.join(templateDir, fileName));
    }
  }
  if (fs.existsSync(resolved) && fs.statSync(resolved).isDirectory()) {
    for (const fileName of fs.readdirSync(resolved)) {
      if (/template.*\.json$|\.bricks-template\.json$|bricks.*\.json$/i.test(fileName)) {
        candidates.push(path.join(resolved, fileName));
      }
    }
  }

  for (const candidate of candidates) {
    try {
      if (!fs.existsSync(candidate) || !fs.statSync(candidate).isFile()) continue;
      const data = JSON.parse(fs.readFileSync(candidate, 'utf8'));
      if (isLikelyBricksTemplateExport(data)) return { root: resolved, templatePath: candidate };
    } catch {
      // Ignore candidate parse errors here; readJson reports precise errors once a path is selected.
    }
  }
  return { root: resolved, templatePath: '' };
}

function validateBricksTemplateElements(root, templatePath, templateElements, findings) {
  const templateRel = rel(root, templatePath);
  templateElements.forEach((element, index) => {
    if (!isPlainObject(element)) return;
    const name = String(element.name || '').trim();
    if (!name) return;
    const pointer = `$.content/header/footer[${index}].name`;
    if (SEMANTIC_HTML_ELEMENT_NAME_MISUSE.has(name)) {
      add(
        findings,
        'fail',
        `Bricks template export uses semantic HTML tag "${name}" as an element name. Use a supported Bricks element such as block/div/container and set tag/customTag to "${name}"; otherwise Bricks can render "${name}: PHP class does not exist".`,
        templateRel,
        pointer
      );
    } else if (!KNOWN_BRICKS_ELEMENTS.has(name)) {
      add(
        findings,
        'warn',
        `Bricks template export uses element "${name}" that is not in the Kiwe known Bricks element list. Confirm it exists on the target Bricks installation before upload.`,
        templateRel,
        pointer
      );
    }
  });
}

function validateBricksTemplateExport(root, templateRelPath, findings, conversionRel, pathPointer) {
  const relPath = String(templateRelPath || '').trim();
  if (!relPath) {
    add(findings, 'fail', 'target.templateExportPath is required when target.importMethod is bricks-admin-template-upload.', conversionRel, pathPointer);
    return;
  }

  const templatePath = resolvePackageFile(root, relPath);
  if (!templatePath) {
    add(findings, 'fail', 'target.templateExportPath must stay inside the handoff package.', conversionRel, pathPointer);
    return;
  }
  if (!fs.existsSync(templatePath) || !fs.statSync(templatePath).isFile()) {
    add(findings, 'fail', `target.templateExportPath does not exist: ${relPath}`, conversionRel, pathPointer);
    return;
  }

  const templateData = readJson(templatePath, findings, `Bricks template export ${relPath}`);
  if (!templateData || !isPlainObject(templateData)) {
    add(findings, 'fail', 'Bricks template export must be a JSON object.', rel(root, templatePath));
    return;
  }
  if (templateData.schema === SCHEMA || Array.isArray(templateData.elements)) {
    add(
      findings,
      'fail',
      'Bricks template export must not be a Kiwe conversion/audit envelope. Bricks My Templates import expects a native export with title plus content/header/footer.',
      rel(root, templatePath)
    );
  }
  const title = String(templateData.title || '').trim();
  if (!title) {
    add(findings, 'fail', 'Bricks template export is missing title. Bricks imports this as "(no title)".', rel(root, templatePath), '$.title');
  } else if (/^\(?\s*no\s+title\s*\)?$/i.test(title)) {
    add(findings, 'fail', 'Bricks template export title is "(no title)". Provide a real human-readable title before upload.', rel(root, templatePath), '$.title');
  }

  const areaKeys = ['content', 'header', 'footer'];
  const populatedArea = areaKeys.find((key) => Array.isArray(templateData[key]) && templateData[key].length > 0);
  if (!populatedArea) {
    add(findings, 'fail', 'Bricks template export must contain a non-empty content, header, or footer array. Otherwise Bricks insert reports "This template has no data".', rel(root, templatePath));
  }
  const templateType = String(templateData.templateType || '').trim();
  if (!templateType) {
    add(findings, 'fail', 'Bricks template export must include templateType so Bricks stores the imported template in the intended area/type.', rel(root, templatePath), '$.templateType');
  } else if (templateType === 'header' && populatedArea && populatedArea !== 'header') {
    add(findings, 'fail', 'Bricks templateType "header" must use a non-empty header array, not content/footer.', rel(root, templatePath), '$.header');
  } else if (templateType === 'footer' && populatedArea && populatedArea !== 'footer') {
    add(findings, 'fail', 'Bricks templateType "footer" must use a non-empty footer array, not content/header.', rel(root, templatePath), '$.footer');
  } else if (!['header', 'footer'].includes(templateType) && populatedArea && populatedArea !== 'content') {
    add(findings, 'fail', 'Non-header/footer Bricks templates should use a non-empty content array.', rel(root, templatePath), '$.content');
  }
  const homepageHint = /(?:^|[\\/_-])home(?:page)?(?:[\\/_\-.]|$)/i.test(relPath) || /homepage|home\s+page/i.test(`${title} ${templateData.name || ''}`);
  if (homepageHint && title && title !== 'Home') {
    add(findings, 'warn', 'Homepage body template should normally use title "Home" so Bricks My Templates is easy to identify.', rel(root, templatePath), '$.title');
  }
  if (homepageHint && templateType && templateType !== 'content') {
    add(findings, 'warn', 'Homepage body template should normally use templateType "content"; use section/header/footer only when that is the intended Bricks library type.', rel(root, templatePath), '$.templateType');
  }
  if (!String(templateData.version || '').trim()) {
    add(findings, 'warn', 'Bricks template export should include the target Bricks version used to author/verify the native template.', rel(root, templatePath), '$.version');
  } else if (!SUPPORTED_TEMPLATE_BRICKS_VERSION_RE.test(String(templateData.version || '').trim())) {
    add(
      findings,
      'fail',
      `Bricks template export declares version "${String(templateData.version || '').trim()}". Kiwe production template uploads currently target the public Bricks 2.3.x importer/runtime; do not emit unreleased/beta 2.4 template metadata unless the contract is explicitly updated after a public Bricks release.`,
      rel(root, templatePath),
      '$.version'
    );
  }
  if (Array.isArray(templateData.globalClasses) && templateData.globalClasses.length && !Array.isArray(templateData.global_classes)) {
    add(
      findings,
      'fail',
      'Bricks template export uses top-level globalClasses but not global_classes. Bricks My Templates import reads global_classes for template class dependencies; include native Bricks global_classes so imported elements do not lose their editable class styles.',
      rel(root, templatePath),
      '$.global_classes'
    );
  }
  if (Array.isArray(templateData.global_classes)) {
    const unsafeNames = [];
    templateData.global_classes.forEach((globalClass, index) => {
      const name = String(globalClass?.name || '').trim();
      if (name && !isCollisionSafeTemplateClassName(name)) unsafeNames.push({ name, index });
    });
    if (unsafeNames.length) {
      const preview = unsafeNames.slice(0, 12).map((item) => `"${item.name}"`).join(', ');
      add(
        findings,
        'fail',
        `Bricks template upload contains ${unsafeNames.length} unscoped global class name(s): ${preview}${unsafeNames.length > 12 ? ', ...' : ''}. Bricks My Templates skips or remaps imported class styles when a local class has the same id or name, so SEAM Compiler must namespace project visual global classes (for example nc-promo-card, bv-product-card, sf-hero-grid) and keep plain semantic names only in _cssClasses/attributes, not importable global_classes.`,
        rel(root, templatePath),
        '$.global_classes'
      );
    }
  }
  const variableNameFindings = collectTemplateVariableNameFindings(templateData);
  const prefixedVariableNameFindings = variableNameFindings.filter((item) => item.type !== 'variable-value-has-fallback');
  const variableValueFallbackFindings = variableNameFindings.filter((item) => item.type === 'variable-value-has-fallback');
  for (const item of prefixedVariableNameFindings.slice(0, 20)) {
    add(
      findings,
      'fail',
      `Bricks global variable "${item.name}" includes the CSS custom-property prefix. Native Bricks global_variables/globalVariables names must be stored without leading "--" because Bricks emits the "--" prefix when compiling CSS. Keeping it here compiles to "----${item.name.replace(/^--/, '')}", while page controls consume "var(${item.name})", leaving the frontend disconnected from the token.`,
      rel(root, templatePath),
      item.path
    );
  }
  if (prefixedVariableNameFindings.length > 20) {
    add(
      findings,
      'fail',
      `Bricks template export contains ${prefixedVariableNameFindings.length - 20} additional global variable names with leading "--". Store names as "kiwe-color-brand" or "nc-app-max", not "--kiwe-color-brand" or "--nc-app-max".`,
      rel(root, templatePath),
      '$.global_variables'
    );
  }
  for (const item of variableValueFallbackFindings.slice(0, 20)) {
    add(
      findings,
      'fail',
      `Bricks global variable "${item.name}" references "${item.variable}" with an inline fallback in "${item.value}". Template variables must not smuggle render values through fallbacks; define the real value in the paired Kiwe Framework profile / Bricks variable push and consume bare variables in the template.`,
      rel(root, templatePath),
      item.path
    );
  }
  if (variableValueFallbackFindings.length > 20) {
    add(
      findings,
      'fail',
      `Bricks template export contains ${variableValueFallbackFindings.length - 20} additional global variable values with inline CSS-variable fallbacks. Remove the fallbacks and keep the values in the Framework profile.`,
      rel(root, templatePath),
      '$.global_variables'
    );
  }

  const templateCustomCss = collectCustomCssBuckets({
    pageSettings: templateData.pageSettings,
    settings: templateData.settings
  });
  const templateCssText = templateCustomCss.map((bucket) => bucket.text).join('\n');
  const templateCssBytes = templateCustomCss.reduce((sum, bucket) => sum + String(bucket.text || '').length, 0);
  const templateMappableCss = countMappableCssDeclarations(templateCssText);
  if (
    templateCssBytes >= TEMPLATE_UPLOAD_CUSTOM_CSS_BYTES ||
    templateMappableCss >= TEMPLATE_UPLOAD_MAPPABLE_CSS_DECLARATION_MIN ||
    /@media\b|#home-campaigns\b|\.nc-(?:bento|campaign|section-head)|grid-template|flex-direction/i.test(templateCssText)
  ) {
    add(
      findings,
      'fail',
      `Bricks template export carries ${templateCssBytes} page/template custom CSS bytes and ${templateMappableCss} mappable declarations. Bricks My Templates insertion can leave pageSettings custom CSS behind or collide with stale target-page CSS; move ordinary layout/design into native element settings, importable globalClasses/globalVariables, or documented tiny exceptions.`,
      rel(root, templatePath),
      '$.pageSettings.customCss'
    );
  }

  const templateElements = []
    .concat(asArray(templateData.content))
    .concat(asArray(templateData.header))
    .concat(asArray(templateData.footer));
  const templateStyleItems = templateElements
    .concat(asArray(templateData.global_classes))
    .concat(asArray(templateData.globalClasses));
  const declaredVariables = collectDeclaredCssVariables(templateData);
  const rawSelfContained = templateData?.kiwe?.renderMode === 'raw-self-contained';
  validateBricksTemplateElements(root, templatePath, templateElements, findings);
  const runtimeCodeElements = collectRuntimeCodeElements(templateElements);
  for (const item of runtimeCodeElements.slice(0, 20)) {
    add(
      findings,
      'fail',
      `Bricks Code element "${item.label}" contains runtime/custom-code settings (${item.keys.join(', ')}). External converters may park CSS/JS in Code elements for manual review, but production SEAM Compiler output must decompose representable layout/design into native Bricks elements, controls, variables, attributes, interactions, and documented unsupported exceptions instead of shipping Code-element authority.`,
      rel(root, templatePath),
      item.path
    );
  }
  if (runtimeCodeElements.length > 20) {
    add(
      findings,
      'fail',
      `Bricks template export contains ${runtimeCodeElements.length - 20} additional runtime Code elements. Treat external-converter output as scaffold/review-only until those Code elements are normalized or documented as explicit unsupported exceptions.`,
      rel(root, templatePath),
      '$.content/header/footer'
    );
  }
  const templateNativeControls = collectNativeStyleControlsFromItems(
    templateStyleItems
  );
  for (const item of collectImplicitBricksLayoutControls(templateStyleItems).slice(0, 40)) {
    if (item.type === 'missing-flex-direction') {
      add(
        findings,
        'fail',
        `Bricks layout element "${item.label}" sets _display:flex but omits _direction. Bricks source-backed layout controls must explicitly own flex direction; relying on browser defaults causes rail/card drift and makes the visual editor ambiguous.`,
        rel(root, templatePath),
        item.path
      );
    } else if (item.type === 'missing-grid-columns') {
      add(
        findings,
        'fail',
        `Bricks layout element "${item.label}" sets _display:grid but omits _gridTemplateColumns/_gridAutoColumns. Grid layout must be represented by Bricks-native grid controls, not implicit CSS/default behavior.`,
        rel(root, templatePath),
        item.path
      );
    } else if (item.type === 'rail-missing-flex-display') {
      add(
        findings,
        'fail',
        `Seam horizontal rail "${item.label}" must set Bricks _display:flex on the actual item track. Rail semantics alone do not create Bricks-native layout ownership.`,
        rel(root, templatePath),
        item.path
      );
    } else if (item.type === 'rail-missing-row-direction') {
      add(
        findings,
        'fail',
        `Seam horizontal rail "${item.label}" must set Bricks _direction:row. This is the source-backed control that preserves category/product rail orientation in Bricks 2.3.x/2.4.`,
        rel(root, templatePath),
        item.path
      );
    } else if (item.type === 'rail-missing-overflow') {
      add(
        findings,
        'fail',
        `Seam horizontal rail "${item.label}" must set Bricks _overflow:auto or scroll so the actual rail track remains scrollable after import.`,
        rel(root, templatePath),
        item.path
      );
    } else if (item.type === 'rail-missing-gap') {
      add(
        findings,
        'fail',
        `Seam horizontal rail "${item.label}" must expose a tokenized Bricks _columnGap or _gap control; spacing cannot be hidden in defaults or external CSS.`,
        rel(root, templatePath),
        item.path
      );
    }
  }
  for (const item of collectBricksCompilerUnsafeControls(templateStyleItems).slice(0, 40)) {
    if (item.type === 'unsupported-control') {
      add(
        findings,
        'fail',
        `Bricks native control "${item.key}" on "${item.label}" is not compiler-safe for My Templates output. Use Bricks' source-backed controls "_widthMin", "_widthMax", "_heightMin", or "_heightMax" instead of "_minWidth", "_maxWidth", "_minHeight", or "_maxHeight"; otherwise the frontend CSS silently drops the intended rule.`,
        rel(root, templatePath),
        item.path
      );
    } else if (item.type === 'font-family-token') {
      add(
        findings,
        'fail',
        `Bricks typography control "${item.key}" on "${item.label}" stores font-family as "${item.value}". Bricks compiles typography font families as quoted values, so CSS-variable font stacks become invalid like font-family: "var(--kiwe-font-body, ...)". Use a concrete Bricks font-family value in _typography and keep tokenized font families in the Framework/theme layer.`,
        rel(root, templatePath),
        item.path
      );
    } else if (item.type === 'semantic-heading-font-size-lock') {
      add(
        findings,
        'fail',
        `Bricks semantic heading "${item.label}" is tagged "${item.tag}" but locks its own font-size to "${item.value}". Semantic heading scale belongs in Kiwe > Framework / Bricks Theme Style; remove local heading-token font-size so changing h3 to h2/h4 in Bricks uses the selected heading level.`,
        rel(root, templatePath),
        item.path
      );
    } else if (item.type === 'color-shape') {
      add(
        findings,
        'fail',
        `Bricks color control "${item.key}" on "${item.label}" stores color as a plain string "${item.value}". Bricks' frontend CSS generator expects color objects such as { "raw": "var(--kiwe-color-surface)" } for background, border, typography and related native controls; plain strings can be kept in JSON but silently omitted from frontend CSS.`,
        rel(root, templatePath),
        item.path
      );
    } else if (item.type === 'radius-shape') {
      add(
        findings,
        'fail',
        `Bricks border-radius control "${item.key}" on "${item.label}" uses CSS corner keys "${item.value}". Bricks' frontend CSS generator reads radius.top, radius.right, radius.bottom, and radius.left, then maps them to the four CSS corners; topLeft/topRight/bottomRight/bottomLeft can remain in JSON but silently compile to no radius.`,
        rel(root, templatePath),
        item.path
      );
    } else if (item.type === 'background-gradient-color') {
      add(
        findings,
        'fail',
        `Bricks background color control "${item.key}" on "${item.label}" stores a gradient in color.raw. Bricks compiles _background.color to background-color, where gradients are invalid; use the native "_gradient" control with tokenized color stops and keep _background.color as a solid fallback.`,
        rel(root, templatePath),
        item.path
      );
    }
  }
  if (rawSelfContained) {
    validateSelfContainedNativeValues(templateStyleItems, findings, rel(root, templatePath));
  } else {
    validateTokenizedNativeLengths(
      templateStyleItems,
      findings,
      rel(root, templatePath),
      '$.content/header/footer/global_classes',
      declaredVariables
    );
    validateTokenizedNativeColors(
      templateStyleItems,
      findings,
      rel(root, templatePath),
      '$.content/header/footer/global_classes'
    );
    validateCssVariableFallbacks(
      templateStyleItems,
      findings,
      rel(root, templatePath),
      '$.content/header/footer/global_classes'
    );
    validateProjectVariableFrameworkProof(
      root,
      templateData,
      templateStyleItems,
      findings,
      rel(root, templatePath)
    );
  }
  if (templateElements.length >= LARGE_CLIPBOARD_ELEMENT_COUNT && templateNativeControls.length < CUSTOM_CSS_NATIVE_STYLE_MIN_CONTROLS) {
    add(
      findings,
      'fail',
      `Large Bricks template export has ${templateElements.length} elements but only ${templateNativeControls.length} native style/layout controls. Full-page template uploads must preserve editable Bricks controls instead of relying on source/page CSS that may not follow insertion.`,
      rel(root, templatePath),
      '$.content'
    );
  }
  const editabilityStats = collectTemplateEditabilityStats(templateElements);
  if (
    editabilityStats.elementCount >= LARGE_CLIPBOARD_ELEMENT_COUNT &&
    editabilityStats.elementNativeControlsPerElement < TEMPLATE_UPLOAD_MIN_ELEMENT_NATIVE_CONTROLS_PER_ELEMENT
  ) {
    add(
      findings,
      'fail',
      `Large Bricks template export has ${editabilityStats.elementNativeControls} element-level native style/layout controls across ${editabilityStats.elementCount} elements (${editabilityStats.elementNativeControlsPerElement.toFixed(2)} per element). This is too class-dependent for a visual-editor handoff: grid/flex, spacing, sizing, typography, color, borders, radius, shadows, and responsive overrides must be editable on elements where the source design depends on them, not only in importable global_classes.`,
      rel(root, templatePath),
      '$.content'
    );
  }
  if (
    editabilityStats.elementCount >= LARGE_CLIPBOARD_ELEMENT_COUNT &&
    editabilityStats.classOnlyElementRatio > TEMPLATE_UPLOAD_MAX_CLASS_ONLY_ELEMENT_RATIO
  ) {
    add(
      findings,
      'fail',
      `Large Bricks template export has ${editabilityStats.classOnlyElements} of ${editabilityStats.elementCount} elements (${Math.round(editabilityStats.classOnlyElementRatio * 100)}%) carrying global-class dependencies without element-level native style/layout controls. Bricks My Templates can skip or remap global class definitions when class names already exist, so SEAM Compiler must keep the rendered design resilient with sufficient element-native controls instead of relying mainly on class hydration.`,
      rel(root, templatePath),
      '$.content'
    );
  }

  const styledGlobalClasses = collectStyledTemplateGlobalClasses(templateData.global_classes);
  if (editabilityStats.elementCount >= LARGE_CLIPBOARD_ELEMENT_COUNT && styledGlobalClasses.length) {
    const preview = styledGlobalClasses.slice(0, 12).map((item) => item.name || item.id || '(unnamed)').join(', ');
    add(
      findings,
      'fail',
      `Large Bricks template export imports ${styledGlobalClasses.length} styled global_classes (${preview}${styledGlobalClasses.length > 12 ? ', ...' : ''}) while element-native controls already own visual fidelity. This creates multi-owner "ghost styling" in Bricks: removing a color/radius/spacing from the visible element or class can leave the same style active from another layer. SEAM Compiler template uploads must use element-native controls as the render/edit owner and keep imported global_classes semantic/name-only; reusable project classes belong in the Framework profile push, not as duplicate styled classes in the template upload.`,
      rel(root, templatePath),
      '$.global_classes'
    );
  }
}

function validateRoot(conversion, findings, conversionRel, root) {
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
  const source = conversion.source || {};
  if (!isPlainObject(source)) {
    add(findings, 'fail', 'source must be an object describing the page artifact being converted.', conversionRel, '$.source');
  } else {
    const sourceText = JSON.stringify(source);
    const sourceHtml = String(source.html || source.path || '');
    if (/(^|[\\/])(combined-preview|appshell-theme|ui-system)([\\/]|$)|theme-package\.json|css[\\/]theme\.css|\b(?:dsa\s*theme|appshell|app\s*shell)\b/i.test(sourceText)) {
      add(findings, 'fail', 'SEAM Compiler source must be the page artifact only. Do not convert combined-preview, appshell-theme, DSA/AppShell preview markup, theme-package.json, or theme.css into Bricks.', conversionRel, '$.source');
    }
    if (sourceHtml && !sourceHtml.replace(/\\/g, '/').endsWith('website/bricks-paste.html')) {
      add(findings, 'warn', 'source.html should point to website/bricks-paste.html. Combined previews and AppShell theme previews are never Bricks conversion sources.', conversionRel, '$.source.html');
    }
  }
  const target = conversion.target || {};
  if (!isPlainObject(target)) {
    add(findings, 'fail', 'target must be an object.', conversionRel, '$.target');
  } else {
    if (target.builder !== 'bricks') add(findings, 'fail', 'target.builder must be "bricks".', conversionRel, '$.target.builder');
    if (!String(target.format || '').toLowerCase().includes('bricks')) add(findings, 'fail', 'target.format must identify a Bricks element JSON artifact.', conversionRel, '$.target.format');
    const importMethod = String(target.importMethod || '').trim();
    if (!importMethod) {
      add(
        findings,
        'fail',
        `target.importMethod is required. Use one of: ${Array.from(BRICKS_IMPORT_METHODS).join(', ')}. Kiwe conversion JSON is not itself a Bricks My Templates upload file.`,
        conversionRel,
        '$.target.importMethod'
      );
    } else if (!BRICKS_IMPORT_METHODS.has(importMethod)) {
      add(
        findings,
        'fail',
        `target.importMethod must be one of: ${Array.from(BRICKS_IMPORT_METHODS).join(', ')}.`,
        conversionRel,
        '$.target.importMethod'
      );
    }
    if (/template|upload|library|my-templates/i.test(`${target.mode || ''} ${target.format || ''}`) && importMethod !== 'bricks-admin-template-upload') {
      add(
        findings,
        'fail',
        'Bricks template-library/My Templates delivery must use target.importMethod "bricks-admin-template-upload" and provide a native Bricks template export file.',
        conversionRel,
        '$.target.importMethod'
      );
    }
    if (importMethod === 'bricks-admin-template-upload') {
      validateBricksTemplateExport(root, target.templateExportPath, findings, conversionRel, '$.target.templateExportPath');
    }
    if (importMethod === 'bricks-clipboard-json' && asArray(conversion.elements).length >= LARGE_CLIPBOARD_ELEMENT_COUNT) {
      add(
        findings,
        'fail',
        `Large Bricks conversions (${asArray(conversion.elements).length} elements) must not default to clipboard paste. Use bricks-admin-template-upload with a separate native Bricks template export, or kiwe-staging-executor after validation.`,
        conversionRel,
        '$.target.importMethod'
      );
    }
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
    for (const key of ['elementMapping', 'dynamicIntent', 'responsiveIntent', 'nativeStyleIntent', 'interactions', 'conditions', 'unsupported']) {
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

function validateTemplateUploadConversionCss({ conversion, findings, conversionRel }) {
  const importMethod = String(conversion && conversion.target && conversion.target.importMethod || '').trim();
  if (importMethod !== 'bricks-admin-template-upload') return;

  const customCssBuckets = collectCustomCssBuckets({
    pageSettings: conversion.pageSettings
  });
  const customCssText = customCssBuckets.map((bucket) => bucket.text).join('\n');
  const customCssBytes = customCssBuckets.reduce((sum, bucket) => sum + String(bucket.text || '').length, 0);
  const mappableCss = countMappableCssDeclarations(customCssText);
  if (
    customCssBytes >= TEMPLATE_UPLOAD_CUSTOM_CSS_BYTES ||
    mappableCss >= TEMPLATE_UPLOAD_MAPPABLE_CSS_DECLARATION_MIN ||
    /@media\b|#home-campaigns\b|\.nc-(?:bento|campaign|section-head)|grid-template|flex-direction/i.test(customCssText)
  ) {
    add(
      findings,
      'fail',
      `target.importMethod is bricks-admin-template-upload, but the Kiwe conversion envelope still carries ${customCssBytes} pageSettings custom CSS bytes and ${mappableCss} mappable declarations. Template-upload handoffs must not rely on pageSettings.customCss for ordinary layout/design because Bricks insertion may not transfer it to the target page. Use native element settings/globalClasses/globalVariables first.`,
      conversionRel,
      '$.pageSettings.customCss'
    );
  }
}

function validateResponsiveLayoutFidelity({ conversion, conversionText, website, findings, conversionRel }) {
  const elements = asArray(conversion.elements);
  const fidelity = isPlainObject(conversion.fidelity) ? conversion.fidelity : {};
  const responsiveIntent = asArray(fidelity.responsiveIntent);
  const responsiveOverrides = collectResponsiveLayoutOverrides(elements);
  const sourceText = website && website.text ? website.text : '';
  const sourceIsComplex = hasComplexLayoutEvidence(sourceText);
  const conversionIsComplex = hasComplexLayoutEvidence(conversionText);
  const hasComplexLayout = sourceIsComplex || conversionIsComplex;

  if ((hasComplexLayout || responsiveOverrides.length > 0) && responsiveIntent.length === 0) {
    add(
      findings,
      'fail',
      'fidelity.responsiveIntent must be a non-empty array when the source/conversion uses complex bento/grid/campaign layout or Bricks breakpoint layout overrides. Map desktop/tablet/mobile intent, source selectors, Bricks element IDs, and the intended grid/flex behavior.',
      conversionRel,
      '$.fidelity.responsiveIntent'
    );
  }

  if (responsiveIntent.length > 0) {
    const byId = new Map(elements.map((element) => [String(element && element.id || ''), element]).filter(([id]) => id));
    responsiveIntent.forEach((item, index) => {
      if (!isPlainObject(item)) {
        add(findings, 'fail', 'Every fidelity.responsiveIntent item must be an object.', conversionRel, `$.fidelity.responsiveIntent[${index}]`);
        return;
      }
      const itemText = JSON.stringify(item);
      if (!/(desktop|tablet|mobile|narrow|breakpoint|viewport|range)/i.test(itemText)) {
        add(findings, 'fail', 'fidelity.responsiveIntent items must identify the breakpoint or viewport range they describe.', conversionRel, `$.fidelity.responsiveIntent[${index}]`);
      }
      if (!/(selector|source|element|bricks|mappedElementIds|id)/i.test(itemText)) {
        add(findings, 'fail', 'fidelity.responsiveIntent items must connect the source selector to Bricks element IDs/settings.', conversionRel, `$.fidelity.responsiveIntent[${index}]`);
      }
      if (!/(grid|flex|direction|columns|rows|span|wrap|align|justify|flow)/i.test(itemText)) {
        add(findings, 'fail', 'fidelity.responsiveIntent items must state the preserved layout behavior, not only the visual label.', conversionRel, `$.fidelity.responsiveIntent[${index}]`);
      }
      if (/_flexDirection\b/i.test(itemText)) {
        for (const id of asArray(item.mappedElementIds)) {
          const mapped = byId.get(String(id));
          if (mapped && isBricksLayoutElement(mapped)) {
            add(
              findings,
              'fail',
              `fidelity.responsiveIntent[${index}] claims _flexDirection for Bricks layout element "${id}". Bricks layout elements (${Array.from(BRICKS_LAYOUT_ELEMENT_NAMES).join(', ')}) must use _direction / _direction:<breakpoint>; _flexDirection is for non-nestable elements.`,
              conversionRel,
              `$.fidelity.responsiveIntent[${index}]`
            );
          }
        }
      }
    });
  }

  if (hasComplexLayout && !fidelityMentions(fidelity.sourceSelectors, /(?:#home-campaigns|bento|campaign|grid-template|grid-column|grid-row)/i)) {
    add(
      findings,
      'fail',
      'fidelity.sourceSelectors must explicitly include complex bento/grid/campaign regions such as #home-campaigns/.nc-bento and their mapped Bricks element IDs.',
      conversionRel,
      '$.fidelity.sourceSelectors'
    );
  }

  if (hasComplexLayout && responsiveIntent.length > 0 && !fidelityMentions(responsiveIntent, /(?:#home-campaigns|bento|campaign|grid|columns|rows|span)/i)) {
    add(
      findings,
      'fail',
      'fidelity.responsiveIntent must explicitly describe bento/grid/campaign responsive behavior so Bricks desktop/tablet/mobile layouts cannot silently drift.',
      conversionRel,
      '$.fidelity.responsiveIntent'
    );
  }

  for (const override of responsiveOverrides) {
    const classText = `${override.classes} ${override.cssId}`;
    if (/seam-spread\b/.test(classText) && /_(?:direction|flexDirection):/i.test(override.key) && String(override.value).toLowerCase() === 'column' && !fidelityMentions(responsiveIntent, new RegExp(`${override.element.id}|${override.cssId || 'no-css-id'}|seam-spread|section-head`, 'i'))) {
      add(
        findings,
        'fail',
        `Element "${override.element.id}" changes seam-spread to column at ${override.key.split(':')[1]} without a responsiveIntent entry tied to source evidence. This can invert section headings in Bricks mobile breakpoints.`,
        conversionRel,
        '$.fidelity.responsiveIntent'
      );
    }
  }
}

function validateNativeStyleFidelity({ conversion, findings, conversionRel }) {
  const elements = asArray(conversion.elements);
  const globalClasses = asArray(conversion.globalClasses);
  const declaredVariables = collectDeclaredCssVariables(conversion);
  const fidelity = isPlainObject(conversion.fidelity) ? conversion.fidelity : {};
  const nativeStyleIntent = asArray(fidelity.nativeStyleIntent);
  const customCssBuckets = collectCustomCssBuckets(conversion);
  const customCssBytes = customCssBuckets.reduce((sum, bucket) => sum + String(bucket.text || '').length, 0);
  const customCssText = customCssBuckets.map((bucket) => bucket.text).join('\n');
  const nativeControls = collectNativeStyleControlsFromItems(elements.concat(globalClasses));
  const mappableCssDeclarations = countMappableCssDeclarations(customCssText);
  const isCustomCssHeavy = customCssBytes >= CUSTOM_CSS_HEAVY_BYTES || /@media\b[\s\S]{0,2400}(?:\.nc-|#home-campaigns|\.seam-|grid-template|flex-direction)/i.test(customCssText);

  validateTokenizedNativeLengths(
    elements.concat(globalClasses),
    findings,
    conversionRel,
    '$.elements/globalClasses',
    declaredVariables
  );
  validateTokenizedNativeColors(
    elements.concat(globalClasses),
    findings,
    conversionRel,
    '$.elements/globalClasses'
  );
  validateCssVariableFallbacks(
    elements.concat(globalClasses),
    findings,
    conversionRel,
    '$.elements/globalClasses'
  );

  if (!isCustomCssHeavy && mappableCssDeclarations < MAPPABLE_CSS_DECLARATION_MIN) return;

  if (nativeStyleIntent.length === 0) {
    add(
      findings,
      'fail',
      `Bricks conversion carries ${customCssBytes} custom CSS bytes and ${mappableCssDeclarations} mappable CSS declarations but has no fidelity.nativeStyleIntent. Prove which visual rules were mapped to editable Bricks native controls and which CSS remains an explicit exception.`,
      conversionRel,
      '$.fidelity.nativeStyleIntent'
    );
  }

  if (isCustomCssHeavy && nativeControls.length < CUSTOM_CSS_NATIVE_STYLE_MIN_CONTROLS) {
    add(
      findings,
      'fail',
      `Bricks conversion uses only ${nativeControls.length} native style/layout controls while carrying ${customCssBytes} custom CSS bytes. This is not editable enough for Bricks visual-editor handoff; map ordinary typography, spacing, backgrounds, borders, radii, shadows, grid/flex, sizing, and responsive controls to Bricks settings/global classes first.`,
      conversionRel,
      '$.elements'
    );
  }

  const minimumNativeControlsForCss = Math.ceil(mappableCssDeclarations * MAPPABLE_CSS_NATIVE_STYLE_RATIO);
  if (mappableCssDeclarations >= MAPPABLE_CSS_DECLARATION_MIN && nativeControls.length < minimumNativeControlsForCss) {
    add(
      findings,
      'fail',
      `Bricks conversion leaves ${mappableCssDeclarations} mappable CSS declarations in custom CSS but exposes only ${nativeControls.length} native Bricks style/layout controls. Ordinary design decisions must be editable through Bricks controls/global classes/global variables; keep custom CSS for explicit exceptions only.`,
      conversionRel,
      '$.elements'
    );
  }

  nativeStyleIntent.forEach((item, index) => {
    if (!isPlainObject(item)) {
      add(findings, 'fail', 'Every fidelity.nativeStyleIntent item must be an object.', conversionRel, `$.fidelity.nativeStyleIntent[${index}]`);
      return;
    }
    const itemText = JSON.stringify(item);
    if (!/(selector|sourceSelector)/i.test(itemText)) {
      add(findings, 'fail', 'fidelity.nativeStyleIntent items must identify the source selector being styled.', conversionRel, `$.fidelity.nativeStyleIntent[${index}]`);
    }
    if (!/(mappedElementIds|bricksElementIds|element)/i.test(itemText)) {
      add(findings, 'fail', 'fidelity.nativeStyleIntent items must identify mapped Bricks element IDs.', conversionRel, `$.fidelity.nativeStyleIntent[${index}]`);
    }
    if (!/(nativeControls|bricksControls|globalClass|globalVariable)/i.test(itemText)) {
      add(findings, 'fail', 'fidelity.nativeStyleIntent items must list editable Bricks native controls, global classes, or global variables used.', conversionRel, `$.fidelity.nativeStyleIntent[${index}]`);
    }
    if (!/(customCssException|unsupported|manualReview|native|editable)/i.test(itemText)) {
      add(findings, 'fail', 'fidelity.nativeStyleIntent items must state what remains custom CSS versus what is editable natively.', conversionRel, `$.fidelity.nativeStyleIntent[${index}]`);
    }
  });
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
    } else if (isPlainObject(element.settings)) {
      const settings = elementSettings(element);
      if (isBricksLayoutElement(element)) {
        for (const key of Object.keys(settings)) {
          if (/^_flexDirection(?::|$)/.test(key)) {
            add(
              findings,
              'fail',
              `Bricks layout element "${id || '(missing id)'}" (${name}) uses ${key}. Bricks source documents that layout elements use _direction / _direction:<breakpoint>; _flexDirection is only for non-nestable elements.`,
              conversionRel,
              `${pointer}.settings.${key}`
            );
          }
        }
      }
      for (const [key, value] of Object.entries(settings)) {
        if (!/^_cssCustom(?::|$)/.test(key) || typeof value !== 'string') continue;
        const text = value;
        if (
          text.length > 4000 &&
          /(?:^|\n|\r)\s*:root\b|@media\b|#home-campaigns\b|\.nc-bento\b|\.nc-campaign\b/i.test(text)
        ) {
          add(
            findings,
            'fail',
            `Element "${id || '(missing id)'}" stores project-wide variables/media/bento CSS in ${key}. Global or responsive page CSS must live in pageSettings.customCss, global classes/variables, or native Bricks controls; element _cssCustom is too fragile for conversion fidelity.`,
            conversionRel,
            `${pointer}.settings.${key}`
          );
        }
      }
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

  for (const capability of extractCapabilityAttributes(website.text)) {
    if (!conversionText.includes(capability.name)) {
      add(findings, 'fail', `bricks_conversion_lost_kiwe_capability_attribute: Source Kiwe capability attribute ${capability.name}${capability.value ? `="${capability.value}"` : ''} was not preserved in the Bricks conversion package.`, conversionRel);
    } else if (capability.value && !conversionText.includes(capability.value)) {
      add(findings, 'warn', `Source Kiwe capability attribute ${capability.name} value "${capability.value}" is not visible in the conversion package.`, conversionRel);
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

function validateEmbeddedKiweTemplateMeta(root, templatePath, templateData, findings) {
  const templateRel = rel(root, templatePath);
  const meta = isPlainObject(templateData.kiwe) ? templateData.kiwe : (isPlainObject(templateData.kiweConversion) ? templateData.kiweConversion : null);
  if (!meta) {
    add(
      findings,
      'warn',
      'Bricks template export has no embedded Kiwe fidelity metadata. It can be uploaded to Bricks, but /audit /bricksconversion has limited source/parity evidence. Prefer a top-level "kiwe" object for no-loss proof when tokens allow.',
      templateRel,
      '$.kiwe'
    );
    return;
  }
  const schema = String(meta.schema || '').trim();
  if (schema && !/^kiwe\.bricks-(?:template|conversion)\.v\d+$/i.test(schema)) {
    add(findings, 'warn', 'Embedded Kiwe metadata uses an unknown schema. Use kiwe.bricks-template.v1 for a one-file Bricks upload artifact.', templateRel, '$.kiwe.schema');
  }
  const sourceHtml = String(meta.source && (meta.source.html || meta.source.path) || '');
  if (sourceHtml && !sourceHtml.replace(/\\/g, '/').endsWith('website/bricks-paste.html')) {
    add(findings, 'warn', 'Embedded Kiwe metadata source.html should point to website/bricks-paste.html.', templateRel, '$.kiwe.source.html');
  }
  if (meta.target && isPlainObject(meta.target)) {
    const importMethod = String(meta.target.importMethod || '').trim();
    if (importMethod && importMethod !== 'bricks-admin-template-upload' && importMethod !== 'kiwe-staging-executor') {
      add(findings, 'warn', 'Embedded Kiwe metadata for a Bricks template upload should use importMethod bricks-admin-template-upload or kiwe-staging-executor.', templateRel, '$.kiwe.target.importMethod');
    }
  }
}

function validateNotes(root, findings, options = {}) {
  const notes = readNotesText(root);
  if (!notes.text) {
    add(findings, 'info', 'BRICKS-CONVERSION-NOTES.md is absent. This is correct for lean SEAM Compiler output.');
    return;
  }
  if (!options.documented) {
    add(
      findings,
      'fail',
      'Documentation/report files were emitted without `/document`. Lean Bricks commands must output only the requested Bricks artifact(s); rerun with `/document` only when notes are explicitly requested.',
      rel(root, notes.file)
    );
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

function summarizeFindings(findings) {
  return findings.reduce(
    (acc, item) => {
      const level = item.level || 'info';
      acc[level] = (acc[level] || 0) + 1;
      return acc;
    },
    { fail: 0, warn: 0, info: 0 }
  );
}

export function validateBricksConversion(target = '.', options = {}) {
  const findings = [];
  const targetPath = path.resolve(target || '.');
  const targetIsFile = fs.existsSync(targetPath) && fs.statSync(targetPath).isFile();
  const { root, conversionPath } = findConversionPath(target);
  if (!conversionPath) {
    const native = findNativeTemplatePath(target);
    if (native.templatePath) {
      validateBricksTemplateExport(native.root, rel(native.root, native.templatePath), findings, '', '$');
      const templateData = readJson(native.templatePath, findings, `Bricks template export ${rel(native.root, native.templatePath)}`);
      if (templateData && isPlainObject(templateData)) {
        validateEmbeddedKiweTemplateMeta(native.root, native.templatePath, templateData, findings);
      }
      if (!targetIsFile) validateNotes(native.root, findings, options);
      const summary = summarizeFindings(findings);
      return {
        ok: summary.fail === 0,
        schema: 'kiwe.bricks-conversion-validation.v1',
        target: path.resolve(target || '.'),
        mode: 'native-bricks-template',
        templatePath: native.templatePath,
        siteGraphPath: options.siteGraphPath ? path.resolve(options.siteGraphPath) : '',
        findings,
        summary
      };
    }
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
      findings: [{ level: 'fail', message: 'Missing native bricks-template/*-template-upload.json or bricks-conversion/kiwe-bricks-conversion.json.' }],
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
    if (isLikelyBricksTemplateExport(conversion)) {
      validateBricksTemplateExport(root, path.basename(conversionPath), findings, conversionRel, '$');
      validateEmbeddedKiweTemplateMeta(root, conversionPath, conversion, findings);
      if (!targetIsFile) validateNotes(root, findings, options);
      const summary = summarizeFindings(findings);
      return {
        ok: summary.fail === 0,
        schema: 'kiwe.bricks-conversion-validation.v1',
        target: path.resolve(target || '.'),
        mode: 'native-bricks-template',
        templatePath: conversionPath,
        siteGraphPath,
        findings,
        summary
      };
    }
    validateRoot(conversion, findings, conversionRel, root);
    validateElements(asArray(conversion.elements), findings, conversionRel, siteIndex);
    const conversionRuntimeCodeElements = collectRuntimeCodeElements(asArray(conversion.elements));
    for (const item of conversionRuntimeCodeElements.slice(0, 20)) {
      add(
        findings,
        'fail',
        `Bricks Code element "${item.label}" contains runtime/custom-code settings (${item.keys.join(', ')}). SEAM Compiler must not ship representable page layout/design or JavaScript authority as a Code element; use native Bricks elements, controls, interactions, Kiwe capability attributes, or an explicit review-only unsupported exception.`,
        conversionRel,
        item.path.replace('$.content/header/footer', '$.elements')
      );
    }
    if (conversionRuntimeCodeElements.length > 20) {
      add(
        findings,
        'fail',
        `Bricks conversion contains ${conversionRuntimeCodeElements.length - 20} additional runtime Code elements. Treat external-converter output as scaffold/review-only until normalized.`,
        conversionRel,
        '$.elements'
      );
    }
    const website = readWebsiteText(root);
    validateSourceParity({
      conversion,
      conversionText,
      website,
      bindingsPath: findBindingsPath(root),
      siteGraphPath,
      findings,
      conversionRel,
      siteIndex,
      root
    });
    validateResponsiveLayoutFidelity({
      conversion,
      conversionText,
      website,
      findings,
      conversionRel
    });
    validateNativeStyleFidelity({
      conversion,
      findings,
      conversionRel
    });
    validateTemplateUploadConversionCss({
      conversion,
      findings,
      conversionRel
    });
  }
  validateNotes(root, findings, options);

  const summary = summarizeFindings(findings);

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
