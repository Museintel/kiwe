#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

function usage() {
  console.log('Usage: node tools/validate-output.cjs <handoff-dir> --mode <website|theme|combined>');
}

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  usage();
  process.exit(0);
}

const target = process.argv[2] || '.';
const modeIndex = process.argv.indexOf('--mode');
const mode = modeIndex >= 0 ? process.argv[modeIndex + 1] : 'website';
const validModes = new Set(['website', 'theme', 'combined']);
if (!validModes.has(mode)) {
  console.error(`Unknown mode: ${mode}`);
  usage();
  process.exit(1);
}

const root = path.resolve(target);
const required = ['README.md'];
if (mode === 'website' || mode === 'combined') {
  required.push('website/bricks-paste.html', 'website/bricks-notes.md');
}
if (mode === 'theme') {
  required.push('appshell-theme/preview/index.html', 'appshell-theme/preview/PLACEHOLDERS.md');
}
if (mode === 'combined') {
  required.push('combined-preview/index.html');
}

const missing = required.filter((rel) => !fs.existsSync(path.join(root, rel)));
if (missing.length) {
  console.error(`Kiwe handoff validation failed for ${root}`);
  for (const rel of missing) console.error(`Missing: ${rel}`);
  process.exit(1);
}

function stripCssComments(css) {
  return String(css || '').replace(/\/\*[\s\S]*?\*\//g, '');
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

function protectedAppShellRootPaint(css) {
  const findings = [];
  const paintPattern = /(?:^|;)\s*(?:background(?:-color|-image)?|border(?:-[a-z-]+)?|box-shadow|filter|backdrop-filter|opacity)\s*:/i;
  for (const match of stripCssComments(css).matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const selector = match[1].trim();
    const declarations = match[2];
    if (selectorTargetsProtectedAppShellRoot(selector) && paintPattern.test(declarations)) {
      findings.push(selector);
    }
  }
  return findings;
}

function anonymousPixelLiterals(css) {
  const literals = new Set();
  for (const match of stripCssComments(css).matchAll(/(^|[^-_a-zA-Z0-9.])((?:\d*\.)?\d+px)\b/gi)) {
    literals.add(match[2].toLowerCase());
  }
  return Array.from(literals).sort();
}

if (mode === 'combined') {
  const privatePreviewClasses = [
    'dsa-screen-head',
    'dsa-screen-body',
    'dsa-profile-card',
    'dsa-score-card',
    'dsa-links-identity',
    'dsa-account-rows',
    'dsa-link-list',
    'dsa-install-steps',
    'dsa-game-frame',
    'kiwe-preview-panel',
    'kiwe-preview-panel-heading',
    'kiwe-preview-alpha',
    'kiwe-preview-fbt',
    'kiwe-preview-score',
    'kiwe-preview-empty',
    'kiwe-preview-muted'
  ];
  const combinedFiles = [
    'combined-preview/index.html',
    'combined-preview/assets/combined-preview.css'
  ].filter((rel) => fs.existsSync(path.join(root, rel)));
  const leaked = [];
  for (const rel of combinedFiles) {
    const text = fs.readFileSync(path.join(root, rel), 'utf8');
    for (const name of privatePreviewClasses) {
      const pattern = new RegExp(`(^|[^-_a-zA-Z0-9])${name}([^-_a-zA-Z0-9]|$)`);
      if (pattern.test(text)) leaked.push(`${rel}:${name}`);
    }
  }
  if (leaked.length) {
    console.error(`Kiwe handoff validation failed for ${root}`);
    console.error('Primary combined preview uses private AppShell fixture structure that Kiwe core does not render live:');
    for (const item of leaked) console.error(`Private fixture: ${item}`);
    console.error('Use documented live DSA roots/internals in the primary combined preview. Keep custom mock wrappers only in optional technical fixtures, clearly labelled preview-only.');
    process.exit(1);
  }
}

if (mode === 'theme' || mode === 'combined') {
  const importRoot = path.join(root, 'appshell-theme', 'import');
  if (fs.existsSync(importRoot)) {
    const themeDirs = fs.readdirSync(importRoot, { withFileTypes: true }).filter((entry) => entry.isDirectory());
    for (const entry of themeDirs) {
      for (const rel of [
        `appshell-theme/import/${entry.name}/theme.json`,
        `appshell-theme/import/${entry.name}/css/theme.css`,
        `appshell-theme/import/${entry.name}/theme-package.json`
      ]) {
        if (!fs.existsSync(path.join(root, rel))) {
          console.error(`Kiwe handoff validation failed for ${root}`);
          console.error(`Missing: ${rel}`);
          process.exit(1);
        }
      }
      const cssRel = `appshell-theme/import/${entry.name}/css/theme.css`;
      const css = fs.readFileSync(path.join(root, cssRel), 'utf8');
      const pixelLiterals = anonymousPixelLiterals(css);
      if (pixelLiterals.length) {
        console.error(`Kiwe handoff validation failed for ${root}`);
        console.error(`${cssRel} contains anonymous pixel literal(s): ${pixelLiterals.join(', ')}`);
        console.error('Importable AppShell theme CSS must consume official --kiwe-* universal tokens, documented --kiwe-theme-* aliases, or Kiwe/DSA geometry variables. Concrete base values belong in theme-package.json settings.tokens or Kiwe core token registries, not installable theme.css.');
        process.exit(1);
      }
      const rootPaint = protectedAppShellRootPaint(css);
      if (rootPaint.length) {
        console.error(`Kiwe handoff validation failed for ${root}`);
        console.error(`${cssRel} paints the protected AppShell surface root:`);
        for (const selector of rootPaint) console.error(`Protected root paint: ${selector}`);
        console.error('The DSA surface root is transparent Kiwe runtime scaffolding. Theme CSS may set tokens/inherited typography on the root, but backgrounds, borders, shadows, opacity, and filters belong on dock/sheet/screen/panel parts.');
        process.exit(1);
      }
      if (!/data-dsa-part/.test(css)) {
        console.error(`Kiwe handoff validation failed for ${root}`);
        console.error(`${cssRel} does not target documented live AppShell part hooks.`);
        console.error('Use documented live hooks such as [data-dsa-screen-name="cart"] [data-dsa-part="summary"], [data-dsa-part="card"], [data-dsa-part="context"], etc. Protected data-seam-* metadata is for tooling/diagnostics, not importable theme styling. Broad root/panel color styling alone is not enough for an installable DSA theme.');
        process.exit(1);
      }
    }
  }
}

const frameworkProfilePresent = fs.existsSync(path.join(root, 'framework', 'kiwe-framework-profile.json')) || fs.existsSync(path.join(root, 'kiwe-framework-profile.json'));
if (frameworkProfilePresent) {
  try {
    execFileSync(process.execPath, [path.join(__dirname, 'validate-framework-profile.cjs'), root], {
      cwd: root,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe']
    });
  } catch (error) {
    console.error(`Kiwe handoff validation failed for ${root}`);
    const stdout = error && error.stdout ? String(error.stdout).trim() : '';
    const stderr = error && error.stderr ? String(error.stderr).trim() : '';
    if (stdout) console.error(stdout);
    if (stderr) console.error(stderr);
    process.exit(1);
  }
}

console.log(`Kiwe handoff OK: ${root} (${mode})`);
