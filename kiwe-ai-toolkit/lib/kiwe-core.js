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
- \`screens\` must use Kiwe screen names only, and should match the brief/settings. Do not list cart/checkout/profile by default for a non-commerce or non-membership website just because those screens exist.
- For combined website/page + AppShell handoffs, a news/editorial default is usually \`search\`, \`menu\`, \`saved\`, \`links\`, \`notifications\`, \`ios-install\`, and \`ai\`. Add \`cart\`/\`checkout\` only for commerce, WooCommerce, shop, products, paid reports, subscriptions, or checkout. Add \`profile\` when account, membership, login, personalization, orders, downloads, or addresses are truly part of the brief.

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

function appShellPreviewQuickContract() {
  return `# AppShell preview quick contract

For theme-only mode, \`appshell-theme/preview/index.html\` must prove the theme against Kiwe's actual preview selectors and Geometry Engine states. A pretty mock phone is not enough.

For combined mode, \`combined-preview/index.html\` is the single primary visual proof. Put the website/page behind the Kiwe AppShell and put the AppShell variation controls there. A separate \`appshell-theme/preview/index.html\` is optional technical proof only.

Minimum preview shell requirements:

- Include a root with \`data-dsa-surface\`.
- Include \`data-dsa-ui-contract="2"\`.
- Include \`data-dsa-dock-presentation\` and demonstrate dock plus navigation bar values; use \`navbar\` for the navigation-bar runtime value.
- Include \`data-dsa-dock-orientation\`.
- Include Geometry Engine variables in the preview markup/style:
  - \`--dsa-dock-control-size\`
  - \`--dsa-dock-only-reserve\`
  - \`--dsa-screen-block-reserve\`
- If \`supports\` includes \`split-dock\`, include \`dsa-dock-split\`.
- If \`supports\` includes dock shapes, demonstrate:
  - \`dsa-dock-shape-pill\`
  - \`dsa-dock-shape-box\`
  - \`dsa-dock-shape-square\`
- If \`supports\` includes dark mode, include \`data-kiwe-theme="dark"\`.
- Link the importable CSS from \`../import/[theme-id]/css/theme.css\`; the preview must demonstrate the real import CSS.
- Keep preview controls outside the app viewport, preferably using \`kiwe-preview-toolbar\` and \`kiwe-preview-stage\`.
- Navigation bar is a separate presentation mode, not horizontal dock orientation. \`data-dsa-dock-presentation="navbar"\` is distinct from \`data-dsa-dock-presentation="dock"\` plus \`data-dsa-dock-orientation="horizontal"\`.
- Classic surface mode must prove the full app viewport unless the live Geometry Engine setting explicitly defines a narrower surface. Do not use a 390px side drawer as the only Classic proof.

Required screen selectors when the theme manifest lists these screens:

- \`profile\`: \`data-dsa-profile-panel\`
- \`cart\`: \`data-dsa-cart-panel\` and \`data-dsa-cart-fbt-rail\`
- \`checkout\`: \`data-dsa-checkout-panel\` and \`data-dsa-checkout-form\`
- \`search\`: \`data-dsa-search-panel\`, \`data-dsa-search-form\`, \`data-dsa-search-input\`, and \`data-dsa-search-results\`
- \`menu\`: \`dsa-menu-panel\`
- \`saved\`: \`data-dsa-saved-panel\`
- \`links\`: \`dsa-links-panel\`
- \`notifications\`: \`data-dsa-notification-panel\`
- \`ios-install\`: \`data-dsa-ios-install-panel\`
- \`games\`: \`data-dsa-game-panel\`
- \`ai\`: \`data-dsa-ai-panel\`

Cart FBT must be a horizontal rail. Include \`data-dsa-cart-fbt-rail\` on that rail. Do not render it as a stacked list.

Links site score is optional. The preview and README must show/document both:

- score present; and
- score absent/no score/without score, where no badge is rendered at all.

Combined website/page + AppShell previews must match the site type. A news/editorial website should not automatically show cart, checkout, orders, downloads, or addresses unless the brief/settings include commerce or membership. It is good to innovate with existing \`ai\` and \`notifications\` screens, but only as presentation over Kiwe-owned payloads/actions.

Responsive fit is mandatory. Prove Geometry Engine profiles for desktop, tablet, and mobile, then add narrow mobile stress widths around 320px, 360px, and 390px. No sheet/screen may create horizontal page or panel scroll except intentional rails such as FBT, alphabet/search filters, or another documented horizontal rail. Decorative header stripes, badges, labels, and pseudo-elements must shrink, wrap, clip inside the panel, or stack; do not use non-shrinking flex decorations that force the panel wider than the viewport.

The Geometry Engine owns AppShell placement and measurement. Importable theme CSS must not assign core geometry to dock, sheet, screen, or backdrop selectors. Do not set \`position: fixed\`, \`position: absolute\`, \`inset\`, \`top\`, \`right\`, \`bottom\`, \`left\`, hardcoded \`z-index\`, \`width: 100vw\`, \`height: 100vh\`, or hardcoded viewport offsets on \`[data-dsa-dock]\`, \`.dsa-dock\`, \`[data-dsa-screen]\`, \`.dsa-panel\`, \`.dsa-sheet\`, \`[data-dsa-screen-backdrop]\`, or sheet/screen backdrop selectors. Those values belong in Kiwe core or preview-only CSS. Theme CSS may style color, typography, border, radius, shadow, spacing inside content, icons, badges, cards, buttons, and state appearance while consuming Geometry Engine variables.

The AppShell handoff README must include:

- distinctness note / visual thesis;
- screen coverage summary;
- shell mode coverage summary;
- selector-fit checklist;
- intentional limitations;
- core/plugin changes section, including "no core/plugin changes" when true;
- Seam AppShell adoption map acknowledgement;
- validation commands.
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
    parts.push(appShellPreviewQuickContract());
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
  const pageHtml = `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwe Bricks-ready website page</title>
  <style>
    /* Kiwe website/page CSS goes here.
       This file is intentionally self-contained: open it in a browser for preview,
       then paste/import it through Bricks HTML-to-Bricks. */
    body {
      margin: 0;
      font-family: var(--kiwe-font-body, system-ui, sans-serif);
    }
  </style>
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
  writeFile(path.join(root, 'website/bricks-paste.html'), `<!-- Kiwe Bricks paste-ready artifact.
Open this same file in a browser for website/page preview.
Paste/import it through Bricks HTML-to-Bricks.
Replace scaffold content with the finished page.
Do not require React/Vite/Tailwind build steps, generated Bricks IDs, or hidden local files. -->
${pageHtml}`);
  writeFile(path.join(root, 'website/bricks-notes.md'), '# Bricks notes\n\n`bricks-paste.html` is the single website/page artifact: open it in a browser for preview, then paste/import the same file through Bricks HTML-to-Bricks. Document preview-only behavior and Kiwe/WordPress/Woo/Bricks-owned interactions. Do not include generated Bricks IDs.\n');
}

