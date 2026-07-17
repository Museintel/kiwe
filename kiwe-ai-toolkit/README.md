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
node bin/kiwe.js start combined --brief "Netflix-like ultra-modern news website for Indian startups and business news, with a matching Kiwe AppShell direction"
node bin/kiwe.js context combined
node bin/kiwe.js create combined ./out/my-kiwe-handoff --name my-kiwe-handoff
node tools/validate-output.cjs ./out/my-kiwe-handoff --mode combined
node bin/kiwe.js dynamic-context
node bin/kiwe.js dynamic-pass --brief "Turn approved product rails into Bricks query-loop binding plans using the supplied Site Graph."
node bin/kiwe.js validate-bindings ./out/my-kiwe-handoff --site-graph ./site-graph.json
node bin/kiwe.js prepare-apply ./out/my-kiwe-handoff --site-graph ./site-graph.json
```

## For external AIs and designers

The human prompt should stay short. The toolkit carries the rules.
For browser AIs, give direct raw links so the model does not waste tokens searching GitHub.

Good human prompts:

```text
Use the Kiwe AI Toolkit. Read only:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md

Create a Netflix-like ultra-modern news website for Indian startups, businesses,
entrepreneurs, and celebrity-owned business spotlights, with its Kiwe AppShell included.
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

The AI should then start with one tool call:

```json
{
  "tool": "kiwe_start_project",
  "arguments": {
    "mode": "auto",
    "brief": "Use the human's plain-language design brief exactly."
  }
}
```

If tool calling is not available, clone the repo and run:

```bash
npm install --prefix kiwe-ai-toolkit
node kiwe-ai-toolkit/bin/kiwe.js start combined --brief "Netflix-like ultra-modern news website for Indian startups, businesses, entrepreneurs, funding news, and celebrity-owned business spotlights, with matching Kiwe AppShell/DSA direction."
```

Do not ask the human to paste long Kiwe rules. `kiwe_start_project` returns the relevant mode context, output contract, preview requirements, Bricks boundaries, Seam vocabulary guidance, and AppShell separation rules.

Do not expect humans to mention artifact names, screen eligibility, responsive overflow rules, Kiwe authority boundaries, or validator requirements. Those are toolkit responsibilities.

The Kiwe AppShell is runtime chrome around the page, not part of the Bricks page itself. In combined mode, only `combined-preview/index.html` shows the page and AppShell together.

### Browser AI fallback

Some browser AIs can read public GitHub files but cannot connect MCP tools or safely execute repo code. In that case, do not clone or crawl the whole repo. Read one static context file:

- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/website.md` for a normal website/page.
- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/theme.md` for a Kiwe DSA/AppShell theme.
- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md` for browser models that need a short website/page plus AppShell contract.
- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined.md` for a website/page plus AppShell direction/settings.
- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md` for v2/v3/v4 revision and self-audit passes.

These files are generated from the toolkit context and exist specifically for ChatGPT/Claude/Grok/Kimi-style browser workflows.
Do not use web search to find these files; open the exact raw URL that matches the assignment mode.
For revisions, read the mode context first, then `audit-lite.md`, and report what was found/fixed. Do not claim the executable Kiwe CLI/audit ran unless it actually executed.

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

- `kiwe_start_project`
- `kiwe_list_modes`
- `kiwe_get_context`
- `kiwe_create_handoff`
- `kiwe_validate_handoff`
- `kiwe_validate_bindings`
- `kiwe_prepare_apply_plan`
- `kiwe_list_class_vocabulary`
- `kiwe_get_dynamic_context`
- `kiwe_start_dynamic_pass`

## Dynamic WordPress/Bricks binding pass

After a website/page or combined handoff passes visual/toolkit audit, use the dynamic pass to bind it to a real WordPress site:

1. Export/read the target site's admin-only Site Graph:

```text
GET /wp-json/dsa/v1/site-graph?sampleLimit=8
```

On WordPress 7+ with Abilities API available, Kiwe also exposes:

```text
dsa/get-site-graph
dsa/validate-bindings
dsa/prepare-apply-plan
dsa/stage-apply-plan
```

2. Give the AI only:

- the current handoff files;
- the Site Graph JSON;
- `kiwe_get_dynamic_context` or `kiwe_start_dynamic_pass`.

3. The AI should add:

```text
bricks-bindings/
  kiwe-bindings.json
  BINDING-NOTES.md
