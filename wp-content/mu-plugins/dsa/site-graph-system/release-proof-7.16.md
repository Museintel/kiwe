# SiteGraph design-context proof — Kiwe 7.16

Kiwe 7.16 adds one framework-neutral SiteGraph design evidence contract for both connected tools and file-only browser AI.

## Contract

- Schema: `kiwe.sitegraph-design-context.v1`
- Canonical command: `/usesitegraph /for /designcontext`
- File-only command: `/usesitegraph /for /designcontext /nonai`
- Public read route: `GET|POST /wp-json/dsa/v1/site-graph/design-context`
- Scoped tool route: `GET|POST /wp-json/dsa/v1/ai/design-context`
- MCP tool: `kiwe_sitegraph_design_context`
- WordPress ability: `dsa/get-sitegraph-design-context`

The packet contains public site identity/logo, menus, bounded public products, gallery/attribute facts, searchable image metadata, public pages/posts/taxonomy facts, and target Bricks/Kiwe capabilities. It never includes credentials, drafts, customer/visitor state, orders, filesystem paths, publish authority or mutation authority.

Static image choices retain their public URL, attachment ID marker and stable selector. Repeating production regions remain dynamic and require an explicit binding target before a binding plan is emitted. Design context alone never adds Seam Framework or emits Bricks JSON.

## Verification

Run:

```text
node tools/release/sitegraph-design-context-contracts.cjs
node kiwe-ai-toolkit/tools/smoke-test.cjs
node tools/release/verify-green-baseline.cjs
```
