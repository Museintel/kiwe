# Kiwe combined handoff lite context

Use this file when a browser AI needs to create a Kiwe website/page plus Kiwe DSA/AppShell direction without reading the full repository.

Do not read the whole repo. Do not output a React/Vite/Tailwind/shadcn app. The output must be plain HTML/CSS with optional preview-only JS.

## Goal

Create a combined Kiwe handoff:

- a normal WordPress/Bricks website/page using Kiwe/Seam ideas; and
- a matching Kiwe DSA/AppShell theme direction for dock, sheets/screens, and AppShell chrome.

Keep these lanes separate:

- `website/` is for the page/website preview and Bricks paste artifact.
- `appshell-theme/` is for the importable Kiwe DSA theme package. Theme settings travel inside the theme package, not as a separate settings import/export lane.

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
        theme-package.json # single Kiwe admin/API import file: manifest + CSS + safe settings preset
        theme.json
        css/
          theme.css
    preview/                      # optional technical fixture only
      index.html
      PLACEHOLDERS.md
```

## Combined preview rule

`combined-preview/index.html` is the primary human review artifact for combined mode.

It should show the website/page behind the Kiwe DSA dock/sheet/screen so the site owner can judge the full AppShell experience in one place. This is where the DSA theme should be experienced over the actual page design.

Do not create separate human review previews for website and AppShell. Combined mode has one primary preview:

- `combined-preview/index.html` proves the paired page + AppShell experience.
- `website/bricks-paste.html` proves the page/Bricks lane and is also the Bricks import artifact.
- `appshell-theme/preview/index.html`, if included, is optional technical fixture only.

The combined preview may simulate save/cart/search/screen switching only as preview-only behavior. Production behavior remains Kiwe/WordPress/WooCommerce/Bricks-owned.

## Optional target-site Studio / Companion

If the human gives a target-site Kiwe AI key and Studio or Companion AI is enabled in `Kiwe > AI`, ask Kiwe for a token-saving Studio packet before guessing Kiwe details:

```text
GET      /wp-json/dsa/v1/ai/studio/status
POST     /wp-json/dsa/v1/ai/studio/start
POST     /wp-json/dsa/v1/ai/studio/draft
POST     /wp-json/dsa/v1/ai/studio/review
GET|POST /wp-json/dsa/v1/ai/bricks/context
POST     /wp-json/dsa/v1/ai/bricks/plan
GET|POST /wp-json/dsa/v1/ai/companion/context
POST     /wp-json/dsa/v1/ai/companion/ask
POST     /wp-json/dsa/v1/ai/companion/review-output
GET|POST /wp-json/dsa/v1/ai/audit-companion/context
POST     /wp-json/dsa/v1/ai/audit-companion/review
```

Studio AI has three operating modes: `native`, `browser_companion`, and `browser_only`. In `browser_companion` mode, the external AI should use `/ai/studio/start` for the compact context packet and `/ai/studio/review` after v1 output. In `native` mode, `/ai/studio/draft` may call the configured provider only if Kiwe > AI enables native generation and the key has `native_ai` scope. In `browser_only` mode, rely on this toolkit and do not expect internal Kiwe AI. The Companion is a compact context broker and deterministic reviewer, not a production behavior owner. It can return mode-aware context cards, site-specific hints, previous audit-failure fingerprints, and safe next-action guidance. For revisions, prefer `/ai/audit-companion/review`: submit the actual file map, fix its `mustFix` list, then run your normal self-audit/explanation. This is the token-saving path; it is not a substitute for browser rendering, Bricks import, WooCommerce, checkout/auth/cart, or live Kiwe install tests. It must not replace this contract, read the whole plugin, save Bricks, publish content, mutate WooCommerce, run cart/checkout/auth, or change SecureTrack enforcement. Redacted SecureTrack context is available only when `Kiwe > AI` enables it and the key has `all`, `security_brief`, or `companion_securetrack` scope.

For Bricks-native planning, use `/ai/bricks/context` or `/ai/bricks/plan` when a target-site key has `bricks_ai`, `studio_ai`, or `all` scope. This gives the external AI a compact map of available Bricks elements, element controls, query loops, dynamic tags, conditions, interactions, Seam headless rules, and Kiwe launcher/runtime boundaries without reading Bricks or Kiwe source. The Bricks AI route is read-only and cannot save a page/template by itself.

The combined preview must include controls in the same file for: desktop/tablet/mobile Geometry Engine profiles, narrow 320/360/390 stress widths, Sheet and Classic, full compact dock, split compact dock, Navigation bar, horizontal/vertical dock orientation, pill/rounded-box/square dock shapes, light/dark, and representative screen switching. Navigation bar is not horizontal dock; `navbar` is a separate presentation mode, while `horizontal` and `vertical` are orientations of compact dock.

Classic mode must prove full app-viewport coverage unless a live Kiwe setting explicitly narrows it. Do not use a 390px side drawer as the only Classic proof.

The Kiwe AppShell is runtime chrome around the page, not part of the Bricks page itself. Combined mode has one review file that shows them together, but the deliverables remain separate:

- `combined-preview/index.html` may show the website/page with the Kiwe AppShell overlay.
- `website/bricks-paste.html` must be page-only and must not include `data-dsa-surface`, DSA dock markup, DSA sheet/screen markup, AppShell preview JS, or Kiwe runtime mocks.
- `appshell-theme/import/[theme-id]/css/theme.css` styles existing Kiwe AppShell selectors. It must not style the whole website.

## Website/page rules

- `website/bricks-paste.html` is required. It is the single website/page artifact: open it directly in a browser for visual review, then paste/import the same file through Bricks HTML-to-Bricks, or feed it to Kiwe's controlled staging operation `bricks.page.from-html` / `bricks.template.from-html` when a target site API key is available.
- Keep `website/bricks-paste.html` self-contained by default. Do not create duplicate `website/preview/index.html`, `site.css`, or `site.js` files unless the human explicitly asks for split files.
- Do not require React, Vite, Tailwind, shadcn, Next, a build pipeline, generated Bricks IDs, or hidden local files.
- Use semantic HTML, class-based CSS, reusable variables, minimal inline styles, and Bricks-friendly structure.
- Do not hand-author raw Bricks `_bricks_page_content_2` JSON inside combined mode. If the human explicitly asks for a Bricks JSON artifact, route to `/convert /bricks` after visual/dynamic approval and produce a reviewable `bricks-conversion/kiwe-bricks-conversion.json` package. Kiwe's staging executor can convert clean HTML/CSS into Bricks JSON while preserving Seam classes/data attributes and storing safe CSS in Bricks page settings.

Seam is semantic/headless. Use Seam classes/attributes for meaning and structure where helpful, but use custom page CSS for the actual visual art direction.

If the paired design has a distinctive live-intended palette, type scale, font pairing, site background, spacing, radius, or shadow system, declare that design-token personality inside `appshell-theme/import/[theme-id]/theme-package.json` at `settings.tokens`. For combined marketplace AppShell themes this is required, not optional, because DSA, Seam page CSS, and Bricks global theme style must share the same token profile. Do not leave the brand system only inside preview CSS. A separate `framework/kiwe-framework-profile.json` with `schema: "kiwe.framework-profile.v1"` is reserved for website/page-only handoffs or explicit standalone `Kiwe > Framework` imports; combined mode uses the theme package token lane.

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

If the combined preview loads `website/bricks-paste.html` in an iframe, add preview-only bridge JavaScript so those canonical page/header launchers open the matching DSA screen/sheet in the preview. Do not claim Profile, Cart, Search, or Menu launcher proof unless you clicked or otherwise verified those launchers.

Repeated launcher activation must be idempotent. Clicking/tapping the same dock or page/header launcher rapidly must leave one active Kiwe screen/sheet for that module, not stacked duplicate panels, doubled backdrops, or repeated "pop" animations. Preview-only JS should model this same single-active-surface behavior so the review catches accidental duplicate render paths.

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
- Do not use `schemaVersion` in AppShell theme manifests. `schemaVersion` is only used by `theme-package.json` wrappers and other package/profile wrappers.
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

For combined website/page + AppShell handoffs, match the AppShell screens to the website type. A news/editorial website should not automatically show cart, checkout, orders, downloads, or addresses just because the prompt says "Netflix-like"; only include commerce/account screens when the brief, Kiwe theme settings preset, or site business model requires them.

## AppShell importable CSS rules

The importable package is only:

- `theme-package.json`
- `theme.json`
- CSS files listed in `theme.json`
- listed static image assets, if any
- optional human README

The import package must not contain JavaScript, TypeScript, PHP, HTML, WASM, remote fonts, remote scripts, trackers, service workers, executable files, remote `@import`, remote `url()`, data URLs, or JavaScript URLs.

The theme CSS is presentation-only. It may style existing DSA selectors and allowed public Seam classes. It must not create runtime authority. In production, Kiwe runtime-scopes installed theme CSS to the active `#dsa-surface[data-dsa-surface].dsa-installed-theme-[theme-id]` root. That root is transparent Kiwe runtime scaffolding: importable theme CSS may set custom properties, inherited color, and inherited typography there, but it must not paint the root with `background`, `background-color`, `background-image`, `border`, `box-shadow`, `filter`, `backdrop-filter`, or `opacity`. Put visual paint on `[data-dsa-dock]`, `[data-dsa-screen]`, `.dsa-panel`, `.dsa-sheet`, and documented `data-dsa-part` internals instead.

