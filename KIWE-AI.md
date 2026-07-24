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

Preferred path for serious work:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/workflow-lite.md
```

Use the workflow file when the human wants high-quality output, fewer correction loops, or command-style phases such as `/ideate /webdraft`, `/rebuild /seamframework`, `/audit /seamframework`, `/create /brickstheme`, `/create /dsatheme`, `/assemble /combined`, or `/dynamic /sitegraph`.

The workflow intentionally separates creativity from Kiwe contract compliance. A pure creative draft may happen first without Kiwe/Seam/DSA constraints; later commands rebuild, audit, package, and bind it.

If the human explicitly asks for one-shot output, read exactly one static context file after this entrypoint:

- Workflow / command router: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/workflow-lite.md`
- Website/page only: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/website.md`
- Kiwe DSA/AppShell theme only: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/theme.md`
- Website/page + AppShell direction/settings, browser-short version: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md`
- Website/page + AppShell direction/settings, full version: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined.md`
- Revision/audit pass: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md`
- Dynamic WordPress/Bricks binding pass after an approved handoff: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/dynamic-lite.md`

For a fast prompt that asks for both a website/page and a Kiwe AppShell/DSA direction in one pass, read:

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

Copy the full key immediately after creation. Kiwe stores only a hash and shows the full secret once; the table later shows only a prefix/last-four fingerprint for identification.

For public/headless content data, do not scrape the site's frontend. Use the AI-less Site Graph Data API:

```text
GET  /wp-json/dsa/v1/site-graph/data/schema
GET  /wp-json/dsa/v1/site-graph/data?resource=products&taxonomy=product_cat&term=fudge&limit=4
POST /wp-json/dsa/v1/site-graph/data
```

This route is read-only and public-safe. Anonymous calls return public/published posts, pages, products, menus, terms, media, and site identity. Authenticated administrators can receive broader private reads. A `POST` body may include a `queries` object to fetch many page datasets in one GraphQL-like request, or a compact `resources` array such as:

```json
{
  "resources": ["site", "products", "pages", "media"],
  "limits": { "products": 4, "pages": 4, "media": 4 }
}
```

Writes still belong to the controlled staging executor.

API-key clients can also use the same data lane through the AI namespace when a key has `site_graph_data` or `all` scope:

```text
GET  /wp-json/dsa/v1/ai/site-graph-data/schema
GET  /wp-json/dsa/v1/ai/site-graph-data?resource=posts&limit=6
POST /wp-json/dsa/v1/ai/site-graph-data
```

For internal Kiwe AI context and redacted security intelligence:

```text
GET /wp-json/dsa/v1/ai/internal-context
GET|POST /wp-json/dsa/v1/ai/advisor
GET|POST /wp-json/dsa/v1/ai/advisor/enrich
GET /wp-json/dsa/v1/ai/security-brief
GET /wp-json/dsa/v1/ai/companion/status
GET|POST /wp-json/dsa/v1/ai/companion/context
POST /wp-json/dsa/v1/ai/companion/ask
POST /wp-json/dsa/v1/ai/companion/review-output
GET|POST /wp-json/dsa/v1/ai/audit-companion/context
POST /wp-json/dsa/v1/ai/audit-companion/review
GET /wp-json/dsa/v1/ai/companion/memory
GET  /wp-json/dsa/v1/ai/studio/status
POST /wp-json/dsa/v1/ai/studio/start
POST /wp-json/dsa/v1/ai/studio/draft
POST /wp-json/dsa/v1/ai/studio/review
GET|POST /wp-json/dsa/v1/ai/bricks/context
POST /wp-json/dsa/v1/ai/bricks/plan
```

