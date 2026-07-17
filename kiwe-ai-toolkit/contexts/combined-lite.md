# Kiwe combined handoff lite context

Use this file when a browser AI needs to create a Kiwe website/page plus Kiwe DSA/AppShell direction without reading the full repository.

Do not read the whole repo. Do not output a React/Vite/Tailwind/shadcn app. The output must be plain HTML/CSS with optional preview-only JS.

## Goal

Create a combined Kiwe handoff:

- a normal WordPress/Bricks website/page using Kiwe/Seam ideas; and
- a matching Kiwe DSA/AppShell theme direction for dock, sheets/screens, and AppShell chrome.

Keep these lanes separate:

- `website/` is for the page/website preview and Bricks paste artifact.
- `appshell-theme/` is for the importable Kiwe DSA theme package and its preview.
- `kiwe-settings/` is optional and only for Kiwe admin/profile settings.

## Required output shape

```text
combined-kiwe-handoff/
  README.md
  combined-preview/
    index.html
    assets/
      combined-preview.css  # optional, preview-only
      combined-preview.js   # optional, preview-only
  website/
    bricks-paste.html       # open in browser for website/page preview; paste/import through Bricks
    bricks-notes.md
  appshell-theme/
    README.md
    import/
      [theme-id]/
        theme.json
        css/
          theme.css
    preview/
      index.html
      PLACEHOLDERS.md
  kiwe-settings/
    kiwe-appsite-profile.json   # optional, only if settings change
    SETTINGS-NOTES.md           # optional, only if settings change
```

## Combined preview rule

`combined-preview/index.html` is the primary human review artifact for combined mode.

It should show the website/page behind the Kiwe DSA dock/sheet/screen so the site owner can judge the full AppShell experience in one place. This is where the DSA theme should be experienced over the actual page design.

The separate AppShell theme preview still exists, but it is a technical fixture:

- `appshell-theme/preview/index.html` proves the AppShell theme against validator selectors and shell states.
- `combined-preview/index.html` proves the paired experience.
- `website/bricks-paste.html` proves the page/Bricks lane and is also the Bricks import artifact.

The combined preview may simulate save/cart/search/screen switching only as preview-only behavior. Production behavior remains Kiwe/WordPress/WooCommerce/Bricks-owned.

The Kiwe AppShell is runtime chrome around the page, not part of the Bricks page itself. Combined mode has one review file that shows them together, but the deliverables remain separate:

- `combined-preview/index.html` may show the website/page with the Kiwe AppShell overlay.
- `website/bricks-paste.html` must be page-only and must not include `data-dsa-surface`, DSA dock markup, DSA sheet/screen markup, AppShell preview JS, or Kiwe runtime mocks.
- `appshell-theme/import/[theme-id]/css/theme.css` styles existing Kiwe AppShell selectors. It must not style the whole website.

## Website/page rules

- `website/bricks-paste.html` is required. It is the single website/page artifact: open it directly in a browser for visual review, then paste/import the same file through Bricks HTML-to-Bricks.
- Keep `website/bricks-paste.html` self-contained by default. Do not create duplicate `website/preview/index.html`, `site.css`, or `site.js` files unless the human explicitly asks for split files.
- Do not require React, Vite, Tailwind, shadcn, Next, a build pipeline, generated Bricks IDs, or hidden local files.
- Use semantic HTML, class-based CSS, reusable variables, minimal inline styles, and Bricks-friendly structure.

Seam is semantic/headless. Use Seam classes/attributes for meaning and structure where helpful, but use custom page CSS for the actual visual art direction.

Useful website Seam vocabulary:

- `seam-horizontal-rail`
- `seam-vertical-rail`
- `seam-story`
- `seam-article`
- `seam-card`
- `seam-grid`
- `seam-stack`
- `seam-cluster`
- `seam-tabs`
- `seam-toc`
- `data-role`
- `data-flow`
- `data-tone`
- `data-state`

Important: `data-role` is a controlled Seam vocabulary, not a free naming slot. Use official broad roles only, such as `hero`, `card`, `nav`, `button`, `form`, `testimonial`, `price`, or `footer`. For specific ecommerce/editorial concepts, use Seam Class Vocabulary names such as `.seam-product-card`, `.seam-product-rail`, `.seam-product-grid`, `.seam-story`, `.seam-article`, plus project classes such as `.nc-product`. If extra preview semantics are needed, use `data-project-role`, not custom `data-role` values. Do not invent values such as `product-card`, `site-header`, `save-placeholder`, `add-to-cart-placeholder`, `category-link`, or `search-placeholder`.

