# Kiwe Framework System

This folder is the portable framework handoff for Kiwe/Seam.

Use it when you want a web developer, Bricks designer, or AI assistant to understand and use the Kiwe Framework outside the full plugin codebase.

It is the framework sibling of `ui-system/`:

- `ui-system/` is the AppShell/theme brain for designing Kiwe DSA screens, sheets, dock, profiles, and marketplace themes.
- `framework-system/` is the page/builder framework brain for Seam vocabulary, universal tokens, Bricks export, framework classes, and safe adoption rules.

## What Kiwe Framework is

Kiwe Framework is the reusable design/development layer underneath the AppShell.

It includes:

- universal `kiwe-*` tokens;
- Seam roles, flows, tones, scenes, states, motion, shape, gap, align, and justify vocabulary;
- Seam/Kiwe Appsite capability attributes for saved items, notifications, theme toggles, DSA launchers, menu context, and dynamic Bricks intent;
- Seam Class Vocabulary: a broad neutral searchable class library for Bricks/global-class authoring;
- production-safe `seam-*` CSS classes;
- safe `window.Seam` runtime helpers;
- Bricks export rules for variables, palette, and global classes;
- Framework profile import rules for pushing the matching safe Bricks Theme Style from `Kiwe > Framework`;
- protected Kiwe AppShell adoption rules so framework classes do not accidentally break DSA sheets/screens.

## What this folder is for

Give this folder to a developer when the assignment is:

- use Kiwe/Seam classes in WordPress page sections;
- design Bricks pages using Kiwe Framework variables/classes;
- understand what `Kiwe > Framework` pushes into Bricks;
- audit whether a proposed website/page section uses the framework safely;
- propose framework additions without touching cart, checkout, PhoneKey, Search, service worker, history, focus trap, or Woo/Bricks authority.

If the assignment is instead “design a new DSA AppShell theme,” give them `ui-system/` too.

## Framework profile contract

`/create /frameworkprofile` outputs one file: `framework/kiwe-framework-profile.json`.

That file imports in `Kiwe > Framework` and is the preferred setup path before `/convert /bricks`. It carries:

- official Kiwe universal token overrides only;
- the complete `settings.tokens.bricks_theme_style` lane with `enabled: true`, a safe `id`, a human `label`, and global-only style slots;
- complete live token coverage for Seam and Bricks after push: `color-brand`, `color-accent`, `color-surface`, `color-surface-raised`, `color-text`, `color-text-muted`, `color-border`, `font-display`, `font-body`, `type-h1`, `type-body`, `space-md`, `radius-lg`, and `shadow-md`, either in `settings.tokens.overrides` or mapped `bricks_theme_style` slots;
- enough design personality for Kiwe to push Bricks variables, color palette, Seam classes, and a native Bricks Theme Style.

It must not contain Bricks page/template JSON, AppShell dock/sheet settings, products, posts, WooCommerce behavior, or runtime JavaScript.

## Token fallback ladder

Seam prefers universal tokens, but visual correctness remains the goal. When converting or rebuilding a design:

1. Use an exact official Kiwe/Seam token when the meaning and CSS property domain match.
2. Use a declared project token for stable site-specific art direction.
3. Use a real fluid `clamp()` only when the source proves different responsive values for the same property.
4. Never use `clamp(v, v, v)` as a token substitute.

Fluid clamp math:

```text
slope = (maxValue - minValue) / (maxViewport - minViewport) * 100
intercept = minValue - (slope / 100 * minViewport)
clamp(minValue, calc(intercept + slope * 1vw), maxValue)
```

CLI/MCP-capable tools may run `kiwe fluid-clamp --min 220px --max 390px --min-vw 478 --max-vw 1440`. Geometry Engine remains the authority for DSA/AppShell placement and measurement; this ladder is for page/Bricks/content design values.

## Layer split

Keep these layers separate:

- **Website/page layer:** A web developer may build normal WordPress/Bricks pages with Seam Framework, custom CSS, or no Seam at all. Seam is available as an additive framework, not a lock-in.
- **Kiwe AppShell/theme layer:** Kiwe themes are only for how the DSA AppShell looks and arranges its screens/sheets/dock. That work belongs in `ui-system/`.
- **Kiwe capability layer:** Save, Links, Menu, menu context, Search, WooCommerce cart/checkout, AI, PhoneKey/auth, notifications, and similar behavior remain Kiwe/WordPress/Woo/Bricks authority.

## Folder contents