`/ai/internal-context` returns the safe fused packet for Kiwe internal AI: Site Graph summary/hash, Site Graph Data schema, WordPress 7/Abilities signals, capability map, operating boundaries, and a SecureTrack status lane. SecureTrack brief details are off unless `Kiwe > AI` enables redacted SecureTrack sharing and the key has `all`, `security_brief`, or `companion_securetrack` scope. `/ai/advisor` runs the deterministic read-only advisor over that context and returns findings, recommendations, and safe next actions without calling a model or mutating the site. `/ai/advisor/enrich` returns the model-optional enrichment envelope: deterministic fallback summary, priority ordering, and the bounded model payload/schema a future WordPress AI Client adapter may use. It does not call a model in the current adapter. `/ai/security-brief` is redacted and separately gated: no raw IPs, usernames, secrets, full URLs, request payloads, or visitor trails.

The same advisor/enrichment seam is visible to administrators at `Kiwe > AI` as the Kiwe Advisor panel. It is server-rendered and read-only; refreshing the panel recomputes findings and the deterministic enrichment summary from current context but does not execute staging, call a model, save Bricks, mutate WooCommerce, run checkout/cart/auth, or change security enforcement.

Kiwe Companion AI is the optional site-aware context broker for external AIs creating website/page, DSA/AppShell theme, combined, dynamic binding, audit, staging, or security-support outputs. Enable it in `Kiwe > AI`, then issue a revocable key with `companion` scope. The Companion returns compact context cards, route hints, validation diffs, rule IDs, and safe next-action plans rather than dumping the whole plugin or raw security logs. For revisions, browser AI should submit the actual file map to `/ai/audit-companion/review` first, fix every `mustFix` item, then run its own explanation/self-audit. This saves tokens because the deterministic Audit Companion identifies concrete contract failures without calling a model. Its local memory stores privacy-safe fingerprints, structured pass/fail finding codes, and counts only, never secrets, raw visitor trails, raw SecureTrack events, customer data, full handoff files, or unredacted transcripts. SecureTrack AI exposure is a toggle under `Kiwe > AI`: redacted SecureTrack briefs use Companion consent/scopes, and SecureTrack Site Brain cloud review syncs from the shared Native AI provider/key when that provider is supported. There is no separate SecureTrack API-key field in Kiwe AI. `Kiwe > Secure` remains focused on human security controls and enforcement.

Kiwe Studio AI is the higher-level companion workflow. Enable it in `Kiwe > AI` and choose one operating mode: `native` for bounded native drafting through the configured provider/API key, `browser_companion` for browser AI plus token-saving Studio packet and Companion review, or `browser_only` when the user wants public toolkit prompts with no internal AI support. Use `/wp-json/dsa/v1/ai/studio/start` first for a token-saving Studio packet, `/wp-json/dsa/v1/ai/studio/draft` only when native drafting is enabled and the Kiwe AI key has `native_ai` scope, and `/wp-json/dsa/v1/ai/studio/review` after v1 output. A normal `studio_ai` key can obtain packets and deterministic reviews; add `native_ai` only when the key may spend provider tokens. Studio does not save Bricks, publish WordPress content, mutate WooCommerce, run cart/checkout/auth, or change SecureTrack enforcement.

Bricks AI Intelligence is the Bricks-native map for both browser AI and Kiwe Studio AI. External tool clients can use a key with `bricks_ai`, `studio_ai`, or `all` scope to call `/wp-json/dsa/v1/ai/bricks/context` before emitting Bricks JSON or dynamic binding plans, and `/wp-json/dsa/v1/ai/bricks/plan` for a compact planning packet. It reports available Bricks elements, compact element controls, query loops, dynamic tags, conditions, interactions, Seam headless rules, and Kiwe launcher/runtime boundaries. It is read-only. It does not paste content, save Bricks, publish pages, or create Woo/cart/auth behavior.

When working inside the Bricks front-end editor, admins can enable the Kiwe Studio companion at `Kiwe > AI`. The editor panel uses WordPress nonce-auth routes (`/wp-json/dsa/v1/bricks/studio/context`, `/start`, `/draft`) to fetch the same Bricks + Seam context, plan a page/section, or call native AI when explicitly allowed. The panel is a planning/copilot surface, not a direct mutation surface; staging saves still go through the controlled executor.

