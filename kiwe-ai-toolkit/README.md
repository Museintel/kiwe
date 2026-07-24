# Kiwe AI Toolkit

Kiwe AI Toolkit lets an AI, web designer, or Bricks developer create import-ready Kiwe handoffs without reading the full Kiwe/DSA plugin codebase.

It exposes only the compact contracts an AI needs:

- `website` — normal WordPress/Bricks page or website using Seam Framework.
- `theme` — Kiwe DSA/AppShell theme package.
- `combined` — website/page plus AppShell theme package, including safe live theme settings and token profile when needed.

The full plugin remains the runtime authority. This toolkit is the public design/generation interface.

For highest-quality work, use the phased workflow instead of one giant combined prompt:

1. `/ideate /webdraft` for pure creative HTML/CSS/JS with no Kiwe constraints.
2. `/rebuild /seamframework` after the human likes the draft.
3. `/audit /seamframework`.
4. `/create /brickstheme` or `/create /frameworkprofile` for global Kiwe/Bricks token personality.
5. `/create /dsatheme`.
6. `/audit /dsatheme`.
7. `/create /preview /dsatheme` when a focused AppShell preview proof is needed.
8. `/assemble /combined`.
9. `/create /preview /combined` when the page-plus-AppShell preview needs revision.
10. `/audit /combined`.
11. `/dynamic /sitegraph` after visual approval.
12. `/convert /bricks`.
13. `/audit /bricksconversion`.

One-shot `combined` still exists for fast experiments, but serious candidate work should be staged.

Any phase can add `/usecompanion`, such as `/rebuild /seamframework /usecompanion` or `/audit /dsatheme /usecompanion`. Companion is optional and non-blocking: if a scoped Kiwe AI key and target REST base are available, use Companion once for compact phase cards or deterministic audit findings; if it is unavailable, disabled, slow, rate-limited, over budget, or inaccessible, continue with the same phase normally and report the fallback. Companion is a contract oracle/context broker, not a creative co-author or full-codebase dump.

Canonical command language uses `/create` for creation phases. `/build` may be tolerated as an old alias by the router, but toolkit-facing output should normalize back to `/create`.

Run the command gate before expensive work when tools or CLI are available:

```bash
node bin/kiwe.js diagnose --command "/create /preview /brickstheme"
```

or MCP:

```text
kiwe_diagnose_command
```

It returns `ok`, `rejected`, `needs_input`, or `noop`. If `stop: true`, the AI should answer the human with the diagnostic instead of continuing into generation, conversion, audit, dynamic binding, or staging.

Current lane rule: combined output uses AppShell `theme-package.json` for live DSA theme settings and `settings.tokens`; standalone `kiwe.framework-profile.v1` files are for website/page-only Framework token profiles or explicit `Kiwe > Framework` imports, not loose AppShell settings profiles.

Token authority rule: AI handoffs should use official universal token names in `settings.tokens.overrides` and consume generated public variables such as `--kiwe-color-brand`, `--kiwe-color-surface`, `--kiwe-radius-lg`, or documented `--kiwe-theme-*` aliases. Do not copy Kiwe core's generated `--dsa-runtime-token-####` bridge variables into themes, previews, docs, or Bricks page CSS; those names are private runtime migration glue and are rejected by the package/audit validators.

## Why this exists

Giving the whole plugin to an AI wastes tokens and invites it to invent against internals. Kiwe instead hands the AI a mode-specific contract pack:

- prompts;
- allowed classes/tokens;
- screen payloads;
- theme package schema;
- preview rules;
- Bricks boundaries;
- Bricks AI Intelligence routes for elements, query loops, dynamic tags, conditions, and interactions;
- Kiwe settings/profile lane;
- theme-package and Framework-profile boundaries;
- validation expectations.

## CLI

```bash
npm install
node bin/kiwe.js modes
node bin/kiwe.js workflow
node bin/kiwe.js diagnose --command "/create /preview /brickstheme"
node bin/kiwe.js route --command "/rebuild /seamframework /usecompanion" --brief "Rebuild the approved draft with Seam"
node bin/kiwe.js start combined --brief "Netflix-like ultra-modern news website for Indian startups and business news, with a matching Kiwe AppShell direction"
node bin/kiwe.js context combined
node bin/kiwe.js create combined ./out/my-kiwe-handoff --name my-kiwe-handoff
node tools/validate-output.cjs ./out/my-kiwe-handoff --mode combined
node tools/validate-framework-profile.cjs ./out/my-website-handoff --optional
node bin/kiwe.js dynamic-context
node bin/kiwe.js dynamic-pass --brief "Turn approved product rails into Bricks query-loop binding plans using the supplied Site Graph."
node bin/kiwe.js bricks-conversion-context
node bin/kiwe.js validate-bindings ./out/my-kiwe-handoff --site-graph ./site-graph.json
node bin/kiwe.js validate-bricks-conversion ./out/my-kiwe-handoff --site-graph ./site-graph.json
node bin/kiwe.js prepare-apply ./out/my-kiwe-handoff --site-graph ./site-graph.json
```

