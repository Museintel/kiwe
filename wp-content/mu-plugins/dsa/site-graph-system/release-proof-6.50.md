# Kiwe 6.50 Framework profile + Bricks theme-style command proof

Purpose: make the Kiwe browser-AI command lane faster and stricter by returning only the artifact asked for, while preserving the correct setup order for Seam/Bricks rendering.

## Changes in 6.50

- `/create /frameworkprofile` is now the preferred setup command for Kiwe > Framework.
  - Default output: `framework/kiwe-framework-profile.json`.
  - The profile is imported in `Kiwe > Framework` and pushed from there to Bricks.
  - It is the single “push everything to Bricks” artifact for variables, colors, classes, and safe Bricks global style metadata.
- `/create /brickstheme` is now a narrow Bricks-only lane.
  - Default output: `bricks-theme-style.json`.
  - The file is a native Bricks Theme Styles JSON object for direct Bricks visual-editor import.
  - It must not contain a Kiwe Framework profile wrapper, page/template element trees, DSA/AppShell theme packages, cart/checkout/auth authority, or extra notes unless `/document` is requested.
- Added the Bricks Theme Styles validator surface:
  - `kiwe-ai-toolkit/lib/bricks-theme-style-validator.js`;
  - `kiwe-ai-toolkit/tools/validate-bricks-theme-style.cjs`;
  - `kiwe-ai-toolkit/schemas/bricks-theme-style.schema.json`;
  - matching website-builder/framework-system contract copies;
  - a valid fixture under `kiwe-ai-toolkit/fixtures/bricks-theme-style-valid/`.
- `/convert /bricks` now checks for a framework foundation before converting.
  - Accepted foundation proof: `framework/kiwe-framework-profile.json`, `bricks-theme-style.json`, or an explicit statement that the Kiwe Framework profile/theme style has already been pushed to Bricks.
  - Without that proof, the command stops with `bricks_convert_missing_framework_profile`.
- `/document` remains the only default path for generated README/notes/report files across the command system.

## Local proof

Commands run:

```bash
cmd /c npm.cmd test
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Results:

- Kiwe AI Toolkit tests pass, including `/convert /bricks` missing-framework diagnostics and the Bricks Theme Styles validator fixture.
- PHP syntax checks pass for the root MU loader and package entry point.
- Package manifest rebuilt with 276 files.
- Package manifest verifies as `6.50` with 276 files.

## Staging proof still required

After uploading the complete MU package, confirm:

1. `/wp-json/dsa/v1/manifest` reports `6.50`.
2. Browser AI using `explore: https://github.com/Museintel/kiwe` then `/list` shows `/create /frameworkprofile`, `/audit /frameworkprofile`, `/create /brickstheme`, `/audit /brickstheme`, and `/document`.
3. Browser AI using `/create /frameworkprofile` outputs only `framework/kiwe-framework-profile.json` by default.
4. Importing that profile in `Kiwe > Framework` and pushing to Bricks creates the expected Kiwe variables, colors, classes, and global style metadata.
5. Browser AI using `/create /brickstheme` outputs only `bricks-theme-style.json` by default.
6. Browser AI using `/convert /bricks` without a framework/profile/theme foundation stops and asks for `/create /frameworkprofile` or a confirmed Bricks push first.
