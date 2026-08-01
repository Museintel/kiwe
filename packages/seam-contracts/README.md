# SEAM Contracts

This package is the canonical wire contract for SEAM Compiler and Kiwe AppSite deployment. Bricks JSON is generated from these typed artifacts; it is never accepted from an AI as compilation authority.

Initial contracts:

- `seam.capture.v1` — rendered browser evidence across viewports and states.
- `seam.page-ir.v1` — normalized semantic, layout, style, and provenance tree.
- `seam.behavior-ir.v1` — classified events, state transitions, and authority.
- `seam.asset-manifest.v1` — hashed importable and external assets.
- `seam.bricks-plan.v1` — native Bricks element/control ownership before serialization.
- `kiwe.appsite-package.v1` — deployable package inventory and compatibility boundary.

Run `node packages/seam-contracts/tools/generate-types.cjs --check` to prove generated TypeScript and PHP declarations match the schema manifest. Run `node packages/seam-contracts/tools/validate-contract.cjs <schema-name> <json-file>` to validate an artifact.
