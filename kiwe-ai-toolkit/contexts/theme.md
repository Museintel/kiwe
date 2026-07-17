# Kiwe context: DSA AppShell theme only

Kiwe DSA/AppShell theme package and preview. No website/page build.

## Important boundary

Use the bundled contracts. Do not ask for or read the full Kiwe/DSA plugin codebase.

Do not create cart, checkout, auth, save, search, AI, service-worker, history, focus, or WooCommerce authority.

# AppShell theme.json quick contract

If your output includes `appshell-theme/import/[theme-id]/theme.json`, copy this shape and only change values that are clearly marked as theme-specific.

Do not invent alternate manifest keys.

Important:

- Use `schema`, not `type`.
- Do not use `schemaVersion` in AppShell theme manifests. `schemaVersion` is only used by optional Kiwe settings profiles.
- Do not use nested `contracts`, `colorAuthority`, `authority`, `supportedPresentationModes`, `supportedDockShapes`, `cssFiles`, or object-form `supports`.
- `supports` must be an array of allowed strings.
- `screens` must use Kiwe screen names only, and should match the brief/settings. Do not list cart/checkout/profile by default for a non-commerce or non-membership website just because those screens exist.
- For combined website/page + AppShell handoffs, a news/editorial default is usually `search`, `menu`, `saved`, `links`, `notifications`, `ios-install`, and `ai`. Add `cart`/`checkout` only for commerce, WooCommerce, shop, products, paid reports, subscriptions, or checkout. Add `profile` when account, membership, login, personalization, orders, downloads, or addresses are truly part of the brief.

```json
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
```

If a theme does not cover a screen, remove that screen from `screens`. Do not add unsupported screen names such as `orders`, `downloads`, `addresses`, or `install`; those are payload sections or concepts inside supported screens, not theme-manifest screen IDs.


# AppShell preview quick contract

If your output includes `appshell-theme/preview/index.html`, it must prove the theme against Kiwe's actual preview selectors and Geometry Engine states. A pretty mock phone is not enough.

Minimum preview shell requirements:

- Include a root with `data-dsa-surface`.
- Include `data-dsa-ui-contract="2"`.
- Include `data-dsa-dock-presentation` and demonstrate dock plus navigation bar values; use `navbar` for the navigation-bar runtime value.
- Include `data-dsa-dock-orientation`.
- Include Geometry Engine variables in the preview markup/style:
  - `--dsa-dock-control-size`
  - `--dsa-dock-only-reserve`
  - `--dsa-screen-block-reserve`
- If `supports` includes `split-dock`, include `dsa-dock-split`.
- If `supports` includes dock shapes, demonstrate:
  - `dsa-dock-shape-pill`
  - `dsa-dock-shape-box`
  - `dsa-dock-shape-square`
- If `supports` includes dark mode, include `data-kiwe-theme="dark"`.
- Link the importable CSS from `../import/[theme-id]/css/theme.css`; the preview must demonstrate the real import CSS.
- Keep preview controls outside the app viewport, preferably using `kiwe-preview-toolbar` and `kiwe-preview-stage`.

Required screen selectors when the theme manifest lists these screens:

- `profile`: `data-dsa-profile-panel`
- `cart`: `data-dsa-cart-panel` and `data-dsa-cart-fbt-rail`
- `checkout`: `data-dsa-checkout-panel` and `data-dsa-checkout-form`
- `search`: `data-dsa-search-panel`, `data-dsa-search-form`, `data-dsa-search-input`, and `data-dsa-search-results`
- `menu`: `dsa-menu-panel`
- `saved`: `data-dsa-saved-panel`
- `links`: `dsa-links-panel`
- `notifications`: `data-dsa-notification-panel`
- `ios-install`: `data-dsa-ios-install-panel`
- `games`: `data-dsa-game-panel`
- `ai`: `data-dsa-ai-panel`

Cart FBT must be a horizontal rail. Include `data-dsa-cart-fbt-rail` on that rail. Do not render it as a stacked list.

Links site score is optional. The preview and README must show/document both:

- score present; and
- score absent/no score/without score, where no badge is rendered at all.

Combined website/page + AppShell previews must match the site type. A news/editorial website should not automatically show cart, checkout, orders, downloads, or addresses unless the brief/settings include commerce or membership. It is good to innovate with existing `ai` and `notifications` screens, but only as presentation over Kiwe-owned payloads/actions.

Responsive fit is mandatory. Check narrow mobile widths around 320px, 360px, and 390px. No sheet/screen may create horizontal page or panel scroll except intentional rails such as FBT, alphabet/search filters, or another documented horizontal rail. Decorative header stripes, badges, labels, and pseudo-elements must shrink, wrap, clip inside the panel, or stack; do not use non-shrinking flex decorations that force the panel wider than the viewport.

The Geometry Engine owns AppShell placement and measurement. Importable theme CSS must not assign core geometry to dock, sheet, screen, or backdrop selectors. Do not set `position: fixed`, `position: absolute`, `inset`, `top`, `right`, `bottom`, `left`, hardcoded `z-index`, `width: 100vw`, `height: 100vh`, or hardcoded viewport offsets on `[data-dsa-dock]`, `.dsa-dock`, `[data-dsa-screen]`, `.dsa-panel`, `.dsa-sheet`, `[data-dsa-screen-backdrop]`, or sheet/screen backdrop selectors. Those values belong in Kiwe core or preview-only CSS. Theme CSS may style color, typography, border, radius, shadow, spacing inside content, icons, badges, cards, buttons, and state appearance while consuming Geometry Engine variables.

The AppShell handoff README must include:

- distinctness note / visual thesis;
- screen coverage summary;
- shell mode coverage summary;
- selector-fit checklist;
- intentional limitations;
- core/plugin changes section, including "no core/plugin changes" when true;
- Seam AppShell adoption map acknowledgement;
- validation commands.


# Kiwe UI System Brain

This folder is the portable UI-authoring brain for Kiwe DSA themes. It is intentionally small enough to hand to a designer, agency, or AI assistant without exposing the whole plugin codebase.

The core rule: Kiwe owns capabilities and authority; themes own arrangement and presentation.

Themes may rearrange Profile, Orders, Addresses, Downloads, Saved, Cart, Search, Menu, Trust, badges, and rails. Themes must not create a second cart, checkout, auth, PhoneKey, payment, history, focus, service-worker, or Bricks query authority.

Framework handoff lives next to this folder at `framework-system/`. Use `ui-system/` when the assignment is a Kiwe DSA AppShell theme. Use `framework-system/` when the assignment is Kiwe/Seam framework usage for WordPress pages, Bricks variables/classes, universal tokens, or framework extension.

## Current status

Batch 1 created this portable UI brain as contract-only reference.

Batch 2 began the runtime proof with Profile only. The production Profile module has a tiny visual-profile adapter registry: Legacy remains the fallback/default renderer, and Kiwe 2027 can rearrange the same Profile/account capabilities without creating new account, PhoneKey, WooCommerce, or notification authority.

