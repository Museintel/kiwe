#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  console.log('Usage: node tools/audit-output.cjs <handoff-or-ai-output-dir-or-bricks-template.json> [--documented]');
  process.exit(0);
}

const target = path.resolve(process.argv[2] || '.');
const targetIsFile = fs.existsSync(target) && fs.statSync(target).isFile();
const root = targetIsFile ? path.dirname(target) : target;
const documented = process.argv.includes('--documented');

function walk(dir, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (!['node_modules', '.git', 'dist', 'build', 'kiwe-contracts'].includes(entry.name)) walk(full, out);
    } else if (entry.name !== 'KIWE_CONTEXT.md') {
      out.push(full);
    }
  }
  return out;
}

function rel(file) {
  return path.relative(root, file).replace(/\\/g, '/');
}

function read(file) {
  try {
    return fs.readFileSync(file, 'utf8');
  } catch (_) {
    return '';
  }
}

function exists(relPath) {
  return fs.existsSync(path.join(root, relPath));
}

const files = targetIsFile ? [target] : walk(root);
const textFiles = files.filter((file) => /\.(html|css|js|json|md|txt|tsx|ts|jsx)$/i.test(file));
const allText = textFiles.map((file) => `\n--- ${rel(file)} ---\n${read(file)}`).join('\n');
const findings = [];
let seamRoles = null;
let coreScreens = null;
let screenPayloads = null;
let officialTokenNames = null;

const themeTokenTopLevelKeys = new Set(['enabled', 'profile_label', 'overrides', 'bricks_theme_style']);
const frameworkCoreTokenCoverage = [
  ['color-brand', '--kiwe-color-brand', ['colorPrimary', 'color_primary', 'primary', 'brand', 'linkColor', 'link_color', 'colorLink', 'color_link']],
  ['color-accent', '--kiwe-color-accent', ['colorSecondary', 'color_secondary', 'secondary', 'accent', 'linkHoverColor', 'link_hover_color']],
  ['color-surface', '--kiwe-color-surface', ['siteBackground', 'site_background', 'background', 'colorSurface', 'color_surface', 'surface', 'colorLight', 'color_light', 'light']],
  ['color-surface-raised', '--kiwe-color-surface-raised', ['colorSurfaceRaised', 'color_surface_raised', 'surfaceRaised']],
  ['color-text', '--kiwe-color-text', ['colorDark', 'color_dark', 'dark']],
  ['color-text-muted', '--kiwe-color-text-muted', ['colorMuted', 'color_muted', 'muted']],
  ['color-border', '--kiwe-color-border', ['colorBorder', 'color_border', 'borderColor', 'border_color']],
  ['font-display', '--kiwe-font-display', ['fontDisplay', 'font_display', 'displayFont', 'display_font']],
  ['font-body', '--kiwe-font-body', ['fontBody', 'font_body', 'bodyFont', 'body_font']],
  ['type-h1', '--kiwe-type-h1', ['typeH1', 'type_h1']],
  ['type-body', '--kiwe-type-body', ['typeBody', 'type_body']],
  ['space-md', '--kiwe-space-md', ['spaceMd', 'space_md']],
  ['radius-lg', '--kiwe-radius-lg', ['radiusLg', 'radius_lg', 'radiusLarge', 'radius_large']],
  ['shadow-md', '--kiwe-shadow-md', ['shadowMd', 'shadow_md', 'shadowMedium', 'shadow_medium']]
];
const allowedThemeCssTokenAliases = new Set(['radius-panel', 'surface-panel']);
const screenCopyFields = {
  profile: new Set(['label', 'eyebrow', 'title', 'intro', 'accountLabel', 'editLabel', 'ordersTitle', 'ordersText', 'downloadsTitle', 'downloadsText', 'notificationsTitle', 'notificationsText', 'addressesTitle', 'addressesText', 'passwordTitle', 'passwordText', 'signOutLabel', 'recentOrdersTitle']),
  cart: new Set(['label', 'eyebrow', 'title', 'emptyTitle', 'emptyText', 'fbtTitle', 'checkoutLabel', 'checkoutEmptyLabel']),
  checkout: new Set(['label', 'title', 'loadingText', 'unavailableText', 'continueLabel', 'returnLabel', 'shippingToggleLabel', 'accountToggleLabel']),
  search: new Set(['label', 'eyebrow', 'title', 'intro', 'placeholder']),
  menu: new Set(['label', 'eyebrow', 'title', 'intro', 'contextTitle', 'dashboardLabel']),
  saved: new Set(['label', 'eyebrow', 'title', 'intro', 'emptyTitle', 'emptyText', 'wishlistLabel', 'bookmarksLabel', 'summaryWishlistLabel', 'summaryBookmarksLabel', 'summaryTotalLabel']),
  links: new Set(['label', 'eyebrow', 'title', 'intro', 'shopLabel', 'shopMeta', 'cartLabel', 'cartMeta']),
  notifications: new Set(['label', 'eyebrow', 'title', 'intro', 'topicsLegend', 'channelsLegend', 'appText', 'submitLabel', 'emailPlaceholder', 'phonePlaceholder']),
  'ios-install': new Set(['label', 'eyebrow', 'title', 'intro', 'stepOneTitle', 'stepOneText', 'stepTwoTitle', 'stepTwoText', 'stepThreeTitle', 'stepThreeText', 'doneLabel']),
  games: new Set(['label', 'eyebrow', 'startTitle', 'startText', 'mobileStartText', 'chooseText', 'scoreLabel', 'bestLabel']),
  ai: new Set(['label', 'eyebrow', 'title', 'intro', 'emptyTitle', 'emptyText', 'chatPlaceholder'])
};

function getSeamRoles() {
  if (seamRoles) return seamRoles;
  seamRoles = new Set();
  const candidates = [
    path.join(__dirname, '..', 'packs', 'website-builder', 'contracts', 'seam-vocabulary.json'),
    path.join(__dirname, '..', 'packs', 'appshell-theme', 'seam-vocabulary.json')
  ];
  for (const file of candidates) {
    if (!fs.existsSync(file)) continue;
    try {
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      for (const role of json.role || []) seamRoles.add(String(role));
    } catch (_) {
      // Non-fatal; audit can continue without role checks.
    }
    if (seamRoles.size) break;
  }
  return seamRoles;
}

function getCoreScreens() {
  if (coreScreens) return coreScreens;
  coreScreens = new Set();
  const payloads = getScreenPayloads();
  if (payloads) {
    for (const screen of Object.keys(payloads.screens || {})) coreScreens.add(String(screen));
    return coreScreens;
  }
  return coreScreens;
}

function getScreenPayloads() {
  if (screenPayloads) return screenPayloads;
  screenPayloads = null;
  const candidates = [
    path.join(__dirname, '..', 'packs', 'appshell-theme', 'screen-payloads.json'),
    path.join(__dirname, '..', 'packs', 'website-builder', 'screen-payloads.json')
  ];
  for (const file of candidates) {
    if (!fs.existsSync(file)) continue;
    try {
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      screenPayloads = json;
    } catch (_) {
      // Non-fatal; audit can continue without screen coverage checks.
    }
    if (screenPayloads) break;
  }
  return screenPayloads;
}

