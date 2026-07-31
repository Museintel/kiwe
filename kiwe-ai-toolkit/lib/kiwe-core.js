import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { prepareApplyPlan as prepareBricksApplyPlan } from './apply-planner.js';
import { validateBindings as validateBindingsPlan } from './binding-validator.js';
import { validateBricksConversion as validateBricksConversionPlan } from './bricks-conversion-validator.js';
import { validateAccessibility as validateAccessibilityPlan } from './accessibility-validator.js';
import { validateFrameworkProfile as validateFrameworkProfilePlan } from './framework-profile-validator.js';
import { validateBricksThemeStyle as validateBricksThemeStylePlan } from './bricks-theme-style-validator.js';

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

function parseLength(value, label) {
  const match = String(value || '').trim().match(/^(-?(?:\d+|\d*\.\d+))(px|rem|em|ch|vw|vh|vmin|vmax)$/i);
  if (!match) {
    throw new Error(`${label} must be a simple CSS length such as 220px, 2.5rem, or -7px.`);
  }
  return {
    value: Number(match[1]),
    unit: match[2].toLowerCase()
  };
}

function roundCssNumber(value, precision = 4) {
  const rounded = Number(value.toFixed(Math.max(0, Math.min(8, precision))));
  return Object.is(rounded, -0) ? 0 : rounded;
}

function formatCssNumber(value, precision = 4) {
  return String(roundCssNumber(value, precision));
}

export function calculateFluidClamp(options = {}) {
  const min = parseLength(options.min ?? options.minValue, 'min');
  const max = parseLength(options.max ?? options.maxValue, 'max');
  const minViewport = Number(options.minViewport ?? options.minVw ?? 478);
  const maxViewport = Number(options.maxViewport ?? options.maxVw ?? 1440);
  const precision = Number.isInteger(options.precision) ? options.precision : 4;

  if (min.unit !== max.unit) {
    throw new Error('min and max must use the same CSS unit. Use a declared project token when units differ.');
  }
  if (!Number.isFinite(minViewport) || !Number.isFinite(maxViewport) || minViewport <= 0 || maxViewport <= 0 || minViewport === maxViewport) {
    throw new Error('minViewport and maxViewport must be positive, different viewport widths.');
  }
  if (min.value === max.value) {
    throw new Error('Refusing to generate clamp(v, v, v). Use an official token or declared project token for a stable value.');
  }

  const lowerViewport = Math.min(minViewport, maxViewport);
  const upperViewport = Math.max(minViewport, maxViewport);
  const lowerValue = minViewport <= maxViewport ? min.value : max.value;
  const upperValue = minViewport <= maxViewport ? max.value : min.value;
  const cssMin = Math.min(min.value, max.value);
  const cssMax = Math.max(min.value, max.value);
  const slope = ((upperValue - lowerValue) / (upperViewport - lowerViewport)) * 100;
  const intercept = lowerValue - (slope / 100) * lowerViewport;

  return {
    schema: 'kiwe.fluid-clamp.v1',
    input: {
      min: `${formatCssNumber(min.value, precision)}${min.unit}`,
      max: `${formatCssNumber(max.value, precision)}${max.unit}`,
      minViewport: lowerViewport,
      maxViewport: upperViewport
    },
    formula: 'clamp(minValue, calc(intercept + slope * 1vw), maxValue)',
    slope: roundCssNumber(slope, precision),
    intercept: roundCssNumber(intercept, precision),
    css: `clamp(${formatCssNumber(cssMin, precision)}${min.unit}, calc(${formatCssNumber(intercept, precision)}${min.unit} + ${formatCssNumber(slope, precision)}vw), ${formatCssNumber(cssMax, precision)}${min.unit})`,
    rules: [
      'Use official Kiwe/Seam universal tokens first.',
      'Use declared project tokens for stable art-direction constants.',
      'Use this real fluid clamp only for proven responsive interpolation.',
      'Never emit clamp(v, v, v).'
    ]
  };
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
    parts.push(readMaybe('contexts/seam-attributes-lite.md'));
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
  return [context, readMaybe('contexts/seam-attributes-lite.md')].filter(Boolean).join('\n\n').trim() + '\n';
}

export function getBricksConversionContext() {
  const context = readMaybe('contexts/bricks-conversion-lite.md');
  if (!context) {
    throw new Error('Bricks conversion context was not found.');
  }
  return [context, readMaybe('contexts/seam-attributes-lite.md')].filter(Boolean).join('\n\n').trim() + '\n';
}

export function getAccessibilityContext() {
  const context = readMaybe('contexts/accessibility-lite.md');
  if (!context) {
    throw new Error('Kiwe accessibility context was not found.');
  }
  return [
    context,
    readMaybe('contexts/seam-attributes-lite.md'),
    frameworkProfileContext(),
    bricksThemeStyleContext()
  ].filter(Boolean).join('\n\n').trim() + '\n';
}

export function getBricksThemeStyleContext() {
  return bricksThemeStyleContext();
}

export function getWorkflowContext() {
  const context = readMaybe('contexts/workflow-lite.md');
  if (!context) {
    throw new Error('Kiwe workflow context was not found.');
  }
  return context.trim() + '\n';
}

export function getCommandManifest() {
  const manifest = readMaybe('command-manifest.json');
  if (!manifest) {
    throw new Error('Kiwe command manifest was not found.');
  }
  return JSON.parse(manifest);
}

export function getStartEntrypoint() {
  const entry = readMaybe('entry.json');
  if (!entry) {
    throw new Error('Kiwe start entrypoint was not found.');
  }
  return JSON.parse(entry);
}

function auditClosureForArtifact(type, { wantsDsa = false } = {}) {
  const pairsByType = {
    'raw-html-css-js': [
      ['/audit /seamframework', '/fix /seamframework'],
      ['/audit /frameworkprofile', '/fix /frameworkprofile'],
      ['/audit /bricksconversion', '/fix /bricksconversion'],
      ['/audit /accessibility', '/fix /accessibility']
    ],
    'seam-page-artifact': [
      ['/audit /seamframework', '/fix /seamframework'],
      ['/audit /frameworkprofile', '/fix /frameworkprofile'],
      ['/audit /bricksconversion', '/fix /bricksconversion'],
      ['/audit /accessibility', '/fix /accessibility']
    ],
    'framework-profile': [
      ['/audit /frameworkprofile', '/fix /frameworkprofile']
    ],
    'bricks-template-upload': [
      ['/audit /bricksconversion', '/fix /bricksconversion'],
      ['/audit /accessibility', '/fix /accessibility']
    ],
    'bricks-conversion-envelope': [
      ['/audit /bricksconversion', '/fix /bricksconversion'],
      ['/audit /accessibility', '/fix /accessibility']
    ],
    'dsa-theme-package': [
      ['/audit /dsatheme', '/fix /dsatheme'],
      ['/audit /accessibility', '/fix /accessibility']
    ],
    'combined-handoff': [
      ['/audit /combined', '/fix /combined'],
      ['/audit /accessibility', '/fix /accessibility']
    ],
    unknown: []
  };

  const selected = [...(pairsByType[type] || pairsByType.unknown)];
  if (wantsDsa && (type === 'raw-html-css-js' || type === 'seam-page-artifact')) {
    selected.splice(2, 0, ['/audit /dsatheme', '/fix /dsatheme'], ['/audit /combined', '/fix /combined']);
  }

  return {
    completionRule: 'SeamFlow closes only when every required current-lane /audit returns PASS after any needed /fix loops.',
    startPoint: type,
    requiredAudits: selected.map(([audit]) => audit),
    matchingFixes: selected.map(([audit, fix]) => ({ audit, fix })),
    loop: [
      'Run the matching /audit command for the current lane.',
      'If audit fails, run the matching /fix command on the actual current artifact.',
      'Re-run the same /audit command.',
      'Repeat until PASS, or stop as NEEDS_INPUT if the same blocker repeats or required source/authority is missing.'
    ],
    notAllowed: [
      'Do not close from visual confidence alone.',
      'Do not skip /fix after a failed audit.',
      'Do not use stale audit reports or prior attempt notes as closure proof.'
    ]
  };
}