Batch 3 extends the same pattern to Menu and Saved in `assets/js/modules/surface-panels.js`. Legacy remains fallback/default. Kiwe 2027 may rearrange site menu links, page table-of-contents, saved wishlist items, bookmarks, empty states, and saved metrics while preserving the existing click/navigation/remove selectors.

Batch 4 extends the adapter proof to Cart in `assets/js/modules/commerce-panels.js` without touching Checkout/payment. Legacy remains fallback/default. Kiwe 2027 may rearrange cart summary, item stack, discount summary, FBT rail, trust badges, empty state, and checkout CTA while preserving the existing quantity, FBT add/claim, full navigation, and checkout-open selectors.

Batch 5 extends the adapter proof to the Search shell in `assets/js/surface.js`. Legacy remains fallback/default. Kiwe 2027 may rearrange the Search title, status, input, filters, alphabet rail, and results container while `assets/js/search.js` remains the behavior authority for DSA Search REST, Bricks Filter Search bridge reconciliation, quick-add, filters, alphabet, and result rendering.

Batch 6 extends the adapter proof to Links, notification preferences, and the iOS install guide. Legacy remains fallback/default. Kiwe 2027 may rearrange Links identity/social/commerce/posts/review/trust blocks, notification topic/channel/category/app controls, and iOS install steps while preserving the existing edit/upload/cart/social/preference/platform/save/install selectors.

Batch 7 extends the adapter proof to the AI panel, inbox, and report presentation in `assets/js/modules/ai-panel.js`. Legacy remains fallback/default. Kiwe 2027 may rearrange AI title, signal inbox, empty state, read/unread summary, report grid, and disabled chat placeholder while preserving the existing AI insight action/dismiss, notification dismiss, tray toggle, status, swipe, and popout-owned selectors.

Batch 8 adds the first runtime theme registry in `assets/js/surface.js` as `window.DSA.ui`. It exposes the active contract id, adapter version, active visual profile, adapter-ready screen ids, current Active/Hover color model, and small helpers for profile-aware payloads. This is intentionally tiny: it gives future theme folders one canonical browser bridge without moving cart, checkout, PhoneKey, Bricks, geometry, or lifecycle authority into theme code.

Batch 9 defines the first marketplace package boundary. `theme-manifest.schema.json` now describes a `kiwe.surface-theme.v1` package with scoped CSS/assets, required UI/token contracts, supported presentation modes, budgets, and forbidden authority classes. `marketplace-package.md` documents import/export rules, rejection checks, the `window.DSA.ui` bridge, and the designer handoff process.

Batch 10 adds the first source-level package validator at `tools/ui-theme/validate-package.cjs`. It checks a proposed theme folder for `theme.json`, required contracts, allowed screens, safe relative CSS/assets, CSS budget, forbidden executable/document files, remote CSS imports/URLs, and unlisted files. A safe fixture package lives at `tools/ui-theme/fixtures/valid/`.

Batch 11 adds the standalone preview handoff contract. `preview-handoff.md` tells designers how to include a viewable HTML preview without mixing preview placeholders, simulator code, fake geometry, or debug labels into the importable theme. `preview/standalone-preview.example.html` provides a minimal app-viewport skeleton with preview controls outside the Surface, production-style `data-dsa-*` attributes, and Geometry Engine variables. `screen-payloads.json` now also documents the Games surface selectors and authority boundary. Dock support is explicit: themes may declare full compact dock, split compact dock, and navigation-bar support, and previews must show split dock with the same `dsa-dock-split` and `is-split-*` runtime classes production emits.

Batch 12 adds the Legacy UI review handoff at `handoffs/legacy-ui-review/`. It gives outside UI reviewers a neutral standalone preview, a baseline manifest, placeholder notes, and a review brief without revealing known issues or asking them to rewrite core behavior. This creates the same review loop for the built-in Legacy profile that marketplace themes use: validate the package boundary, inspect the preview, report visual issues, and separate CSS-level fixes from core/runtime requests.

Batch 17 graduates the built-in modern profile from prototype language to `Kiwe 2027`. Existing saved `prototype` values remain compatibility aliases, but new runtime payloads and admin settings use `kiwe2027`. Links site score is now optional core data: a blank admin score is emitted as no score and the badge must be omitted by all themes. Dock modes are also explicit UI-brain rules: full compact dock, split compact dock, and Navigation bar are core shell modes that themes may style but must not reinterpret or allow page content to bleed through.

Batch 18 makes Dock shape a first-class shell state instead of a vague styling hint. Admin exposes Pill, Rounded box, and Square / no radius. The renderer normalizes older `rounded` settings to Pill for compatibility, while the CSS publishes `--dsa-dock-shell-radius`, `--dsa-dock-control-radius`, and `--dsa-dock-segment-radius` so Legacy, Kiwe 2027, and future themes can honor the same shape controls. Theme handoffs should preview all claimed dock modes and shape modes.

Batch 19 records the first external-AI theme intake audit at `theme-intake-audit-2026-07-15.md` and tightens `prompt.md` after multiple "ultra-modern" outputs converged on Aurora/glass/frosted-card patterns. Future handoffs must now include a distinctness note, selector-fit checklist, local preview placeholders, absent optional-data states, and a clear separation between CSS-only theme work and core/adapter-profile requirements.

Batch 20 begins the Seam framework merge. `assets/css/seam.css` is now the production-safe, opt-in Seam class/attribute layer for WordPress pages and future Bricks exports. `includes/Design/Seam_Vocabulary_Schema.php`, `seam-vocabulary.md`, and `seam-vocabulary.json` define the canonical vocabulary: roles, flows, tones, scenes, states, motion, shapes, body classes, and reserved behavior attributes. The old Seam runtime ideas remain reserved; Batch 20 does not create a second cart/auth/checkout/page-history authority.

Batch 21 exports Seam into Bricks without making Bricks the framework authority. The existing Kiwe token export now also publishes curated Seam global classes/categories to Bricks 2.4 global class storage while Kiwe continues to ship `assets/css/seam.css` as the portable source of styling. Attribute/class parity was tightened for gap, alignment, justification, and hidden state helpers so page builders, AI handoffs, and raw HTML can use the same vocabulary.

Batch 22 adds the safe Seam runtime at `assets/js/seam.js`. It exposes `window.Seam.ready`, vocabulary-aware query/closest helpers, `setAttr`, `setState`, `toggleState`, `hasState`, and `describe`. It deliberately does not revive the old Seam binding/action system in production and does not own cart, checkout, auth, PhoneKey, service workers, page history, focus traps, or DSA surface lifecycle.

Batch 23 starts Kiwe DSA adoption through a protected shadow contract. Rendered DSA panel roots now receive `data-seam-*` metadata (`data-seam-role`, `data-seam-flow`, `data-seam-tone`, `data-seam-scene`, and `data-seam-surface-panel`) after render/update. Public page authors can use normal Seam classes and attributes; Kiwe's own panels use `data-seam-*` first so the framework brain can identify them without accidentally applying generic page-level Seam CSS to live sheets/screens.

