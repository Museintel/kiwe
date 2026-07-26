# Kiwe 6.41 release proof

Date: 2026-07-26

## Scope

This release hardens the Kiwe AI Toolkit command shell that external/browser/IDE AIs use before creating Seam pages, DSA AppShell themes, combined handoffs, Site Graph bindings, Bricks conversion packages, and staging apply plans.

No runtime cart, checkout, PhoneKey, SecureTrack, WooCommerce, Bricks save, payment, service worker, or DSA Geometry Engine authority is added in this release.

## Added

- `/list` command route for command discovery without generation.
- `/fix` command route for repairing an existing failed artifact lane without restarting creative work.
- `/usesitegraph` as the canonical Site Graph command.
- `/usesitegraph /replacepreviewdata` for replacing preview-only samples with Site Graph data while preserving production dynamic intent.
- `/usesitegraph /websitename` for deriving site identity from Site Graph instead of frontend scraping.
- `/usesitegraph /nonai` for forcing the AI-less Site Graph Data/export lane.
- MCP `kiwe_list_commands`.
- CLI `kiwe list` and `kiwe commands`.

## Preserved

- Legacy `/build` remains an internal alias for `/create`.
- Legacy `/dynamic /sitegraph` remains accepted internally and normalizes to `/usesitegraph`.
- `/usecompanion` remains optional and non-blocking.
- `/usesitegraph /nonai` overrides `/usecompanion` so non-AI reads stay non-AI.

## Boundaries

- `/create /brickstheme` is a Kiwe Framework / Bricks global token profile, not a DSA AppShell theme, Bricks element JSON, or preview lane.
- `/convert /bricks` converts only `website/bricks-paste.html`.
- `/convert /bricks` must not consume `combined-preview/`, `appshell-theme/`, DSA dock/sheet/screen/navbar markup, `theme-package.json`, or `css/theme.css`.
- Canonical Bricks conversion output remains `bricks-conversion/kiwe-bricks-conversion.json` plus `BRICKS-CONVERSION-NOTES.md`.
- Site Graph phases must use target-site truth from API-key routes, exported `kiwe.site-graph.v1` JSON, or the AI-less/public Site Graph Data API. Public frontend scraping remains forbidden as fallback.

## Local proof

Ran:

```bash
node --check kiwe-ai-toolkit/lib/kiwe-core.js
node --check kiwe-ai-toolkit/bin/kiwe.js
node --check kiwe-ai-toolkit/mcp/index.js
node kiwe-ai-toolkit/bin/kiwe.js list
node kiwe-ai-toolkit/bin/kiwe.js route --command "/list"
```

Ran an import-level command assertion covering:

- `listCommands()` schema and command inventory;
- `/dynamic /sitegraph` normalizes to `/usesitegraph`;
- `/usesitegraph /nonai` without target truth returns `needs_input`;
- `/repalcepreview` is rejected with `/replacepreviewdata` suggestion;
- `/usesitegraph /replacepreviewdata /nonai /usecompanion` emits Site Graph guidance and skips Companion;
- `/fix /bricksconversion` emits the fix-phase route.

Result: passed.

## Post-upload checks

After the package is uploaded to staging:

1. Confirm `/wp-json/dsa/v1/manifest` reports `6.41`.
2. Confirm the public GitHub raw workflow file lists `/list`, `/fix`, and `/usesitegraph`.
3. Ask a browser AI to read only `KIWE-AI.md` or `workflow-lite.md`, run `/list`, and wait for the next command.
4. Ask `/usesitegraph /nonai` without API/export and confirm it asks for target-site truth instead of scraping.
5. Ask `/convert /bricks` against an AppShell/combined-preview artifact summary and confirm it rejects the lane.

