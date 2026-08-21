# SiteGraph universal-client adapter proof — Kiwe 7.15

## Scope

This release adds thin OpenAPI, HTTP, MCP, Claude, Cursor and maintained Chrome-extension configuration contracts on top of Kiwe 7.14's task capsule. It does not create a vendor-specific authority layer and does not grant external clients staging, controlled execution, WordPress mutation, WooCommerce mutation, authentication, runtime or publishing access.

## Deterministic guarantees

- `/wp-json/dsa/v1/ai/openapi.task.json` contains only `status` and scopes present in `Task_Capsule_Service::SCOPES`; staging and mutation routes are absent rather than merely expected to fail at execution time.
- `/wp-json/dsa/v1/ai/client-adapters` is public, secret-free setup metadata. A downloaded `kiwe.external-client-connection.v1` adds one `kiwe.external-client-adapters.v1` descriptor while retaining one canonical secret reference.
- `kiwe-ai-toolkit/mcp/sitegraph-client.js` exposes a fixed allowlist of SiteGraph, Bricks context, deterministic validation and SEAM conversion tools. It rejects permanent `kiwe_ai_*` keys.
- The MCP bridge requires HTTPS except for localhost, refuses redirects before following them, limits request time and response size, and never writes the capsule into a URL or tool result.
- Claude and Cursor descriptors use the same stdio MCP bridge and environment names. An OpenAPI/action client imports the same task-only schema and stores bearer authentication outside model instructions.
- The SEAM Compiler Chrome extension imports the same connection schema and stores its token in `chrome.storage.local`, never sync storage or page DOM.

## Executable evidence

```text
node tools/release/sitegraph-adapter-contracts.cjs
node tools/release/sitegraph-client-contracts.cjs
php tools/release/test-sitegraph-task-capsule.php
node tools/release/verify-green-baseline.cjs
```

The final remaining SiteGraph batch is live adversarial/cross-client acceptance: expired, revoked, exhausted, cross-origin, redirect, oversized-response and forbidden-route tests against the uploaded canonical MU plugin plus extension import/status verification.