Batch 24 extends that shadow contract into stable DSA interior landmarks. After each render/update, the runtime annotates existing title blocks, FBT rails, cart items, saved grids, forms, fields, media, badges, prices, action rows, CTAs, trust rows, and game stages with protected `data-seam-*` metadata. This does not change visual styling or behavior selectors; it gives Seam, validators, Bricks/page handoffs, and AI reviewers a reliable semantic map of the live AppShell.

Batch 25 adds the first landmark discovery API. `window.Seam.landmarks(scope?, filters?)` returns public Seam and protected Kiwe shadow landmarks; `window.DSA.ui.seam.landmarks()` scopes the same idea to the active AppShell panel. `window.Seam.describe()` and `window.DSA.ui.seam.activePanel()` provide small semantic summaries for tooling, previews, diagnostics, and AI/theme review. The debug-only Seam linter now also catches unknown `data-seam-*` shadow attributes and Kiwe roots missing a shadow role.

Batch 26 tightens the offline marketplace validator. `tools/ui-theme/validate-package.cjs` now reads `seam-vocabulary.json` and the production Seam CSS class list. Importable theme CSS fails when it uses unknown `.seam-*` classes, invalid public Seam attribute values, or protected Kiwe `data-seam-*` shadow selectors. Regression fixtures under `tools/ui-theme/fixtures/invalid-seam-*` prove the failure cases.

Batch 27 adds whole-handoff validation at `tools/ui-theme/validate-handoff.cjs`. It runs the import-package validator and then checks `preview/index.html` plus `preview/PLACEHOLDERS.md` for Kiwe geometry attributes, dock modes, dock shape states, screen selectors, FBT rail proof, placeholder documentation, and forbidden remote/network/service-worker/payment behavior. The Legacy UI review handoff now passes this stricter preview contract.

Batch 28 adds review-quality gates to the whole-handoff validator. A returned AI/designer handoff must now include a root README with a distinctness note / visual thesis, screen and shell mode coverage, selector-fit checklist, validation instructions, intentional limitations, and explicit core/plugin change separation. The Legacy UI review README now models these required sections.

Batch 29 starts real DSA adoption of public Seam classes, but only where visual risk is low. The runtime now adds public `seam-eyebrow`, `seam-caption`, `seam-price`, and matching tone classes to existing DSA eyebrow, caption, and price landmarks while keeping cards, buttons, inputs, media, badges, dock geometry, checkout, and sheet layout on protected `data-seam-*` shadow metadata until visual parity is proven.

Batch 30 adds a machine-readable Seam adoption map to the canonical vocabulary contract. `appShellAdoption` now tells runtime code, validators, handoff AIs, and reviewers whether a role is `public-adopted`, `shadow-only`, or `authority-only` inside Kiwe DSA internals. `window.Seam.adoption(role?)` and `window.DSA.ui.seam.adoption(role?)` expose the same decision in the browser. This intentionally blocks premature public-class adoption for cards, buttons, inputs, media, badges, nav, actions, forms, fields, and modals because those classes still carry layout/shape/spacing risk inside live sheets/screens.

Batch 31 adds the Seam adoption audit command at `tools/ui-theme/audit-seam-adoption.cjs` and requires handoff READMEs to acknowledge the AppShell adoption map. The audit checks that DSA runtime does not attach shadow-only public classes, that public-adopted classes are actually applied, and that shadow-only reasons remain tied to real high-risk Seam CSS declarations. This makes the framework brain testable instead of relying on memory.

Batch 32 adds the final integration proof note at `integration-proof-2026-07-16.md` and aligns the marketplace, preview, and Kiwe 2027 reference docs with the current Seam adoption bridge. The proof file lists the commands that must pass before the framework track moves into release prep, including runtime syntax checks, PHP Seam vocabulary linting, Seam adoption audit, package/handoff validation, invalid-fixture rejection, and MU manifest verification.

Batch 33 completes release prep for the Seam/Kiwe Framework track. `Kiwe > Tokens` is now `Kiwe > Framework` because the admin action pushes variables, the Kiwe Universal palette, and Kiwe Seam global classes/categories into Bricks. The legacy `kiwe-tokens` admin slug redirects to the Framework page. The root MU loader and nested package are bumped to `0.5.75`, the changelog records the framework track, and the package manifest is rebuilt for folder-based MU deployment.

Batch 34 adds `framework-system/` as the portable Kiwe Framework handoff folder. It contains curated Seam vocabulary, token map, runtime snapshots, Bricks capability docs, framework prompt, source map, and reference audit tooling so web developers can use Kiwe/Seam without receiving the whole plugin. `ui-system/` remains the AppShell theme brain; `framework-system/` is the framework brain.

Batch 35 tightens the framework after external developer review and AI website/page-output audits. `window.Seam` now validates against both nested and flat vocabulary contracts, mirrors the `collapsed` state class, and ships as 0.3.1. The Seam CSS no longer has a self-referential scene-intensity fallback, body heading identity classes now affect scene headings, and the debug linter flags shadow-only public Seam classes placed inside live Kiwe roots. `framework-system/HANDOFF-LITE.md` defines the smaller file set to give ordinary web developers/AIs, while the framework prompt and Bricks notes now explicitly separate Seam-built website/page work from Kiwe AppShell theme work. Seam is optional/additive for website pages; Kiwe AppShell themes remain `ui-system/`; DSA/Woo/Bricks behavior authority remains core-owned. Bricks 2.4 beta's `includes/html-to-bricks` converter is now documented as a real handoff target for standalone website previews.

Batch 36 adds `framework-system/handoffs/website-builder/` as the one-folder website/page handoff. It copies only the practical framework prompt, handoff-lite instructions, token/vocabulary contracts, runtime CSS/JS, and Bricks capability docs needed by an AI/web developer to build a normal website/page with Kiwe/Seam. This avoids giving the full framework source/reference folder for ordinary website work while keeping AppShell theme work separate in `ui-system/`.

Batch 37 makes Seam roles semantic/headless by default after website-output testing showed that high role usage produced repetitive boxed layouts. `data-role="card"` and `.seam-card` now identify meaning without forcing padding, background, border, shadow, radius, flex layout, gap, or color. The temporary recipe idea has been removed entirely for now: no recipe-prefixed classes ship in core and no recipe classes are exported to Bricks. Bricks export now focuses on semantic roles, neutral flows, states, tones, motion, explicit shape utilities, and universal Kiwe/Seam tokens. The vocabulary/contracts explain that real website art direction should live in site CSS/classes backed by Kiwe/Seam tokens, and reusable missing patterns should become generic framework additions rather than project-locked classes. AppShell shadow adoption remains protected for isolation from site CSS, not because core role classes are visually heavy.

Batch 38 adds the Seam Class Vocabulary: a neutral/searchable Bricks class library with 21 Kiwe Seam categories and 276 generic class handles. It covers core roles, content, commerce, navigation, disclosures, tables/data, media, forms, sizes, density, emphasis, placement, aspect, flow controls, and utilities. These names are pushed from `Kiwe > Framework` so Bricks designers can search, add, and style classes such as `seam-card`, `seam-accordion`, `seam-table`, `seam-size-xl`, and `seam-density-spacious`. They are naming infrastructure, not visual recipes.

Next release-prep steps:

1. Live Hostinger upload/proof after the rebuilt MU package is deployed.

## Files

- `theme-manifest.schema.json` - the shape of a future theme folder.
- `prompt.md` - the prompt to give an AI along with this folder when requesting a new theme.
- `HANDOFF-MODES.md` - explains website-only, DSA-theme-only, and combined website + AppShell theme assignments.
- `marketplace-package.md` - import/export, acceptance, and designer handoff rules.
- `preview-handoff.md` - standalone HTML preview rules and layer separation.
- `theme-intake-audit-2026-07-15.md` - first external-AI theme intake findings and prompt corrections.
- `integration-proof-2026-07-16.md` - final framework-track proof checklist before release prep.
- `seam-vocabulary.md` - human-readable Seam vocabulary and authority rules.
- `seam-vocabulary.json` - machine-readable Seam vocabulary for validators, builders, and AI handoffs.
- `seam-class-vocabulary.md` - human-readable neutral class library for Bricks/global-class authoring.
- `seam-class-vocabulary.json` - machine-readable searchable class vocabulary pushed to Bricks.
- `screen-payloads.json` - what screens can consume and rearrange.
- `bricks-capabilities.json` - Bricks hooks, dynamic tags, builder controls, and safe design boundaries.
- `token-map.css` - the universal token aliases theme authors should use.
- `tokens-reference.md` - explains why the portable token map is curated and how to request/promote missing tokens.
- `slots.md` - stable slots/data attributes and what they mean.
- `budgets.md` - payload, motion, paint, authority, and interaction constraints.
- `adapters/adapter-contract.js` - function signatures and helper expectations.
- `adapters/profile.kiwe-2027.example.js` - example Profile layout using existing Profile capabilities.
- `profiles/legacy.css` - compact reference for the built-in low-tax Legacy profile and shared dock shape variables.
- `profiles/kiwe-2027.css` - scoped profile styling example for the built-in modern profile.
- `preview/standalone-preview.example.html` - copyable preview shell skeleton; not importable theme code.
- `handoffs/legacy-ui-review/` - reviewer bundle for auditing the built-in Legacy UI profile.

## htmx and Alpine

Use htmx only for server-owned fragments where WordPress remains authoritative.

Use Alpine only for local presentation state such as tabs, disclosure, preview toggles, and temporary local UI.

Do not use htmx or Alpine for PhoneKey/auth, checkout/payment, cart reconciliation authority, service-worker policy, navigation history, focus trapping, or the Surface lifecycle.

## Color model

For now, site owners control the primary brand state through Active and Hover colors. The theme system must consume the universal `kiwe-*` tokens and compatibility `dsa-*` aliases. A richer palette can be added later without breaking theme folders.

## Package validation

From the repository root:

```bash
node tools/ui-theme/validate-package.cjs path/to/theme-folder
```

The validator is intentionally conservative. Passing it does not mean a design is visually approved; failing it means the package crossed the first marketplace safety boundary.


# Prompt for designing a Kiwe DSA AppShell theme

You are designing a new visual theme for Kiwe DSA, a WordPress MU plugin that turns a normal WordPress/WooCommerce site into an AppShell-style surface. The theme is for the Kiwe Surface/AppShell that sits on top of the website: dock, sheets/screens, module panels, cart surface, search surface, profile/account surface, Links hub, saved items, notifications, iOS install guide, games shell, and AI shell.

First read this entire `ui-system/` folder before designing. Treat this folder as the UI brain and contract source. Do not assume access to the full plugin codebase.

## How to navigate this folder

Read files in this order:

1. `README.md` to understand the theme system and current built-in profiles.
2. This `prompt.md` to understand the assignment and output requirements.
3. `HANDOFF-MODES.md` if the assignment asks for both a website/page and a DSA AppShell theme.
4. `screen-payloads.json` and `slots.md` to learn what screens, payload fields, selectors, and slots exist.
5. `token-map.css`, `tokens-reference.md`, and `budgets.md` to understand the token system, the difference between stable theme tokens and internal runtime variables, geometry variables, size limits, motion limits, and low-tax performance expectations.
6. `preview-handoff.md` to learn exactly how to build the standalone preview.
7. `theme-manifest.schema.json` and `marketplace-package.md` to build the importable package correctly.
8. `profiles/legacy.css` and `profiles/kiwe-2027.css` to understand the current built-in baseline styles.
9. `bricks-capabilities.json` to understand Bricks/dynamic tag capabilities and boundaries.
10. `seam-vocabulary.md`, `seam-class-vocabulary.md`, `seam-vocabulary.json`, and `seam-class-vocabulary.json` to understand public Seam roles/classes, the protected Kiwe `data-seam-*` shadow contract, and the `appShellAdoption` map that says which Seam roles are safe as public classes inside live DSA screens.
11. `handoffs/legacy-ui-review/` as an example of a review/preview handoff.

## Goal

Create a new Kiwe DSA AppShell visual theme in the requested visual style, for example neumorphism, glassmorphism, editorial luxury, playful commerce, minimalist SaaS, etc.

Your theme may change the look, layout, hierarchy, spacing, material, typography, icon treatment, badge style, card style, and screen arrangement. It must not create new runtime authority.

If the assignment asks for a website/page and a DSA AppShell theme together, follow `HANDOFF-MODES.md`: keep website/page files, AppShell theme package, standalone previews, and optional Kiwe settings/profile JSON in separate folders.

## Originality requirement

Do not treat "modern" or "ultra-modern" as permission to default to generic glassmorphism, Aurora gradients, floating blurred cards, or Bento dashboards. Those directions are allowed only when the site owner explicitly asks for them.

Before writing files, choose a distinct design thesis and name. The thesis must explain how the theme differs from both built-in references:

- Legacy: the lowest-tax compact baseline.
- Kiwe 2027: the current modern/prototype-style profile.

If the requested style is broad, such as "ultra modern", avoid the overused names and concepts `Aurora`, `Glass`, `Flow`, `Bento`, `Neon`, and `Neo` unless the site owner requested that exact direction. Prefer a specific commerce/appshell concept, for example editorial catalog, tactical dashboard, soft utility, high-contrast retail, calm healthcare, luxury concierge, kinetic cards, quiet OS, etc.

Your final handoff must include a short "distinctness note" in `README.md` covering:

1. The visual thesis.
2. What it deliberately does differently from Legacy.
3. What it deliberately does differently from Kiwe 2027.
4. Whether it uses blur/glass; if yes, why it is not merely another frosted-card theme.

The root handoff `README.md` is machine-checked. It must include clearly named sections or unmistakable text for:

- distinctness note / visual thesis
- screen and shell mode coverage
- selector-fit checklist
- intentional limitations
- core/plugin changes, including "no core/plugin changes" when none are needed
- validation commands for both package and full handoff validation

The output must let the site owner do two things:

1. Open a standalone `preview/index.html` and visually review the theme with realistic placeholder content before installing anything.
2. Install or validate only the safe importable package inside `import/your-theme-id/`.

## Files you must use

