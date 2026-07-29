# Kiwe / DSA Release Proof 6.65

Date: 2026-07-29

## Scope

6.65 tightens Kiwe Start into a true command-central entrypoint.

The human prompt can now be only:

```text
read Kiwe Start:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-START.md
```

No extra "I attached a file" instruction is required.

## Required startup behavior

An AI reading Kiwe Start must:

- report `Kiwe Start contract: 6.65`;
- return a compact `/list` summary when no command is supplied;
- detect whether attachments exist;
- classify attachments by actual code/content, not filename;
- identify the current artifact stage;
- recommend the next command;
- ask whether the human wants step-by-step flow, full-flow execution, or a specific `/command`;
- wait before audit/fix/create/convert/live API/Companion review unless the human supplied an explicit command.

## Fast navigation

`entry.json` now includes a direct raw URL navigation tree for:

- Start;
- machine entry;
- command manifest;
- workflow context;
- Seam attributes context;
- Bricks conversion context;
- accessibility context;
- dynamic/Site Graph context;
- combined context;
- audit context;
- key validators.

This is designed to stop browser AIs from searching the repository, cloning unnecessarily, or reading stale files.

## Planner proof

`planFlow()` now returns:

- artifact types and confidence;
- recommended mode;
- recommended next commands;
- `startResponse` shape;
- permission policy;
- MCP/tool preference;
- hard boundaries.

For a raw HTML/CSS/JS attachment, the planner recommends:

```text
/rebuild /seamframework
/audit /seamframework
/fix /seamframework if needed
/create /frameworkprofile
/audit /frameworkprofile
/fix /frameworkprofile if needed
/convert /bricks
/audit /bricksconversion
/fix /bricksconversion if needed
/audit /accessibility
/fix /accessibility if needed
```

For a Bricks template upload, it starts at:

```text
/audit /bricksconversion
/fix /bricksconversion if needed
/audit /accessibility
/fix /accessibility if needed
```

## Verification commands

Run from repository root:

```text
node kiwe-ai-toolkit/bin/kiwe.js entry
node kiwe-ai-toolkit/bin/kiwe.js plan-flow
node kiwe-ai-toolkit/bin/kiwe.js plan-flow --artifact-summary "native Bricks template upload JSON with title templateType content and seam classes"
npm test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
```
