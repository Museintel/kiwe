# Kiwe dynamic binding context

Use this context only after the website/page and optional Kiwe AppShell theme already pass the normal Kiwe audit.

Goal: perform only the dynamic/data target named by the command, using either real SiteGraph evidence or general verified Bricks context.

Do not read the whole Kiwe repo. Do not search GitHub. Do not guess the target site's categories, pages, products, post types, dynamic tags, or Bricks query-loop object types.

Canonical context sources: `/usesitegraph` and `/usebrickscontext`.

Legacy alias: `/dynamic /sitegraph`.

## Contextual command grammar

### `/usesitegraph`

Select target-site truth as the evidence source. It does not authorize every possible change. Require `/for` plus one or more explicit targets. If no target is supplied, ask one short command question and stop.

If no target-site truth is available, stop and ask for one of:

- `KIWE_REST_BASE` plus `KIWE_AI_KEY`;
- exported `kiwe.site-graph.v1` JSON;
- AI-less public/read-only Site Graph Data schema/data routes.

Do not browse or scrape the public frontend as a fallback.

### `/usesitegraph /for /previewdata`

Legacy aliases: `/usesitegraph /replacepreviewdata`, `/usesitegraph /replacepreview`.

Replace preview-only sample content with real SiteGraph Data samples and change nothing else. This target does not add query loops, dynamic tags, launchers, conditions, or `kiwe-bindings.json` unless their explicit target tokens are also present.

Sampled preview cards/images/prices must remain marked preview-only. Existing production dynamic intent remains unchanged.

### `/usesitegraph /for /siteidentity`

Legacy alias: `/usesitegraph /websitename`.

Use Site Graph identity/menu data for site name, logo, brand identity, menu labels, public pages, and broad tone.

Do not infer the brand from the public homepage or external search.

### `/usesitegraph /for /bricksbindings`

Create the complete target-grounded binding plan from the current raw artifact. Use `/dynamictags`, `/queryloops`, or `/kiwelaunchers` instead of `/bricksbindings` when only one binding family should change. Do not replace preview data or site identity unless those targets are also present.

### `/usebrickscontext /for /bricksbindings`

Use verified Bricks/Kiwe capabilities without SiteGraph. This lane may map standard dynamic tags, generic post/product query settings, and canonical Kiwe launcher attributes when source intent is clear. It must not invent target-specific post types, taxonomies, terms, IDs, custom fields, media, products, posts, or site identity; place those unknowns in `requiresHumanReview`.

The same source may be narrowed with `/dynamictags`, `/queryloops`, or `/kiwelaunchers`.

### `/usesitegraph ... /nonai`

Force the AI-less/read-only lane:

- use exported `kiwe.site-graph.v1` JSON, or;
- use `/wp-json/dsa/v1/site-graph/data/schema` and `/wp-json/dsa/v1/site-graph/data`.

Do not call Companion, native AI, Advisor, Studio, or other model-backed `/ai/*` routes when `/nonai` is present, even if `/usecompanion` is also present.

`/nonai` is invalid with `/usebrickscontext`; that context contains deterministic builder capabilities and does not use a SiteGraph AI/data route.

## Inputs you should ask for

- The current Kiwe handoff folder or files.
- For `/usesitegraph`: the target site's `kiwe.site-graph.v1` JSON or authorized API route.
- For `/previewdata` or `/siteidentity`: target data responses containing the requested real records.
- For `/usebrickscontext`: verified Bricks/Kiwe capability context; no SiteGraph is required.

The slash targets are the request. Do not require the human to repeat their meaning as a long prose prompt. Ask only for a missing artifact, missing evidence source, or an ambiguous target selector.

Optional entity scopes are `/products`, `/posts`, `/pages`, `/media`, and `/menus`. Optional field scopes are `/titles`, `/images`, `/prices`, `/links`, `/excerpts`, and `/metadata`. When scopes are supplied, change only the intersection they name. Example: `/usesitegraph /for /previewdata /products /titles /images /nonai` replaces product names and product images only. When no scope is supplied, process every eligible region for the selected target. Never treat a scope token as permission to alter another target.