```

This is a binding plan, not a direct mutation. It maps placeholder rails/cards/buttons to Bricks query loops, dynamic data tags, and Kiwe launchers using real site terms/pages/products. Later trusted apply adapters can use Bricks 2.4 abilities to convert/import/apply after validation.

Validate the binding plan before accepting it:

```bash
node kiwe-ai-toolkit/tools/validate-bindings.cjs ./path/to/handoff --site-graph ./site-graph.json
```

The same check is available to MCP clients as `kiwe_validate_bindings`. It validates `bricks-bindings/kiwe-bindings.json` against `kiwe.bricks-bindings.v1`, verifies real Site Graph post types/terms/query-loop object types/dynamic tags where supplied, and enforces canonical Kiwe launchers such as `data-dsa-open-module`.

If Kiwe is installed on the target WordPress site, admins can also upload the produced `kiwe-bindings.json` at `Kiwe > Framework > AI connector and Site Graph` for a live non-mutating validation report. The same admin report also previews/downloads the dry-run apply plan, can stage it as a `kiwe.trusted-apply-stage.v1` review candidate, can run `kiwe.trusted-adapter-proof.v1` against the current live Site Graph, can attach `kiwe.guarded-apply-authorization.v1`, can build `kiwe.pre-execution-gate.v1`, can build `kiwe.trusted-execution-preview.v1`, can attach `kiwe.final-apply-confirmation.v1`, can run `kiwe.fresh-sitegraph-revalidation.v1`, can build `kiwe.rollback-readiness-checkpoint.v1`, can attach `kiwe.target-resolution.v1`, can capture `kiwe.rollback-capture.v1`, can attach `kiwe.rendered-target-inspection.v1`, can build `kiwe.minimal-adapter-shell.v1`, and can record `kiwe.final-save-approval.v1` for a future trusted adapter, including preflight gates, Bricks/Kiwe operations, capability signals, blockers, manual-review items, rollback/render/final-confirmation requirements, live drift checks, target locking, target rollback snapshots, target baseline inspection, smallest-mutation route selection, final save approval, and post-apply checks, without saving anything.

On WordPress 7+ / MCP Adapter capable sites, the same early connector path is available as WordPress abilities: `dsa/get-site-graph`, `dsa/validate-bindings`, `dsa/prepare-apply-plan`, and `dsa/stage-apply-plan`. Treat these as safe connector surfaces: only `dsa/stage-apply-plan` writes, and it writes Kiwe internal staging metadata rather than Bricks, WordPress page content, WooCommerce, or publish state.

4. After validation, prepare the dry-run apply plan:

```bash
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs ./path/to/handoff --site-graph ./site-graph.json
```

MCP clients can call `kiwe_prepare_apply_plan`.

This returns `kiwe.bricks-apply-plan.v1`: a non-mutating plan that lists preflight gates, Bricks query-loop operations, dynamic-data operations, Kiwe launcher/menu-context operations, manual review items, and future adapter steps. Add `--write` only when you intentionally want the toolkit to write `bricks-apply/kiwe-apply-plan.json` and `bricks-apply/APPLY-NOTES.md` into the handoff.

This is still not a WordPress mutation. It exists so a later trusted adapter can apply with admin approval, revision capture, rendered-output inspection, and post-apply audit.

## Output modes

### Website only

```text
website-handoff/
  README.md
  website/
    bricks-paste.html  # browser preview + Bricks paste/import artifact
    bricks-notes.md
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
```

### Combined

```text
combined-kiwe-handoff/
  README.md
  combined-preview/index.html  # primary human review: website with DSA over it
  website/bricks-paste.html    # Bricks artifact; also website/page preview
  website/bricks-notes.md
  appshell-theme/import/theme-id/theme.json
  appshell-theme/import/theme-id/css/theme.css
  appshell-theme/preview/index.html          # optional technical fixture only
  appshell-theme/preview/PLACEHOLDERS.md     # optional technical fixture only
  kiwe-settings/               # optional profile/settings lane
```

Combined mode has one primary visual review: `combined-preview/index.html`. It must show the website/page behind the Kiwe AppShell and include AppShell variation controls there. Do not make reviewers open a separate website preview and a separate AppShell preview to understand the paired design.

## Naming

The public tool namespace is `kiwe`.

- Kiwe = product/tool namespace.
- Seam = neutral framework and class vocabulary.
- DSA/AppShell = runtime surface, sheets, dock, and capabilities.
