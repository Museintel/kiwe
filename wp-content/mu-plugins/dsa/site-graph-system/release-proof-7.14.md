# SiteGraph external-client release proof — Kiwe 7.14

## Scope

This release closes the first vendor-neutral external-client security layer. It does not make a browser AI a WordPress administrator and does not broaden the trusted staging authority.

## Deterministic guarantees

- `GET /wp-json/dsa/v1/ai/openapi.json` publishes a secret-free OpenAPI 3.1 contract generated from the same guarded route table registered by Kiwe.
- `GET /wp-json/dsa/v1/ai/client-manifest` explains HTTP/OpenAPI, MCP, IDE, browser-extension and file-only connection lanes without choosing an AI vendor.
- SiteGraph task capsules contain at least 240 bits of generated secret material, are stored only as WordPress password hashes, expire within 24 hours, have a bounded use count and can be revoked.
- Capsules force SiteGraph Data to public objects, remove private status/meta selectors and enforce administrator-selected resource, field, row and sample budgets.
- Capsule scopes are limited to SiteGraph, Bricks context, SEAM and deterministic validators. Staging, themes, runtime execution, publishing and mutation are absent and cannot be requested through a capsule.
- `/ai/*` applies a per-origin authentication limit and a per-credential/per-scope operation limit. High-authority permanent-key routes have the smallest budgets.
- A separately scoped permanent `kiwe_ai_...` key remains mandatory for the human-approved trusted staging chain.

## Executable evidence

```text
php tools/release/test-sitegraph-task-capsule.php
node tools/release/sitegraph-client-contracts.cjs
node tools/release/verify-green-baseline.cjs
```

The runtime contract creates, authenticates, constrains and revokes a capsule; proves the plaintext is absent from stored options; rejects disallowed resources; clamps private selectors and budgets; and validates OpenAPI multi-method and WordPress-regex path conversion. The release contract additionally gates admin wording, discovery routes, authority annotations, throttling and documentation synchronization.

## Remaining external-client work

Vendor-specific adapters remain outside the WordPress truth layer. Subsequent batches may provide thin ChatGPT Action, MCP, Claude/Cursor and Chrome-extension adapters plus adversarial live-site acceptance, but every adapter must consume this same OpenAPI/manifest/capsule contract and may not introduce a second authority model.