If Kiwe is installed on the target site, the admin can download SiteGraph from `Kiwe > SiteGraph`.

External tool clients can use a revocable key created in `Kiwe > AI > API access keys` with `Authorization: Bearer kiwe_ai_...` or `X-Kiwe-AI-Key` against `/wp-json/dsa/v1/ai/site-graph`, `/wp-json/dsa/v1/ai/bricks/context`, `/wp-json/dsa/v1/ai/bricks/plan`, `/wp-json/dsa/v1/ai/validate-bindings`, `/wp-json/dsa/v1/ai/validate-bricks-conversion`, `/wp-json/dsa/v1/ai/prepare-apply-plan`, `/wp-json/dsa/v1/ai/stage-apply-plan`, `/wp-json/dsa/v1/ai/stages/{stageId}/...`, and `/wp-json/dsa/v1/ai/themes`.

For ordinary external content, conversion and validation work, prefer the vendor-neutral connection package from `Kiwe > SiteGraph`. It uses schema `kiwe.external-client-connection.v1`, points to `kiwe.external-client-manifest.v1`, the reduced `/wp-json/dsa/v1/ai/openapi.task.json`, and the secret-safe `/wp-json/dsa/v1/ai/client-adapters` catalog, and contains a one-time-visible `kiwe_task_...` bearer capsule. Configure the bearer value in the client's secret/action/tool/MCP/IDE layer; never paste it into model conversation context. Capsules are short-lived, hash-only at rest, public-data-only, field/resource/row/request-budgeted, and exclude staging, runtime, theme, publishing and mutation scopes. Claude, Cursor and other MCP clients can run `kiwe-ai-toolkit/mcp/sitegraph-client.js`; it refuses permanent keys, plaintext remote HTTP and redirects. Clients that cannot store authenticated secrets must use exported SiteGraph JSON or the public SiteGraph Data endpoints instead.

For headless/content reads, prefer the AI-less Site Graph Data API instead of scraping a public website:

```text
GET  /wp-json/dsa/v1/site-graph/data/schema
GET  /wp-json/dsa/v1/site-graph/data?resource=products&taxonomy=product_cat&term=fudge&limit=4
POST /wp-json/dsa/v1/site-graph/data
```

Anonymous/public calls return only public/published objects. Authenticated administrators can receive broader private read data. `POST` may use the strict `queries` object or a compact `resources` shorthand:

```json
{
  "resources": ["site", "products", "pages", "media"],
  "limits": { "products": 4, "pages": 4, "media": 4 }
}
```

Site Graph Data is read-only; it does not install themes, save Bricks, publish content, mutate WooCommerce, run cart/checkout/auth, or bypass the Controlled Executor.

API-key clients can use the matching AI namespace routes when a key has `site_graph_data`, `security_brief`, `internal_ai`, or `all` scope:

```text
GET  /wp-json/dsa/v1/ai/site-graph-data/schema
GET|POST /wp-json/dsa/v1/ai/site-graph-data
GET  /wp-json/dsa/v1/ai/security-brief
GET  /wp-json/dsa/v1/ai/internal-context
GET|POST /wp-json/dsa/v1/ai/advisor
GET|POST /wp-json/dsa/v1/ai/advisor/enrich
GET|POST /wp-json/dsa/v1/ai/companion/context
POST /wp-json/dsa/v1/ai/companion/ask
POST /wp-json/dsa/v1/ai/companion/review-output
POST /wp-json/dsa/v1/ai/audit-companion/review
GET  /wp-json/dsa/v1/ai/studio/status
POST /wp-json/dsa/v1/ai/studio/start
POST /wp-json/dsa/v1/ai/studio/draft
POST /wp-json/dsa/v1/ai/studio/review
GET|POST /wp-json/dsa/v1/ai/bricks/context
POST /wp-json/dsa/v1/ai/bricks/plan
POST /wp-json/dsa/v1/ai/validate-bricks-conversion
```

