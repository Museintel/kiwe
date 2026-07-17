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
  const candidates = [
    path.join(__dirname, '..', 'packs', 'appshell-theme', 'screen-payloads.json'),
    path.join(__dirname, '..', 'packs', 'website-builder', 'screen-payloads.json')
  ];
  for (const file of candidates) {
    if (!fs.existsSync(file)) continue;
    try {
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      for (const screen of Object.keys(json.screens || {})) coreScreens.add(String(screen));
    } catch (_) {
      // Non-fatal; audit can continue without screen coverage checks.
    }
    if (coreScreens.size) break;
  }
  return coreScreens;
}

function add(level, message, file = '') {
  findings.push({ level, message, file });
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
const settingsText = textFiles
  .filter((file) => rel(file).startsWith('kiwe-settings/'))
  .map((file) => read(file))
  .join('\n');
const hasCustomDockSettings = /"custom_items"\s*:\s*\[/.test(settingsText);
const hasFocusItemSettings = /"focus_item"\s*:/.test(settingsText);

for (const file of textFiles.filter((item) => /\.html?$/i.test(item))) {
  const body = read(file);
  for (const match of body.matchAll(/\bdata-dsa-module\s*=\s*["']([^"']+)["']/gi)) {
    const moduleId = String(match[1] || '').trim();
    if (moduleId && !knownDsaModules.has(moduleId) && !moduleId.startsWith('link-')) {
      add('warn', `Unknown DSA module "${moduleId}". URL-only dock links are valid, but they must be declared in kiwe-settings dock.custom_items and rendered as custom link items, not invented as registered DSA modules.`, rel(file));
    }
  }
  if (/\bdata-open-screen\s*=|\bdata-nav-anchor\s*=/.test(body)) {
    add('warn', 'Preview-only dock attributes such as data-open-screen/data-nav-anchor detected. Use Kiwe module launch hooks or kiwe-settings for production handoff behavior.', rel(file));
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
  add('warn', 'Home appears as a dock/AppShell item but no kiwe-settings dock.custom_items entry was found. Home/custom URL dock items are valid, but they must be declared as URL-only custom links rather than registered DSA screens.');
}

if (/\bdsa-dock-primary\b|data-dsa-dock-focus-id|focus button|split-dock center/i.test(allText) && !hasFocusItemSettings) {
  add('warn', 'The AppShell appears to choose a dock focus/primary item, but no kiwe-settings dock.focus_item was found. Add focus_item so the live split dock matches the preview.');
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
}

if (exists('appshell-theme') && !themeJsonFiles.length) {
  add('fail', 'AppShell/DSA direction appears present but no importable theme.json was found.');
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
