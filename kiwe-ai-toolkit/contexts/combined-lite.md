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
- Do not hand-author raw Bricks `_bricks_page_content_2` JSON unless the human explicitly asks and provides a verified target contract. Kiwe's staging executor can convert clean HTML/CSS into Bricks JSON while preserving Seam classes/data attributes and storing safe CSS in Bricks page settings.

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

The theme CSS is presentation-only. It may style existing DSA selectors and allowed public Seam classes. It must not create runtime authority. In production, Kiwe runtime-scopes installed theme CSS to the active `#dsa-surface[data-dsa-surface].dsa-installed-theme-[theme-id]` root so correct `[data-dsa-surface]` selectors have visual authority over core defaults while core keeps geometry/state ownership.

The Geometry Engine owns AppShell placement and measurement. Importable theme CSS must not assign core geometry to dock, sheet, screen, or backdrop selectors. Do not set `position: fixed`, `position: absolute`, `inset`, `top`, `right`, `bottom`, `left`, hardcoded `z-index`, `width: 100vw`, `height: 100vh`, or hardcoded viewport offsets on `[data-dsa-dock]`, `.dsa-dock`, `[data-dsa-screen]`, `.dsa-panel`, `.dsa-sheet`, `[data-dsa-screen-backdrop]`, or sheet/screen backdrop selectors. Those values belong in Kiwe core or preview-only CSS. Theme CSS may style color, typography, border, radius, shadow, spacing inside content, icons, badges, cards, buttons, and state appearance while consuming Geometry Engine variables.

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

The importable `theme.css` must style live Kiwe roots and documented runtime internals. Do not make the installed theme depend on preview-fixture-only classes such as `.dsa-screen-head`, `.dsa-screen-body`, `.dsa-profile-card`, `.dsa-score-card`, `.dsa-links-identity`, `.dsa-account-rows`, `.dsa-link-list`, `.dsa-install-steps`, `.dsa-game-frame`, or `.dsa-ai-insight`. Those names may exist only in preview CSS if they are part of a standalone mock fixture. If `theme.json.screens` lists a screen, `theme.css` must target that screen's live root below.

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

Cart FBT must be a horizontal rail. Include `data-dsa-cart-fbt-rail` on that rail. Do not render it as a stacked list.

Links site score is optional. The preview and README must show/document both:

- score present; and
- score absent/no score/without score, where no badge is rendered at all.

It is valid to create distinctive presentations for existing `ai` and `notifications` screens. They must use Kiwe-owned AI/notification payloads/actions and must not execute AI actions, notification permission requests, push subscription, dismiss state, or privacy/master-switch behavior from theme or preview code.

Responsive fit is mandatory. Test desktop, tablet, and mobile Geometry Engine profiles, then narrow stress widths around 320px, 360px, and 390px. No DSA sheet/screen may create horizontal page or panel scrolling except intentional rails such as FBT, alphabet/search filters, or another documented horizontal rail. Decorative header stripes, badges, labels, and pseudo-elements must shrink, wrap, clip inside the panel, or stack; do not use non-shrinking flex decorations that can force the panel wider than the viewport.

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
        "title": "Your National account",
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
        "title": "National links",
        "shopLabel": "Shop all products",
        "cartLabel": "Tea-time bag"
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
- `dock.focus_item`: the enabled item that becomes the emphasized/focus button and split-dock center. Default is `ai`, but a design may choose `search`, `cart`, or a custom link when justified.
- `dock.custom_items`: safe URL navigation items such as Home. Custom dock links navigate only; they do not create new DSA screens.
- `tokens`: the design token profile that lets the live DSA theme, Seam website CSS, and Bricks global theme style share one visual personality. Use only known Kiwe universal token names such as `color-brand`, `color-accent`, `color-surface`, `color-text`, `font-display`, `font-body`, `type-h1`, `type-h2`, `leading-tight`, `space-md`, `radius-lg`, and `shadow-md`. Do not invent private token names when a Kiwe token can carry the design.
- `tokens` must be shaped as `settings.tokens.enabled`, `settings.tokens.profile_label`, `settings.tokens.overrides`, and optional `settings.tokens.bricks_theme_style`. Do not put raw CSS variable keys at the top of `tokens`, and do not use `--kiwe-*` or `var(...)` keys inside `overrides`. Wrong: `"tokens": { "--kiwe-color-brand": "#d71920" }`. Right: `"tokens": { "enabled": true, "overrides": { "color-brand": "#d71920" } }`.
- Importable `theme.css` should consume official CSS variables generated from those same tokens, such as `--kiwe-color-brand`, `--kiwe-color-accent`, `--kiwe-color-surface`, `--kiwe-color-surface-raised`, `--kiwe-color-text`, `--kiwe-font-display`, `--kiwe-type-h1`, `--kiwe-radius-xl`, `--kiwe-radius-full`, `--kiwe-shadow-md`, and `--kiwe-space-md`, or documented `--kiwe-theme-*` aliases. Do not use invented/obsolete CSS variables such as `--kiwe-color-background`, `--kiwe-radius-card`, `--kiwe-radius-control`, `--kiwe-shadow-panel`, or `--kiwe-space-unit`; they will not be driven by the live token profile.
- `tokens.bricks_theme_style`: optional safe Bricks global theme-style lane. It may set global typography, colors, links, and site background only. It must not contain element-level Bricks styling, component recipes, AppShell geometry, or per-module behavior.
- `screens`: presentation/copy labels only for registered DSA screens/sheets. Allowed screen keys are `profile`, `cart`, `checkout`, `search`, `menu`, `saved`, `links`, `notifications`, `ios-install`, `games`, and `ai`. This lane may rename labels, titles, helper text, empty states, safe CTA labels, FBT rail title, profile row labels, Links shop/cart labels, notification form labels, iOS install steps, game labels, and AI empty/chat copy. It must not contain products, orders, saved items, profile identity, menu items, search results, social URLs, score values, notification state, AI messages/actions, cart line items, totals, checkout/payment URLs, JavaScript, endpoints, or state authority.
- Common examples: `screens.cart.title`, `screens.cart.fbtTitle`, `screens.profile.title`, `screens.profile.ordersTitle`, `screens.links.shopLabel`, `screens.search.placeholder`, `screens.menu.contextTitle`, `screens.notifications.submitLabel`, and `screens.ai.chatPlaceholder`.

`settings.screens` must use live Kiwe field names exactly. Do not create natural-language aliases such as `helperText`, `ordersLabel`, `addressesLabel`, `downloadsLabel`, `actionLabel`, `scoreLabel`, `noScoreText`, `instagramLabel`, `storesLabel`, or `giftingLabel`; Kiwe will sanitize/ignore unsupported fields and the installed theme will drift from the preview. Use `intro` for helper/body copy, `ordersTitle`/`ordersText` for Profile rows, and `shopLabel`/`shopMeta` or `cartLabel`/`cartMeta` for Links actions.

If the combined preview shows custom live-intended screen/sheet copy, declare the same copy in `theme-package.json` under `settings.screens`. For example, "Your tea-time bag" belongs in `settings.screens.cart.title`, "Pairs well with" belongs in `settings.screens.cart.fbtTitle`, "Your National account" belongs in `settings.screens.profile.title`, and renamed Links actions belong in `settings.screens.links`. If not declared there, the audit should treat it as preview-only copy and should not expect it to appear in the live Kiwe adapters.

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