The Geometry Engine owns AppShell placement and measurement. Importable theme CSS must not assign core geometry to dock, sheet, screen, or backdrop selectors. Do not set `position: fixed`, `position: absolute`, `inset`, `top`, `right`, `bottom`, `left`, hardcoded `z-index`, `width: 100vw`, `height: 100vh`, or hardcoded viewport offsets on `[data-dsa-dock]`, `.dsa-dock`, `.dsa-dock-cluster`, `.dsa-phonekey-dock`, `[data-dsa-screen]`, `.dsa-panel`, `.dsa-sheet`, `[data-dsa-screen-backdrop]`, or sheet/screen backdrop selectors. Those values belong in Kiwe core or preview-only CSS.

Importable theme CSS must not use anonymous raw CSS literals such as hardcoded lengths (`22px`, `1.25rem`, `100vw`), color literals/functions (`#fff`, `rgb(...)`, `oklch(...)`), or literal shadow/effect recipes (`box-shadow: 0 18px 48px ...`). A concrete base value is allowed in the token definition layer (`theme-package.json settings.tokens`, Kiwe core token registries, or generated runtime variables), but installed theme CSS must consume named `--kiwe-*` universal tokens, documented `--kiwe-theme-*` aliases, or Kiwe/DSA Geometry Engine variables. This keeps AppShell themes portable across sites, Bricks token export, device profiles, and future core geometry changes.

