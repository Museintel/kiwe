# Kiwe 6.52 Bricks native upload contract proof

Date: 2026-07-27

## Changes in 6.52

- `/create /bricks` is now the preferred user-facing Bricks conversion command.
- `/convert /bricks` remains a compatibility alias, but both commands now default to one human upload artifact:

```text
bricks-template/<page-or-template-name>-template-upload.json
```

- The Bricks upload file must be a native Bricks template export:
  - top-level non-empty `title`;
  - `templateType`;
  - one non-empty `content`, `header`, or `footer` array.
- For a homepage body, the expected title is `Home` and `templateType` is `content`.
- `bricks-conversion/kiwe-bricks-conversion.json` is now explicitly optional audit/executor metadata, not the file a human uploads to Bricks My Templates.
- Lean Bricks commands must not emit notes, reports, validation files, ZIPs, duplicate previews, or other docs unless `/document` is explicitly requested.
- The Node validator, generic audit tool, MCP schema, workflow docs, and Kiwe Companion/API review now inspect native Bricks upload JSON directly.

## Browser-AI regression this closes

The failing browser-AI handoff uploaded a Kiwe wrapper to Bricks:

```json
{
  "schema": "kiwe.bricks-conversion.v1",
  "elements": []
}
```

Bricks imports that object as `(no title)` because it has no root `title`, then insert fails with `This template has no data` because Bricks reads root `content`, `header`, or `footer`, not Kiwe's review-envelope `elements`.

6.52 makes that failure a deterministic validation error before the human reaches Bricks.

## Required local proof

Run from the repo root:

```bash
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_AI_Intelligence_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php
php -l wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php
cd kiwe-ai-toolkit && npm test
node tools/audit-output.cjs fixtures/bricks-template-valid
node tools/validate-bricks-conversion.cjs fixtures/bricks-template-valid
node ../tools/release/build-package-manifest.cjs
node ../tools/release/verify-package.cjs
```

## Staging proof checklist

1. Upload MU plugin version `6.52`.
2. Confirm `/wp-json/dsa/v1/manifest` reports `6.52`.
3. Ask a browser AI to run `explore: https://github.com/Museintel/kiwe` then `/list`.
4. Give it an approved `website/bricks-paste.html` and run `/create /bricks`.
5. Confirm the output is only `bricks-template/<page>-template-upload.json` unless `/document` was requested.
6. Upload that JSON to Bricks My Templates and confirm:
   - template title is real, e.g. `Home`;
   - insert does not show `This template has no data`;
   - no `kiwe-bricks-conversion.json` wrapper is offered as the upload file.
7. Run `/audit /bricksconversion` and confirm it audits the native upload JSON or the optional wrapper if present.