Use `/ai/internal-context` when a tool needs one safe first-party packet containing Site Graph summary/hash, Site Graph Data schema, WordPress 7/Abilities signals, capability map, and operating boundaries. SecureTrack details are a separately gated redacted lane controlled from `Kiwe > AI` and by API key scope. Use `/ai/advisor` when a tool needs deterministic read-only findings, recommendations, and safe next actions before any model-enriched internal AI or staging plan. Use `/ai/advisor/enrich` when a tool needs the deterministic summary, priority order, and bounded model envelope for future WordPress AI Client enrichment; it does not grant mutation authority. Use `/ai/companion/context`, `/ai/companion/ask`, and `/ai/companion/review-output` when an external AI needs compact Kiwe context cards or a deterministic handoff review without reading the whole plugin. Prefer `/ai/audit-companion/review` for revision loops because it returns compact `mustFix`, `shouldFix`, and `passed` maps from actual file contents before the model spends another broad self-audit pass. Use `/ai/studio/start` when a target-site key is available and you need a token-saving Studio packet for website/theme/combined/dynamic/audit/staging work. Use `/ai/bricks/context` and `/ai/bricks/plan` when a standalone browser AI needs Bricks-native elements, element controls, query loops, dynamic tags, conditions, interactions, Seam rules, and `/convert /bricks` conversion-package expectations without reading Bricks or Kiwe source. Studio mode may be `native`, `browser_companion`, or `browser_only`; `/ai/studio/draft` spends native provider tokens only if Kiwe > AI allows it and the key has `native_ai` scope. Use `/ai/security-brief` for redacted security posture only; it does not expose raw IPs, usernames, secrets, full URLs, request payloads, or visitor trails.

Kiwe theme package install/activation is allowed through `/wp-json/dsa/v1/ai/themes/install` and `/wp-json/dsa/v1/ai/themes/{themeId}/activate` when the key has theme scope. A theme package is one JSON file containing `schema: "kiwe.theme-package.v1"`, `theme`, `settings`, and `css`; do not produce a loose settings import file for DSA themes. If a website-only or combined handoff changes the shared Seam/Kiwe design-token system outside a DSA theme package, use a Framework profile with `schema: "kiwe.framework-profile.v1"` and `settings.tokens` only.

For target-site inspection, use `/wp-json/dsa/v1/ai/site-inspection`. It returns active/installed plugin inventory, safe Bricks settings summaries, Bricks templates, pages/posts, and staging detection without raw Bricks meta or secrets.

The Site Graph and Site Inspection expose custom WordPress structures. Use `customContent.postTypes`, `customContent.taxonomies`, and `customContent.customFields` to discover real custom post types, custom taxonomy terms, registered meta, and observed safe field keys. Values are redacted and secret-like keys are excluded; never invent custom field handles when this data is available.

For staging-only execution, use `/wp-json/dsa/v1/ai/staging/execute` or `/wp-json/dsa/v1/ai/stages/{stageId}/execute-staging`. The body must include `confirmControlledStagingExecution: true`, `stagingSiteConfirmed: true`, and an `operations` array. Allowed operation types are `wordpress.page.upsert`, `wordpress.post.upsert`, `bricks.page.from-html`, `bricks.template.create`, `bricks.template.upsert`, `bricks.template.from-html`, `bricks.settings.patch`, `kiwe.framework-profile.apply`, `kiwe.framework.push-bricks`, `kiwe.theme-package.install-activate`, `woocommerce.mutate`, `woocommerce.product.upsert`, `woocommerce.order.upsert`, `woocommerce.settings.patch`, `cart.run`, `checkout.run`, `auth.run`, and `bricks.raw-meta-write`. Page/post/template operations may include `html`, `bricksPasteHtml`, and optional safe preview `css`. Use `bricks.page.from-html` or `bricks.template.from-html` when a website/page handoff should become real Bricks element JSON on staging; Kiwe uses Bricks native conversion when available and otherwise uses its fallback converter to preserve Seam classes, IDs, links, data attributes, ARIA, and CSS page settings. Use `kiwe.framework-profile.apply` to apply a standalone Framework token profile to Kiwe settings; use `kiwe.framework.push-bricks` after applying a theme/token profile when Bricks should receive the current Kiwe Framework variables, palette, neutral Seam classes, and safe global theme style. Bricks settings patches are limited to known Bricks settings/options and safe patch paths. WooCommerce/order/settings operations require `confirmWooCommerceMutation: true`; cart/checkout/auth harness operations require `confirmRuntimeExecution: true`; auth test-user create/delete also requires `confirmAuthRuntime: true`; raw Bricks JSON writes and Kiwe HTML/CSS-to-Bricks staging conversion require `confirmRawBricksJsonWrite: true`.