- `README.md` for the current state of the theme system.
- `theme-manifest.schema.json` for the importable theme package shape.
- `marketplace-package.md` for import/export and acceptance rules.
- `preview-handoff.md` for how to build a viewable HTML preview.
- `screen-payloads.json` for the screens, payload fields, and selectors themes may consume.
- `slots.md` for stable `data-dsa-*` slots and runtime ownership.
- `token-map.css` for universal tokens and compatibility aliases.
- `tokens-reference.md` for what is intentionally included or excluded from the portable token map.
- `budgets.md` for size, paint, motion, and authority constraints.
- `bricks-capabilities.json` for Bricks hooks, dynamic tags, builder controls, and boundaries.
- `profiles/legacy.css` to understand the low-tax Legacy baseline.
- `profiles/kiwe-2027.css` to understand the built-in modern profile direction.
- `adapters/adapter-contract.js` and `adapters/profile.kiwe-2027.example.js` for adapter expectations.
- `handoffs/legacy-ui-review/` for an example review handoff.

## Non-negotiable rules

Do not create or simulate a second authority for:

- cart, checkout, payment, WooCommerce mutation, discounts, or order ownership
- auth, PhoneKey, profile update, logout, addresses, downloads, or account ownership
- Search query authority, Bricks query authority, Bricks dynamic tag resolution, or filters
- service worker, PWA install, cache policy, browser history, focus trap, or scroll lock
- AI actions, notification permission, Push subscription, or privacy/master switches

Themes are presentation only. Kiwe owns capability, state, lifecycle, security, and commerce.

## Required shell modes

Your theme must account for all core shell states it claims in `theme.json`:

- Sheet and Classic presentation.
- Full compact dock.
- Split compact dock.
- Navigation bar.
- Horizontal and vertical dock orientation.
- Light and dark mode.
- Narrow, compact, and wide layout states.
- Reduced motion.

Dock shape is a real core setting. If your theme supports dock styling, preview and support:

- `dsa-dock-shape-pill`
- `dsa-dock-shape-box`
- `dsa-dock-shape-square`

Use the runtime variables:

- `--dsa-dock-shell-radius`
- `--dsa-dock-control-radius`
- `--dsa-dock-segment-radius`

Do not hardcode one dock radius and ignore admin shape controls.

## Screen rules

Preserve all required selectors from `screen-payloads.json` and `slots.md`.

Use the Seam adoption map correctly:

- Public WordPress/Bricks page sections may use the normal public Seam vocabulary.
- Importable AppShell theme CSS may style existing DSA selectors and public Seam classes.
- Do not add protected `data-seam-*` attributes to theme markup or CSS.
- Do not assume high-impact classes such as `seam-card`, `seam-button`, `seam-input`, `seam-media`, `seam-badge`, `seam-nav`, `seam-actions`, `seam-form`, `seam-field`, or `seam-modal` are safe to attach to live DSA internals. Check `appShellAdoption.shadowOnly` first.
- If you believe a shadow-only role should become public-adopted, document that as a proposed core change. Do not silently depend on it in the importable theme.

Important examples:

- FBT / frequently bought together must remain a horizontal side-scrolling rail. You may style cards, but do not turn the rail into a stacked list or static grid.
- Links site score is optional. If no score exists in the payload, omit the score badge entirely. Do not render `0`, a blank score card, or placeholder score text.
- Menu page table of contents must preserve anchor selectors and be clickable.
- Top-level screen title tag is Theme-owned through Kiwe > Theme, but nested product/content/report headings keep their own semantics.
- Checkout CTA and AI chat placeholder must flow with panel content and must not float over products/messages.
- Sheet scrollbars must not visually cut into rounded sheet corners.
- No content may render under the dock, navigation bar, safe area, or browser chrome reserve.

Do not invent new structural wrapper classes and then claim the theme needs no core change. If your CSS depends on a class, attribute, or wrapper that is not listed in `screen-payloads.json`, `slots.md`, `preview-handoff.md`, or the built-in profile references, call it out as a core/plugin change instead of silently using it in the importable CSS.

If you use Seam framework selectors, use only the published public vocabulary:

- public classes such as `.seam-card`, `.seam-grid`, `.seam-tone-brand`, `.seam-scene-elevated`, `.seam-gap-md`
- public attributes such as `[data-role="card"]`, `[data-flow="grid"]`, `[data-tone="brand"]`

Do not style or depend on protected Kiwe shadow attributes such as `[data-seam-role]`, `[data-seam-slot]`, or `[data-seam-surface-panel]` in importable theme CSS. Those attributes are runtime metadata for tooling and diagnostics, not theme styling hooks.

Every preview must include a selector-fit checklist in its `README.md`:

- Which stable `.dsa-*` classes and `data-dsa-*` selectors are styled.
- Which optional selectors are demonstrated.
- Whether any desired layout would require a future adapter-profile or core wrapper.
- Confirmation that FBT remains horizontal, score can be hidden when absent, dock shape controls visibly change the dock, and sheet content does not pass beneath the dock.

## Color and tokens

For now, site owners control the main color model through Active and Hover colors.

Use tokens and aliases from `token-map.css`. Do not create a hidden large palette that cannot map back to Kiwe tokens. You may propose future palette controls separately, but the importable theme must work with current Active/Hover color authority.

## Output format

Return a complete handoff folder shaped like this:

```text
theme-handoff/
  README.md
  import/
    your-theme-id/
      theme.json
      css/theme.css
      assets/optional-static-assets-only.svg
      README.md
  preview/
    index.html
    PLACEHOLDERS.md
```

Only the `import/your-theme-id/` folder is importable. The preview is for humans only.

Do not return only screenshots, a Figma-style description, or a single CSS file. The required output is a complete handoff folder with both an importable package and a standalone preview.

## Importable package rules

The importable theme package may contain:

- `theme.json`
- listed CSS files
- listed static image assets
- optional human README

It must not contain:

- JavaScript, TypeScript, PHP, HTML, WASM, remote fonts, remote scripts, trackers, service workers, or executable files
- remote `@import`, remote `url()`, data URLs, or JavaScript URLs in CSS
- preview-only mocks, simulator code, or placeholder product/account data

Importable theme CSS must not own AppShell geometry. Kiwe's Geometry Engine owns dock, sheet, screen, and backdrop placement and measurement. Do not set `position: fixed`, `position: absolute`, `inset`, `top`, `right`, `bottom`, `left`, hardcoded `z-index`, `width: 100vw`, `height: 100vh`, or hardcoded viewport offsets on `[data-dsa-dock]`, `.dsa-dock`, `[data-dsa-screen]`, `.dsa-panel`, `.dsa-sheet`, `[data-dsa-screen-backdrop]`, or sheet/screen backdrop selectors. Those properties belong in core or preview-only CSS. Theme CSS may style color, typography, border, radius, shadow, inner spacing, icons, badges, cards, buttons, and state appearance while consuming Geometry Engine variables.

## Preview rules

The preview must be viewable as standalone HTML, but preview code is not the theme.

Preview controls must live outside the app viewport. Do not overlay preview controls on top of the dock, sheet, checkout CTA, FBT rail, or AI chat.

