# SEAM Toolkit

This package contains the strict SEAM command router and deterministic validators shared by the hosted start registry, CLI and MCP server.

## Commands

```text
/ideate
/preview
/seam
/library
/convert /bricks
/audit
/accessibility
/fix
/redo
```

There are no aliases. SiteGraph and embedded Design Context are input data. `/ideate` plans; `/preview` approves the usage brief to generate raw source; `/seam` prepares an accepted preview as design-preserving Framework source; `/library` reads the complete source-bound reference. `/convert /bricks` retains its immutable binding-only path and existing modifiers. Framework native conversion remains an explicit compiler option. Only `seam.kiwe` emits production Bricks JSON; no authoring command approves live deployment.

## CLI

```text
node bin/kiwe.js manifest
node bin/kiwe.js diagnose --command "/convert /bricks" --artifact-summary source.html
node bin/kiwe.js route --command "/accessibility" --artifact-summary template.json
node bin/kiwe.js validate-bricks-conversion fixtures/bricks-conversion-valid --site-graph fixtures/bricks-conversion-valid/site-graph.json
node bin/kiwe.js validate-framework-profile fixtures/framework-profile-valid
node bin/kiwe.js validate-accessibility fixtures/accessibility-valid
```

## MCP

`mcp/index.js` exposes the same router and validators with `seam_*` tool names. `mcp/sitegraph-client.js` is the separate read-only SiteGraph client.

## Verification

```text
npm test
node ../tools/release/build-command-registry.cjs --check
```

The validators remain the executable authority. Prompt prose cannot create PASS evidence.
