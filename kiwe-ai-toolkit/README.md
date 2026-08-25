# SEAM Toolkit

This package contains the strict SEAM command router and deterministic validators shared by the hosted start registry, CLI and MCP server.

## Commands

```text
/ideate
/convert /bricks
/audit
/accessibility
/fix
/redo
```

There are no aliases. SiteGraph is input data. Seam Framework is the `frameworkMode` conversion option. SEAM Compiler is the only production Bricks JSON authority.

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
