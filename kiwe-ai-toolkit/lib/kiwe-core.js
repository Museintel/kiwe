import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { prepareApplyPlan as prepareBricksApplyPlan } from './apply-planner.js';
import { validateBindings as validateBindingsPlan } from './binding-validator.js';
import { validateBricksConversion as validateBricksConversionPlan } from './bricks-conversion-validator.js';
import { validateFrameworkProfile as validateFrameworkProfilePlan } from './framework-profile-validator.js';

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
    summary: 'Website/page, combined preview, and AppShell theme package. Theme settings travel inside theme-package.json.'
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
- Do not use \`schemaVersion\` in AppShell theme manifests. \`schemaVersion\` is only used by \`theme-package.json\` wrappers and other package/profile wrappers.
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

export function getDynamicContext() {
  const context = readMaybe('contexts/dynamic-lite.md');
  if (!context) {
    throw new Error('Dynamic binding context was not found.');
  }
  return context.trim() + '\n';
}

export function getBricksConversionContext() {
  const context = readMaybe('contexts/bricks-conversion-lite.md');
  if (!context) {
    throw new Error('Bricks conversion context was not found.');
  }
  return context.trim() + '\n';
}

export function getWorkflowContext() {
  const context = readMaybe('contexts/workflow-lite.md');
  if (!context) {
    throw new Error('Kiwe workflow context was not found.');
  }
  return context.trim() + '\n';
}

function frameworkProfileContext() {
  const schema = readMaybe('schemas/framework-profile.schema.json');
  return [
    '# Kiwe Framework / Bricks theme profile context',
    '',
    'Use this only for `/create /brickstheme`, `/create /frameworkprofile`, `/audit /brickstheme`, or `/audit /frameworkprofile` phases.',
    '',
    'A Framework profile is a sitewide design-token profile for `Kiwe > Framework` and safe Bricks global theme-style export. It is not a DSA AppShell theme package.',
    '',
    'Expected file:',
    '',
    '```text',
    'framework/kiwe-framework-profile.json',
    'framework/FRAMEWORK-NOTES.md',
    '```',
    '',
    schema ? '## JSON Schema\n\n```json\n' + schema.trim() + '\n```' : '',
    '',
    'If tools are available, validate with:',
    '',
    '```bash',
    'node kiwe-ai-toolkit/tools/validate-framework-profile.cjs /path/to/handoff-or-profile',
    '```'
  ].filter(Boolean).join('\n').trim() + '\n';
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

export function validateBindings(targetDir, options = {}) {
  return validateBindingsPlan(targetDir, options);
}

export function validateBricksConversion(targetDir, options = {}) {
  return validateBricksConversionPlan(targetDir, options);
}

export function validateFrameworkProfile(targetDir, options = {}) {
  return validateFrameworkProfilePlan(targetDir, options);
}

