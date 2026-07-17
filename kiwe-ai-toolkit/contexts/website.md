# Kiwe context: Website/page only

Normal WordPress/Bricks website or page using Seam Framework. No AppShell theme package.

## Important boundary

Use the bundled contracts. Do not ask for or read the full Kiwe/DSA plugin codebase.

Do not create cart, checkout, auth, save, search, AI, service-worker, history, focus, or WooCommerce authority.

# Kiwe Framework website-builder handoff

Give this folder to an AI, web developer, or Bricks designer when the job is:

- create a normal website/page using Kiwe/Seam Framework;
- create a standalone previewable HTML/CSS page that can later be brought into Bricks;
- design Bricks sections using Kiwe variables, Seam classes, and Kiwe capability boundaries.

This is not a Kiwe AppShell theme handoff. AppShell themes use `ui-system/` and style DSA sheets/screens/dock around existing Kiwe capabilities.

If the assignment asks for both a website/page and a DSA AppShell theme, use `HANDOFF-MODES.md` and keep the output in separate `website/`, `appshell-theme/`, and optional `kiwe-settings/` folders.

## Read order

1. `prompt.md`
2. `HANDOFF-LITE.md`
3. `HANDOFF-MODES.md` if the assignment might include a DSA AppShell theme too
4. `contracts/token-map.css`
5. `contracts/tokens-reference.md`
6. `contracts/seam-vocabulary.md`
7. `contracts/seam-vocabulary.json`
8. `contracts/seam-class-vocabulary.md`
9. `contracts/seam-class-vocabulary.json`
10. `runtime/seam.css`
11. `runtime/seam.js`
12. `bricks/bricks-capabilities.json`
13. `bricks/BRICKS-INTEGRATION.md`

## What to build

Build a polished website/page. Seam is available, not mandatory. Good output may use:

- semantic HTML;
- class-based CSS;
- Kiwe/Seam tokens where useful;
- Seam roles/flows/tones where they describe structure;
- Seam Class Vocabulary names where they match the intended component or variant;
- custom CSS for the actual visual style;
- preview-only JavaScript clearly marked as preview-only.

Required website artifacts:

- `bricks-paste.html` as the single website/page artifact. It must open directly in a browser for visual review and also paste/import through Bricks HTML-to-Bricks.
- `bricks-notes.md` explaining how the preview maps to Bricks and which interactions remain Kiwe/WordPress/Woo/Bricks-owned.

Do not return a React, Vite, Next, Tailwind, shadcn, or other build-app project as the primary output. Those can be inspirational prototypes only if separately requested. The Kiwe handoff must be plain HTML/CSS with optional preview-only JS so it can travel into Bricks.

Seam roles are semantic/headless by default. `data-role="card"` tells tools what something is; it must not force the page into generic cards. Seam core has no starter visual layer right now: no default card/button/modal padding, radius, border, shadow, or background. Use site CSS and searchable Seam Class Vocabulary names such as `.seam-card`, `.seam-accordion`, `.seam-table`, `.seam-horizontal-rail`, `.seam-size-xl`, or `.seam-density-spacious` for the actual look.

Use `data-role` only for official Seam role values from `contracts/seam-vocabulary.json`, such as `hero`, `card`, `nav`, `button`, `form`, `testimonial`, `price`, or `footer`. For specific ecommerce/editorial concepts, prefer Seam Class Vocabulary names such as `.seam-product-card`, `.seam-product-rail`, `.seam-category`, `.seam-story`, or project classes such as `.nc-product`. Do not invent custom `data-role` values like `product-card`, `site-header`, `save-placeholder`, or `add-to-cart-placeholder`; use a project-specific attribute such as `data-project-role` if extra preview semantics are needed.

## Bricks path

Bricks 2.4 beta has an HTML-to-Bricks converter. Make the standalone preview conversion-friendly:

- avoid generated Bricks IDs;
- avoid heavy inline styles;
- prefer reusable classes and variables;
- keep JS separate and minimal;
- do not recreate Kiwe/DSA behavior authority.

Use `bricks-paste.html` as the preview and paste file. Keep it self-contained by default so browser AIs do not spend tokens maintaining duplicate `preview/index.html`, `site.css`, and `site.js` files. Only add separate assets when the human explicitly requests split files or when a real media asset is required.

