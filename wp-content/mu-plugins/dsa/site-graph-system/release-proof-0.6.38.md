# Kiwe 0.6.38 release proof

Date: 2026-07-24

## Scope

This release adds the no-waste slash-command gate for AI/tool workflows.

The command gate returns `kiwe.command-diagnostic.v1` with:

- `ok` when a phase may proceed;
- `rejected` when the command does not exist or crosses a forbidden lane;
- `needs_input` when the command is valid but required artifact/context/authority is missing;
- `noop` when the requested output already exists or is the same artifact.

If `stop` is true, the AI/tool must stop and report the diagnostic to the human instead of continuing into generation, conversion, audit, dynamic binding, or staging work.

## Covered examples

- `/buid /preview /brickstheme` -> `unknown_command_token`
- `/create /preview /brickstheme` -> `unsupported_preview_target`
- `/create /preview /website` with `website/bricks-paste.html` -> `website_preview_already_exists`
- `/convert /bricks` without `website/bricks-paste.html` -> `bricks_convert_missing_page_source`
- `/convert /bricks` against AppShell/combined/theme lanes -> rejected
- `/audit /bricksconversion` without `kiwe-bricks-conversion.json` -> `bricks_audit_missing_conversion_artifact`
- `/dynamic /sitegraph` without Site Graph/API truth -> `dynamic_missing_site_graph`
- `/apply /staging` without explicit staging authority -> `staging_missing_explicit_authority`

## Surfaces updated

- Toolkit core route diagnostics
- Toolkit CLI `kiwe diagnose`
- MCP `kiwe_diagnose_command`
- Companion context `commandGate`
- Companion ask stop-answer behavior
- Workflow, entrypoint, and README docs
- Connector contract smoke checks

## Verification commands

```text
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
node --check kiwe-ai-toolkit/lib/kiwe-core.js
node --check kiwe-ai-toolkit/bin/kiwe.js
node --check kiwe-ai-toolkit/mcp/index.js
npm.cmd test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/verify-package.cjs
node tools/release/rc12-contracts.cjs
```