## For external AIs and designers

The human prompt should stay short. The toolkit carries the rules.
For browser AIs, give direct raw links so the model does not waste tokens searching GitHub.

Good human prompts:

```text
Use the Kiwe AI Toolkit. Read only:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md

Create a pure visual concept for a premium Indian startup news homepage. Do not use Kiwe yet. /ideate /webdraft
```

```text
Use the Kiwe AI Toolkit. Read only:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md

I have an approved creative HTML/CSS/JS draft. Rebuild it with Seam. /rebuild /seamframework
```

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

The AI should then start with one tool call.

For phased work:

```json
{
  "tool": "kiwe_route_command",
  "arguments": {
    "command": "/rebuild /seamframework /usecompanion",
    "brief": "Use the human's current phase request exactly.",
    "artifactSummary": "Summarize the prior artifact if one was supplied.",
    "useCompanion": true
  }
}
```

For fast one-shot work:

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

When a target site has Kiwe installed and a scoped Kiwe AI key is available, external AIs should ask `/wp-json/dsa/v1/ai/bricks/context` or `/wp-json/dsa/v1/ai/bricks/plan` for Bricks-native intelligence before emitting Bricks JSON or dynamic binding plans. A key with `bricks_ai`, `studio_ai`, or `all` scope can read this packet. It is read-only and covers Bricks elements, compact element controls, query loops, dynamic tags, conditions, interactions, Seam rules, and Kiwe launcher/runtime boundaries. Admins can also enable the read-only Kiwe Studio companion inside the Bricks front-end editor from `Kiwe > AI`.

### Browser AI fallback

Some browser AIs can read public GitHub files but cannot connect MCP tools or safely execute repo code. In that case, do not clone or crawl the whole repo. Read one static context file:

- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/workflow-lite.md` for phased command routing and best-quality generation/revision loops.
- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/website.md` for a normal website/page.
- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/theme.md` for a Kiwe DSA/AppShell theme.
- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md` for browser models that need a short website/page plus AppShell contract.
- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined.md` for a website/page plus AppShell direction/settings.
- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md` for v2/v3/v4 revision and self-audit passes.
- `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/bricks-conversion-lite.md` for `/convert /bricks` and `/audit /bricksconversion`.

These files are generated from the toolkit context and exist specifically for ChatGPT/Claude/Grok/Kimi-style browser workflows.
Do not use web search to find these files; open the exact raw URL that matches the assignment mode.
For revisions, read the mode context first, then `audit-lite.md`, and report what was found/fixed. If a target-site Kiwe AI key is available, call `/wp-json/dsa/v1/ai/audit-companion/review` with the actual generated file map before broad self-audit, fix every `mustFix` item, then rerun the same route. Do not claim the executable Kiwe CLI/audit ran unless it actually executed.

When `/usecompanion` is present, include a compact `COMPANION-TRACE` with routes attempted, success/fallback state, contextHash/siteGraphHash when supplied, cards/findings used, and confirmation that Companion did not replace the selected Kiwe phase.

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
- `kiwe_get_workflow`
- `kiwe_diagnose_command`
- `kiwe_route_command`
- `kiwe_list_modes`
- `kiwe_get_context`
- `kiwe_create_handoff`
- `kiwe_validate_handoff`
- `kiwe_validate_bindings`
- `kiwe_get_bricks_conversion_context`
- `kiwe_validate_bricks_conversion`
- `kiwe_prepare_apply_plan`
- `kiwe_list_class_vocabulary`
- `kiwe_get_dynamic_context`
- `kiwe_start_dynamic_pass`

## Dynamic WordPress/Bricks binding pass

After a website/page or combined handoff passes visual/toolkit audit, use the dynamic pass to bind it to a real WordPress site:

1. Export/read the target site's admin-only Site Graph for capabilities and Bricks/dynamic binding facts:

```text
GET /wp-json/dsa/v1/ai/site-graph?sampleLimit=8
Authorization: Bearer kiwe_ai_...
```

For public/headless content data, use the AI-less Site Graph Data API instead of scraping the frontend:

```text
GET /wp-json/dsa/v1/site-graph/data/schema
GET /wp-json/dsa/v1/site-graph/data?resource=products&taxonomy=product_cat&term=fudge&limit=4
POST /wp-json/dsa/v1/site-graph/data
```

The data route is read-only and public-safe. Anonymous requests only return public/published posts, pages, products, menus, terms, media, and site identity. Authenticated administrators can receive broader private reads. One `POST` body may include a `queries` object so a headless page can fetch site identity, menus, product rails, post rails, and media in a single GraphQL-like envelope without using the AI connector.

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
- optional Site Graph Data API responses when the visual/page content should be grounded in real public site data;
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

## Bricks conversion package

After the dynamic binding pass is accepted, use `/convert /bricks` to create a reviewable Bricks-native element package:

```text
bricks-conversion/
  kiwe-bricks-conversion.json
  BRICKS-CONVERSION-NOTES.md
```

