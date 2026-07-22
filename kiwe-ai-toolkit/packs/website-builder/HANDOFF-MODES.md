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
  framework/
    kiwe-framework-profile.json # optional when the page defines a sitewide token profile
```

Rules:

- Use Kiwe/Seam tokens and Seam Class Vocabulary names where useful.
- Produce `bricks-paste.html` as the single website/page artifact. It must open directly in a browser for visual review and also paste/import through Bricks HTML-to-Bricks. It may inline the CSS/JS needed for the page preview, but must not require a React/Vite/Tailwind build, generated Bricks IDs, duplicate preview files, or hidden local files.
- If the website/page establishes a brand system that should be reused by Bricks and future Kiwe pages, include `framework/kiwe-framework-profile.json` with `schema: "kiwe.framework-profile.v1"` and `settings.tokens` only. This imports at `Kiwe > Framework`, not `Kiwe > Theme`.
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

- The review folder includes `theme.json`, `css/theme.css`, and `theme-package.json`. `theme-package.json` is the single Kiwe admin/API import file containing the manifest, CSS, and safe theme settings preset.
- Preview code is visual proof only.
- Do not create website/page sections.
- Do not invent DSA behavior or state authority.
- Use `screen-payloads.json`, `slots.md`, `preview-handoff.md`, and `theme-manifest.schema.json`.
- Dock destination visibility is configuration, not theme CSS. If a design needs a different dock composition, declare it inside `theme-package.json` root `settings`.

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
        theme-package.json
        theme.json
        css/
          theme.css
    preview/                  # optional technical fixture only; not required for combined mode
      index.html
      PLACEHOLDERS.md
```

Rules:

- `combined-preview/index.html` is the primary review artifact. It should show the website/page behind the Kiwe DSA dock/sheet/screen, using the AppShell theme CSS and realistic placeholder data.
- Combined mode should have one primary preview, not separate website and AppShell visual reviews. Put the variation controls in `combined-preview/index.html` so the reviewer can test the page and AppShell together.
- The combined preview must prove: page/header `data-dsa-open-module` launchers, dock buttons, full compact dock, split compact dock, Navigation bar, horizontal and vertical dock orientation, Sheet and Classic surface modes, light/dark, pill/rounded-box/square dock shapes, desktop/tablet/mobile Geometry Engine profiles, and narrow mobile stress widths.
- If `website/bricks-paste.html` is loaded inside an iframe, preview-only JS must bridge canonical page/header launchers such as `data-dsa-open-module="cart"` and `data-dsa-open-module="profile"` into the preview AppShell. In live WordPress, Kiwe owns that behavior; in the combined preview, the bridge only proves the handoff.
- Keep the website/page CSS and AppShell theme CSS separate.
- The website lane must include `website/bricks-paste.html`. This is the Bricks copy/paste artifact. Do not return only a React/Vite app, screenshot, Markdown spec, or preview without the paste-ready file.
- The Kiwe AppShell is runtime chrome around the page, not part of the Bricks page itself. `website/bricks-paste.html` must be page-only: no `data-dsa-surface`, DSA dock, DSA sheet/screen markup, AppShell preview controller, or Kiwe runtime mock belongs in the Bricks paste artifact.
- Only `combined-preview/index.html` should show the website/page and Kiwe AppShell together for human review.
- Website CSS may use Seam Class Vocabulary and Bricks-friendly classes.
- AppShell theme CSS may style DSA theme selectors and allowed public Seam classes according to `ui-system/`.
- Do not create a separate `website/preview/index.html` by default. `website/bricks-paste.html` is the website/page preview and the Bricks import artifact. Only add split website preview assets if the human explicitly asks for them.
- A separate `appshell-theme/preview/index.html` is optional in combined mode. If included, label it as a technical fixture only. Do not make it the only place where dock shape, Navigation bar, Classic, or device profiles are tested.
- The combined preview may simulate save/cart/search/screen switching only as preview-only behavior. Production behavior remains Kiwe/WordPress/Woo/Bricks-owned.
- Do not copy website page classes into DSA internals unless the AppShell adoption map allows it.
- Do not use DSA theme CSS to style the whole website.
- Navigation bar is not a horizontal dock. `dock.presentation="navbar"` is a separate core presentation mode; `horizontal` and `vertical` are dock orientation states. Split dock applies only when presentation is `dock`.
- In combined mode, put the live-intended design-token profile in `appshell-theme/import/theme-id/theme-package.json` under `settings.tokens` so the theme install keeps DSA, Seam page CSS, and Bricks global style aligned. Do not add a separate Framework profile unless the brief explicitly asks for a standalone `Kiwe > Framework` import artifact too.

## Page-to-AppShell hooks

Website/page markup may include Kiwe hooks, but must not implement Kiwe behavior itself.

- Open a DSA module from a page/header control with canonical `data-dsa-open-module="cart"`. Valid values include `menu`, `search`, `profile`, `links`, `saved`, `cart`, `theme`, `ai`, `notifications`, and `ios-install`.
- Do not add Seam attributes only to feed the DSA Menu. Kiwe Menu context is heading-first: it reads the admin-selected heading levels for classic blog/page table-of-contents behavior. When no configured headings are available, Kiwe may opportunistically use existing semantic page sections (`data-role="section"` or `.seam-section`) with a stable `id` and standard labels (`aria-label`, `aria-labelledby`, or visible heading text) as contextual fallback.
- Do not create duplicate cart/profile/search/save/auth behavior. Keep Kiwe hooks as handoff points to the live plugin.
- Do not use website CSS to restyle protected DSA internals.

