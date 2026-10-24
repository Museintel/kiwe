# Reserve request and token allowance in one atomic site record

Date: 6 September 2026. Status: accepted in local RC61 source; native/provider/browser acceptance pending.

Request counts alone cannot distinguish large SEO payloads from small security reviews. Independently reserving request and token rows would create partial charges during contention. Returning estimated units without an idempotent receipt could let concurrent retries refund the same work twice.

Keep the existing native site ledger and upgrade its internal schema in place. Add three token counters, a tracking timestamp and a bounded map of internal receipt IDs with time/units. One CAS reserves both allowances. One CAS settles and retires a receipt. Only supported, consistent successful provider usage can release unused units; failures and unknown usage retain estimates. Every HTTP retry needs its own reservation. Exceeding an estimate charges the observed total and triggers a shared pause. Receipts expire without a refund, and settlement never adjusts a newer UTC window.

The token estimate uses encoded payload bytes, configured output allowance and reasoning/framing headroom; it is explicitly a heuristic. It is not a tokenizer, provider quota, price estimate or guaranteed cost ceiling. Gemini response contracts are supported; other adapters retain estimates until individually accepted. WordPress AI internal attempts remain opaque. Supported response parsing is source-level evidence, not a live transport certificate.

The original owner AI settings gain the new Kiwe-only token ceilings. The existing client AI assistance page projects aggregate usage and tracking start, with no editor for technical budgets. RC60 feature permission and client opt-in remain mandatory. No other existing WordPress/Woo/SEO control gains a shadow copy.

Schema-1 request/cooldown state survives lazy migration; token tracking begins with the next reservation and does not fabricate historical usage. Old runtime code rejects schema 2 and pauses inference on rollback. Never reset counters as a downgrade shortcut. No new table, scheduler, public asset, paid action or cross-repository dependency is required.

The [RC61 batch record](../AI-TOKEN-ALLOWANCE-RC61-2026-09-06.md) owns the implementation, failure boundaries, verification and remaining acceptance. A future monetary cap requires stricter provider/pricing/billing evidence than this token-work allowance supplies.
