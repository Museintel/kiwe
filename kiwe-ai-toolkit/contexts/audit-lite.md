# Kiwe AI audit-lite context

Use this file when revising an existing Kiwe handoff from v1 to v2/v3/v4, or when the human asks you to audit your own output against the Kiwe AI Toolkit.

Do not read the full Kiwe repository. Use this audit context together with the relevant mode context:

- Website/page only: `contexts/website.md`
- AppShell theme only: `contexts/theme.md`
- Website/page + AppShell: `contexts/combined-lite.md`

If you can run shell commands, also run:

```bash
node kiwe-ai-toolkit/tools/validate-output.cjs /path/to/handoff --mode combined
node kiwe-ai-toolkit/tools/validate-framework-profile.cjs /path/to/handoff --optional
node kiwe-ai-toolkit/tools/validate-accessibility.cjs /path/to/handoff --optional
node kiwe-ai-toolkit/tools/audit-output.cjs /path/to/handoff
```

For a website/page Seam artifact, run the named wrapper when available:

```bash
node kiwe-ai-toolkit/tools/validate-seamframework.cjs /path/to/website/bricks-paste.html
```

The Seam validator is standalone-capable for browser AI. If `validate-seamframework.cjs` is downloaded without the rest of the toolkit, it runs bundled core Seam checks and reports `mode: "self-contained-fallback"`. Missing `audit-output.cjs` alone is not a valid blocker for `/audit /seamframework` once the current Seam validator file is present.

PASS requires executable validator proof. If you cannot run code, MCP validator tools, REST/plugin validator routes, or a hosted/local Kiwe validator endpoint, perform the manual audit below only as advisory review and report `WARN` or `UNVERIFIED`, not `PASS`. Copied, reconstructed, simulated, or "equivalent" validator logic may guide fixes but cannot close a lane.

If the human gives a target-site Kiwe AI key and Companion AI is enabled in `Kiwe > AI`, you may also run the deterministic site Companion audit:

```text
POST /wp-json/dsa/v1/ai/companion/review-output
POST /wp-json/dsa/v1/ai/audit-companion/review
```

Prefer `/ai/audit-companion/review` for revision loops because it returns a compact `mustFix` / `shouldFix` / `passed` map. Submit the actual generated file map, revise every `mustFix` item, then rerun the same route before spending another broad model pass. Use it as an extra compact rule/finding source, not as a replacement for this audit context or for official validators. A Companion pass does not prove browser rendering, WordPress import, Bricks import, WooCommerce behavior, checkout/auth/cart behavior, or live Kiwe theme installation unless those tests actually ran.

## Audit posture

Be critical. Passing a basic folder-shape validator is not enough. A Kiwe handoff should be:

- importable;
- previewable;
- Bricks-friendly;
- Seam-aligned;
- AppShell-safe;
- resilient when a site owner later changes Kiwe settings.

Do not only explain fixes. Revise the actual files.

## Output shape audit

For a combined handoff, verify:

- `combined-preview/index.html` exists and is the primary human review artifact.
- `website/bricks-paste.html` exists and is page-only.
- `website/bricks-notes.md` is optional and should exist only when `/document` was requested.
- `appshell-theme/import/<theme-id>/theme.json` exists.
- `appshell-theme/import/<theme-id>/css/theme.css` exists.
- `appshell-theme/import/<theme-id>/theme-package.json` exists when the theme changes dock composition, focus item, module visibility/order, presentation, shape, colors, visual effects, sheet behavior, or other Kiwe runtime theme settings.
- `combined-preview/index.html` is the single primary visual proof for combined mode. It must include the page and AppShell together with variation controls.
- `appshell-theme/preview/index.html` is optional in combined mode. If it exists, it is only a technical selector/state fixture and must not be the only place where dock modes, dock shapes, Classic, or responsive profiles are reviewed.
- Any preview placeholder documentation explicitly says all mock data/content is preview-only.
- Do not require or create a separate `kiwe-settings/` folder for AppShell theme settings. Kiwe imports/exports installed themes; the safe settings preset belongs inside the theme package.

For website-only mode, do not output duplicate preview folders unless explicitly requested. `website/bricks-paste.html` is both browser preview and Bricks paste/import artifact.

If website-only mode includes a sitewide reusable design-token profile, verify `framework/kiwe-framework-profile.json` uses `schema: "kiwe.framework-profile.v1"` and contains only `settings.tokens`. This file imports at `Kiwe > Framework`; it is not a DSA theme package and must not contain dock, sheet, screen, module, WooCommerce, page content, or AppShell CSS behavior.

For combined mode, do not duplicate the website inside the AppShell import package. The AppShell is runtime chrome around the page, not part of the Bricks page.

Do not create separate human review previews for the website and AppShell in combined mode. The reviewer should open one combined preview and see the website/page behind the Kiwe AppShell.

For combined mode, live-intended palette, typography, spacing, radius, shadow, and global Bricks style personality must live inside `appshell-theme/import/<theme-id>/theme-package.json` under `settings.tokens` for marketplace AppShell themes. Do not require a separate Framework profile unless the brief explicitly asks for a standalone `Kiwe > Framework` import artifact too.

## Accessibility / dark-mode audit

When the human asks for `/create /accessibility` or `/audit /accessibility`, also read `contexts/accessibility-lite.md`.

For all modern Kiwe outputs, audit color contrast and native light/dark behavior:

- literal text/background pairs must meet contrast; white-on-white, light-on-light, black-on-black, and dark-on-dark pills/cards/buttons are blocking failures;
- badges, chips, pills, buttons, stats, product labels, rail cards, dock controls, and DSA screen/sheet labels need explicit readable foreground/background token pairs;
- light and dark mode must be proven through native theme state such as `data-kiwe-theme`, `data-kiwe-theme-toggle`, or a clearly mapped standalone `data-theme`;
- dark mode must not be a `filter: invert()` hack or a second unrelated palette;
- text over gradients or images must have a solid fallback token or a manual-review note;
- private project color variables must map back to Kiwe token pairs in `accessibility/kiwe-accessibility-plan.json`;
- Bricks targets should align Kiwe tokens with Bricks root theme-style slots: `siteBackground`, `colorPrimary`, `colorSecondary`, `colorLight`, `colorDark`, `colorMuted`, and state colors;
- DSA theme import CSS must not hide accessibility fixes in preview-only CSS; live import selectors and token settings should carry the same visual contrast.

