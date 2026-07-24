# Kiwe 0.6.37 release proof

Date: 2026-07-24

## Scope

This release tightens the AI command and Bricks conversion boundaries:

- `/convert /bricks` is now explicitly page-only.
- The only canonical conversion source is `website/bricks-paste.html`.
- Combined previews, AppShell previews, AppShell theme imports, DSA sheet/screen/dock/navbar markup, `theme-package.json`, and `css/theme.css` are rejected as conversion sources.
- Canonical preview-proof commands are now `/create /preview /dsatheme` and `/create /preview /combined`.
- `/build` remains only a tolerated legacy alias internally; public toolkit language normalizes to `/create`.

## Guardrails added

- Toolkit router returns focused guidance for DSA preview proof and combined preview proof.
- Companion phase cards describe those two preview routes.
- Companion and deterministic Bricks conversion validators fail forbidden AppShell/preview source lanes.
- `bricksConversionChecked` no longer passes when source data is missing or points at a forbidden lane.
- A negative Bricks conversion fixture proves the guard rejects `combined-preview/index.html`.

## Verification commands

```text
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php
node --check kiwe-ai-toolkit/lib/kiwe-core.js
node --check kiwe-ai-toolkit/lib/bricks-conversion-validator.js
npm.cmd test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs kiwe-ai-toolkit/fixtures/bricks-conversion-invalid-appshell-source --optional
```

Expected negative fixture result: validation exits non-zero with a failure explaining that `/convert /bricks` must not use `combined-preview` or AppShell/theme lanes as source.
