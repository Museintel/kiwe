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
  preview/
    index.html
    assets/
      site.css
      site.js       # optional, preview-only
  bricks-paste.html
  bricks-notes.md
```

Rules:

- Use Kiwe/Seam tokens and Seam Class Vocabulary names where useful.
- Produce `bricks-paste.html` as the copy/paste artifact for Bricks HTML-to-Bricks import. It may inline the CSS/JS needed for the page preview, but must not require a React/Vite/Tailwind build, generated Bricks IDs, or hidden local files.
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
    preview/
      index.html
      assets/
        site.css
        site.js       # optional, preview-only
    bricks-paste.html
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
- Website CSS may use Seam Class Vocabulary and Bricks-friendly classes.
- AppShell theme CSS may style DSA theme selectors and allowed public Seam classes according to `ui-system/`.
- The separate `website/preview/index.html` and `appshell-theme/preview/index.html` are technical fixtures for Bricks/page review and AppShell validator proof. They are not the primary combined-mode visual review.
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