Dock arrangement is especially protected because small CSS changes can make a centered shell look right-biased on mobile. Importable theme CSS must not set `gap`, `row-gap`, `column-gap`, `margin`, `padding`, `width`, `height`, `inline-size`, `block-size`, `min/max-*` sizing, `display`, `flex`, `grid`, `order`, `align-*`, `justify-*`, `place-*`, `transform`, `translate`, `scale`, `rotate`, or `overflow` on dock shell/control/focus selectors such as `[data-dsa-dock]`, `.dsa-dock`, `.dsa-dock-cluster`, `.dsa-phonekey-dock`, `[data-dsa-dock-focus]`, `[data-dsa-dock-primary]`, `.dsa-ai-launcher`, `.dsa-dock__button`, or `[data-dsa-module]`. Use `settings.dock`, official dock shapes, and Geometry Engine variables for composition. Theme CSS may style color, typography, border, radius, shadow, icons, badges, cards, buttons, content spacing, rails, and state appearance while consuming Geometry Engine variables. Visual effects must fit inside the Geometry Engine's safe gutters; do not use negative margins, transforms, or overflow clipping to create glow/shadow space.

## AppShell preview quick contract

In combined mode, `combined-preview/index.html` must prove the theme against Kiwe's actual preview selectors and Geometry Engine states over the page. A pretty mock phone is not enough. If you also include `appshell-theme/preview/index.html`, label it as optional technical proof only.

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

