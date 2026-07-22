# Kiwe Site Graph System

Kiwe Site Graph is the AI-independent context/query layer for a WordPress site.

It is GraphQL-like in purpose, but intentionally WordPress-native and Kiwe-aware:

- WordPress, WooCommerce, Bricks, Kiwe settings, Seam tokens, CPTs, taxonomies, menus, dynamic tags, and builder capabilities are normalized into one structured graph.
- AI is only one consumer. Admin screens, Bricks tooling, audits, staging importers, future headless exports, and external developer tools can also consume the graph.
- Site Graph is read/introspection-first. Mutations belong to the Controlled Executor, not to the graph.

## Live routes

Site Graph has two lanes:

- Admin introspection routes expose full site capability context and require `manage_options`.
- Data routes are headless/public-safe by default and only expose public/published objects unless the requester is an authenticated administrator.

```text
GET /wp-json/dsa/v1/site-graph
GET /wp-json/dsa/v1/site-graph/summary
GET /wp-json/dsa/v1/site-graph/query?select=site,woocommerce.productCategories,bricks.dynamicTags
POST /wp-json/dsa/v1/site-graph/query

GET /wp-json/dsa/v1/site-graph/data/schema
GET /wp-json/dsa/v1/site-graph/data?resource=products&taxonomy=product_cat&term=fudge&limit=4
POST /wp-json/dsa/v1/site-graph/data

GET /wp-json/dsa/v1/ai/site-graph-data/schema
GET|POST /wp-json/dsa/v1/ai/site-graph-data
GET /wp-json/dsa/v1/ai/security-brief
GET /wp-json/dsa/v1/ai/internal-context
GET|POST /wp-json/dsa/v1/ai/advisor
GET|POST /wp-json/dsa/v1/ai/advisor/enrich
```

Example graph selector body:

```json
{
  "sampleLimit": 8,
  "select": [
    "site",
    "wordpress.postTypes",
    "woocommerce.productCategories",
    "bricks.queryLoopTypes",
    "bricks.dynamicTags",
    "kiwe.modules",
    "kiwe.tokenSummary"
  ]
}
```

Example headless data body:

```json
{
  "queries": {
    "site": {
      "resource": "site"
    },
    "mainMenu": {
      "resource": "menus",
      "location": "primary"
    },
    "fudgeRail": {
      "resource": "products",
      "category": "fudge",
      "limit": 4,
      "fields": ["id", "title", "url", "featuredImage", "product", "terms"]
    },
    "blogRail": {
      "resource": "posts",
      "limit": 6,
      "fields": ["id", "title", "url", "excerpt", "featuredImage", "terms"]
    }
  }
}
```

That one request is the Kiwe equivalent of a compact GraphQL page query: it can fetch site identity, menus, product rails, editorial rails, media-rich nodes, taxonomies, and WooCommerce product data in a normalized envelope.

## Boundary

Site Graph answers questions:

- What content exists?
- Which post types, taxonomies, custom fields, and terms exist?
- Which WooCommerce pages/categories/products are available?
- Which Bricks query loop types and dynamic tags are available?
- Which Kiwe AppShell modules, dock items, tokens, and capability boundaries exist?
- Which public posts, pages, products, menus, terms, and images can feed a headless page?

Site Graph does not mutate:

- WordPress content
- WooCommerce products/orders/settings
- Bricks pages/templates/settings
- Kiwe themes/framework profiles/settings
- cart, checkout, auth, AI, notifications, saved state, or payment flows

Those actions stay in the Controlled Executor and require explicit scoped authorization.

## Why this exists

The original AI connector needed a compact way to explain a site to an AI without dumping the whole plugin or wp-admin state. That direction remains valuable.

The Site Graph system promotes the same capability into a standalone platform primitive. A human developer, a Bricks integration, a future MCP server, a headless exporter, or an AI agent should all be able to ask the same graph what the site can do.

## Current implementation

- Graph producer: `includes/AI/Site_Graph_Service.php`
- AI-less REST controller: `includes/Rest/Site_Graph_Controller.php`
- Query selector: `includes/Site_Graph/Query_Service.php`
- Headless data reader: `includes/Site_Graph/Data_Query_Service.php`
- AI route remains preserved: `includes/Rest/AI_Access_Controller.php`

The producer still lives in the historical `DSA\AI` namespace for compatibility. The public feature is now Site Graph, and future batches can move/alias the producer into `DSA\Site_Graph` without breaking existing AI routes.

## GraphQL comparison

Kiwe Site Graph Data is not a GraphQL language parser. It does not ask frontend tools to learn a schema language, fragments, resolvers, or mutations.

It covers the practical headless need directly:

- discover the site contract through `/data/schema`;
- fetch one resource or many named resources in one request;
- return normalized WordPress/WooCommerce/media/menu nodes;
- stay public-safe without an API key for published content;
- allow richer private reads only for authenticated administrators;
- keep all writes in the Controlled Executor with explicit scoped authorization.

GraphQL is broader as a query language. Kiwe Site Graph is narrower by design, but more aligned to this plugin: it includes Kiwe AppShell, Seam, Bricks, WooCommerce, and AI handoff needs as first-class context instead of treating them as custom resolver work someone else must build later.

## WordPress 7 / Abilities lane

When WordPress exposes the Abilities API, Kiwe registers these safe connector abilities:

```text
dsa/get-site-graph
dsa/get-site-graph-data-schema
dsa/query-site-graph-data
dsa/get-securetrack-brief
dsa/get-internal-ai-context
dsa/run-internal-ai-advisor
dsa/enrich-internal-ai-advisor
dsa/validate-bindings
dsa/prepare-apply-plan
dsa/stage-apply-plan
```

The Abilities lane mirrors the REST/API-key lane for native WordPress AI/tool clients. It remains admin-authorized and schema-described. `dsa/stage-apply-plan` writes only Kiwe review metadata; it does not save Bricks content, publish WordPress content, mutate WooCommerce, or alter security enforcement.

## Internal AI context

`/wp-json/dsa/v1/ai/internal-context` returns `kiwe.internal-ai.context.v1`, a safe fused packet for future Kiwe internal AI:

- Site Graph summary/hash and route map;
- Site Graph Data schema and headless usage notes;
- redacted SecureTrack AI brief;
- WordPress 7/Abilities/AI-client availability signals;
- capability map and operating boundaries.

SecureTrack data is redacted by design: no raw IPs, usernames, secrets, full URLs, request payloads, or visitor trails. Internal AI can explain, triage, recommend, and plan from the brief, but enforcement changes stay outside this read context.

`/wp-json/dsa/v1/ai/advisor` returns `kiwe.internal-ai.advisor.v1`, the first deterministic advisor layer built on the same context. It groups Site Graph/Data, SecureTrack, WP7/AI-client, and staging-boundary signals into findings, recommendations, and safe next actions. It is model-optional and read-only: a future WordPress AI Client may enrich wording, but deterministic findings and mutation boundaries remain authoritative.

`/wp-json/dsa/v1/ai/advisor/enrich` returns `kiwe.internal-ai.enrichment.v1`, the bounded model-enrichment seam. It prepares a deterministic summary, priority order, and model envelope that a future WordPress AI Client adapter may use. The model layer may improve explanation and grouping only; it must not change advisor IDs, severities, counts, routes, confirmation requirements, or mutation boundaries.

## Release proof

The current phase-close proof lives at `site-graph-system/release-proof-0.6.7.md`. Use it after uploading the folder-based MU package to staging. It lists the expected local package commands, API proof routes, WordPress 7 ability proof, dynamic handoff proof, and browser smoke checks for this Site Graph + internal AI phase.
