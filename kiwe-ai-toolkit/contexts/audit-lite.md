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
node kiwe-ai-toolkit/tools/audit-output.cjs /path/to/handoff
```

If you cannot run code, perform the manual audit below and report every issue you found and fixed.

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
- `website/bricks-notes.md` exists.
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

## Website / Bricks audit

`website/bricks-paste.html` must be page-only:

- no DSA dock/sheet/screen markup;
- no AppShell preview controller;
- no `data-dsa-surface`, `.dsa-dock`, `.dsa-sheet`, or `.dsa-panel` AppShell shell markup;
- no duplicate cart/search/profile/auth/save/AI runtime authority.

If the handoff is being applied to a real staging site through Kiwe AI, prefer the controlled `bricks.page.from-html` or `bricks.template.from-html` executor path over browser clipboard paste. The handoff author should still provide clean HTML/CSS, not raw Bricks JSON, unless a verified target explicitly asks for raw JSON. The auditor should check that the HTML/CSS is converter-friendly: semantic nesting, stable classes, preserved `data-dsa-open-module` launchers, no huge base64 payloads, no script-owned production behavior, and CSS that can safely live in Bricks page `customCss`.

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
- optional `settings.tokens.bricks_theme_style` metadata.

Reject or mark for revision any Framework profile that carries custom token names, AppShell geometry, dock configuration, screen copy, products, posts, Bricks raw JSON, or runtime behavior.

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

Do not claim the official Kiwe CLI/audit ran unless you actually executed it. If you performed a manual audit from this file, say that.
