# Kiwe Accessibility Lite Context

Use this context only for:

```text
/create /accessibility
/audit /accessibility
/fix /accessibility
```

This is a focused accessibility lane for color contrast, light/dark mode, Kiwe/Seam token pairing, Bricks global theme-style alignment, and visible text containment. Do not use this phase for a full typography redesign or font-size preference system yet; that is a separate future lane. It may, however, fix text that is hidden, clipped, overlapped, unreadable, or forced into too-small pills/cards.

## Boundary

`/create /accessibility` needs an existing artifact:

- a pure HTML/CSS/JS page draft;
- `website/bricks-paste.html`;
- a DSA/AppShell theme package;
- a combined handoff;
- a Framework / Bricks global theme profile; or
- a Bricks conversion package.

It does not create a new page, theme, DSA shell, Bricks JSON, WooCommerce logic, cart runtime, checkout runtime, auth runtime, service worker, or staging mutation.

`/audit /accessibility` inspects concrete files and reports what fails.

`/fix /accessibility` revises only the existing artifact lane that failed. It must not recreate the website, convert to Bricks, create a DSA theme, create a combined preview, or add documentation unless `/document` is present.

For browser AIs, this lane is not a generic accessibility-consultant essay. Use Kiwe/Seam context first, make the smallest safe file changes, and return a compact pass/fail summary.

If no artifact/file map is supplied, stop and ask for the files.

## Required output

Create or update this lane:

```text
accessibility/
  kiwe-accessibility-plan.json
```

Do not emit `ACCESSIBILITY-NOTES.md`, README files, reports, or duplicate documentation unless the command also includes `/document` or the human explicitly asks for notes.

Do not add a duplicate website preview just for accessibility. The existing page/theme/combined preview remains the visual proof. If you need to demonstrate dark mode in a preview, revise the existing preview controls or the existing page artifact.

## Structural preservation contract

`/fix /accessibility` is an in-place repair lane. Preserve the design system and artifact structure unless the failure cannot be fixed otherwise.

Default preservation requirements:

- Do not add new Bricks elements.
- Do not remove Bricks elements.
- Do not replace a native Bricks element tree with a Code element.
- Do not remove Seam classes such as `.seam-*`.
- Do not remove `data-role`, `data-flow`, `data-kiwe-*`, or `data-dsa-*` attributes.
- Do not remove Bricks dynamic tags, query-loop intent, conditions, interactions, ARIA labels, IDs, or relationships.
- Do not create a DSA/AppShell theme or modify AppShell geometry while fixing a page/template accessibility issue.
- Preserve content element count, global class count, and existing global variable names where possible.

Allowed small changes:

- change existing foreground/background token values when contrast fails;
- add a missing accessibility plan;
- add native light/dark token remap rules to the existing page/theme artifact;
- add official Kiwe/Seam token references;
- add clearly namespaced project tokens only when the value is a true project art-direction constant and no official token fits;
- add wrapping/min-size/fluid sizing using Geometry/Seam variables or calculated `clamp(...)` when critical text is clipped.

Any exception must be reported in the final compact structural-drift summary. If the fix adds classes, variables, or elements, explain why the existing Seam/Framework vocabulary could not solve the accessibility failure.

## Accessibility plan schema

Use this JSON shape:

```json
{
  "schema": "kiwe.accessibility-plan.v1",
  "source": {
    "mode": "website|theme|combined|framework|bricks-conversion",
    "artifact": "short human-readable artifact summary"
  },
  "modes": ["light", "dark"],
  "tokenPairs": [
    {
      "id": "page",
      "purpose": "Default body text over site background.",
      "foreground": "var(--kiwe-color-text)",
      "background": "var(--kiwe-color-surface)",
      "modes": ["light", "dark"],
      "minimumContrast": 4.5,
      "status": "tokenized"
    }
  ],
  "bricks": {
    "themeStyle": {
      "usesRootThemeStyle": true,
      "maps": {
        "siteBackground": "var(--kiwe-color-surface)",
        "colorPrimary": "var(--kiwe-color-brand)",
        "colorSecondary": "var(--kiwe-color-accent)",
        "colorLight": "var(--kiwe-color-surface)",
        "colorDark": "var(--kiwe-color-text)",
        "colorMuted": "var(--kiwe-color-text-muted)"
      }
    }
  },
  "findings": [],
  "manualReview": []
}
```

