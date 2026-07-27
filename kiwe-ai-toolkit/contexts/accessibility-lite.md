# Kiwe Accessibility Lite Context

Use this context only for:

```text
/create /accessibility
/audit /accessibility
```

This is a focused accessibility lane for color contrast, light/dark mode, Kiwe/Seam token pairing, and Bricks global theme-style alignment. Do not use this phase for font-size/readability scaling yet; that is a separate future lane.

## Boundary

`/create /accessibility` needs an existing artifact:

- a pure HTML/CSS/JS page draft;
- `website/bricks-paste.html`;
- a DSA/AppShell theme package;
- a combined handoff;
- a Framework / Bricks global theme profile; or
- a Bricks conversion package.

It does not create a new page, theme, DSA shell, Bricks JSON, WooCommerce logic, cart runtime, checkout runtime, auth runtime, service worker, or staging mutation.

`/audit /accessibility` inspects and revises concrete files. If no artifact/file map is supplied, stop and ask for the files.

## Required output

Create or update this lane:

```text
accessibility/
  kiwe-accessibility-plan.json
```

Do not emit `ACCESSIBILITY-NOTES.md`, README files, reports, or duplicate documentation unless the command also includes `/document` or the human explicitly asks for notes.

Do not add a duplicate website preview just for accessibility. The existing page/theme/combined preview remains the visual proof. If you need to demonstrate dark mode in a preview, revise the existing preview controls or the existing page artifact.

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
5. Create `accessibility/kiwe-accessibility-plan.json`.
6. If `/document` was requested, create `accessibility/ACCESSIBILITY-NOTES.md` explaining:
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
