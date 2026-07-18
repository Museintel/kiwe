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
Kiwe > AI > AI connector and Site Graph > Download Site Graph JSON
```

External tool clients can create a revocable key at `Kiwe > AI > API access keys` and then use the API-key connector:

```text
GET /wp-json/dsa/v1/ai/status
GET /wp-json/dsa/v1/ai/site-graph?sampleLimit=8
Authorization: Bearer kiwe_ai_...
```

Theme installers can use the same key to review, install, and activate Kiwe DSA theme packages:

```text
GET  /wp-json/dsa/v1/ai/themes
POST /wp-json/dsa/v1/ai/themes/install
POST /wp-json/dsa/v1/ai/themes/{themeId}/activate
```

A Kiwe theme package is one JSON file with root `schema: "kiwe.theme-package.v1"`, root `theme`, root `settings`, and root `css`. The `settings` preset is limited to safe theme-owned subsets (`style`, `dock`, `dsa_theme`, and `visual_effects`) and appears in WordPress under `Kiwe > Theme > Installed themes`. Do not output or ask users to import a loose settings file for DSA themes.

Staging-aware clients can inspect the target site and run the first controlled staging executor:

```text
GET  /wp-json/dsa/v1/ai/site-inspection?sampleLimit=12
POST /wp-json/dsa/v1/ai/staging/execute
POST /wp-json/dsa/v1/ai/stages/{stageId}/execute-staging
```

`/ai/site-inspection` is read-only and returns installed plugin inventory, active plugin status, safe Bricks option summaries, Bricks templates, pages/posts, and staging detection. It redacts secrets and does not expose raw Bricks page meta.

`/ai/staging/execute` is intentionally narrow. It requires `confirmControlledStagingExecution: true` and `stagingSiteConfirmed: true`, refuses production-looking hosts unless explicitly overridden by the human, and supports only staging-safe operations such as:

- `wordpress.page.upsert`
- `wordpress.post.upsert`
- `bricks.template.create`
- `bricks.template.upsert`
- `bricks.settings.patch`
- `kiwe.theme-package.install-activate`
- `woocommerce.mutate`
- `woocommerce.product.upsert`
- `woocommerce.order.upsert`
- `woocommerce.settings.patch`
- `cart.run`
- `checkout.run`
- `auth.run`
- `bricks.raw-meta-write`

Page/post/template operations may include `html`, `bricksPasteHtml`, and optional `css`. The executor stores sanitized staging content and preserves safe preview CSS while refusing script-like payloads. `bricks.settings.patch` is limited to known Bricks settings/options, scalar or simple nested payloads, safe path keys, and an internal patch hash log. It exists for staging checks such as Bricks import/converter switches or global-class/variable setting probes; do not use it as a raw Bricks database writer.

WooCommerce, cart, checkout, auth, and raw Bricks operations require extra explicit flags:

- WooCommerce product/order/settings mutation: `confirmWooCommerceMutation: true`
- Cart/checkout/auth harnesses: `confirmRuntimeExecution: true`
- Auth test-user create/delete: `confirmAuthRuntime: true`
- Raw Bricks `_bricks*` meta writes: `confirmRawBricksJsonWrite: true`

The executor can create/update WooCommerce staging products, create/update staging orders, patch a controlled allow-list of WooCommerce settings, run server-side cart harness actions, validate checkout fields or create pending staging orders, create/delete Kiwe-marked test users, and write allowed Bricks meta keys with backup metadata. Bricks-ready HTML is still the preferred first path; raw `_bricks` JSON writes are for controlled staging adapter tests only.

The legacy same-site admin REST path still exists for logged-in WordPress admin contexts at `GET /wp-json/dsa/v1/site-graph?sampleLimit=8`, but external AI tools should use `/wp-json/dsa/v1/ai/*` with a Kiwe AI key.

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

If the human is using the WordPress admin UI, they can upload the produced `bricks-bindings/kiwe-bindings.json` at `Kiwe > AI > AI connector and Site Graph` to get a live non-mutating validation report against the target site's current Site Graph. The same admin report also shows the dry-run apply-plan preview, lets the human download the reviewed apply-plan JSON, can stage it as a Kiwe-owned `kiwe.trusted-apply-stage.v1` review candidate, can run `kiwe.trusted-adapter-proof.v1`, can attach `kiwe.guarded-apply-authorization.v1`, can build `kiwe.pre-execution-gate.v1`, can build `kiwe.trusted-execution-preview.v1`, can attach `kiwe.final-apply-confirmation.v1`, can run `kiwe.fresh-sitegraph-revalidation.v1`, can build `kiwe.rollback-readiness-checkpoint.v1`, can attach `kiwe.target-resolution.v1`, can capture `kiwe.rollback-capture.v1`, can attach `kiwe.rendered-target-inspection.v1`, can build `kiwe.minimal-adapter-shell.v1`, can record `kiwe.final-save-approval.v1`, can build `kiwe.controlled-executor.v1`, can prepare `kiwe.bricks-controlled-adapter.v1`, and can build `kiwe.post-apply-verification.v1` as non-mutating proof artifacts without running CLI tools.

External API clients can run the same connector chain with a Kiwe AI key through `/wp-json/dsa/v1/ai/*`. These endpoints can read Site Graph context and write Kiwe internal staging/proof metadata, but they still do not save Bricks page content, publish WordPress changes, mutate WooCommerce data, or execute checkout/cart/auth behavior.

Direct mutation intent endpoints exist only as explicit locked surfaces so AI clients can discover the boundary instead of guessing:

```text
POST /wp-json/dsa/v1/ai/mutations/bricks-page-save
POST /wp-json/dsa/v1/ai/mutations/wordpress-publish
POST /wp-json/dsa/v1/ai/mutations/woocommerce
POST /wp-json/dsa/v1/ai/runtime/cart
POST /wp-json/dsa/v1/ai/runtime/checkout
POST /wp-json/dsa/v1/ai/runtime/auth
```

When called without the staging executor confirmation body, they return confirmation-required responses. With the same explicit flags used by `/ai/staging/execute`, they run through the controlled staging executor. AI keys still do not grant silent production mutation, shopper impersonation, payment execution, or unbounded database access.

`kiwe.controlled-executor.v1`, `kiwe.bricks-controlled-adapter.v1`, and `kiwe.post-apply-verification.v1` are still not saves. The executor records the future adapter interface. The adapter plan maps approved operation IDs to deterministic Bricks/Kiwe instructions. The post-apply proof selects the smallest future controlled run and proves rollback source/checks from the captured snapshot. These artifacts keep `actualApplyExecuted`/`actualSaveExecuted`, `actualRollbackExecuted`, and `mayExecuteMutationNow` false until a human starts a real staging-site controlled run.

On WordPress 7+ / MCP Adapter capable sites, Kiwe also exposes safe connector abilities for the same early chain: `dsa/get-site-graph`, `dsa/validate-bindings`, `dsa/prepare-apply-plan`, and `dsa/stage-apply-plan`. These abilities do not save Bricks/page content; `dsa/stage-apply-plan` writes only a Kiwe internal review queue record.

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
