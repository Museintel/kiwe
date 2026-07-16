import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
export const toolkitRoot = path.resolve(__dirname, '..');

export const modes = {
  website: {
    label: 'Website/page only',
    packs: ['website-builder'],
    summary: 'Normal WordPress/Bricks website or page using Seam Framework. No AppShell theme package.'
  },
  theme: {
    label: 'DSA AppShell theme only',
    packs: ['appshell-theme'],
    summary: 'Kiwe DSA/AppShell theme package and preview. No website/page build.'
  },
  combined: {
    label: 'Website/page + DSA AppShell theme',
    packs: ['website-builder', 'appshell-theme'],
    summary: 'Website/page, AppShell theme package, and optional Kiwe settings profile kept in separate folders.'
  }
};

export function normalizeMode(mode) {
  const key = String(mode || '').trim().toLowerCase();
  if (!modes[key]) {
    throw new Error(`Unknown Kiwe mode "${mode}". Use one of: ${Object.keys(modes).join(', ')}`);
  }
  return key;
}

export function listModes() {
  return Object.entries(modes).map(([key, value]) => ({ mode: key, ...value }));
}

function readMaybe(relPath) {
  const full = path.join(toolkitRoot, relPath);
  return fs.existsSync(full) ? fs.readFileSync(full, 'utf8') : '';
}

export function getContext(mode = 'website') {
  const normalized = normalizeMode(mode);
  const parts = [
    `# Kiwe context: ${modes[normalized].label}`,
    '',
    modes[normalized].summary,
    '',
    '## Important boundary',
    '',
    'Use the bundled contracts. Do not ask for or read the full Kiwe/DSA plugin codebase.',
    'Do not create cart, checkout, auth, save, search, AI, service-worker, history, focus, or WooCommerce authority.',
    ''
  ];

  if (normalized === 'website' || normalized === 'combined') {
    parts.push(readMaybe('packs/website-builder/README-FIRST.md'));
    parts.push(readMaybe('packs/website-builder/HANDOFF-LITE.md'));
    parts.push(readMaybe('packs/website-builder/prompt.md'));
  }

  if (normalized === 'theme' || normalized === 'combined') {
    parts.push(readMaybe('packs/appshell-theme/README.md'));
    parts.push(readMaybe('packs/appshell-theme/prompt.md'));
    parts.push(readMaybe('packs/appshell-theme/preview-handoff.md'));
  }

  parts.push(readMaybe('packs/website-builder/HANDOFF-MODES.md'));

  return parts.filter(Boolean).join('\n\n').trim() + '\n';
}

export function listClassVocabulary() {
  const candidates = [
    'packs/website-builder/contracts/seam-class-vocabulary.json',
    'packs/appshell-theme/seam-class-vocabulary.json'
  ];
  for (const rel of candidates) {
    const full = path.join(toolkitRoot, rel);
    if (fs.existsSync(full)) {
      return JSON.parse(fs.readFileSync(full, 'utf8'));
    }
  }
  throw new Error('Seam class vocabulary was not found in toolkit packs.');
}

function safeName(value, fallback) {
  const raw = String(value || fallback || 'kiwe-handoff').toLowerCase();
  return raw.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80) || fallback;
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function writeFile(file, content) {
  ensureDir(path.dirname(file));
  fs.writeFileSync(file, content, 'utf8');
}

function copyDir(src, dest) {
  ensureDir(dest);
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    const from = path.join(src, entry.name);
    const to = path.join(dest, entry.name);
    if (entry.isDirectory()) {
      copyDir(from, to);
    } else if (entry.isFile()) {
      fs.copyFileSync(from, to);
    }
  }
}

function websiteScaffold(root, brief) {
  writeFile(path.join(root, 'website/preview/index.html'), `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwe website preview</title>
  <link rel="stylesheet" href="./assets/site.css">
</head>
<body>
  <main class="seam-section seam-stack seam-gap-lg">
    <section class="seam-hero seam-stack seam-gap-md">
      <p class="seam-eyebrow">Kiwe / Seam preview</p>
      <h1>Replace this with the requested website/page concept.</h1>
      <p class="seam-lead">${brief || 'Use Seam Class Vocabulary and Kiwe tokens. Keep behavior preview-only.'}</p>
    </section>
  </main>
</body>
</html>
`);
  writeFile(path.join(root, 'website/preview/assets/site.css'), `@import "../../../packs/website-builder/contracts/token-map.css";

/* Website/page CSS goes here. Use Seam Class Vocabulary names and Kiwe/Seam tokens. */
body {
  margin: 0;
  font-family: var(--kiwe-font-body, system-ui, sans-serif);
}
`);
  writeFile(path.join(root, 'website/bricks-notes.md'), '# Bricks notes\n\nDescribe how this preview should be imported or recreated in Bricks. Do not include generated Bricks IDs.\n');
}