The plan may include extra explanatory fields, but these keys must exist:

- `schema`;
- `source`;
- `modes` containing both `light` and `dark`;
- `tokenPairs`;
- `manualReview`.

## Required token-pair coverage

At minimum, cover the real surfaces used by the artifact:

- page background + body text;
- raised card/surface + card text;
- muted surface + muted/secondary text;
- brand/accent CTA + readable on-brand/on-accent text;
- badge/chip/pill foreground + badge/chip/pill background;
- dark card/surface + dark-mode text;
- danger/success/warning/info states when present;
- DSA dock and screen/sheet chrome when auditing an AppShell theme;
- FBT/product rails and product cards when commerce appears;
- Bricks theme-style root colors when the output targets Bricks.

Every visible text-bearing pill, badge, chip, button, card, tab, rail card, statistic, and product label needs an explicit foreground/background relationship in light and dark. A decorative pill with `color: #fff` on `background: #fff` is a blocking failure even if the overall page is beautiful.

## Native light/dark requirement

Use Kiwe/Seam native theme state:

- `data-kiwe-theme="light"` / `data-kiwe-theme="dark"` for Kiwe/AppShell-aware surfaces;
- `data-kiwe-theme-toggle` for page controls that toggle the site/app theme outside the dock;
- `data-theme="light"` / `data-theme="dark"` only when the artifact is standalone and clearly maps back to Kiwe theme state;
- `prefers-color-scheme: dark` may be a fallback, not the only proof when Kiwe theme state exists.

Do not implement dark mode with filter/invert hacks. Do not create two unrelated palettes. Dark mode should be a controlled token remap of the same visual thesis.

### Dark-mode token remap recipe

This context is complete for `/fix /accessibility`; do not search the repository for a separate dark-mode contract.

For a website/page or Bricks template, add dark proof to the existing root page class or root page element. Use the artifact's real page root class, for example `.nc-home`, `.bv-home`, or another existing root. Do not create a new wrapper just for dark mode.

Pattern:

```css
.page-root-class,
[data-kiwe-theme="light"] .page-root-class {
  --kiwe-color-surface: <light page background>;
  --kiwe-color-surface-raised: <light card/surface>;
  --kiwe-color-surface-sunken: <light muted surface>;
  --kiwe-color-text: <light readable text>;
  --kiwe-color-text-muted: <light readable muted text>;
  --kiwe-color-text-inverse: <text on dark/brand surfaces>;
  --kiwe-color-border: <light border>;

  /* Existing project variables map to Kiwe tokens. */
  --project-page-bg: var(--kiwe-color-surface);
  --project-card-bg: var(--kiwe-color-surface-raised);
  --project-text: var(--kiwe-color-text);
  --project-muted: var(--kiwe-color-text-muted);
  --project-line: var(--kiwe-color-border);
}

html[data-kiwe-theme="dark"] .page-root-class,
[data-kiwe-theme="dark"] .page-root-class,
.page-root-class[data-kiwe-theme="dark"] {
  --kiwe-color-surface: <dark page background>;
  --kiwe-color-surface-raised: <dark card/surface>;
  --kiwe-color-surface-sunken: <dark muted surface>;
  --kiwe-color-text: <dark readable text>;
  --kiwe-color-text-muted: <dark readable muted text>;
  --kiwe-color-text-inverse: <text on light/brand surfaces>;
  --kiwe-color-border: <dark border>;

  --project-page-bg: var(--kiwe-color-surface);
  --project-card-bg: var(--kiwe-color-surface-raised);
  --project-text: var(--kiwe-color-text);
  --project-muted: var(--kiwe-color-text-muted);
  --project-line: var(--kiwe-color-border);
}
```

For National-Chikki-style project variables, the expected mapping is:

