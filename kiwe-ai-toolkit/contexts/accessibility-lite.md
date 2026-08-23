# Kiwe Accessibility Lite Context

Use this context only for:

```text
/accessibility
/create /accessibility
/audit /accessibility
/fix /accessibility
```

This is an independent accessibility lane. It works on the current raw HTML/CSS/JS page during or after `/ideate`, on a framework-neutral Bricks conversion, or on a Seam Framework/Bricks package. It never requires Seam Framework or Bricks. It covers semantics, keyboard/focus behavior, readable text, contrast, reflow/spacing resilience, reduced motion, touch targets, and a deliberate brand-aware light/dark color system.

Bare `/accessibility` means: inspect the current working page/artifact, report the automated evidence score and manual checks separately, then make the smallest safe improvements to that same artifact when the human is actively refining it. During `/ideate`, preserve the accepted composition, content, motion language, and art direction. Do not replace the design with a generic accessibility template or a black-background inversion.

## Boundary

`/accessibility` and `/create /accessibility` need a current artifact, which may be the HTML/CSS/JS page already present in the active AI conversation:

- a pure HTML/CSS/JS page draft;
- `website/bricks-paste.html`;
- a DSA/AppShell theme package;
- a combined handoff;
- a Framework / Bricks global theme profile; or
- a Bricks conversion package.

It does not create a new page, theme, DSA shell, Bricks JSON, WooCommerce logic, cart runtime, checkout runtime, auth runtime, service worker, or staging mutation.

`/audit /accessibility` inspects concrete files and reports what fails.

`/fix /accessibility` revises only the existing artifact lane that failed. It must not recreate the website, convert to Bricks, create a DSA theme, create a combined preview, or add documentation unless `/document` is present.

`/audit /fix /accessibility` is one closed pass: inspect the complete current artifact, record findings, repair all safely discoverable failures, render the result in both modes at supported widths, and re-audit the repaired artifact. The human does not have to enumerate visible symptoms such as a clipped capsule label, an uneven card footer, or an overflowing heading when those defects can be discovered from the files or rendered page.

For browser AIs, this lane is not a generic accessibility-consultant essay. Use Kiwe/Seam context first, make the smallest safe file changes, and return a compact pass/fail summary.

If no current page, preview, artifact, or file map exists, stop and ask for it. Do not require a downloaded handoff when the AI is already working on the page in the same conversation.

## Evidence and score contract

- Automated checks may produce a `SEAM automated accessibility score` only from code/DOM/render evidence actually inspected.
- Report passed checks, failed checks, and automated coverage. Keep manual review outside the numeric score.
- Never label the score “WCAG compliant,” “certified,” or complete accessibility proof.
- Complex image/gradient contrast, screen-reader usability, cognitive clarity, zoom/reflow, keyboard flow, and real assistive-technology behavior remain manual/browser checks unless they were actually exercised.
- If the page already passes the available automated checks, preserve it and return the score plus remaining manual checks; do not manufacture edits.

## Non-destructive dark-mode contract

- Treat dark mode as a designed second expression of the same brand, not literal inversion and not a monotonous near-black preset.
- Derive semantic roles first: canvas, raised/sunken surface, body/secondary/inverse text, brand/accent, borders, and status colors.
- Inspect the approved light design's personality before choosing dark values: brand-color distribution, warm/cool bias, imagery, hierarchy, decorative layers, borders, shadows, translucency, and focal accents.
- Preserve the brand hue where it remains usable, retain meaningful accent distribution, reduce glare with tiered tinted surfaces, and verify foreground/background contrast numerically. Do not collapse every section, card, chip, and control into charcoal merely because the contrast score passes.
- Rebalance borders, shadows, translucency, illustrations, and decorative layers for dark surfaces without recoloring protected logos or product imagery. The dark result must still be recognizably the same project.
- Explicit light/dark values in an existing Framework profile remain authoritative. Generated dark values fill missing roles; they do not overwrite deliberate supplied values.
- At Bricks level, use native light/dark palette values and `data-brx-theme`. At Kiwe level, bridge the same state to `data-kiwe-theme`. At raw HTML level, use semantic CSS custom properties, honor the system preference, and provide a visible keyboard-operable toggle unless the human explicitly requests system-only behavior.
- Bind enhanced Bricks variables at the converted template root under `:root[data-brx-theme="dark"]` so source-scoped variables cannot shadow the native Bricks palette.

