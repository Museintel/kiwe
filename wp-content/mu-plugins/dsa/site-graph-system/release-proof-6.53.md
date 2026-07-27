# Kiwe 6.53 Bricks conversion command proof

Date: 2026-07-28

## Changes in 6.53

- `/convert /bricks` is the only public Bricks conversion command.
- `/create /bricks` is rejected as a stale typo and redirected to `/convert /bricks`.
- Lean `/convert /bricks` output is one native Bricks My Templates upload JSON by default:

```text
bricks-template/<page-or-template-name>-template-upload.json
```

- `audit-output.cjs` now accepts either an output folder or a single native Bricks template JSON file.
- Standalone Bricks template validation no longer fails because unrelated sibling files exist in `Downloads` or another parent folder.
- Missing target Bricks version is a warning, not an import-blocking error.
- Framework profiles must include a complete `settings.tokens.bricks_theme_style` object with `enabled: true`, safe `id`, and human `label` so Kiwe > Framework can create/update the matching Bricks Theme Style.
- Kiwe Companion/API review now catches incomplete `settings.tokens.bricks_theme_style` lanes before the user discovers missing Bricks variables or missing global style manually.

## Required local proof

Run from the repo root:

```bash
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_AI_Intelligence_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php
cd kiwe-ai-toolkit && npm test
node tools/audit-output.cjs fixtures/bricks-template-valid
node tools/validate-bricks-conversion.cjs fixtures/bricks-template-valid
node ../tools/release/build-package-manifest.cjs
node ../tools/release/verify-package.cjs
```

## Staging/browser-AI proof checklist

1. Upload MU plugin version `6.53`.
2. Confirm `/wp-json/dsa/v1/manifest` reports `6.53`.
3. Ask a browser AI to run `explore: https://github.com/Museintel/kiwe` then `/list`.
4. Confirm `/list` shows `/convert /bricks`, not `/create /bricks`.
5. Give it an approved `website/bricks-paste.html` and run `/convert /bricks`.
6. Confirm lean output is only the native Bricks template upload JSON unless `/document` was requested.
7. Upload that JSON to Bricks My Templates and confirm:
   - template title is real, e.g. `Home`;
   - insert does not show `This template has no data`;
   - no `kiwe-bricks-conversion.json` wrapper is offered as the upload file.
8. If a Framework profile is included, confirm `settings.tokens.bricks_theme_style.enabled`, `id`, and `label` are present before pushing to Bricks.