export function prepareApplyPlan(targetDir, options = {}) {
  return prepareBricksApplyPlan(targetDir, options);
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

function routeKind(command) {
  const text = String(command || '').trim().toLowerCase();
  if (!text) return 'workflow';
  if (/(\/ideate|\/creative|\/webdraft)/.test(text)) return 'ideate';
  if (/\/create/.test(text) && /\/preview/.test(text) && /(\/dsatheme|\/appshell|\/dsa|app shell)/.test(text)) return 'theme-preview-create';
  if (/\/create/.test(text) && /\/preview/.test(text) && /(\/combined|\/combine)/.test(text)) return 'combined-preview-create';
  if (/(\/build|\/create)/.test(text) && /(dsathemeandhomepage|theme and homepage|homepage and theme)/.test(text)) return 'combined-assemble';
  if (/\/audit/.test(text) && /(\/bricksconversion|\/bricks-conversion|bricks conversion|bricks json|bricksjson|html-to-bricks)/.test(text)) return 'bricks-audit';
  if (/(\/convert|\/export|\/translate|\/rebuild|\/adapt)/.test(text) && /(\/bricks|bricks json|bricks conversion|html-to-bricks|html css to bricks)/.test(text)) return 'bricks-convert';
  if (/(\/rebuild|\/convert|\/adapt)/.test(text) && /(\/seamframework|\/seam|seam framework)/.test(text)) return 'seam-rebuild';
  if (/\/audit/.test(text) && /(\/seamframework|\/seam|seam framework)/.test(text)) return 'seam-audit';
  if (/(\/create|\/build)/.test(text) && /(\/brickstheme|\/frameworkprofile|\/framework|bricks theme)/.test(text)) return 'framework-create';
  if (/\/audit/.test(text) && /(\/brickstheme|\/frameworkprofile|\/framework|bricks theme)/.test(text)) return 'framework-audit';
  if (/(\/create|\/build)/.test(text) && /(\/dsatheme|\/appshell|\/dsa|app shell)/.test(text)) return 'theme-create';
  if (/\/audit/.test(text) && /(\/dsatheme|\/appshell|\/dsa|app shell)/.test(text)) return 'theme-audit';
  if (/\/audit/.test(text) && /(\/combined|\/combine)/.test(text)) return 'combined-audit';
  if (/(\/assemble|\/combine|\/combined)/.test(text)) return 'combined-assemble';
  if (/(\/dynamic|\/sitegraph|\/binding|\/bindings)/.test(text)) return 'dynamic';
  if (/(\/apply|\/staging)/.test(text)) return 'staging';
  return 'workflow';
}

function wantsCompanion(command, explicit = false) {
  const text = String(command || '').trim().toLowerCase();
  return Boolean(explicit) || /(?:^|\s)\/usecompanion\b/.test(text) || /\buse\s+companion\b/.test(text);
}

function commandWithoutCompanion(command) {
  return String(command || '').replace(/(?:^|\s)\/usecompanion\b/gi, ' ').replace(/\s+/g, ' ').trim();
}

const KNOWN_COMMAND_TOKENS = new Set([
  '/adapt',
  '/apply',
  '/appshell',
  '/assemble',
  '/audit',
  '/binding',
  '/bindings',
  '/bricks',
  '/bricks-conversion',
  '/bricksconversion',
  '/brickstheme',
  '/build',
  '/combine',
  '/combined',
  '/convert',
  '/create',
  '/creative',
  '/dsa',
  '/dsatheme',
  '/dsathemeandhomepage',
  '/dynamic',
  '/export',
  '/framework',
  '/frameworkprofile',
  '/htmlcssjs',
  '/ideate',
  '/page',
  '/preview',
  '/rebuild',
  '/seam',
  '/seamframework',
  '/staging',
  '/theme',
  '/translate',
  '/usecompanion',
  '/webdraft',
  '/webpage',
  '/website'
]);

const TYPO_TOKEN_SUGGESTIONS = new Map([
  ['/buid', '/create'],
  ['/bild', '/create'],
  ['/bulid', '/create'],
  ['/buld', '/create'],
  ['/creat', '/create'],
  ['/crate', '/create'],
  ['/previe', '/preview'],
  ['/preveiw', '/preview'],
  ['/brick', '/bricks'],
  ['/brikcs', '/bricks'],
  ['/dsathem', '/dsatheme'],
  ['/seamframwork', '/seamframework']
]);

const VALID_PHASE_COMMANDS = [
  '/ideate /webdraft',
  '/rebuild /seamframework',
  '/audit /seamframework',
  '/create /brickstheme',
  '/audit /brickstheme',
  '/create /dsatheme',
  '/create /preview /dsatheme',
  '/audit /dsatheme',
  '/assemble /combined',
  '/create /preview /combined',
  '/audit /combined',
  '/dynamic /sitegraph',
  '/convert /bricks',
  '/audit /bricksconversion',
  '/apply /staging'
];

function slashTokens(text) {
  return Array.from(String(text || '').matchAll(/(?:^|\s)(\/[a-z0-9-]+)/gi), (match) => match[1].toLowerCase());
}

function commandHas(text, pattern) {
  return pattern.test(String(text || '').toLowerCase());
}

function hasPageArtifact(text) {
  return /website[\\/]bricks-paste\.html|bricks-paste\.html/i.test(String(text || ''));
}

function hasConversionArtifact(text) {
  return /bricks-conversion[\\/]kiwe-bricks-conversion\.json|kiwe-bricks-conversion\.json/i.test(String(text || ''));
}

function hasThemeArtifact(text) {
  return /appshell-theme|theme-package\.json|css[\\/]theme\.css|\btheme\.css\b|dsatheme|app\s*shell|appshell/i.test(String(text || ''));
}

function hasForbiddenBricksSource(text) {
  return /combined-preview|appshell-theme|theme-package\.json|css[\\/]theme\.css|\btheme\.css\b|data-dsa-surface|dsa[-\s]*(?:dock|sheet|screen|navbar)|appshell[-\s]*preview|app\s*shell[-\s]*preview/i.test(String(text || ''));
}

function commandDiagnostic({ status = 'ok', code = 'ok', message = '', kind = '', normalizedCommand = '', suggestions = [], boundaries = [] } = {}) {
  const stop = ['rejected', 'needs_input', 'noop'].includes(status);
  return {
    schema: 'kiwe.command-diagnostic.v1',
    status,
    stop,
    code,
    kind,
    normalizedCommand,
    message,
    suggestions,
    boundaries
  };
}

export function diagnoseCommand({ command = '', artifactSummary = '', siteGraphSummary = '' } = {}) {
  const raw = String(command || '').trim();
  const text = raw.toLowerCase();
  const commandCore = commandWithoutCompanion(raw);
  const normalizedCommand = commandCore.replace(/(?:^|\s)\/build\b/gi, ' /create').replace(/\s+/g, ' ').trim();
  const tokens = slashTokens(raw);
  const unknown = tokens.filter((token) => !KNOWN_COMMAND_TOKENS.has(token));

  if (!raw) {
    return commandDiagnostic({
      status: 'ok',
      code: 'workflow_default',
      kind: 'workflow',
      normalizedCommand: '',
      message: 'No slash command supplied; return the Kiwe workflow context.'
    });
  }

  if (unknown.length) {
    const suggestions = unknown.map((token) => TYPO_TOKEN_SUGGESTIONS.get(token) || '').filter(Boolean);
    return commandDiagnostic({
      status: 'rejected',
      code: 'unknown_command_token',
      normalizedCommand,
      message: `Unknown Kiwe command token${unknown.length > 1 ? 's' : ''}: ${unknown.join(', ')}. Do not guess or continue.`,
      suggestions: suggestions.length ? [...new Set(suggestions)] : VALID_PHASE_COMMANDS,
      boundaries: ['Use only registered Kiwe slash-command tokens.', 'If the human made a typo, ask them to resend the corrected command.']
    });
  }

  if (commandHas(text, /\/preview/) && !commandHas(text, /\/create/)) {
    return commandDiagnostic({
      status: 'rejected',
      code: 'preview_requires_create',
      normalizedCommand,
      message: 'Preview proof commands must use the canonical creation verb `/create`.',
      suggestions: ['/create /preview /dsatheme', '/create /preview /combined'],
      boundaries: ['Do not invent `/preview` as a standalone command.']
    });
  }

  if (commandHas(text, /\/create/) && commandHas(text, /\/preview/) && commandHas(text, /\/(?:brickstheme|frameworkprofile|framework|bricks)\b|bricks theme/)) {
    return commandDiagnostic({
      status: 'rejected',
      code: 'unsupported_preview_target',
      normalizedCommand,
      message: 'No `/create /preview /brickstheme` or Bricks-theme preview command exists. Framework/Bricks theme profiles are token JSON, not a separate preview lane.',
      suggestions: ['/create /brickstheme', '/audit /brickstheme', '/create /preview /dsatheme', '/create /preview /combined'],
      boundaries: ['Previews exist for the website/page HTML artifact, DSA AppShell theme proof, and combined page-plus-AppShell proof.', 'Framework profiles are validated, not previewed as their own UI.']
    });
  }

  if (commandHas(text, /\/create/) && commandHas(text, /\/preview/) && commandHas(text, /\/(?:website|webpage|page|htmlcssjs)\b/)) {
    const existing = hasPageArtifact(artifactSummary) || /\bhtml\b.*\bcss\b|\bindex\.html\b|creative draft|website draft/i.test(String(artifactSummary || ''));
    return commandDiagnostic({
      status: existing ? 'noop' : 'rejected',
      code: existing ? 'website_preview_already_exists' : 'website_preview_is_page_artifact',
      normalizedCommand,
      message: existing
        ? 'A website/page preview already exists in the supplied artifact. Do not regenerate the same preview; move to `/rebuild /seamframework`, `/audit /seamframework`, or `/convert /bricks` when appropriate.'
        : 'There is no separate Kiwe website preview command. A website/page preview is the HTML/CSS/JS page artifact itself, normally `website/bricks-paste.html` after the Seam rebuild.',
      suggestions: existing ? ['/rebuild /seamframework', '/audit /seamframework', '/dynamic /sitegraph', '/convert /bricks'] : ['/ideate /webdraft', '/rebuild /seamframework'],
      boundaries: ['Do not spend tokens recreating a preview that is already the artifact.']
    });
  }

  if (commandHas(text, /\/create/) && commandHas(text, /\/preview/) && !commandHas(text, /\/(?:dsatheme|appshell|dsa|combined|combine)\b|app shell/)) {
    return commandDiagnostic({
      status: 'rejected',
      code: 'missing_preview_target',
      normalizedCommand,
      message: 'Preview creation needs an explicit supported target.',
      suggestions: ['/create /preview /dsatheme', '/create /preview /combined'],
      boundaries: ['Supported preview-proof targets are DSA/AppShell theme and combined page-plus-AppShell only.']
    });
  }

  if (commandHas(text, /\/convert/) && commandHas(text, /\/bricks/) && hasForbiddenBricksSource(raw)) {
    return commandDiagnostic({
      status: 'rejected',
      code: 'bricks_convert_forbidden_source_in_command',
      kind: 'bricks-convert',
      normalizedCommand,
      message: '`/convert /bricks` cannot convert combined previews, AppShell themes, DSA screen/sheet/dock/navbar markup, theme packages, or theme CSS.',
      suggestions: ['/convert /bricks with source.html = website/bricks-paste.html', '/create /preview /dsatheme', '/create /preview /combined'],
      boundaries: ['Bricks conversion source is strictly `website/bricks-paste.html`.']
    });
  }

  if (commandHas(text, /\/convert/) && commandHas(text, /\/bricks/)) {
    const artifactText = String(artifactSummary || '');
    if (!hasPageArtifact(artifactText)) {
      return commandDiagnostic({
        status: hasThemeArtifact(artifactText) || hasForbiddenBricksSource(artifactText) ? 'rejected' : 'needs_input',
        code: hasThemeArtifact(artifactText) || hasForbiddenBricksSource(artifactText) ? 'bricks_convert_missing_page_source_with_theme_artifact' : 'bricks_convert_missing_page_source',
        kind: 'bricks-convert',
        normalizedCommand,
        message: hasThemeArtifact(artifactText) || hasForbiddenBricksSource(artifactText)
          ? 'The supplied artifact summary looks like an AppShell/theme/preview lane and does not include `website/bricks-paste.html`. Stop; do not convert DSA theme material into Bricks.'
          : '`/convert /bricks` needs the approved page artifact summary first: `website/bricks-paste.html`.',
        suggestions: ['/rebuild /seamframework to create website/bricks-paste.html', '/convert /bricks after website/bricks-paste.html exists'],
        boundaries: ['Do not guess a Bricks source from a DSA theme or combined preview.']
      });
    }
  }

  if (commandHas(text, /\/audit/) && commandHas(text, /\/(?:bricksconversion|bricks-conversion)\b|bricks conversion|bricks json|html-to-bricks/) && !hasConversionArtifact(artifactSummary)) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'bricks_audit_missing_conversion_artifact',
      kind: 'bricks-audit',
      normalizedCommand,
      message: '`/audit /bricksconversion` needs `bricks-conversion/kiwe-bricks-conversion.json`. Do not audit a non-existent conversion.',
      suggestions: ['/convert /bricks', '/audit /bricksconversion after kiwe-bricks-conversion.json exists'],
      boundaries: ['Audit phases inspect existing artifacts; they do not silently create missing outputs.']
    });
  }

  if (commandHas(text, /\/dynamic|\/sitegraph|\/binding|\/bindings/) && !String(siteGraphSummary || '').trim()) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'dynamic_missing_site_graph',
      kind: 'dynamic',
      normalizedCommand,
      message: '`/dynamic /sitegraph` needs a target Site Graph summary or API access. Do not guess product categories, pages, custom fields, dynamic tags, or Bricks query-loop types.',
      suggestions: ['GET /wp-json/dsa/v1/ai/site-graph', 'GET|POST /wp-json/dsa/v1/ai/site-graph-data', '/dynamic /sitegraph after Site Graph is available'],
      boundaries: ['Dynamic binding must be grounded in target-site truth, not frontend scraping or assumptions.']
    });
  }

  if (commandHas(text, /\/audit/) && commandHas(text, /\/(?:seamframework|seam|brickstheme|frameworkprofile|framework|dsatheme|appshell|dsa|combined|combine)\b|seam framework|bricks theme|app shell/) && !String(artifactSummary || '').trim()) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'audit_missing_artifact',
      normalizedCommand,
      message: 'Audit commands need an existing generated artifact or file map. Do not perform a generic audit against nothing.',
      suggestions: ['Provide the handoff folder/file map', 'Run the matching `/create` or `/rebuild` phase first'],
      boundaries: ['Audit phases inspect and revise concrete files; they do not invent missing artifacts.']
    });
  }

  if (commandHas(text, /\/apply|\/staging/) && !/confirm|authorized|staging site|staging confirmed|rollback|executor/i.test(`${raw}\n${artifactSummary}`)) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'staging_missing_explicit_authority',
      kind: 'staging',
      normalizedCommand,
      message: '`/apply /staging` needs explicit staging confirmation, mutation authorization, and controlled executor details. Stop before any write path.',
      suggestions: ['Use Kiwe controlled staging executor with explicit confirmation flags', 'Prepare/review apply plan first'],
      boundaries: ['No WordPress, Bricks, WooCommerce, cart, checkout, auth, or raw meta mutation without explicit staging authority.']
    });
  }

  return commandDiagnostic({
    status: 'ok',
    code: normalizedCommand !== commandCore ? 'legacy_alias_normalized' : 'ok',
    kind: routeKind(raw),
    normalizedCommand,
    message: normalizedCommand !== commandCore ? 'Legacy `/build` alias accepted internally; use `/create` in user-facing output.' : 'Command is recognized.'
  });
}