function getOfficialTokenNames() {
  if (officialTokenNames) return officialTokenNames;
  officialTokenNames = new Set();
  const candidates = [
    path.join(__dirname, '..', '..', 'wp-content', 'mu-plugins', 'dsa', 'includes', 'Design', 'Seam_Token_Service.php'),
    path.join(__dirname, '..', '..', 'wp-content', 'mu-plugins', 'dsa', 'ui-system', 'token-map.css'),
    path.join(__dirname, '..', 'packs', 'website-builder', 'contracts', 'token-map.css')
  ];
  for (const file of candidates) {
    if (!fs.existsSync(file)) continue;
    const body = read(file);
    for (const match of body.matchAll(/self::token\(\s*['"]([^'"]+)['"]/g)) {
      officialTokenNames.add(String(match[1]));
    }
    for (const match of body.matchAll(/['"]name['"]\s*=>\s*['"]([^'"]+)['"]/g)) {
      officialTokenNames.add(String(match[1]));
    }
    for (const match of body.matchAll(/--kiwe-([a-z0-9-]+)/g)) {
      const name = String(match[1]);
      if (!name.startsWith('theme-')) officialTokenNames.add(name);
    }
    if (officialTokenNames.size) break;
  }
  return officialTokenNames;
}

function add(level, message, file = '') {
  findings.push({ level, message, file });
}

function selectorIsMentioned(selector, cssText) {
  if (!selector || !cssText) return false;
  return String(selector)
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean)
    .some((part) => cssText.includes(part));
}

const responsiveLayoutKeyPattern = /^_(?:cssCustom|direction|display|grid|gridItem|gridTemplate|gridAuto|align|justify|place|flex|gap|rowGap|columnGap|order|width|widthMin|widthMax|height|heightMin|heightMax|minWidth|maxWidth|minHeight|maxHeight|aspectRatio|margin|padding|position|top|right|bottom|left|zIndex|overflow|masonry)[A-Za-z0-9_]*:[a-z][a-z0-9_-]{1,48}(?::[a-z-]+)?$/i;
const complexLayoutPattern = /\b(?:bento|campaign-grid|masonry|editorial-grid)\b|grid-template-(?:columns|rows|areas)\s*:|grid-auto-(?:columns|rows|flow)\s*:|grid-column\s*:|grid-row\s*:|@media[\s\S]{0,1600}(?:grid-template|grid-column|grid-row|flex-direction|\.nc-section-head|\.seam-spread)/i;
const bricksLayoutElementNames = new Set(['container', 'div', 'section', 'block']);
const nativeStyleControlPattern = /^_(?:typography|background|gradient|border|boxShadow|transform|transformOrigin|cssFilters|cssTransition|display|grid|gridItem|gridTemplate|gridAuto|justifyItemsGrid|alignItemsGrid|justifyContentGrid|alignContentGrid|direction|alignSelf|alignItems|justifyContent|flexWrap|flexGrow|flexShrink|flexBasis|columnGap|rowGap|gap|width|widthMin|widthMax|height|heightMin|heightMax|margin|padding|position|top|right|bottom|left|zIndex|overflow|objectFit|objectPosition|opacity|isolation|mixBlendMode|pointerEvents|perspective|perspectiveOrigin|color|textAlign|font|lineHeight|letterSpacing)(?::|$)/;
const tokenOwnedNestedKeyPattern = /^(?:font-size|fontSize|line-height|lineHeight|letter-spacing|letterSpacing|top|right|bottom|left|width|height|widthMin|widthMax|heightMin|heightMax|minWidth|maxWidth|minHeight|maxHeight|radius|offsetX|offsetY|blur|spread|translateX|translateY|translateZ|x|y|gap|rowGap|columnGap)$/i;
const tokenOwnedColorControlPattern = /^_(?:typography|background|gradient|border|boxShadow|cssFilters|color|fill|stroke|cssCustom)(?::|$)/;
const tokenOwnedColorNestedKeyPattern = /^(?:color|background|backgroundColor|background-color|backgroundImage|background-image|gradient|raw|hex|rgb|hsl|hue|saturation|lightness|fill|stroke|borderColor|border-color|shadowColor|shadow-color)$/i;
const colorLiteralPattern = /#[0-9a-fA-F]{3,8}\b|\b(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color)\s*\([^)]*\)|\b(?:white|black)\b/gi;
const mappableCssDeclarationPattern = /\b(?:display|flex(?:-direction|-wrap|-grow|-shrink|-basis)?|align-items|align-self|justify-content|justify-items|align-content|gap|row-gap|column-gap|grid-template-columns|grid-template-rows|grid-auto-flow|grid-auto-columns|grid-auto-rows|grid-column|grid-row|width|max-width|min-width|height|max-height|min-height|aspect-ratio|margin(?:-(?:top|right|bottom|left))?|padding(?:-(?:top|right|bottom|left))?|position|top|right|bottom|left|z-index|overflow|opacity|background(?:-color|-image|-size|-position|-repeat)?|color|border(?:-(?:radius|color|width|style))?|box-shadow|font(?:-(?:family|size|weight|style))?|line-height|letter-spacing|text-align|text-transform|transform|filter|transition)\s*:/gi;
const customCssHeavyBytes = 12000;
const customCssNativeStyleMinControls = 60;
const mappableCssDeclarationMin = 40;
const mappableCssNativeStyleRatio = 0.45;
const largeClipboardElementCount = 180;
const templateUploadCustomCssBytes = 2500;
const templateUploadMappableCssDeclarationMin = 12;
const templateUploadMinElementNativeControlsPerElement = 1.15;
const templateUploadMaxClassOnlyElementRatio = 0.25;
const supportedTemplateBricksVersionPattern = /^2\.3(?:\.|$)/;
const reviewOnlyCodeElementAllowancePattern = /\b(?:review-only|manual-review|unsupported|code-exception)\b/i;
const bricksCompileUnsafeControlPattern = /^_(?:minWidth|maxWidth|minHeight|maxHeight)(?::|$)/;
const bricksFontFamilyTokenPattern = /var\(\s*--/i;
const semanticHeadingTagPattern = /^h[1-6]$/i;
const semanticHeadingTypeTokenPattern = /var\(\s*--(?:kiwe|seam)-type-h[1-6]\b/i;
const bricksImportMethods = new Set(['review-only', 'bricks-clipboard-json', 'bricks-admin-template-upload', 'kiwe-staging-executor']);

function isBricksLayoutElement(value) {
  return value && typeof value === 'object' && bricksLayoutElementNames.has(String(value.name || '').toLowerCase());
}

function elementSettings(value) {
  return value && typeof value === 'object' && value.settings && typeof value.settings === 'object' && !Array.isArray(value.settings)
    ? value.settings
    : {};
}

function collectBricksElementMisuse(value, out = []) {
  if (Array.isArray(value)) {
    for (const item of value) collectBricksElementMisuse(item, out);
  } else if (value && typeof value === 'object') {
    const settings = elementSettings(value);
    if (String(value.name || '').toLowerCase() === 'code') {
      const reviewText = JSON.stringify({
        classes: settings._cssClasses || '',
        attributes: settings._attributes || [],
        kiwe: value.kiwe || {}
      });
      const runtimeKeys = Object.entries(settings)
        .filter(([key, settingValue]) => {
          if (!/^(?:code|css|cssCode|javascriptCode|js|html|php|executeCode)$/i.test(String(key))) return false;
          if (key === 'executeCode' && settingValue === true) return true;
          return String(settingValue || '').trim() !== '';
        })
        .map(([key]) => key);
      if (runtimeKeys.length && !reviewOnlyCodeElementAllowancePattern.test(reviewText)) {
        out.push(`Bricks Code element "${value.id || value.label || 'unknown'}" contains runtime/custom-code settings (${Array.from(new Set(runtimeKeys)).join(', ')}). External converter output may use Code elements as a temporary scaffold, but Kiwe /convert /bricks must decompose representable layout/design into native Bricks controls or mark the artifact review-only.`);
      }
    }
    if (isBricksLayoutElement(value)) {
      for (const [key] of Object.entries(settings)) {
        if (/^_flexDirection(?::|$)/.test(key)) {
          out.push(`Bricks layout element "${value.id || 'unknown'}" (${value.name}) uses ${key}; layout elements must use _direction / _direction:<breakpoint>.`);
        }
      }
    }
    for (const [key, settingValue] of Object.entries(settings)) {
      if (!/^_cssCustom(?::|$)/.test(key) || typeof settingValue !== 'string') continue;
      if (
        settingValue.length > 4000 &&
        /(?:^|\n|\r)\s*:root\b|@media\b|#home-campaigns\b|\.nc-bento\b|\.nc-campaign\b/i.test(settingValue)
      ) {
        out.push(`Element "${value.id || 'unknown'}" stores project-wide variables/media/bento CSS in ${key}; use pageSettings.customCss, global classes/variables, or native Bricks controls instead of one fragile element custom-CSS bucket.`);
      }
    }
    for (const item of Object.values(value)) collectBricksElementMisuse(item, out);
  }
  return out;
}

function settingHasAttribute(settings, name, valuePattern = null) {
  const wanted = String(name || '').toLowerCase();
  return (Array.isArray(settings?._attributes) ? settings._attributes : []).some((attribute) => {
    if (!attribute || typeof attribute !== 'object' || Array.isArray(attribute)) return false;
    if (String(attribute.name || '').toLowerCase() !== wanted) return false;
    return valuePattern ? valuePattern.test(String(attribute.value || '')) : true;
  });
}

function collectImplicitBricksLayoutControls(items, prefix) {
  const problems = [];
  const list = Array.isArray(items) ? items : [];
  list.forEach((item, index) => {
    if (!item || typeof item !== 'object' || Array.isArray(item)) return;
    const settings = elementSettings(item);
    const label = String(item.id || item.name || item.label || `item-${index}`);
    const classes = String(settings._cssClasses || '');
    const display = String(settings._display || '').toLowerCase();
    const isRail = /\bseam-horizontal-rail\b/.test(classes) || settingHasAttribute(settings, 'data-flow', /^horizontal-rail$/i);

    if (isBricksLayoutElement(item) && display === 'flex' && !Object.prototype.hasOwnProperty.call(settings, '_direction')) {
      problems.push(`${prefix} layout element "${label}" sets _display:flex but omits _direction. Bricks source-backed layout controls must explicitly own flex direction; relying on browser defaults causes rail/card drift and makes the visual editor ambiguous.`);
    }

    if (isBricksLayoutElement(item) && display === 'grid' && !(Array.isArray(item.children) && item.children.length === 0)) {
      const hasColumns = Object.keys(settings).some((key) => /^_grid(?:TemplateColumns|AutoColumns)(?::|$)/.test(key));
      if (!hasColumns) {
        problems.push(`${prefix} layout element "${label}" sets _display:grid but omits _gridTemplateColumns/_gridAutoColumns. Grid layout must be represented by Bricks-native grid controls, not implicit CSS/default behavior.`);
      }
    }

    if (isRail) {
      if (display !== 'flex') {
        problems.push(`${prefix} Seam horizontal rail "${label}" must set Bricks _display:flex on the actual item track. Rail semantics alone do not create Bricks-native layout ownership.`);
      }
      if (String(settings._direction || '').toLowerCase() !== 'row') {
        problems.push(`${prefix} Seam horizontal rail "${label}" must set Bricks _direction:row. This is the source-backed control that preserves category/product rail orientation in Bricks 2.3.x/2.4.`);
      }
      if (!/(auto|scroll)/i.test(String(settings._overflow || ''))) {
        problems.push(`${prefix} Seam horizontal rail "${label}" must set Bricks _overflow:auto or scroll so the actual rail track remains scrollable after import.`);
      }
      if (!Object.prototype.hasOwnProperty.call(settings, '_columnGap') && !Object.prototype.hasOwnProperty.call(settings, '_gap')) {
        problems.push(`${prefix} Seam horizontal rail "${label}" must expose a tokenized Bricks _columnGap or _gap control; spacing cannot be hidden in defaults or external CSS.`);
      }
    }
  });
  return problems;
}

function collectCustomCssBuckets(value, out = []) {
  if (Array.isArray(value)) {
    for (const item of value) collectCustomCssBuckets(item, out);
  } else if (value && typeof value === 'object') {
    for (const [key, item] of Object.entries(value)) {
      if ((/^_cssCustom(?::|$)/.test(key) || key === 'customCss') && typeof item === 'string' && item.trim()) {
        out.push(item);
      }
      collectCustomCssBuckets(item, out);
    }
  }
  return out;
}

function countNativeStyleControls(value) {
  let count = 0;
  if (Array.isArray(value)) {
    for (const item of value) count += countNativeStyleControls(item);
  } else if (value && typeof value === 'object') {
    const settings = elementSettings(value);
    for (const key of Object.keys(settings)) {
      if (nativeStyleControlPattern.test(key) && !/^_cssCustom(?::|$)/.test(key)) count += 1;
    }
    for (const item of Object.values(value)) count += countNativeStyleControls(item);
  }
  return count;
}

function countNativeStyleControlsOnItem(value) {
  const settings = elementSettings(value);
  let count = 0;
  for (const key of Object.keys(settings)) {
    if (nativeStyleControlPattern.test(key) && !/^_cssCustom(?::|$)/.test(key)) count += 1;
  }
  return count;
}

function collectStyledTemplateGlobalClasses(globalClasses) {
  return (Array.isArray(globalClasses) ? globalClasses : [])
    .filter((globalClass) => countNativeStyleControlsOnItem(globalClass) > 0)
    .map((globalClass) => ({
      id: String(globalClass.id || ''),
      name: String(globalClass.name || ''),
      controls: countNativeStyleControlsOnItem(globalClass)
    }));
}

function templateEditabilityStats(elements) {
  const list = Array.isArray(elements) ? elements : [];
  const elementNativeControls = list.reduce((sum, element) => sum + countNativeStyleControlsOnItem(element), 0);
  const classOnlyElements = list.filter((element) => {
    const settings = elementSettings(element);
    return countNativeStyleControlsOnItem(element) === 0 && Array.isArray(settings._cssGlobalClasses) && settings._cssGlobalClasses.length > 0;
  }).length;
  return {
    elementCount: list.length,
    elementNativeControls,
    classOnlyElements,
    elementNativeControlsPerElement: list.length ? elementNativeControls / list.length : 0,
    classOnlyElementRatio: list.length ? classOnlyElements / list.length : 0
  };
}

function collectBricksTemplateVariableNameMisuse(templateData, relPath) {
  const problems = [];
  for (const lane of ['global_variables', 'globalVariables']) {
    const variables = Array.isArray(templateData?.[lane]) ? templateData[lane] : [];
    variables.forEach((variable, index) => {
      if (!variable || typeof variable !== 'object' || Array.isArray(variable)) return;
      const name = String(variable.name || '').trim();
      if (name.startsWith('--')) {
        problems.push(`Bricks template export ${relPath} global variable "${name}" at $.${lane}[${index}].name includes leading "--". Native Bricks variable records must store names without the CSS custom-property prefix because Bricks emits it during CSS compilation; otherwise "${name}" becomes "----${name.replace(/^--/, '')}" and controls consuming var(${name}, ...) disconnect from the token.`);
      }
      const value = String(variable.value || '').trim();
      if (value.includes('var(')) {
        for (const call of extractCssFunctionCalls(value, 'var')) {
          const args = splitCssFunctionArgs(call);
          const cssVariable = String(args[0] || '').trim();
          const hasFallback = args.length >= 2 && args.slice(1).join(',').trim() !== '';
          if (/^--[a-z][a-z0-9_-]*$/i.test(cssVariable) && hasFallback) {
            problems.push(`Bricks template export ${relPath} global variable "${name}" at $.${lane}[${index}].value references "${cssVariable}" with an inline fallback in "${value}". Template variables must not smuggle render values through fallbacks; define the real value in the paired Kiwe Framework profile / Bricks variable push and consume bare variables in the template.`);
          }
        }
      }
    });
  }
  return problems;
}

function collectBricksCompilerUnsafeControls(items, prefix) {
  const problems = [];
  const list = Array.isArray(items) ? items : [];
  list.forEach((item, index) => {
    const settings = elementSettings(item);
    const label = String(item?.id || item?.name || item?.label || `item-${index}`);
    const isSemanticHeading = String(item?.name || '').toLowerCase() === 'heading' && semanticHeadingTagPattern.test(String(settings.tag || ''));
    for (const [key, value] of Object.entries(settings)) {
      if (bricksCompileUnsafeControlPattern.test(key)) {
        problems.push(`${prefix} native control "${key}" on "${label}" is not compiler-safe for My Templates output. Use Bricks source-backed controls _widthMin/_widthMax/_heightMin/_heightMax instead of _minWidth/_maxWidth/_minHeight/_maxHeight; otherwise Bricks can keep the JSON but silently omit the frontend CSS rule.`);
      }
      if ((key === '_typography' || /^_typography:/.test(key)) && value && typeof value === 'object' && !Array.isArray(value)) {
        const fontSize = value['font-size'] ?? value.fontSize ?? value.font_size;
        if (isSemanticHeading && typeof fontSize === 'string' && semanticHeadingTypeTokenPattern.test(fontSize)) {
          problems.push(`${prefix} semantic heading "${label}" is tagged "${String(settings.tag || '')}" but locks its own font-size to "${fontSize}". Semantic heading scale belongs in Kiwe > Framework / Bricks Theme Style; remove local heading-token font-size so changing h3 to h2/h4 in Bricks uses the selected heading level.`);
        }
        const fontFamily = value['font-family'] ?? value.fontFamily ?? value.font_family;
        if (typeof fontFamily === 'string' && bricksFontFamilyTokenPattern.test(fontFamily)) {
          problems.push(`${prefix} typography control "${key}" on "${label}" stores font-family as "${fontFamily}". Bricks quotes typography font-family output, so CSS-variable font stacks become invalid literal families like font-family: "var(--kiwe-font-body, ...)". Use a concrete Bricks font-family value in _typography and keep tokenized font families in the Framework/theme layer.`);
        }
      }
      if ((key === '_background' || /^_background:/.test(key)) && value && typeof value === 'object' && !Array.isArray(value) && typeof value.color === 'string') {
        problems.push(`${prefix} color control "${key}" on "${label}" stores color as a plain string "${value.color}". Bricks frontend CSS generation expects color objects such as { "raw": "var(--kiwe-color-surface)" }; plain strings can remain in JSON but be omitted from compiled frontend CSS.`);
      }
      if ((key === '_background' || /^_background:/.test(key)) && value && typeof value === 'object' && !Array.isArray(value) && value.color && typeof value.color === 'object' && !Array.isArray(value.color) && typeof value.color.raw === 'string' && /gradient\(/i.test(value.color.raw)) {
        problems.push(`${prefix} background color control "${key}" on "${label}" stores a gradient in color.raw. Bricks compiles _background.color to background-color, where gradients are invalid; use the native _gradient control with tokenized color stops and keep _background.color as a solid fallback.`);
      }
      if ((key === '_border' || /^_border:/.test(key)) && value && typeof value === 'object' && !Array.isArray(value) && typeof value.color === 'string') {
        problems.push(`${prefix} color control "${key}" on "${label}" stores color as a plain string "${value.color}". Bricks frontend CSS generation expects color objects such as { "raw": "var(--kiwe-color-border, rgba(...))" }; plain strings can remain in JSON but be omitted from compiled frontend CSS.`);
      }
      if ((key === '_border' || /^_border:/.test(key)) && value && typeof value === 'object' && !Array.isArray(value) && value.radius && typeof value.radius === 'object' && !Array.isArray(value.radius)) {
        const invalidRadiusKeys = ['topLeft', 'topRight', 'bottomRight', 'bottomLeft'].filter((radiusKey) => Object.prototype.hasOwnProperty.call(value.radius, radiusKey));
        if (invalidRadiusKeys.length) {
          problems.push(`${prefix} border-radius control "${key}" on "${label}" uses CSS corner keys "${invalidRadiusKeys.join(', ')}". Bricks frontend CSS generation reads radius.top, radius.right, radius.bottom, and radius.left; topLeft/topRight/bottomRight/bottomLeft can remain in JSON but compile to no radius.`);
        }
      }
      if ((key === '_typography' || /^_typography:/.test(key)) && value && typeof value === 'object' && !Array.isArray(value) && typeof value.color === 'string') {
        problems.push(`${prefix} color control "${key}" on "${label}" stores color as a plain string "${value.color}". Bricks frontend CSS generation expects color objects such as { "raw": "var(--kiwe-color-text)" }; plain strings can remain in JSON but be omitted from compiled frontend CSS.`);
      }
    }
  });
  return problems;
}

function countMappableCssDeclarations(cssText) {
  const text = String(cssText || '');
  mappableCssDeclarationPattern.lastIndex = 0;
  let count = 0;
  while (mappableCssDeclarationPattern.exec(text)) count += 1;
  return count;
}

function cssFunctionRanges(value, functionName) {
  const text = String(value || '');
  const lower = text.toLowerCase();
  const needle = `${String(functionName || '').toLowerCase()}(`;
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

function directColorLiterals(value) {
  if (typeof value !== 'string') return [];
  const varRanges = cssFunctionRanges(value, 'var');
  const literals = [];
  colorLiteralPattern.lastIndex = 0;
  let match;
  while ((match = colorLiteralPattern.exec(value))) {
    const literal = String(match[0] || '').trim();
    if (literal && !indexInsideRanges(match.index, varRanges)) literals.push(literal);
  }
  return literals;
}

function colorOwnedChild(parentOwned, key) {
  return parentOwned || tokenOwnedColorControlPattern.test(String(key || '')) || tokenOwnedColorNestedKeyPattern.test(String(key || ''));
}

function collectUntokenizedColorValues(value, out = [], trail = '$', parentOwned = false) {
  if (Array.isArray(value)) {
    value.forEach((item, index) => collectUntokenizedColorValues(item, out, `${trail}[${index}]`, parentOwned));
  } else if (value && typeof value === 'object') {
    for (const [key, item] of Object.entries(value)) {
      collectUntokenizedColorValues(item, out, `${trail}.${key}`, colorOwnedChild(parentOwned, key));
    }
  } else if (parentOwned && typeof value === 'string') {
    const literals = directColorLiterals(value);
    if (literals.length) out.push({ path: trail, value: String(value), literals });
  }
  return out;
}

function collectBricksColorTokenMisuse(items, prefix) {
  const problems = [];
  const limit = 40;
  for (const [index, item] of (Array.isArray(items) ? items : []).entries()) {
    if (!item || typeof item !== 'object' || Array.isArray(item)) continue;
    const label = item.id || item.name || item.label || `item-${index}`;
    const settings = elementSettings(item);
    for (const problem of collectUntokenizedColorValues(settings, [], `$[${index}].settings`)) {
      problems.push(`${prefix} native style "${problem.path}" on "${label}" uses direct color literal(s) "${problem.literals.join(', ')}". /convert /bricks outputs must be 100% Seam/Framework integrated: component colors, gradients, borders, shadows, fills, and local CSS variables must consume var(--kiwe-*), var(--seam-*), or declared project variables. Literal colors are allowed only at token-definition/fallback layer, not direct component styling.`);
      if (problems.length >= limit) break;
    }
    if (problems.length >= limit) break;
  }
  const total = (Array.isArray(items) ? items : []).reduce((sum, item) => {
    if (!item || typeof item !== 'object' || Array.isArray(item)) return sum;
    return sum + collectUntokenizedColorValues(elementSettings(item)).length;
  }, 0);
  if (total > limit) problems.push(`${prefix} contains ${total - limit} additional direct color literal issues beyond the first ${limit}. Replace them with official Kiwe/Seam tokens or declared project variables, then rerun /audit /bricksconversion.`);
  return problems;
}

function splitCssFunctionArgs(value) {
  const text = String(value || '');
  const args = [];
  let depth = 0;
  let start = 0;
  for (let index = 0; index < text.length; index += 1) {
    if (text[index] === '(') depth += 1;
    if (text[index] === ')') depth -= 1;
    if (text[index] === ',' && depth === 0) {
      args.push(text.slice(start, index).trim());
      start = index + 1;
    }
  }
  args.push(text.slice(start).trim());
  return args;
}

function extractCssFunctionCalls(value, functionName) {
  const text = String(value || '');
  const lower = text.toLowerCase();
  const needle = `${String(functionName || '').toLowerCase()}(`;
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

function collectCssVariablesWithoutFallback(value, out = [], trail = '$', parentOwned = false) {
  if (Array.isArray(value)) {
    value.forEach((item, index) => collectCssVariablesWithoutFallback(item, out, `${trail}[${index}]`, parentOwned));
  } else if (value && typeof value === 'object') {
    for (const [key, item] of Object.entries(value)) {
      const owned = parentOwned || nativeStyleControlPattern.test(String(key || '')) || tokenOwnedNestedKeyPattern.test(String(key || '')) || tokenOwnedColorNestedKeyPattern.test(String(key || ''));
      collectCssVariablesWithoutFallback(item, out, `${trail}.${key}`, owned);
    }
  } else if (parentOwned && typeof value === 'string') {
    for (const call of extractCssFunctionCalls(value, 'var')) {
      const args = splitCssFunctionArgs(call);
      const variable = String(args[0] || '').trim();
      const hasFallback = args.length >= 2 && args.slice(1).join(',').trim() !== '';
      if (/^--[a-z][a-z0-9_-]*$/i.test(variable) && hasFallback) {
        out.push({ path: trail, value: String(value), variable });
      }
    }
  }
  return out;
}

function collectBricksVariableFallbackMisuse(items, prefix) {
  const problems = [];
  const limit = 40;
  for (const [index, item] of (Array.isArray(items) ? items : []).entries()) {
    if (!item || typeof item !== 'object' || Array.isArray(item)) continue;
    const label = item.id || item.name || item.label || `item-${index}`;
    const settings = elementSettings(item);
    for (const problem of collectCssVariablesWithoutFallback(settings, [], `$[${index}].settings`)) {
      problems.push(`${prefix} native style "${problem.path}" on "${label}" references "${problem.variable}" with an inline fallback in "${problem.value}". SeamFlow template render-owner settings must consume bare Framework/project variables only, e.g. var(${problem.variable}). Put the actual value in the paired Kiwe Framework profile / Bricks variable push so missing profile setup fails visibly instead of silently rendering from hidden fallback values.`);
      if (problems.length >= limit) break;
    }
    if (problems.length >= limit) break;
  }
  const total = (Array.isArray(items) ? items : []).reduce((sum, item) => {
    if (!item || typeof item !== 'object' || Array.isArray(item)) return sum;
    return sum + collectCssVariablesWithoutFallback(elementSettings(item)).length;
  }, 0);
  if (total > limit) problems.push(`${prefix} contains ${total - limit} additional CSS variable references with inline fallbacks beyond the first ${limit}. Remove fallbacks from Bricks render-owner settings and define those values in the paired Framework profile, then rerun /audit /bricksconversion.`);
  return problems;
}

function collectResponsiveLayoutOverrides(value, out = []) {
  if (Array.isArray(value)) {
    for (const item of value) collectResponsiveLayoutOverrides(item, out);
  } else if (value && typeof value === 'object') {
    if (value.settings && typeof value.settings === 'object' && !Array.isArray(value.settings)) {
      for (const [key, settingValue] of Object.entries(value.settings)) {
        if (responsiveLayoutKeyPattern.test(key)) {
          out.push({
            id: String(value.id || ''),
            key,
            value: settingValue,
            classes: String(value.settings._cssClasses || ''),
            cssId: String(value.settings._cssId || '')
          });
        }
      }
    }
    for (const item of Object.values(value)) collectResponsiveLayoutOverrides(item, out);
  }
  return out;
}

function conversionPackageRoot(file) {
  const dir = path.dirname(file);
  return path.basename(dir) === 'bricks-conversion' ? path.dirname(dir) : dir;
}

function resolvePackageFile(packageRoot, relPath) {
  const base = path.resolve(packageRoot || '.');
  const full = path.resolve(base, String(relPath || ''));
  return full === base || full.startsWith(`${base}${path.sep}`) ? full : '';
}

function validateBricksTemplateExport(packageRoot, templateRelPath) {
  const out = [];
  const relPath = String(templateRelPath || '').trim();
  if (!relPath) {
    out.push('kiwe-bricks-conversion.json target.templateExportPath is required when target.importMethod is bricks-admin-template-upload.');
    return out;
  }
  const templatePath = resolvePackageFile(packageRoot, relPath);
  if (!templatePath) {
    out.push('kiwe-bricks-conversion.json target.templateExportPath must stay inside the handoff package.');
    return out;
  }
  if (!fs.existsSync(templatePath) || !fs.statSync(templatePath).isFile()) {
    out.push(`kiwe-bricks-conversion.json target.templateExportPath does not exist: ${relPath}`);
    return out;
  }

  let templateData;
  try {
    templateData = JSON.parse(read(templatePath));
  } catch (error) {
    out.push(`Bricks template export ${relPath} is invalid JSON: ${error.message}`);
    return out;
  }
  if (!templateData || typeof templateData !== 'object' || Array.isArray(templateData)) {
    out.push(`Bricks template export ${relPath} must be a JSON object.`);
    return out;
  }
  if (templateData.schema === 'kiwe.bricks-conversion.v1' || Array.isArray(templateData.elements)) {
    out.push(`Bricks template export ${relPath} must not be a Kiwe conversion/audit envelope. Bricks My Templates import expects a native export with title plus content/header/footer.`);
  }
  if (!String(templateData.title || '').trim()) {
    out.push(`Bricks template export ${relPath} is missing title. Bricks imports this as "(no title)".`);
  }
  const title = String(templateData.title || '').trim();
  const populatedArea = ['content', 'header', 'footer'].find((key) => Array.isArray(templateData[key]) && templateData[key].length > 0);
  if (!populatedArea) {
    out.push(`Bricks template export ${relPath} must contain a non-empty content, header, or footer array. Otherwise Bricks insert reports "This template has no data".`);
  }
  const templateType = String(templateData.templateType || '').trim();
  if (!templateType) {
    out.push(`Bricks template export ${relPath} must include templateType so Bricks stores the imported template in the intended area/type.`);
  } else if (templateType === 'header' && populatedArea && populatedArea !== 'header') {
    out.push(`Bricks template export ${relPath} has templateType "header" but no non-empty header array.`);
  } else if (templateType === 'footer' && populatedArea && populatedArea !== 'footer') {
    out.push(`Bricks template export ${relPath} has templateType "footer" but no non-empty footer array.`);
  } else if (!['header', 'footer'].includes(templateType) && populatedArea && populatedArea !== 'content') {
    out.push(`Bricks template export ${relPath} is not header/footer, so it should use a non-empty content array.`);
  }
  const homepageHint = /(?:^|[\\/_-])home(?:page)?(?:[\\/_\-.]|$)/i.test(relPath) || /homepage|home\s+page/i.test(`${title} ${templateData.name || ''}`);
  if (homepageHint && title && title !== 'Home') {
    out.push(`Bricks template export ${relPath} should use title "Home" for a homepage body template.`);
  }
  if (homepageHint && templateType && templateType !== 'content') {
    out.push(`Bricks template export ${relPath} should use templateType "content" for a homepage body template unless section/header/footer is intentional.`);
  }
  if (!String(templateData.version || '').trim()) {
    out.push(`Bricks template export ${relPath} should include the target Bricks version used to author/verify the native template.`);
  } else if (!supportedTemplateBricksVersionPattern.test(String(templateData.version || '').trim())) {
    out.push(`Bricks template export ${relPath} declares version "${String(templateData.version || '').trim()}". Kiwe production template uploads currently target the public Bricks 2.3.x importer/runtime; do not emit unreleased/beta 2.4 template metadata unless the contract is explicitly updated after a public Bricks release.`);
  }
  if (Array.isArray(templateData.globalClasses) && templateData.globalClasses.length && !Array.isArray(templateData.global_classes)) {
    out.push(`Bricks template export ${relPath} uses top-level globalClasses but not global_classes. Bricks My Templates import reads global_classes for template class dependencies; include native Bricks global_classes so imported elements do not lose their editable class styles.`);
  }
  out.push(...collectBricksTemplateVariableNameMisuse(templateData, relPath));

  const templateCustomCssBuckets = collectCustomCssBuckets({
    pageSettings: templateData.pageSettings,
    settings: templateData.settings
  });
  const templateCustomCssText = templateCustomCssBuckets.join('\n');
  const templateCustomCssBytes = templateCustomCssBuckets.reduce((sum, text) => sum + String(text || '').length, 0);
  const templateMappableCss = countMappableCssDeclarations(templateCustomCssText);
  if (
    templateCustomCssBytes >= templateUploadCustomCssBytes ||
    templateMappableCss >= templateUploadMappableCssDeclarationMin ||
    /@media\b|#home-campaigns\b|\.nc-(?:bento|campaign|section-head)|grid-template|flex-direction/i.test(templateCustomCssText)
  ) {
    out.push(`Bricks template export ${relPath} carries ${templateCustomCssBytes} page/template custom CSS bytes and ${templateMappableCss} mappable declarations. Bricks My Templates insertion can leave pageSettings custom CSS behind or collide with stale target-page CSS; move ordinary layout/design into native element settings, importable globalClasses/globalVariables, or documented tiny exceptions.`);
  }

  const templateElements = []
    .concat(Array.isArray(templateData.content) ? templateData.content : [])
    .concat(Array.isArray(templateData.header) ? templateData.header : [])
    .concat(Array.isArray(templateData.footer) ? templateData.footer : []);
  for (const message of collectBricksElementMisuse(templateElements)) {
    out.push(`Bricks template export ${relPath} ${message}`);
  }
  const templateStyleItems = templateElements
    .concat(Array.isArray(templateData.global_classes) ? templateData.global_classes : [])
    .concat(Array.isArray(templateData.globalClasses) ? templateData.globalClasses : []);
  out.push(...collectImplicitBricksLayoutControls(templateStyleItems, `Bricks template export ${relPath}`));
  out.push(...collectBricksCompilerUnsafeControls(templateStyleItems, `Bricks template export ${relPath}`));
  out.push(...collectBricksColorTokenMisuse(
    templateStyleItems,
    `Bricks template export ${relPath}`
  ));
  out.push(...collectBricksVariableFallbackMisuse(
    templateStyleItems,
    `Bricks template export ${relPath}`
  ));
  const templateNativeControls = countNativeStyleControls(
    templateStyleItems
  );
  if (templateElements.length >= largeClipboardElementCount && templateNativeControls < customCssNativeStyleMinControls) {
    out.push(`Large Bricks template export ${relPath} has ${templateElements.length} elements but only ${templateNativeControls} native style/layout controls. Full-page template uploads must preserve editable Bricks controls instead of relying on source/page CSS that may not follow insertion.`);
  }
  const editabilityStats = templateEditabilityStats(templateElements);
  if (
    editabilityStats.elementCount >= largeClipboardElementCount &&
    editabilityStats.elementNativeControlsPerElement < templateUploadMinElementNativeControlsPerElement
  ) {
    out.push(`Large Bricks template export ${relPath} has ${editabilityStats.elementNativeControls} element-level native style/layout controls across ${editabilityStats.elementCount} elements (${editabilityStats.elementNativeControlsPerElement.toFixed(2)} per element). This is too class-dependent for a visual-editor handoff: ordinary grid/flex, spacing, sizing, typography, color, borders, radius, shadows, and responsive overrides must be editable on elements where the source design depends on them, not only in importable global_classes.`);
  }
  if (
    editabilityStats.elementCount >= largeClipboardElementCount &&
    editabilityStats.classOnlyElementRatio > templateUploadMaxClassOnlyElementRatio
  ) {
    out.push(`Large Bricks template export ${relPath} has ${editabilityStats.classOnlyElements} of ${editabilityStats.elementCount} elements (${Math.round(editabilityStats.classOnlyElementRatio * 100)}%) carrying global-class dependencies without element-level native style/layout controls. Bricks My Templates can skip or remap global class definitions when class names already exist, so /convert /bricks must keep the rendered design resilient with sufficient element-native controls instead of relying mainly on class hydration.`);
  }
  const styledGlobalClasses = collectStyledTemplateGlobalClasses(templateData.global_classes);
  if (editabilityStats.elementCount >= largeClipboardElementCount && styledGlobalClasses.length) {
    const preview = styledGlobalClasses.slice(0, 12).map((item) => item.name || item.id || '(unnamed)').join(', ');
    out.push(`Large Bricks template export ${relPath} imports ${styledGlobalClasses.length} styled global_classes (${preview}${styledGlobalClasses.length > 12 ? ', ...' : ''}) while element-native controls already own visual fidelity. This creates multi-owner "ghost styling" in Bricks: removing a color/radius/spacing from the visible element or class can leave the same style active from another layer. /convert /bricks template uploads must use element-native controls as the render/edit owner and keep imported global_classes semantic/name-only; reusable project classes belong in the Framework profile push, not as duplicate styled classes in the template upload.`);
  }
  return out;
}

function isLikelyBricksTemplateExport(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
  if (value.schema === 'kiwe.bricks-conversion.v1' || Array.isArray(value.elements)) return false;
  return (
    Array.isArray(value.content) ||
    Array.isArray(value.header) ||
    Array.isArray(value.footer) ||
    Object.prototype.hasOwnProperty.call(value, 'templateType') ||
    Object.prototype.hasOwnProperty.call(value, 'pageSettings') ||
    Object.prototype.hasOwnProperty.call(value, 'bundles')
  );
}

function nativeBricksTemplatePackageRoot(file) {
  const dir = path.dirname(file);
  return path.basename(dir) === 'bricks-template' ? path.dirname(dir) : dir;
}

function nativeBricksTemplateFiles() {
  const out = [];
  for (const file of files.filter((item) => /\.json$/i.test(item) && path.basename(item) !== 'kiwe-bricks-conversion.json')) {
    try {
      const json = JSON.parse(read(file));
      if (isLikelyBricksTemplateExport(json)) out.push(file);
    } catch (_) {
      // Other JSON validation reports elsewhere; this scanner only finds native template candidates.
    }
  }
  return out;
}

function auditLeanBricksDocumentation(bricksArtifacts) {
  if (!bricksArtifacts.length || documented) return;
  const docPattern = /(?:^|\/)(?:BRICKS-CONVERSION-NOTES|FRAMEWORK-NOTES|BRICKS-CONVERSION-AUDIT|LOCAL-VALIDATION|CURRENT-MAIN-BRICKS-AUDIT|validation-report)[^/]*\.(?:md|json|txt)$/i;
  for (const file of files) {
    if (!docPattern.test(rel(file))) continue;
    add(
      'fail',
      'Documentation/report files were emitted without `/document`. Lean `/convert /bricks` output should hand back only the native Bricks upload JSON unless the human explicitly asks for docs.',
      rel(file)
    );
  }
}

function bareSeamSelectorDeclarations(cssText) {
  const found = [];
  const seen = new Set();
  const body = String(cssText || '');
  const pattern = /(?:^|[{}]|\\n|\\r|\n|\r)\s*([^{}@]{0,760})\{/gi;
  for (const match of body.matchAll(pattern)) {
    const selectorText = String(match[1] || '').replace(/\/\*[\s\S]*?\*\//g, '').trim();
    for (const part of selectorText.split(',')) {
      const selector = part.replace(/\\[nr]/g, ' ').replace(/\s+/g, ' ').trim();
      if (!selector || seen.has(selector)) continue;
      if (!/(?:^|[\s>+~(:])\.seam-[a-z0-9_-]+|\[data-(?:flow|role|tone|state)\b/i.test(selector)) continue;
      seen.add(selector);
      found.push(selector.slice(0, 180));
    }
  }
  return found;
}

function validateBricksConversionJson(file) {
  const out = [];
  let json;
  try {
    json = JSON.parse(read(file));
  } catch (error) {
    out.push(`kiwe-bricks-conversion.json is invalid JSON: ${error.message}`);
    return out;
  }
  if (!json || typeof json !== 'object' || Array.isArray(json)) {
    out.push('kiwe-bricks-conversion.json must be an object.');
    return out;
  }
  for (const key of ['schema', 'source', 'target', 'conversion', 'elements', 'fidelity', 'report']) {
    if (!(key in json)) out.push(`kiwe-bricks-conversion.json missing required root key: ${key}`);
  }
  if (json.schema !== 'kiwe.bricks-conversion.v1') {
    out.push('kiwe-bricks-conversion.json schema must be kiwe.bricks-conversion.v1.');
  }
  if (!Array.isArray(json.elements) || json.elements.length === 0) {
    out.push('kiwe-bricks-conversion.json elements must be a non-empty array.');
  }
  if (!json.target || typeof json.target !== 'object' || json.target.builder !== 'bricks') {
    out.push('kiwe-bricks-conversion.json target.builder must be "bricks".');
  }
  if (!json.target || typeof json.target !== 'object' || !/bricks/i.test(String(json.target.format || ''))) {
    out.push('kiwe-bricks-conversion.json target.format must identify a Bricks element JSON artifact.');
  }
  const importMethod = json.target && typeof json.target === 'object' ? String(json.target.importMethod || '').trim() : '';
  if (!importMethod) {
    out.push(`kiwe-bricks-conversion.json target.importMethod is required. Use one of: ${Array.from(bricksImportMethods).join(', ')}. Kiwe conversion JSON is not itself a Bricks My Templates upload file.`);
  } else if (!bricksImportMethods.has(importMethod)) {
    out.push(`kiwe-bricks-conversion.json target.importMethod must be one of: ${Array.from(bricksImportMethods).join(', ')}.`);
  }
  if (json.target && typeof json.target === 'object' && /template|upload|library|my-templates/i.test(`${json.target.mode || ''} ${json.target.format || ''}`) && importMethod !== 'bricks-admin-template-upload') {
    out.push('kiwe-bricks-conversion.json Bricks template-library/My Templates delivery must use target.importMethod "bricks-admin-template-upload" and provide a native Bricks template export file.');
  }
  if (importMethod === 'bricks-admin-template-upload') {
    out.push(...validateBricksTemplateExport(conversionPackageRoot(file), json.target.templateExportPath));
  }
  if (importMethod === 'bricks-clipboard-json' && Array.isArray(json.elements) && json.elements.length >= largeClipboardElementCount) {
    out.push(`kiwe-bricks-conversion.json has ${json.elements.length} elements but targets clipboard paste. Large conversions must use bricks-admin-template-upload with a separate native Bricks template export, or kiwe-staging-executor after validation.`);
  }
  if (!json.target || typeof json.target !== 'object' || !/(human|review|trusted|staging|adapter)/i.test(String(json.target.applyAuthority || ''))) {
    out.push('kiwe-bricks-conversion.json target.applyAuthority must point to human review or a trusted Kiwe staging adapter.');
  }
  if (!json.fidelity || typeof json.fidelity !== 'object' || !Array.isArray(json.fidelity.sourceSelectors) || json.fidelity.sourceSelectors.length === 0) {
    out.push('kiwe-bricks-conversion.json fidelity.sourceSelectors must map important source selectors to Bricks element IDs.');
  }
  if (json.fidelity && typeof json.fidelity === 'object' && 'nativeStyleIntent' in json.fidelity && !Array.isArray(json.fidelity.nativeStyleIntent)) {
    out.push('kiwe-bricks-conversion.json fidelity.nativeStyleIntent must be an array when present.');
  }
  if (!json.report || typeof json.report !== 'object' || !Array.isArray(json.report.manualReview)) {
    out.push('kiwe-bricks-conversion.json report.manualReview must be an array, even when empty.');
  }
  const packageRoot = conversionPackageRoot(file);
  const sourceText = read(path.join(packageRoot, 'website', 'bricks-paste.html'));
  const jsonText = JSON.stringify(json);
  const responsiveOverrides = collectResponsiveLayoutOverrides(json.elements || []);
  const hasComplexLayout = complexLayoutPattern.test(`${sourceText}\n${jsonText}`);
  const fidelity = json.fidelity && typeof json.fidelity === 'object' ? json.fidelity : {};
  const responsiveIntent = Array.isArray(fidelity.responsiveIntent) ? fidelity.responsiveIntent : [];
  if ((hasComplexLayout || responsiveOverrides.length) && responsiveIntent.length === 0) {
    out.push('kiwe-bricks-conversion.json fidelity.responsiveIntent must be a non-empty array when the source/conversion uses complex bento/grid/campaign layout or Bricks breakpoint layout overrides.');
  }
  if (responsiveIntent.length) {
    const byId = new Map((json.elements || []).filter((element) => element && typeof element === 'object' && element.id).map((element) => [String(element.id), element]));
    for (const [index, item] of responsiveIntent.entries()) {
      const itemText = JSON.stringify(item || {});
      if (!/(desktop|tablet|mobile|narrow|breakpoint|viewport|range)/i.test(itemText)) out.push(`kiwe-bricks-conversion.json fidelity.responsiveIntent[${index}] must identify the breakpoint or viewport range.`);
      if (!/(selector|source|element|bricks|mappedElementIds|id)/i.test(itemText)) out.push(`kiwe-bricks-conversion.json fidelity.responsiveIntent[${index}] must connect the source selector to Bricks element IDs/settings.`);
      if (!/(grid|flex|direction|columns|rows|span|wrap|align|justify|flow)/i.test(itemText)) out.push(`kiwe-bricks-conversion.json fidelity.responsiveIntent[${index}] must state the preserved layout behavior.`);
      if (/_flexDirection\b/i.test(itemText)) {
        const ids = Array.isArray(item && item.mappedElementIds) ? item.mappedElementIds : [];
        for (const id of ids) {
          const mapped = byId.get(String(id));
          if (mapped && isBricksLayoutElement(mapped)) {
            out.push(`kiwe-bricks-conversion.json fidelity.responsiveIntent[${index}] claims _flexDirection for layout element "${id}". Use _direction / _direction:<breakpoint> for Bricks layout elements.`);
          }
        }
      }
    }
  }
  if (hasComplexLayout && !/(#home-campaigns|bento|campaign|grid-template|grid-column|grid-row)/i.test(JSON.stringify(fidelity.sourceSelectors || []))) {
    out.push('kiwe-bricks-conversion.json fidelity.sourceSelectors must explicitly include complex bento/grid/campaign regions such as #home-campaigns/.nc-bento and their mapped Bricks element IDs.');
  }
  if (hasComplexLayout && responsiveIntent.length && !/(#home-campaigns|bento|campaign|grid|columns|rows|span)/i.test(JSON.stringify(responsiveIntent))) {
    out.push('kiwe-bricks-conversion.json fidelity.responsiveIntent must explicitly describe bento/grid/campaign responsive behavior so Bricks desktop/tablet/mobile layouts cannot silently drift.');
  }
  for (const override of responsiveOverrides) {
    if (/\bseam-spread\b/.test(`${override.classes} ${override.cssId}`) && /_(?:direction|flexDirection):/i.test(override.key) && String(override.value).toLowerCase() === 'column' && !new RegExp(`${override.id || 'missing-id'}|${override.cssId || 'missing-css-id'}|seam-spread|section-head`, 'i').test(JSON.stringify(responsiveIntent))) {
      out.push(`kiwe-bricks-conversion.json changes seam-spread element "${override.id || 'unknown'}" to column at ${override.key.split(':')[1]} without a responsiveIntent entry tied to source evidence.`);
    }
  }
  for (const message of collectBricksElementMisuse(json.elements || [])) {
    out.push(`kiwe-bricks-conversion.json ${message}`);
  }
  out.push(...collectBricksColorTokenMisuse([].concat(json.elements || []).concat(json.globalClasses || []), 'kiwe-bricks-conversion.json'));
  const customCssBuckets = collectCustomCssBuckets(json);
  const customCssText = customCssBuckets.join('\n');
  const customCssBytes = customCssBuckets.reduce((sum, text) => sum + String(text || '').length, 0);
  const mappableCssDeclarations = countMappableCssDeclarations(customCssText);
  const importMethodForCss = json.target && typeof json.target === 'object' ? String(json.target.importMethod || '').trim() : '';
  if (importMethodForCss === 'bricks-admin-template-upload') {
    const pageCssBuckets = collectCustomCssBuckets({ pageSettings: json.pageSettings });
    const pageCssText = pageCssBuckets.join('\n');
    const pageCssBytes = pageCssBuckets.reduce((sum, text) => sum + String(text || '').length, 0);
    const pageMappableCss = countMappableCssDeclarations(pageCssText);
    if (
      pageCssBytes >= templateUploadCustomCssBytes ||
      pageMappableCss >= templateUploadMappableCssDeclarationMin ||
      /@media\b|#home-campaigns\b|\.nc-(?:bento|campaign|section-head)|grid-template|flex-direction/i.test(pageCssText)
    ) {
      out.push(`kiwe-bricks-conversion.json target.importMethod is bricks-admin-template-upload, but pageSettings.customCss carries ${pageCssBytes} CSS bytes and ${pageMappableCss} mappable declarations. Template-upload handoffs must not depend on pageSettings.customCss for ordinary layout/design because inserted templates can lose page CSS or collide with stale page CSS.`);
    }
  }
  const isCustomCssHeavy = customCssBytes >= customCssHeavyBytes || /@media\b[\s\S]{0,2400}(?:\.nc-|#home-campaigns|\.seam-|grid-template|flex-direction)/i.test(customCssText);
  if (isCustomCssHeavy || mappableCssDeclarations >= mappableCssDeclarationMin) {
    const nativeStyleIntent = json.fidelity && Array.isArray(json.fidelity.nativeStyleIntent) ? json.fidelity.nativeStyleIntent : [];
    const nativeControlCount = countNativeStyleControls([].concat(json.elements || []).concat(json.globalClasses || []));
    if (!nativeStyleIntent.length) {
      out.push(`kiwe-bricks-conversion.json carries ${customCssBytes} custom CSS bytes and ${mappableCssDeclarations} mappable CSS declarations but has no fidelity.nativeStyleIntent proving editable Bricks-native style mapping.`);
    }
    if (isCustomCssHeavy && nativeControlCount < customCssNativeStyleMinControls) {
      out.push(`kiwe-bricks-conversion.json uses only ${nativeControlCount} native style/layout controls while carrying ${customCssBytes} custom CSS bytes. Map ordinary typography, spacing, backgrounds, borders, radii, shadows, grid/flex, sizing, and responsive controls to Bricks settings/global classes first.`);
    }
    const minimumNativeControlsForCss = Math.ceil(mappableCssDeclarations * mappableCssNativeStyleRatio);
    if (mappableCssDeclarations >= mappableCssDeclarationMin && nativeControlCount < minimumNativeControlsForCss) {
      out.push(`kiwe-bricks-conversion.json leaves ${mappableCssDeclarations} mappable CSS declarations in custom CSS but exposes only ${nativeControlCount} native Bricks style/layout controls. Ordinary design decisions must be editable through Bricks controls/global classes/global variables; keep custom CSS for explicit exceptions only.`);
    }
    for (const [index, item] of nativeStyleIntent.entries()) {
      const itemText = JSON.stringify(item || {});
      if (!/(selector|sourceSelector)/i.test(itemText)) out.push(`kiwe-bricks-conversion.json fidelity.nativeStyleIntent[${index}] must identify the source selector being styled.`);
      if (!/(mappedElementIds|bricksElementIds|element)/i.test(itemText)) out.push(`kiwe-bricks-conversion.json fidelity.nativeStyleIntent[${index}] must identify mapped Bricks element IDs.`);
      if (!/(nativeControls|bricksControls|globalClass|globalVariable)/i.test(itemText)) out.push(`kiwe-bricks-conversion.json fidelity.nativeStyleIntent[${index}] must list editable Bricks native controls, global classes, or global variables used.`);
      if (!/(customCssException|unsupported|manualReview|native|editable)/i.test(itemText)) out.push(`kiwe-bricks-conversion.json fidelity.nativeStyleIntent[${index}] must state what remains custom CSS versus what is editable natively.`);
    }
  }
  return out;
}

function nestedSeamRailMisuse(htmlText) {
  const found = [];
  const body = String(htmlText || '');
  if (/<[a-z][a-z0-9-]*\b[^>]*class\s*=\s*["'][^"']*\bseam-nav\b[^"']*\bseam-horizontal-rail\b[^"']*["'][^>]*>/i.test(body)) {
    found.push('a seam-nav wrapper also carries seam-horizontal-rail');
  }
  if (/<[a-z][a-z0-9-]*\b[^>]*class\s*=\s*["'][^"']*\bseam-nav\b[^"']*["'][^>]*data-flow\s*=\s*["'](?:reel|horizontal-rail)["'][^>]*>/i.test(body)) {
    found.push('a seam-nav wrapper also carries data-flow="horizontal-rail"');
  }
  const outerPattern = /<([a-z][a-z0-9-]*)\b[^>]*(?:class\s*=\s*["'][^"']*\bseam-horizontal-rail\b[^"']*["']|data-flow\s*=\s*["'](?:reel|horizontal-rail)["'])[^>]*>([\s\S]{0,5200}?)(?:<\/\1>|$)/gi;
  for (const match of body.matchAll(outerPattern)) {
    const tag = String(match[1] || '').toLowerCase();
    const inner = String(match[2] || '');
    if (/(?:class\s*=\s*["'][^"']*\bseam-horizontal-rail\b[^"']*["']|data-flow\s*=\s*["'](?:reel|horizontal-rail)["'])/i.test(inner)) {
      found.push(`<${tag}> has Seam rail flow on both wrapper and descendant track`);
    } else if (/<(?:div|section|ul)\b[^>]*class\s*=\s*["'][^"']*\bseam-container\b/i.test(inner)) {
      found.push(`<${tag}> applies Seam rail flow to a wrapper containing a seam-container; put the rail flow on the inner track instead`);
    }
  }
  return Array.from(new Set(found)).slice(0, 12);
}

function isPlainObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value);
}

function hasMeaningfulObjectValue(container, key) {
  return isPlainObject(container)
    && Object.prototype.hasOwnProperty.call(container, key)
    && ['string', 'number'].includes(typeof container[key])
    && String(container[key]).trim() !== '';
}

function validateThemePackageTokenSettings(tokens, file) {
  if (!isPlainObject(tokens)) {
    add('fail', 'theme-package.json settings.tokens must be an object containing enabled, profile_label, overrides, and optional bricks_theme_style.', rel(file));
    return;
  }

  for (const key of Object.keys(tokens)) {
    if (/^--|var\(/i.test(key)) {
      add('fail', `theme-package.json settings.tokens uses CSS variable key "${key}". Use settings.tokens.overrides with official token names such as "color-brand", without --kiwe- or var().`, rel(file));
    } else if (!themeTokenTopLevelKeys.has(key)) {
      add('fail', `theme-package.json settings.tokens has unsupported top-level key "${key}". Allowed keys are enabled, profile_label, overrides, and bricks_theme_style. Token values belong in settings.tokens.overrides.`, rel(file));
    }
  }

  if (!Object.prototype.hasOwnProperty.call(tokens, 'overrides')) {
    add('fail', 'theme-package.json settings.tokens must contain an overrides object. A token lane without overrides will not synchronize DSA, Seam page CSS, and Bricks global theme style.', rel(file));
    return;
  }

  if (!isPlainObject(tokens.overrides)) {
    add('fail', 'theme-package.json settings.tokens.overrides must be an object keyed by official Kiwe universal token names.', rel(file));
    return;
  }

  const official = getOfficialTokenNames();
  for (const tokenName of Object.keys(tokens.overrides)) {
    if (/^--|var\(/i.test(tokenName)) {
      add('fail', `theme-package.json settings.tokens.overrides "${tokenName}" must use the official token name without --kiwe- or var().`, rel(file));
    } else if (!/^[a-z0-9][a-z0-9_-]{1,80}$/i.test(tokenName)) {
      add('fail', `theme-package.json settings.tokens.overrides has invalid token name "${tokenName}".`, rel(file));
    } else if (official.size && !official.has(tokenName)) {
      add('warn', `theme-package.json settings.tokens.overrides "${tokenName}" is not in the known Kiwe universal token list. Use official tokens or request core promotion.`, rel(file));
    }
  }

  if (Object.prototype.hasOwnProperty.call(tokens, 'bricks_theme_style') && !isPlainObject(tokens.bricks_theme_style)) {
    add('fail', 'theme-package.json settings.tokens.bricks_theme_style must be an object when present.', rel(file));
  }
}

function validateThemePackageScreenSettings(screens, file) {
  if (!isPlainObject(screens)) {
    add('fail', 'theme-package.json settings.screens must be an object keyed by registered DSA screen ids.', rel(file));
    return;
  }

  for (const [screen, config] of Object.entries(screens)) {
    const fields = screenCopyFields[screen];
    if (!fields) {
      add('fail', `theme-package.json settings.screens contains unsupported screen "${screen}". Use registered DSA screens only: ${Object.keys(screenCopyFields).join(', ')}.`, rel(file));
      continue;
    }
    if (!isPlainObject(config)) {
      add('fail', `theme-package.json settings.screens.${screen} must be an object of presentation-only copy fields.`, rel(file));
      continue;
    }
    for (const key of Object.keys(config)) {
      if (!fields.has(key)) {
        add('fail', `theme-package.json settings.screens.${screen}.${key} is not a live Kiwe screen-copy field. Use only supported ${screen} fields: ${Array.from(fields).join(', ')}.`, rel(file));
      }
    }
  }
}

function validateThemePackageSettings(json, file) {
  const settings = isPlainObject(json.settings) ? json.settings : null;
  if (!settings) return;
  if (Object.prototype.hasOwnProperty.call(settings, 'tokens')) {
    validateThemePackageTokenSettings(settings.tokens, file);
  }
  if (Object.prototype.hasOwnProperty.call(settings, 'screens')) {
    validateThemePackageScreenSettings(settings.screens, file);
  }
}

function validateImportCssKiweTokenReferences(cssText, file) {
  const official = getOfficialTokenNames();
  const seen = new Set();
  for (const match of cssText.matchAll(/--kiwe-([a-z0-9-]+)/gi)) {
    const raw = String(match[0]);
    const tokenName = String(match[1]);
    if (seen.has(raw)) continue;
    seen.add(raw);
    if (tokenName.startsWith('theme-')) continue;
    if (allowedThemeCssTokenAliases.has(tokenName)) continue;
    if (official.size && official.has(tokenName)) continue;
    add('fail', `Importable theme CSS references unsupported Kiwe token variable "${raw}". Use official universal tokens such as --kiwe-color-surface, --kiwe-color-surface-raised, --kiwe-radius-xl, --kiwe-radius-full, --kiwe-shadow-md, and --kiwe-space-md, or documented --kiwe-theme-* aliases.`, rel(file));
  }
}

function validateImportCssNoRuntimeBridgeTokens(cssText, file) {
  const seen = new Set();
  for (const match of cssText.matchAll(/--dsa-runtime-token-\d{4}/gi)) {
    seen.add(match[0]);
  }
  if (seen.size) {
    add('fail', `Importable theme CSS references Kiwe core runtime bridge token(s) ${Array.from(seen).sort().join(', ')}. Generated --dsa-runtime-token-* variables are private migration glue for Kiwe runtime CSS, not public Seam/AppShell theme vocabulary. Use official --kiwe-* variables, documented --kiwe-theme-* aliases, or request a generic universal token promotion.`, rel(file));
  }
}

function cssDeclarationText(cssText) {
  return Array.from(stripCssComments(cssText).matchAll(/([^{}]+)\{([^{}]*)\}/g)).map((match) => match[2]).join('\n');
}

function validateImportCssNoAnonymousLiterals(cssText, file) {
  const stripped = stripCssComments(cssText);
  const declarations = cssDeclarationText(cssText);
  const lengths = new Set();
  const colors = new Set();
  const effects = new Set();
  const lengthPattern = /(^|[^-_a-zA-Z0-9.])(-?(?:\d*\.)?\d+(?:px|rem|em|ch|ex|cap|ic|lh|rlh|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc))\b/gi;
  for (const match of stripped.matchAll(lengthPattern)) {
    lengths.add(match[2].toLowerCase());
  }
  for (const match of declarations.matchAll(/(^|[^#_a-zA-Z0-9-])(#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b)/gi)) {
    colors.add(match[2].toLowerCase());
  }
  for (const match of declarations.matchAll(/\b(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color-mix|light-dark|color)\s*\(/gi)) {
    colors.add(match[0].replace(/\s+/g, '').toLowerCase());
  }
  for (const match of declarations.matchAll(/(?:^|;)\s*((?:box-shadow|text-shadow)\s*:\s*(?![^;]*\b(?:none|inherit|initial|unset|revert)\b)(?![^;]*var\()[^;]+)/gi)) {
    effects.add(match[1].trim().replace(/\s+/g, ' ').slice(0, 120));
  }
  const details = [];
  if (lengths.size) details.push(`lengths ${Array.from(lengths).sort().join(', ')}`);
  if (colors.size) details.push(`colors/functions ${Array.from(colors).sort().join(', ')}`);
  if (effects.size) details.push(`effects ${Array.from(effects).sort().join(' | ')}`);
  if (details.length) {
    add('fail', `Importable theme CSS contains anonymous CSS literal(s): ${details.join('; ')}. Marketplace AppShell themes must consume official --kiwe-* universal tokens, documented --kiwe-theme-* aliases, or Kiwe/DSA geometry variables. Concrete base values belong in theme-package.json settings.tokens or Kiwe core token registries, not installable theme.css.`, rel(file));
  }
}

function stripCssComments(cssText) {
  return String(cssText || '').replace(/\/\*[\s\S]*?\*\//g, '');
}

function selectorTargetsProtectedAppShellRoot(selector) {
  return String(selector || '')
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean)
    .some((part) => {
      const match = part.match(/(?:#dsa-surface|\[data-dsa-surface\]|\.dsa-installed-theme-[a-z0-9_-]+)(.*)$/i);
      if (!match) return false;
      const after = String(match[1] || '');
      return !/[>+~\s]/.test(after);
    });
}

function validateImportCssNoProtectedRootPaint(cssText, file) {
  const paintPattern = /(?:^|;)\s*(?:background(?:-color|-image)?|border(?:-[a-z-]+)?|box-shadow|filter|backdrop-filter|opacity)\s*:/i;
  for (const match of stripCssComments(cssText).matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const selector = match[1].trim();
    const declarations = match[2];
    if (selectorTargetsProtectedAppShellRoot(selector) && paintPattern.test(declarations)) {
      add('fail', `Importable theme CSS paints the protected AppShell surface root "${selector.slice(0, 140)}". The DSA surface root is transparent Kiwe runtime scaffolding; theme CSS may set tokens/inherited typography on the root, but backgrounds, borders, shadows, opacity, and filters belong on dock/sheet/screen/panel parts.`, rel(file));
    }
  }
}

if (exists('package.json') || exists('vite.config.ts') || exists('tailwind.config.js') || exists('components.json')) {
  add('fail', 'Output looks like a React/Vite/Tailwind/shadcn app. Kiwe handoffs must be plain HTML/CSS with optional preview-only JS unless an app prototype was explicitly requested.');
}

const discoveredBricksConversionFiles = files.filter((item) => path.basename(item) === 'kiwe-bricks-conversion.json');
const discoveredBricksTemplateFiles = nativeBricksTemplateFiles();

if (!exists('website/bricks-paste.html') && !exists('bricks-paste.html') && !discoveredBricksTemplateFiles.length) {
  add('fail', 'Missing bricks-paste.html. It is the single website/page artifact: browser preview and Bricks HTML-to-Bricks copy/paste file.');
}

if (exists('website/preview/index.html')) {
  add('warn', 'Duplicate website/preview/index.html detected. Website mode should normally use website/bricks-paste.html as the single preview + Bricks artifact unless a split preview was explicitly requested.');
}

const bricksPastePath = exists('website/bricks-paste.html')
  ? path.join(root, 'website/bricks-paste.html')
  : (exists('bricks-paste.html') ? path.join(root, 'bricks-paste.html') : '');
const websiteText = bricksPastePath ? read(bricksPastePath) : '';
const websiteIds = new Set();
if (websiteText) {
  for (const match of websiteText.matchAll(/\bid\s*=\s*["']([^"']+)["']/gi)) {
    if (match[1]) websiteIds.add(String(match[1]).trim());
  }
}
if (bricksPastePath) {
  const bricksPaste = websiteText;
  if (/\bdata-dsa-surface\b|class\s*=\s*["'][^"']*\bdsa-surface\b|class\s*=\s*["'][^"']*\bdsa-dock\b|class\s*=\s*["'][^"']*\bdsa-sheet\b|\bshowKiweSheet\s*\(/i.test(bricksPaste)) {
    add('fail', 'website/bricks-paste.html contains Kiwe AppShell/dock/sheet markup or preview controller code. The Bricks page artifact must be page-only; only combined-preview/index.html should show the AppShell over the page.', rel(bricksPastePath));
  }
}

if (exists('website') && exists('appshell-theme') && !exists('combined-preview/index.html')) {
  add('fail', 'Combined handoff is missing combined-preview/index.html, the primary review artifact showing the website/page behind the Kiwe DSA AppShell.');
}

const combinedPreviewPath = exists('combined-preview/index.html') ? path.join(root, 'combined-preview/index.html') : '';
const combinedPreviewText = combinedPreviewPath ? read(combinedPreviewPath) : '';
const combinedPreviewSupportText = textFiles
  .filter((file) => rel(file).startsWith('combined-preview/'))
  .map((file) => read(file))
  .join('\n');
const appShellPreviewPath = exists('appshell-theme/preview/index.html') ? path.join(root, 'appshell-theme/preview/index.html') : '';
const appShellPreviewText = appShellPreviewPath ? read(appShellPreviewPath) : '';
const appShellPreviewSupportText = textFiles
  .filter((file) => rel(file).startsWith('appshell-theme/preview/'))
  .map((file) => read(file))
  .join('\n');

if (combinedPreviewPath) {
  if (appShellPreviewPath) {
    add('warn', 'Combined handoff includes a separate appshell-theme/preview/index.html. Combined mode should use combined-preview/index.html as the single primary visual proof with page + AppShell + variation controls; AppShell-only preview should be omitted unless explicitly labelled as a technical fixture.', rel(appShellPreviewPath));
  }

  const combinedProofText = `${combinedPreviewText}\n${combinedPreviewSupportText}`;
  const combinedShapes = new Set();
  for (const match of combinedProofText.matchAll(/dsa-dock-shape-(pill|box|square)|data-[\w-]*(?:preview-)?set-(?:shape|dock-shape)\s*=\s*["'](pill|box|square)["']/gi)) {
    combinedShapes.add(String(match[1] || match[2] || '').toLowerCase());
  }
  if (combinedShapes.size < 3) {
    add('warn', 'combined-preview/index.html does not prove dock shape switching. Combined review must visibly cover pill, rounded box, and square/no-radius dock shapes.', rel(combinedPreviewPath));
  }
  if (!/data-[\w-]*(?:preview-)?set-(?:presentation|dock)|full compact|split compact|navigation bar|navbar/i.test(combinedProofText)) {
    add('warn', 'combined-preview/index.html does not prove dock presentation switching. Combined review must cover full compact dock, split compact dock, and Navigation bar as separate core modes.', rel(combinedPreviewPath));
  }
  if (!/data-[\w-]*(?:preview-)?set-(?:surface|surface-mode|mode)|sheet[\s\S]{0,240}classic|classic[\s\S]{0,240}sheet/i.test(combinedProofText)) {
    add('warn', 'combined-preview/index.html does not prove Sheet and Classic surface modes in the page + AppShell context.', rel(combinedPreviewPath));
  }
  if (!/(desktop|tablet|mobile|1280|1200|1024|768|640)/i.test(combinedProofText)) {
    add('warn', 'combined-preview/index.html does not prove Geometry Engine device profiles. Include desktop, tablet, mobile, plus narrow stress widths rather than mobile-only 320/360/390 controls.', rel(combinedPreviewPath));
  }
  if (websiteText && /\bdata-dsa-open-module\b/i.test(websiteText) && /<iframe\b/i.test(combinedPreviewText) && !/contentDocument[\s\S]{0,1200}data-dsa-open-module|data-dsa-open-module[\s\S]{0,1200}contentDocument/i.test(combinedPreviewSupportText)) {
    add('warn', 'website/bricks-paste.html contains data-dsa-open-module hooks and combined-preview uses an iframe, but no iframe bridge for those header/page launchers was detected. Header profile/cart/search/menu buttons must open the previewed DSA screen.', rel(combinedPreviewPath));
  }
  if (websiteText && /\bdata-dsa-open-module\b/i.test(websiteText) && !/(manual smoke|smoke test|clicked|verified)[\s\S]{0,240}(?:profile|account)[\s\S]{0,240}(?:cart|bag|search|menu)|(?:profile|account)[\s\S]{0,240}(?:cart|bag|search|menu)[\s\S]{0,240}(?:manual smoke|smoke test|clicked|verified)/i.test(allText)) {
    add('warn', 'No manual smoke-test note found for page/header launchers. Combined handoffs with data-dsa-open-module should report that Profile/Account, Cart/Bag, Search, and Menu launchers were clicked or otherwise verified in combined-preview/index.html.', rel(combinedPreviewPath));
  }
  const privatePreviewPanelClasses = [
    'kiwe-preview-panel',
    'kiwe-preview-panel-heading',
    'kiwe-preview-alpha',
    'kiwe-preview-fbt',
    'kiwe-preview-score',
    'kiwe-preview-empty',
    'kiwe-preview-muted'
  ].filter((className) => new RegExp(`\\b${className}\\b`, 'i').test(combinedPreviewText));
  if (privatePreviewPanelClasses.length && /\bdata-dsa-screen\b/i.test(combinedPreviewText)) {
    add('fail', `Primary combined preview styles AppShell screens with preview-only panel classes (${privatePreviewPanelClasses.join(', ')}). The human approval preview must use live-like Kiwe DSA screen/sheet markup and put visual identity in importable theme.css against live selectors; preview CSS may position the harness only.`, rel(combinedPreviewPath));
  }
}

const allPreviewText = `${combinedPreviewText}\n${combinedPreviewSupportText}\n${appShellPreviewText}\n${appShellPreviewSupportText}`;
if (/(?:320|360|390|430)/.test(allPreviewText) && !/(desktop|tablet|mobile|1280|1200|1024|768|640)/i.test(allPreviewText)) {
  add('warn', 'Preview viewport controls are mobile-only. Kiwe Geometry Engine proof must include desktop, tablet, mobile profiles and may add 320/360/390 narrow stress cases.');
}
if (/navigation bar|navbar/i.test(allPreviewText) && !/(separate|distinct|not (?:a )?horizontal(?: compact)? dock|not (?:as|just|merely) horizontal|not relabel|presentation(?: mode|, not)|separate presentation)/i.test(allText)) {
  add('warn', 'Preview mentions Navigation bar but does not clearly distinguish it from horizontal dock orientation. Navigation bar is a separate presentation mode; horizontal/vertical are dock orientations.');
}
if (/classic[\s\S]{0,320}(?:width\s*:\s*min\(\s*(?:390|430)px|left\s*:\s*auto|right\s*:\s*0)/i.test(allPreviewText)) {
  add('warn', 'Classic surface preview appears to use a narrow side-drawer layout. Classic DSA surface proof should cover the full app viewport unless the live Geometry Engine setting explicitly says otherwise.');
}

const knownDsaModules = new Set(['menu', 'search', 'profile', 'links', 'saved', 'cart', 'theme', 'ai', 'secure', 'notifications', 'ios-install', 'games']);
const themePackageFiles = files.filter((file) => path.basename(file) === 'theme-package.json');
const importThemeCssFiles = files.filter((file) => /(^|\/|\\)appshell-theme(\/|\\)import(\/|\\)[^/\\]+(\/|\\).*\.css$/i.test(rel(file)));
const importThemeCssText = importThemeCssFiles.map((file) => `\n--- ${rel(file)} ---\n${read(file)}`).join('\n');
const frameworkProfilePath = exists('framework/kiwe-framework-profile.json') ? path.join(root, 'framework', 'kiwe-framework-profile.json') : '';
const settingsText = textFiles
  .filter((file) => rel(file).startsWith('kiwe-settings/') || /(^|\/)theme-package\.json$/i.test(rel(file)))
  .map((file) => read(file))
  .join('\n');
const hasStaleSettingsFolder = files.some((file) => rel(file).startsWith('kiwe-settings/'));
const hasThemePackageSettings = /"settings"\s*:\s*\{/.test(settingsText);
const hasCustomDockSettings = /"custom_items"\s*:\s*\[/.test(settingsText);
const hasFocusItemSettings = /"focus_item"\s*:/.test(settingsText);
const hasScreenSettings = /"screens"\s*:\s*\{/.test(settingsText);
const hasScreenProfileSettings = /"screens"\s*:\s*\{[\s\S]*"profile"\s*:\s*\{/.test(settingsText);
const hasScreenCartSettings = /"screens"\s*:\s*\{[\s\S]*"cart"\s*:\s*\{/.test(settingsText);
const hasScreenLinksSettings = /"screens"\s*:\s*\{[\s\S]*"links"\s*:\s*\{/.test(settingsText);
const hasAnyTokenSettings = /"tokens"\s*:\s*\{/.test(settingsText);

if (hasStaleSettingsFolder) {
  add('warn', 'kiwe-settings/ folder detected. Current Kiwe AppShell theme settings should travel inside appshell-theme/import/<theme-id>/theme-package.json so Kiwe imports/exports installed themes, not loose settings profiles.');
}

if (frameworkProfilePath) {
  let frameworkProfile = null;
  try {
    frameworkProfile = JSON.parse(read(frameworkProfilePath));
  } catch (error) {
    add('fail', `framework/kiwe-framework-profile.json is invalid JSON: ${error.message}`, rel(frameworkProfilePath));
  }

  if (frameworkProfile) {
    if (frameworkProfile.schema !== 'kiwe.framework-profile.v1') {
      add('fail', 'Framework profile schema must be kiwe.framework-profile.v1.', rel(frameworkProfilePath));
    }
    const settings = frameworkProfile.settings && typeof frameworkProfile.settings === 'object' && !Array.isArray(frameworkProfile.settings) ? frameworkProfile.settings : null;
    const tokens = settings && settings.tokens && typeof settings.tokens === 'object' && !Array.isArray(settings.tokens) ? settings.tokens : null;
    if (!tokens) {
      add('fail', 'Framework profile must contain settings.tokens.', rel(frameworkProfilePath));
    }
    if (settings) {
      for (const key of Object.keys(settings)) {
        if (key !== 'tokens') {
          add('fail', `Framework profile settings must contain only tokens; found settings.${key}.`, rel(frameworkProfilePath));
        }
      }
    }
    for (const forbidden of ['dock', 'style', 'screens', 'theme_screens', 'dsa_theme', 'visual_effects', 'commerce', 'bricks', 'css', 'html']) {
      if (Object.prototype.hasOwnProperty.call(frameworkProfile, forbidden)) {
        add('fail', `Framework profile must not contain root ${forbidden}. Put AppShell settings in theme-package.json and page content in website/bricks-paste.html.`, rel(frameworkProfilePath));
      }
    }
    if (tokens && tokens.overrides && typeof tokens.overrides === 'object' && !Array.isArray(tokens.overrides)) {
      const official = getOfficialTokenNames();
      for (const tokenName of Object.keys(tokens.overrides)) {
        if (/^--|var\(/i.test(tokenName)) {
          add('fail', `Framework profile override "${tokenName}" must use the official token name without --kiwe- or var().`, rel(frameworkProfilePath));
        } else if (official.size && !official.has(tokenName)) {
          add('warn', `Framework profile override "${tokenName}" is not in the known Kiwe universal token list. Use official token names or request core promotion.`, rel(frameworkProfilePath));
        }
      }
    }
    if (tokens) {
      const style = tokens.bricks_theme_style && typeof tokens.bricks_theme_style === 'object' && !Array.isArray(tokens.bricks_theme_style) ? tokens.bricks_theme_style : null;
      const safeStyleKeys = new Set([
        'enabled', 'id', 'label',
        'siteBackground', 'site_background', 'background',
        'colorPrimary', 'color_primary', 'primary', 'brand',
        'colorSecondary', 'color_secondary', 'secondary', 'accent',
        'colorSurface', 'color_surface', 'surface',
        'colorSurfaceRaised', 'color_surface_raised', 'surfaceRaised',
        'colorLight', 'color_light', 'light',
        'colorDark', 'color_dark', 'dark',
        'colorMuted', 'color_muted', 'muted',
        'colorBorder', 'color_border', 'borderColor', 'border_color',
        'linkColor', 'link_color', 'colorLink', 'color_link',
        'linkHoverColor', 'link_hover_color',
        'fontDisplay', 'font_display', 'displayFont', 'display_font',
        'fontBody', 'font_body', 'bodyFont', 'body_font',
        'typeH1', 'type_h1', 'typeH2', 'type_h2', 'typeBody', 'type_body',
        'radiusLg', 'radius_lg', 'radiusLarge', 'radius_large',
        'shadowMd', 'shadow_md', 'shadowMedium', 'shadow_medium',
        'spaceMd', 'space_md'
      ]);

      if (!style) {
        add('fail', 'Framework profile must include settings.tokens.bricks_theme_style so Kiwe > Framework can push the matching Bricks Theme Style.', rel(frameworkProfilePath));
      } else {
        for (const key of Object.keys(style)) {
          if (!safeStyleKeys.has(key)) {
            add('fail', `Framework profile bricks_theme_style contains unsupported key "${key}". Use only global style slots, not Bricks element-level styling.`, rel(frameworkProfilePath));
          }
        }
        if (style.enabled !== true) {
          add('fail', 'Framework profile bricks_theme_style.enabled must be true for the one-file Kiwe > Framework setup path.', rel(frameworkProfilePath));
        }
        if (typeof style.id !== 'string' || !/^[a-z0-9][a-z0-9_-]{0,79}$/i.test(style.id)) {
          add('fail', 'Framework profile bricks_theme_style.id must be a safe Bricks theme-style id.', rel(frameworkProfilePath));
        }
        if (typeof style.label !== 'string' || !style.label.trim() || style.label.length > 100) {
          add('fail', 'Framework profile bricks_theme_style.label must be a human-readable label up to 100 characters.', rel(frameworkProfilePath));
        }
        const overrides = isPlainObject(tokens.overrides) ? tokens.overrides : {};
        for (const [tokenName, cssVar, styleKeys] of frameworkCoreTokenCoverage) {
          const covered = hasMeaningfulObjectValue(overrides, tokenName)
            || styleKeys.some((styleKey) => hasMeaningfulObjectValue(style, styleKey));
          if (!covered) {
            add('fail', `Framework profile must cover official token "${tokenName}" (${cssVar}) through settings.tokens.overrides or a mapped bricks_theme_style global slot so Kiwe > Framework push does not leave live Seam/Bricks variables empty.`, rel(frameworkProfilePath));
          }
        }
      }
    }
  }
}

if (exists('appshell-theme') && themePackageFiles.length && !hasAnyTokenSettings && /(?:font-family|--kiwe-color-|--kiwe-theme-|background|color|box-shadow|text-shadow|Your tea-time bag|brand|palette|typography|heading)/i.test(allText)) {
  add('fail', 'AppShell theme defines a visual personality but no theme-package.json settings.tokens profile was found. Modern combined/marketplace Kiwe themes must carry official token overrides so DSA, Seam page CSS, and Bricks global theme style stay synchronized.');
}

if (exists('appshell-theme') && importThemeCssText) {
  const previewOnlyScreenSelectors = [
    '.dsa-screen-head',
    '.dsa-screen-body',
    '.dsa-profile-card',
    '.dsa-score-card',
    '.dsa-links-identity',
    '.dsa-account-rows',
    '.dsa-link-list',
    '.dsa-editorial-title',
    '.dsa-install-steps',
    '.dsa-game-hud',
    '.dsa-game-frame',
    '.dsa-ai-status',
    '.dsa-preference-group',
    '.dsa-contact-field',
    '.dsa-inline-notice',
    '.dsa-result-thumb'
  ];
  const leakedSelectors = previewOnlyScreenSelectors.filter((selector) => importThemeCssText.includes(selector));
  if (leakedSelectors.length) {
    add('fail', `Importable AppShell theme CSS relies on preview-fixture screen selectors (${leakedSelectors.join(', ')}). Move fixture-only selectors to combined-preview CSS and target live Kiwe runtime roots/internals in theme.css.`, importThemeCssFiles.map(rel).join(', '));
  }
  if (!/data-dsa-part/i.test(importThemeCssText)) {
    add('fail', 'Importable AppShell theme CSS never targets documented live AppShell part hooks such as [data-dsa-part]. Protected data-seam-* metadata is for tooling/diagnostics, not importable theme styling. Broad root/panel colors alone make installed themes collapse into the same live UI with only palette changes.', importThemeCssFiles.map(rel).join(', '));
  }
  for (const file of importThemeCssFiles) {
    validateImportCssKiweTokenReferences(read(file), file);
    validateImportCssNoRuntimeBridgeTokens(read(file), file);
    validateImportCssNoAnonymousLiterals(read(file), file);
    validateImportCssNoProtectedRootPaint(read(file), file);
  }
}

for (const file of textFiles.filter((item) => /\.html?$/i.test(item))) {
  const body = read(file);
  for (const match of body.matchAll(/\bdata-dsa-module\s*=\s*["']([^"']+)["']/gi)) {
    const moduleId = String(match[1] || '').trim();
    if (moduleId && !knownDsaModules.has(moduleId) && !moduleId.startsWith('link-')) {
      add('warn', `Unknown DSA module "${moduleId}". URL-only dock links are valid, but they must be declared in theme-package.json settings.dock.custom_items and rendered as custom link items, not invented as registered DSA modules.`, rel(file));
    }
  }
  if (/\bdata-open-screen\s*=|\bdata-nav-anchor\s*=/.test(body)) {
    add('warn', 'Preview-only dock attributes such as data-open-screen/data-nav-anchor detected. Use Kiwe module launch hooks or theme-package settings for production handoff behavior.', rel(file));
  }
  for (const match of body.matchAll(/\bdata-dsa-menu-anchor\s*=\s*["']([^"']+)["']/gi)) {
    const anchor = String(match[1] || '').trim();
    if (anchor.startsWith('#')) {
      add('warn', `data-dsa-menu-anchor should contain a raw id such as "${anchor.slice(1)}", not a hash-prefixed selector.`, rel(file));
    }
    const rawAnchor = anchor.replace(/^#/, '');
    if (websiteText && rawAnchor && !websiteIds.has(rawAnchor)) {
      add('warn', `data-dsa-menu-anchor="${anchor}" does not match an id in website/bricks-paste.html. DSA Menu can only scroll to real page ids or headings generated by the live plugin.`, rel(file));
    }
  }
}

if (/data-dsa-module\s*=\s*["']home["']|>Home<\/|aria-label\s*=\s*["']Home["']/i.test(allText) && !hasCustomDockSettings) {
  add('warn', 'Home appears as a dock/AppShell item but no theme-package.json settings.dock.custom_items entry was found. Home/custom URL dock items are valid, but they must be declared as URL-only custom links rather than registered DSA screens.');
}

if (/\bdsa-dock-primary\b|data-dsa-dock-focus-id|focus button|split-dock center/i.test(allText) && !hasFocusItemSettings) {
  add('warn', 'The AppShell appears to choose a dock focus/primary item, but no theme-package.json settings.dock.focus_item was found. Add focus_item so the live split dock matches the preview.');
}

const websiteTextWithoutCartPlaceholders = websiteText
  ? websiteText.replace(/<button\b(?=[^>]*\bdata-project-role\s*=\s*["']add-to-cart-placeholder["'])[\s\S]*?<\/button>/gi, '')
  : '';
if (websiteTextWithoutCartPlaceholders && (/(cart|bag|account|profile)[^<]{0,80}(<\/button>|<\/a>)|aria-label\s*=\s*["'][^"']*(cart|bag|account|profile)/i.test(websiteTextWithoutCartPlaceholders)) && !/\bdata-dsa-open-module\b/.test(websiteTextWithoutCartPlaceholders)) {
  add('warn', 'Website/header appears to include cart/account/profile affordances without the canonical Kiwe open hook. Use data-dsa-open-module="cart" or data-dsa-open-module="profile".');
}

if (exists('bricks-bindings')) {
  if (!exists('bricks-bindings/kiwe-bindings.json')) {
    add('fail', 'bricks-bindings/ exists but bricks-bindings/kiwe-bindings.json is missing.');
  }

  if (exists('bricks-bindings/kiwe-bindings.json')) {
    const bindingPath = path.join(root, 'bricks-bindings/kiwe-bindings.json');
    let bindingJson = null;
    try {
      bindingJson = JSON.parse(read(bindingPath));
    } catch (error) {
      add('fail', `kiwe-bindings.json is invalid JSON: ${error.message}`, rel(bindingPath));
    }

    if (bindingJson) {
      if (bindingJson.schema !== 'kiwe.bricks-bindings.v1') {
        add('fail', 'kiwe-bindings.json schema must be kiwe.bricks-bindings.v1.', rel(bindingPath));
      }
      if (bindingJson.siteGraphSchema !== 'kiwe.site-graph.v1') {
        add('warn', 'kiwe-bindings.json should declare siteGraphSchema: kiwe.site-graph.v1 so bindings are tied to a real target-site context.', rel(bindingPath));
      }
      const target = bindingJson.target || {};
      if (target.builder !== 'bricks') {
        add('warn', 'kiwe-bindings.json target.builder should be "bricks" for the current dynamic binding pass.', rel(bindingPath));
      }
      if (/direct|auto|mutat|write|save/i.test(String(target.applyAuthority || '')) && !/human|adapter|trusted|review/i.test(String(target.applyAuthority || ''))) {
        add('warn', 'kiwe-bindings.json appears to claim direct apply authority. Dynamic pass output should be a binding plan unless a trusted Kiwe/Bricks apply tool actually ran.', rel(bindingPath));
      }
      const queries = Array.isArray(bindingJson.queries) ? bindingJson.queries : [];
      for (const query of queries) {
        const q = query && query.bricks && typeof query.bricks === 'object' ? query.bricks : {};
        if (!q.objectType) {
          add('warn', `Binding query "${query && query.id ? query.id : 'unnamed'}" is missing bricks.objectType. Use the Site Graph/Bricks query-loop types.`, rel(bindingPath));
        }
        const taxValues = []
          .concat(Array.isArray(q.tax_query) ? q.tax_query : [])
          .concat(Array.isArray(q.tax_query_not) ? q.tax_query_not : []);
        for (const value of taxValues) {
          if (typeof value === 'string' && !/^[a-z0-9_-]+::\d+$/i.test(value)) {
            add('warn', `Binding query "${query && query.id ? query.id : 'unnamed'}" uses taxonomy filter "${value}". Bricks taxonomy filters should use taxonomy::term_id from the Site Graph.`, rel(bindingPath));
          }
        }
      }
      const review = Array.isArray(bindingJson.requiresHumanReview) ? bindingJson.requiresHumanReview : [];
      if (/placeholder|TODO|guess|unknown/i.test(JSON.stringify(bindingJson)) && review.length === 0) {
        add('warn', 'Binding plan contains placeholder/unknown/guess language but requiresHumanReview is empty.', rel(bindingPath));
      }
    }
  }
}

const themeJsonFiles = files.filter((file) => path.basename(file) === 'theme.json');
for (const file of themeJsonFiles) {
  let json;
  try {
    json = JSON.parse(read(file));
  } catch (error) {
    add('fail', `theme.json is invalid JSON: ${error.message}`, rel(file));
    continue;
  }
  for (const key of ['schema', 'id', 'name', 'version', 'profile', 'screens', 'requires']) {
    if (!(key in json)) add('fail', `theme.json missing required key: ${key}`, rel(file));
  }
  if (json.schema && json.schema !== 'kiwe.surface-theme.v1') add('fail', 'theme.json schema must be kiwe.surface-theme.v1.', rel(file));
  for (const stale of ['schemaVersion', 'contract', 'requiredUiContract', 'supportedModes', 'supportedPresentations', 'supportedDockModes', 'supportedDockShapes', 'supportedColorModes']) {
    if (stale in json) add('fail', `theme.json uses stale/unsupported key: ${stale}`, rel(file));
  }
  const screens = Array.isArray(json.screens) ? json.screens.map((screen) => String(screen)) : [];
  const missingScreens = Array.from(getCoreScreens()).filter((screen) => !screens.includes(screen));
  if (missingScreens.length) {
    add('warn', `theme.json.screens omits registered core screens: ${missingScreens.join(', ')}. That is acceptable only for a clearly documented partial theme; marketplace-ready themes should skin all registered screens even if the current settings profile hides some dock icons.`, rel(file));
  }
  const payloads = getScreenPayloads();
  if (importThemeCssText && payloads && payloads.screens) {
    for (const screen of screens) {
      const requiredRoot = payloads.screens[screen] && payloads.screens[screen].requiredRoot;
      if (requiredRoot && !selectorIsMentioned(requiredRoot, importThemeCssText)) {
        add('fail', `theme.json lists screen "${screen}" but importable theme.css does not target its live runtime root ${requiredRoot}. A preview may still look correct, but the installed theme can fall back to Kiwe defaults for that screen/sheet.`, rel(file));
      }
    }
  }
}

for (const file of textFiles.filter((item) => /\.(html?|css|json)$/i.test(item))) {
  const badSelectors = bareSeamSelectorDeclarations(read(file));
  for (const selector of badSelectors.slice(0, 12)) {
    add('fail', `Project CSS redefines bare Seam framework selector "${selector}". Use Seam classes/attributes in markup, but put visual CSS on project-owned classes such as .brand-card, .nc-category-track, or .appsite-rail so framework flow classes cannot shrink or rearrange Bricks layouts.`, rel(file));
  }
  if (/\.(html?)$/i.test(file)) {
    for (const misuse of nestedSeamRailMisuse(read(file))) {
      add('fail', `Seam rail flow is applied to the wrong wrapper: ${misuse}. Outer nav/sticky/container shells should remain normal layout; only the actual item track should use .seam-horizontal-rail or data-flow="horizontal-rail".`, rel(file));
    }
  }
}

const bricksConversionFiles = discoveredBricksConversionFiles;
const bricksTemplateFiles = discoveredBricksTemplateFiles;

for (const file of bricksConversionFiles) {
  for (const message of validateBricksConversionJson(file)) {
    const level = /should include the target Bricks version|should use title "Home"|should use templateType "content"/i.test(message) ? 'warn' : 'fail';
    add(level, message, rel(file));
  }
}

for (const file of bricksTemplateFiles) {
  const packageRoot = nativeBricksTemplatePackageRoot(file);
  const relativeTemplatePath = path.relative(packageRoot, file).replace(/\\/g, '/');
  for (const message of validateBricksTemplateExport(packageRoot, relativeTemplatePath)) {
    const level = /should include the target Bricks version|should use title "Home"|should use templateType "content"/i.test(message) ? 'warn' : 'fail';
    add(level, message, rel(file));
  }
  const text = read(file);
  if (!/"kiwe"\s*:|"kiweConversion"\s*:/i.test(text)) {
    add('warn', 'Native Bricks template upload JSON has no embedded Kiwe fidelity metadata. It may import, but `/audit /bricksconversion` has less source/parity proof.', rel(file));
  }
}

auditLeanBricksDocumentation(bricksConversionFiles.concat(bricksTemplateFiles));

if (exists('appshell-theme') && !themeJsonFiles.length) {
  add('fail', 'AppShell/DSA direction appears present but no importable theme.json was found.');
}

for (const file of themePackageFiles) {
  let json;
  try {
    json = JSON.parse(read(file));
  } catch (error) {
    add('fail', `theme-package.json is invalid JSON: ${error.message}`, rel(file));
    continue;
  }
  if (json.schema !== 'kiwe.theme-package.v1') add('fail', 'theme-package.json schema must be kiwe.theme-package.v1.', rel(file));
  if (!json.theme || typeof json.theme !== 'object') add('fail', 'theme-package.json must contain a root theme manifest object.', rel(file));
  if (!json.settings || typeof json.settings !== 'object') add('warn', 'theme-package.json has no root settings object. Add it when dock composition, focus item, shape, sheet behavior, colors, or visual effects are part of the design.', rel(file));
  if (typeof json.css !== 'string' || !json.css.trim()) add('warn', 'theme-package.json should contain root css with the same presentation CSS as css/theme.css so Kiwe admin/API can import one theme file.', rel(file));
  if (json.theme && json.theme.id && !rel(file).includes(`/import/${json.theme.id}/`)) {
    add('warn', `theme-package.json theme.id "${json.theme.id}" does not match its import folder path.`, rel(file));
  }
  validateThemePackageSettings(json, file);
}

const placeholderText = textFiles
  .filter((file) => /PLACEHOLDERS\.md$/i.test(file))
  .map((file) => read(file))
  .join('\n');
if (/Your tea-time bag|Pairs well with|tea-time bag|bag is ready/i.test(combinedPreviewText) && !hasScreenCartSettings && !/cart copy[\s\S]{0,120}preview-only|preview-only[\s\S]{0,120}cart copy/i.test(placeholderText)) {
  add('fail', 'Combined preview contains custom cart/bag copy, but no theme-package.json settings.screens.cart preset was found. Live-intended cart copy must travel in the installed theme package; otherwise document it as preview-only in PLACEHOLDERS.md.', rel(combinedPreviewPath || root));
}
if (/Your\s+[A-Z][^<\n]{0,70}\s+account|National customer|customer account/i.test(combinedPreviewText) && !hasScreenProfileSettings && !/profile copy[\s\S]{0,120}preview-only|preview-only[\s\S]{0,120}profile copy/i.test(placeholderText)) {
  add('fail', 'Combined preview contains custom profile/account copy, but no theme-package.json settings.screens.profile preset was found. Live-intended Profile copy must travel in the installed theme package; otherwise document it as preview-only in PLACEHOLDERS.md.', rel(combinedPreviewPath || root));
}
if (/National links|Shop all products|Tea-time bag|Open store locations|Corporate gifting/i.test(combinedPreviewText) && !hasScreenLinksSettings && !/links copy[\s\S]{0,120}preview-only|preview-only[\s\S]{0,120}links copy/i.test(placeholderText)) {
  add('fail', 'Combined preview contains custom Links screen/action copy, but no theme-package.json settings.screens.links preset was found. Live-intended Links copy must travel in the installed theme package; otherwise document it as preview-only in PLACEHOLDERS.md.', rel(combinedPreviewPath || root));
}
if (/settings\.screens\.cart|settings\.screens\.profile|settings\.screens\.links|settings\.screens\.ai/i.test(allText) && !hasScreenSettings && exists('appshell-theme')) {
  add('warn', 'The handoff discusses live screen-copy settings but no theme-package.json settings.screens object was found. Ensure all live-intended DSA screen/sheet copy is in the installed theme package.');
}

if (exists('appshell-theme') && !themePackageFiles.length && (hasThemePackageSettings || /custom_items|focus_item|split_style|"shape"\s*:/i.test(allText))) {
  add('fail', 'AppShell theme appears to define dock/theme settings but no appshell-theme/import/<theme-id>/theme-package.json was found. Current Kiwe imports installed theme packages, not loose settings profiles.');
}

const forbiddenRuntime = [
  ['serviceWorker', /serviceWorker|navigator\.serviceWorker/i],
  ['remote fetch', /\bfetch\s*\(|axios|XMLHttpRequest/i],
  ['localStorage for capability state', /localStorage|sessionStorage/i],
  ['payment authority', /stripe|razorpay|paypal|checkout\s*session/i]
];
for (const [label, pattern] of forbiddenRuntime) {
  if (pattern.test(allText)) add('warn', `Check ${label}: production authority must remain Kiwe/WordPress/Woo/Bricks-owned.`);
}

if (/data-dsa-save|data-dsa-open|data-dsa-cart|data-dsa-checkout/i.test(allText) && !/preview-only|placeholder/i.test(allText)) {
  add('warn', 'Kiwe capability attributes appear without clear preview-only/placeholder documentation.');
}

const roles = getSeamRoles();
if (roles.size) {
  for (const file of textFiles.filter((item) => /\.html?$/i.test(item))) {
    const body = read(file);
    const seen = new Set();
    for (const match of body.matchAll(/\bdata-role\s*=\s*["']([^"']+)["']/gi)) {
      for (const value of String(match[1]).split(/\s+/).filter(Boolean)) {
        if (!roles.has(value) && !seen.has(value)) {
          seen.add(value);
          add('warn', `Non-standard Seam data-role value "${value}". Use official Seam roles only; use Seam classes, project classes, or data-project-role for custom concepts.`, rel(file));
        }
      }
    }
  }
}

if (/"(?:id|name)"\s*:\s*"[^"]*(?:aurora|glassmorphism|frosted)|class\s*=\s*["'][^"']*\b(?:aurora|glassmorphism|frosted|glass-card|frosted-card)\b/i.test(allText)) {
  add('warn', 'Design may be drifting toward common Aurora/glass/bento patterns; confirm a distinct visual thesis.');
}

if (/backdrop-filter/i.test(allText)) {
  add('warn', 'backdrop-filter detected. It can be valid, but overuse often recreates generic glass and may affect performance.');
}

if (!/distinctness|visual thesis/i.test(allText)) add('warn', 'Missing distinctness/visual thesis note.');
if (!/selector-fit|selector fit/i.test(allText)) add('warn', 'Missing selector-fit checklist.');
if (!/validation/i.test(allText)) add('warn', 'Missing validation instructions.');

const grouped = findings.reduce((acc, item) => {
  acc[item.level] = (acc[item.level] || 0) + 1;
  return acc;
}, {});

console.log(JSON.stringify({ ok: !findings.some((item) => item.level === 'fail'), root, counts: grouped, findings }, null, 2));
process.exitCode = findings.some((item) => item.level === 'fail') ? 1 : 0;