- `contracts/seam-vocabulary.md` — human-readable Seam vocabulary and AppShell adoption rules.
- `contracts/seam-vocabulary.json` — machine-readable vocabulary, `capabilityAttributes`, and `appShellAdoption` map.
- `contracts/seam-class-vocabulary.md` — explains the neutral searchable class library.
- `contracts/seam-class-vocabulary.json` — machine-readable class categories/classes pushed to Bricks.
- `contracts/token-map.css` — portable public token map for designers and AI.
- `contracts/tokens-reference.md` — explains curated versus complete token exposure.
- `runtime/seam.css` — snapshot of the production-safe framework CSS.
- `runtime/seam.js` — snapshot of the safe framework runtime helper.
- `runtime/seam-dev.js` — snapshot of the debug-only framework linter.
- `bricks/bricks-capabilities.json` — Bricks hooks, dynamic tags, controls, and boundaries.
- `bricks/BRICKS-INTEGRATION.md` — deeper Bricks integration notes.
- `tools/audit-seam-adoption.cjs` — reference copy of the adoption audit tool.
- `references/` — historical Seam source material from before the MU plugin.
- `source-map.md` — exact canonical source locations in the real plugin.
- `prompt.md` — prompt to give an AI/developer with this folder.
- `HANDOFF-LITE.md` — the smaller file list to give a web developer or AI for ordinary website/page work.
- `HANDOFF-MODES.md` — explains website-only, DSA-theme-only, and combined website + AppShell theme assignments.
- `handoffs/website-builder/` — one-folder website/page handoff for AI, web developers, and Bricks designers.

## Canonical source rule

Do not edit this folder as the runtime source of truth unless the intent is to update the portable handoff.

Canonical runtime sources are:

- `assets/css/seam.css`
- `assets/js/seam.js`
- `assets/js/seam-dev.js`
- `includes/Design/Seam_Vocabulary_Schema.php`
- `includes/Design/Seam_Token_Service.php`
- `includes/Design/Token_Schema.php`
- `includes/Admin/Admin.php`
- `tools/ui-theme/audit-seam-adoption.cjs`

After changing canonical files, refresh this folder’s snapshots and rebuild the package manifest.

## Handoff size rule

This folder intentionally includes both the live framework snapshot and historical/reference material. For ordinary developer or AI assignments, start with `HANDOFF-LITE.md`. Do not duplicate the folder or flatten it just to make it smaller; the source map and references are useful when deeper framework work is needed, but they are not the first read for a normal page design.

## Seam-native does not mean zero custom CSS

Seam is the framework skeleton: tokens, vocabulary, roles, flows, states, safe runtime helpers, and Bricks-compatible class/export rules. A real website/page may still have a custom component layer for art direction, or may choose not to use Seam for a specific area. The rule is that any CSS claiming to be Seam-based should consume Kiwe/Seam tokens and must not create a second behavior authority.

Good:

- `data-role`, `data-flow`, `data-tone`, and Seam classes for the structural layer.
- Reusable generic Seam classes for a specific editorial/news/product look, for example `seam-story`, `seam-feature`, `seam-horizontal-rail`, or `seam-button`.
- Seam Class Vocabulary names such as `seam-card`, `seam-accordion`, `seam-table`, `seam-size-xl`, or `seam-density-spacious` styled by Bricks/site CSS.
- Placeholder preview behavior clearly marked as preview-only.

Bad:

- Hardcoded generated Bricks IDs.
- LocalStorage save/wishlist/search/cart logic replacing Kiwe/DSA/Woo behavior.
- Custom JS for wishlist/bookmark, browser notifications, light/dark toggles, or DSA screen launchers when a live Kiwe capability attribute exists.
- A private color/spacing/radius scale unrelated to Kiwe/Seam variables.
- Public high-impact `seam-*` classes forced inside live DSA AppShell internals when the adoption map says `shadow-only`.

## Role CSS is headless

`data-role` and `.seam-*` role classes are semantic by default. They identify meaning for tools, audits, Bricks, and site CSS, but they do not force generic cards, buttons, modals, badges, fields, padding, radius, border, shadow, or background. Seam core has no starter visual layer right now. Real website art direction should live in site CSS/classes that consume Kiwe/Seam tokens. The Seam Class Vocabulary is the preferred naming library for those classes, and any reusable missing pattern should be proposed as a generic framework addition rather than a project-locked class.

## Reference files are not runtime

The files in `references/` are retained historical Seam inputs from before Kiwe became the MU plugin. They are useful for future framework evolution, but they are not executed by the current plugin and there is no live "seam ingest" pipeline reading those reference files in production.

## Current integration status

Seam is integrated into Kiwe as of package `0.5.75`:

- framework CSS is enqueued as `dsa-seam`;
- runtime helpers are available under `window.Seam`;
- AppShell helpers are available under `window.DSA.ui.seam`;
- DSA panels receive protected `data-seam-*` landmarks;
- low-risk DSA text/price landmarks also receive public Seam classes;
- higher-risk roles remain `shadow-only` inside live DSA until visual parity is proven;
- Bricks export includes variables, color palette, and the expanded Seam global class vocabulary/categories;
- `Kiwe > Framework` is the admin area for pushing the framework into Bricks.

## Boundary

Kiwe Framework is presentation and builder vocabulary. It must not own:

- cart or checkout mutation;
- payment;
- PhoneKey/auth/session state;
- Search query authority;
- Bricks query authority;
- service worker/cache policy;
- browser history;
- focus trap;
- PWA install state;
- notification permission;
- AI actions.

Those remain Kiwe/Woo/WordPress/Bricks core authority.