## Kiwe theme package settings lane

Combined handoffs should include `appshell-theme/import/theme-id/theme-package.json` when the design intentionally changes Kiwe runtime settings. Do not create a separate `kiwe-settings/` folder for DSA theme settings.

This is useful when the design wants:

- Dock presentation as compact dock or navigation bar.
- Split compact dock on/off.
- Dock shape: `pill`, `box`, or `square`.
- Dock module visibility and order.
- Dock focus item: `dock.focus_item` chooses the emphasized/focus button and split-dock center. Default is `ai`; use another enabled module or custom link only when the brief justifies it.
- Custom dock links: `dock.custom_items` may add safe URL navigation items such as Home. They navigate only; they do not create new DSA screens.
- Dark/light mode action hidden from the dock because a page/header launcher will open it elsewhere.
- Cart hidden from the dock for a non-commerce site.
- Cart visible for a WooCommerce/ecommerce site.
- Screen presentation copy, such as cart titles and FBT/checkout labels, when the preview copy is intended to appear in live Kiwe.
- Sheet mode, sheet placement, sheet spacing, sheet origin, sheet width, and sheet height.
- Visual profile: `legacy` or `kiwe2027`.
- Design-token profile: palette, font stacks, heading scale, site background, line-height, spacing, radius, shadows, and the optional safe Bricks global theme-style export. Active/hover/hero colors remain compatibility settings for `color-brand`, `color-accent`, and `color-hero`.

`theme.json` remains the manifest-only validator file. Put the settings preset in `theme-package.json` at root `settings`, beside root `theme` and root `css`.
- Active/hover/hero colors.
- WooCommerce or Search bridge settings when the website design requires them.

Use only recognized Kiwe theme settings. Unknown keys are ignored by import.

Safe root `settings` keys inside `theme-package.json` include:

```json
{
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
    "focus_item": "ai",
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
      "ai": true,
      "link-home": true
    },
    "item_order": ["link-home", "menu", "search", "profile", "links", "saved", "cart", "theme", "ai"],
    "custom_items": [
      { "id": "link-home", "label": "Home", "url": "/", "icon": "home", "enabled": true }
    ]
  },
  "dsa_theme": {
    "active_color": "#8f8f98",
    "hover_color": "#24c6a1",
    "hero_text_color": "rgba(20,24,34,0.18)"
  },
  "tokens": {
    "enabled": true,
    "profile_label": "Theme design tokens",
    "overrides": {
      "color-brand": "#8f8f98",
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
      "title": "Your account",
      "ordersTitle": "Orders",
      "addressesTitle": "Addresses"
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
      "shopLabel": "Shop all products",
      "cartLabel": "Tea-time bag"
    }
  }
}
```

Notes:

- Hiding a dock item only hides the dock button. It does not delete the registered DSA module.
- Bricks/Icon/header launchers may still open DSA modules through Kiwe's Bricks controls and canonical `data-dsa-open-module`.
- WooCommerce controls should match the assignment. A news/editorial design should not force cart UI unless requested. An ecommerce design should account for cart, checkout, product rails, and Woo-owned behavior.
- `settings.screens` is presentation/copy only for registered DSA screens/sheets: `profile`, `cart`, `checkout`, `search`, `menu`, `saved`, `links`, `notifications`, `ios-install`, `games`, and `ai`. It may rename labels, titles, helper text, empty states, safe CTA labels, Cart FBT title, Profile row labels, Links shop/cart labels, notification form labels, iOS install steps, game labels, and AI empty/chat copy. It must not contain products, orders, saved items, profile identity, menu items, search results, social URLs, score values, notification state, AI messages/actions, cart line items, totals, checkout/payment URLs, JavaScript, endpoints, or state authority.
- If a preview shows custom live-intended screen/sheet copy, it must be declared in `theme-package.json` under `settings.screens`; otherwise document it as preview-only.
- `Kiwe > Theme` exposes manual DSA screen/sheet copy controls. Manual admin edits merge over imported `settings.screens` defaults, but a theme package should still ship defaults so first install matches the preview.
- The theme settings must not contain users, orders, credentials, logs, raw API keys, API secrets, or private data.

## What to ask the AI

For website only:

> Follow `framework-system/handoffs/website-builder/prompt.md`. Build a standalone previewable Bricks-ready page using Kiwe/Seam. Do not create an AppShell theme.

For DSA theme only:

> Follow `ui-system/prompt.md`. Build a Kiwe DSA AppShell theme handoff with importable package and standalone preview. Do not create a website.

For combined:

> Follow both `framework-system/handoffs/website-builder/prompt.md` and `ui-system/prompt.md`, using `HANDOFF-MODES.md` as the layer boundary. Create a combined website/page plus DSA AppShell theme handoff. Keep website/page output separate from the AppShell theme package; place DSA theme settings inside `theme-package.json`.
