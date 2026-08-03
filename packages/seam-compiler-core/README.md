# SEAM Compiler Core

This package converts rendered `seam.capture.v1` evidence into normalized page, behavior, and asset IR. It contains no browser, WordPress, network, or AI client and performs no mutation.

The core is deterministic: identical capture input produces identical IR. `classify-component.cjs` records the semantic type, selected adapter class, confidence, evidence, and limitations for every source node. It distinguishes behavior-equivalent native mappings from semantic-layout preservation and explicit review boundaries. AI may later propose bounded classifications against an IR slice, but `ai-direct-json.cjs` permanently rejects AI-authored Bricks JSON as compiler input.

`sitegraph-deployment.cjs` adds the target-aware plane without granting mutation authority. It sanitizes an existing `kiwe.site-graph.v1`, compiles only explicit `data-kiwe-*` and `data-dsa-open-module` intent that the graph can prove, plans content-addressed assets, and emits a rollback-safe dry-run deployment plan. Missing asset hashes/MIME/bytes become review blockers. Actual writes remain behind Kiwe's admin-approved staging executor.

Static compilation remains valid without a SiteGraph. For a target-specific package:

```text
node packages/seam-compiler-core/tools/compile-capture.cjs capture.json bricks-profile.json output "Page title" --site-graph site-graph.json
```