The controlled executor may create/update staging WordPress content, convert clean HTML/CSS handoffs into Bricks page/template JSON, write Bricks templates/settings probes, install Kiwe theme packages, create WooCommerce staging products/orders/settings, run server-side cart/checkout harness state, create/delete Kiwe-marked auth test users, and write allowed Bricks `_bricks*` meta with rollback backup metadata. It must not process payment, impersonate a shopper silently, expose secrets, or perform unbounded raw database writes.

## What the Site Graph gives you

The admin Site Graph is read-only and can include:

- public WordPress post types, sample pages/posts/products, and taxonomies;
- real taxonomy term IDs and slugs;
- WooCommerce page assignments and product categories/tags;
- Bricks presence/version, query loop types, dynamic data tags, and Kiwe dynamic tags;
- Bricks AI Intelligence, when available, can additionally return compact element schemas, element controls, query-loop options, dynamic tags, display conditions, interactions, and safe Seam/Kiwe launcher boundaries for Bricks-native planning;
- Kiwe AppShell modules, dock/search settings, and launcher capabilities;
- authority guardrails.

The AI-less Site Graph Data API is the public/headless read lane. It can return normalized:

- `site` identity and logo;
- `menus` and menu items;
- `posts`, `pages`, custom public post types, and `products`;
- product/category/tag rails by real taxonomy/term slug or ID;
- `terms` for public taxonomies;
- `media`/image nodes with alt text, dimensions, and common sizes;
- batch responses through a `queries` object, or a compact `resources` shorthand, so one request can feed a full page.

Example:

```json
{
  "queries": {
    "site": {"resource": "site"},
    "mainMenu": {"resource": "menus", "location": "primary"},
    "fudgeRail": {"resource": "products", "category": "fudge", "limit": 4},
    "blogRail": {"resource": "posts", "limit": 6}
  }
}
```

Compact equivalent when you only need default resource samples:

```json
{
  "resources": ["site", "products", "pages", "media"],
  "limits": { "products": 4, "pages": 4, "media": 4 }
}
```

If a needed item is not in the Site Graph, list it under `requiresHumanReview`. Do not fabricate it.

## Required output change

Keep the handoff's existing mode shape. Add this optional folder:

```text
bricks-bindings/
  kiwe-bindings.json
```

The normal `website/bricks-paste.html` remains the page preview and Bricks paste/import artifact. The binding folder explains how static prototype regions map to Bricks query loops, dynamic tags, Kiwe launchers, and review requirements.

Do not emit `BINDING-NOTES.md`, README files, reports, or extra docs unless the command also includes `/document` or the human explicitly asks for documentation.

Validate the binding plan when a CLI or MCP tool is available:

```bash
node kiwe-ai-toolkit/tools/validate-bindings.cjs <handoff-or-bindings-dir-or-json> --site-graph <site-graph.json>
```

MCP clients should call `kiwe_validate_bindings` with `targetDir` and `siteGraphPath`.

After the binding plan passes, use `/convert /bricks` to produce the Bricks-native upload artifact:

```text
bricks-template/
  <page-or-template-name>-template-upload.json
```

For the standalone SEAM Compiler lane, upload the approved raw HTML/CSS/JS project and the same validated `bricks-bindings/kiwe-bindings.json` together. The compiler applies only the plan's declared source selectors before native Bricks conversion, reports unmatched or conflicting bindings, and does not fetch, copy, or mutate target-site content. The binding plan may come from `/usesitegraph /for /bricksbindings` or `/usebrickscontext /for /bricksbindings`; then run `/convert /bricks`. `/convert /usesitegraph` is not a command.