function themeScaffold(root, name, { includePreview = true } = {}) {
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
    screens: ['profile', 'cart', 'checkout', 'search', 'menu', 'saved', 'links', 'notifications', 'ios-install', 'games', 'ai'],
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

This folder must contain a safe importable theme package${includePreview ? ' and a standalone preview' : ''}.

Validate with:

\`\`\`bash
node tools/ui-theme/validate-package.cjs appshell-theme/import/${id}
${includePreview ? 'node tools/ui-theme/validate-handoff.cjs appshell-theme' : 'node kiwe-ai-toolkit/tools/validate-output.cjs . --mode combined'}
\`\`\`
`);
  if (includePreview) {
    writeFile(path.join(root, 'appshell-theme/preview/index.html'), '<!doctype html><html lang="en"><meta charset="utf-8"><title>Kiwe AppShell theme preview</title><body><p>Build standalone visual preview here. Link the import CSS.</p></body></html>\n');
    writeFile(path.join(root, 'appshell-theme/preview/PLACEHOLDERS.md'), '# Preview placeholders\n\nDocument mock products, account names, orders, links, scores, and AI data here. None of this belongs in the importable theme package.\n');
  }
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

function combinedPreviewScaffold(root, name, brief) {
  const id = safeName(name, 'kiwe-theme');
  writeFile(path.join(root, 'combined-preview/index.html'), `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwe combined preview</title>
  <link rel="stylesheet" href="../appshell-theme/import/${id}/css/theme.css">
  <link rel="stylesheet" href="./assets/combined-preview.css">
</head>
<body>
  <header class="kiwe-preview-toolbar" aria-label="Combined preview controls">
    <button type="button" data-kiwe-preview-set-device="desktop">Desktop 1280</button>
    <button type="button" data-kiwe-preview-set-device="tablet">Tablet 768</button>
    <button type="button" data-kiwe-preview-set-device="mobile">Mobile 390</button>
    <button type="button" data-kiwe-preview-set-device="narrow">Narrow 320</button>
    <button type="button" data-kiwe-preview-set-surface-mode="sheet">Sheet</button>
    <button type="button" data-kiwe-preview-set-surface-mode="classic">Classic</button>
    <button type="button" data-kiwe-preview-set-presentation="dock">Dock</button>
    <button type="button" data-kiwe-preview-set-presentation="split">Split dock</button>
    <button type="button" data-kiwe-preview-set-presentation="navbar">Navigation bar</button>
    <button type="button" data-kiwe-preview-set-shape="pill">Pill</button>
    <button type="button" data-kiwe-preview-set-shape="box">Rounded box</button>
    <button type="button" data-kiwe-preview-set-shape="square">Square</button>
    <span role="note">Navigation bar is a separate presentation mode, not horizontal dock.</span>
  </header>
  <main class="kiwe-preview-stage">
  <div class="kiwe-combined-preview kiwe-preview-viewport" data-kiwe-combined-preview data-device="desktop">
    <iframe class="kiwe-site-frame" title="Website/Bricks artifact preview" src="../website/bricks-paste.html"></iframe>
    <section
      class="dsa-surface dsa-dock-shape-pill"
      data-dsa-surface
      data-dsa-ui-contract="2"
      data-dsa-dock-presentation="dock"
      data-dsa-dock-orientation="horizontal"
      data-kiwe-theme="dark"
      style="--dsa-dock-control-size:48px;--dsa-dock-only-reserve:82px;--dsa-screen-block-reserve:104px;"
      aria-label="Kiwe DSA AppShell preview"
    >
      <article class="dsa-panel" data-dsa-search-panel>
        <h2>DSA sheet over page</h2>
        <p>Preview-only AppShell proof. Header buttons in the page iframe should open this sheet through data-dsa-open-module.</p>
        <form data-dsa-search-form><input data-dsa-search-input value="Preview" aria-label="Search preview"></form>
        <div data-dsa-search-results>Preview-only result area.</div>
      </article>
      <nav class="dsa-dock" aria-label="Preview dock">
        <button type="button" data-dsa-module="menu">Menu</button>
        <button type="button" data-dsa-module="search">Search</button>
        <button type="button" data-dsa-module="saved">Saved</button>
        <button type="button" data-dsa-module="ai">AI</button>
      </nav>
    </section>
  </div>
  </main>
  <script src="./assets/combined-preview.js"></script>
</body>
</html>
`);
  writeFile(path.join(root, 'combined-preview/assets/combined-preview.css'), `/* Combined preview only: show website/page and DSA AppShell together. */
body {
  margin: 0;
  min-height: 100vh;
  font-family: var(--kiwe-font-body, system-ui, sans-serif);
}

.kiwe-combined-preview {
  width: min(1280px, 100%);
  height: min(840px, calc(100vh - 76px));
  position: relative;
  overflow: hidden;
  margin: 0 auto;
  background: Canvas;
}

.kiwe-preview-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.75rem;
}

.kiwe-preview-stage {
  min-height: calc(100vh - 64px);
  display: grid;
  place-items: center;
}

.kiwe-site-frame {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
}

.kiwe-combined-preview[data-device="tablet"] {
  width: 768px;
  height: 920px;
}

.kiwe-combined-preview[data-device="mobile"] {
  width: 390px;
  height: 844px;
}

.kiwe-combined-preview[data-device="narrow"] {
  width: 320px;
  height: 700px;
}

.kiwe-combined-preview .dsa-surface {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.kiwe-combined-preview .dsa-panel,
.kiwe-combined-preview .dsa-dock {
  pointer-events: auto;
}

.kiwe-combined-preview [data-dsa-surface][data-kiwe-preview-surface-mode="classic"] .dsa-panel {
  inset: 0;
  width: auto;
  max-height: none;
}
`);
  writeFile(path.join(root, 'combined-preview/assets/combined-preview.js'), `// Preview-only combined controller. Production behavior remains Kiwe/WordPress/Woo/Bricks-owned.
document.documentElement.dataset.kiweCombinedPreview = '1';

const viewport = document.querySelector('[data-kiwe-combined-preview]');
const surface = document.querySelector('[data-dsa-surface]');
const frame = document.querySelector('.kiwe-site-frame');

document.addEventListener('click', (event) => {
  const device = event.target.closest('[data-kiwe-preview-set-device]');
  if (device && viewport) viewport.dataset.device = device.dataset.kiwePreviewSetDevice;

  const surfaceMode = event.target.closest('[data-kiwe-preview-set-surface-mode]');
  if (surfaceMode && surface) surface.dataset.kiwePreviewSurfaceMode = surfaceMode.dataset.kiwePreviewSetSurfaceMode;

  const shape = event.target.closest('[data-kiwe-preview-set-shape]');
  if (shape && surface) {
    surface.classList.remove('dsa-dock-shape-pill', 'dsa-dock-shape-box', 'dsa-dock-shape-square');
    surface.classList.add('dsa-dock-shape-' + shape.dataset.kiwePreviewSetShape);
  }

  const presentation = event.target.closest('[data-kiwe-preview-set-presentation]');
  if (presentation && surface) {
    const value = presentation.dataset.kiwePreviewSetPresentation;
    surface.dataset.dsaDockPresentation = value === 'navbar' ? 'navbar' : 'dock';
    surface.classList.toggle('dsa-dock-split', value === 'split');
  }
});

function bridgeFrameLaunchers() {
  if (!frame || !frame.contentDocument) return;
  frame.contentDocument.addEventListener('click', (event) => {
    const launcher = event.target.closest('[data-dsa-open-module]');
    if (!launcher) return;
    event.preventDefault();
    if (surface) surface.dataset.kiwePreviewActiveModule = launcher.dataset.dsaOpenModule;
  });
}

frame?.addEventListener('load', bridgeFrameLaunchers);
bridgeFrameLaunchers();
`);
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
  if (normalized === 'theme' || normalized === 'combined') themeScaffold(root, baseName, { includePreview: normalized === 'theme' });
  if (normalized === 'combined') settingsScaffold(root);
  if (normalized === 'combined') combinedPreviewScaffold(root, baseName, brief);

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
    required.push('website/bricks-paste.html', 'website/bricks-notes.md');
  }
  if (normalized === 'theme') {
    required.push('appshell-theme/README.md', 'appshell-theme/preview/index.html', 'appshell-theme/preview/PLACEHOLDERS.md');
  }
  if (normalized === 'combined') {
    required.push('appshell-theme/README.md');
  }
  if (normalized === 'combined') {
    required.push('combined-preview/index.html');
    if (fs.existsSync(path.join(root, 'kiwe-settings'))) {
      required.push('kiwe-settings/SETTINGS-NOTES.md');
    }
  }
  const missing = required.filter((rel) => !fs.existsSync(path.join(root, rel)));
  return {
    ok: missing.length === 0,
    mode: normalized,
    root,
    missing
  };
}