export function planFlow({ command = '', artifactSummary = '', desiredOutcome = '', useCompanion = false } = {}) {
  const text = [command, artifactSummary, desiredOutcome].join('\n').toLowerCase();
  const artifactTypes = [];
  const addType = (type, confidence, reason) => {
    if (!artifactTypes.some((item) => item.type === type)) artifactTypes.push({ type, confidence, reason });
  };

  if (/schema["']?\s*:\s*["']kiwe\.framework-profile\.v1|kiwe-framework-profile\.json|framework profile/.test(text)) {
    addType('framework-profile', 'high', 'Framework profile schema/path is present.');
  }
  if (/schema["']?\s*:\s*["']kiwe\.bricks-conversion\.v1|kiwe-bricks-conversion\.json|bricks conversion envelope/.test(text)) {
    addType('bricks-conversion-envelope', 'high', 'Bricks conversion envelope schema/path is present.');
  }
  if (/templatetype|template type|bricks-template|template-upload|top-level title|content\/header\/footer|native bricks template/.test(text)) {
    addType('bricks-template-upload', 'medium', 'Bricks template upload indicators are present.');
  }
  if (/theme-package\.json|appshell-theme\/import|css\/theme\.css|dsa theme package|appshell theme package/.test(text)) {
    addType('dsa-theme-package', 'high', 'DSA/AppShell theme package indicators are present.');
  }
  if (/combined-preview|combined handoff|appshell-theme.+website|website.+appshell-theme/.test(text)) {
    addType('combined-handoff', 'high', 'Combined preview/theme/website lanes are present.');
  }
  if (/bricks-paste\.html|website\/bricks-paste\.html|seam-[a-z0-9_-]+|data-role|data-flow|data-kiwe-|data-dsa-open-module/.test(text)) {
    addType('seam-page-artifact', 'medium', 'Seam/page artifact indicators are present.');
  }
  if (/<html|<!doctype html|\.html|<style|raw html|html\/css\/js|creative draft/.test(text) && !artifactTypes.some((item) => item.type === 'bricks-template-upload')) {
    addType('raw-html-css-js', 'medium', 'Standalone HTML/CSS/JS draft indicators are present.');
  }
  if (!artifactTypes.length) {
    addType('unknown', 'low', 'No known Kiwe artifact shape was provided.');
  }

  const wantsFull = /\/execute\s+\/fullflow|full[-\s]?flow|complete flow|all commands|through accessibility|final artifacts|end to end/.test(text);
  const wantsStep = /\/execute\s+\/stepbystep|step[-\s]?by[-\s]?step|one command|in parts|turns/.test(text);
  const wantsAuditEachStep = /\/audit\s+\/eachstep|audit at each step|audit each step|audit after each/.test(text);
  const wantsAuditAtEnd = /\/audit\s+\/atend|\/audit\s+\/at-end|audit at end|final audit/.test(text);
  const wantsDsa = /dsa|appshell|app shell|theme package|combined/.test(text);
  const wantsBricks = /bricks|template|builder|convert/.test(text);
  const hasCommand = /\/[a-z][a-z0-9_-]*(?:\s+\/[a-z][a-z0-9_-]*)*/i.test(String(command || ''));

  let recommendedMode = 'needs-human-choice';
  let recommendedNextCommands = [];
  const primary = artifactTypes[0]?.type || 'unknown';

  if (hasCommand) {
    recommendedMode = 'route-command';
    recommendedNextCommands = [String(command || '').trim()];
  } else if (primary === 'raw-html-css-js') {
    recommendedMode = wantsFull ? 'full-flow' : wantsStep ? 'step-by-step' : 'ask-flow-choice';
    recommendedNextCommands = [
      '/rebuild /seamframework',
      '/audit /seamframework',
      '/fix /seamframework if needed',
      '/create /frameworkprofile',
      '/audit /frameworkprofile',
      '/fix /frameworkprofile if needed',
      '/convert /bricks',
      '/audit /bricksconversion',
      '/fix /bricksconversion if needed',
      '/audit /accessibility',
      '/fix /accessibility if needed'
    ];
    if (wantsDsa) {
      recommendedNextCommands.splice(6, 0, '/create /dsatheme', '/audit /dsatheme if requested', '/assemble /combined');
    }
  } else if (primary === 'seam-page-artifact') {
    recommendedMode = wantsFull ? 'full-flow-from-seam' : 'ask-flow-choice';
    recommendedNextCommands = [
      '/audit /seamframework',
      '/fix /seamframework if needed',
      '/create /frameworkprofile',
      '/audit /frameworkprofile',
      '/convert /bricks',
      '/audit /bricksconversion',
      '/fix /bricksconversion if needed',
      '/audit /accessibility',
      '/fix /accessibility if needed'
    ];
  } else if (primary === 'bricks-template-upload' || primary === 'bricks-conversion-envelope') {
    recommendedMode = 'audit-existing-bricks';
    recommendedNextCommands = [
      '/audit /bricksconversion',
      '/fix /bricksconversion if needed',
      '/audit /accessibility',
      '/fix /accessibility if needed'
    ];
  } else if (primary === 'framework-profile') {
    recommendedMode = 'audit-framework-profile';
    recommendedNextCommands = [
      '/audit /frameworkprofile',
      '/fix /frameworkprofile if needed'
    ];
  } else if (primary === 'dsa-theme-package') {
    recommendedMode = 'audit-dsa-theme';
    recommendedNextCommands = [
      '/audit /dsatheme',
      '/fix /dsatheme if needed',
      '/audit /accessibility',
      '/fix /accessibility if needed'
    ];
  } else if (primary === 'combined-handoff') {
    recommendedMode = 'audit-combined';
    recommendedNextCommands = [
      '/audit /combined',
      '/fix /combined if needed',
      '/audit /accessibility',
      '/fix /accessibility if needed'
    ];
  } else if (primary === 'unknown') {
    recommendedMode = 'entry-orientation';
    recommendedNextCommands = ['/list'];
  }

  const auditClosure = auditClosureForArtifact(primary, { wantsDsa });

  const questions = [];
  if (!hasCommand) {
    if (primary === 'unknown') {
      questions.push('What do you want to create, rebuild, audit, fix, convert, or apply?');
    } else {
      questions.push('Choose `/execute /stepbystep`, `/execute /fullflow`, or a specific `/command`. Optional flags: `/audit /eachstep`, `/audit /atend`, `/usecompanion`.');
      if (primary === 'bricks-template-upload') {
        questions.push('Should I audit the existing Bricks template as-is, or should we return to the source HTML for a stricter Seam rebuild first?');
      }
      if (!wantsBricks && (primary === 'raw-html-css-js' || primary === 'seam-page-artifact')) {
        questions.push('Is the target website/page-only Bricks output, DSA theme, or combined Appsite output?');
      }
    }
  }

  return {
    schema: 'kiwe.seamflow-plan.v1',
    compatibilitySchema: 'kiwe.flow-plan.v1',
    productName: 'SeamFlow',
    flowName: 'seamflow',
    contractVersion: '6.83',
    purpose: 'Plan the smallest safe SeamFlow command path for website/page, header, footer, template, Framework profile, Bricks conversion, DSA theme, combined handoff, and accessibility flows.',
    architecture: {
      seamflow: 'External AI command-central flow for browser AI, IDE AI, MCP clients, and skill-capable agents.',
      kiweInternalAi: 'Bounded Kiwe Companion/Internal AI. It can assist through /usecompanion, API, or MCP routes, but SeamFlow must still work in browser-only mode.',
      currentLaunchScope: 'Close Seam Framework plus Bricks-powered webpages, headers, footers, reusable templates, Framework profiles, dynamic intent, and accessibility first.',
      nextPhase: 'DSA/AppShell theme remains mapped but full DSA theme production hardening follows after page-builder flow testing passes.'
    },
    status: hasCommand ? 'route' : 'needs_input',
    commandDetected: hasCommand,
    artifactTypes,
    recommendedMode,
    recommendedNextCommands,
    executionOptions: {
      stepByStep: '/execute /stepbystep',
      fullFlow: '/execute /fullflow',
      auditCadence: wantsAuditEachStep ? '/audit /eachstep' : wantsAuditAtEnd ? '/audit /atend' : 'ask-or-default-/audit-/eachstep-for-production',
      companion: wantsCompanion({ command, explicit: useCompanion }) ? '/usecompanion' : 'optional',
      closureMode: wantsAuditEachStep ? 'audit-fix-repeat-after-each-phase' : wantsAuditAtEnd ? 'audit-fix-repeat-before-final-delivery' : 'ask'
    },
    auditClosure,
    startResponse: {
      mustReport: 'SeamFlow contract: 6.83',
      order: [
        'STATUS',
        'SeamFlow contract',
        'Attachments detected',
        'Artifact diagnostic',
        'Recommended next command',
        'Question with /execute command choices and optional flags',
        'Commands: use /list for the compact command list'
      ],
      includeCompactList: false,
      includeCommandListHint: !hasCommand,
      commandListHint: 'Commands: use /list for the compact command list',
      doNotDumpCommandListUnless: '/list',
      includeAttachmentDiagnostic: !hasCommand,
      waitsForApprovalBefore: ['audit', 'fix', 'convert', 'create', 'assemble', 'live-api', 'companion-review'],
      permissionPolicy: 'Classification is read-only and allowed. Audits, fixes, conversion, creation, live API calls, and Companion review require an explicit /command or human approval.'
    },
    questions,
    capabilityCheck: {
      mcpPreferred: true,
      firstToolIfAvailable: 'kiwe_get_start',
      flowPlannerTool: 'kiwe_seamflow_plan',
      compatibilityFlowPlannerTool: 'kiwe_plan_flow',
      sequence: ['kiwe_get_start', 'kiwe_get_command_manifest', 'kiwe_seamflow_plan', 'kiwe_diagnose_command', 'kiwe_route_command', 'lane validator'],
      nonBlockingFallback: 'Use raw KIWE-START.md / entry.json / command-manifest.json when MCP/tools are unavailable.',
      askToConnectWhen: 'Ask once only when the human wants live Site Graph/API use, Companion review, or direct validator/tool execution and no Kiwe MCP/tool is available. Full-flow itself must still work through raw files when tools are unavailable.',
      companionHandshake: 'If /usecompanion is selected and no Kiwe MCP/tool is connected, ask for KIWE_REST_BASE and KIWE_AI_KEY. First call a bounded Companion status/context route. On success report COMPANION: connected with compact route/hash proof. On failure report COMPANION: fallback and continue without blocking.'
    },
    boundaries: [
      'Do not crawl the repository.',
      'Do not use general web search, arXiv, unrelated GitHub search, stale local examples, or prior accepted notes to complete SeamFlow.',
      'Do not mark artifact lanes PASS from manual confidence, copied/reconstructed validator logic, simulated validator logic, or equivalent checks. PASS requires executable validator proof; report WARN or UNVERIFIED instead.',
      'Do not use prior Kiwe validation material, old National Chikki/BioVantage attempts, previous browser-AI outputs, local downloads, search results, or accepted notes unless the human supplied those exact files in the current turn or explicitly requested comparison.',
      'Do not create documentation unless /document is present.',
      'Do not convert DSA/AppShell theme files through /convert /bricks.',
      'Do not create DSA/AppShell or combined output during page-builder full-flow unless the target explicitly requests DSA theme or combined mode.',
      'Do not treat missing Site Graph as a blocker for static Bricks conversion; preserve dynamic intent/manual-review markers and suggest /usesitegraph.',
      '/execute /fullflow is sequential internally: read and close one phase at a time, then return only final canonical artifacts.',
      'Do not claim a pass without running or following the matching lane audit.',
      'Stop at the first blocking failure that cannot be fixed from supplied artifacts.',
      'A SeamFlow phase, step-by-step run, full-flow run, or mid-stream resumed flow is complete only after every required current-lane audit returns PASS.'
    ]
  };
}

export function getSeamAttributesContext() {
  const context = readMaybe('contexts/seam-attributes-lite.md');
  if (!context) {
    throw new Error('Seam capability attributes context was not found.');
  }
  return context.trim() + '\n';
}

export function listCommands() {
  return {
    schema: 'kiwe.command-list.v1',
    productName: 'SeamFlow',
    flowName: 'seamflow',
    canonicalVerb: '/create',
    terminalEntry: {
      pattern: 'read https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-START.md\\n/list',
      meaning: 'Read SeamFlow Start first. It confirms the SeamFlow contract version, points to the machine-readable manifest, routes the next slash command, and forbids repository crawling. KIWE-START.md is the compatibility URL.'
    },
    aliases: {
      '/build': 'Legacy alias accepted internally; user-facing output should say /create.',
      '/dynamic /sitegraph': 'Legacy alias for /usesitegraph.',
      '/sitegraph': 'Legacy shorthand for /usesitegraph when used as a workflow phase.',
      '/usesitegraph /replacepreview': 'Legacy shorthand for /usesitegraph /replacepreviewdata.'
    },
    commands: [
      {
        command: '/list',
        purpose: 'List the supported Kiwe workflow commands without starting generation.',
        requires: [],
        output: 'command list only'
      },
      {
        command: '/execute /stepbystep',
        purpose: 'Run only the next safe SeamFlow phase for the current artifact, return that artifact, and stop for the next user command.',
        requires: ['classified current artifact or explicit current command path'],
        output: 'next phase artifact only'
      },
      {
        command: '/execute /fullflow',
        purpose: 'Run the complete approved SeamFlow path to final artifacts with compact pass/fail status.',
        requires: ['classified current artifact and human approval for full-flow execution'],
        output: 'final canonical artifacts only'
      },
      {
        command: '/audit /allattached',
        purpose: 'Second-pass audit: classify all attached/current files and run every matching lane audit without rebuilding or redesigning.',
        requires: ['one or more current attached/supplied artifacts'],
        output: 'compact pass/fail per detected artifact lane'
      },
      {
        command: '/fix /allattached',
        purpose: 'Second-pass repair: fix every failed attached/current lane, then rerun matching audits until PASS or NEEDS_INPUT.',
        requires: ['current attached/supplied artifacts and failed audit findings or rerunnable validators'],
        output: 'corrected current artifact files only'
      },
      {
        command: '/audit /allflow',
        purpose: 'Audit every closure lane required by the detected SeamFlow start point/current stage.',
        requires: ['classified current artifact or file map'],
        output: 'compact pass/fail across required closure audits'
      },
      {
        command: '/fix /allflow',
        purpose: 'Repair failed lanes across the detected current flow, then rerun every required closure audit until PASS or NEEDS_INPUT.',
        requires: ['classified current artifact/file map and failed audit findings or rerunnable validators'],
        output: 'corrected canonical artifacts for the detected current flow'
      },
      {
        command: '/audit /previousoutput',
        purpose: 'Audit the files generated in the immediate previous AI output in this same session.',
        requires: ['immediate previous AI output files accessible in the current session'],
        output: 'compact pass/fail per detected artifact lane in previous output'
      },
      {
        command: '/fix /previousoutput',
        purpose: 'Fix the files generated in the immediate previous AI output in this same session and rerun matching audits.',
        requires: ['immediate previous AI output files accessible in the current session'],
        output: 'corrected files for the previous output only'
      },
      {
        command: '/audit /allattached /allflow',
        purpose: 'Classify all current files and run every matching lane audit plus every closure audit required by the detected current flow.',
        requires: ['current attached/supplied artifacts or file map'],
        output: 'compact pass/fail for every detected artifact lane and required closure audit'
      },
      {
        command: '/fix /previousaudit',
        purpose: 'Fix only the failures from the immediately previous audit result, then rerun that same audit scope.',
        requires: ['immediately previous audit findings and current artifact files'],
        output: 'corrected files for the previously audited failed lanes only'
      },
      {
        command: '/fix',
        purpose: 'Repair an existing generated artifact in place after a failed audit or wrong output shape.',
        requires: ['existing artifact folder/file map'],
        output: 'revised existing files; no unrelated new package'
      },
      {
        command: '/document',
        purpose: 'Create compact lane-specific notes only after an artifact exists. Documentation is opt-in for every lane.',
        requires: ['existing artifact folder/file map'],
        output: 'documentation files only; no artifact redesign'
      },
      {
        command: '/ideate /webdraft',
        purpose: 'Create a pure creative HTML/CSS/JS draft before Kiwe constraints are introduced.',
        requires: ['plain design brief'],
        output: 'creative draft only'
      },
      {
        command: '/rebuild /seamframework',
        purpose: 'Rebuild an approved creative draft with Seam Framework, Kiwe tokens, and capability attributes.',
        requires: ['approved HTML/CSS/JS draft'],
        output: 'website/bricks-paste.html only; add /document if notes are wanted'
      },
      {
        command: '/audit /seamframework',
        purpose: 'Audit a Seam rebuild with executable validator proof. Does not revise unless paired with /fix.',
        requires: ['website/bricks-paste.html'],
        output: 'PASS/FAIL/WARN findings only'
      },
      {
        command: '/fix /seamframework',
        purpose: 'Repair only the failed Seam Framework page lane, then rerun the Seam validator until PASS or NEEDS_INPUT.',
        requires: ['website/bricks-paste.html and failed /audit /seamframework findings'],
        output: 'website/bricks-paste.html only'
      },
      {
        command: '/create /frameworkprofile',
        purpose: 'Create the Kiwe > Framework import profile. Admin imports this file in Kiwe > Framework, then pushes variables, colors, classes, and Bricks theme-style data from there.',
        requires: ['approved visual direction'],
        output: 'framework/kiwe-framework-profile.json only; add /document if notes are wanted'
      },
      {
        command: '/audit /frameworkprofile',
        purpose: 'Validate the Kiwe > Framework import profile against Kiwe token and safe Bricks global-style rules.',
        requires: ['framework/kiwe-framework-profile.json'],
        output: 'PASS/FAIL/WARN findings only'
      },
      {
        command: '/create /brickstheme',
        purpose: 'Create one native Bricks Theme Styles JSON import file. This is not a Kiwe Framework profile, not Bricks template JSON, and not a DSA theme.',
        requires: ['approved visual direction or framework profile intent'],
        output: 'bricks-theme-style.json only; add /document if notes are wanted'
      },
      {
        command: '/audit /brickstheme',
        purpose: 'Validate and revise the native Bricks Theme Styles JSON file only.',
        requires: ['bricks-theme-style.json'],
        output: 'same Bricks theme-style JSON, corrected'
      },
      {
        command: '/create /dsatheme',
        purpose: 'Create a Kiwe DSA/AppShell theme package. This styles Kiwe runtime chrome; it is not Bricks page content.',
        requires: ['theme brief or approved website visual thesis'],
        output: 'appshell-theme/import/[theme-id]/theme-package.json, theme.json, css/theme.css, README'
      },
      {
        command: '/create /preview /dsatheme',
        purpose: 'Create only the DSA theme technical preview lane.',
        requires: ['existing or in-progress AppShell theme package'],
        output: 'appshell-theme/preview/index.html and PLACEHOLDERS.md'
      },
      {
        command: '/audit /dsatheme',
        purpose: 'Audit and revise a DSA/AppShell theme package and optional preview.',
        requires: ['appshell-theme/import/[theme-id]/theme-package.json'],
        output: 'same AppShell theme lane, corrected'
      },
      {
        command: '/assemble /combined',
        purpose: 'Assemble approved website and AppShell theme lanes into one combined handoff.',
        requires: ['approved website lane', 'approved AppShell theme lane'],
        output: 'combined handoff with one combined preview'
      },
      {
        command: '/create /preview /combined',
        purpose: 'Create only the combined page-plus-AppShell preview.',
        requires: ['website/bricks-paste.html', 'appshell-theme/import/[theme-id]/css/theme.css'],
        output: 'combined-preview/index.html and optional assets'
      },
      {
        command: '/audit /combined',
        purpose: 'Audit website, AppShell theme, and combined preview lanes together.',
        requires: ['combined handoff files'],
        output: 'same combined handoff, corrected'
      },
      {
        command: '/usesitegraph',
        purpose: 'Use real target-site Site Graph/API facts for identity, preview samples, dynamic bindings, Bricks context, and query-loop intent.',
        requires: ['KIWE_REST_BASE plus key, or exported kiwe.site-graph.v1 JSON, or public Site Graph Data route'],
        output: 'bricks-bindings/kiwe-bindings.json when binding intent changes; add /document if notes are wanted'
      },
      {
        command: '/usesitegraph /replacepreviewdata',
        aliases: ['/usesitegraph /replacepreview'],
        purpose: 'Replace preview-only sample content with real Site Graph samples while keeping production/import artifacts dynamic.',
        requires: ['existing handoff', 'Site Graph Data/API access'],
        output: 'preview samples updated; production dynamic intent preserved'
      },
      {
        command: '/usesitegraph /websitename',
        purpose: 'Use Site Graph identity/name/logo/tone facts instead of scraping or guessing the brand.',
        requires: ['Site Graph site identity data'],
        output: 'identity-aware handoff revisions'
      },
      {
        command: '/usesitegraph /nonai',
        purpose: 'Force the AI-less/read-only Site Graph Data lane. Do not call Companion/native AI routes.',
        requires: ['public or authenticated Site Graph Data API/export'],
        output: 'read-only data-grounded revision'
      },
      {
        command: '/convert /bricks',
        purpose: 'Convert only website/bricks-paste.html into one token-pure native Bricks My Templates upload JSON for a page body, header, footer, or reusable section/template, with optional embedded Kiwe fidelity metadata.',
        requires: ['website/bricks-paste.html', 'framework/kiwe-framework-profile.json or confirmed Kiwe > Framework/Bricks theme-style already pushed', 'optional bricks-bindings/kiwe-bindings.json'],
        output: 'bricks-template/[page-or-template-name]-template-upload.json only by default; target templateType must be content/header/footer/section; add /document if notes or an external audit envelope are wanted'
      },
      {
        command: '/audit /bricksconversion',
        purpose: 'Audit and revise the canonical Bricks conversion package, including native Bricks token purity.',
        requires: ['bricks-template/[page-name]-template-upload.json or bricks-conversion/kiwe-bricks-conversion.json'],
        output: 'same Bricks artifact lane, corrected; no notes unless /document was requested'
      },
      {
        command: '/create /accessibility',
        aliases: ['/create /a11y'],
        purpose: 'Create a light/dark accessibility plan for an existing website/page, DSA theme, combined handoff, Framework profile, or Bricks conversion.',
        requires: ['existing artifact folder/file map or approved visual output'],
        output: 'accessibility/kiwe-accessibility-plan.json only; add /document if notes are wanted'
      },
      {
        command: '/audit /accessibility',
        aliases: ['/audit /a11y'],
        purpose: 'Audit and revise color contrast, token pairing, native dark-mode proof, Bricks/Kiwe theme-token alignment, and visible text containment.',
        requires: ['existing artifact files and optional accessibility/kiwe-accessibility-plan.json'],
        output: 'same artifact plus corrected accessibility lane'
      },
      {
        command: '/fix /accessibility',
        aliases: ['/fix /a11y'],
        purpose: 'Repair only the failed accessibility lane: contrast, dark/light token proof, Bricks theme-token alignment, and critical text clipping.',
        requires: ['existing artifact files and accessibility findings'],
        output: 'same artifact file(s) plus accessibility/kiwe-accessibility-plan.json; no docs unless /document was requested'
      },
      {
        command: '/apply /staging',
        purpose: 'Use only the controlled staging executor after explicit staging and mutation authorization.',
        requires: ['explicit staging confirmation', 'controlled executor details', 'rollback plan'],
        output: 'staging execution report, never silent production mutation'
      }
    ],
    flags: [
      {
        flag: '/usecompanion',
        purpose: 'Optional bounded Kiwe Companion assist. If unavailable, continue without it and report fallback.'
      },
      {
        flag: '/audit /eachstep',
        purpose: 'Audit and fix after each phase before continuing. Recommended for production/importable files.'
      },
      {
        flag: '/audit /atend',
        purpose: 'Run creation/conversion first, then audit/fix before delivery. Useful for quick exploratory drafts.'
      }
    ],
    utilities: [
      {
        command: 'kiwe fluid-clamp --min 220px --max 390px --min-vw 478 --max-vw 1440',
        purpose: 'Deterministically calculate a real responsive clamp for proven min/max responsive design states. Refuses clamp(v, v, v). Use inside /convert /bricks or /rebuild /seamframework when no official universal token fits and a declared project token is not the right shape.'
      }
    ]
  };
}

function frameworkProfileContext() {
  const schema = readMaybe('schemas/framework-profile.schema.json');
  return [
    '# Kiwe Framework / Bricks theme profile context',
    '',
    'Use this only for `/create /frameworkprofile` or `/audit /frameworkprofile` phases.',
    '',
    'A Framework profile is the sitewide design-token import for `Kiwe > Framework`. After import, the admin can push variables, colors, global classes, and Bricks theme-style data to Bricks from Kiwe. It is not a DSA AppShell theme package, not a Bricks template, and not the standalone `/brickstheme` file.',
    '',
    '`settings.tokens.bricks_theme_style` must be complete for `/frameworkprofile`: `enabled: true`, a safe `id`, a human `label`, and only safe global style slots such as site background, palette, fonts, heading scale, links, radius, shadow, and spacing. Kiwe normalizes those slots into universal tokens and generates the native Bricks Theme Style during the Kiwe > Framework push.',
    '',
    'Do not make the human spoon-feed missing CSS variables. `/audit /frameworkprofile` must independently verify that the profile covers the core live token foundation through `settings.tokens.overrides` or mapped `bricks_theme_style` slots: `color-brand`, `color-accent`, `color-surface`, `color-surface-raised`, `color-text`, `color-text-muted`, `color-border`, `font-display`, `font-body`, `type-h1`, `type-body`, `space-md`, `radius-lg`, and `shadow-md`. These map to official CSS variables such as `--kiwe-color-brand`, `--kiwe-color-accent`, `--kiwe-color-surface`, `--kiwe-font-body`, and `--kiwe-space-md`; do not invent non-canonical variables like `--kiwe-color-primary`.',
    '',
    'Kiwe universal tokens carry behavior roles: `fluid-scale`, `fixed-primitive`, `geometry-input`, `content-limit`, `responsive-guard`, `semantic-token`, `alias`, `layer-token`, and `project-token`. Plain token values are valid only at the named token definition layer for roles such as fixed primitive, geometry input, content limit, and responsive guard. Page CSS and Bricks JSON must consume those tokens instead of copying the raw values.',
    '',
    'Never treat `/audit /frameworkprofile` as an alias of `/audit /brickstheme`. The standalone `/brickstheme` file is a native Bricks Theme Styles JSON artifact; a Framework profile is a Kiwe import artifact with `settings.tokens`. In Framework profiles, safe `bricks_theme_style` global slots such as `siteBackground`, `colorPrimary`, `colorSecondary`, `colorLight`, `colorDark`, `colorMuted`, `fontDisplay`, `fontBody`, `typeBody`, `spaceMd`, `radiusLg`, and `shadowMd` are valid and must not be stripped.',
    '',
    'Expected file:',
    '',
    '```text',
    'framework/kiwe-framework-profile.json',
    '```',
    '',
    'Do not emit `framework/FRAMEWORK-NOTES.md`, README files, reports, Bricks templates, or AppShell theme packages unless the command also includes `/document` or the human explicitly asks for documentation.',
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

function bricksThemeStyleContext() {
  const schema = readMaybe('schemas/bricks-theme-style.schema.json');
  return [
    '# Native Bricks Theme Styles context',
    '',
    'Use this only for `/create /brickstheme` or `/audit /brickstheme` phases.',
    '',
    '`/brickstheme` outputs exactly one native Bricks Theme Styles JSON import file. It is for Bricks\' front-end visual editor Theme Styles manager. It is not a Kiwe Framework profile, not Bricks template/page JSON, and not a DSA/AppShell theme package.',
    '',
    'Expected file:',
    '',
    '```text',
    'bricks-theme-style.json',
    '```',
    '',
    'Root shape, verified from Bricks Theme Styles behavior. `id` is optional for Bricks-export compatibility; if omitted, Bricks/Kiwe can generate one:',
    '',
    '```json',
    '{',
    '  "id": "optional-safe-id",',
    '  "label": "Human readable style name",',
    '  "settings": {',
    '    "_custom": true,',
    '    "conditions": { "conditions": [ { "id": "kiwe-global", "main": "any" } ] },',
    '    "general": {},',
    '    "colors": {},',
    '    "typography": {},',
    '    "links": {}',
    '  }',
    '}',
    '```',
    '',
    'Output discipline: no notes, README, reports, Bricks page/template content, global classes, DSA/AppShell selectors, WooCommerce runtime data, checkout/cart/auth logic, or Kiwe Framework profile wrapper unless `/document` is explicitly present.',
    '',
    'Prefer Kiwe/Seam token variables and Bricks global color/theme slots where practical. The file may define site background, body text, links, typography, and global color identity; it must not style individual page components.',
    '',
    schema ? '## JSON Schema\n\n```json\n' + schema.trim() + '\n```' : '',
    '',
    'If tools are available, validate with:',
    '',
    '```bash',
    'node kiwe-ai-toolkit/tools/validate-bricks-theme-style.cjs /path/to/bricks-theme-style.json',
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

export function listCapabilityAttributes() {
  const candidates = [
    'packs/website-builder/contracts/seam-vocabulary.json',
    'packs/appshell-theme/seam-vocabulary.json'
  ];
  for (const rel of candidates) {
    const full = path.join(toolkitRoot, rel);
    if (!fs.existsSync(full)) continue;
    const vocabulary = JSON.parse(fs.readFileSync(full, 'utf8'));
    if (vocabulary && vocabulary.capabilityAttributes) {
      return vocabulary.capabilityAttributes;
    }
  }
  throw new Error('Seam capability attributes were not found in toolkit vocabulary.');
}

export function validateBindings(targetDir, options = {}) {
  return validateBindingsPlan(targetDir, options);
}

export function validateBricksConversion(targetDir, options = {}) {
  return validateBricksConversionPlan(targetDir, options);
}

export function validateAccessibility(targetDir, options = {}) {
  return validateAccessibilityPlan(targetDir, options);
}

export function validateFrameworkProfile(targetDir, options = {}) {
  return validateFrameworkProfilePlan(targetDir, options);
}

export function validateBricksThemeStyle(targetDir, options = {}) {
  return validateBricksThemeStylePlan(targetDir, options);
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
  if (/(?:^|\s)\/list\b/.test(text)) return 'command-list';
  if (/(?:^|\s)\/(?:document|notes)\b/.test(text)) return 'document';
  if (/(\/audit|\/fix)/.test(text) && /(\/allattached|\/allflow|\/previousaudit|\/previouspass|\/previousoutput)/.test(text)) return 'audit-all';
  if (/(?:^|\s)\/fix\b/.test(text)) return 'fix';
  if (/(\/ideate|\/creative|\/webdraft)/.test(text)) return 'ideate';
  if (/\/create/.test(text) && /\/preview/.test(text) && /(\/dsatheme|\/appshell|\/dsa|app shell)/.test(text)) return 'theme-preview-create';
  if (/\/create/.test(text) && /\/preview/.test(text) && /(\/combined|\/combine)/.test(text)) return 'combined-preview-create';
  if (/(\/build|\/create)/.test(text) && /(dsathemeandhomepage|theme and homepage|homepage and theme)/.test(text)) return 'combined-assemble';
  if (/\/audit/.test(text) && /(\/bricksconversion|\/bricks-conversion|bricks conversion|bricks json|bricksjson|html-to-bricks|bricks template|template upload)/.test(text)) return 'bricks-audit';
  if (/(\/convert|\/export|\/translate|\/rebuild|\/adapt)/.test(text) && /(\/bricks\b|bricks json|bricks conversion|bricks template|html-to-bricks|html css to bricks)/.test(text) && !/(\/brickstheme|\btheme style\b)/.test(text)) return 'bricks-convert';
  if (/(\/rebuild|\/convert|\/adapt)/.test(text) && /(\/seamframework|\/seam|seam framework)/.test(text)) return 'seam-rebuild';
  if (/\/audit/.test(text) && /(\/seamframework|\/seam|seam framework)/.test(text)) return 'seam-audit';
  if (/(\/create|\/build)/.test(text) && /(\/accessibility|\/a11y|accessibility)/.test(text)) return 'accessibility-create';
  if (/\/audit/.test(text) && /(\/accessibility|\/a11y|accessibility)/.test(text)) return 'accessibility-audit';
  if (/(\/create|\/build)/.test(text) && /(\/frameworkprofile|\bframework profile\b|\/framework\b)/.test(text)) return 'framework-profile-create';
  if (/\/audit/.test(text) && /(\/frameworkprofile|\bframework profile\b|\/framework\b)/.test(text)) return 'framework-profile-audit';
  if (/(\/create|\/build)/.test(text) && /(\/brickstheme|\bbricks theme\b|\btheme style\b)/.test(text)) return 'bricks-theme-create';
  if (/\/audit/.test(text) && /(\/brickstheme|\bbricks theme\b|\btheme style\b)/.test(text)) return 'bricks-theme-audit';
  if (/(\/create|\/build)/.test(text) && /(\/dsatheme|\/appshell|\/dsa|app shell)/.test(text)) return 'theme-create';
  if (/\/audit/.test(text) && /(\/dsatheme|\/appshell|\/dsa|app shell)/.test(text)) return 'theme-audit';
  if (/\/audit/.test(text) && /(\/combined|\/combine)/.test(text)) return 'combined-audit';
  if (/(\/assemble|\/combine|\/combined)/.test(text)) return 'combined-assemble';
  if (/(\/usesitegraph|\/dynamic|\/sitegraph|\/binding|\/bindings)/.test(text)) return 'dynamic';
  if (/(\/apply|\/staging)/.test(text)) return 'staging';
  return 'workflow';
}

function wantsCompanion(command, explicit = false) {
  const text = String(command || '').trim().toLowerCase();
  return Boolean(explicit) || /(?:^|\s)\/usecompanion\b/.test(text) || /\buse\s+companion\b/.test(text);
}

function commandWithoutExplore(command) {
  return String(command || '')
    .replace(/(?:^|\r?\n)\s*explore\s*:\s*\S+/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function commandWithoutCompanion(command) {
  return commandWithoutExplore(command).replace(/(?:^|\s)\/usecompanion\b/gi, ' ').replace(/\s+/g, ' ').trim();
}

const KNOWN_COMMAND_TOKENS = new Set([
  '/adapt',
  '/apply',
  '/appshell',
  '/assemble',
  '/audit',
  '/a11y',
  '/accessibility',
  '/allattached',
  '/allflow',
  '/atend',
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
  '/document',
  '/dsa',
  '/dsatheme',
  '/dsathemeandhomepage',
  '/dynamic',
  '/eachstep',
  '/export',
  '/execute',
  '/fix',
  '/framework',
  '/frameworkprofile',
  '/htmlcssjs',
  '/ideate',
  '/list',
  '/notes',
  '/nonai',
  '/page',
  '/preview',
  '/previousaudit',
  '/previousoutput',
  '/previouspass',
  '/replacepreview',
  '/replacepreviewdata',
  '/rebuild',
  '/seam',
  '/seamframework',
  '/staging',
  '/stepbystep',
  '/sitegraph',
  '/theme',
  '/translate',
  '/usesitegraph',
  '/usecompanion',
  '/webdraft',
  '/websitename',
  '/webpage',
  '/website',
  '/fullflow',
  '/ai'
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
  ['/seamframwork', '/seamframework'],
  ['/repalcepreview', '/replacepreviewdata'],
  ['/usegraph', '/usesitegraph'],
  ['/sitegrap', '/usesitegraph']
]);

const VALID_PHASE_COMMANDS = [
  '/list',
  '/execute /stepbystep',
  '/execute /fullflow',
  '/audit /eachstep',
  '/audit /atend',
  '/audit /allattached',
  '/fix /allattached',
  '/audit /allflow',
  '/fix /allflow',
  '/audit /previousoutput',
  '/fix /previousoutput',
  '/audit /allattached /allflow',
  '/fix /previousaudit',
  '/document',
  '/fix',
  '/ideate /webdraft',
  '/rebuild /seamframework',
  '/audit /seamframework',
  '/create /frameworkprofile',
  '/audit /frameworkprofile',
  '/create /brickstheme',
  '/audit /brickstheme',
  '/create /dsatheme',
  '/create /preview /dsatheme',
  '/audit /dsatheme',
  '/assemble /combined',
  '/create /preview /combined',
  '/audit /combined',
  '/usesitegraph',
  '/usesitegraph /replacepreviewdata',
  '/usesitegraph /websitename',
  '/usesitegraph /nonai',
  '/convert /bricks',
  '/audit /bricksconversion',
  '/create /accessibility',
  '/audit /accessibility',
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
  const value = String(text || '');
  return /bricks-conversion[\\/]kiwe-bricks-conversion\.json|kiwe-bricks-conversion\.json|bricks-template[\\/][^\\/\n]+\.json|template-upload\.json|\"templateType\"\s*:|\"content\"\s*:\s*\[/i.test(value);
}

function isBricksConvertCommand(text) {
  const value = String(text || '');
  return commandHas(value, /\/convert\b/) && commandHas(value, /\/bricks\b|bricks json|bricks conversion|bricks template|html-to-bricks|html css to bricks/) && !commandHas(value, /\/brickstheme\b|\btheme style\b/);
}

function hasThemeArtifact(text) {
  return /appshell-theme|theme-package\.json|css[\\/]theme\.css|\btheme\.css\b|dsatheme|app\s*shell|appshell/i.test(String(text || ''));
}

function hasAccessibilityArtifact(text) {
  return /accessibility[\\/]kiwe-accessibility-plan\.json|kiwe-accessibility-plan\.json|kiwe\.accessibility-plan\.v1|ACCESSIBILITY-NOTES\.md/i.test(String(text || ''));
}

function hasFrameworkProfileArtifact(text) {
  return /framework[\\/]kiwe-framework-profile\.json|kiwe-framework-profile\.json|kiwe\.framework-profile\.v1|\/frameworkprofile|\bframework profile\b|kiwe\s*>\s*framework/i.test(String(text || ''));
}

function hasBricksThemeStyleArtifact(text) {
  return /bricks-theme-style\.json|bricks theme style|theme styles? manager|theme-style import|theme_style|bricksThemeStyle|themeStyle/i.test(String(text || ''));
}

function hasFrameworkFoundation(text) {
  return hasFrameworkProfileArtifact(text) || hasBricksThemeStyleArtifact(text) || /pushed to bricks|bricks variables installed|global theme style installed|kiwe framework installed|framework already pushed/i.test(String(text || ''));
}

function hasForbiddenBricksSource(text) {
  return /combined-preview|appshell-theme|theme-package\.json|css[\\/]theme\.css|\btheme\.css\b|data-dsa-surface|dsa[-\s]*(?:dock|sheet|screen|navbar)|appshell[-\s]*preview|app\s*shell[-\s]*preview/i.test(String(text || ''));
}

function hasSiteGraphAccess(text) {
  return /kiwe\.site-graph\.v1|sitegraphhash|site graph json|site graph summary|siteGraphSummary|KIWE_REST_BASE|\/wp-json\/dsa\/v1|kiwe_ai_|X-Kiwe-AI-Key|Authorization:\s*Bearer|site-graph-data|site_graph_data/i.test(String(text || ''));
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

const KIWE_ERROR_CODES = {
  unknown_command_token: 'KIWE_UNKNOWN_COMMAND',
  execute_missing_current_artifact: 'KIWE_MISSING_ARTIFACT',
  audit_all_missing_artifacts: 'KIWE_MISSING_ARTIFACT',
  fix_missing_artifact: 'KIWE_MISSING_ARTIFACT',
  document_missing_artifact: 'KIWE_MISSING_ARTIFACT',
  previous_audit_missing: 'KIWE_PREVIOUS_AUDIT_MISSING',
  previous_output_missing: 'KIWE_PREVIOUS_OUTPUT_MISSING',
  accessibility_audit_missing_artifact: 'KIWE_MISSING_ARTIFACT',
  bricks_convert_missing_framework_profile: 'KIWE_WRONG_LANE',
  bricks_convert_requires_convert_verb: 'KIWE_WRONG_LANE',
  command_is_noop: 'KIWE_WRONG_LANE',
  audit_cadence_requires_execute: 'KIWE_WRONG_LANE',
  theme_convert_blocked: 'KIWE_WRONG_LANE',
  sitegraph_required: 'KIWE_SITEGRAPH_REQUIRED'
};

function kiweErrorCode(code) {
  return KIWE_ERROR_CODES[code] || `KIWE_${String(code || 'COMMAND_BLOCKED').toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '')}`;
}

export function diagnoseCommand({ command = '', brief = '', artifactSummary = '', siteGraphSummary = '' } = {}) {
  const raw = String(command || '').trim();
  const commandText = commandWithoutExplore(raw);
  const text = commandText.toLowerCase();
  const commandCore = commandWithoutCompanion(raw);
  const normalizedCommand = commandCore
    .replace(/(?:^|\s)\/build\b/gi, ' /create')
    .replace(/(?:^|\s)\/dynamic\s+\/sitegraph\b/gi, ' /usesitegraph')
    .replace(/(?:^|\s)\/sitegraph\b/gi, ' /usesitegraph')
    .replace(/(?:^|\s)\/replacepreview\b/gi, ' /replacepreviewdata')
    .replace(/\s+/g, ' ')
    .trim();
  const tokens = slashTokens(commandText);
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

  if (commandHas(text, /\/list/) && tokens.length === 1) {
    return commandDiagnostic({
      status: 'ok',
      code: 'command_list',
      kind: 'command-list',
      normalizedCommand: '/list',
      message: 'List the Kiwe command vocabulary only. Do not start generation.'
    });
  }

  if (commandHas(text, /\/audit\s+\/(?:eachstep|atend)/) && !commandHas(text, /\/execute/) && !commandHas(text, /\/(?:allattached|allflow)/)) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'audit_cadence_requires_execute',
      kind: 'execute',
      normalizedCommand,
      message: '`/audit /eachstep` and `/audit /atend` are execution flags. Pair them with `/execute /stepbystep` or `/execute /fullflow`.',
      suggestions: ['/execute /stepbystep /audit /eachstep', '/execute /fullflow /audit /eachstep', '/execute /fullflow /audit /atend'],
      boundaries: ['Do not treat audit cadence flags as standalone generation or audit commands.']
    });
  }

  if (commandHas(text, /\/execute/) && !String(artifactSummary || '').trim()) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'execute_missing_current_artifact',
      kind: 'execute',
      normalizedCommand,
      message: '`/execute` needs the current artifact or file map. It must not use older Kiwe/National Chikki/BioVantage outputs or prior validation notes unless they were supplied in the current turn.',
      suggestions: ['Provide the current artifact/file map.', '/execute /stepbystep /audit /eachstep', '/execute /fullflow /audit /eachstep'],
      boundaries: ['Current-run artifacts only.', 'No prior test material unless explicitly supplied for comparison.']
    });
  }

  if (commandHas(text, /\/fix/) && commandHas(text, /\/previouspass/)) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'previous_audit_missing',
      kind: 'audit-all',
      normalizedCommand: normalizedCommand.replace(/\/previouspass\b/i, '/previousaudit'),
      message: '`/fix /previouspass` is not a canonical SeamFlow command. Interpreting the intent as `/fix /previousaudit`: no immediately previous audit result was supplied, so there is nothing safe to fix yet.',
      suggestions: String(artifactSummary || '').trim()
        ? ['/audit /allattached /allflow', '/fix /previousaudit after that audit returns failures']
        : ['Attach the current output files.', '/audit /allattached /allflow after files are supplied', '/fix /previousaudit after that audit returns failures'],
      boundaries: ['Do not fix from memory.', 'Do not infer previous failures from old conversations or stale files.', 'Run the relevant audit first, then fix the reported failures only.']
    });
  }

  if (commandHas(text, /\/previousoutput/) && !String(artifactSummary || '').trim()) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'previous_output_missing',
      kind: 'audit-all',
      normalizedCommand,
      message: '`/previousoutput` means the files generated in the immediate previous AI output in this same session. Those files are not available to this command, so it cannot continue safely.',
      suggestions: ['Attach the immediate previous output files.', 'Rerun the previous generation command if the AI cannot access its output files.', '/audit /allattached /allflow after files are supplied'],
      boundaries: ['Do not search downloads, old sandboxes, old conversations, or previous project attempts.', 'Do not infer file contents from a summary.', 'Use only immediately previous output files that are directly accessible in this session.']
    });
  }

  if (commandHas(text, /\/fix/) && commandHas(text, /\/previousaudit/) && !String(artifactSummary || '').trim()) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'previous_audit_missing',
      kind: 'audit-all',
      normalizedCommand,
      message: '`/fix /previousaudit` needs the immediately previous audit findings and current artifacts in this conversation or supplied as files. It must not fix from memory or prior accepted notes.',
      suggestions: ['Attach the previous audit output plus current artifacts.', '/audit /allattached /allflow after current files are supplied'],
      boundaries: ['Previous audit findings must be current-run evidence.', 'Do not use stale findings from old tests.', 'Do not redesign or rebuild during /fix /previousaudit.']
    });
  }

  if (commandHas(text, /\/(?:audit|fix)/) && commandHas(text, /\/(?:allattached|allflow)/) && !String(artifactSummary || '').trim()) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'audit_all_missing_artifacts',
      kind: 'audit-all',
      normalizedCommand,
      message: '`/audit /allattached`, `/fix /allattached`, `/audit /allflow`, and `/fix /allflow` need the current generated files, attached artifacts, or file map. They classify and audit real artifacts; they do not search for stale files.',
      suggestions: ['Attach the current output files.', 'Provide the current artifact folder/file map.', '/audit /allattached after files are supplied', '/fix /allflow after failed current artifacts are supplied'],
      boundaries: ['Current-run artifacts only.', 'Do not use prior National Chikki/BioVantage outputs unless supplied in this turn.', 'Do not rebuild during audit-all/fix-all commands.']
    });
  }

  if (commandHas(text, /\/fix/) && !String(artifactSummary || '').trim()) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'fix_missing_artifact',
      kind: 'fix',
      normalizedCommand,
      message: '`/fix` needs the failed output folder/file map, audit result, or artifact summary. It repairs an existing artifact; it does not start a new creative phase.',
      suggestions: ['Provide the generated folder/file map plus the failed audit output.', '/fix /seamframework', '/fix /dsatheme', '/fix /combined', '/fix /bricksconversion'],
      boundaries: ['Fix phases must revise actual files in the current artifact lane.', 'Do not create a new unrelated package to hide the failed one.']
    });
  }

  if (commandHas(text, /\/(?:document|notes)\b/) && !String(artifactSummary || '').trim()) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'document_missing_artifact',
      kind: 'document',
      normalizedCommand,
      message: '`/document` needs an existing artifact folder/file map. It writes compact notes for an artifact; it does not generate, rebuild, audit, convert, or redesign.',
      suggestions: ['Provide the artifact folder/file map.', '/document after /rebuild /seamframework', '/document after /convert /bricks'],
      boundaries: ['Documentation phases must not create or revise production artifacts unless paired with an explicit create/fix command.']
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
      message: 'No `/create /preview /brickstheme` or `/create /preview /frameworkprofile` command exists. Framework profiles and Bricks theme styles are import/config JSON, not separate preview lanes.',
      suggestions: ['/create /frameworkprofile', '/audit /frameworkprofile', '/create /brickstheme', '/audit /brickstheme', '/create /preview /dsatheme', '/create /preview /combined'],
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
      suggestions: existing ? ['/rebuild /seamframework', '/audit /seamframework', '/usesitegraph', '/convert /bricks'] : ['/ideate /webdraft', '/rebuild /seamframework'],
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

  if (commandHas(text, /\/create/) && commandHas(text, /\/bricks\b/) && !commandHas(text, /\/brickstheme\b|\btheme style\b|\/preview\b/)) {
    return commandDiagnostic({
      status: 'rejected',
      code: 'bricks_convert_requires_convert_verb',
      kind: 'bricks-convert',
      normalizedCommand,
      message: 'No `/create /bricks` command exists. Use `/convert /bricks` for the Bricks My Templates upload JSON lane.',
      suggestions: ['/convert /bricks'],
      boundaries: ['Use `/create` for new creative/config artifacts and `/convert` for transforming approved page HTML into Bricks template JSON.']
    });
  }

  if (isBricksConvertCommand(text) && hasForbiddenBricksSource(raw)) {
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

  if (isBricksConvertCommand(text)) {
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
    if (!hasFrameworkFoundation(`${artifactText}\n${siteGraphSummary}`)) {
      return commandDiagnostic({
        status: 'needs_input',
        code: 'bricks_convert_missing_framework_profile',
        kind: 'bricks-convert',
        normalizedCommand,
        message: '`/convert /bricks` should run after a Kiwe Framework profile or Bricks theme style exists and has been imported/pushed. Otherwise the Bricks page may reference Seam/Kiwe variables, colors, and font tokens that do not render on the frontend.',
        suggestions: ['/create /frameworkprofile first', '/audit /frameworkprofile, then import it in Kiwe > Framework and push to Bricks', 'If already pushed, rerun `/convert /bricks` with artifactSummary saying Kiwe > Framework is already pushed to Bricks'],
        boundaries: ['Do not silently convert a page that depends on missing sitewide tokens/theme style.', 'Do not create a Framework profile inside `/convert /bricks`; stop and ask for the missing foundation.']
      });
    }
  }

  if (commandHas(text, /\/audit/) && commandHas(text, /\/frameworkprofile|\bframework profile\b|\/framework\b/) && !hasFrameworkProfileArtifact(artifactSummary)) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'framework_profile_audit_missing_artifact',
      kind: 'framework-profile-audit',
      normalizedCommand,
      message: '`/audit /frameworkprofile` needs `framework/kiwe-framework-profile.json`. Do not audit a Bricks theme-style file or DSA theme package as a Framework profile.',
      suggestions: ['/create /frameworkprofile', '/audit /frameworkprofile after kiwe-framework-profile.json exists'],
      boundaries: ['Framework profile audit is for the Kiwe > Framework import file only.']
    });
  }

  if (commandHas(text, /\/audit/) && commandHas(text, /\/brickstheme|\bbricks theme\b|\btheme style\b/) && !hasBricksThemeStyleArtifact(artifactSummary)) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'bricks_theme_audit_missing_artifact',
      kind: 'bricks-theme-audit',
      normalizedCommand,
      message: '`/audit /brickstheme` needs `bricks-theme-style.json`. Do not audit a Kiwe Framework profile, Bricks template, or DSA theme package as a native Bricks theme style.',
      suggestions: ['/create /brickstheme', '/audit /brickstheme after bricks-theme-style.json exists'],
      boundaries: ['Bricks theme-style audit is for the native Bricks Theme Styles JSON only.']
    });
  }

  if (commandHas(text, /\/audit/) && commandHas(text, /\/(?:bricksconversion|bricks-conversion)\b|bricks conversion|bricks json|html-to-bricks|bricks template|template upload/) && !hasConversionArtifact(artifactSummary)) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'bricks_audit_missing_conversion_artifact',
      kind: 'bricks-audit',
      normalizedCommand,
      message: '`/audit /bricksconversion` needs a native `bricks-template/*-template-upload.json` or `bricks-conversion/kiwe-bricks-conversion.json`. Do not audit a non-existent conversion.',
      suggestions: ['/convert /bricks', '/audit /bricksconversion after the Bricks template upload JSON exists'],
      boundaries: ['Audit phases inspect existing artifacts; they do not silently create missing outputs.']
    });
  }

  if (commandHas(text, /\/create/) && commandHas(text, /\/(?:accessibility|a11y)\b|accessibility/) && !String(artifactSummary || '').trim()) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'accessibility_create_missing_artifact',
      kind: 'accessibility-create',
      normalizedCommand,
      message: '`/create /accessibility` needs an existing website/page, DSA theme, combined handoff, Framework profile, Bricks conversion, or approved visual output. It is a contrast/dark-mode/token pass, not a pure creative phase.',
      suggestions: ['/rebuild /seamframework', '/create /dsatheme', '/assemble /combined', '/create /accessibility after artifact files exist'],
      boundaries: ['Accessibility plans revise concrete visuals and token pairs. They should not invent a new website or DSA theme from nothing.']
    });
  }

  if (commandHas(text, /\/audit/) && commandHas(text, /\/(?:accessibility|a11y)\b|accessibility/) && !String(artifactSummary || '').trim() && !hasAccessibilityArtifact(artifactSummary)) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'accessibility_audit_missing_artifact',
      kind: 'accessibility-audit',
      normalizedCommand,
      message: '`/audit /accessibility` needs existing artifact files and preferably `accessibility/kiwe-accessibility-plan.json`. Do not run a generic accessibility audit against nothing.',
      suggestions: ['Provide the handoff folder/file map', '/create /accessibility after the website/theme/combined lane exists'],
      boundaries: ['Accessibility audit inspects concrete color pairs, dark-mode selectors, Bricks theme-style tokens, and Kiwe/Seam token usage.']
    });
  }

  if (commandHas(text, /\/usesitegraph/) && commandHas(text, /\/(?:replacepreview|replacepreviewdata)/) && !String(artifactSummary || '').trim()) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'sitegraph_replacepreview_missing_artifact',
      kind: 'dynamic',
      normalizedCommand,
      message: '`/usesitegraph /replacepreviewdata` needs an existing handoff to revise. It should replace preview-only samples from Site Graph data without hardcoding those samples into production/import artifacts.',
      suggestions: ['Provide the handoff folder/file map and Site Graph API/export.', '/usesitegraph /replacepreviewdata after the handoff exists'],
      boundaries: ['Preview data may become real samples; production lanes must keep dynamic tags/query-loop intent when available.']
    });
  }

  if (commandHas(text, /\/usesitegraph|\/dynamic|\/sitegraph|\/binding|\/bindings/) && !hasSiteGraphAccess(`${raw}\n${brief}\n${artifactSummary}\n${siteGraphSummary}`)) {
    return commandDiagnostic({
      status: 'needs_input',
      code: 'dynamic_missing_site_graph',
      kind: 'dynamic',
      normalizedCommand,
      message: '`/usesitegraph` needs target-site truth. Ask for either KIWE_REST_BASE + KIWE_AI_KEY, an exported kiwe.site-graph.v1 JSON packet, or the public Site Graph Data endpoint. Do not guess product categories, pages, custom fields, dynamic tags, Bricks settings, or query-loop types.',
      suggestions: ['Ask for KIWE_REST_BASE and KIWE_AI_KEY.', 'Ask for exported kiwe.site-graph.v1 JSON.', 'For AI-less public reads use /usesitegraph /nonai with /wp-json/dsa/v1/site-graph/data/schema and /site-graph/data.', '/usesitegraph after Site Graph is available'],
      boundaries: ['Dynamic binding must be grounded in target-site truth, not frontend scraping or assumptions.']
    });
  }

  if (commandHas(text, /\/audit/) && commandHas(text, /\/(?:seamframework|seam|brickstheme|frameworkprofile|framework|dsatheme|appshell|dsa|combined|combine|accessibility|a11y)\b|seam framework|bricks theme|app shell/) && !String(artifactSummary || '').trim()) {
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

  const normalizedMessage = normalizedCommand !== commandCore
    ? /\/build\b/i.test(commandCore)
      ? 'Legacy `/build` alias accepted internally; use `/create` in user-facing output.'
      : 'Legacy command alias accepted internally; use the canonical command in user-facing output.'
    : 'Command is recognized.';

  return commandDiagnostic({
    status: 'ok',
    code: normalizedCommand !== commandCore ? 'legacy_alias_normalized' : 'ok',
    kind: routeKind(raw),
    normalizedCommand,
    message: normalizedMessage
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
    `ERROR: ${kiweErrorCode(diagnostic.code)}`,
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
  if (kind.startsWith('accessibility')) return 'audit';
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

function commandListMarkdown() {
  const list = listCommands();
  const lines = [
    '# Kiwe slash commands',
    '',
    'Use one small phase at a time. `/create` is the canonical creation verb; `/build` is only a legacy alias.',
    '',
    'Fast machine-readable command manifest: `kiwe-ai-toolkit/command-manifest.json`.',
    '',
    'Documentation and long explanations are opt-in through `/document`; otherwise return only the requested artifact(s) and a compact PASS/FAIL/WARN summary.',
    '',
    '## Commands'
  ];
  for (const entry of list.commands) {
    lines.push(
      '',
      `### ${entry.command}`,
      '',
      entry.aliases ? `Aliases: ${entry.aliases.join(', ')}` : '',
      '',
      `Purpose: ${entry.purpose}`,
      '',
      `Requires: ${entry.requires.length ? entry.requires.join('; ') : 'nothing beyond the brief'}`,
      '',
      `Output: ${entry.output}`
    );
  }
  lines.push(
    '',
    '## Flags',
    '',
    '- `/usecompanion`: optional bounded Companion assist. If unavailable, continue without it and report fallback.',
    '',
    '## Site Graph lane',
    '',
    '- `/usesitegraph` is the canonical Site Graph command.',
    '- `/usesitegraph /nonai` forces public/read-only Site Graph Data or an exported packet. It must not call Companion/native AI.',
    '- `/usesitegraph /replacepreviewdata` updates preview samples from real data but keeps production artifacts dynamic.',
    '- `/usesitegraph /websitename` derives name/logo/identity from Site Graph only.',
    '',
    '## Accessibility lane',
    '',
    '- `/create /accessibility` works only after there is an existing website/page, DSA theme, combined handoff, Framework profile, or Bricks conversion to inspect.',
    '- `/audit /accessibility` checks literal and token-resolved contrast pairs, light/dark proof, Kiwe/Seam token usage, Bricks theme-style alignment, preview/import separation, and visible text containment risks.',
    '- `/fix /accessibility` repairs only the existing failed artifact lane plus `accessibility/kiwe-accessibility-plan.json`; it must not redesign, reconvert to Bricks, create DSA themes, or add docs unless `/document` is present.',
    '- `/fix /accessibility` should preserve Bricks element count, global class count, existing Seam classes, Kiwe/Appsite attributes, dynamic tags, query-loop intent, ARIA relationships, and DSA/AppShell boundaries unless a documented accessibility-token exception is necessary.',
    '- This lane covers color contrast, native light/dark transitions, and clipped/overflowing critical text. Full font-size/readability preference work remains a separate future phase.',
    '',
    '## Bricks boundary',
    '',
    '- `/convert /bricks` converts only `website/bricks-paste.html`.',
    '- `/convert /bricks` is the user-facing Bricks My Templates upload phase.',
    '- The lean default output is one native Bricks template upload JSON at `bricks-template/[page-name]-template-upload.json` with non-empty `title`, `templateType`, and `content/header/footer` data.',
    '- Optional Kiwe fidelity proof may be embedded in that upload JSON under top-level `kiwe`; external notes/reports/wrappers require `/document`.',
    '- `/convert /bricks` should run only after `/create /frameworkprofile` has produced `framework/kiwe-framework-profile.json` or the human confirms Kiwe > Framework/Bricks Theme Styles are already pushed.',
    '- `/convert /bricks` must consume that Framework token layer inside native Bricks element settings and `global_classes`; a valid profile does not rewrite hardcoded Bricks JSON later.',
    '- Treat hardcoded design lengths in native Bricks settings as audit failures: `_padding: 28px`, `_border.radius: 24px`, `_heightMin: 390px`, `_typography.font-size: 2.35rem`, `_rowGap: 20px`, `_transform.translateY: -7px`, and similar values must become Kiwe/Seam variables or tokenized `clamp(...)` expressions.',
    '- Treat direct component colors in native Bricks settings/global classes/custom CSS as audit failures too: `color: #fff`, `_background.color.raw: #8deae5`, `linear-gradient(#201b18, #514238)`, and `--pack-bg: #f5b942` must consume `var(--kiwe-*)`, `var(--seam-*)`, or declared project variables. Literal colors are allowed only in Framework/global variable definitions or as fallbacks inside `var(...)`.',
    '- It must not convert DSA themes, combined previews, AppShell sheets/screens/docks, or theme CSS.',
    '- It must not output `README.md`, `BRICKS-CONVERSION-NOTES.md`, validation reports, ZIP files, duplicated previews, or loose extra page files unless `/document` is explicitly present.',
    '',
    '## Framework/theme-style boundary',
    '',
    '- `/create /frameworkprofile` creates `framework/kiwe-framework-profile.json` for Kiwe > Framework import/push.',
    '- `/create /brickstheme` creates only `bricks-theme-style.json` for Bricks Theme Styles import.',
    '- These are separate commands. Do not output both unless the human requested both commands.'
  );
  return lines.filter(Boolean).join('\n').trim() + '\n';
}

function fixPhaseContext(command, artifactSummary) {
  const text = `${command}\n${artifactSummary}`.toLowerCase();
  const inferred = hasAccessibilityArtifact(text) || /\/(?:accessibility|a11y)\b|accessibility|contrast|dark mode|light mode|overflow|clipp/.test(text)
      ? '/audit /accessibility'
    : hasConversionArtifact(text)
      ? '/audit /bricksconversion'
    : hasBricksThemeStyleArtifact(text)
      ? '/audit /brickstheme'
    : hasFrameworkProfileArtifact(text)
      ? '/audit /frameworkprofile'
      : hasThemeArtifact(text)
        ? '/audit /dsatheme'
        : /combined-preview|combined-kiwe-handoff|\/combined/.test(text)
          ? '/audit /combined'
          : hasPageArtifact(text)
          ? '/audit /seamframework'
            : '/list';
  const rules = [
    '- Inspect the supplied files, not the whole Kiwe repository.',
    '- Keep only files required by the current lane unless the human explicitly asked for extras.',
    '- Revise the actual files that failed; do not only explain the failure.',
    inferred === '/audit /bricksconversion' ? '- If the artifact is a Bricks conversion/template upload, require `bricks-template/*-template-upload.json` or `bricks-conversion/kiwe-bricks-conversion.json`.' : '',
    inferred === '/audit /bricksconversion' ? '- Fix token purity in the Bricks artifact itself: direct component colors and hardcoded lengths in element settings/global_classes/custom CSS must become official Kiwe/Seam variables, declared project variables, or real responsive clamps where appropriate. Do not say a Framework profile will fix hardcoded Bricks JSON later.' : '',
    inferred === '/audit /accessibility' ? '- If the artifact is a Bricks template, treat it only as the accessibility target; do not run `/convert /bricks`, do not rebuild the template, and do not create a new Bricks conversion package unless the human separately asks.' : '',
    inferred === '/audit /accessibility' ? '- Preserve Bricks element count, global class count, Seam classes, data-role/data-flow, data-kiwe-* attributes, data-dsa-* attributes, dynamic tags, query-loop intent, conditions, interactions, IDs, and ARIA relationships unless a documented accessibility-token exception is necessary.' : '',
    inferred === '/audit /accessibility' ? '- Follow Kiwe/Seam token pairs and Framework/Bricks theme-style context before inventing new classes, colors, or tokens. Add project tokens only for genuine art-direction constants that official Kiwe tokens cannot express.' : '',
    '- If the artifact is a Seam rebuild, keep `website/bricks-paste.html` as the single page preview/import artifact.',
    '- If the artifact is a DSA theme, keep AppShell theme CSS separate from Bricks/page CSS.',
    '- If the artifact is combined, keep `website/`, `appshell-theme/`, and `combined-preview/` separate.',
    '- Preserve Site Graph/dynamic intent instead of converting sampled preview data into production hardcoding.'
  ].filter(Boolean);
  return [
    '# Kiwe fix phase',
    '',
    '`/fix` repairs the existing artifact lane. It must not restart creative work, add unrelated output folders, or invent a new package shape.',
    '',
    `Suggested audit route for this artifact: ${inferred}`,
    '',
    '## Fix rules',
    '',
    rules.join('\n'),
    '',
    '## Artifact summary',
    '',
    String(artifactSummary || '').trim(),
    '',
    'Run the matching audit context below, fix every blocking item, and report compact PASS/FAIL/WARN, files changed, structural drift, validation run, and remaining warnings.'
  ].join('\n').trim() + '\n';
}

function siteGraphCommandGuidance(command) {
  const text = String(command || '').toLowerCase();
  const nonAi = commandHas(text, /\/nonai/);
  const replacePreview = commandHas(text, /\/(?:replacepreview|replacepreviewdata)/);
  const websiteName = commandHas(text, /\/websitename/);
  const lines = [
    '# `/usesitegraph` guidance',
    '',
    '`/usesitegraph` is the canonical Site Graph phase. Legacy `/dynamic /sitegraph` may be accepted internally, but user-facing output should say `/usesitegraph`.',
    '',
    nonAi
      ? 'This command includes `/nonai`: use only exported `kiwe.site-graph.v1` JSON or the AI-less Site Graph Data routes. Do not call Companion, native AI, `/ai/advisor`, `/ai/studio`, or other model-backed routes.'
      : 'Use the richest safe route available: API-key AI namespace when provided, otherwise exported Site Graph JSON, otherwise the AI-less public Site Graph Data route.',
    '',
    'If no Site Graph/API/export is available, stop and ask for it. Do not scrape the public frontend as a fallback.',
    '',
    replacePreview
      ? 'This command includes `/replacepreviewdata`: update preview-only sample cards/images/text from real Site Graph Data, but keep production/import artifacts dynamic through Bricks tags, query-loop intent, or bindings.'
      : '',
    websiteName
      ? 'This command includes `/websitename`: derive site name, logo, brand identity, menus, and tone from Site Graph identity/menu data only.'
      : '',
    '',
    'Expected output when dynamic intent changes:',
    '',
    '```text',
    'bricks-bindings/',
    '  kiwe-bindings.json',
    '```',
    '',
    'Do not emit `BINDING-NOTES.md`, README files, reports, or extra docs unless the command also includes `/document` or the human explicitly asks for documentation.'
  ];
  return lines.filter(Boolean).join('\n').trim() + '\n';
}

function seamRebuildPhaseContext() {
  return [
    '# Seam rebuild compact contract',
    '',
    'Output only the page lane unless the human explicitly requests `/document`, `/convert /bricks`, DSA theme work, combined preview work, dynamic binding, or staging.',
    '',
    'Required output:',
    '',
    '```text',
    'website/',
    '  bricks-paste.html',
    '```',
    '',
    'Do not emit `website/bricks-notes.md`, README files, reports, Bricks JSON, AppShell/DSA markup, combined previews, or split preview assets during plain `/rebuild /seamframework`.',
    '',
    '`website/bricks-paste.html` is both the standalone browser preview and the Bricks HTML/CSS paste/import artifact.',
    '',
    'Preserve the approved visual thesis, rebuild with official Seam roles/classes/tokens, keep `data-role` official and headless, put visual styling on project-owned classes, preserve real Kiwe capability intent through attributes, and do not duplicate Kiwe/WordPress/WooCommerce/Bricks runtime authority.',
    '',
    getSeamAttributesContext()
  ].join('\n');
}

function workflowBoundaryContext(kind) {
  return [
    '# Kiwe workflow boundary',
    '',
    `Selected route kind: ${kind || 'workflow'}.`,
    '',
    'Do only the selected phase. Do not silently expand into website + DSA + Bricks + dynamic + staging work.',
    '',
    'Documentation is opt-in: create notes only when the command includes `/document` or the human explicitly asks for documentation.',
    '',
    'If the command is impossible, missing inputs, or targeting the wrong lane, stop and report the command-gate diagnostic instead of guessing.',
    '',
    'Use `/list` when the human wants the complete command vocabulary.'
  ].join('\n');
}

export function routeCommand({ command = '', brief = '', artifactSummary = '', siteGraphSummary = '', useCompanion = false } = {}) {
  const diagnostic = diagnoseCommand({ command, brief, artifactSummary, siteGraphSummary });
  if (diagnostic.stop) {
    return commandDiagnosticResponse(diagnostic, command);
  }
  const kind = diagnostic.kind || routeKind(command);
  const companionRequested = wantsCompanion(command, useCompanion) && !commandHas(command, /\/nonai/);
  const humanBrief = String(brief || '').trim() || 'No human brief supplied.';
  const artifact = String(artifactSummary || '').trim() || 'No previous artifact summary supplied. Ask the human for the prior phase output if this command depends on one.';
  const graph = String(siteGraphSummary || '').trim() || 'No Site Graph summary supplied. Ask for target-site Site Graph before dynamic binding.';

  if (kind === 'command-list') {
    return commandListMarkdown();
  }

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
    kind === 'workflow' ? getWorkflowContext() : workflowBoundaryContext(kind)
  ];

  if (kind === 'fix') {
    const fixText = `${command}\n${artifactSummary}`.toLowerCase();
    if (/accessibility|a11y|contrast|dark mode|light mode|overflow|clipp/.test(fixText)) {
      parts.push(fixPhaseContext(command, artifactSummary), '', getAccessibilityContext());
    } else if (hasConversionArtifact(fixText) || /bricksconversion|bricks conversion|bricks template|html-to-bricks|\/bricks\b/.test(fixText)) {
      parts.push(fixPhaseContext(command, artifactSummary), '', readMaybe('contexts/audit-lite.md'), '', getBricksConversionContext());
    } else if (/sitegraph|dynamic|binding|query loop|dynamic tag/.test(fixText)) {
      parts.push(fixPhaseContext(command, artifactSummary), '', readMaybe('contexts/audit-lite.md'), '', getDynamicContext());
    } else {
      parts.push(fixPhaseContext(command, artifactSummary), '', readMaybe('contexts/audit-lite.md'));
    }
  } else if (kind === 'document') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Create compact documentation for the supplied artifact only. Do not redesign, rebuild, audit, convert, or create extra artifact lanes.',
      '',
      'For a Seam rebuild, `website/bricks-notes.md` is allowed only in this `/document` phase. Keep it short: preview/import file, capability boundaries, preview-only notes, and next recommended command.',
      '',
      'For Bricks conversion, DSA theme, combined handoff, Site Graph/dynamic binding, or accessibility lanes, document only the files and assumptions already present in the supplied artifact.'
    );
  } else if (kind === 'ideate') {
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
      seamRebuildPhaseContext()
    );
  } else if (kind === 'seam-audit') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Audit the Seam rebuild and revise the actual files.',
      '',
      seamRebuildPhaseContext(),
      '',
      readMaybe('contexts/audit-lite.md')
    );
  } else if (kind === 'framework-profile-create') {
    parts.push(frameworkProfileContext());
  } else if (kind === 'framework-profile-audit') {
    parts.push(frameworkProfileContext(), readMaybe('contexts/audit-lite.md'));
  } else if (kind === 'bricks-theme-create') {
    parts.push(bricksThemeStyleContext());
  } else if (kind === 'bricks-theme-audit') {
    parts.push(bricksThemeStyleContext(), readMaybe('contexts/audit-lite.md'));
  } else if (kind === 'accessibility-create') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Create the accessibility lane over the supplied artifact. Do not redesign the page/theme or create Bricks JSON unless the existing artifact already includes that lane.',
      '',
      getAccessibilityContext()
    );
  } else if (kind === 'accessibility-audit') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Audit and revise actual files for color contrast, light/dark proof, Bricks global theme-style color alignment, Kiwe/Seam token pairing, and critical text containment.',
      '',
      getAccessibilityContext(),
      '',
      readMaybe('contexts/audit-lite.md')
    );
  } else if (kind === 'audit-all') {
    parts.push(
      '# Selected phase guidance',
      '',
      'Classify every supplied/current artifact by actual file content, then run every matching lane audit required by `/audit /allattached`, `/audit /allflow`, `/audit /allattached /allflow`, or `/audit /previousoutput`.',
      '',
      'Do not rebuild, redesign, create DSA/combined output, create docs, search for stale files, or use prior accepted notes. Audit-only commands return findings only. If `/fix /allattached`, `/fix /allflow`, `/fix /previousoutput`, or `/fix /previousaudit` is present, fix only failed current lanes and rerun the same audits until PASS or NEEDS_INPUT.',
      '',
      '`/previousoutput` may use only the files generated in the immediate previous AI output in this same session. If those files are not directly accessible, stop with `ERROR: KIWE_PREVIOUS_OUTPUT_MISSING`.',
      '',
      '`/fix /previousaudit` may use only the immediately previous audit findings supplied in the current conversation/file set. If those findings are absent or stale, stop with `ERROR: KIWE_PREVIOUS_AUDIT_MISSING`.',
      '',
      'Use validator authority: only executed validator proof may close a lane as PASS. Official Kiwe validator commands, Kiwe MCP validator tools, Kiwe REST/plugin validators, or hosted/local Kiwe validator endpoints count; copied/reconstructed validator logic does not.',
      '',
      frameworkProfileContext(),
      '',
      getBricksConversionContext(),
      '',
      getAccessibilityContext(),
      '',
      readMaybe('contexts/audit-lite.md')
    );
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
      siteGraphCommandGuidance(command),
      '',
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
  const required = normalized === 'website' ? [] : ['README.md'];
  if (normalized === 'website' || normalized === 'combined') {
    required.push('website/bricks-paste.html');
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
  const bricksThemeStyle = validateBricksThemeStylePlan(root, { optional: true });
  const bricksThemeErrors = bricksThemeStyle.ok ? [] : bricksThemeStyle.errors || [];
  return {
    ok: missing.length === 0 && frameworkErrors.length === 0 && bricksThemeErrors.length === 0,
    mode: normalized,
    root,
    missing,
    frameworkProfile,
    bricksThemeStyle
  };
}