The compiler may also emit `sitegraph-<project>-post-import-verification.json` with schema `kiwe.sitegraph-post-import-verification.v1`. It maps each validated binding to the expected native Bricks element ID and a rendered DOM proof marker, reports unmatched or multi-target selectors, and provides non-applying Bricks template-condition recommendations. It is a read-only verification contract: it must declare `mayMutateWordPress: false` and `mayActivateTemplateConditions: false`. Header/footer, single/archive and route-specific conditions always require target review or route-ID resolution; dedicated WooCommerce runtime template types remain Bricks-managed.

Then validate it:

```bash
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs <handoff-or-conversion-json> --site-graph <site-graph.json>
```

MCP clients should call `kiwe_validate_bricks_conversion`. The conversion package is still non-mutating; staging writes remain controlled executor work.

If the human has WordPress admin access, they can also upload `bricks-bindings/kiwe-bindings.json` at `Kiwe > SiteGraph` for a live non-mutating validation report.

On WordPress 7+ / MCP Adapter capable sites, Kiwe may expose the same early connector path as abilities: `dsa/get-site-graph`, `dsa/get-site-graph-data-schema`, `dsa/query-site-graph-data`, `dsa/get-securetrack-brief`, `dsa/get-internal-ai-context`, `dsa/run-internal-ai-advisor`, `dsa/enrich-internal-ai-advisor`, `dsa/get-companion-context`, `dsa/ask-companion`, `dsa/review-ai-output`, `dsa/start-studio-project`, `dsa/review-studio-output`, `dsa/get-bricks-ai-context`, `dsa/plan-bricks-ai-page`, `dsa/validate-bindings`, `dsa/prepare-apply-plan`, and `dsa/stage-apply-plan`. These are safe connector surfaces. `dsa/run-internal-ai-advisor` is deterministic/read-only. `dsa/enrich-internal-ai-advisor` prepares model-optional summary/enrichment output while preserving advisor truth. `dsa/start-studio-project` returns a token-saving Studio packet. `dsa/get-bricks-ai-context` and `dsa/plan-bricks-ai-page` return read-only Bricks-native planning packets. `dsa/stage-apply-plan` writes only Kiwe internal review metadata and does not save Bricks, WordPress page content, WooCommerce, security enforcement, or publish state.

If no execution is available, do not claim validation ran. Self-check against this context and report that executable validation was not available.

After validation, an apply-path request may prepare a dry-run plan:

```bash
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs <handoff-or-bindings-dir-or-json> --site-graph <site-graph.json>
```

MCP clients should call `kiwe_prepare_apply_plan`.

This produces `kiwe.bricks-apply-plan.v1`. It is not a mutation. It lists the future Bricks apply sequence, preflight checks, operations, and manual-review gates for an admin-approved trusted adapter.

In WordPress admin, the non-mutating apply path continues as a staged safety chain before any future adapter may save: `kiwe.trusted-apply-stage.v1`, `kiwe.trusted-adapter-proof.v1`, `kiwe.guarded-apply-authorization.v1`, `kiwe.pre-execution-gate.v1`, `kiwe.trusted-execution-preview.v1`, `kiwe.final-apply-confirmation.v1`, `kiwe.fresh-sitegraph-revalidation.v1`, `kiwe.rollback-readiness-checkpoint.v1`, `kiwe.target-resolution.v1`, `kiwe.rollback-capture.v1`, `kiwe.rendered-target-inspection.v1`, `kiwe.minimal-adapter-shell.v1`, `kiwe.final-save-approval.v1`, `kiwe.controlled-executor.v1`, `kiwe.bricks-controlled-adapter.v1`, and `kiwe.post-apply-verification.v1`.

The rendered target inspection is a baseline snapshot inspection, not permission to save. Missing selectors on the current target are warnings/manual review for first-import or new-content cases; a future adapter must map them after conversion/import and before any reviewed save.

The minimal adapter shell selects the least-risk future route and allowed operation set. It still does not run Bricks save, WordPress update, publish, WooCommerce mutation, or custom runtime code.

The final save approval requires an explicit human checkbox for the exact minimal shell. It records post-apply audit, browser smoke, and rollback verification obligations, but still does not save Bricks, WordPress, WooCommerce, or publish state.