The importable `theme.css` must style live Kiwe roots and documented runtime internals. The primary combined preview must also use live-like DSA roots/internals so the review preview resembles the installed theme. Do not make the installed theme or primary combined preview depend on private fixture-only classes such as `.dsa-screen-head`, `.dsa-screen-body`, `.dsa-profile-card`, `.dsa-score-card`, `.dsa-links-identity`, `.dsa-account-rows`, `.dsa-link-list`, `.dsa-install-steps`, or `.dsa-game-frame`. Do not style DSA screen/sheet interiors in the primary combined preview through harness-only classes such as `.kiwe-preview-panel`, `.kiwe-preview-panel-heading`, `.kiwe-preview-alpha`, `.kiwe-preview-fbt`, `.kiwe-preview-score`, `.kiwe-preview-empty`, or `.kiwe-preview-muted`; preview CSS may position the harness, but the approved AppShell look must come from importable `theme.css`. Those names may exist only in an optional technical fixture that is clearly labelled preview-only, not in the main combined visual proof. If `theme.json.screens` lists a screen, `theme.css` must target that screen's live root below.

For real visual distinction, theme CSS must use live AppShell part hooks, not only broad root/panel color rules. Kiwe annotates live screens with `data-dsa-screen-name` and `data-dsa-part`; it may also attach protected `data-seam-*` shadow metadata for tooling and AI understanding, but importable theme CSS should not style or depend on those protected attributes. Use selectors such as `[data-dsa-screen-name="cart"] [data-dsa-part="summary"]`, `[data-dsa-screen-name="menu"] [data-dsa-part="context"]`, `[data-dsa-screen-name="profile"] [data-dsa-part="identity"]`, `[data-dsa-part="card"]`, `[data-dsa-part="row"]`, and `[data-dsa-part="action"]` to shape live screens. A theme that only changes `[data-dsa-screen]`, `.dsa-panel`, buttons, and colors is a skin, not a marketplace AppShell theme, and should be revised.

Required screen selectors when the theme manifest lists these screens:

- `profile`: `data-dsa-profile-panel`
- `cart`: `data-dsa-cart-panel` and `data-dsa-cart-fbt-rail`; stable cart internals include `[data-dsa-cart-line]`, `.dsa-cart-line`, `.dsa-line-thumb`, `.dsa-quantity`, `[data-dsa-cart-fbt-card]`, `.dsa-fbt-card`, and `.dsa-fbt-img`
- `checkout`: `data-dsa-checkout-panel` and `data-dsa-checkout-form`
- `search`: `data-dsa-search-panel`, `data-dsa-search-form`, `data-dsa-search-input`, and `data-dsa-search-results`
- `menu`: `dsa-menu-panel`
- `saved`: `data-dsa-saved-panel`
- `links`: `dsa-links-panel`
- `notifications`: `data-dsa-notification-panel`
- `ios-install`: `data-dsa-ios-install-panel`
- `games`: `data-dsa-game-panel`
- `ai`: `data-dsa-ai-panel`

Search screen styling has one extra mobile rule because it is a common failure point. `data-dsa-search-form` is a semantic/runtime form hook, not the outer visual search container. Do not draw an extra pill/card/container on `[data-dsa-search-form]` that wraps an already-styled search field; style the actual field/control surface and keep the form wrapper visually neutral unless Kiwe core markup explicitly makes it the field. On narrow/touch Sheet surfaces, the search input must not autofocus and summon the mobile keyboard on open. The user should choose the input before the keyboard appears. Alphabet/search filter chips should remain centered circular/round controls; themes may change color and border treatment, but must not rely on loose padding/line-height that leaves letters off-center.

Cart FBT must be a horizontal rail. Include `data-dsa-cart-fbt-rail` on that rail. Do not render it as a stacked list, and do not shrink mobile FBT cards so far that product names, the "Pairs well with"/FBT title, price/meta, or the View/Add action become unreadable. On narrow screens, prefer fewer readable rail cards in view over many cramped cards.

Links site score is optional. The preview and README must show/document both:

- score present; and
- score absent/no score/without score, where no badge is rendered at all.

It is valid to create distinctive presentations for existing `ai` and `notifications` screens. They must use Kiwe-owned AI/notification payloads/actions and must not execute AI actions, notification permission requests, push subscription, dismiss state, or privacy/master-switch behavior from theme or preview code.

