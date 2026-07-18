# Kiwe dynamic binding context

Use this context only after the website/page and optional Kiwe AppShell theme already pass the normal Kiwe audit.

Goal: revise the handoff into a WordPress/Bricks-aware version by using a real `kiwe.site-graph.v1` JSON snapshot from the target site.

Do not read the whole Kiwe repo. Do not search GitHub. Do not guess the target site's categories, pages, products, post types, dynamic tags, or Bricks query-loop object types.

## Inputs you should ask for

- The current Kiwe handoff folder or files.
- The target site's `kiwe.site-graph.v1` JSON.
- The plain-language dynamic request, for example: "turn placeholder product rails into Bricks query loops and dynamic product cards."

If Kiwe is installed on the target site, the admin can download the Site Graph from `Kiwe > AI > AI connector and Site Graph`.

External tool clients can use a revocable key created in `Kiwe > AI > API access keys` with `Authorization: Bearer kiwe_ai_...` or `X-Kiwe-AI-Key` against `/wp-json/dsa/v1/ai/site-graph`, `/wp-json/dsa/v1/ai/validate-bindings`, `/wp-json/dsa/v1/ai/prepare-apply-plan`, `/wp-json/dsa/v1/ai/stage-apply-plan`, and `/wp-json/dsa/v1/ai/stages/{stageId}/...`.

## What the Site Graph gives you

The Site Graph is admin-only and read-only. It can include:

- public WordPress post types, sample pages/posts/products, and taxonomies;
- real taxonomy term IDs and slugs;
- WooCommerce page assignments and product categories/tags;
- Bricks presence/version, query loop types, dynamic data tags, and Kiwe dynamic tags;
- Kiwe AppShell modules, dock/search settings, and launcher capabilities;
- authority guardrails.

If a needed item is not in the Site Graph, list it under `requiresHumanReview`. Do not fabricate it.

## Required output change

Keep the handoff's existing mode shape. Add this optional folder:

```text
bricks-bindings/
  kiwe-bindings.json
  BINDING-NOTES.md
```

The normal `website/bricks-paste.html` remains the page preview and Bricks paste/import artifact. The binding folder explains how static prototype regions map to Bricks query loops, dynamic tags, Kiwe launchers, and review requirements.

Validate the binding plan when a CLI or MCP tool is available:

```bash
node kiwe-ai-toolkit/tools/validate-bindings.cjs <handoff-or-bindings-dir-or-json> --site-graph <site-graph.json>
```

MCP clients should call `kiwe_validate_bindings` with `targetDir` and `siteGraphPath`.

If the human has WordPress admin access, they can also upload `bricks-bindings/kiwe-bindings.json` at `Kiwe > AI > AI connector and Site Graph` for a live non-mutating validation report.

On WordPress 7+ / MCP Adapter capable sites, Kiwe may expose the same early connector path as abilities: `dsa/get-site-graph`, `dsa/validate-bindings`, `dsa/prepare-apply-plan`, and `dsa/stage-apply-plan`. These are safe connector surfaces. `dsa/stage-apply-plan` writes only Kiwe internal review metadata and does not save Bricks, WordPress page content, WooCommerce, or publish state.

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
- Do not create a second cart, checkout, saved, auth, search, AI, notification, or AppShell runtime in page JavaScript.
- The AppShell remains Kiwe-owned and the page remains WordPress/Bricks-owned.

## Menu context rules

- Prefer visible semantic page sections and headings already in the page.
- Seam attributes describe meaning; they are not hidden duplicate navigation data.
- If a desired menu label does not exist in the page content/section semantics, report it rather than inventing hidden anchors.

## Notes file

`BINDING-NOTES.md` must include:

- what changed from placeholders to dynamic bindings;
- which Site Graph entries were used;
- any assumptions;
- items requiring human/site-owner review;
- which parts are still preview-only;
- whether applying the plan requires Bricks 2.4 abilities, manual Bricks builder work, or a future Kiwe adapter.

Do not claim that Bricks, WordPress, WooCommerce, or Kiwe were mutated unless an actual trusted apply tool ran.
