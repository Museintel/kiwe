# Bricks dynamic binding pass

This document defines Kiwe's v5-style pass: turning a successful static Seam/DSA handoff into a WordPress/Bricks-aware handoff.

## Inputs

A dynamic binding pass needs only:

1. the current Kiwe handoff;
2. the Kiwe Toolkit dynamic context;
3. a `kiwe.site-graph.v1` JSON snapshot from the target site.

Do not read the full plugin codebase. Do not guess categories, pages, products, Bricks tags, or query-loop object types when the Site Graph provides them.

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
