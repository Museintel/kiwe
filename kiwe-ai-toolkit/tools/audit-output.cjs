#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  console.log('Usage: node tools/audit-output.cjs <handoff-or-ai-output-dir>');
  process.exit(0);
}

const root = path.resolve(process.argv[2] || '.');

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

const files = walk(root);
const textFiles = files.filter((file) => /\.(html|css|js|json|md|txt|tsx|ts|jsx)$/i.test(file));
const allText = textFiles.map((file) => `\n--- ${rel(file)} ---\n${read(file)}`).join('\n');
const findings = [];
let seamRoles = null;
let coreScreens = null;
let screenPayloads = null;
let officialTokenNames = null;

const themeTokenTopLevelKeys = new Set(['enabled', 'profile_label', 'overrides', 'bricks_theme_style']);
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

function isPlainObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value);
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

if (!exists('website/bricks-paste.html') && !exists('bricks-paste.html')) {
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

if (websiteText && /(cart|bag|account|profile)[^<]{0,80}(<\/button>|<\/a>)|aria-label\s*=\s*["'][^"']*(cart|bag|account|profile)/i.test(websiteText) && !/\bdata-dsa-open-module\b/.test(websiteText)) {
  add('warn', 'Website/header appears to include cart/account/profile affordances without the canonical Kiwe open hook. Use data-dsa-open-module="cart" or data-dsa-open-module="profile".');
}

if (!exists('website/bricks-notes.md') && !exists('bricks-notes.md')) {
  add('warn', 'Missing bricks-notes.md explaining Bricks import mapping and capability boundaries.');
}

if (exists('bricks-bindings')) {
  if (!exists('bricks-bindings/kiwe-bindings.json')) {
    add('fail', 'bricks-bindings/ exists but bricks-bindings/kiwe-bindings.json is missing.');
  }
  if (!exists('bricks-bindings/BINDING-NOTES.md')) {
    add('warn', 'bricks-bindings/ exists but BINDING-NOTES.md is missing. Explain Site Graph sources, assumptions, review items, and apply authority.');
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
