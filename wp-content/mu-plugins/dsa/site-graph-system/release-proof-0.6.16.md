# Kiwe DSA 0.6.16 release proof

Date: 2026-07-22

Purpose: harden the AI-less Site Graph Data route after live staging verification showed the public schema was available but simple client reads needed a more forgiving request shape.

## Changes proved

- `/wp-json/dsa/v1/site-graph/data` merges explicit query parameters before JSON body data so simple headless reads like `resource=products&limit=3` stay aligned with the advertised schema.
- Site Graph Data supports a compact `resources` batch shorthand in addition to explicit `queries`, allowing external AI, native Kiwe Studio, and headless frontends to ask for common resource sets without inventing verbose request payloads.
- Public/headless docs and the dynamic binding context now show the same compact batch shape.
- Connector contracts now assert the route/parser/docs wiring so future AI work does not drift back to scraping or placeholder-only content.

## Live staging observation that triggered this patch

- Staging was still serving package manifest `0.6.14`, so the `0.6.15` local AI/Companion hardening was not yet live.
- Gemini native drafting worked on staging with HTTP 200, but large-context compaction still requires the local `0.6.15+` package to be uploaded.
- The public Site Graph schema route worked, and explicit batch `queries` worked. This patch improves the simpler GET and compact batch ergonomics before the next upload.

## Expected verification before upload

- PHP syntax passes for changed PHP files.
- `tools/connector/ai-api-contracts.cjs` passes with the new Site Graph Data contract assertion.
- Release package manifest is rebuilt and verified.