The preview must include placeholder content, but placeholders must be preview-only and documented in `preview/PLACEHOLDERS.md`. Placeholder product images, account names, order counts, score values, social links, search results, cart totals, trust badges, and AI signals must not appear in the importable package.

Preview placeholders must be local or pure CSS/HTML. Do not use remote image URLs, remote fonts, analytics, third-party scripts, or live network resources in the preview. If you need photography, use neutral local SVG/CSS placeholders and document them.

Build the preview like this:

- `preview/index.html` contains the preview shell, mock data, optional preview-only controls, and realistic placeholder markup.
- Link the importable CSS from `../import/your-theme-id/css/theme.css` so the preview demonstrates the actual theme CSS.
- Use production-style attributes such as `data-dsa-surface`, `data-dsa-ui-contract="2"`, `data-dsa-dock-presentation`, `data-dsa-dock-orientation`, `data-dsa-layout`, `data-dsa-density`, and the required screen selectors.
- Use Geometry Engine variables such as `--dsa-dock-control-size`, `--dsa-dock-ai-size`, `--dsa-dock-only-reserve`, `--dsa-screen-block-reserve`, and the dock shape radius variables.
- Include preview controls for switching at least light/dark, sheet/classic, viewport size, full compact dock, split compact dock, Navigation bar, and dock shape if the theme claims support for those modes.
- Keep all preview controls outside `.dsa-surface`.

The preview should demonstrate:

- Every screen listed in `theme.json`, and only those screens unless the README explicitly explains why an optional capability is being shown.
- Profile/account only when membership, login, account, or personalization is part of the brief/settings.
- Orders/downloads/addresses/password/profile actions only if represented by the theme and relevant to the appsite.
- Cart with quantity controls, checkout CTA, trust badges, and FBT rail only when commerce, WooCommerce, shop, products, paid reports, subscriptions, or checkout are part of the brief/settings.
- Search with filters/alphabet/results
- Menu with page table of contents
- Links/social hub with optional score hidden and shown states
- Saved
- Notifications when notification preferences, alerts, updates, briefs, or push/PWA journeys are part of the brief/settings
- iOS install
- AI/inbox/report shell
- Dock modes and dock shape modes
- Sheet/classic, light/dark, narrow/compact/wide

Use natural placeholder data. Do not fill the UI with debug labels.

For combined website/page + AppShell handoffs, match the AppShell screens to the website type. A news/editorial website should not automatically show cart, checkout, orders, downloads, or addresses just because the prompt says "Netflix-like"; only include commerce/account screens when the brief, Kiwe settings profile, or site business model requires them. If a theme supports an optional screen but the current combined preview hides it, document that in the README/settings notes.

Responsive fit is a hard quality gate. The standalone preview must be checked at narrow mobile widths around 320px, 360px, and 390px. No DSA sheet/screen may create horizontal page or panel scrolling unless the element is an intentional rail such as FBT, alphabet/search filters, or another documented horizontal rail. Decorative header stripes, badges, labels, and pseudo-elements must shrink, wrap, clip inside the panel, or stack; do not use non-shrinking flex decorations that can force the panel wider than the viewport.

Also demonstrate "absent optional data" states. At minimum, include a Links screen state where site score is missing and therefore not rendered at all.

## Validation expectation

The theme should be able to pass:

```bash
node tools/ui-theme/validate-package.cjs path/to/theme-handoff/import/your-theme-id
```

The complete handoff should also pass:

```bash
node tools/ui-theme/validate-handoff.cjs path/to/theme-handoff
```

The current AppShell Seam adoption boundary should also remain clean:

```bash
node tools/ui-theme/audit-seam-adoption.cjs
```

The validators now also check Seam usage. Unknown `.seam-*` classes, invalid public Seam attribute values, protected `data-seam-*` selectors in importable CSS, and missing handoff acknowledgement of the `appShellAdoption` map fail validation.

Passing validation is not visual approval; it only means the first safety and preview-contract boundaries were not crossed.

## Final answer to the site owner

When you return the theme, include:

1. What visual style you implemented.
2. What screens and shell modes are covered.
3. The distinctness note.
4. The selector-fit checklist.
5. Any intentional limitations.
6. How to validate the import folder.
7. Any core/plugin changes you believe would be needed, clearly separated from theme CSS.


# Standalone Preview Handoff Contract

Theme handoffs may include a standalone HTML preview, but the preview is not the theme.

The importable theme remains only:

- `theme.json`
- listed CSS files
- listed static image assets
- optional human README

The preview exists so a site owner, designer, or AI assistant can look at the theme before installing it. Preview markup, preview JavaScript, mock payloads, placeholder images, and preview controls must never be copied into the importable theme package.

## Recommended handoff shape

```text
theme-handoff/
  README.md
  import/
    theme-id/
      theme.json
      css/theme.css
      assets/optional.svg
      README.md
  preview/
    index.html
    PLACEHOLDERS.md
```

Validate the importable package first:

```bash
node tools/ui-theme/validate-package.cjs theme-handoff/import/theme-id
```

When reviewing a complete AI/designer handoff, validate the whole handoff too:

```bash
node tools/ui-theme/validate-handoff.cjs theme-handoff
```

The handoff validator runs the import-package validator and then checks the standalone preview for required Kiwe geometry attributes, dock modes, dock shape states, screen selectors, FBT rail proof, placeholder documentation, and forbidden remote/network/service-worker/payment behavior.

It also checks the root handoff `README.md` for review-quality sections: distinctness note / visual thesis, screen and shell mode coverage, selector-fit checklist, validation instructions, intentional limitations, clear core/plugin change separation, and acknowledgement of the Seam `appShellAdoption` map.

## Preview layer rules

Keep four layers separate:

| Layer | Allowed source | Importable? |
| --- | --- | --- |
| Theme CSS | `import/theme-id/css/theme.css` | Yes |
| Theme manifest | `import/theme-id/theme.json` | Yes |
| Mock screen content | Preview fixtures/placeholders | No |
| Preview shell/controller | `preview/index.html` only | No |

The preview may simulate data, screen switching, light/dark mode, viewport profiles, sheet/classic presentation, full compact dock, split compact dock, Navigation bar presentation, and placeholder commerce/account/search states. It must not simulate real cart mutation, checkout/payment, PhoneKey, Push subscription, service-worker behavior, Bricks queries, reward verification, or browser history ownership. Marketplace manifests may describe support as `navigation-bar`, but production preview markup must use the runtime attribute value `data-dsa-dock-presentation="navbar"`.

## Geometry rules for preview HTML

The preview shell should mimic Kiwe geometry by setting production-style attributes and CSS variables on the Surface root:

```html
<div
  class="dsa-surface dsa-theme-sheet dsa-visual-marketplace"
  data-dsa-surface
  data-dsa-ui-contract="2"
  data-dsa-layout="compact"
  data-dsa-density="comfortable"
  data-dsa-dock-presentation="dock"
  data-dsa-dock-orientation="horizontal"
  data-dsa-sheet-position="bottom"
  data-dsa-sheet-space="inset"
  style="
    --dsa-dock-control-size:48px;
    --dsa-dock-ai-size:54px;
    --dsa-dock-gap:2px;
    --dsa-dock-padding:8px;
    --dsa-dock-only-reserve:82px;
    --dsa-screen-block-reserve:104px;
    --dsa-screen-inline-reserve:24px;
    --dsa-screen-available-inline:calc(100vw - 24px);
    --dsa-screen-available-block:calc(100vh - 104px);
  "
>
```

