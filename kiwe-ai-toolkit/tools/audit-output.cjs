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

function add(level, message, file = '') {
  findings.push({ level, message, file });
}

if (exists('package.json') || exists('vite.config.ts') || exists('tailwind.config.js') || exists('components.json')) {
  add('fail', 'Output looks like a React/Vite/Tailwind/shadcn app. Kiwe handoffs must be plain HTML/CSS with optional preview-only JS unless an app prototype was explicitly requested.');
}

if (!exists('website/preview/index.html') && !exists('preview/index.html')) {
  add('fail', 'Missing standalone website preview index.html.');
}

if (!exists('website/bricks-paste.html') && !exists('bricks-paste.html')) {
  add('fail', 'Missing bricks-paste.html copy/paste artifact for Bricks HTML-to-Bricks.');
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

if (!themeJsonFiles.length && /appshell|dsa|dock|sheet/i.test(allText)) {
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