If an accessibility lane exists, verify:

- `accessibility/kiwe-accessibility-plan.json` uses `schema: "kiwe.accessibility-plan.v1"`;
- `modes` includes both `light` and `dark`;
- `tokenPairs` is non-empty and covers the real surfaces present in the artifact;
- `manualReview` exists, even when empty;
- critical text-bearing titles, labels, pills, chips, buttons, tabs, prices, stats, and card headings are not clipped, hidden, nowrap-ellipsized, or line-clamped inside constrained boxes at desktop/tablet/mobile/narrow widths;
- if the artifact targets Bricks, native Bricks settings and global classes preserve the same accessibility fixes instead of hiding them in preview-only CSS;
- `ACCESSIBILITY-NOTES.md` records what was fixed and what was only manually reviewed only when `/document` was requested.

When tools are available, run:

```bash
node kiwe-ai-toolkit/tools/validate-accessibility.cjs <handoff>
```

## Website / Bricks audit

`website/bricks-paste.html` must be page-only:

- no DSA dock/sheet/screen markup;
- no AppShell preview controller;
- no `data-dsa-surface`, `.dsa-dock`, `.dsa-sheet`, or `.dsa-panel` AppShell shell markup;
- no duplicate cart/search/profile/auth/save/AI runtime authority.

If the handoff is being applied to a real staging site through Kiwe AI, prefer the controlled `bricks.page.from-html` or `bricks.template.from-html` executor path over browser clipboard paste. The handoff author should still provide clean HTML/CSS first. If a human explicitly asks for `/convert /bricks`, the default human-facing artifact is a native Bricks My Templates upload JSON, not a Kiwe wrapper. The auditor should check that the source HTML/CSS is converter-friendly: semantic nesting, stable classes, preserved `data-dsa-open-module` launchers, no huge base64 payloads, no script-owned production behavior, and CSS that can be mapped into Bricks-native controls/global classes/variables.

If the handoff includes a `/convert /bricks` result, audit the conversion lane too:

- The primary human upload artifact is `bricks-template/<page-or-template-name>-template-upload.json`.
- The primary upload file is a native Bricks template export with a non-empty `title`, a valid `templateType`, and a non-empty `content`, `header`, or `footer` array. For a homepage body, `title` should be `Home` and `templateType` should be `content`.
- Do not tell the human to upload `bricks-conversion/kiwe-bricks-conversion.json`; that object is an optional Kiwe audit/executor envelope and Bricks imports it as `(no title)` with no insertable data.
- `bricks-conversion/kiwe-bricks-conversion.json` is optional only when `/document`, a controlled executor, or an explicit audit-envelope request is present. If present, it uses `schema: "kiwe.bricks-conversion.v1"` and points to the native template through `target.templateExportPath` when `target.importMethod` is `bricks-admin-template-upload`.
- `BRICKS-CONVERSION-NOTES.md`, `FRAMEWORK-NOTES.md`, README/report files, ZIPs, duplicate previews, and loose extra docs should exist only when `/document` was requested.
- `source.html` points to `website/bricks-paste.html`.
- A Framework foundation exists or is explicitly confirmed: `framework/kiwe-framework-profile.json`, `bricks-theme-style.json`, or a human statement that Kiwe > Framework/Bricks Theme Styles are already pushed. Otherwise `/convert /bricks` should have stopped before conversion.
- Fail if the conversion source is `combined-preview`, `appshell-theme`, DSA/AppShell preview markup, sheet/screen/dock/navbar markup, `theme-package.json`, or `css/theme.css`.
- `content`, `header`, `footer`, or optional envelope `elements` is a non-empty Bricks element array with IDs, names, and valid parent references.
- Source Seam classes, IDs, ARIA, `data-role`, `data-seam-*`, and `data-dsa-open-module` launchers are preserved in the conversion package.
- Source `data-kiwe-query-template` markers have Bricks query settings or `fidelity.dynamicIntent`.
- Bento/campaign/editorial grids, CSS grid columns/rows/spans, media-query layout changes, and Bricks responsive layout overrides have `fidelity.responsiveIntent` entries naming breakpoint/range, source selector, mapped Bricks element IDs, Bricks controls, and intended grid/flex behavior. Bricks responsive control keys use `controlKey:breakpoint`; layout elements (`container`, `div`, `section`, `block`) must use `_direction` / `_direction:<breakpoint>` for flex direction. `_flexDirection` is valid only for non-nestable Bricks elements. Audit grid controls, `_cssCustom:<breakpoint>`, and custom breakpoint keys instead of assuming only default viewport names exist.
- Full-page Bricks template uploads are resilient to Bricks My Templates class hydration behavior: global class rows may be skipped or remapped when class names already exist on the target site, so enough element-level native style/layout controls must remain for rendered fidelity and editor editability.
- Full-page Bricks template uploads must also avoid duplicate visual ownership. If element-native controls already own render/edit fidelity, imported `global_classes` must be semantic/name-only. Styled template `global_classes` plus styled element-native controls create Bricks ghost styling where removing a color/radius/spacing from one visible layer leaves the same style active from another layer. Reusable styled project classes belong in the Framework profile push or a class-library artifact, not duplicated inside the page template upload.
- Fail Bricks conversions that park project-wide variables, `@media` rules, bento/campaign CSS, or global page selectors inside one element's `_cssCustom`. Use native Bricks controls, global classes, and global variables first. For review-only or small clipboard lanes, `pageSettings.customCss` may hold documented exceptions; for Bricks template-upload delivery, ordinary layout/design must not depend on `pageSettings.customCss` because inserted templates can leave that CSS behind or collide with stale target-page CSS.
- If a live Bricks preview/builder URL is supplied as proof, audit the target page itself before blaming the current template. Check for `#bricks-inline-css-page`, `#bricks-frontend-inline-inline-css`, `#dynamic-element-css`, other `bricks-inline-css-*` style blocks, page settings `customCss`/`_cssCustom*`, and stale selectors from previous attempts. If old page-level CSS or matching old selectors are present and are not part of the current attached artifact, return `ERROR: KIWE_STALE_BRICKS_PAGE_CSS` and require a blank/clean target page or cleared Bricks page settings custom CSS before visual pass/fail.
- Bricks conversion must be editable in the Bricks visual editor, not merely visually rendered through a CSS dump. Prefer native Bricks element controls and global variables for typography, colors/backgrounds/gradients, borders/radii, shadows, transforms, filters, transitions, spacing, sizing, grid/flex, responsive direction, alignment, query loops, conditions, interactions, and dynamic tags. For full-page template uploads, imported `global_classes` should be semantic/name-only unless the command explicitly targets a reusable class-library artifact. If substantial custom CSS remains, `fidelity.nativeStyleIntent` must prove which selectors were mapped to editable Bricks-native controls and which rules remain explicit custom-CSS exceptions.
- Bricks-native does not mean hardcoded. Fail element settings, `global_classes`, or optional envelope `globalClasses` that use bare literal design lengths such as `28px`, `2.35rem`, `390px`, `20vw`, or `-7px` for spacing, sizing, radius, typography scale, shadow geometry, transform offsets, or responsive layout controls. Those values must follow the Kiwe token ladder: official `var(--kiwe-*)`/`var(--seam-*)` token when meaning and property domain match; declared project variable for stable art direction; real fluid `clamp(...)` only when source responsive states prove different min/max values. Examples that must fail: `.nc-promo-card` with `_padding: 28px`, `_border.radius: 24px`, `_heightMin: 390px`, `_typography.font-size: 2.35rem`, `_rowGap: 20px`, or no-op wrappers like `clamp(680px, 680px, 680px)`. Plain values are allowed in Framework token definitions when their behavior role is a named primitive, geometry input, content limit, or responsive guard; copying the same values into Bricks settings without the token is still a failure.
- Also fail direct component color literals in Bricks element settings, `global_classes`, optional envelope `globalClasses`, and custom CSS exceptions. Values such as `color: #fff`, `_background.color.raw: #8deae5`, `linear-gradient(#201b18, #514238)`, `rgba(255,255,255,.11)`, or local component variables like `--pack-bg: #f5b942` must consume official Kiwe/Seam variables or declared project variables. Literal colors are allowed only in Framework/global variable definitions or as fallbacks inside `var(...)`, not as direct component styling.
- Also fail bare CSS variable references in Bricks native element settings, `global_classes`, or optional envelope `globalClasses`, such as `var(--kiwe-color-surface)`, `var(--sf-full)`, `var(--nc-promo-min)`, or `minmax(var(--sf-hero-side-min), .65fr)`. Bricks My Templates upload imports `global_classes`, but the human upload path cannot be trusted to install every top-level `globalVariables` entry from the same JSON on all target sites. Use `var(--token, fallback)` inside native Bricks styling, or explicitly require a verified Kiwe > Framework push before template import. Also fail template `global_variables`/`globalVariables` names that include leading `--`, because Bricks emits the prefix itself and compiles those records into disconnected `----token` variables. Fail Bricks compiler-unsafe sizing aliases such as `_maxWidth`, `_minWidth`, `_maxHeight`, and `_minHeight`; use `_widthMax`, `_widthMin`, `_heightMax`, and `_heightMin`. Fail `_typography.font-family` values that contain `var(...)`, because Bricks quotes typography font-family output and turns CSS variables into invalid literal font family names. Fail semantic Bricks Heading elements (`name: "heading"` with `tag: "h1"` through `"h6"`) that carry local official heading-scale font-size locks such as `_typography.font-size: "var(--kiwe-type-h3, 2rem)"`; the H1-H6 scale belongs in Kiwe > Framework / Bricks Theme Style so editor tag changes control the actual heading size. Fail plain-string Bricks color controls such as `_background.color: "var(...)"`, `_border.color: "transparent"`, and `_typography.color: "var(...)"`; Bricks expects color objects with `raw`, `rgb`, or `hex`. Fail gradients placed in `_background.color`; use `_gradient`. Fail CSS-corner radius keys inside Bricks border controls, such as `_border.radius.topLeft`, `_border.radius.topRight`, `_border.radius.bottomRight`, or `_border.radius.bottomLeft`; Bricks compiles radius from `top`, `right`, `bottom`, and `left` keys, so corner keys can import but render with no radius.
- Also fail reserved-looking Framework variables that are not in the actual Kiwe universal token registry/runtime. `var(--kiwe-space-md, ...)` is valid because `space-md` exists; invented names such as `--kiwe-type-2xs`, `--kiwe-letterspace-eyebrow`, `--kiwe-radius-pill`, `--kiwe-border-width`, or `--seam-color-primary` are not valid just because they use a reserved prefix. Map them to existing official tokens, declare project variables with a collision-safe prefix, or add the token to the universal registry before claiming a validator PASS.
- Treat mappable CSS declarations as technical debt unless they are represented in Bricks controls. If custom CSS contains many ordinary properties such as `display`, `flex-direction`, `grid-template-columns`, `gap`, `padding`, `font-size`, `background`, `border-radius`, or `box-shadow`, the package must expose enough native controls/global classes/global variables to keep those decisions editable in the Bricks visual editor. Custom CSS is allowed for documented exceptions only, not as the main conversion strategy.
- Bricks conversion must declare `target.importMethod` as one of `review-only`, `bricks-clipboard-json`, `bricks-admin-template-upload`, or `kiwe-staging-executor` when an optional envelope exists. `kiwe-bricks-conversion.json` is a Kiwe audit/executor envelope, not a Bricks "My Templates" upload file. Full pages and large sections should use `bricks-admin-template-upload` or `kiwe-staging-executor`, not clipboard JSON. For template-upload delivery, require Bricks template dependency rows under `global_classes` when imported elements rely on global class styling; copied-elements `globalClasses` alone is insufficient for this path.
- Also fail Bricks template-upload `global_classes` that use unscoped project visual class names. Bricks skips/remaps imported global class styles when a local class already has the same `id` or `name`, so mutable visual global classes must be namespaced (`nc-promo-card`, `bv-product-card`, `sf-hero-grid`, `kiwe-commerce-rail`). Bare names such as `promo-card`, `screen`, `display`, `eyebrow`, `pill`, `product-card`, `save-btn`, `rating`, `quick-grid`, and `story-card` may remain in `_cssClasses`/attributes for semantic readability, but not in importable `global_classes`.
- Fail Bricks template-upload handoffs that depend on `pageSettings.customCss` for ordinary design. Bricks can store custom CSS on a template record, but when a template is inserted into a target page that CSS may not travel with the content or may collide with stale page CSS. Template-upload JSON must therefore carry ordinary grid/flex, sizing, spacing, typography, color/background, borders/radii, and shadows in native element settings, importable `globalClasses`, and `globalVariables`; allow only small documented custom-CSS exceptions.
- Conditions/interactions are expressed through Bricks-supported settings and do not use unsafe JavaScript actions.
- The conversion package does not claim direct Bricks/WordPress/WooCommerce write authority.
- Fail if project/page/theme CSS redefines Seam framework selectors such as `.seam-horizontal-rail`, `.seam-card`, `.seam-button`, `.seam-visually-hidden`, `[data-flow="reel"]`, or `[data-role="card"]`, including when scoped as `.project .seam-card`. Seam classes and attributes may appear in markup, but visual CSS must target project-owned classes so the same framework vocabulary can be reused without unintended layout changes.
- Fail if a nav/sticky/container wrapper uses `.seam-horizontal-rail` or `data-flow="horizontal-rail"` and then contains a `.seam-container` or another descendant rail. That shrinks the container into a rail item after Bricks import. The outer wrapper should be project/sticky/nav layout; only the inner item track should carry the Seam rail flow.