function commandDiagnosticResponse(diagnostic, command) {
  const suggestions = Array.isArray(diagnostic.suggestions) && diagnostic.suggestions.length
    ? diagnostic.suggestions.map((item) => `- ${item}`).join('\n')
    : '- Re-run with a valid Kiwe phase command.';
  const boundaries = Array.isArray(diagnostic.boundaries) && diagnostic.boundaries.length
    ? diagnostic.boundaries.map((item) => `- ${item}`).join('\n')
    : '- Stop this phase instead of guessing.';

  return [
    `# Kiwe command diagnostic: ${diagnostic.status}`,
    '',
    `Command: ${String(command || '(none)').trim() || '(none)'}`,
    `Code: ${diagnostic.code}`,
    diagnostic.normalizedCommand ? `Normalized command: ${diagnostic.normalizedCommand}` : '',
    '',
    '## What went wrong',
    '',
    diagnostic.message || 'The command cannot be executed as written.',
    '',
    '## Boundary',
    '',
    boundaries,
    '',
    '## What to do next',
    '',
    suggestions,
    '',
    'Do not continue into generation, conversion, audit, dynamic binding, or staging work until the command is corrected or the missing artifact/context is supplied.'
  ].filter(Boolean).join('\n').trim() + '\n';
}

function companionModeForKind(kind) {
  if (kind === 'bricks-convert') return 'dynamic';
  if (kind === 'bricks-audit') return 'audit';
  if (kind === 'dynamic') return 'dynamic';
  if (kind === 'staging') return 'staging';
  if (kind.includes('audit')) return 'audit';
  if (kind.includes('theme')) return 'theme';
  if (kind.includes('combined')) return 'combined';
  return 'website';
}

