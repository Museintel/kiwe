# Kiwe AI audit-lite context

Use this file when revising an existing Kiwe handoff from v1 to v2/v3/v4, or when the human asks you to audit your own output against the Kiwe AI Toolkit.

Do not read the full Kiwe repository. Use this audit context together with the relevant mode context:

- Website/page only: `contexts/website.md`
- AppShell theme only: `contexts/theme.md`
- Website/page + AppShell: `contexts/combined-lite.md`

If you can run shell commands, also run:

```bash
node kiwe-ai-toolkit/tools/validate-output.cjs /path/to/handoff --mode combined
node kiwe-ai-toolkit/tools/audit-output.cjs /path/to/handoff
```

If you cannot run code, perform the manual audit below and report every issue you found and fixed.

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

For combined mode, do not duplicate the website inside the AppShell import package. The AppShell is runtime chrome around the page, not part of the Bricks page.

Do not create separate human review previews for the website and AppShell in combined mode. The reviewer should open one combined preview and see the website/page behind the Kiwe AppShell.

## Website / Bricks audit

`website/bricks-paste.html` must be page-only:

- no DSA dock/sheet/screen markup;
- no AppShell preview controller;
- no `data-dsa-surface`, `.dsa-dock`, `.dsa-sheet`, or `.dsa-panel` AppShell shell markup;
- no duplicate cart/search/profile/auth/save/AI runtime authority.

If the handoff is being applied to a real staging site through Kiwe AI, prefer the controlled `bricks.page.from-html` or `bricks.template.from-html` executor path over browser clipboard paste. The handoff author should still provide clean HTML/CSS, not raw Bricks JSON, unless a verified target explicitly asks for raw JSON. The auditor should check that the HTML/CSS is converter-friendly: semantic nesting, stable classes, preserved `data-dsa-open-module` launchers, no huge base64 payloads, no script-owned production behavior, and CSS that can safely live in Bricks page `customCss`.

Page/header controls that open Kiwe modules must use canonical hooks:

```html
data-dsa-open-module="cart"
data-dsa-open-module="profile"
data-dsa-open-module="search"
```

Use only registered module names: `menu`, `search`, `profile`, `links`, `saved`, `cart`, `theme`, `ai`, `notifications`, `ios-install`, `games`.

Home or other URL-only dock items are valid. Do not call them invalid merely because they are not built-in DSA screens. The requirement is that they must be declared as custom dock links in the theme package `settings.dock.custom_items`, not invented as registered DSA modules.

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
- `screens.cart`: optional presentation/copy labels for the live cart adapter. Allowed text keys are `label`, `eyebrow`, `title`, `emptyTitle`, `emptyText`, `fbtTitle`, `checkoutLabel`, and `checkoutEmptyLabel`. This lane must not contain cart data, product IDs, prices, totals, checkout URLs, JavaScript, endpoints, or state authority.

Do not use `theme.json` for Kiwe settings. `theme.json` is the AppShell theme manifest. Theme settings belong in `theme-package.json` at root `settings`, beside the root `theme` manifest and root `css` import CSS.

If a preview shows custom live-intended cart copy such as "Your tea-time bag" or "Pairs well with", verify that the same copy is present in `theme-package.json` under `settings.screens.cart`. If it is absent, mark the package as a preview/live mismatch. If the copy is intentionally preview-only, `PLACEHOLDERS.md` must say so explicitly.

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

Installed theme CSS should use production selectors such as `[data-dsa-surface]`, `[data-dsa-dock]`, `[data-dsa-screen]`, `.dsa-panel`, and documented screen internals. Kiwe runtime-scopes installed theme CSS to the active surface so correct `[data-dsa-surface]` selectors can beat core visual defaults while core keeps geometry/state ownership. If a preview looks branded but the import CSS only styles preview-only selectors, mark it as a failure.

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

FBT must remain a horizontal side-scrolling rail in every theme.

Checkout CTA and AI chat placeholder must flow with panel content and not float over products/messages.

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
- close affordance closes the surface;
- Menu context anchors scroll to real page sections/headings;
- shape/presentation/device controls visibly change the preview without horizontal overflow.

## Report format

When revising a handoff, include a concise audit report:

1. Issues found in previous version.
2. Files changed.
3. What is now fixed.
4. What remains intentionally preview-only.
5. Any limitations or proposed core/plugin changes.

Do not claim the official Kiwe CLI/audit ran unless you actually executed it. If you performed a manual audit from this file, say that.