For staging proof after uploading the MU folder, use the latest `wp-content/mu-plugins/dsa/site-graph-system/release-proof-*.md` file. Version `0.6.14` records the Studio AI operating-mode routes, native-provider boundary, Bricks AI intelligence routes, Bricks editor companion toggle, SecureTrack shared AI settings boundary, API proof routes, WordPress 7 ability checks, dynamic handoff checks, browser smoke checks, and mutation boundaries for the Site Graph + internal AI phase.

Theme installers can use the same key to review, install, and activate Kiwe DSA theme packages:

```text
GET  /wp-json/dsa/v1/ai/themes
POST /wp-json/dsa/v1/ai/themes/install
POST /wp-json/dsa/v1/ai/themes/{themeId}/activate
```

A Kiwe theme package is one JSON file with root `schema: "kiwe.theme-package.v1"`, root `theme`, root `settings`, and root `css`. The `settings` preset is limited to safe theme-owned subsets (`style`, `dock`, `dsa_theme`, `visual_effects`, `tokens`, and `screens`) and appears in WordPress under `Kiwe > Theme > Installed themes`. Do not output or ask users to import a loose settings file for DSA themes.

Standalone website/page work may also ship a Kiwe Framework profile when it changes the shared design-token system without installing a DSA theme. A Framework profile uses `schema: "kiwe.framework-profile.v1"` and contains `settings.tokens` only: `tokens.enabled`, `tokens.profile_label`, official Kiwe universal `tokens.overrides`, and `tokens.bricks_theme_style`. Admins import/export this under `Kiwe > Framework`; AI staging clients may apply it with `kiwe.framework-profile.apply`, then separately push it to Bricks with `kiwe.framework.push-bricks`.

Staging-aware clients can inspect the target site and run the first controlled staging executor:

```text
GET  /wp-json/dsa/v1/ai/site-inspection?sampleLimit=12
POST /wp-json/dsa/v1/ai/staging/execute
POST /wp-json/dsa/v1/ai/stages/{stageId}/execute-staging
```

`/ai/site-inspection` is read-only and returns installed plugin inventory, active plugin status, safe Bricks option summaries, Bricks templates, pages/posts, custom post types, custom taxonomies, safe observed custom-field keys, and staging detection. It redacts secrets and does not expose raw Bricks page meta values.

`/ai/staging/execute` is intentionally narrow. It requires `confirmControlledStagingExecution: true` and `stagingSiteConfirmed: true`, refuses production-looking hosts unless explicitly overridden by the human, and supports only staging-safe operations such as:

- `wordpress.page.upsert`
- `wordpress.post.upsert`
- `bricks.template.create`
- `bricks.template.upsert`
- `bricks.settings.patch`
- `kiwe.framework-profile.apply`
- `kiwe.framework.push-bricks`
- `kiwe.theme-package.install-activate`
- `woocommerce.mutate`
- `woocommerce.product.upsert`
- `woocommerce.order.upsert`
- `woocommerce.settings.patch`
- `cart.run`
- `checkout.run`
- `auth.run`
- `bricks.raw-meta-write`

Page/post/template operations may include `html`, `bricksPasteHtml`, and optional `css`. The executor stores sanitized staging content and preserves safe preview CSS while refusing script-like payloads. `kiwe.framework-profile.apply` applies a sanitized Framework token profile to Kiwe settings only; `kiwe.framework.push-bricks` pushes the current Kiwe Framework design-token profile into Bricks as additive `kiwe-*` variables, Kiwe Universal palette, neutral Seam Class Vocabulary, and one safe global theme style while preserving non-Kiwe Bricks data. `bricks.settings.patch` is limited to known Bricks settings/options, scalar or simple nested payloads, safe path keys, and an internal patch hash log. It exists for staging checks such as Bricks import/converter switches or global setting probes; do not use it as a raw Bricks database writer.