Transient Kiwe notifications/toasts are not dock attachments. In desktop Geometry Engine profiles they should render from a body-level notification viewport in the top-right safe area, independent of whether the `ai` or `notifications` dock icons are enabled. Multiple notifications should read as a compact cascade that expands on hover/focus so actions such as Dismiss, Apply, View, or Open remain reachable. On mobile/touch profiles the same notification stack appears from the top safe area rather than from the dock. For live/combined preview proof, use Kiwe's runtime hook `window.DSA.previewNotification({ title, body, actionLabel })` after the AppShell has booted; do not invent a separate notification fixture, dock-attached toast, push-permission simulator, or theme-owned notification JavaScript.

External site/Bricks popups are not DSA screens. The Kiwe dock is persistent AppShell chrome, and Kiwe DSA sheets/screens are Geometry Engine-owned surfaces; a page login popup, Bricks popup, offcanvas, lightbox, search overlay, or third-party modal is page-owned. When an external modal is active, the live Kiwe runtime yields the dock instead of placing it above the popup. Theme CSS and combined previews must not raise dock z-index to compete with site popups or treat the dock as part of popup content. Preview simulations should prove that canonical page launchers open DSA surfaces, while ordinary page modals keep their own modal authority and do not have the dock visually sitting on top of them.

Responsive fit is mandatory. Test desktop, tablet, and mobile Geometry Engine profiles, then narrow stress widths around 320px, 360px, and 390px. No DSA sheet/screen may create horizontal page or panel scrolling except intentional rails such as FBT, alphabet/search filters, or another documented horizontal rail. Decorative header stripes, badges, labels, and pseudo-elements must shrink, wrap, clip inside the panel, or stack; do not use non-shrinking flex decorations that can force the panel wider than the viewport. On mobile/touch Sheet surfaces, also test the Search screen with the input unfocused and focused/keyboard-reserved. Opening Search should not shrink the initial sheet because the keyboard was summoned automatically, and focusing the input should not push the dock off-screen or misalign the dock against the visual viewport.

## Kiwe theme package settings quick rules

If the design changes dock composition or shell behavior, include those settings inside `appshell-theme/import/[theme-id]/theme-package.json`. Do not output a separate `kiwe-settings/` folder for AppShell theme settings.

`theme-package.json` is the one file Kiwe admin/API imports as an installed theme. It wraps the manifest, CSS, and safe settings preset:

```json
{
  "type": "kiwe-theme-package",
  "schema": "kiwe.theme-package.v1",
  "schemaVersion": 1,
  "theme": {
    "schema": "kiwe.surface-theme.v1",
    "id": "your-theme-id",
    "name": "Your Theme Name",
    "version": "1.0.0",
    "profile": "marketplace",
    "mode": "css-only",
    "css": ["css/theme.css"],
    "assets": [],
    "screens": ["search", "menu", "saved", "links", "notifications", "ios-install", "ai"],
    "requires": {
      "uiContract": "kiwe.surface-ui.v2",
      "tokenContract": "kiwe.universal",
      "minKiwe": "0.5.84"
    },
    "supports": ["light", "dark", "sheet", "classic", "dock", "split-dock", "full-dock", "navigation-bar", "dock-shape-pill", "dock-shape-box", "dock-shape-square", "horizontal", "vertical", "reduced-motion"],
    "budgets": { "cssKb": 40, "jsKb": 0, "blockingAssets": 0 },
    "forbidden": ["remote-code", "trackers", "php", "service-worker", "history-owner", "cart-owner", "checkout-owner", "phonekey-owner", "bricks-owner"]
  },
  "settings": {
    "style": {
      "active_theme_id": "your-theme-id",
      "visual_profile": "kiwe2027"
    },
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
        "cart": false,
        "theme": false,
        "ai": true,
        "link-home": true
      },
      "item_order": ["link-home", "menu", "search", "profile", "links", "saved", "cart", "theme", "ai"],
      "custom_items": [
        { "id": "link-home", "label": "Home", "url": "/", "icon": "home", "enabled": true }
      ]
    },
    "tokens": {
      "enabled": true,
      "profile_label": "Your Theme Design Tokens",
      "overrides": {
        "color-brand": "#d6006f",
        "color-accent": "#24c6a1",
        "color-surface": "#f6f8f7",
        "color-text": "#1f2933",
        "font-display": "Inter, system-ui, sans-serif",
        "font-body": "Inter, system-ui, sans-serif",
        "type-h1": "clamp(52px, 5vw + 36px, 108px)",
        "type-h2": "clamp(38px, 3vw + 28.4px, 72px)"
      },
      "bricks_theme_style": {
        "enabled": true,
        "id": "kiwe-global-design",
        "label": "Kiwe Universal Design Tokens"
      }
    },
    "screens": {
      "profile": {
        "label": "Account",
        "eyebrow": "Profile & Activity",
        "title": "Your account",
        "ordersTitle": "Orders",
        "addressesTitle": "Addresses",
        "signOutLabel": "Sign out"
      },
      "cart": {
        "label": "Bag",
        "eyebrow": "Cart",
        "title": "Your tea-time bag",
        "emptyTitle": "Your tea-time bag is waiting.",
        "emptyText": "Add products to continue.",
        "fbtTitle": "Pairs well with",
        "checkoutLabel": "Checkout",
        "checkoutEmptyLabel": "Empty"
      },
      "links": {
        "label": "Links",
        "title": "Store links",
        "shopLabel": "Shop all products",
        "cartLabel": "Shopping bag"
      }
    }
  },
  "css": "/* same presentation CSS as css/theme.css, inline for Kiwe admin/API import */"
}
```