```css
.nc-home,
[data-kiwe-theme="light"] .nc-home {
  --nc-paper: var(--kiwe-color-surface);
  --nc-surface: var(--kiwe-color-surface-raised);
  --nc-surface-soft: var(--kiwe-color-surface-sunken);
  --nc-ink: var(--kiwe-color-text);
  --nc-muted: var(--kiwe-color-text-muted);
  --nc-line: var(--kiwe-color-border);
  --nc-brand: var(--kiwe-color-brand);
  --nc-accent: var(--kiwe-color-accent);
}

html[data-kiwe-theme="dark"] .nc-home,
[data-kiwe-theme="dark"] .nc-home,
.nc-home[data-kiwe-theme="dark"] {
  --kiwe-color-surface: #14100d;
  --kiwe-color-surface-raised: #201915;
  --kiwe-color-surface-sunken: #2a211c;
  --kiwe-color-text: #fff6ea;
  --kiwe-color-text-muted: #d9c5b2;
  --kiwe-color-text-inverse: #201b18;
  --kiwe-color-border: rgba(255, 246, 234, .18);
  --nc-paper: var(--kiwe-color-surface);
  --nc-surface: var(--kiwe-color-surface-raised);
  --nc-surface-soft: var(--kiwe-color-surface-sunken);
  --nc-ink: var(--kiwe-color-text);
  --nc-muted: var(--kiwe-color-text-muted);
  --nc-line: var(--kiwe-color-border);
}
```

The concrete hex values may differ by brand, but the structure should not. Brand/accent colors may be adjusted for contrast, but keep them as `--kiwe-color-brand`, `--kiwe-color-accent`, and existing project variables rather than creating an unrelated dark palette.

## Bricks native theme-style alignment

Bricks 2.4 theme-style color controls map global color slots at `:where(:root)`, including:

- `colorPrimary`;
- `colorSecondary`;
- `colorLight`;
- `colorDark`;
- `colorMuted`;
- `colorBorder`;
- `colorInfo`;
- `colorSuccess`;
- `colorWarning`;
- `colorDanger`;
- `siteBackground`.

When the output includes a Framework / Bricks theme profile, map Kiwe tokens through `settings.tokens.bricks_theme_style` instead of inventing a separate Bricks palette. Keep this global and safe:

- site background;
- body text;
- headings;
- links;
- palette/global variables.

Do not write element-level Bricks styles into the Framework profile. Page-specific art direction stays in the page CSS/classes or the Bricks element tree.

## What `/create /accessibility` should do

1. Inspect only the supplied artifact files.
2. Identify actual color surfaces and text-bearing components.
3. Add/repair Kiwe token usage where hardcoded colors would break light/dark.
4. Add native dark-mode state proof to the existing preview/page.
5. Inspect visible containment risks: overlapping text, labels hidden by overflow, clipped pills/chips/buttons, text squeezed inside bento cards, and text over gradients/images with no readable fallback.
6. Create `accessibility/kiwe-accessibility-plan.json`.
7. If `/document` was requested, create `accessibility/ACCESSIBILITY-NOTES.md` explaining:
   - source artifact;
   - token pairs;
   - light/dark behavior;
   - Bricks theme-style mapping;
   - known manual-review items;
   - commands/tests actually run.

If a dynamic Bricks artifact or Site Graph binding exists, preserve dynamic intent. Do not replace dynamic tags/query loops with sampled preview text merely to prove contrast.

## What `/audit /accessibility` should reject

Blocking failures:

- missing accessibility plan for an accessibility audit;
- no dark-mode proof;
- literal low-contrast CSS pairs such as white on white or black on black;
- dark-mode CSS that changes backgrounds but leaves text on the old light token;
- badges/chips/pills/buttons with no readable `on-*` foreground;
- text-bearing titles, labels, pills, chips, buttons, tabs, prices, stats, or critical card text that is clipped, hidden, nowrap-ellipsized, or line-clamped inside a constrained box without an accessible full-text path;
- bento/card/product rail layouts where visible text is cut off at supported widths and the fix requires Geometry/Seam sizing rather than a manual one-screen patch;
- `filter: invert()` dark mode;
- hidden duplicate text layers used to fake contrast;
- production/import artifacts containing preview-only color fixtures;
- a Bricks target with no Kiwe/Bricks theme-style alignment when the output claims Bricks readiness.