When previewing split dock, add the same runtime classes the production renderer emits:

```html
<div class="dsa-surface dsa-dock-split" data-dsa-dock-presentation="dock" ...>
  <nav class="dsa-dock dsa-phonekey-dock" data-dsa-dock-cluster>
    <button class="dsa-dock__button is-split-segment-start">...</button>
    <button class="dsa-dock__button is-split-before-ai">...</button>
    <button class="dsa-dock__button dsa-ai-launcher">...</button>
    <button class="dsa-dock__button is-split-after-ai">...</button>
    <button class="dsa-dock__button is-split-segment-end">...</button>
  </nav>
</div>
```

Split dock applies only when `data-dsa-dock-presentation="dock"`. A navigation bar must ignore split styling even if a preview toggle accidentally leaves `dsa-dock-split` on the root. Full compact dock, split compact dock, and Navigation bar are core dock modes; a theme may style their material, radius, badge treatment, and spacing, but may not convert one mode into another. If a handoff claims dock styling, preview pill (`dsa-dock-shape-pill`), rounded box (`dsa-dock-shape-box`), and square/no-radius (`dsa-dock-shape-square`) so reviewers can see the shape controls actually work.

Do not replace Geometry Engine behavior with one-off hardcoded offsets like `right: 94px`, `width: min(780px, 100%)`, or a fixed toolbar overlay. If a preview needs controls, place them outside the app viewport or reserve space for them above the preview. Mixed-content rows such as logo plus optional score, social links, Shop/Cart actions, and metrics must wrap or rebalance before text becomes unreadable; shrinking labels into fragments is a preview and theme failure. If the Links payload has no site score, omit the score badge entirely. Do not render `0`, a blank white card, or placeholder score copy.

## Preview controls

Preview controls must be outside the app viewport:

```html
<header class="kiwe-preview-toolbar">...</header>
<main class="kiwe-preview-stage">
  <div class="kiwe-preview-viewport">...DSA surface...</div>
</main>
```

Avoid `position: fixed` controls over the app unless the app viewport reserves space for them. A toolbar that overlaps the dock, checkout CTA, FBT rail, or sheet bottom is a preview bug.

## Required preview selectors

Screen mockups must preserve the runtime selectors from `screen-payloads.json` and `slots.md`.

Examples:

```html
<section class="dsa-panel dsa-profile-panel" role="dialog" aria-modal="false" data-dsa-profile-panel>
```

```html
<section class="dsa-panel dsa-cart-panel" role="dialog" aria-modal="false" data-dsa-cart-panel>
```

```html
<section class="dsa-panel dsa-search-panel" role="dialog" aria-modal="false" data-dsa-search-panel>
  <form data-dsa-search-form data-dsa-keep-open>
    <input data-dsa-search-input>
  </form>
  <div data-dsa-search-filters></div>
  <div data-dsa-search-alphabet></div>
  <div data-dsa-search-results></div>
</section>
```

Do not invent final production wrappers when the UI brain already names a required selector. If a designer must add wrapper elements for visual composition, keep them inside the screen root and do not remove required attributes.

## Placeholder rules

Good placeholders look like real neutral content:

- soft product thumbnails
- initials avatars
- logo monograms
- realistic short names and prices
- natural trust badges
- short sample copy

Bad placeholders dominate the design:

- giant debug labels
- red warning boxes
- `PREVIEW IMAGE HERE` banners
- layout-affecting placeholder text

Debug labels are allowed only behind a preview-only toggle such as `data-preview-debug="true"`.

## CSS rules

The preview may have preview-only CSS, but it must be visibly separated from the importable theme CSS.

Recommended:

```html
<link rel="stylesheet" href="../import/theme-id/css/theme.css">
<style>
  .kiwe-preview-toolbar { ... }
  .kiwe-preview-stage { ... }
</style>
```

Preview CSS should be namespaced with `.kiwe-preview-*`. Importable theme CSS should remain scoped to `.dsa-surface`, visual-profile classes, and stable `data-dsa-*` selectors.

## Seam adoption proof

If the preview or README discusses Seam classes inside DSA sheets/screens, it must respect `seam-vocabulary.json`:

- `public-adopted` roles may appear as public `seam-*` classes where Kiwe core applies them.
- `shadow-only` roles should be reviewed through DSA selectors and protected runtime metadata; do not make the theme depend on attaching public `seam-card`, `seam-button`, `seam-input`, `seam-media`, `seam-badge`, `seam-nav`, `seam-actions`, `seam-form`, `seam-field`, or `seam-modal` to live DSA internals.
- `authority-only` concerns such as cart, checkout, PhoneKey, Search, Bricks query, service worker, history, and focus trap must remain Kiwe/core owned.

The current adoption boundary can be checked from the repo root:

```bash
node tools/ui-theme/audit-seam-adoption.cjs
```

## What a broken preview proves

A broken preview proves one of these:

1. The preview shell is not faithfully simulating Kiwe geometry.
2. The preview markup does not match required screen selectors.
3. Preview controls or placeholders are interfering with app layout.
4. The importable theme CSS has a real issue.

Use `tools/ui-theme/validate-package.cjs` first. If the import package passes but the standalone HTML looks broken, inspect the preview shell before blaming the theme engine.

Use `tools/ui-theme/validate-handoff.cjs` when evaluating a complete folder returned by an AI/designer. Passing handoff validation still is not visual approval, but failing it means the preview does not prove the Kiwe contract yet.

## Current limitation

The UI brain provides contract fixtures, not a live WordPress renderer. Pixel-perfect preview snapshots still need to be checked inside the real plugin because production markup, WooCommerce state, Bricks bridges, and responsive Geometry Engine measurements are runtime-owned.

The standalone preview is a design review aid. The live plugin remains the final truth.


# Kiwe handoff modes

Kiwe can be handed to a designer, developer, or AI in three different ways. Pick the mode before asking for output.

## Mode 1: Website/page only

Use this when the job is to create a normal WordPress/Bricks page, section, landing page, shop page, editorial page, or full standalone website preview.

Give:

- `framework-system/handoffs/website-builder/`

Expected output:

```text
website-handoff/
  README.md
  bricks-paste.html  # open in browser for preview; paste/import through Bricks
  bricks-notes.md
```

Rules:

- Use Kiwe/Seam tokens and Seam Class Vocabulary names where useful.
- Produce `bricks-paste.html` as the single website/page artifact. It must open directly in a browser for visual review and also paste/import through Bricks HTML-to-Bricks. It may inline the CSS/JS needed for the page preview, but must not require a React/Vite/Tailwind build, generated Bricks IDs, duplicate preview files, or hidden local files.
- Do not create a Kiwe DSA AppShell theme.
- Do not create cart, checkout, save, auth, AI, Search, service-worker, history, or focus authority.
- If the page includes cart/save/search UI, mark it as Kiwe/Woo/Bricks-owned behavior.
- If the page is WooCommerce/ecommerce, it may use commerce classes such as `seam-product-card`, `seam-product-grid`, `seam-cart-summary`, and `seam-checkout-cta`.
- If the page is editorial/news, it may ignore Woo-specific UI and use content/navigation classes such as `seam-story`, `seam-article`, `seam-horizontal-rail`, `seam-tabs`, and `seam-toc`.

