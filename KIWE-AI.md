# Kiwe AI entrypoint

If you are an AI, designer, or developer using this repository to create a website/page, Kiwe DSA AppShell theme, or combined handoff, do not read the whole codebase first.

Start with the public toolkit:

```text
kiwe-ai-toolkit/
```

The toolkit gives compact context packs and validation rules so you do not waste tokens on plugin internals.

## Browser AI path, no tool execution

If you are a browser-based AI and cannot connect the Kiwe MCP server or run the CLI, do not clone or crawl the full repository.

Read exactly one static context file after this entrypoint:

- Website/page only: `kiwe-ai-toolkit/contexts/website.md`
- Kiwe DSA/AppShell theme only: `kiwe-ai-toolkit/contexts/theme.md`
- Website/page + AppShell direction/settings, browser-short version: `kiwe-ai-toolkit/contexts/combined-lite.md`
- Website/page + AppShell direction/settings, full version: `kiwe-ai-toolkit/contexts/combined.md`

For a prompt that asks for both a website/page and a Kiwe AppShell/DSA direction, read:

```text
kiwe-ai-toolkit/contexts/combined-lite.md
```

Treat that file as the authoritative generated toolkit response. It exists so browser AIs do not need to execute repo code or read the full plugin.

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

## CLI fallback

If MCP/tool calling is unavailable but shell execution is allowed:

```bash
npm install --prefix kiwe-ai-toolkit
node kiwe-ai-toolkit/bin/kiwe.js start auto --brief "Paste the human brief here."
```

If shell execution is not allowed, use the browser AI path above.

## Human prompt should be short

The human should not need to prompt-engineer Kiwe. A good prompt is:

```text
Use https://github.com/Museintel/kiwe and the Kiwe AI Toolkit.
Create a Netflix-like ultra-modern news website for Indian startups and businesses.
Include the Kiwe AppShell/DSA direction too.
```

The toolkit response carries the detailed output format, Bricks handoff rules, Seam framework boundaries, preview requirements, and validation expectations.
