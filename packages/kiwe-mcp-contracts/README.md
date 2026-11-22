# Kiwe operational MCP foundations

M01 source foundation for the optional **Kiwe → Apps → MCP** product. This package is not registered with Core, an HTTP server, OAuth provider or WordPress Abilities yet. Nothing here exposes an endpoint, grants access, executes a business operation or changes the accepted Core472 package.

`operation-catalogue.json` maps intended conversational operations to existing native owners. Entries are planned adapters, not a claim that they are callable. `src/Admission_Policy.php` decides admission after a future server has authenticated a grant; it is not a token verifier. Every decision also needs a live native-owner permission callback and an explicit available-handler callback. Unknown/unimplemented operations, wrong site/actor, expired/revoked grants, disabled dependencies and callback failures deny. Writes need a bounded server-prepared intent. Consequential operations additionally need an independent server-side approval lookup; an AI's `approved: true` is never authority. Native owners still validate values, object access, current state and side effects immediately before execution.

`src/Orientation.php` composes a small welcome and next-step packet from an authorized native inventory and the existing Design Context journey projection. It does not fetch data, count records, write completion or infer permission. Incomplete inventories cannot establish a fresh installation. Existing content or saved business context takes precedence over a claimed fresh state. It preserves the native required/deferred distinction and omits Store-only steps when Store is unavailable.

Run `php packages/kiwe-mcp-contracts/tests/foundation.php`. These are source decision tests using synthetic trusted-server inputs, not OAuth, native WordPress, HTTP, actual-client or deployment acceptance. M02/M03 must supply and qualify those boundaries before anything is reachable remotely.

Current architecture, delivery batches and official-source research: [MCP App plan](../../docs/MCP-APP-2026-11-03.md).