## Page-to-AppShell hooks

The website/page may include Kiwe hooks, but must not implement Kiwe behavior itself.

- Page/header controls that open AppShell modules should use canonical `data-dsa-open-module="cart"`. Valid module values include `menu`, `search`, `profile`, `links`, `saved`, `cart`, `theme`, `ai`, `notifications`, and `ios-install`.
- Do not add Seam attributes only to feed the DSA Menu. Kiwe Menu context is heading-first and reads the admin-selected H1/H2/H3-style levels for classic table-of-contents behavior. If no configured headings exist, Kiwe may use existing semantic page sections (`data-role="section"` or `.seam-section`) with a stable `id` and standard label sources as fallback context. Build the page semantically for the page first; Kiwe consumes that meaning opportunistically.
- Save/wishlist/bookmark affordances should use Kiwe save hooks from the toolkit/contracts. Do not create local storage or duplicate save authority except as clearly labelled preview-only behavior.
- Example header buttons: `<button type="button" data-dsa-open-module="profile" aria-label="Open account">...</button>` and `<button type="button" data-dsa-open-module="cart" aria-label="Open cart">...</button>`.

Preview-only JS may simulate these hooks inside `combined-preview/index.html`, but `website/bricks-paste.html` should keep the real attributes so the live plugin owns behavior after Bricks import.

## Authority boundaries

Do not create production authority for:

- cart, checkout, payment, discounts, orders, WooCommerce mutation;
- auth, PhoneKey, profile updates, logout, addresses, downloads;
- search query authority or Bricks query authority;
- save/bookmark/wishlist state authority;
- service workers, cache policy, browser history, focus trap, scroll lock;
- AI actions, notification permission, Push subscription, privacy/master switches.

Preview UI can show these affordances, but production behavior must be documented as Kiwe/WordPress/WooCommerce/Bricks-owned.

## AppShell theme.json quick contract

If your output includes `appshell-theme/import/[theme-id]/theme.json`, copy this shape and only change theme-specific values.

Do not invent alternate manifest keys.

Important:

- Use `schema`, not `type`.
- Do not use `schemaVersion` in AppShell theme manifests. `schemaVersion` is only used by optional Kiwe settings profiles.
- Do not use nested `contracts`, `colorAuthority`, `authority`, `supportedPresentationModes`, `supportedDockShapes`, `cssFiles`, or object-form `supports`.
- `supports` must be an array of allowed strings.
- `screens` must use Kiwe screen names only and must match the brief/settings. Do not list cart, checkout, or profile by default for a non-commerce or non-membership website just because those screens exist.
- For a news/editorial website, the usual screen set is `search`, `menu`, `saved`, `links`, `notifications`, `ios-install`, and `ai`. Add `cart`/`checkout` only for commerce, WooCommerce, shop, products, paid reports, subscriptions, or checkout. Add `profile` when account, membership, login, personalization, orders, downloads, or addresses are truly part of the brief.

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

For combined website/page + AppShell handoffs, match the AppShell screens to the website type. A news/editorial website should not automatically show cart, checkout, orders, downloads, or addresses just because the prompt says "Netflix-like"; only include commerce/account screens when the brief, Kiwe settings profile, or site business model requires them.

## AppShell importable CSS rules

The importable package is only:

- `theme.json`
- CSS files listed in `theme.json`
- listed static image assets, if any
- optional human README

The import package must not contain JavaScript, TypeScript, PHP, HTML, WASM, remote fonts, remote scripts, trackers, service workers, executable files, remote `@import`, remote `url()`, data URLs, or JavaScript URLs.

The theme CSS is presentation-only. It may style existing DSA selectors and allowed public Seam classes. It must not create runtime authority.

