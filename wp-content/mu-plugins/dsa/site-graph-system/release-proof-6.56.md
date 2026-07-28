# Kiwe 6.56 token behavior role proof

## Changes in 6.56

- Added explicit token behavior roles to the canonical Seam/Kiwe token registry:
  - fixed primitive
  - fluid scale
  - geometry input
  - content limit
  - responsive guard
  - semantic token
  - alias
  - layer token
  - project token
- Surfaced those roles in `Kiwe > Framework` so admins can see why some official tokens are plain values while others are fluid `clamp(...)` values.
- Exported token-role metadata with Bricks variables so future adapters and audits can distinguish named framework primitives from anonymous hardcoded Bricks styles.
- Clarified the architectural rule across the AI toolkit, Framework docs, token maps, and validators:
  - plain values are valid in the named token definition/profile layer when they are fixed primitives, geometry inputs, content limits, responsive guards, semantic values, aliases, or layer values;
  - page, theme, and Bricks output must consume named Kiwe/Seam/project tokens or use a proven real fluid clamp;
  - anonymous copied values in Bricks-native settings remain audit failures.
- Kept the Phantom Viewport / Geometry Engine direction intact: framework tokens provide named inputs and safe bounds; Geometry Engine and real calculated clamps handle responsive interpolation where a single token cannot represent multiple viewport states.

## Local validation

```text
php -l wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php
npm test
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs C:/Users/shariq/Downloads/national-chikki-home-template-upload.json
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Expected:

- PHP syntax checks pass.
- Kiwe AI Toolkit test suite passes.
- The old National Chikki native Bricks template remains an intentional regression fixture and fails with 16 token-ladder findings until `/fix /bricksconversion` replaces anonymous Bricks values with official variables, declared project variables, or real calculated clamps.
- `package-manifest.json` reports version `6.56`.
- Package verifier reports `Package 6.56` with all files verified.

## Staging smoke

1. Upload both `wp-content/mu-plugins/dsa.php` and the complete `wp-content/mu-plugins/dsa/` folder from this release.
2. Confirm `/wp-json/dsa/v1/manifest` reports `6.56`.
3. Open `Kiwe > Framework` and confirm token-role chips appear above the token cards.
4. Push Framework variables to Bricks and confirm exported variables include `tokenRole` metadata.
5. Run `/audit /frameworkprofile` on a profile with plain named token definitions; confirm it accepts the token layer.
6. Run `/audit /bricksconversion` on Bricks output containing anonymous hardcoded design values; confirm it rejects the page/template layer with token-ladder guidance.