function companionAssistContext(kind, command) {
  const baseCommand = commandWithoutCompanion(command) || command;
  const mode = companionModeForKind(kind);
  const isAudit = kind.includes('audit');
  const lines = [
    '# Optional /usecompanion assist',
    '',
    '`/usecompanion` is a bounded assist flag, not a dependency and not a second creative author.',
    '',
    'If `KIWE_REST_BASE` and `KIWE_AI_KEY` are available and the target site has Companion enabled, make one short Companion attempt for this phase. If credentials are missing, the route fails, Companion is disabled, rate-limited, times out, returns unclear data, or the AI tool cannot call HTTP routes, continue with the same command without `/usecompanion` and report the fallback in `COMPANION-TRACE`.',
    '',
    'Do not retry repeatedly, do not browse the whole repository as a fallback, and do not ask Companion to write the whole output. Companion is a deterministic Kiwe contract oracle/context broker: compact cards, rule IDs, hashes, previous failure fingerprints, and safe next-action hints. It is intentionally not allowed to dump full plugin files line by line or spend native model tokens for this flag.',
    '',
    'Suggested bounded payload:',
    '',
    '```json',
    JSON.stringify({
      mode,
      phase: kind,
      command: baseCommand,
      sampleLimit: kind === 'dynamic' ? 8 : 4,
      brief: 'short human brief',
      artifactSummary: 'short previous artifact summary when available'
    }, null, 2),
    '```',
    ''
  ];

  if (isAudit) {
    lines.push(
      'Preferred Companion route for this audit phase:',
      '',
      '```text',
      'POST ${KIWE_REST_BASE}/ai/audit-companion/review',
      'Authorization: Bearer ${KIWE_AI_KEY}',
      '```',
      '',
      'Send the actual generated file map within the byte budget. Fix every `mustFix` item, then rerun once if practical. If the route cannot be used, perform the normal audit for this phase from the toolkit context.'
    );
  } else {
    lines.push(
      'Preferred Companion routes for this generation/rebuild/planning phase:',
      '',
      '```text',
      'GET|POST ${KIWE_REST_BASE}/ai/companion/context',
      'POST     ${KIWE_REST_BASE}/ai/companion/ask',
      '```',
      '',
      'Use the returned cards to sharpen the phase. Then execute the normal selected phase from this route. After output exists, `POST /ai/companion/review-output` or `/ai/audit-companion/review` may be used for a compact deterministic review.'
    );
  }

  lines.push(
    '',
    'Required `COMPANION-TRACE` when `/usecompanion` appears:',
    '',
    '- routes attempted;',
    '- whether each route succeeded, failed, or was skipped;',
    '- contextHash / siteGraphHash when supplied;',
    '- count of cards/findings used;',
    '- fallback reason, if any;',
    '- confirmation that Companion did not replace the selected Kiwe phase.'
  );

  return lines.join('\n').trim() + '\n';
}

