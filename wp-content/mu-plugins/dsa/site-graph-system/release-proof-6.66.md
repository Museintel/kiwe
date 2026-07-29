# Kiwe / DSA Release Proof 6.66

Date: 2026-07-29

## Scope

6.66 names the external-AI command-central layer **SeamFlow**.

SeamFlow covers browser-based AIs with token limits, IDE/app AIs, MCP clients, skill-capable agents, and Companion-assisted workflows. It is the external flow for producing Seam Framework and Bricks-powered outputs without reading the whole Kiwe plugin or wasting tokens.

`KIWE-START.md` remains the compatibility URL, but the public contract now reports:

```text
SeamFlow contract: 6.66
```

## Architecture boundary

SeamFlow is external AI:

- browser AI such as ChatGPT, Claude, Grok, Kimi, etc.;
- long-context app/IDE AI such as Codex, Claude Code, Cursor-like IDEs;
- MCP/tool-capable clients;
- skill-capable clients.

Kiwe Internal AI / Companion is internal:

- plugin-native;
- WordPress-aware;
- Bricks-aware;
- bounded and token-saving;
- built from Kiwe context and contracts;
- available to SeamFlow through `/usecompanion`, REST API, MCP, or Studio routes when configured.

Browser-only SeamFlow must still work without Kiwe Internal AI.

## Current launch scope

Close this first:

- Seam Framework rebuild/audit/fix;
- Kiwe Framework profile;
- Bricks-powered webpages;
- Bricks headers;
- Bricks footers;
- Bricks reusable templates/sections;
- Site Graph/dynamic intent;
- Bricks conversion audit/fix;
- accessibility audit/fix through light/dark and text containment.

DSA/AppShell theme remains a critical SeamFlow lane, but full DSA theme production hardening is the next phase after page-builder flow testing passes.

## Tooling

New preferred planner names:

```text
CLI: kiwe seamflow
MCP: kiwe_seamflow_plan
```

Compatibility aliases remain:

```text
CLI: kiwe plan-flow
MCP: kiwe_plan_flow
```

## Bricks target guidance

`/convert /bricks` now explicitly covers page body, header, footer, and reusable section/template outputs:

```text
homepage/body -> templateType: "content", content[]
page body     -> templateType: "content", content[]
header        -> templateType: "header", header[]
footer        -> templateType: "footer", footer[]
section       -> templateType: "section", content[]
```

The source is still page/template HTML only. DSA/AppShell theme files must never be converted through `/convert /bricks`.

## Verification commands

Run from repository root:

```text
node kiwe-ai-toolkit/bin/kiwe.js entry
node kiwe-ai-toolkit/bin/kiwe.js seamflow
node kiwe-ai-toolkit/bin/kiwe.js seamflow --artifact-summary "homepage-appsite-v3-main-only-preview.html raw html css js"
node kiwe-ai-toolkit/bin/kiwe.js plan-flow --artifact-summary "native Bricks template upload JSON with title templateType header and seam classes"
npm test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
```