## Kiwe capability boundaries

Do not create a second system for:

- save/bookmark/wishlist;
- Links;
- Menu/menu context;
- Search;
- WooCommerce cart/checkout;
- PhoneKey/auth/profile mutation;
- AI;
- notifications;
- service worker/cache/history/focus/scroll authority.

The website may include UI affordances for these capabilities, but production must hook into Kiwe/WordPress/Woo/Bricks-owned behavior.


# Kiwe Framework handoff lite

The full `framework-system/` folder is the source/reference kit. For most web developers, designers, or AI assistants, start with this smaller reading set instead of trying to mentally load every file at once.

## Minimum files to share first

Share these files for a normal website page, Bricks section, or standalone preview assignment:

1. `README.md`
2. `prompt.md`
3. `HANDOFF-MODES.md` if the assignment might include both website/page work and a DSA AppShell theme
4. `contracts/seam-vocabulary.md`
5. `contracts/seam-vocabulary.json`
6. `contracts/seam-class-vocabulary.md`
7. `contracts/seam-class-vocabulary.json`
8. `contracts/token-map.css`
9. `runtime/seam.css`
10. `runtime/seam.js`
11. `bricks/bricks-capabilities.json`
12. `bricks/BRICKS-INTEGRATION.md`

Only add `source-map.md`, `runtime/seam-dev.js`, `tools/`, and `references/` when the person is auditing internals or proposing framework changes.

## Correct design target

Do not ask for "zero custom CSS". That makes most real marketing/editorial pages look generic or broken.

Ask for:

- a single `bricks-paste.html` file that opens in a browser as the standalone preview and is ready to paste/import through Bricks HTML-to-Bricks;
- CSS that consumes Kiwe/Seam variables from `token-map.css` and `runtime/seam.css`;
- public Seam roles/flows/tones/states where they describe the structure;
- reusable generic component/layout classes from the Seam Class Vocabulary for the actual art direction;
- no duplicate cart, search, save, checkout, auth, AI, or Bricks-query behavior.

This is not a Kiwe AppShell theme handoff. Kiwe AppShell themes use `ui-system/` and style the DSA sheets/screens/dock around existing capabilities.

Seam roles are semantic/headless by default. Do not ask an AI to maximize `data-role` usage as a visual design method. Use Seam for meaning, flow, tokens, states, and builder portability; use site CSS and reusable generic classes from `seam-class-vocabulary.json` for the actual website look. Seam core intentionally does not ship starter card/button/modal visuals, padding, radius, border, shadow, or background.

`data-role` is a controlled Seam vocabulary, not a free naming slot. Use official role values from `seam-vocabulary.json`. For specific components like product cards, category chips, search placeholders, save placeholders, or add-to-cart placeholders, use classes from `seam-class-vocabulary.json` plus project classes or a project-specific attribute such as `data-project-role`.

## Bricks path

Bricks 2.4 beta includes an `includes/html-to-bricks` converter pipeline. This means a good standalone preview should be built so it can travel into Bricks:

- semantic HTML;
- class-based CSS;
- variables in `:root` or reusable classes;
- minimal inline styles;
- no localStorage behavior for DSA-owned actions;
- no hardcoded generated Bricks element IDs.

The `bricks-paste.html` preview is allowed to use mock content and placeholder interactions, but production handoff must say which interactions are placeholders and which are Kiwe/DSA-owned.

Do not accept a React/Vite/Tailwind/shadcn application as the handoff unless the assignment explicitly asked for a separate app prototype. Kiwe website handoffs should be plain HTML/CSS with optional preview-only JS, because the target path is Bricks HTML-to-Bricks.


# Prompt for using Kiwe Framework

You are working with Kiwe Framework, the Seam-powered design/development framework bundled with Kiwe DSA.

Read this entire `framework-system/` folder before proposing code or design.

## Goal

Use the Kiwe Framework to design or implement WordPress/Bricks page sections, reusable classes, or builder-safe design patterns that align with Kiwe’s AppShell and token system.

