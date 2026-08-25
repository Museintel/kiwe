#!/usr/bin/env node
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  console.log('Usage: node tools/validate-seamframework.cjs <framework-package-dir-or-raw-page>');
  console.log('');
  console.log('Auto-detects a compiler Framework package and runs the package integration validator.');
  console.log('For legacy raw-page artifacts it delegates to audit-output.cjs when present, then falls back');
  console.log('to the bundled self-contained Seam page checks.');
  process.exit(0);
}

const target = path.resolve(process.argv[2] || '.');
const targetExists = fs.existsSync(target);
const targetIsFile = targetExists && fs.statSync(target).isFile();
const root = targetIsFile ? path.dirname(target) : target;
const auditTool = path.join(__dirname, 'audit-output.cjs');
const packageTool = path.join(__dirname, 'validate-seamframework-package.cjs');
const frameworkProfile = path.join(root, 'framework', 'kiwe-framework-profile.json');

if (fs.existsSync(frameworkProfile) && fs.existsSync(packageTool)) {
  const result = spawnSync(process.execPath, [packageTool, root], { cwd: path.resolve(__dirname, '..'), stdio: 'inherit' });
  if (result.error) {
    console.error(JSON.stringify({ ok: false, error: 'KIWE_FRAMEWORK_PACKAGE_VALIDATOR_UNAVAILABLE', message: result.error.message }, null, 2));
    process.exit(1);
  }
  process.exit(typeof result.status === 'number' ? result.status : 1);
}

if (fs.existsSync(auditTool)) {
  const result = spawnSync(process.execPath, [auditTool, target], {
    cwd: path.resolve(__dirname, '..'),
    stdio: 'inherit'
  });

  if (result.error) {
    console.error(JSON.stringify({
      ok: false,
      error: 'KIWE_VALIDATOR_UNAVAILABLE',
      message: result.error.message
    }, null, 2));
    process.exit(1);
  }

  process.exit(typeof result.status === 'number' ? result.status : 1);
}

function walk(dir, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (!['node_modules', '.git', 'dist', 'build', 'kiwe-contracts'].includes(entry.name)) walk(full, out);
    } else {
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

function sha256(file) {
  try {
    return require('node:crypto').createHash('sha256').update(fs.readFileSync(file)).digest('hex');
  } catch (_) {
    return '';
  }
}

const findings = [];
function add(level, message, file = '', details = {}) {
  findings.push({ level, message, file, ...details });
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

const fallbackRoles = new Set([
  'section', 'hero', 'card', 'nav', 'button', 'form', 'field', 'input', 'search',
  'testimonial', 'price', 'footer', 'header', 'main', 'media', 'avatar', 'badge',
  'chip', 'link', 'menu', 'dialog', 'drawer', 'toast', 'progress', 'skeleton',
  'lead', 'eyebrow', 'label', 'caption', 'hint'
]);

function getRoles() {
  const candidates = [
    path.join(__dirname, '..', 'packs', 'website-builder', 'contracts', 'seam-vocabulary.json'),
    path.join(__dirname, '..', 'packs', 'appshell-theme', 'seam-vocabulary.json')
  ];
  for (const file of candidates) {
    if (!fs.existsSync(file)) continue;
    try {
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      if (Array.isArray(json.role)) return new Set(json.role.map(String));
    } catch (_) {}
  }
  return fallbackRoles;
}

const files = targetIsFile ? [target] : walk(root);
const textFiles = files.filter((file) => /\.(html|css|js|json|md|txt)$/i.test(file));
const allText = textFiles.map(read).join('\n');

if (!targetExists) {
  add('fail', 'Target artifact does not exist.', '');
}

const bricksPastePath = targetIsFile && /\.html?$/i.test(target)
  ? target
  : (exists('website/bricks-paste.html')
    ? path.join(root, 'website/bricks-paste.html')
    : (exists('bricks-paste.html') ? path.join(root, 'bricks-paste.html') : ''));

if (!bricksPastePath) {
  add('fail', 'Missing bricks-paste.html. It is the single website/page artifact: browser preview and Bricks HTML-to-Bricks copy/paste file.');
}

for (const file of textFiles.filter((item) => /\.(html?|css|json)$/i.test(item))) {
  const text = read(file);
  for (const selector of bareSeamSelectorDeclarations(text).slice(0, 12)) {
    add('fail', `Project CSS redefines bare Seam framework selector "${selector}". Use Seam classes/attributes in markup, but put visual CSS on project-owned classes such as .brand-card, .nc-category-track, or .appsite-rail so framework flow classes cannot shrink or rearrange Bricks layouts.`, rel(file));
  }
  if (/\.html?$/i.test(file)) {
    for (const misuse of nestedSeamRailMisuse(text)) {
      add('fail', `Seam rail flow is applied to the wrong wrapper: ${misuse}. Outer nav/sticky/container shells should remain normal layout; only the actual item track should use .seam-horizontal-rail or data-flow="horizontal-rail".`, rel(file));
    }
  }
}

const roles = getRoles();
for (const file of textFiles.filter((item) => /\.html?$/i.test(item))) {
  const seen = new Set();
  for (const match of read(file).matchAll(/\bdata-role\s*=\s*["']([^"']+)["']/gi)) {
    for (const value of String(match[1]).split(/\s+/).filter(Boolean)) {
      if (!roles.has(value) && !seen.has(value)) {
        seen.add(value);
        add('warn', `Non-standard Seam data-role value "${value}". Use official Seam roles only; use Seam classes, project classes, or data-project-role for custom concepts.`, rel(file));
      }
    }
  }
}

if (/backdrop-filter/i.test(allText)) {
  add('warn', 'backdrop-filter detected. It can be valid, but overuse often recreates generic glass and may affect performance.');
}
if (!/distinctness|visual thesis/i.test(allText)) add('warn', 'Missing distinctness/visual thesis note.');
if (!/selector-fit|selector fit/i.test(allText)) add('warn', 'Missing selector-fit checklist.');
if (!/validation/i.test(allText)) add('warn', 'Missing validation instructions.');

const summary = findings.reduce((acc, item) => {
  acc[item.level] = (acc[item.level] || 0) + 1;
  return acc;
}, {});
const artifactHash = bricksPastePath ? sha256(bricksPastePath) : '';
const result = {
  ok: (summary.fail || 0) === 0,
  schema: 'kiwe.seamframework-validation.v1',
  validator: 'validate-seamframework.cjs',
  mode: fs.existsSync(auditTool) ? 'delegated-audit-output' : 'self-contained-fallback',
  target,
  root,
  artifact: bricksPastePath ? rel(bricksPastePath) : '',
  artifactSha256: artifactHash,
  counts: summary,
  findings
};

console.log(JSON.stringify(result, null, 2));
process.exit(result.ok ? 0 : 1);
