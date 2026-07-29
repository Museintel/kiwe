# Kiwe / DSA Release Proof 6.64

Date: 2026-07-29

## Scope

6.64 expands Kiwe Start from a Seam-only front door into a full Appsite flow planner for browser AI, IDE AI, MCP clients, and Companion-assisted workflows.

The goal is the command-terminal behavior:

- user can give only the Kiwe repo/start link plus an attachment;
- AI reports `Kiwe Start contract: 6.64`;
- AI classifies the artifact before generating;
- AI asks for step-by-step vs full-flow when no command is supplied;
- AI routes only the chosen command lane;
- AI uses MCP/tools when available and falls back to raw context files when not;
- AI does not crawl the repository or create docs unless `/document` is present.

## Added / changed

- `KIWE-START.md` contract bumped to 6.64.
- `kiwe-ai-toolkit/entry.json` now contains full artifact classifier, flow planner instructions, MCP preference, output discipline, Seam purity, and accessibility rules.
- `kiwe-ai-toolkit/lib/kiwe-core.js` exports `planFlow()`.
- `kiwe-ai-toolkit/bin/kiwe.js` exposes:

```text
kiwe plan-flow [--command text] [--artifact-summary text] [--desired-outcome text] [--use-companion]
```

- `kiwe-ai-toolkit/mcp/index.js` exposes:

```text
kiwe_plan_flow
kiwe_validate_framework_profile
```

## Flow coverage

The 6.64 planner covers:

- raw HTML/CSS/JS draft -> Seam -> Framework profile -> Bricks -> accessibility;
- Seam page artifact -> audit/fix -> Framework profile -> Bricks -> accessibility;
- native Bricks template upload -> Bricks conversion audit/fix -> accessibility;
- Bricks conversion envelope -> Bricks conversion audit/fix -> accessibility;
- Framework profile -> audit/fix;
- DSA/AppShell theme package -> theme audit/fix -> accessibility;
- combined handoff -> combined audit/fix -> accessibility.

## Important boundaries

- `/convert /bricks` is page-only and must never convert DSA/AppShell theme files.
- Documentation is opt-in through `/document`.
- Fix commands preserve their current lane and do not restart creative work.
- Accessibility fixes must preserve Seam classes, Kiwe/Appsite attributes, Bricks dynamic tags, query-loop intent, and DSA/AppShell boundaries.
- MCP/Companion is preferred when available but must not block raw browser-AI operation.

## Verification commands

Run from repository root:

```text
node --check kiwe-ai-toolkit/bin/kiwe.js
node --check kiwe-ai-toolkit/lib/kiwe-core.js
node --check kiwe-ai-toolkit/mcp/index.js
node kiwe-ai-toolkit/bin/kiwe.js entry
node kiwe-ai-toolkit/bin/kiwe.js plan-flow --artifact-summary "homepage-appsite-v3-main-only-preview.html raw html css js"
npm test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
```

## Expected browser-AI first prompt

```text
Read Kiwe Start:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-START.md

I have attached a file. Follow Kiwe Start.
```

Expected AI response: report contract 6.64, classify the attached file, ask step-by-step vs full-flow if no command is supplied, and mention MCP/tool use only if available or needed.
