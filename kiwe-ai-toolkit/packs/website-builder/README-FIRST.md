# Kiwe Framework website-builder handoff

Give this folder to an AI, web developer, or Bricks designer when the job is:

- create a normal website/page using Kiwe/Seam Framework;
- create a standalone previewable HTML/CSS page that can later be brought into Bricks;
- design Bricks sections using Kiwe variables, Seam classes, and Kiwe capability boundaries.

This is not a Kiwe AppShell theme handoff. AppShell themes use `ui-system/` and style DSA sheets/screens/dock around existing Kiwe capabilities.

If the assignment asks for both a website/page and a DSA AppShell theme, use `HANDOFF-MODES.md` and keep the output in separate `website/`, `appshell-theme/`, and `combined-preview/` folders. AppShell theme settings belong inside `appshell-theme/import/<theme-id>/theme-package.json`.

If a website/page-only handoff creates a reusable brand token system, preserve its actual source CSS and advisory design intent. The deterministic compiler generates the final `framework/kiwe-framework-profile.json`, native Bricks JSON and dependency ZIP. AI does not invent native IDs or import success. The target's `Kiwe > Framework` journey reviews real Media, shared native styles and draft templates before import; live publishing remains separate.

## Read order

1. `prompt.md`
2. `HANDOFF-LITE.md`
3. `HANDOFF-MODES.md` if the assignment might include a DSA AppShell theme too
4. `contracts/token-map.css`
5. `contracts/tokens-reference.md`
6. `contracts/framework-profile.schema.json`
7. `contracts/bricks-theme-style.schema.json`
8. `contracts/seam-vocabulary.md`
9. `contracts/seam-vocabulary.json`
10. `contracts/seam-class-vocabulary.md`
11. `contracts/seam-class-vocabulary.json`
12. `runtime/seam.css`
13. `runtime/seam.js`
14. `bricks/bricks-capabilities.json`
15. `bricks/BRICKS-INTEGRATION.md`

## What to build

Build a polished website/page. Seam is available, not mandatory. Good output may use:

- semantic HTML;
- class-based CSS;
- Kiwe/Seam tokens where useful;
- Seam roles/flows/tones where they describe structure;
- Seam Class Vocabulary names where they match the intended component or variant;
- live Kiwe capability attributes where the draft already has matching UI intent;
- custom CSS for the actual visual style;
- preview-only JavaScript clearly marked as preview-only.

Use Seam classes/attributes in markup, but do not redefine Seam framework selectors in project CSS. For example, do not write `.seam-horizontal-rail { ... }`, `.brand .seam-card { ... }`, `.seam-visually-hidden { ... }`, or `[data-flow="reel"] { ... }` in the page/theme CSS. Put all visual rules on project-owned classes such as `.brand-rail`, `.nc-campaign-card`, or `.appsite-hero` so Seam remains the stable headless vocabulary and Bricks layouts do not accidentally shrink or rearrange when the same Seam class appears in another place.

For rails, put `.seam-horizontal-rail` / `data-flow="horizontal-rail"` only on the actual item track. Do not put the rail flow on an outer sticky nav or wrapper that contains `.seam-container`; that wrapper can shrink the inner container into one rail item after Bricks import.

Required website artifacts:

- `bricks-paste.html` as the single website/page artifact. It must open directly in a browser for visual review and also paste/import through Bricks HTML-to-Bricks.
- `bricks-notes.md` only when `/document` is requested; if present, it should explain how the preview maps to Bricks and which interactions remain Kiwe/WordPress/Woo/Bricks-owned.
- Actual source assets with provenance and generation status. Optional design notes remain advisory; the compiler derives the final native Profile/dependency package, which has its own validation and target review.

Do not return a React, Vite, Next, Tailwind, shadcn, or other build-app project as the primary output. Those can be inspirational prototypes only if separately requested. The Kiwe handoff must be plain HTML/CSS with optional preview-only JS so it can travel into Bricks.

Seam roles are semantic/headless by default. `data-role="card"` tells tools what something is; it must not force the page into generic cards. Seam core has no starter visual layer right now: no default card/button/modal padding, radius, border, shadow, or background. Use site CSS and searchable Seam Class Vocabulary names such as `.seam-card`, `.seam-accordion`, `.seam-table`, `.seam-horizontal-rail`, `.seam-size-xl`, or `.seam-density-spacious` for the actual look.

Use `data-role` only for official Seam role values from `contracts/seam-vocabulary.json`, such as `hero`, `card`, `nav`, `button`, `form`, `testimonial`, `price`, or `footer`. For specific ecommerce/editorial concepts, prefer Seam Class Vocabulary names such as `.seam-product-card`, `.seam-product-rail`, `.seam-category`, `.seam-story`, or project classes such as `.nc-product`. Do not invent custom `data-role` values like `product-card`, `site-header`, `save-placeholder`, or `add-to-cart-placeholder`; use a project-specific attribute such as `data-project-role` if extra preview semantics are needed.

Use `capabilityAttributes` from `contracts/seam-vocabulary.json` when the UI already asks for appsite behavior. Examples: `data-kiwe-save` for wishlist/bookmark buttons, `data-kiwe-notifications` for notification CTAs, `data-kiwe-theme-toggle` for page/header theme controls, `data-dsa-open-module` for Kiwe screen launchers, semantic section IDs/labels for Menu context, and `data-kiwe-query-template` / `data-kiwe-binding` for future Bricks dynamic/query-loop intent. Preserve the visible UI and add the smallest live attribute; do not recreate Kiwe runtime in page JavaScript.

## Bricks path

Compatible Bricks targets may expose an HTML-to-Bricks converter. Make the standalone preview conversion-friendly:

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
