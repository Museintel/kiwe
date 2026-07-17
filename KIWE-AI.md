# Kiwe AI entrypoint

If you are an AI, designer, or developer using this repository to create a website/page, Kiwe DSA AppShell theme, or combined handoff, do not read the whole codebase first.

Start with the public toolkit:

```text
kiwe-ai-toolkit/
```

The toolkit gives compact context packs and validation rules so you do not waste tokens on plugin internals.

## Browser AI path, no tool execution

If you are a browser-based AI and cannot connect the Kiwe MCP server or run the CLI, do not clone or crawl the full repository.
Do not use web search to discover toolkit files. Use the exact raw links below.

Read exactly one static context file after this entrypoint:

- Website/page only: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/website.md`
- Kiwe DSA/AppShell theme only: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/theme.md`
- Website/page + AppShell direction/settings, browser-short version: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md`
- Website/page + AppShell direction/settings, full version: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined.md`
- Revision/audit pass: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md`
- Dynamic WordPress/Bricks binding pass after an approved handoff: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/dynamic-lite.md`

For a prompt that asks for both a website/page and a Kiwe AppShell/DSA direction, read:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md
```

Treat that file as the authoritative generated toolkit response. It exists so browser AIs do not need to execute repo code or read the full plugin.

For v2/v3/v4-style revision prompts, read the relevant mode context first, then read:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md
```

Use it to identify and fix issues in the previous handoff. Do not claim the executable Kiwe audit ran unless you actually executed the CLI.

For a v5-style dynamic pass where the design already passed and the human wants real WordPress/Bricks/WooCommerce query loops, dynamic data, and Kiwe launchers, read:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/dynamic-lite.md
```

Also ask for the target site's `kiwe.site-graph.v1` JSON. Do not guess categories, term IDs, pages, products, dynamic tags, or Bricks query-loop object types. The Site Graph is available to admins from Kiwe as:

```text
Kiwe > Framework > AI connector and Site Graph > Download Site Graph JSON
```

Tool clients can also use:

```text
GET /wp-json/dsa/v1/site-graph?sampleLimit=8
```

On WordPress 7+ with Abilities API available, Kiwe may also expose:

```text
dsa/get-site-graph
```

## Preferred tool call

Use this tool first:

```json
{
  "tool": "kiwe_start_project",
  "arguments": {
    "mode": "auto",
    "brief": "Paste the human's plain-language design brief here."
  }
}
```

Use `mode: "website"` for a normal WordPress/Bricks page, `mode: "theme"` for a Kiwe DSA/AppShell theme, and `mode: "combined"` when the output should include both website/page work and AppShell direction/settings.

For a dynamic binding revision after the handoff already exists, use:

```json
{
  "tool": "kiwe_start_dynamic_pass",
  "arguments": {
    "brief": "Use the human's plain-language dynamic binding request exactly.",
    "siteGraphSummary": "Summarize the supplied kiwe.site-graph.v1 JSON.",
    "currentHandoffSummary": "Summarize the current handoff being revised."
  }
}
```

## CLI fallback

If MCP/tool calling is unavailable but shell execution is allowed:

```bash
npm install --prefix kiwe-ai-toolkit
node kiwe-ai-toolkit/bin/kiwe.js start auto --brief "Paste the human brief here."
node kiwe-ai-toolkit/bin/kiwe.js dynamic-pass --brief "Paste the dynamic binding request here."
node kiwe-ai-toolkit/tools/validate-bindings.cjs ./path/to/handoff --site-graph ./site-graph.json
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs ./path/to/handoff --site-graph ./site-graph.json
```

If shell execution is not allowed, use the browser AI path above.

For dynamic binding revisions, run `validate-bindings` when shell or MCP execution is available. If execution is not available, do not claim it ran; instead self-check the binding plan against `dynamic-lite.md` and report the limitation.

For apply-path requests, run `prepare-apply-plan` only after `validate-bindings` passes. The apply plan is dry-run and non-mutating. Do not claim WordPress, Bricks, WooCommerce, or Kiwe were changed unless a future trusted adapter actually performs the mutation with admin approval.

## Human prompt should be short

The human should not need to prompt-engineer Kiwe. They should only provide the repo/toolkit pointer and the design intent. Do not expect humans to mention output folders, Bricks artifacts, AppShell validator rules, screen eligibility, overflow rules, or Kiwe authority boundaries; the toolkit response supplies those.

Good human prompts:

```text
Use the Kiwe AI Toolkit. Read only:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md

Create a Netflix-like ultra-modern news website for Indian startups and businesses, with its Kiwe AppShell included.
```

```text
Use the Kiwe AI Toolkit. Read only:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md

Create a quiet luxury DSA AppShell theme.
```

```text
Use the Kiwe AI Toolkit. Read only:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md

Create a conversion-focused product landing page.
```

The AI must translate the plain-language request into the correct toolkit mode:

```json
{
  "tool": "kiwe_start_project",
  "arguments": {
    "mode": "auto",
    "brief": "Use the human's plain-language design brief exactly."
  }
}
```

The toolkit response carries the detailed output format, Bricks handoff rules, Seam framework boundaries, AppShell screen rules, preview requirements, responsive overflow rules, and validation expectations. The Kiwe AppShell is runtime chrome around the page, not part of the Bricks page itself.