export function routeCommand({ command = '', brief = '', artifactSummary = '', siteGraphSummary = '', useCompanion = false } = {}) {
  const diagnostic = diagnoseCommand({ command, artifactSummary, siteGraphSummary });
  if (diagnostic.stop) {
    return commandDiagnosticResponse(diagnostic, command);
  }
  const kind = diagnostic.kind || routeKind(command);
  const companionRequested = wantsCompanion(command, useCompanion);
  const humanBrief = String(brief || '').trim() || 'No human brief supplied.';
  const artifact = String(artifactSummary || '').trim() || 'No previous artifact summary supplied. Ask the human for the prior phase output if this command depends on one.';
  const graph = String(siteGraphSummary || '').trim() || 'No Site Graph summary supplied. Ask for target-site Site Graph before dynamic binding.';

  const parts = [
    `# Kiwe command route: ${kind}`,
    '',
    `Command: ${String(command || '(none)').trim() || '(none)'}`,
    '',
    '## Human brief',
    '',
    humanBrief,
    '',
    '## Previous artifact summary',
    '',
    artifact,
    '',
    '## Route rule',
    '',
    'Do only the selected phase. Do not silently expand into website + DSA + Bricks + dynamic + staging work.',
    '',
    '## Command gate',
    '',
    `Status: ${diagnostic.status}`,
    `Code: ${diagnostic.code}`,
    diagnostic.normalizedCommand ? `Canonical command: ${diagnostic.normalizedCommand}` : '',
    diagnostic.message || '',
    '',
    companionRequested ? companionAssistContext(kind, command) : '',
    getWorkflowContext()
  ];

  if (kind === 'ideate') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Create a pure creative HTML/CSS/JS draft. Do not use Kiwe, DSA, Seam, Bricks, WordPress, WooCommerce, Site Graph, or AppShell constraints unless the human independently requested them. This phase optimizes for visual invention.'
    );
  } else if (kind === 'seam-rebuild') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Rebuild the approved creative draft with Seam Framework while preserving the visual thesis.',
      '',
      getContext('website')
    );
  } else if (kind === 'seam-audit') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Audit the Seam rebuild and revise the actual files.',
      '',
      getContext('website'),
      '',
      readMaybe('contexts/audit-lite.md')
    );
  } else if (kind === 'framework-create') {
    parts.push(frameworkProfileContext());
  } else if (kind === 'framework-audit') {
    parts.push(frameworkProfileContext(), readMaybe('contexts/audit-lite.md'));
  } else if (kind === 'theme-create') {
    parts.push(getContext('theme'));
  } else if (kind === 'theme-preview-create') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Create or revise only the DSA AppShell theme preview lane: `appshell-theme/preview/index.html` and `appshell-theme/preview/PLACEHOLDERS.md`.',
      '',
      'The preview must prove the AppShell theme against live-like Kiwe DSA roots, screen/sheet internals, dock modes, Geometry Engine states, and installed `theme.css`. Do not create or convert a Bricks page in this phase.',
      '',
      getContext('theme')
    );
  } else if (kind === 'theme-audit') {
    parts.push(getContext('theme'), readMaybe('contexts/audit-lite.md'));
  } else if (kind === 'combined-preview-create') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Create or revise only the primary combined preview lane: `combined-preview/index.html` and optional `combined-preview/assets/*`.',
      '',
      'The preview must show the website/page behind the Kiwe AppShell with variation controls. It is not the Bricks import artifact and it must not be used as `/convert /bricks` source.',
      '',
      readMaybe('contexts/combined-lite.md')
    );
  } else if (kind === 'combined-assemble') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Assemble the approved website/page lane and approved DSA theme lane. Do not redesign from scratch unless the human explicitly asks.',
      '',
      readMaybe('contexts/combined-lite.md')
    );
  } else if (kind === 'combined-audit') {
    parts.push(readMaybe('contexts/combined-lite.md'), readMaybe('contexts/audit-lite.md'));
  } else if (kind === 'bricks-convert') {
    parts.push(
      '# Site Graph summary',
      '',
      graph,
      '',
      getBricksConversionContext()
    );
  } else if (kind === 'bricks-audit') {
    parts.push(
      '# Site Graph summary',
      '',
      graph,
      '',
      getBricksConversionContext(),
      '',
      readMaybe('contexts/audit-lite.md')
    );
  } else if (kind === 'dynamic') {
    parts.push(
      '# Site Graph summary',
      '',
      graph,
      '',
      getDynamicContext()
    );
  } else if (kind === 'staging') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Controlled staging apply is not a creative generation phase. Use it only with target Kiwe API access, explicit staging confirmation, explicit mutation authorization, and trusted executor routes. If those are missing, stop and ask for them.'
    );
  }

  return parts.filter(Boolean).join('\n').trim() + '\n';
}

