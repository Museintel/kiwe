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
if (bricksPastePath) {
  const bricksPaste = read(bricksPastePath);
  if (/\bdata-dsa-surface\b|class\s*=\s*["'][^"']*\bdsa-surface\b|class\s*=\s*["'][^"']*\bdsa-dock\b|class\s*=\s*["'][^"']*\bdsa-sheet\b|\bshowKiweSheet\s*\(/i.test(bricksPaste)) {
    add('fail', 'website/bricks-paste.html contains Kiwe AppShell/dock/sheet markup or preview controller code. The Bricks page artifact must be page-only; only combined-preview/index.html should show the AppShell over the page.', rel(bricksPastePath));
  }
}

if (exists('website') && exists('appshell-theme') && !exists('combined-preview/index.html')) {
  add('fail', 'Combined handoff is missing combined-preview/index.html, the primary review artifact showing the website/page behind the Kiwe DSA AppShell.');
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
      add('warn', `Unknown DSA module "${moduleId}". Use registered modules, or define a URL-only custom dock link in kiwe-settings dock.custom_items with an id such as link-home.`, rel(file));
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
  }
}

if (/data-dsa-module\s*=\s*["']home["']|>Home<\/|aria-label\s*=\s*["']Home["']/i.test(allText) && !hasCustomDockSettings) {
  add('warn', 'Home appears as a dock/AppShell item but no kiwe-settings dock.custom_items entry was found. Add a URL-only custom dock link instead of inventing a Home DSA screen.');
}

if (/\bdsa-dock-primary\b|data-dsa-dock-focus-id|focus button|split-dock center/i.test(allText) && !hasFocusItemSettings) {
  add('warn', 'The AppShell appears to choose a dock focus/primary item, but no kiwe-settings dock.focus_item was found. Add focus_item so the live split dock matches the preview.');
}

const websiteText = bricksPastePath ? read(bricksPastePath) : '';
if (websiteText && /#heritage|#shop-bestsellers|#limca-record|data-dsa-menu-anchor|table of contents|on this page/i.test(allText) && !/\bdata-kiwe-menu-section\b/.test(websiteText)) {
  add('warn', 'The AppShell/menu preview references page sections, but website/bricks-paste.html does not expose data-kiwe-menu-section + data-kiwe-menu-label landmarks. Add them to the real sections so live DSA Menu context can scroll correctly.');
}

if (websiteText && /(cart|bag|account|profile)[^<]{0,80}(<\/button>|<\/a>)|aria-label\s*=\s*["'][^"']*(cart|bag|account|profile)/i.test(websiteText) && !/\bdata-(?:kiwe-open|dsa-open|dsa-open-module)\b/.test(websiteText)) {
  add('warn', 'Website/header appears to include cart/account/profile affordances without Kiwe open hooks. Use data-kiwe-open="cart", data-kiwe-open="profile", or canonical data-dsa-open-module.');
}

if (!exists('website/bricks-notes.md') && !exists('bricks-notes.md')) {
  add('warn', 'Missing bricks-notes.md explaining Bricks import mapping and capability boundaries.');
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

if (/Aurora|glassmorphism|frosted|bento/i.test(allText)) {
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
