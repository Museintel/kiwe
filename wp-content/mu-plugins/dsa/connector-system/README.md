# Kiwe Connector System

The Connector System is Kiwe's AI/data binding brain.

It sits beside the two existing brains:

- `ui-system/` — Kiwe DSA/AppShell theme contracts and previews.
- `framework-system/` — Seam Framework, tokens, vocabulary, and website/page handoff contracts.
- `connector-system/` — live WordPress/Bricks/WooCommerce/Kiwe context and dynamic binding rules.

The first connector is the **Kiwe Site Graph**.

## What the Site Graph does

The Site Graph gives an AI or developer a bounded, admin-only snapshot of what the current WordPress site can actually use:

- public post types, sample pages/posts/products, and taxonomies;
- real term IDs and slugs for Bricks query-loop filters;
- WooCommerce shop/cart/checkout/account page assignments;
- WooCommerce product categories/tags and store public address context;
- Bricks presence, version, query loop types, dynamic data tags, and Kiwe dynamic tags when available;
- Kiwe AppShell modules, dock/search settings, and launcher capabilities;
- authority boundaries so the AI does not recreate cart/search/auth/checkout/runtime behavior.

The Site Graph is read-only. It never exposes secrets, API keys, visitor state, orders, payment data, private customer data, or credentials.

## Runtime surfaces

When Kiwe is installed, admins can fetch the Site Graph from:

```text
GET /wp-json/dsa/v1/site-graph?sampleLimit=8
```

Admins can also download the same graph from:

```text
Kiwe > Framework > AI connector and Site Graph > Download Site Graph JSON
```

The admin download path is useful for browser-based AI workflows where REST cookie/nonces or external connectors are inconvenient.

Permissions:

- requires `manage_options`;
- response is `private, no-store`;
- intended for local/admin design workflows, not public crawling.

On WordPress 7+ when the Abilities API is available, Kiwe also registers:

```text
dsa/get-site-graph
```

That ability returns the same `kiwe.site-graph.v1` graph for MCP/AI clients.

## How this fits the AI workflow

The current successful Kiwe generation loop is:

1. AI designs a Seam website/page.
2. AI designs a Kiwe DSA/AppShell theme.
3. AI audits the handoff against the Kiwe toolkit.

The new dynamic pass becomes:

4. AI reads the Site Graph.
5. AI revises the website/page into a Bricks binding plan:
   - placeholder product/category/post rails become Bricks query-loop intentions;
   - static titles/images/prices/excerpts become Bricks dynamic-data bindings;
   - page/header buttons use canonical Kiwe launchers;
   - WooCommerce/cart/checkout/search/auth remain owned by Woo, Bricks, WordPress, and Kiwe.
6. Kiwe/Bricks validate the binding plan before anything is applied. The public toolkit validator is:

```bash
node kiwe-ai-toolkit/tools/validate-bindings.cjs ./path/to/handoff --site-graph ./site-graph.json
```

MCP clients can call `kiwe_validate_bindings`.

Admins can also validate an AI-produced `bricks-bindings/kiwe-bindings.json` directly against the live target site from:

```text
Kiwe > Framework > AI connector and Site Graph > Validate AI binding plan
```

That admin intake is non-mutating. It reports failures/warnings and now also shows the dry-run apply-plan preview for the same upload: preflight gates, prepared Bricks query/dynamic-field operations, Kiwe launcher/menu-context operations, and manual-review items. It still does not save WordPress, WooCommerce, or Bricks content.

7. Prepare a dry-run trusted apply plan:

```bash
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs ./path/to/handoff --site-graph ./site-graph.json
```

MCP clients can call `kiwe_prepare_apply_plan`.

8. Later batches can safely apply the plan through Bricks 2.4 abilities or Bricks import workflows after admin approval, revision capture, rendered-output inspection, and post-apply audit. Until that future adapter exists, both the CLI and WordPress admin apply-plan views are inspection contracts only.

## Lead rule

Do not ask a browser AI to crawl the whole plugin or guess a site's categories. Give it:

1. the relevant Kiwe Toolkit context;
2. the Site Graph JSON exported from the target site;
3. the current handoff it must revise.

The model should then output a revised handoff plus a binding report. It should not mutate WordPress directly unless a future trusted apply step explicitly authorizes it.