Kiwe is an AppShell framework for WordPress. The page you design may be imported into Bricks, and the site may also run Kiwe DSA sheets/screens/dock on top of it.

## Layer split

Do not confuse these layers:

- **Website/page:** normal WordPress or Bricks pages. You may use Seam Framework, custom CSS, or neither. The web developer has liberty here.
- **Kiwe AppShell theme:** how Kiwe DSA sheets, screens, dock, and AppShell surfaces look and arrange existing capabilities. That is handled by `ui-system/`, not this framework prompt.
- **Kiwe capabilities:** save/bookmark/wishlist, Links, Menu, menu context, Search, WooCommerce cart/checkout, AI, PhoneKey/auth, notifications, privacy, service worker, and history/focus behavior. These are core/plugin authority, not website preview JavaScript.

## Read order

1. `README.md` for the framework boundary.
2. `source-map.md` for canonical source locations.
3. `HANDOFF-MODES.md` if the assignment might include both website/page work and a DSA AppShell theme.
4. `contracts/seam-vocabulary.md` and `contracts/seam-vocabulary.json` for roles, flows, tones, states, and AppShell adoption rules.
5. `contracts/seam-class-vocabulary.md` and `contracts/seam-class-vocabulary.json` for the searchable neutral class library pushed to Bricks.
6. `contracts/token-map.css` and `contracts/tokens-reference.md` for public tokens.
7. `runtime/seam.css` and `runtime/seam.js` to understand the available framework CSS/runtime helpers.
8. `bricks/bricks-capabilities.json` and `bricks/BRICKS-INTEGRATION.md` for Bricks support and boundaries.

## What you may create

- Bricks sections using `kiwe-*` variables and `seam-*` classes.
- WordPress HTML/CSS sections using public Seam vocabulary.
- Standalone previewable HTML pages that can be visually reviewed before being brought into Bricks.
- Documentation for how a designer should use the framework.
- Proposals for new framework roles/classes/tokens.
- A combined website/page + DSA AppShell theme handoff only when the assignment explicitly asks for both; follow `HANDOFF-MODES.md` and keep website, AppShell theme, and Kiwe settings/profile output separate.

Custom CSS is allowed. Seam-native does not mean zero custom CSS; it means Kiwe/Seam tokens, vocabulary, and behavior boundaries stay authoritative.

Seam roles are semantic/headless by default. `data-role="card"` or `.seam-card` should identify an element as a card-like thing, but must not be relied on to create the visual design. Seam core does not ship generic card/button/modal padding, radius, border, shadow, or background. Build the visual layer with neutral framework primitives, universal Kiwe/Seam tokens, and the searchable Seam Class Vocabulary. Prefer existing generic classes such as `seam-card`, `seam-accordion`, `seam-table`, `seam-horizontal-rail`, `seam-size-xl`, `seam-density-spacious`, and `seam-emphasis-featured` before inventing new names.

Prefer the Kiwe/Seam token library first. If a design genuinely needs a missing art-direction variable, propose a generic addition to the universal token library instead of inventing project-locked token names. Temporary preview-only variables are allowed only when clearly documented.

## What you must not create

Do not create a second authority for:

- cart, checkout, payment, discounts, or orders;
- auth, PhoneKey, profile/account mutation, or logout;
- Search or Bricks query ownership;
- service workers, cache policy, browser history, focus trap, or scroll lock;
- notification permission, Push subscription, AI actions, or privacy/master switches.

## DSA AppShell caution

Public WordPress/Bricks page sections may use the public Seam vocabulary directly.

Live DSA sheets/screens are stricter. Before assuming a public Seam class is safe inside DSA, check `contracts/seam-vocabulary.json` → `appShellAdoption`.

- `public-adopted` means Kiwe core may apply the public class inside DSA.
- `shadow-only` means DSA only gets protected `data-seam-*` metadata for now.
- `authority-only` means do not touch it from framework/page code. If you are designing a Kiwe AppShell theme, follow `ui-system/` instead.

Do not attach high-impact classes such as `seam-card`, `seam-button`, `seam-input`, `seam-media`, `seam-badge`, `seam-nav`, `seam-actions`, `seam-form`, `seam-field`, or `seam-modal` to live DSA internals unless the adoption map is updated by Kiwe core.