Keep `theme.json` as the manifest-only validator file. Do not put settings into `theme.json`. Put settings in `theme-package.json` under root `settings`.

Important dock settings:

- `dock.presentation`: `dock` or `navbar`.
- `dock.split_style`: split compact dock on/off. It only applies when presentation is `dock`.
- `dock.shape`: `pill`, `box`, or `square`.
- `dock.enabled_items` and `dock.item_order`: visible built-in modules and their order.
- `dock.focus_item`: the enabled item that becomes the emphasized/focus button and split-dock center. Default is `ai`, but a design may choose `search`, `cart`, or a custom link when justified. The live runtime marks that item with `data-dsa-dock-focus` / `data-dsa-dock-primary`; theme CSS should style those public attributes/classes as the persistent focus affordance instead of assuming the focus item is always AI or styling only `[aria-pressed="true"]`.
- `dock.custom_items`: safe URL navigation items such as Home. Custom dock links navigate only; they do not create new DSA screens. Custom link icons should use safe available names such as `home`, `house`, `external-link`, `search`, `shopping-bag`, `bookmark`, `heart`, `share-2`, `user-round`, `package`, `map-pin`, `download`, `sparkles`, or `sun-moon`. A Home link is valid and should not render as a blank icon.
- `tokens`: the design token profile that lets the live DSA theme, Seam website CSS, and Bricks global theme style share one visual personality. Use only known Kiwe universal token names such as `color-brand`, `color-accent`, `color-surface`, `color-text`, `font-display`, `font-body`, `type-h1`, `type-h2`, `leading-tight`, `space-md`, `radius-lg`, and `shadow-md`. Do not invent private token names when a Kiwe token can carry the design.
- `tokens` must be shaped as `settings.tokens.enabled`, `settings.tokens.profile_label`, `settings.tokens.overrides`, and optional `settings.tokens.bricks_theme_style`. Do not put raw CSS variable keys at the top of `tokens`, and do not use `--kiwe-*` or `var(...)` keys inside `overrides`. Wrong: `"tokens": { "--kiwe-color-brand": "#d71920" }`. Right: `"tokens": { "enabled": true, "overrides": { "color-brand": "#d71920" } }`.
- Importable `theme.css` should consume official CSS variables generated from those same tokens, such as `--kiwe-color-brand`, `--kiwe-color-accent`, `--kiwe-color-surface`, `--kiwe-color-surface-raised`, `--kiwe-color-text`, `--kiwe-font-display`, `--kiwe-type-h1`, `--kiwe-radius-xl`, `--kiwe-radius-full`, `--kiwe-shadow-md`, and `--kiwe-space-md`, or documented `--kiwe-theme-*` aliases. Do not use invented/obsolete CSS variables such as `--kiwe-color-background`, `--kiwe-radius-card`, `--kiwe-radius-control`, `--kiwe-shadow-panel`, or `--kiwe-space-unit`; they will not be driven by the live token profile.
- Do not copy or reference generated `--dsa-runtime-token-####` variables in any handoff or importable theme CSS. Those names are private Kiwe core migration bridge tokens used by the runtime token-purity gate; they are not public Seam/AppShell theme vocabulary and may change without notice. A theme should use official `--kiwe-*` variables, documented `--kiwe-theme-*` aliases, or request promotion of a missing need into the universal token library.
- Do not put anonymous raw length, color, or shadow/effect values directly in importable `theme.css`. Write `padding: var(--kiwe-space-md)`, `border-radius: var(--kiwe-radius-xl)`, `border-width: var(--dsa-geometry-dock-border, thin)`, `color: var(--kiwe-color-text)`, and `box-shadow: var(--kiwe-shadow-md)` rather than `padding: 24px`, `border-radius: 18px`, `color: #10231d`, `border: 1px solid`, or `box-shadow: 0 18px 48px ...`.
- `tokens.bricks_theme_style`: optional safe Bricks global theme-style lane. It may set global typography, colors, links, and site background only. It must not contain element-level Bricks styling, component recipes, AppShell geometry, or per-module behavior.
- `screens`: presentation/copy labels only for registered DSA screens/sheets. Allowed screen keys are `profile`, `cart`, `checkout`, `search`, `menu`, `saved`, `links`, `notifications`, `ios-install`, `games`, and `ai`. This lane may rename labels, titles, helper text, empty states, safe CTA labels, FBT rail title, profile row labels, Links shop/cart labels, notification form labels, iOS install steps, game labels, and AI empty/chat copy. It must not contain products, orders, saved items, profile identity, menu items, search results, social URLs, score values, notification state, AI messages/actions, cart line items, totals, checkout/payment URLs, JavaScript, endpoints, or state authority.
- Common examples: `screens.cart.title`, `screens.cart.fbtTitle`, `screens.profile.title`, `screens.profile.ordersTitle`, `screens.links.shopLabel`, `screens.search.placeholder`, `screens.menu.contextTitle`, `screens.notifications.submitLabel`, and `screens.ai.chatPlaceholder`.