This is the no-loss bridge between approved HTML/CSS and Bricks JSON. It should prefer Bricks 2.4 native HTML/CSS conversion where the target site exposes it, then add Kiwe fidelity evidence: source selectors, element mapping, query-loop/dynamic intent, conditions, interactions, unsupported features, manual-review notes, and preserved Seam/Kiwe attributes.

`/convert /bricks` converts only `website/bricks-paste.html`. It must never convert `combined-preview`, `appshell-theme`, DSA/AppShell preview markup, screen/sheet/dock/navbar markup, `theme-package.json`, or `css/theme.css`. Use `/create /preview /dsatheme` and `/create /preview /combined` for preview-proof work instead.

Validate it before staging:

```bash
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs ./path/to/handoff --site-graph ./site-graph.json
```

MCP clients can call `kiwe_validate_bricks_conversion`. This validator is deterministic and non-mutating; it does not save Bricks or WordPress content.

If Kiwe is installed on the target WordPress site, admins can also upload the produced `kiwe-bindings.json` at `Kiwe > AI > AI connector and Site Graph` for a live non-mutating validation report. The same admin report also previews/downloads the dry-run apply plan, can stage it as a `kiwe.trusted-apply-stage.v1` review candidate, can run `kiwe.trusted-adapter-proof.v1` against the current live Site Graph, can attach `kiwe.guarded-apply-authorization.v1`, can build `kiwe.pre-execution-gate.v1`, can build `kiwe.trusted-execution-preview.v1`, can attach `kiwe.final-apply-confirmation.v1`, can run `kiwe.fresh-sitegraph-revalidation.v1`, can build `kiwe.rollback-readiness-checkpoint.v1`, can attach `kiwe.target-resolution.v1`, can capture `kiwe.rollback-capture.v1`, can attach `kiwe.rendered-target-inspection.v1`, can build `kiwe.minimal-adapter-shell.v1`, can record `kiwe.final-save-approval.v1`, can build `kiwe.controlled-executor.v1`, can prepare `kiwe.bricks-controlled-adapter.v1`, and can build `kiwe.post-apply-verification.v1`, including preflight gates, Bricks/Kiwe operations, capability signals, blockers, manual-review items, rollback/render/final-confirmation requirements, live drift checks, target locking, target rollback snapshots, target baseline inspection, smallest-mutation route selection, final save approval, executor interface, adapter planning, post-apply verification planning, and rollback proof, without saving anything.

For external IDE/browser/tool clients, create a revocable key in `Kiwe > AI > API access keys`, then call `/wp-json/dsa/v1/ai/status`, `/wp-json/dsa/v1/ai/site-graph`, `/wp-json/dsa/v1/ai/validate-bindings`, `/wp-json/dsa/v1/ai/prepare-apply-plan`, `/wp-json/dsa/v1/ai/stage-apply-plan`, and the `/wp-json/dsa/v1/ai/stages/{stageId}/...` proof endpoints with `Authorization: Bearer kiwe_ai_...` or `X-Kiwe-AI-Key`. These endpoints can create Kiwe internal staging/proof artifacts but must not be described as Bricks, WordPress, WooCommerce, cart, checkout, auth, or publish mutations.

`kiwe.controlled-executor.v1`, `kiwe.bricks-controlled-adapter.v1`, and `kiwe.post-apply-verification.v1` are not Bricks saves. The adapter plan may report `adapterImplementationPresent: true` because the planner exists, but the late artifacts must keep `adapterCanSaveNow: false`, `actualApplyExecuted`/`actualSaveExecuted: false`, `actualRollbackExecuted: false`, and `mayExecuteMutationNow: false` until a human starts a real staging-site controlled run and captures post-apply proof.

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
  framework/
    kiwe-framework-profile.json # optional sitewide Seam/Kiwe token profile
```

Use `framework/kiwe-framework-profile.json` only when a website/page-only handoff establishes a reusable brand token profile. It must use `schema: "kiwe.framework-profile.v1"` and contain `settings.tokens` only. Combined/AppShell theme work should normally put live-intended tokens inside `appshell-theme/import/theme-id/theme-package.json` under `settings.tokens`.

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
  appshell-theme/import/theme-id/theme-package.json # single Kiwe admin/API import file when settings change
  appshell-theme/import/theme-id/css/theme.css
  appshell-theme/preview/index.html          # optional technical fixture only
  appshell-theme/preview/PLACEHOLDERS.md     # optional technical fixture only
```

Combined mode has one primary visual review: `combined-preview/index.html`. It must show the website/page behind the Kiwe AppShell and include AppShell variation controls there. Do not make reviewers open a separate website preview and a separate AppShell preview to understand the paired design.

Standalone Framework profiles import/export under `Kiwe > Framework`. Theme packages install under `Kiwe > Theme > Installed themes`. AI staging clients can apply a Framework profile with `kiwe.framework-profile.apply` and then explicitly push the active profile to Bricks with `kiwe.framework.push-bricks`.

## Naming

The public tool namespace is `kiwe`.

- Kiwe = product/tool namespace.
- Seam = neutral framework and class vocabulary.
- DSA/AppShell = runtime surface, sheets, dock, and capabilities.