export function startDynamicPass({ brief = '', siteGraphSummary = '', currentHandoffSummary = '' } = {}) {
  const humanBrief = String(brief || '').trim() || 'Revise the current Kiwe handoff into a WordPress/Bricks dynamic binding pass.';
  const graphSummary = String(siteGraphSummary || '').trim() || 'No Site Graph summary supplied. Ask for the target site kiwe.site-graph.v1 JSON before creating bindings.';
  const handoffSummary = String(currentHandoffSummary || '').trim() || 'No current handoff summary supplied. Inspect only the handoff files the human provides.';

  return [
    '# Kiwe dynamic binding pass',
    '',
    'Use this response after the website/page and optional AppShell theme already pass the normal Kiwe audit.',
    '',
    '## Human brief',
    '',
    humanBrief,
    '',
    '## Current handoff summary',
    '',
    handoffSummary,
    '',
    '## Site Graph summary',
    '',
    graphSummary,
    '',
    '## Required behavior',
    '',
    'Use the full target Site Graph JSON supplied by the human. Do not guess missing WordPress, WooCommerce, Bricks, or Kiwe details. Add a bricks-bindings/ folder with a binding plan and notes. Do not mutate WordPress or Bricks unless a trusted apply tool actually runs.',
    'After creating the binding plan, run validate-bindings when tools are available. If the human asks for an apply path, run prepare-apply-plan after validation; it is a dry-run plan, not a WordPress mutation.',
    '',
    getDynamicContext()
  ].join('\n').trim() + '\n';
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
  const manifest = {
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
  };
  const css = `/*
 * Kiwe DSA AppShell theme CSS.
 * Style existing DSA selectors only. Do not create runtime authority.
 */
`;
  const settings = {
    style: {
      active_theme_id: id,
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
    },
    screens: {
      profile: {
        label: 'Profile',
        eyebrow: 'Profile & Activity',
        title: 'Your account',
        ordersTitle: 'Orders',
        addressesTitle: 'Addresses',
        signOutLabel: 'Sign out'
      },
      cart: {
        label: 'Cart',
        eyebrow: 'Cart',
        title: 'Your cart',
        emptyTitle: 'Your cart is waiting.',
        emptyText: 'Add products to continue.',
        fbtTitle: 'Frequently Bought Together',
        checkoutLabel: 'Checkout',
        checkoutEmptyLabel: 'Empty'
      },
      search: {
        label: 'Search',
        title: 'Find what you need.',
        placeholder: 'Search products and posts'
      },
      links: {
        label: 'Links',
        title: 'Store links',
        shopLabel: 'Shop',
        cartLabel: 'Cart'
      },
      ai: {
        label: 'AI Assistant',
        title: 'Useful things, at the right moment.',
        chatPlaceholder: 'Chat with AI'
      }
    }
  };
  writeFile(path.join(root, `appshell-theme/import/${id}/theme.json`), JSON.stringify(manifest, null, 2) + '\n');
  writeFile(path.join(root, `appshell-theme/import/${id}/css/theme.css`), css);
  writeFile(path.join(root, `appshell-theme/import/${id}/theme-package.json`), JSON.stringify({
    type: 'kiwe-theme-package',
    schema: 'kiwe.theme-package.v1',
    schemaVersion: 1,
    theme: manifest,
    settings,
    css
  }, null, 2) + '\n');
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
  if (normalized === 'website' || normalized === 'combined') websiteScaffold(root, brief);
  if (normalized === 'theme' || normalized === 'combined') themeScaffold(root, baseName, { includePreview: normalized === 'theme' });
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
  const required = ['README.md'];
  if (normalized === 'website' || normalized === 'combined') {
    required.push('website/bricks-paste.html', 'website/bricks-notes.md');
  }
  if (normalized === 'theme') {
    required.push('appshell-theme/README.md', 'appshell-theme/preview/index.html', 'appshell-theme/preview/PLACEHOLDERS.md');
  }
  if (normalized === 'theme' || normalized === 'combined') {
    required.push('appshell-theme/README.md');
  }
  if (normalized === 'combined') {
    required.push('combined-preview/index.html');
  }
  if (normalized === 'theme' || normalized === 'combined') {
    const importRoot = path.join(root, 'appshell-theme', 'import');
    if (!fs.existsSync(importRoot)) {
      required.push('appshell-theme/import/<theme-id>/theme-package.json');
    } else {
      const themeDirs = fs.readdirSync(importRoot, { withFileTypes: true }).filter((entry) => entry.isDirectory());
      if (!themeDirs.length) {
        required.push('appshell-theme/import/<theme-id>/theme-package.json');
      }
      for (const entry of themeDirs) {
        required.push(
          `appshell-theme/import/${entry.name}/theme.json`,
          `appshell-theme/import/${entry.name}/css/theme.css`,
          `appshell-theme/import/${entry.name}/theme-package.json`
        );
      }
    }
  }
  const missing = required.filter((rel) => !fs.existsSync(path.join(root, rel)));
  const frameworkProfile = validateFrameworkProfilePlan(root, { optional: true });
  const frameworkErrors = frameworkProfile.ok ? [] : frameworkProfile.errors || [];
  return {
    ok: missing.length === 0 && frameworkErrors.length === 0,
    mode: normalized,
    root,
    missing,
    frameworkProfile
  };
}
