# SEAM Contracts

This package is the canonical wire contract for SEAM Compiler and Kiwe AppSite deployment. Bricks JSON is generated from these typed artifacts; it is never accepted from an AI as compilation authority.

Initial contracts:

- `seam.capture.v1` — rendered browser evidence across viewports and states.
- `seam.page-ir.v1` — normalized semantic, layout, style, and provenance tree.
- `seam.behavior-ir.v1` — classified events, state transitions, and authority.
- `seam.asset-manifest.v1` — hashed importable and external assets.
- `seam.asset-import-plan.v1` — content-addressed, dry-run asset decisions with preconditions and rollback intent.
- `seam.sitegraph-snapshot.v1` — sanitized target capability evidence derived from `kiwe.site-graph.v1`.
- `kiwe.bricks-bindings.v1` — target-proven query, dynamic-data, menu and AppShell launcher bindings.
- `seam.deployment-plan.v1` — non-mutating staging sequence and executor boundaries.
- `seam.visual-proof.v1` — matrix-scoped pixel, geometry, computed-style, accessibility, and diagnostic comparison.
- `seam.repair-plan.v1` — bounded proposal-only diagnostics that must be applied through IR/compiler rules.
- `seam.bricks-plan.v1` — native Bricks element/control ownership before serialization.
- `kiwe.appsite-package.v1` — deployable package inventory and compatibility boundary.

Run `node packages/seam-contracts/tools/generate-types.cjs --check` to prove generated TypeScript and PHP declarations match the schema manifest. Run `node packages/seam-contracts/tools/validate-contract.cjs <schema-name> <json-file>` to validate an artifact.
