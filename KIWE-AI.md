# Kiwe AI entrypoint

If you are an AI, designer, or developer using this repository to create a website/page, Kiwe DSA AppShell theme, or combined handoff, do not read the whole codebase first.

Start with the public toolkit:

```text
kiwe-ai-toolkit/
```

The toolkit gives compact context packs and validation rules so you do not waste tokens on plugin internals.

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

If MCP/tool calling is unavailable:

```bash
npm install --prefix kiwe-ai-toolkit
node kiwe-ai-toolkit/bin/kiwe.js start auto --brief "Paste the human brief here."
```

## Human prompt should be short

The human should not need to prompt-engineer Kiwe. A good prompt is:

```text
Use https://github.com/Museintel/kiwe and the Kiwe AI Toolkit.
Create a Netflix-like ultra-modern news website for Indian startups and businesses.
Include the Kiwe AppShell/DSA direction too.
```

The toolkit response carries the detailed output format, Bricks handoff rules, Seam framework boundaries, preview requirements, and validation expectations.