## Bricks usage

`Kiwe > Framework` pushes the active framework to Bricks as:

- variables;
- color palette;
- Seam global classes/categories, including the neutral searchable class vocabulary.

Use Bricks as the page-design authority. Do not hardcode site-specific Bricks element IDs or copy Bricks post meta between sites.

Bricks 2.4 beta includes an HTML-to-Bricks converter pipeline. Make standalone previews conversion-friendly: semantic HTML, class-based CSS, reusable variables, limited inline styles, and clear separation between preview-only JavaScript and production behavior.

## Preview requirements

When creating a standalone preview:

1. Include `index.html`.
2. Include CSS that references Kiwe/Seam variables where possible.
3. Include mock content only where the live plugin/WordPress/Bricks would normally supply data.
4. Mark placeholder behavior in comments or a short `README.md`.
5. Do not implement production save, cart, checkout, auth, search-query, AI, or Bricks-query behavior in preview JavaScript.
6. For bookmark/wishlist/save UI, use Kiwe/DSA save attributes or clearly mark the interaction as preview-only.
7. Do not treat high Seam usage as visual quality. A good page uses Seam for structure/meaning/tokens and lets the site CSS create the creative layer without duplicating Kiwe-owned behavior.

## Expected output

When returning work, include:

1. What framework pieces you used.
2. Whether the work targets normal WordPress/Bricks pages or DSA AppShell internals.
3. Any proposed new tokens/classes/roles.
4. Any core/plugin changes required.
5. Validation notes.
6. Whether the preview is ready for Bricks HTML-to-Bricks conversion or only visual review.


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
    preview/                  # optional technical fixture only; not required for combined mode
      index.html
      PLACEHOLDERS.md
  kiwe-settings/
    kiwe-appsite-profile.json   # optional, only when changing Kiwe settings
    SETTINGS-NOTES.md
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

## Page-to-AppShell hooks

Website/page markup may include Kiwe hooks, but must not implement Kiwe behavior itself.

- Open a DSA module from a page/header control with canonical `data-dsa-open-module="cart"`. Valid values include `menu`, `search`, `profile`, `links`, `saved`, `cart`, `theme`, `ai`, `notifications`, and `ios-install`.
- Do not add Seam attributes only to feed the DSA Menu. Kiwe Menu context is heading-first: it reads the admin-selected heading levels for classic blog/page table-of-contents behavior. When no configured headings are available, Kiwe may opportunistically use existing semantic page sections (`data-role="section"` or `.seam-section`) with a stable `id` and standard labels (`aria-label`, `aria-labelledby`, or visible heading text) as contextual fallback.
- Do not create duplicate cart/profile/search/save/auth behavior. Keep Kiwe hooks as handoff points to the live plugin.
- Do not use website CSS to restyle protected DSA internals.

## Kiwe settings/profile lane

Combined handoffs may include `kiwe-settings/kiwe-appsite-profile.json` when the design intentionally changes Kiwe runtime settings.

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
    }
  }
}
```

Notes:

- Hiding a dock item only hides the dock button. It does not delete the registered DSA module.
- Bricks/Icon/header launchers may still open DSA modules through Kiwe's Bricks controls and canonical `data-dsa-open-module`.
- WooCommerce controls should match the assignment. A news/editorial design should not force cart UI unless requested. An ecommerce design should account for cart, checkout, product rails, and Woo-owned behavior.
- The profile must not contain users, orders, credentials, tokens, logs, raw API keys, or private data.

## What to ask the AI

For website only:

> Follow `framework-system/handoffs/website-builder/prompt.md`. Build a standalone previewable Bricks-ready page using Kiwe/Seam. Do not create an AppShell theme.

For DSA theme only:

> Follow `ui-system/prompt.md`. Build a Kiwe DSA AppShell theme handoff with importable package and standalone preview. Do not create a website.

For combined:

> Follow both `framework-system/handoffs/website-builder/prompt.md` and `ui-system/prompt.md`, using `HANDOFF-MODES.md` as the layer boundary. Create a combined website/page plus DSA AppShell theme handoff. Keep website, AppShell theme, and Kiwe settings/profile output separate.
