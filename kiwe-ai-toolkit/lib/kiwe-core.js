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

function themeManifestQuickContract() {
  return `# AppShell theme.json quick contract

If your output includes \`appshell-theme/import/[theme-id]/theme.json\`, copy this shape and only change values that are clearly marked as theme-specific.

Do not invent alternate manifest keys.

Important:

- Use \`schema\`, not \`type\`.
- Do not use \`schemaVersion\` in AppShell theme manifests. \`schemaVersion\` is only used by optional Kiwe settings profiles.
- Do not use nested \`contracts\`, \`colorAuthority\`, \`authority\`, \`supportedPresentationModes\`, \`supportedDockShapes\`, \`cssFiles\`, or object-form \`supports\`.
- \`supports\` must be an array of allowed strings.
- \`screens\` must use Kiwe screen names only.

\`\`\`json
{
  "schema": "kiwe.surface-theme.v1",
  "id": "your-theme-id",
  "name": "Your Theme Name",
  "version": "1.0.0",
  "profile": "marketplace",
  "mode": "css-only",
  "description": "Short presentation-only Kiwe DSA AppShell theme description under 240 characters.",
  "author": "Your name or team",
  "css": ["css/theme.css"],
  "assets": [],
  "screens": ["profile", "cart", "checkout", "search", "menu", "saved", "links", "notifications", "ios-install", "games", "ai"],
  "requires": {
    "uiContract": "kiwe.surface-ui.v2",
    "tokenContract": "kiwe.universal",
    "minKiwe": "0.5.75"
  },
  "supports": ["light", "dark", "sheet", "classic", "dock", "split-dock", "full-dock", "navigation-bar", "dock-shape-pill", "dock-shape-box", "dock-shape-square", "horizontal", "vertical", "reduced-motion"],
  "budgets": {
    "cssKb": 40,
    "jsKb": 0,
    "blockingAssets": 0
  },
  "forbidden": ["remote-code", "trackers", "php", "service-worker", "history-owner", "cart-owner", "checkout-owner", "phonekey-owner", "bricks-owner"]
}
\`\`\`

If a theme does not cover a screen, remove that screen from \`screens\`. Do not add unsupported screen names such as \`orders\`, \`downloads\`, \`addresses\`, or \`install\`; those are payload sections or concepts inside supported screens, not theme-manifest screen IDs.
`;
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
    parts.push(themeManifestQuickContract());
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

function inferMode(mode, brief) {
  const requested = String(mode || '').trim().toLowerCase();
  if (requested && requested !== 'auto') {
    return normalizeMode(requested);
  }

  const text = String(brief || '').toLowerCase();
  const wantsTheme = /\b(theme|appshell|app shell|dsa|dock|sheet|surface|screen|kiwe ui)\b/.test(text);
  const wantsWebsite = /\b(website|webpage|page|bricks|landing|homepage|site|news|store|shop|editorial)\b/.test(text);

  if (wantsTheme && wantsWebsite) return 'combined';
  if (wantsTheme) return 'theme';
  return 'website';
}

export function startProject({ mode = 'auto', brief = '', name = '' } = {}) {
  const normalized = inferMode(mode, brief);
  const title = safeName(name || brief || `${normalized}-kiwe-project`, `${normalized}-kiwe-project`);
  const humanBrief = String(brief || '').trim() || 'No human brief supplied.';

  const parts = [
    `# Kiwe project start: ${title}`,
    '',
    `Selected mode: ${normalized}`,
    '',
    '## Human brief',
    '',
    humanBrief,
    '',
    '## How to use this response',
    '',
    'Use this response as the authoritative assignment brief. The human should not need to prompt-engineer Kiwe details.',
    'Create the requested output using the Kiwe context below, then validate the handoff before final delivery.',
    '',
    'If you can write files, first scaffold the output with:',
    '',
    `kiwe_create_handoff({ "mode": "${normalized}", "outputDir": "./${title}", "name": "${title}", "brief": ${JSON.stringify(humanBrief)} })`,
    '',
    'If you only have CLI access, use:',
    '',
    `node kiwe-ai-toolkit/bin/kiwe.js create ${normalized} ./${title} --name ${title} --brief ${JSON.stringify(humanBrief)}`,
    '',
    'Then replace the scaffold content with the finished design while preserving the required folder/file contract.',
    '',
    getContext(normalized)
  ];

  return parts.filter(Boolean).join('\n').trim() + '\n';
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
  const previewHtml = `<!doctype html>
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
`;
  writeFile(path.join(root, 'website/preview/index.html'), previewHtml);
  writeFile(path.join(root, 'website/preview/assets/site.css'), `@import "../../../packs/website-builder/contracts/token-map.css";

/* Website/page CSS goes here. Use Seam Class Vocabulary names and Kiwe/Seam tokens. */
body {
  margin: 0;
  font-family: var(--kiwe-font-body, system-ui, sans-serif);
}
`);
  writeFile(path.join(root, 'website/bricks-paste.html'), `<!-- Kiwe Bricks paste-ready artifact.
Paste/import this through Bricks HTML-to-Bricks. Replace scaffold content with the finished page.
Do not require React/Vite/Tailwind build steps or generated Bricks IDs. -->
${previewHtml}`);
  writeFile(path.join(root, 'website/bricks-notes.md'), '# Bricks notes\n\nDescribe how `bricks-paste.html` should be pasted/imported through Bricks HTML-to-Bricks. Document preview-only behavior and Kiwe/WordPress/Woo/Bricks-owned interactions. Do not include generated Bricks IDs.\n');
}

function themeScaffold(root, name) {
  const id = safeName(name, 'kiwe-theme');
  writeFile(path.join(root, `appshell-theme/import/${id}/theme.json`), JSON.stringify({
    schema: 'kiwe.surface-theme.v1',
    id,
    name: id.replace(/-/g, ' '),
    version: '0.1.0',
    profile: 'marketplace',
    mode: 'css-only',
    description: 'Kiwe DSA AppShell theme generated from Kiwe AI Toolkit.',
    author: 'Kiwe AI Toolkit',
    css: ['css/theme.css'],
    assets: [],
    screens: ['profile', 'cart', 'search', 'menu', 'saved', 'links', 'notifications', 'ios-install', 'ai'],
    requires: {
      uiContract: 'kiwe.surface-ui.v2',
      tokenContract: 'kiwe.universal',
      minKiwe: '0.5.75'
    },
    supports: ['light', 'dark', 'sheet', 'classic', 'dock', 'split-dock', 'full-dock', 'navigation-bar', 'dock-shape-pill', 'dock-shape-box', 'dock-shape-square', 'horizontal', 'vertical', 'reduced-motion'],
    budgets: {
      cssKb: 40,
      jsKb: 0,
      blockingAssets: 0
    },
    forbidden: ['remote-code', 'trackers', 'php', 'service-worker', 'history-owner', 'cart-owner', 'checkout-owner', 'phonekey-owner', 'bricks-owner']
  }, null, 2) + '\n');
  writeFile(path.join(root, `appshell-theme/import/${id}/css/theme.css`), `/*
 * Kiwe DSA AppShell theme CSS.
 * Style existing DSA selectors only. Do not create runtime authority.
 */
`);
  writeFile(path.join(root, 'appshell-theme/README.md'), `# ${id} AppShell theme handoff

This folder must contain a safe importable theme package and a standalone preview.

Validate with:

\`\`\`bash
node tools/ui-theme/validate-package.cjs appshell-theme/import/${id}
node tools/ui-theme/validate-handoff.cjs appshell-theme
\`\`\`
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
    required.push('website/preview/index.html', 'website/preview/assets/site.css', 'website/bricks-paste.html', 'website/bricks-notes.md');
  }
  if (normalized === 'theme' || normalized === 'combined') {
    required.push('appshell-theme/README.md', 'appshell-theme/preview/index.html', 'appshell-theme/preview/PLACEHOLDERS.md');
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