`settings.screens` must use live Kiwe field names exactly. Do not create natural-language aliases such as `helperText`, `ordersLabel`, `addressesLabel`, `downloadsLabel`, `actionLabel`, `scoreLabel`, `noScoreText`, `instagramLabel`, `storesLabel`, or `giftingLabel`; Kiwe will sanitize/ignore unsupported fields and the installed theme will drift from the preview. Use `intro` for helper/body copy, `ordersTitle`/`ordersText` for Profile rows, and `shopLabel`/`shopMeta` or `cartLabel`/`cartMeta` for Links actions.

If the combined preview shows custom live-intended screen/sheet copy, declare the same copy in `theme-package.json` under `settings.screens`. For example, a custom cart title belongs in `settings.screens.cart.title`, a custom FBT rail label belongs in `settings.screens.cart.fbtTitle`, a custom account title belongs in `settings.screens.profile.title`, and renamed Links actions belong in `settings.screens.links`. If not declared there, the audit should treat it as preview-only copy and should not expect it to appear in the live Kiwe adapters.

Site owners can later edit registered DSA screen/sheet copy manually under `Kiwe > Theme > DSA screen/sheet copy`; those admin overrides merge over imported `settings.screens` defaults. The package must still ship the intended defaults so the preview and first live install match.

Minimal `settings` object example inside `theme-package.json`:

```json
{
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
  },
  "tokens": {
    "enabled": true,
    "profile_label": "Theme design tokens",
    "overrides": {
      "color-brand": "#d6006f",
      "color-accent": "#24c6a1",
      "color-surface": "#f6f8f7",
      "color-text": "#1f2933",
      "font-display": "Inter, system-ui, sans-serif",
      "font-body": "Inter, system-ui, sans-serif"
    },
    "bricks_theme_style": {
      "enabled": true,
      "id": "kiwe-global-design",
      "label": "Kiwe Universal Design Tokens"
    }
  },
  "screens": {
    "profile": {
      "label": "Account",
      "title": "Your account"
    },
    "cart": {
      "label": "Bag",
      "title": "Your tea-time bag",
      "fbtTitle": "Pairs well with",
      "checkoutLabel": "Checkout"
    },
    "search": {
      "placeholder": "Search products and stories"
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
node kiwe-ai-toolkit/tools/validate-framework-profile.cjs combined-kiwe-handoff --optional
node kiwe-ai-toolkit/tools/audit-output.cjs combined-kiwe-handoff
```