WooCommerce, cart, checkout, auth, and raw Bricks operations require extra explicit flags:

- WooCommerce product/order/settings mutation: `confirmWooCommerceMutation: true`
- Cart/checkout/auth harnesses: `confirmRuntimeExecution: true`
- Auth test-user create/delete: `confirmAuthRuntime: true`
- Raw Bricks `_bricks*` meta writes: `confirmRawBricksJsonWrite: true`

The executor can create/update WooCommerce staging products, create/update staging orders, patch a controlled allow-list of WooCommerce settings, run server-side cart harness actions, validate checkout fields or create pending staging orders, create/delete Kiwe-marked test users, and write allowed Bricks meta keys with backup metadata. Bricks-ready HTML is still the preferred first path; raw `_bricks` JSON writes are for controlled staging adapter tests only.

For custom WordPress structures, use `/ai/site-graph` first. The graph includes `customContent.postTypes`, `customContent.taxonomies`, and `customContent.customFields` so AI can bind to real Pods/ACF/native custom content without guessing slugs or field handles. Field values are not exposed; use the keys/types/occurrence signals for planning only.

The legacy same-site admin REST path still exists for logged-in WordPress admin contexts at `GET /wp-json/dsa/v1/site-graph?sampleLimit=8`, but external AI tools should use `/wp-json/dsa/v1/ai/*` with a Kiwe AI key.

On WordPress 7+ with Abilities API available, Kiwe may also expose:

```text
dsa/get-site-graph
dsa/get-site-graph-data-schema
dsa/query-site-graph-data
dsa/get-securetrack-brief
dsa/get-internal-ai-context
dsa/run-internal-ai-advisor
dsa/enrich-internal-ai-advisor
dsa/get-companion-context
dsa/ask-companion
dsa/review-ai-output
dsa/start-studio-project
dsa/review-studio-output
dsa/get-bricks-ai-context
dsa/plan-bricks-ai-page
dsa/validate-bindings
dsa/prepare-apply-plan
dsa/stage-apply-plan
```

## Preferred tool call

For capable MCP/tool clients, use the command router first when the user gives a phase command:

```json
{
  "tool": "kiwe_route_command",
  "arguments": {
    "command": "/rebuild /seamframework",
    "brief": "Paste the human's short phase request here.",
    "artifactSummary": "Briefly summarize the prior phase artifact when available."
  }
}
```

If the user gives a broad one-shot request instead of a phased command, use:

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
node kiwe-ai-toolkit/tools/validate-framework-profile.cjs ./path/to/handoff --optional
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

On WordPress 7+ / MCP Adapter capable sites, Kiwe also exposes safe connector abilities for the same early chain: `dsa/get-site-graph`, `dsa/get-site-graph-data-schema`, `dsa/query-site-graph-data`, `dsa/get-securetrack-brief`, `dsa/get-internal-ai-context`, `dsa/run-internal-ai-advisor`, `dsa/enrich-internal-ai-advisor`, `dsa/get-companion-context`, `dsa/ask-companion`, `dsa/review-ai-output`, `dsa/start-studio-project`, `dsa/review-studio-output`, `dsa/get-bricks-ai-context`, `dsa/plan-bricks-ai-page`, `dsa/validate-bindings`, `dsa/prepare-apply-plan`, and `dsa/stage-apply-plan`. These abilities do not save Bricks/page content or mutate security enforcement; `dsa/run-internal-ai-advisor` is deterministic/read-only, `dsa/enrich-internal-ai-advisor` prepares a model-optional read-only summary/envelope, `dsa/start-studio-project` returns a token-saving Studio packet, `dsa/get-bricks-ai-context` and `dsa/plan-bricks-ai-page` return read-only Bricks-native planning packets, and `dsa/stage-apply-plan` writes only a Kiwe internal review queue record.

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