The deterministic validator measures evidence and rejects known hazards; it does not choose the art direction. The browser AI must use visual judgement to design and inspect the second mode while preserving the accepted light mode.

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
  "closure": {
    "command": "/audit /fix /accessibility",
    "auditFixReaudit": "passed",
    "darkModeArtDirection": "passed",
    "repeatedComponentsReviewed": true,
    "renderProof": [
      {
        "viewport": "desktop",
        "width": 1440,
        "modes": ["light", "dark"],
        "status": "passed",
        "evidence": "screenshot path, browser result, or concrete rendered evidence inspected"
      },
      { "viewport": "tablet", "width": 1024, "modes": ["light", "dark"], "status": "passed", "evidence": "..." },
      { "viewport": "mobile", "width": 390, "modes": ["light", "dark"], "status": "passed", "evidence": "..." },
      { "viewport": "narrow", "width": 320, "modes": ["light", "dark"], "status": "passed", "evidence": "..." }
    ]
  },
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

`closure` is optional for a score-only `/accessibility` or `/create /accessibility` pass. It is mandatory for `/audit /fix /accessibility`, and `renderProof` must contain separate passed entries for desktop, tablet, mobile, and narrow widths. Every entry must cover light and dark, record a positive width, and identify the concrete rendered evidence inspected. If render tools are unavailable, the command must return `NEEDS_INPUT`; it must not claim closure.

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

Use the theme state native to the artifact:

- `data-kiwe-theme="light"` / `data-kiwe-theme="dark"` for Kiwe/AppShell-aware surfaces;
- `data-kiwe-theme-toggle` for page controls that toggle the site/app theme outside the dock;
- `data-brx-theme="light"` / `data-brx-theme="dark"` when the target Bricks site exposes native frontend color mode; Bricks may set this on `:root`/`html` and emit dark palette variables under `:root[data-brx-theme="dark"]`;
- `data-theme="light"` / `data-theme="dark"` for a framework-neutral standalone artifact; do not introduce Kiwe or Seam solely to implement accessibility;
- `prefers-color-scheme: dark` may be a fallback, not the only proof when Kiwe theme state exists.

Do not implement dark mode with filter/invert hacks. Do not create two unrelated palettes. Dark mode should be a controlled token remap of the same visual thesis.

### Dark-mode token remap recipe

This context is complete for `/fix /accessibility`; do not search the repository for a separate dark-mode contract.

Add dark proof to the existing root page class or root page element. Use the artifact's real page root class; do not create a new wrapper just for dark mode.

For framework-neutral raw HTML/CSS/JS, preserve and remap the project's own semantic variables under `html[data-theme="dark"]` (plus `prefers-color-scheme` fallback when appropriate). Do not add `--kiwe-*`, Seam classes, or Bricks selectors.

For an artifact that already targets Kiwe, Seam, AppShell, or Bricks, use the integrated pattern below:

Pattern:

```css
.page-root-class,
[data-kiwe-theme="light"] .page-root-class,
html[data-brx-theme="light"] .page-root-class,
:root[data-brx-theme="light"] .page-root-class {
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
.page-root-class[data-kiwe-theme="dark"],
html[data-brx-theme="dark"] .page-root-class,
:root[data-brx-theme="dark"] .page-root-class {
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

The concrete values differ by project. In integrated artifacts, brand/accent colors may be adjusted for contrast but remain mapped through `--kiwe-color-brand`, `--kiwe-color-accent`, and the artifact's existing project variables. In framework-neutral artifacts, retain the equivalent project-owned semantic variables without inventing Kiwe names.

## Bricks native theme-style alignment

Bricks theme-style color controls map global color slots at `:where(:root)` on compatible sites, including:

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

Bricks frontend color mode is native but separate from Kiwe's AppShell state when available:

- Bricks sets `document.documentElement.dataset.brxTheme` from `localStorage.brx_mode`, the Bricks default mode, or `prefers-color-scheme`;
- Bricks emits light palette variables at `:root` and dark palette variables at `:root[data-brx-theme="dark"]`;
- Kiwe/AppShell still owns `data-kiwe-theme` and `data-kiwe-theme-toggle`;
- Bricks-ready accessibility fixes should support both selectors when a Bricks template/page is supplied, unless the artifact is explicitly not for Bricks.

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
3. Add or repair semantic color-token usage where hardcoded colors would break light/dark. Preserve project-local tokens in framework-neutral raw pages; use Kiwe/Seam tokens only when the artifact already targets Kiwe, Seam, AppShell, Framework, or Bricks integration.
4. Add native dark-mode state proof to the existing preview/page.
5. Inspect visible containment risks: overlapping text, labels hidden by overflow, clipped pills/chips/buttons, text squeezed inside bento cards, and text over gradients/images with no readable fallback.
6. Compare every repeated component family—cards, product tiles, rail items, pills, tabs, and CTA groups—for consistent internal alignment. Different copy lengths must not arbitrarily move equivalent actions unless the source design intentionally proves that behavior.
7. Inspect the complete page in light and dark modes at desktop, tablet, mobile, and narrow widths when render/browser tools are available; do not approve only the currently visible viewport.
8. Create `accessibility/kiwe-accessibility-plan.json`.
9. If `/document` was requested, create `accessibility/ACCESSIBILITY-NOTES.md` explaining:
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
- repeated card/rail/product components whose equivalent CTAs or footers drift because their content bodies do not share resilient flex/grid sizing;
- `filter: invert()` dark mode;
- a contrast-passing dark mode that erases the approved brand/accent hierarchy into a generic near-black palette;
- hidden duplicate text layers used to fake contrast;
- production/import artifacts containing preview-only color fixtures;
- a Bricks target with no Kiwe/Bricks theme-style alignment when the output claims Bricks readiness.

Warnings/manual review:

- gradients or images behind text without a solid fallback token;
- semi-transparent text/background pairs that require composed contrast proof;
- project color variables not mapped in `tokenPairs` when their foreground/background relationship cannot otherwise be proven;
- color literals that could be replaced with the artifact's existing semantic project tokens, or official Kiwe tokens when the artifact already targets Kiwe/Seam;
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
3. Add the artifact-native light/dark token state: standalone `data-theme` plus system preference for framework-neutral raw pages; Kiwe/Bricks selectors and state bridges only for artifacts already targeting those systems.
4. Replace unsafe literal color pairs with documented project tokens, or Kiwe/Seam tokens when that framework is already in scope, and map the pairs in the accessibility plan.
5. Replace clipped critical text surfaces with wrapping, fluid Geometry/Seam sizing, safer min-block sizing, rail item width tokens, or accessible full text.
6. Repair repeated-component alignment with resilient flex/grid structure, content growth, and shared footer placement; do not use fixed coordinates or copy-specific offsets.
7. Preserve Bricks dynamic tags, query-loop intent, Kiwe launcher attributes, and DSA/AppShell boundaries.
8. Preserve the structural counts listed above unless a documented accessibility-token exception is necessary.
9. Render and inspect the complete repaired page in light and dark at desktop, tablet, mobile, and narrow widths, then re-run the accessibility audit. For combined `/audit /fix /accessibility`, unavailable rendering is `STATUS: NEEDS_INPUT`, not a warning or manual PASS. A score-only `/accessibility` pass may still list unexecuted render work under `manualReview`.
10. Record the closure evidence in `plan.closure` and run `node kiwe-ai-toolkit/tools/validate-accessibility.cjs <artifact-root> --closure`. A non-zero or unavailable validator result must not be labelled PASS or complete. Return any corrected candidate artifact as `STATUS: NEEDS_INPUT` with the exact missing proof so the repair work is not discarded.
11. Output only the revised existing artifact file(s) plus `accessibility/kiwe-accessibility-plan.json`; do not output notes unless `/document` was requested.

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
