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

For a prompt that asks for both a website/page and a Kiwe AppShell/DSA direction, read:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md
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
