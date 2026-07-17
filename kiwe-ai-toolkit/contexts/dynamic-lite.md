# Kiwe dynamic binding context

Use this context only after the website/page and optional Kiwe AppShell theme already pass the normal Kiwe audit.

Goal: revise the handoff into a WordPress/Bricks-aware version by using a real `kiwe.site-graph.v1` JSON snapshot from the target site.

Do not read the whole Kiwe repo. Do not search GitHub. Do not guess the target site's categories, pages, products, post types, dynamic tags, or Bricks query-loop object types.

## Inputs you should ask for

- The current Kiwe handoff folder or files.
- The target site's `kiwe.site-graph.v1` JSON.
- The plain-language dynamic request, for example: "turn placeholder product rails into Bricks query loops and dynamic product cards."

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

If no execution is available, do not claim validation ran. Self-check against this context and report that executable validation was not available.

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
