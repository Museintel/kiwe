# Kiwe 6.63 Start entrypoint and command-terminal proof

## Reason

Kiwe 6.62 made `/convert /bricks` stricter: Bricks output must consume the Framework token layer and must fail direct component colors or hardcoded design lengths.

Kiwe 6.63 adds a smaller front door so browser AIs, IDE AIs, MCP clients, and Companion-assisted workflows do not waste tokens exploring the repository before they reach that strict command contract.

## New entrypoints

Browser/human-readable:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-START.md
```

Machine-readable:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/entry.json
```

The Start contract records:

- `contractVersion: 6.63`
- the repository identity;
- first-response rules;
- attachment classification;
- step-by-step versus full-flow selection;
- no-repo-crawl read policy;
- MCP/tool preference;
- the raw HTML/CSS/JS to Seam/Framework/Bricks/accessibility flow;
- the 100% Seam token-purity rule.

## CLI / MCP behavior

CLI:

```text
node kiwe-ai-toolkit/bin/kiwe.js entry
```

MCP:

```text
kiwe_get_start
```

Tool-capable AIs should use:

1. `kiwe_get_start`
2. `kiwe_get_command_manifest`
3. `kiwe_diagnose_command`
4. `kiwe_route_command`
5. the lane validator

Browser AIs without tools should read `entry.json`, then `command-manifest.json`, then only the context files listed for the matched command.

## No-command attachment behavior

If the human gives a file but no slash command, the AI should:

1. report `Kiwe Start contract: 6.63`;
2. classify the file;
3. if it is raw HTML/CSS/JS, ask whether to run:
   - step-by-step flow; or
   - full-flow execution;
4. avoid generation until that choice is clear.

For `homepage-appsite-v3-main-only-preview.html`, the expected classifier is:

```text
raw-html-css-js
```

because it is a standalone HTML document with inline CSS and visible page markup, not a Bricks template upload JSON, not a Framework profile, and not a DSA theme package.

## Local validation

```text
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
node --check kiwe-ai-toolkit/bin/kiwe.js
node --check kiwe-ai-toolkit/lib/kiwe-core.js
node --check kiwe-ai-toolkit/mcp/index.js
node -e "JSON.parse(require('fs').readFileSync('kiwe-ai-toolkit/entry.json','utf8')); JSON.parse(require('fs').readFileSync('kiwe-ai-toolkit/command-manifest.json','utf8'));"
node kiwe-ai-toolkit/bin/kiwe.js entry
npm.cmd test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Expected:

- PHP syntax passes.
- Start entry JSON parses and reports `kiwe.start.v1` / `6.63`.
- CLI `kiwe entry` works.
- Toolkit tests pass.
- Connector contracts prove `KIWE-START.md`, `entry.json`, CLI, MCP, and command manifest are aligned.
- Package manifest reports version `6.63`.
- Package verifier reports `Package 6.63` with all files verified.
