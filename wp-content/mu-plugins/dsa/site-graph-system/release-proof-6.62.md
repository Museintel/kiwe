# Kiwe 6.62 Bricks conversion Seam-token purity proof

## Reason

Kiwe 6.61 aligned DSA/AppShell dark mode with Bricks' `data-brx-theme` state. Kiwe 6.62 tightens the website-to-Bricks handoff so `/rebuild /seamframework`, `/convert /bricks`, `/audit /bricksconversion`, and `/fix /bricksconversion` cannot pass outputs that merely render well while bypassing the Framework token layer.

The practical failure this closes is a Bricks template that keeps Seam classes but stores hardcoded component paint and dimensions in native Bricks settings or `global_classes`, such as:

- `color: #fff`
- `_background.color.raw: #8deae5`
- `linear-gradient(#201b18, #514238)`
- `rgba(255,255,255,.11)`
- local component variables like `--pack-bg: #f5b942`
- hardcoded lengths like `_padding: 28px`, `_heightMin: 390px`, or no-op `clamp(22px, 22px, 22px)`

Those are now audit failures unless the value consumes an official Kiwe/Seam token, a declared project variable from the Framework profile/global variables, or a real responsive clamp calculated from proven source breakpoint states.

## Contract behavior

- `/rebuild /seamframework` documentation now requires the page to be fully Framework-token integrated before Bricks conversion.
- `/convert /bricks` documentation now states that Bricks-native controls are not allowed to be hardcoded-native.
- `/audit /bricksconversion` now checks direct component color literals in:
  - Bricks element settings;
  - native template `global_classes`;
  - copied-elements `globalClasses`;
  - `_cssCustom` custom CSS buckets.
- Literal colors remain valid only at the token/global-variable definition layer or inside `var(...)` fallbacks, for example `var(--kiwe-color-text, #201b18)`.
- The Framework profile is not allowed to be used as an excuse for a hardcoded Bricks JSON file. `/fix /bricksconversion` must fix the Bricks artifact itself.

## Validator behavior

- Node toolkit validator:
  - `kiwe-ai-toolkit/lib/bricks-conversion-validator.js`
  - `kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs`
- Legacy/browser audit tool:
  - `kiwe-ai-toolkit/tools/audit-output.cjs`
- WordPress REST validator:
  - `wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php`

All three lanes reject direct component color literals with a fail-level finding before a handoff can be treated as passing.

## Browser-AI command behavior

External AI clients that start with:

```text
explore: https://github.com/Museintel/kiwe
/rebuild /seamframework
/convert /bricks
/audit /bricksconversion
/fix /bricksconversion
```

should receive the same rule from the no-clone command manifest, workflow context, Bricks conversion context, Seam attributes context, combined contexts, and audit context:

> visual correctness is required, but visual correctness must be expressed through Seam/Framework tokens and Bricks-native editable controls.

## Local validation

```text
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php
node --check kiwe-ai-toolkit/lib/bricks-conversion-validator.js
node --check kiwe-ai-toolkit/tools/audit-output.cjs
node tools/connector/ai-api-contracts.cjs
npm.cmd test --prefix kiwe-ai-toolkit
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Expected:

- PHP syntax passes.
- Toolkit syntax passes.
- Connector contracts pass and include the Bricks color-token purity assertion.
- Toolkit tests pass.
- Package manifest reports version `6.62`.
- Package verifier reports `Package 6.62` with all files verified.

## Negative proof

The National Chikki Bricks template artifact that still contains direct component colors must fail both:

```text
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs "C:/Users/shariq/Downloads/national-chikki-home-template-fixed (1).json"
node kiwe-ai-toolkit/tools/audit-output.cjs "C:/Users/shariq/Downloads/national-chikki-home-template-fixed (1).json"
```

Expected:

- Both commands exit non-zero.
- Findings include direct component color literals such as `#fff`, `#8deae5`, gradients, `rgba(...)`, or local literal component variables.