Warnings/manual review:

- gradients or images behind text without a solid fallback token;
- semi-transparent text/background pairs that require composed contrast proof;
- private project color variables not mapped in `tokenPairs`;
- color literals that could be replaced with official Kiwe tokens;
- dark mode proven only by `prefers-color-scheme` when Kiwe theme state exists.
- cards/rails with `overflow:hidden`, `overflow:clip`, `white-space:nowrap`, `text-overflow:ellipsis`, or `line-clamp` on non-critical body/excerpt text; these can be acceptable only with desktop/tablet/mobile/narrow render proof and accessible full text.

## Geometry / Seam accessibility rule

Visible clipping is not just a cosmetic accessibility failure; it is also a Geometry Engine / Seam contract failure when the element declares what it is.

Examples:

- A `data-role="title"`, `.seam-title`, heading, badge, pill, chip, tab, button, price, or stat must remain readable at desktop, tablet, mobile, and narrow widths.
- A card may crop decorative media, but it must not crop its own critical text.
- A horizontal rail may scroll cards, but each visible card must have enough tokenized inline/block space for its own label and CTA.
- If the source proves different responsive states, use a real Kiwe calculated `clamp(...)`.
- If the value is a stable art-direction constant, declare a project token in the Framework profile or Bricks global variables.
- Do not fix clipping by shrinking text until unreadable, hiding overflow, duplicate hidden text, or making a one-screen absolute-position patch.

When static validation cannot prove text over image/gradient/transparent layers, the AI must run or request browser/render proof. Required proof widths are desktop, tablet, mobile, and narrow. If browser tools are unavailable, leave a blocking/manualReview item instead of claiming pass.

## What `/fix /accessibility` should do

1. Run or emulate `/audit /accessibility` on the supplied artifact.
2. Fix only the files that failed the accessibility lane.
3. Add native Kiwe light/dark token state, preferably with `[data-kiwe-theme="light"]`, `[data-kiwe-theme="dark"]`, and `data-kiwe-theme-toggle` when the artifact has a theme toggle.
4. Replace unsafe literal color pairs with Kiwe/Seam token pairs or documented project tokens mapped in the accessibility plan.
5. Replace clipped critical text surfaces with wrapping, fluid Geometry/Seam sizing, safer min-block sizing, rail item width tokens, or accessible full text.
6. Preserve Bricks dynamic tags, query-loop intent, Kiwe launcher attributes, and DSA/AppShell boundaries.
7. Preserve the structural counts listed above unless a documented accessibility-token exception is necessary.
8. Output only the revised existing artifact file(s) plus `accessibility/kiwe-accessibility-plan.json`; do not output notes unless `/document` was requested.

## Final response contract

Keep the chat reply short unless `/document` is present.

Required final fields:

- `Status`: `PASS`, `FAIL`, or `PASS WITH WARNINGS`.
- `Files changed`: exact file names only.
- `Structural drift`: element/class/variable/attribute changes, or `none`.
- `Validation`: command/tool actually run or `manual/static only`.
- `Remaining`: blocking failures and warnings, if any.

Do not include long WCAG tutorials, marketing paragraphs, or generic accessibility explanations in the final chat response. Put documentation in `ACCESSIBILITY-NOTES.md` only when `/document` is present.

## Validator

Tool-capable clients should run:

```bash
node kiwe-ai-toolkit/tools/validate-accessibility.cjs <handoff-or-accessibility-dir>
```

The validator is deterministic and non-mutating. It catches obvious literal contrast failures and missing dark-mode proof. It cannot visually prove text over photography or complex gradients, so those must be documented in `manualReview`.

Browser AI clients with Kiwe API access may also send the generated file map to:

```text
POST /wp-json/dsa/v1/ai/audit-companion/review
```

Use `/usecompanion` only as an optional assist. If it fails, continue with this context and report the fallback.
