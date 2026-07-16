# Kiwe AI Toolkit

Kiwe AI Toolkit lets an AI, web designer, or Bricks developer create import-ready Kiwe handoffs without reading the full Kiwe/DSA plugin codebase.

It exposes only the compact contracts an AI needs:

- `website` — normal WordPress/Bricks page or website using Seam Framework.
- `theme` — Kiwe DSA/AppShell theme package.
- `combined` — website/page plus AppShell theme plus optional Kiwe settings profile.

The full plugin remains the runtime authority. This toolkit is the public design/generation interface.

## Why this exists

Giving the whole plugin to an AI wastes tokens and invites it to invent against internals. Kiwe instead hands the AI a mode-specific contract pack:

- prompts;
- allowed classes/tokens;
- screen payloads;
- theme package schema;
- preview rules;
- Bricks boundaries;
- Kiwe settings/profile lane;
- validation expectations.

## CLI

```bash
npm install
node bin/kiwe.js modes
node bin/kiwe.js context combined
node bin/kiwe.js create combined ./out/my-kiwe-handoff --name my-kiwe-handoff
node tools/validate-output.cjs ./out/my-kiwe-handoff --mode combined
```

## MCP

Run the MCP server over stdio:

```bash
npm install
node mcp/index.js
```

Example MCP client entry:

```json
{
  "mcpServers": {
    "kiwe": {
      "command": "node",
      "args": ["/absolute/path/to/kiwe-ai-toolkit/mcp/index.js"]
    }
  }
}
```

## MCP tools

- `kiwe_list_modes`
- `kiwe_get_context`
- `kiwe_create_handoff`
- `kiwe_validate_handoff`
- `kiwe_list_class_vocabulary`

## Output modes

### Website only

```text
website-handoff/
  README.md
  website/
    preview/
      index.html
      assets/site.css
    bricks-notes.md
  KIWE_CONTEXT.md
```

### AppShell theme only

```text
theme-handoff/
  README.md
  appshell-theme/
    import/theme-id/theme.json
    import/theme-id/css/theme.css
    preview/index.html
    preview/PLACEHOLDERS.md
  KIWE_CONTEXT.md
```

### Combined

```text
combined-kiwe-handoff/
  README.md
  website/
  appshell-theme/
  kiwe-settings/
  KIWE_CONTEXT.md
```

## Naming

The public tool namespace is `kiwe`.

- Kiwe = product/tool namespace.
- Seam = neutral framework and class vocabulary.
- DSA/AppShell = runtime surface, sheets, dock, and capabilities.

