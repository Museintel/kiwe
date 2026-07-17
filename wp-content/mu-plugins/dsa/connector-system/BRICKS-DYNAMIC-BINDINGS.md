# Bricks dynamic binding pass

This document defines Kiwe's v5-style pass: turning a successful static Seam/DSA handoff into a WordPress/Bricks-aware handoff.

## Inputs

A dynamic binding pass needs only:

1. the current Kiwe handoff;
2. the Kiwe Toolkit dynamic context;
3. a `kiwe.site-graph.v1` JSON snapshot from the target site.

Do not read the full plugin codebase. Do not guess categories, pages, products, Bricks tags, or query-loop object types when the Site Graph provides them.

Admins can download the target Site Graph from `Kiwe > Framework > AI connector and Site Graph`.

## Output expectation

The AI should keep the normal Kiwe handoff shape and add one optional folder:

```text
bricks-bindings/
  kiwe-bindings.json
  BINDING-NOTES.md
```

The existing `website/bricks-paste.html` remains the paste/import artifact and browser preview for the page. The binding plan describes how the static prototype maps to real Bricks data.

## Binding manifest shape

```json
{
  "schema": "kiwe.bricks-bindings.v1",
  "siteGraphSchema": "kiwe.site-graph.v1",
  "target": {
    "builder": "bricks",
    "mode": "binding-plan",
    "applyAuthority": "human-or-kiwe-adapter"
  },
  "queries": [
    {
      "id": "fudge-products",
      "label": "Fudge products",
      "selector": "[data-kiwe-binding='fudge-products']",
      "bricks": {
        "objectType": "post",
        "post_type": ["product"],
        "posts_per_page": 4,
        "orderby": "date",
        "order": "DESC",
        "tax_query": ["product_cat::123"]
      },
      "bindings": {
        "title": "{post_title}",
        "url": "{post_url}",
        "image": "{featured_image}",
        "price": "{woo_product_price}",
        "weight": "{woo_product_weight}"
      }
    }
  ],
  "dynamicFields": [],
  "launchers": [
    {
      "selector": "[data-dsa-open-module='cart']",
      "attribute": "data-dsa-open-module",
      "value": "cart"
    }
  ],
  "menuContext": [],
  "assumptions": [],
  "requiresHumanReview": []
}
```

The formal schema lives at:

```text
kiwe-ai-toolkit/schemas/bricks-bindings.schema.json
```

Validate a binding plan with:

```bash
node kiwe-ai-toolkit/tools/validate-bindings.cjs ./path/to/handoff --site-graph ./site-graph.json
```

MCP clients can call `kiwe_validate_bindings` with `targetDir` and optional `siteGraphPath`.

WordPress admins can validate the same `kiwe-bindings.json` against the current live Site Graph from `Kiwe > Framework > AI connector and Site Graph`. This admin intake does not write Bricks data. When validation completes, the same screen also shows a dry-run apply-plan preview, a JSON download, and a staging action so admins can inspect, share, or pin the planned query-loop, dynamic-field, launcher, menu-context, and manual-review operations before any future adapter path is considered.

Prepare a dry-run apply plan with:

```bash
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs ./path/to/handoff --site-graph ./site-graph.json
```

MCP clients can call `kiwe_prepare_apply_plan`.

The apply plan uses `kiwe.bricks-apply-plan.v1` and remains non-mutating. It is the bridge contract for a future admin-approved Bricks adapter.

When staged in WordPress admin, Kiwe wraps the reviewed dry-run plan in `kiwe.trusted-apply-stage.v1`. That stage record is a capped Kiwe review queue item with a plan hash and gates; it still does not write Bricks page data.

Admins can then run a trusted-adapter proof. The proof attaches `kiwe.trusted-adapter-proof.v1` metadata to the stage by checking the current live Site Graph and mapping each operation for future adapter review. This remains non-mutating.

After proof passes, admins can attach `kiwe.guarded-apply-authorization.v1`. That authorization is a future-only review token, not an apply action.

After authorization, admins can build `kiwe.pre-execution-gate.v1`. The gate is still non-mutating; it confirms the staged plan, proof, and authorization line up and records the rollback, rendered-preview, final-confirmation, smallest-mutation, post-apply audit, and browser-smoke requirements a future adapter must satisfy.

After the gate passes, admins can build `kiwe.trusted-execution-preview.v1`. The preview is still non-mutating; it rehearses operation-level preview, rollback, final-confirmation, and post-apply audit requirements before any trusted adapter is allowed to save.

After preview, admins can attach `kiwe.final-apply-confirmation.v1`. The confirmation requires an explicit checkbox for the exact execution preview and still does not save Bricks or WordPress content.

After final confirmation, admins can run `kiwe.fresh-sitegraph-revalidation.v1`. The revalidation checks the current live Site Graph for drift in Bricks capability, post types, taxonomy terms, and dynamic tags before any rollback or mutation adapter work begins.

After fresh revalidation, admins can build `kiwe.rollback-readiness-checkpoint.v1`. This locks the approved artifact hashes and required rollback captures but does not yet create a real WordPress/Bricks revision.

After rollback readiness, admins can attach `kiwe.target-resolution.v1` by entering the exact post/page/template ID that the future adapter is allowed to touch. Target resolution is still non-mutating and prevents ambiguous saves.

After target resolution, admins can attach `kiwe.rollback-capture.v1`. This captures the locked target's current WordPress fields and relevant Bricks/Kiwe/DSA meta into Kiwe's internal staging record. It is restore-prep only: it does not save Bricks, edit WordPress content, publish, or create a native WordPress revision.

After rollback capture, admins can attach `kiwe.rendered-target-inspection.v1`. This inspects the protected baseline snapshot for post content, Bricks meta shape, estimated nodes, and operation selector coverage. Missing selectors are warnings for first-import/new-content cases; they are not permission for an AI or adapter to guess a save.

After rendered target inspection, admins can attach `kiwe.minimal-adapter-shell.v1`. This selects the least-risk future route and allowed operation set for the exact target. It is still non-mutating and does not run Bricks save, WordPress update, publish, WooCommerce mutation, or custom runtime code.

## Rules

- Use real `taxonomy::term_id` values from the Site Graph for Bricks taxonomy filters.
- Use Bricks query loop `objectType` values from the Site Graph or Bricks `list-query-loop-types`.
- Use dynamic data tags from the Site Graph or Bricks `list-dynamic-data-tags`.
- Use Kiwe tags such as `{kiwe_site_logo}` and `{woo_product_weight}` only when the Site Graph says Kiwe dynamic tags are enabled/available.
- If a required term, page, post type, product category, or dynamic tag is absent, put it in `requiresHumanReview`; do not fabricate it.
- Do not create custom cart, checkout, payment, login, save, search, or Woo mutation JavaScript.
- Do not make the page own DSA/AppShell geometry or runtime behavior.
- Page/header launchers should use canonical Kiwe attributes such as `data-dsa-open-module`.
- Menu context should prefer semantic page sections and headings already present in the page; do not create duplicate hidden navigation data just for Kiwe.

## Apply strategy

Batch 1 is read-only and produces the plan.

Later batches may add a trusted apply adapter that:

1. calls Bricks 2.4 abilities such as HTML/CSS conversion, dynamic-data preview, global-query creation, and page element mutation;
2. validates the rendered output before save;
3. captures a Bricks revision before changes;
4. refuses destructive writes unless the admin explicitly approves.