When tools are available, run:

```bash
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs <handoff> --site-graph <site-graph.json>
```

Converter-friendly also means human-readable after import. Audit the rendered page text at mobile and desktop widths for cramped or joined copy caused by missing inline spacing or over-compressed layout, such as `BestsellersThe`, `A century ofirresistible`, or `100+years`. Stat cards, category chips, hero eyebrow/title pairs, and CTA rows must preserve readable spacing and minimum legible type size after Bricks import.

Page/header controls that open Kiwe modules must use canonical hooks:

```html
data-dsa-open-module="cart"
data-dsa-open-module="profile"
data-dsa-open-module="search"
```

Use only registered module names: `menu`, `search`, `profile`, `links`, `saved`, `cart`, `theme`, `ai`, `notifications`, `ios-install`, `games`.

Home or other URL-only dock items are valid. Do not call them invalid merely because they are not built-in DSA screens. The requirement is that they must be declared as custom dock links in the theme package `settings.dock.custom_items`, not invented as registered DSA modules.

Repeated launcher activation must be audited. Rapidly click/tap the same dock item and any matching page/header launcher more than once. The result must still be one active screen/sheet for that module, one backdrop/overlay path, and no stacked duplicate panel markup or visible repeated pop animation.

Dock focus styling must be audited separately from active/open state. The configured `dock.focus_item` should be visibly emphasized through the live `data-dsa-dock-focus` / `data-dsa-dock-primary` hooks even when another module is currently open. Do not pass themes that only style `[aria-pressed="true"]` and leave a non-AI focus item, such as Search, visually ordinary.

Custom URL dock links must show an icon when the package/settings request one. A Home link using `home` or `house` must not render as a blank dock button.

For ecommerce pages:

- WooCommerce owns product queries, add-to-cart, cart state, checkout, payment, orders, coupons, and account endpoints.
- Preview-only add-to-cart behavior must be clearly labelled and easy to replace with Woo/Bricks controls.
- Save/wishlist/bookmark affordances, if shown, should use Kiwe save hooks or be documented as not implemented.

Avoid huge inline base64 assets in Bricks paste files unless the human explicitly asked for a single-file throwaway preview. For live candidates, prefer Media Library replacement notes and optimized image assets.

## Seam audit

`data-role` is controlled vocabulary, not a free naming slot.

- Use only official Seam roles from the toolkit.
- Put project-specific concepts in project classes, `data-project-role`, content text, or official Seam Class Vocabulary handles.
- Do not invent custom `data-role` values such as `product-card`, `brand-story`, `hero-cta`, or `news-card`.
- Do not add Seam attributes only to feed DSA Menu context.

Seam is semantic/headless. Use page CSS/project CSS for visual art direction.

Seam selector declarations are not project CSS. Reject `.seam-*`, `[data-flow]`, `[data-role]`, `[data-tone]`, and `[data-state]` selector definitions in generated handoff CSS unless the file is the official Seam runtime itself, even if a project selector scopes them. Project appearance belongs on project classes, while Seam remains the shared vocabulary.

Audit public Kiwe/Appsite capability attributes as part of Seam, not as unrelated plugin hooks:

- If a page/header/button opens a Kiwe surface, it must use canonical `data-dsa-open-module` with a registered module value.
- If the UI shows wishlist/bookmark/save intent, it should use `data-kiwe-save="wishlist"`, `bookmark`, or `auto` with stable IDs/titles/URLs/images where dynamic data is available.
- If the UI asks for browser notifications, it should use `data-kiwe-notifications` and must not request permission until a real visitor click.
- If the UI offers light/dark mode outside the dock, it should use `data-kiwe-theme-toggle` rather than custom production theme-toggle JavaScript.
- If sample cards/rails are intended to become Bricks dynamic data, preserve `data-kiwe-query-template` and `data-kiwe-binding` or record equivalent dynamic intent in the Bricks conversion package.
- Reject production/import artifacts that implement separate localStorage/sessionStorage state for Kiwe-owned save, notification, theme, AppShell launcher, cart, checkout, auth, search, or AI capabilities.
- Treat candidate attributes such as `data-kiwe-share`, `data-kiwe-compare`, `data-kiwe-recently-viewed`, `data-kiwe-follow`, `data-kiwe-ai-context`, `data-kiwe-feedback`, and `data-kiwe-offer` as non-live roadmap ideas unless the current machine contract explicitly marks them live.

## DSA Menu / context audit

Kiwe Menu context is heading-first.

- Admin-selected H1/H2/H3-style heading levels remain the default table-of-contents source.
- Existing semantic sections may be consumed as fallback context when configured headings are unavailable.
- Do not fake a menu table of contents that would not match the page.
- `data-dsa-menu-anchor` values must be raw IDs, not hash selectors.

Correct:

```html
data-dsa-menu-anchor="heritage"
```

Wrong:

```html
data-dsa-menu-anchor="#heritage"
```

Every manual menu anchor in preview must match a real page `id`, unless the preview clearly demonstrates live heading-generated context.

## Kiwe theme package settings audit

Include `appshell-theme/import/<theme-id>/theme-package.json` when the design changes runtime settings. This is the single Kiwe admin/API import file for an installed theme. It must contain root `theme`, `settings`, and `css` keys. Keep `theme.json` as the manifest-only validator file.

Common settings to declare:

- `dock.presentation`: `dock` or `navbar`.
- `dock.split_style`: split compact dock on/off; only applies when presentation is `dock`.
- `dock.shape`: `pill`, `box`, or `square`.
- `dock.enabled_items`: visible built-in modules/custom links.
- `dock.item_order`: visible item order.
- `dock.focus_item`: the emphasized/focus item and split-dock center.
- `dock.custom_items`: URL-only custom dock links such as Home, Shop, About, Offers, or any safe site URL. These are first-class Kiwe dock items, but they navigate only and do not create new DSA screens.
- `tokens`: design token profile overrides for the live DSA theme, Seam website/page CSS, and Bricks global theme-style export. Token names must be official Kiwe universal names only; examples include `color-brand`, `color-accent`, `color-surface`, `color-text`, `font-display`, `font-body`, `type-h1`, `type-h2`, `leading-tight`, `space-md`, `radius-lg`, and `shadow-md`.
- `tokens.bricks_theme_style`: optional safe global Bricks theme-style export metadata. It may cover only global typography, colors, links, and site background. It must not own Bricks element-level styling, AppShell geometry, modules, state, WooCommerce data, or runtime behavior.
- `screens`: optional presentation/copy labels for the live registered DSA screen/sheet adapters. Allowed screen keys are `profile`, `cart`, `checkout`, `search`, `menu`, `saved`, `links`, `notifications`, `ios-install`, `games`, and `ai`. This lane may rename labels, titles, helper text, empty states, safe CTA labels, the Cart FBT rail title, Profile row labels, Links shop/cart labels, Notification form labels, iOS install steps, Game labels, and AI empty/chat copy. It must not contain products, orders, saved items, profile identity, menu items, search results, social URLs, score values, notification state, AI messages/actions, cart line items, totals, checkout/payment URLs, JavaScript, endpoints, or state authority.

Do not use `theme.json` for Kiwe settings. `theme.json` is the AppShell theme manifest. Theme settings belong in `theme-package.json` at root `settings`, beside the root `theme` manifest and root `css` import CSS.

Audit the actual import shape, not just whether a friendly-looking object exists. Fail the package when:

- `settings.tokens` contains raw CSS variable keys such as `--kiwe-color-brand`;
- `settings.tokens` is missing from a marketplace AppShell theme package with a distinctive live visual personality;
- `settings.tokens` is missing an `overrides` object when a token profile is declared;
- `settings.tokens.overrides` uses `--kiwe-*`, `var(...)`, private token names, or invalid token names instead of official Kiwe universal token names like `color-brand`;
- importable `theme.css` references unsupported `--kiwe-*` CSS variables that are not official universal tokens or documented `--kiwe-theme-*` aliases. Fail invented variables such as `--kiwe-color-background`, `--kiwe-radius-card`, `--kiwe-radius-control`, `--kiwe-shadow-panel`, and `--kiwe-space-unit`; use `--kiwe-color-surface`, `--kiwe-color-surface-raised`, `--kiwe-radius-xl`, `--kiwe-radius-full`, `--kiwe-shadow-md`, and `--kiwe-space-md` instead;
- importable `theme.css`, preview CSS, or documentation copies generated `--dsa-runtime-token-####` names as if they are public tokens. Those variables are private Kiwe core migration bridge tokens for runtime token-purity validation, not theme/Seam vocabulary. Replace them with official `--kiwe-*`, documented `--kiwe-theme-*`, or propose a generic universal token addition;
- importable `theme.css` contains anonymous raw CSS literals such as hardcoded lengths (`22px`, `1.25rem`, `100vw`), color literals/functions (`#fff`, `rgb(...)`, `oklch(...)`), or literal shadow/effect recipes (`box-shadow: 0 18px 48px ...`). Fail these even when they appear inside CSS custom property declarations. Concrete base values belong in `theme-package.json settings.tokens` or Kiwe core token registries; importable theme CSS should consume official `--kiwe-*`, documented `--kiwe-theme-*`, or Kiwe/DSA Geometry Engine variables instead;
- `settings.screens` contains unsupported screen ids;
- a `settings.screens.<screen>` object contains unsupported field names. For example, fail `profile.helperText`, `profile.ordersLabel`, `profile.addressesLabel`, `profile.downloadsLabel`, `profile.actionLabel`, `links.scoreLabel`, `links.noScoreText`, `links.instagramLabel`, `links.storesLabel`, and `links.giftingLabel`. Use live fields such as `intro`, `ordersTitle`, `ordersText`, `downloadsTitle`, `downloadsText`, `addressesTitle`, `addressesText`, `shopLabel`, `shopMeta`, `cartLabel`, and `cartMeta`.

