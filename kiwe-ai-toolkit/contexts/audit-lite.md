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
- `appshell-theme/preview/index.html` exists as a technical selector/state fixture.
- `appshell-theme/preview/PLACEHOLDERS.md` explicitly says all mock data/content is preview-only.
- `kiwe-settings/` exists when the design changes dock composition, focus item, module visibility/order, presentation, shape, or other Kiwe runtime settings.

For website-only mode, do not output duplicate preview folders unless explicitly requested. `website/bricks-paste.html` is both browser preview and Bricks paste/import artifact.

For combined mode, do not duplicate the website inside the AppShell import package. The AppShell is runtime chrome around the page, not part of the Bricks page.

## Website / Bricks audit

`website/bricks-paste.html` must be page-only:

- no DSA dock/sheet/screen markup;
- no AppShell preview controller;
- no `data-dsa-surface`, `.dsa-dock`, `.dsa-sheet`, or `.dsa-panel` AppShell shell markup;
- no duplicate cart/search/profile/auth/save/AI runtime authority.

Page/header controls that open Kiwe modules must use canonical hooks:

```html
data-dsa-open-module="cart"
data-dsa-open-module="profile"
data-dsa-open-module="search"
```

Use only registered module names: `menu`, `search`, `profile`, `links`, `saved`, `cart`, `theme`, `ai`, `notifications`, `ios-install`, `games`.

Home or other URL-only dock items are valid. Do not call them invalid merely because they are not built-in DSA screens. The requirement is that they must be declared as custom dock links in `kiwe-settings`, not invented as registered DSA modules.

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

## Kiwe settings audit

Include `kiwe-settings/kiwe-appsite-profile.json` when the design changes runtime settings.

Common settings to declare:

- `dock.presentation`: `dock` or `navbar`.
- `dock.split_style`: split compact dock on/off; only applies when presentation is `dock`.
- `dock.shape`: `pill`, `box`, or `square`.
- `dock.enabled_items`: visible built-in modules/custom links.
- `dock.item_order`: visible item order.
- `dock.focus_item`: the emphasized/focus item and split-dock center.
- `dock.custom_items`: URL-only custom dock links such as Home, Shop, About, Offers, or any safe site URL. These are first-class Kiwe dock items, but they navigate only and do not create new DSA screens.

Do not use `theme.json` for Kiwe settings profile schema. `theme.json` is the AppShell theme manifest. `kiwe-settings/kiwe-appsite-profile.json` is the settings/profile artifact.

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
- Cart quantity, checkout CTA, FBT rail, totals, empty state.
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

## Report format

When revising a handoff, include a concise audit report:

1. Issues found in previous version.
2. Files changed.
3. What is now fixed.
4. What remains intentionally preview-only.
5. Any limitations or proposed core/plugin changes.

Do not claim the official Kiwe CLI/audit ran unless you actually executed it. If you performed a manual audit from this file, say that.