## Mode 2: DSA AppShell theme only

Use this when the job is to design the Kiwe DSA AppShell itself: dock, sheet/screen styling, module surfaces, cart surface, profile surface, search surface, Links hub, saved items, AI surface, and related AppShell chrome.

Give:

- `ui-system/`

Expected output:

```text
theme-handoff/
  README.md
  import/
    theme-id/
      theme.json
      css/
        theme.css
  preview/
    index.html
    PLACEHOLDERS.md
```

Rules:

- The importable package is only `theme.json` plus theme CSS/assets allowed by the validator.
- Preview code is visual proof only.
- Do not create website/page sections.
- Do not invent DSA behavior or state authority.
- Use `screen-payloads.json`, `slots.md`, `preview-handoff.md`, and `theme-manifest.schema.json`.
- Dock destination visibility is configuration, not theme CSS. If a design needs a different dock composition, document it as a Kiwe settings/profile change.

## Mode 3: Combined website/page + DSA AppShell theme

Use this when the assignment is to design a website/page and the Kiwe DSA AppShell look together, so the page and dock/sheets feel intentionally paired.

Give both:

- `framework-system/handoffs/website-builder/`
- `ui-system/`
- this `HANDOFF-MODES.md`

Expected output:

```text
combined-kiwe-handoff/
  README.md
  combined-preview/
    index.html              # primary human review: website/page with DSA AppShell over it
    assets/
      combined-preview.css  # optional, preview-only
      combined-preview.js   # optional, preview-only
  website/
    bricks-paste.html      # Bricks artifact; also openable as the website/page preview
    bricks-notes.md
  appshell-theme/
    import/
      theme-id/
        theme.json
        css/
          theme.css
    preview/
      index.html
      PLACEHOLDERS.md
  kiwe-settings/
    kiwe-appsite-profile.json   # optional, only when changing Kiwe settings
    SETTINGS-NOTES.md
```

Rules:

- `combined-preview/index.html` is the primary review artifact. It should show the website/page behind the Kiwe DSA dock/sheet/screen, using the AppShell theme CSS and realistic placeholder data.
- Keep the website/page CSS and AppShell theme CSS separate.
- The website lane must include `website/bricks-paste.html`. This is the Bricks copy/paste artifact. Do not return only a React/Vite app, screenshot, Markdown spec, or preview without the paste-ready file.
- The Kiwe AppShell is runtime chrome around the page, not part of the Bricks page itself. `website/bricks-paste.html` must be page-only: no `data-dsa-surface`, DSA dock, DSA sheet/screen markup, AppShell preview controller, or Kiwe runtime mock belongs in the Bricks paste artifact.
- Only `combined-preview/index.html` should show the website/page and Kiwe AppShell together for human review.
- Website CSS may use Seam Class Vocabulary and Bricks-friendly classes.
- AppShell theme CSS may style DSA theme selectors and allowed public Seam classes according to `ui-system/`.
- Do not create a separate `website/preview/index.html` by default. `website/bricks-paste.html` is the website/page preview and the Bricks import artifact. Only add split website preview assets if the human explicitly asks for them.
- The separate `appshell-theme/preview/index.html` is a technical fixture for AppShell validator proof. It is not the primary combined-mode visual review.
- The combined preview may simulate save/cart/search/screen switching only as preview-only behavior. Production behavior remains Kiwe/WordPress/Woo/Bricks-owned.
- Do not copy website page classes into DSA internals unless the AppShell adoption map allows it.
- Do not use DSA theme CSS to style the whole website.
- Do not use website CSS to restyle protected DSA internals.

## Kiwe settings/profile lane

Combined handoffs may include `kiwe-settings/kiwe-appsite-profile.json` when the design intentionally changes Kiwe runtime settings.

This is useful when the design wants:

- Dock presentation as compact dock or navigation bar.
- Split compact dock on/off.
- Dock shape: `pill`, `box`, or `square`.
- Dock module visibility and order.
- Dark/light mode action hidden from the dock because a page/header launcher will open it elsewhere.
- Cart hidden from the dock for a non-commerce site.
- Cart visible for a WooCommerce/ecommerce site.
- Sheet mode, sheet placement, sheet spacing, sheet origin, sheet width, and sheet height.
- Visual profile: `legacy` or `kiwe2027`.
- Active/hover/hero colors.
- WooCommerce or Search bridge settings when the website design requires them.

Use only recognized Kiwe profile settings. Unknown keys are ignored by import.

Safe high-level keys include:

```json
{
  "type": "kiwe-appsite-profile",
  "schemaVersion": 1,
  "settings": {
    "enabled": true,
    "style": {
      "visual_profile": "kiwe2027",
      "mode": "sheet",
      "sheet_position": "bottom",
      "sheet_spacing": "inset",
      "sheet_origin": "above_dock",
      "sheet_width_percent": 78
    },
    "dock": {
      "presentation": "dock",
      "split_style": true,
      "shape": "pill",
      "desktop_orientation": "auto",
      "tablet_orientation": "auto",
      "mobile_orientation": "auto",
      "enabled_items": {
        "menu": true,
        "search": true,
        "profile": true,
        "links": true,
        "saved": true,
        "cart": true,
        "theme": false,
        "ai": true
      },
      "item_order": ["menu", "search", "profile", "links", "saved", "cart", "theme", "ai"]
    },
    "dsa_theme": {
      "active_color": "#8f8f98",
      "hover_color": "#24c6a1",
      "hero_text_color": "rgba(20,24,34,0.18)"
    }
  }
}
```

Notes:

- Hiding a dock item only hides the dock button. It does not delete the registered DSA module.
- Bricks/Icon launchers may still open DSA modules through Kiwe's Bricks controls and `data-dsa-open-module`.
- WooCommerce controls should match the assignment. A news/editorial design should not force cart UI unless requested. An ecommerce design should account for cart, checkout, product rails, and Woo-owned behavior.
- The profile must not contain users, orders, credentials, tokens, logs, raw API keys, or private data.

## What to ask the AI

For website only:

> Follow `framework-system/handoffs/website-builder/prompt.md`. Build a standalone previewable Bricks-ready page using Kiwe/Seam. Do not create an AppShell theme.

For DSA theme only:

> Follow `ui-system/prompt.md`. Build a Kiwe DSA AppShell theme handoff with importable package and standalone preview. Do not create a website.

For combined:

> Follow both `framework-system/handoffs/website-builder/prompt.md` and `ui-system/prompt.md`, using `HANDOFF-MODES.md` as the layer boundary. Create a combined website/page plus DSA AppShell theme handoff. Keep website, AppShell theme, and Kiwe settings/profile output separate.
