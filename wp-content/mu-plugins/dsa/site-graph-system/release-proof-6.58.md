# Kiwe 6.58 command-manifest and accessibility fix proof

## Changes in 6.58

- Added `kiwe-ai-toolkit/command-manifest.json` as the compact machine-readable slash-command contract for browser AIs, IDE AIs, MCP clients, and Companion-assisted workflows.
- Updated `KIWE-AI.md` and `workflow-lite.md` so `explore: https://github.com/Museintel/kiwe` directs capable clients to the manifest first and the narrow Markdown context second.
- Tightened `/fix /accessibility` so it is explicitly an in-place repair lane:
  - preserve Bricks element counts where possible;
  - preserve global class counts where possible;
  - preserve Seam classes;
  - preserve `data-role`, `data-flow`, `data-kiwe-*`, and `data-dsa-*` attributes;
  - preserve dynamic tags, query-loop intent, conditions, interactions, IDs, and ARIA relationships;
  - avoid new classes/tokens unless the accessibility failure cannot be solved with existing Kiwe/Seam tokens or documented project-token mappings.
- Added a compact final-response contract for accessibility fixes: status, files changed, structural drift, validation, and remaining issues.
- Added `kiwe command-manifest` CLI output.
- Added connector contract coverage for the command manifest.

## Local validation

```text
npm.cmd test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Expected:

- Toolkit tests pass.
- Connector contracts include the command manifest and pass.
- Package manifest includes the manifest and reports version `6.58`.
- Package verifier reports `Package 6.58` with all files verified.

## Browser AI smoke expectation

For:

```text
explore: https://github.com/Museintel/kiwe
/fix /accessibility
```

Expected behavior:

- read `KIWE-AI.md` or `command-manifest.json`;
- read only `accessibility-lite.md` plus the referenced Seam/Framework context needed by the lane;
- fix only the existing failed artifact and `accessibility/kiwe-accessibility-plan.json`;
- preserve Bricks/Seam/DSA structure unless a documented accessibility-token exception is necessary;
- return a compact PASS/FAIL/WARN summary, not a long explanation;
- create no documentation, reports, duplicate previews, or ZIP files unless `/document` is present.