If a preview shows custom live-intended screen/sheet copy, verify that the same copy is present in `theme-package.json` under `settings.screens`. Examples: custom account title/rows under `settings.screens.profile`, cart/bag title and FBT title under `settings.screens.cart`, search placeholder under `settings.screens.search`, menu table-of-contents label under `settings.screens.menu`, Links action labels under `settings.screens.links`, and AI chat placeholder under `settings.screens.ai`. If absent, mark the package as a preview/live mismatch. If the copy is intentionally preview-only, `PLACEHOLDERS.md` must say so explicitly.

Kiwe also exposes manual registered screen/sheet copy controls under `Kiwe > Theme > DSA screen/sheet copy`; those admin overrides merge over imported package defaults. A handoff should still include live-intended package defaults so first install matches the preview.

If a preview shows a distinctive palette, background, font pairing, heading scale, or global link treatment that should appear live, verify that the same personality is represented in `theme-package.json` under `settings.tokens.overrides` and, for Bricks/site-wide application, `settings.tokens.bricks_theme_style`. If the token profile is missing, mark the package as a preview/live mismatch: importable `theme.css` alone is not enough to synchronize DSA, Seam page CSS, and Bricks global style.

Standalone Framework profiles must be narrow:

- `schema: "kiwe.framework-profile.v1"`;
- `settings.tokens.enabled`;
- `settings.tokens.profile_label`;
- `settings.tokens.overrides` using official Kiwe universal token names only;
- complete `settings.tokens.bricks_theme_style` metadata with `enabled: true`, safe `id`, human `label`, and global style slots only when useful. Kiwe > Framework uses this lane to push the matching native Bricks Theme Style.
- optional `settings.tokens.project` for project-specific SeamFlow extensions that should be pushed to Bricks before template import. Project variables must be prefixed CSS custom properties such as `--nc-promo-min` or `--bv-card-bg`, never reserved `--kiwe-*` or `--seam-*`; project classes must be collision-safe names such as `nc-promo-card`, never generic names such as `card`, `hero`, `display`, or `button`.
- complete core live token coverage so a pushed Framework profile does not leave the website or Bricks editor with empty Seam/Kiwe variables. Required coverage: `color-brand`, `color-accent`, `color-surface`, `color-surface-raised`, `color-text`, `color-text-muted`, `color-border`, `font-display`, `font-body`, `type-h1`, `type-body`, `space-md`, `radius-lg`, and `shadow-md`.
- The coverage may be direct in `settings.tokens.overrides` or supplied through mapped `bricks_theme_style` slots, for example `colorPrimary`, `colorSecondary`, `siteBackground`, `colorDark`, `colorMuted`, `colorBorder`, `fontDisplay`, `fontBody`, `typeH1`, `typeBody`, `spaceMd`, `radiusLg`, and `shadowMd`.
- Audit official token names, not invented CSS variables. `color-brand` maps to `--kiwe-color-brand`; `space-md` maps to `--kiwe-space-md`. Do not require or generate `--kiwe-color-primary`, `--kiwe-color-secondary`, or `--seam-color-primary`.
- Do not alias Framework profile audit to Bricks Theme Style audit. A standalone `/brickstheme` file has native Bricks root shape, while a Framework profile has Kiwe root `settings.tokens`. In a Framework profile, safe `bricks_theme_style` global slots are valid and must not be removed merely because a standalone Bricks Theme Style file has a different root shape.

Reject or mark for revision any Framework profile that carries custom token names in `overrides`, AppShell geometry, dock configuration, screen copy, products, posts, Bricks raw JSON, or runtime behavior. Project-specific variables/classes are valid only inside `settings.tokens.project`; they are not universal Seam tokens and are not automatically promoted into the universal library.

## AppShell theme manifest audit

`theme.json` must use current schema:

```json
{
  "schema": "kiwe.surface-theme.v1",
  "id": "...",
  "name": "...",
  "version": "...",
  "profile": "...",
  "screens": [],
  "requires": {}
}
```

Do not use stale manifest keys:

- `schemaVersion`
- `contract`
- `requiredUiContract`
- `supportedModes`
- `supportedPresentations`
- `supportedDockModes`
- `supportedDockShapes`
- `supportedColorModes`

Marketplace-ready AppShell themes should skin every registered core screen, even if the current Kiwe settings profile hides some dock icons:

- `profile`
- `cart`
- `checkout`
- `search`
- `menu`
- `saved`
- `links`
- `notifications`
- `ios-install`
- `games`
- `ai`

A theme that omits core screens must clearly label itself as partial/non-marketplace-ready.

## AppShell CSS authority audit

Importable theme CSS is presentation-only.

Installed theme CSS should use production selectors such as `[data-dsa-dock]`, `[data-dsa-screen]`, `.dsa-panel`, `.dsa-sheet`, and documented screen internals. Kiwe runtime-scopes installed theme CSS to the active `[data-dsa-surface].dsa-installed-theme-[theme-id]` root, but that root is transparent runtime scaffolding, not a paint surface. Mark the package as failed if importable `theme.css` assigns `background`, `background-color`, `background-image`, `border`, `box-shadow`, `filter`, `backdrop-filter`, or `opacity` directly to `[data-dsa-surface]`, `#dsa-surface`, or `.dsa-installed-theme-[theme-id]` root selectors. Root selectors may carry custom properties, inherited `color`, and inherited typography only. If a preview looks branded but the import CSS only styles preview-only selectors, mark it as a failure.

