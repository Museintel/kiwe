# One atomic website request allowance

Date: 6 September 2026. Status: accepted for RC59 request accounting; monetary enforcement remains proposed.

The founder requires each website's AI features to share the dedicated Gemini project's limited allowance. Per-consumer rate limits and output sizes do not limit combined monthly requests. Start with an enforceable request ceiling whose unit and limitations can be explained accurately.

Use a single bounded, non-autoloaded Kiwe option with raw database reads and atomic insert/compare-and-swap writes. The option-name unique index arbitrates initialization. Binary value comparison arbitrates updates and concurrent rollover. Retain failed/uncertain attempt charges and share direct-provider 429 cooldowns. Do not use the existing fail-open rate limiter as the affordability boundary. No database transaction spans a network call; no cleanup scheduler or per-request history is needed.

Native dsa_settings.ai owns the owner-configured ceilings. AI_Allowance_Service owns Kiwe-invented counters. A per-service store would duplicate project allowance; a transient is evictable and cannot guarantee spend; a custom ledger table is unnecessary for bounded counts. Token/cost settlement will need an independently reviewed reservation model rather than relabelling request counts as money.

Runtime changes add no new user authority. Owner/client permission separation continues through the existing ownership policy; client opt-in is a subsequent task. WordPress AI internals and out-of-Kiwe calls are outside precise attempt accounting. Backups restoring the ledger and code rollback can affect protection; the [batch contract](../AI-ALLOWANCE-RC59-2026-09-06.md) records recovery and acceptance limits.

Verification: run adversarial state/transport fixtures and the full source baseline together. Native MySQL contention and admin form/browser acceptance remain required before broader rollout. Related repositories Key and Seam require no changes.
