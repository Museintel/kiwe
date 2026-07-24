# Kiwe 0.6.35 release proof

This release adds the phased Kiwe AI workflow router and the optional `/usecompanion` command flag.

## What changed

- `kiwe-ai-toolkit/contexts/workflow-lite.md` now defines the staged AI workflow: ideate, Seam rebuild/audit, Framework profile, DSA theme/audit, combined assembly/audit, dynamic Site Graph binding, and controlled staging apply.
- The toolkit CLI and MCP server expose `kiwe workflow` / `kiwe_get_workflow` and `kiwe route` / `kiwe_route_command` so browser and IDE AIs can request the smallest relevant Kiwe context instead of reading the full codebase.
- `/usecompanion` can be appended to any phase command. Companion use is optional and non-blocking: if Companion cannot answer, the AI continues the original phase and reports the fallback.
- The live Companion REST context is phase-aware. It can return compact phase cards, context hashes, Site Graph hashes, prior finding fingerprints, and deterministic audit maps without calling a model or dumping whole plugin files.

## Authority boundary

`/usecompanion` does not make Companion a creative co-author, a Bricks saver, a WooCommerce mutator, a SecureTrack enforcement owner, or a native-model spending lane. It is a token-saving contract oracle and deterministic review source.

Mutation remains behind the controlled staging executor and explicit human confirmations.

## Verification

- `npm.cmd test --prefix kiwe-ai-toolkit`
- `php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php`
- `node tools/connector/ai-api-contracts.cjs`
- `node tools/release/verify-package.cjs`
- `node tools/release/rc12-contracts.cjs`
- `node tools/runtime/htmx-alpine-contracts.cjs`