Also check whether the importable CSS uses live AppShell part hooks. Kiwe annotates live screen/sheet interiors with `data-dsa-screen-name` and `data-dsa-part`; protected `data-seam-*` shadow metadata may exist for tooling and AI understanding, but it is not an importable theme styling dependency. A marketplace AppShell theme should target documented live part hooks for screen composition and detail work, for example `[data-dsa-screen-name="cart"] [data-dsa-part="summary"]`, `[data-dsa-screen-name="menu"] [data-dsa-part="context"]`, `[data-dsa-screen-name="profile"] [data-dsa-part="identity"]`, `[data-dsa-part="card"]`, `[data-dsa-part="row"]`, and `[data-dsa-part="action"]`. If importable CSS only styles the root, dock, broad `.dsa-panel`/`[data-dsa-screen]`, buttons, and colors, mark it as a preview/live mismatch: that is a color skin, not a full DSA theme.

If `theme.json.screens` lists a registered screen, `appshell-theme/import/<theme-id>/css/theme.css` must target that screen's live runtime root from `screen-payloads.json`. A package that styles only preview fixture classes may pass a standalone screenshot and still fail live. Treat preview-fixture-only selectors in import CSS as a failure, especially `.dsa-screen-head`, `.dsa-screen-body`, `.dsa-profile-card`, `.dsa-score-card`, `.dsa-links-identity`, `.dsa-account-rows`, `.dsa-link-list`, `.dsa-install-steps`, and `.dsa-game-frame`.

The primary `combined-preview/index.html` must not use those private fixture-only DSA wrappers either. It is the human approval artifact, so it must resemble what Kiwe can render live after import. If custom mock wrappers are needed for optional selector experiments, put them only in an optional technical fixture and label them preview-only. Live core selectors such as `.dsa-ai-insight` are allowed when they exist in Kiwe runtime markup.

The primary combined preview must also not style DSA screen/sheet interiors through preview-only panel classes such as `.kiwe-preview-panel`, `.kiwe-preview-panel-heading`, `.kiwe-preview-alpha`, `.kiwe-preview-fbt`, `.kiwe-preview-score`, `.kiwe-preview-empty`, or `.kiwe-preview-muted`. Those are harness conveniences, not live Kiwe markup. Preview CSS may position the review harness, but the AppShell visual identity that the user approves must be present in `appshell-theme/import/<theme-id>/css/theme.css` against live Kiwe selectors.

Do not set AppShell geometry ownership in importable theme CSS on dock, screen, sheet, panel, or backdrop selectors:

- `position: fixed`
- `position: absolute`
- `inset`
- `top`
- `right`
- `bottom`
- `left`
- hardcoded `z-index`
- `width: 100vw`
- `height: 100vh`
- hardcoded viewport offsets

Those belong to Kiwe Geometry Engine or preview-only CSS.

Dock arrangement is also Geometry Engine-owned. On `[data-dsa-dock]`, `.dsa-dock`, `.dsa-dock-cluster`, `.dsa-phonekey-dock`, `[data-dsa-dock-focus]`, `[data-dsa-dock-primary]`, `.dsa-ai-launcher`, `.dsa-dock__button`, or `[data-dsa-module]`, fail import CSS that sets:

- `gap`, `row-gap`, or `column-gap`;
- `margin` or `padding` on the dock shell/control/focus item;
- width/height/inline-size/block-size/min/max sizing;
- `display`, `flex`, `grid`, `order`, `align-*`, `justify-*`, or `place-*`;
- `transform`, `translate`, `scale`, or `rotate`;
- `overflow` or `overflow-x/y`.

This is not cosmetic. A theme-defined split-dock gap or focus margin can make the outer dock shell technically centered while the visible buttons drift right/left or clip effects. Core owns split spacing and effect-safe gutters. Theme CSS may still style the dock visually with colors, borders, radius, shadows, icons, badges, labels, and state appearance.

Theme CSS may style:

- color;
- typography;
- border;
- radius;
- shadow;
- inner spacing;
- icons;
- badges;
- cards;
- buttons;
- forms;
- rails;
- state appearance.

Use Geometry Engine variables and Kiwe tokens where possible.

## Dock audit

The theme must not depend on a fixed dock composition.

Verify:

- full compact dock works;
- split compact dock works;
- navigation bar works;
- split dock is disabled/irrelevant when presentation is `navbar`;
- Navigation bar is not just horizontal dock. `dock.presentation="navbar"` is a separate mode; `horizontal` and `vertical` are compact dock orientations.
- horizontal and vertical orientation work;
- `pill`, `box`, and `square` shapes visibly differ;
- square/no-rounded shape is genuinely square or near-zero radius;
- adding/enabling another registered module later does not break spacing, badge placement, active state, focus state, or segment rounding.
- at 320px, 360px, and 390px, the visible dock controls remain centered as a group inside the Geometry Engine shell and retain adequate room for badges, outlines, glows, and shadows without horizontal clipping.

URL navigation in the dock is allowed through custom dock links. Do not invent a registered DSA module ID for URL navigation; use `dock.custom_items` and a custom item id such as `link-home`.

Do not use preview-only attributes as production contracts, such as:

- `data-open-screen`
- `data-nav-anchor`

If preview-only attributes are used, namespace/document them clearly and keep production contracts canonical.

## Screen coverage audit

Use `screen-payloads.json` as the screen truth.

Check that the theme preserves required roots/actions/selectors for:

- Profile account actions and rows.
- Cart quantity, checkout CTA, FBT rail, totals, empty state, and optional `settings.screens.cart` copy. For cart theming, verify the live-runtime hook family: `[data-dsa-cart-panel]`, `[data-dsa-cart-line]`, `.dsa-cart-line`, `.dsa-line-thumb`, `.dsa-quantity`, `[data-dsa-cart-fbt-rail]`, `[data-dsa-cart-fbt-card]`, `.dsa-fbt-card`, and `.dsa-fbt-img`.
- Checkout fields/notices/continue action without owning payment.
- Search form, input, filters, alphabet rail, results.
- Menu navigation and page table-of-contents anchors.
- Saved open/remove state.
- Links identity, social links, optional site score, commerce actions.
- Notifications topics/channels/contact fields/preferences.
- iOS install journey.
- Games shell/HUD/canvas frame without game-loop ownership.
- AI tray, insight actions, dismiss/status/report/chat placeholder without AI action ownership.