The controlled executor skeleton defines the future adapter interface only. The Bricks controlled adapter plan maps approved operation IDs to deterministic Bricks/Kiwe adapter instructions. The post-apply verification proof selects one smallest future controlled run and records rollback proof from the captured snapshot. These artifacts are valid only while they still report `adapterCanSaveNow: false`, `actualApplyExecuted`/`actualSaveExecuted: false`, `actualRollbackExecuted: false`, and `mayExecuteMutationNow: false` until a human starts a real staging-site controlled run.

## `kiwe-bindings.json` quick contract

```json
{
  "schema": "kiwe.bricks-bindings.v1",
  "siteGraphSchema": "kiwe.site-graph.v1",
  "target": {
    "builder": "bricks",
    "mode": "binding-plan",
    "applyAuthority": "human-or-kiwe-adapter"
  },
  "queries": [],
  "dynamicFields": [],
  "launchers": [],
  "menuContext": [],
  "assumptions": [],
  "requiresHumanReview": []
}
```

## Query loop rules

- Use Bricks query-loop `objectType` values from the Site Graph or Bricks.
- Product loops normally use `objectType: "post"` and `post_type: ["product"]`.
- Post/news loops normally use `objectType: "post"` and `post_type: ["post"]` or the real CPT from the Site Graph.
- Term/category rails use `objectType: "term"` with a real taxonomy.
- Bricks taxonomy filters use `taxonomy::term_id`, for example `product_cat::123`.
- Use `posts_per_page`, `orderby`, and `order` intentionally.
- Do not write custom SQL/PHP query editor code unless the brief explicitly demands something Bricks cannot express safely.

## Dynamic data rules

- Use only dynamic tags present in the Site Graph or standard Bricks tags verified by Bricks.
- Common Bricks tags include `{post_title}`, `{post_url}`, `{post_excerpt}`, `{post_date}`, `{post_author}`, and `{featured_image}`.
- Kiwe may expose `{kiwe_site_logo}`, `{kiwe_site_logo_inverse}`, store address/location tags, and `{woo_product_weight}`.
- If a product price/tag is not available in the Site Graph, request review instead of inventing a tag.

## Kiwe AppShell launcher rules

- Page/header buttons that open Kiwe surfaces should use canonical Kiwe launchers such as `data-dsa-open-module="cart"`, `profile`, `search`, or `menu`.
- Wishlist/bookmark controls should use live Kiwe Saved attributes such as `data-kiwe-save="wishlist"` or `data-kiwe-save="bookmark"` with Bricks dynamic IDs/titles/URLs where available.
- Browser notification CTAs should use `data-kiwe-notifications` and optional `data-kiwe-notification-status-target`; do not request permission except after the visitor clicks the control.
- Light/dark controls outside the dock should use `data-kiwe-theme-toggle` rather than custom theme-switch JavaScript.
- Do not create a second cart, checkout, saved, auth, search, AI, notification, or AppShell runtime in page JavaScript.
- The AppShell remains Kiwe-owned and the page remains WordPress/Bricks-owned.

## Menu context rules

- Prefer visible semantic page sections and headings already in the page.
- Seam attributes describe meaning; they are not hidden duplicate navigation data.
- If a desired menu label does not exist in the page content/section semantics, report it rather than inventing hidden anchors.

## Optional `/document` notes file

Create `BINDING-NOTES.md` only when `/document` was requested. When requested, it should include:

- what changed from placeholders to dynamic bindings;
- which Site Graph entries were used;
- any assumptions;
- items requiring human/site-owner review;
- which parts are still preview-only;
- whether applying the plan requires verified Bricks converter/import abilities, manual Bricks builder work, or a future Kiwe adapter.

Do not claim that Bricks, WordPress, WooCommerce, or Kiwe were mutated unless an actual trusted apply tool ran.

Direct AI intent endpoints for Bricks save, WordPress publish, WooCommerce mutation, cart, checkout, and auth may exist, but they are locked discovery surfaces. Treat locked responses as proof of the boundary, not as failed authority.
