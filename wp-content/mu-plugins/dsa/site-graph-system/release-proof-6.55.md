# Kiwe 6.55 token-pure Bricks conversion proof

## Changes in 6.55

- Added generic universal tokens for fine/strong border widths and compact/readable content widths so common Bricks-native conversions do not invent project variables for reusable sizing needs.
- Added matching Seam runtime aliases and utilities (`.seam-compact`, `.seam-readable`) across the live runtime, Framework system handoff, and AI toolkit packs.
- Documented the official Kiwe token fallback ladder:
  1. official Kiwe/Seam token when meaning and property domain match;
  2. declared project variable for stable site-specific art direction;
  3. real fluid `clamp(...)` only when source responsive states prove different min/max values.
- Added the deterministic `kiwe fluid-clamp` helper so shell/MCP-capable agents can calculate interpolation instead of inventing formulas.
- Tightened Bricks conversion validator and Companion messages so `/audit /bricksconversion` rejects hardcoded Bricks-native design lengths, complex no-op clamps such as `clamp(min(82%, 210px), min(82%, 210px), min(82%, 210px))`, and clamp-shaped values that do not follow Kiwe's calculated `clamp(min, calc(intercept + slope * vw), max)` geometry form.
- Expanded native Bricks style-control coverage so grid template and grid auto controls such as `_gridTemplateColumns`, `_gridTemplateRows:*`, and `_gridAutoColumns` are audited instead of slipping through as unchecked layout constants.
- Synchronized the public AI contexts, Framework source docs, and toolkit handoff packs so browser AIs receive the same guidance from `KIWE-AI.md`, `workflow-lite.md`, `bricks-conversion-lite.md`, `combined-lite.md`, and the website-builder pack.

## Local validation

```text
npm test
php -l wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
node kiwe-ai-toolkit/bin/kiwe.js fluid-clamp --min 220px --max 390px --min-vw 478 --max-vw 1440
node kiwe-ai-toolkit/bin/kiwe.js fluid-clamp --min 22px --max 22px
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs C:/Users/shariq/Downloads/national-chikki-home-template-upload.json
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Expected:

- Kiwe AI Toolkit test suite passes.
- PHP syntax checks pass.
- `kiwe fluid-clamp` returns a real `kiwe.fluid-clamp.v1` CSS clamp when min/max differ.
- `kiwe fluid-clamp` refuses no-op clamp generation when min/max are identical.
- The current National Chikki native Bricks template upload is now an intentional regression fixture for the stricter audit and fails with 16 token-purity findings until `/fix /bricksconversion` replaces non-Kiwe clamps, no-op clamps, and grid literals with official variables, declared project variables, or real calculated clamps.
- `package-manifest.json` reports version `6.55`.
- Package verifier reports `Package 6.55: 281 files verified.`

## Staging smoke

1. Upload both `wp-content/mu-plugins/dsa.php` and the complete `wp-content/mu-plugins/dsa/` folder from this release.
2. Confirm `/wp-json/dsa/v1/manifest` reports `6.55`.
3. In `Kiwe > Framework`, push a Framework profile to Bricks and confirm the new generic variables are available alongside the existing universal token set.
4. Run `/audit /bricksconversion` on a native Bricks template upload that includes hardcoded values such as `_padding: 28px`; confirm it fails with token-ladder guidance.
5. Run `/fix /bricksconversion`; confirm the fixed template uses official variables, declared project variables, or real fluid clamps, not no-op clamps.