Links site score is optional. If absent, omit the score badge entirely. Do not show a blank, white, zero, or placeholder score card.

FBT must remain a horizontal side-scrolling rail in every theme. On mobile, FBT cards must retain enough width and internal layout to read the title/meta and reach the View/Add action; do not pass cramped rail cards that reduce product text to unreadable initials.

Checkout CTA and AI chat placeholder must flow with panel content and not float over products/messages.

Search has a stricter live/preview parity audit:

- `[data-dsa-search-form]` is a semantic/runtime form hook. Treat it as a neutral wrapper unless Kiwe core markup explicitly makes it the visual field. Fail themes/previews that put a second decorative pill/card/container on the form around an already-styled field.
- `[data-dsa-search-input]` must not autofocus on narrow/touch Sheet open. The initial Search surface should open without summoning the mobile keyboard; keyboard reserve is only tested after the user focuses the input.
- Alphabet/search filter chips must be visually centered round controls. Fail off-center letters caused by unbalanced padding, tiny line-height, or block display inside circular chips.

Transient Kiwe notification/toast audit:

- Do not attach notification toasts to the dock or position them from the AI/focus dock item.
- Desktop notifications should use a top-right safe-area viewport; mobile/touch notifications should use a top safe-area stack.
- The toast viewport must exist even when `ai` or `notifications` dock icons are hidden by theme settings. Dock visibility does not disable system feedback.
- Multiple notifications should cascade compactly and expand on hover/focus-within so all visible actions remain reachable.
- Use the live Kiwe proof hook `window.DSA.previewNotification({ title, body, actionLabel })` when a browser smoke test needs deterministic notification cards. Fail previews that create their own notification fixture JavaScript instead of exercising Kiwe's body-level stack.
- Notification theme CSS may style the cards, but production actions, dismiss state, AI action execution, browser notification permission, and push subscription remain Kiwe-owned.

External site popup/modal audit:

- Treat Kiwe dock, DSA sheets/screens, and site/Bricks popups as separate layers. DSA sheets/screens are AppShell-owned; page login popups, Bricks popups, offcanvas panels, lightboxes, search overlays, and third-party modals are page-owned.
- The dock must yield when an external page modal is active and no Kiwe DSA overlay is active. Fail outputs that show the dock sitting over a login/signup popup, newsletter popup, Bricks popup, lightbox, or page-owned dialog.
- Theme CSS must not solve popup overlap by assigning hardcoded z-index or fixed/absolute geometry to Kiwe dock/screen/sheet/backdrop selectors. The external-modal yield state is Kiwe core/Geometry Engine behavior.
- Combined previews should include or document at least one external page-modal state if the page design contains modal launchers, proving the popup owns its content layer while Kiwe-owned DSA launchers still open Kiwe surfaces.

## Responsive audit

Check narrow widths around:

- desktop Geometry Engine profile, e.g. 1280px or wider;
- tablet Geometry Engine profile, e.g. 768px-1024px;
- mobile Geometry Engine profile, e.g. 390px;
- 320px
- 360px
- 390px

No sheet/screen should create horizontal page or panel scrolling except intentional rails such as FBT or search/alphabet filters.

Decorative stripes, oversized labels, badges, score cards, logos, and pseudo-elements must shrink, wrap, clip inside the panel, or stack.

No content may render under the dock, navigation bar, safe area, or browser chrome reserve.

When testing mobile/touch Sheet mode, include a keyboard-reserved Search state: open Search, confirm no initial autofocus/keyboard, then focus the input and verify the sheet remains usable and the dock stays centered/in viewport rather than shifting or collapsing around the visual viewport.

## Preview audit

Preview files may simulate interactions, but production authority remains Kiwe/WordPress/Woo/Bricks-owned.

Preview-only JS must not:

- fetch remote data;
- use service workers;
- own history/focus lifecycle;
- own cart/checkout/payment/auth/save/AI/notification state;
- use localStorage/sessionStorage for capability state.

Combined preview should show the website/page behind the Kiwe AppShell. It should not replace the website artifact, and the website artifact must remain page-only.

Combined preview must include variation controls in the same preview for:

- desktop/tablet/mobile Geometry Engine profiles and narrow stress widths;
- Sheet and Classic modes;
- full compact dock, split compact dock, and Navigation bar;
- horizontal and vertical dock orientation;
- pill, rounded box, and square/no-radius dock shapes;
- light/dark;
- score present and score absent when Links is in scope.

If the website/page is loaded in an iframe and `website/bricks-paste.html` contains `data-dsa-open-module`, the combined preview needs preview-only bridge JavaScript so header/page Profile, Cart, Search, and Menu launchers open the matching DSA screen/sheet in the preview. Do not claim this passed unless you clicked or otherwise verified the launchers.

Classic mode must prove full app-viewport coverage unless a live Kiwe setting explicitly narrows it. Do not use a 390px side drawer as the only Classic proof.

Manual smoke tests that should be reported:

- page/header Profile launcher opens Profile surface;
- page/header Cart or Bag launcher opens Cart surface when commerce is in scope;
- page/header Search launcher opens Search surface;
- dock modules open the matching surfaces;
- repeated clicks/taps on one launcher keep one active surface and do not stack duplicate panels;
- close affordance closes the surface;
- Menu context anchors scroll to real page sections/headings;
- shape/presentation/device controls visibly change the preview without horizontal overflow.
- mobile/touch Search opens without automatic keyboard focus, and the focused-input state still respects the visual viewport/dock reserve.

## Report format

When revising a handoff, include a concise audit report:

1. Issues found in previous version.
2. Files changed.
3. What is now fixed.
4. What remains intentionally preview-only.
5. Any limitations or proposed core/plugin changes.

Do not claim the official Kiwe CLI/audit ran unless you actually executed it. If you performed a manual audit from this file, say that and do not report `STATUS: PASS`; use `WARN` or `UNVERIFIED` until executable validator proof exists.
