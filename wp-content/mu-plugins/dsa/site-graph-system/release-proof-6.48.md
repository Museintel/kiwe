# Kiwe 6.48 Bricks template upload validator proof

Purpose: close the National Chikki Bricks template-import loop where a browser-AI output imported into Bricks but rendered with stale/wrong CSS and a visible `nav: PHP class does not exist` error.

## Root cause captured

- The broken artifact was a native Bricks template JSON supplied directly, not a full `/convert /bricks` package.
- It imported as `(no title)` because `title` was not a real template name.
- It had no `templateType`, so Bricks could not reliably store it in the intended template lane.
- It relied on `pageSettings.customCss` for ordinary layout/design; after inserting into an existing page, Bricks can leave that CSS behind or collide with stale target-page CSS.
- It emitted a semantic HTML tag (`nav`) as a Bricks element `name`, which can render as `nav: PHP class does not exist`; semantic tags must be represented with supported Bricks elements such as `block`, `div`, or `container` plus `tag/customTag`.
- It had hundreds of elements but zero native Bricks style/layout controls, so it was not a production-grade visual-editor handoff.

## Changes in 6.48

- `kiwe-ai-toolkit/lib/bricks-conversion-validator.js` now detects native Bricks template JSON passed directly and reports the correct `/convert /bricks` package shape requirement.
- The Node validator now rejects `(no title)` template exports, semantic HTML tag misuse as Bricks element names, template-upload dependence on large `pageSettings.customCss`, and large templates with too few native Bricks style/layout controls.
- `DSA\AI\Bricks_Conversion_Validator` mirrors the same critical checks for the live REST route `/wp-json/dsa/v1/ai/validate-bricks-conversion`.
- `bricks-conversion-lite.md` and `audit-lite.md` now remove the ambiguity around page settings CSS: it may be a documented exception for review/clipboard lanes, but template-upload output must carry ordinary design through native controls, importable global classes, and variables.

## Local proof

Commands run:

```bash
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs C:/Users/shariq/Downloads/template-no-title-2026-07-27.json
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs kiwe-ai-toolkit/fixtures/bricks-conversion-valid
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs kiwe-ai-toolkit/fixtures/bricks-conversion-invalid-flexdirection-responsive
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Expected results:

- PHP syntax check passes.
- The broken National template JSON fails with direct-template, `(no title)`, missing `templateType`, page custom CSS dependency, semantic `nav` element misuse, and insufficient native controls.
- The valid fixture still passes.
- The invalid flex-direction fixture still fails.
- Package manifest verifies as `6.48`.

## Staging proof still required

After uploading the complete MU package, confirm:

1. `/wp-json/dsa/v1/manifest` reports `6.48`.
2. `/wp-json/dsa/v1/ai/validate-bricks-conversion` rejects the broken direct template object with the new findings.
3. Browser AI using `/audit /bricksconversion` no longer reports a structural pass for template-upload artifacts that depend on `pageSettings.customCss` or contain unsupported semantic element names.