function themeScaffold(root, name) {
  const id = safeName(name, 'kiwe-theme');
  writeFile(path.join(root, `appshell-theme/import/${id}/theme.json`), JSON.stringify({
    schemaVersion: 1,
    id,
    name: id.replace(/-/g, ' '),
    version: '0.1.0',
    description: 'Kiwe DSA AppShell theme generated from Kiwe AI Toolkit.',
    author: 'Kiwe AI Toolkit',
    supports: {
      presentation: ['classic', 'sheet'],
      dock: ['dock', 'navbar'],
      dockShapes: ['pill', 'box', 'square'],
      colorModes: ['light', 'dark']
    },
    css: ['css/theme.css']
  }, null, 2) + '\n');
  writeFile(path.join(root, `appshell-theme/import/${id}/css/theme.css`), `/*
 * Kiwe DSA AppShell theme CSS.
 * Style existing DSA selectors only. Do not create runtime authority.
 */
`);
  writeFile(path.join(root, 'appshell-theme/preview/index.html'), '<!doctype html><html lang="en"><meta charset="utf-8"><title>Kiwe AppShell theme preview</title><body><p>Build standalone visual preview here. Link the import CSS.</p></body></html>\n');
  writeFile(path.join(root, 'appshell-theme/preview/PLACEHOLDERS.md'), '# Preview placeholders\n\nDocument mock products, account names, orders, links, scores, and AI data here. None of this belongs in the importable theme package.\n');
}

function settingsScaffold(root) {
  writeFile(path.join(root, 'kiwe-settings/kiwe-appsite-profile.json'), JSON.stringify({
    type: 'kiwe-appsite-profile',
    schemaVersion: 1,
    settings: {
      enabled: true,
      style: {
        visual_profile: 'kiwe2027',
        mode: 'sheet',
        sheet_position: 'bottom',
        sheet_spacing: 'inset',
        sheet_origin: 'above_dock',
        sheet_width_percent: 78
      },
      dock: {
        presentation: 'dock',
        split_style: true,
        shape: 'pill',
        desktop_orientation: 'auto',
        tablet_orientation: 'auto',
        mobile_orientation: 'auto',
        enabled_items: {
          menu: true,
          search: true,
          profile: true,
          links: true,
          saved: true,
          cart: true,
          theme: false,
          ai: true
        },
        item_order: ['menu', 'search', 'profile', 'links', 'saved', 'cart', 'theme', 'ai']
      }
    }
  }, null, 2) + '\n');
  writeFile(path.join(root, 'kiwe-settings/SETTINGS-NOTES.md'), '# Kiwe settings notes\n\nExplain every changed setting. Remove this folder if the design does not require Kiwe settings changes.\n');
}

export function createHandoff({ mode = 'website', outputDir = '', name = '', brief = '' } = {}) {
  const normalized = normalizeMode(mode);
  const baseName = safeName(name || `${normalized}-kiwe-handoff`, `${normalized}-kiwe-handoff`);
  const root = path.resolve(outputDir || baseName);
  ensureDir(root);

  writeFile(path.join(root, 'README.md'), `# ${baseName}

Mode: ${normalized}

${modes[normalized].summary}

## Brief

${brief || 'No brief provided yet.'}

## Required validation

Run Kiwe validation before importing or installing anything.
`);
  writeFile(path.join(root, 'KIWE_CONTEXT.md'), getContext(normalized));

  if (normalized === 'website' || normalized === 'combined') websiteScaffold(root, brief);
  if (normalized === 'theme' || normalized === 'combined') themeScaffold(root, baseName);
  if (normalized === 'combined') settingsScaffold(root);

  const contractsDir = path.join(root, 'kiwe-contracts');
  ensureDir(contractsDir);
  for (const pack of modes[normalized].packs) {
    copyDir(path.join(toolkitRoot, 'packs', pack), path.join(contractsDir, pack));
  }

  return { mode: normalized, outputDir: root };
}

export function validateHandoff(targetDir, mode = 'website') {
  const normalized = normalizeMode(mode);
  const root = path.resolve(targetDir || '.');
  const required = ['README.md', 'KIWE_CONTEXT.md'];
  if (normalized === 'website' || normalized === 'combined') {
    required.push('website/preview/index.html', 'website/preview/assets/site.css', 'website/bricks-notes.md');
  }
  if (normalized === 'theme' || normalized === 'combined') {
    required.push('appshell-theme/preview/index.html', 'appshell-theme/preview/PLACEHOLDERS.md');
  }
  if (normalized === 'combined') {
    required.push('kiwe-settings/SETTINGS-NOTES.md');
  }
  const missing = required.filter((rel) => !fs.existsSync(path.join(root, rel)));
  return {
    ok: missing.length === 0,
    mode: normalized,
    root,
    missing
  };
}
