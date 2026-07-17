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
