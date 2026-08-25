# One SiteGraph handoff proof — Kiwe 7.27

Kiwe 7.27 removes the pre-launch split between SiteGraph and a separately
exported Design Context. `kiwe.site-graph.v1` is now the only external AI
context packet. It embeds owner-authored Design Context and approved
enhancements alongside products, media, public content, custom fields,
taxonomies, commerce facts, Bricks capabilities and binding targets.

The clean break removes:

- the separate browser-AI Design Context download;
- `/site-graph/design-context` and `/ai/design-context`;
- `dsa/get-sitegraph-design-context`;
- `kiwe_sitegraph_design_context`;
- `/usesitegraph /for /designcontext` and its aliases;
- acceptance of standalone Design Context as an `/ideate` handoff.

The owner onboarding profile remains an internal authoritative SiteGraph
section. The hash-bound Design Context enhancement approval lane also remains,
but it consumes the one SiteGraph packet.

Verification:

```text
node tools/release/sitegraph-design-context-contracts.cjs
node tools/release/onboarding-contracts.cjs
node kiwe-ai-toolkit/tools/smoke-test.cjs
node tools/wp7/rc11-contracts.cjs
```