The Geometry Engine owns AppShell placement and measurement. Importable theme CSS must not assign core geometry to dock, sheet, screen, or backdrop selectors. Do not set `position: fixed`, `position: absolute`, `inset`, `top`, `right`, `bottom`, `left`, hardcoded `z-index`, `width: 100vw`, `height: 100vh`, or hardcoded viewport offsets on `[data-dsa-dock]`, `.dsa-dock`, `[data-dsa-screen]`, `.dsa-panel`, `.dsa-sheet`, `[data-dsa-screen-backdrop]`, or sheet/screen backdrop selectors. Those values belong in Kiwe core or preview-only CSS. Theme CSS may style color, typography, border, radius, shadow, spacing inside content, icons, badges, cards, buttons, and state appearance while consuming Geometry Engine variables.

## AppShell preview quick contract

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

It is valid to create distinctive presentations for existing `ai` and `notifications` screens. They must use Kiwe-owned AI/notification payloads/actions and must not execute AI actions, notification permission requests, push subscription, dismiss state, or privacy/master-switch behavior from theme or preview code.

Responsive fit is mandatory. Test the AppShell preview at narrow widths around 320px, 360px, and 390px. No DSA sheet/screen may create horizontal page or panel scrolling except intentional rails such as FBT, alphabet/search filters, or another documented horizontal rail. Decorative header stripes, badges, labels, and pseudo-elements must shrink, wrap, clip inside the panel, or stack; do not use non-shrinking flex decorations that can force the panel wider than the viewport.

## Kiwe settings/profile quick rules

If the design changes dock composition or shell behavior, include `kiwe-settings/kiwe-appsite-profile.json` and `kiwe-settings/SETTINGS-NOTES.md`.

Important dock settings:

- `dock.presentation`: `dock` or `navbar`.
- `dock.split_style`: split compact dock on/off. It only applies when presentation is `dock`.
- `dock.shape`: `pill`, `box`, or `square`.
- `dock.enabled_items` and `dock.item_order`: visible built-in modules and their order.
- `dock.focus_item`: the enabled item that becomes the emphasized/focus button and split-dock center. Default is `ai`, but a design may choose `search`, `cart`, or a custom link when justified.
- `dock.custom_items`: safe URL navigation items such as Home. Custom dock links navigate only; they do not create new DSA screens.

Example:

```json
{
  "type": "kiwe-appsite-profile",
  "schemaVersion": 1,
  "settings": {
    "dock": {
      "presentation": "dock",
      "split_style": true,
      "shape": "pill",
      "focus_item": "ai",
      "enabled_items": {
        "menu": true,
        "search": true,
        "profile": true,
        "links": true,
        "saved": true,
        "cart": true,
        "theme": false,
        "ai": true,
        "link-home": true
      },
      "item_order": ["link-home", "menu", "search", "profile", "links", "saved", "cart", "theme", "ai"],
      "custom_items": [
        { "id": "link-home", "label": "Home", "url": "/", "icon": "home", "enabled": true }
      ]
    }
  }
}
```

Hiding a dock item only hides the dock button. It does not delete the registered DSA module. Header/Bricks/page launchers may still open modules with canonical `data-dsa-open-module`.

Treat Kiwe settings as a site preset, not the boundary of the AppShell theme. The importable theme should provide resilient baseline styling for every registered core DSA screen even when the current combined preview hides some icons/screens. A news/editorial preset may hide cart/checkout visually, but if the site owner later enables cart, notifications, AI, or another registered module in Kiwe admin, that module should inherit the theme’s shared panel/card/button/form/badge/CTA language rather than falling back to a broken or mismatched look.

## AppShell README requirements

The AppShell handoff README must include:

- distinctness note / visual thesis;
- screen coverage summary;
- shell mode coverage summary;
- selector-fit checklist;
- intentional limitations;
- core/plugin changes section, including "no core/plugin changes" when true;
- Seam AppShell adoption map acknowledgement;
- validation commands.

## Visual originality

Avoid generic Aurora/glass/bento/neon sameness unless explicitly requested. If using blur/glass, explain why it is not just another frosted-card theme. Prefer a distinct thesis such as editorial newsroom, cinematic markets desk, luxury business briefing, tactical founder dashboard, or quiet OS.

## Validation commands

The final handoff should be able to pass:

```bash
node tools/ui-theme/validate-package.cjs appshell-theme/import/[theme-id]
node tools/ui-theme/validate-handoff.cjs appshell-theme
```

For generated AI output review, also use:

```bash
node kiwe-ai-toolkit/tools/audit-output.cjs combined-kiwe-handoff
```
