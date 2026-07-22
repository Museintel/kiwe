# Kiwe DSA 0.6.22 release proof

Purpose: close the Seam purity gap before the next staging test.

## What changed

- `assets/css/surface.css` now has a runtime primitive bridge: raw legacy visual values live in token authority, while runtime selectors consume `var(...)`.
- `assets/css/bricks-studio-ai.css` now uses Seam/Kiwe tokens for the native Bricks front-end AI companion UI.
- `tools/ui-theme/audit-runtime-token-purity.cjs` was added as a release-gate audit.

## Proof commands

```bash
node tools/ui-theme/audit-seam-adoption.cjs
node tools/ui-theme/audit-runtime-token-purity.cjs
node tools/ui-theme/validate-package.cjs tools/ui-theme/fixtures/valid
node kiwe-ai-toolkit/tools/validate-framework-profile.cjs kiwe-ai-toolkit/fixtures/framework-profile-valid
```

## Result

- Seam adoption audit passed.
- Runtime token-purity audit passed:
  - DSA Surface runtime: 4,187 runtime declarations checked, 793 authority tokens allowed.
  - Kiwe Bricks Studio AI runtime: 95 runtime declarations checked, 29 authority tokens allowed.
- Theme package validator passed.
- Framework profile validator passed.

## Boundary

CSS `@media` / `@container` query thresholds remain browser syntax. Component declarations inside those wrappers are tokenized. Future geometry cleanup can migrate older wrappers to Geometry Engine state selectors after visual verification.
