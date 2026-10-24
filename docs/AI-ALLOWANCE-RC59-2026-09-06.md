# Shared website AI allowance — RC59

6 September 2026. This is the first implementation unit under the [AI affordability/SEO direction](AI-AFFORDABILITY-SEO.md). Local RC59 follows the installed RC58 lab artifact. No hosting, credentials, provider calls, billing or production changes are part of this build.

## Delivered behavior

The existing AI settings page gains Website AI allowance controls for minute/day/month requests, usage counts and a provider cooldown. They use the same native dsa_settings.ai authority and existing owner settings permission/nonce flow. There is no second settings page, separate feature key or visitor asset. A zero ceiling pauses calls. Missing submitted fields preserve the previous ceiling; malformed values become zero, never an absolute positive number.

Default ceilings are 2 per minute, 30 per UTC day and 300 per UTC calendar month. These are conservative Kiwe defaults, not a quoted Gemini allowance. They also apply to existing installations where these new fields are absent; existing AI workloads may pause sooner after upgrading. The owner can set lower ceilings, including zero. Maximum accepted values are 60/10,000/100,000 respectively. Changing provider, model or key does not replenish usage. No automatic paid/provider/model fallback is introduced.

Every direct provider HTTP attempt reserves one request before transmission, including the existing single eligible 5xx retry. Timeouts, failures and process interruption after reservation stay charged because consumption may be unknown. Missing key, disabled generation and invalid/oversized prompt do not spend an allowance. If reservation storage is unavailable, corrupt or too contended, no new request is sent. Previously successful attempts retain called=true if a later retry is denied.

A direct provider 429 installs a shared cooldown, observing Retry-After seconds or HTTP date; absent/invalid values defer one hour, and every hold lasts at least a minute. Accepted holds are bounded to one year. A shorter concurrent hold cannot shorten a longer one. SEO saves retryAt with the existing paused job error and displays the next eligible time. Resumption remains manual; this batch does not replace the SEO scheduler or add automatic retries.

The WordPress AI Client entry reserves from the same allowance once per Kiwe invocation. Internal provider selection/retries and usage inside that client are opaque to this adapter. Therefore these local limits are not a certified count of all underlying WordPress-provider HTTP attempts. That integration needs a separate usage/attempt contract before a hard money ceiling can be claimed.

The Gemini 3 Interactions payload now sends the configured max_output_tokens; previously only other payload paths applied the configured output limit. The official [Interactions reference](https://ai.google.dev/api/interactions-api?hl=en) documents that field. No model default or endpoint is changed by this batch.

## Persistence and concurrency

[AI_Allowance_Service](../wp-content/mu-plugins/dsa/includes/AI/AI_Allowance_Service.php) owns one non-autoloaded option, kiwe_ai_allowance_v1, containing only a version, fixed UTC window identifiers, counters, hold expiry and last update time. It never stores prompts, keys, account identifiers, content, IPs or provider responses. One bounded row replaces a growing event history. It is created lazily on the first reservation; constructors and disabled generation perform no ledger writes. Read-only status does not create state. No new table, cron, daemon or visitor hook is installed.

All reservations read the database directly, then insert under WordPress's unique option-name index or update only the exact bytes read. Six bounded collision retries reread the latest state before granting; exhausted contention fails closed. This avoids using a cached/transient read followed by a non-atomic write. Provider I/O never holds a database transaction or lock. Window normalization and counters are committed together. A backwards clock or malformed/inconsistent ledger pauses calls instead of resetting spend. Option caches are invalidated after successful writes. See [the accepted decision](decisions/2026-09-06-ai-request-allowance.md).

Fixed UTC minute/day windows are intentionally not provider rolling windows. Google documents project-scoped model/tier limits and a Pacific-time daily reset. Kiwe's independently lower limits and shared cooldown reduce traffic; they do not establish remaining provider quota. Other callers and plugins can use that project without passing through Kiwe. [Gemini rate limits](https://ai.google.dev/gemini-api/docs/rate-limits).

## Boundaries and next work

This is a request allowance, not token settlement, an estimated invoice or a guaranteed monetary cap. It cannot certify that the configured project has no billing enabled. Pricing-aware conservative token reservation, reported-usage settlement, client opt-in beneath owner allowance, independent SEO permission, per-object SEO actions, paged resumable library jobs and automatic quota-aware resume remain further work. Existing dedicated SEO plugins retain frontend authority. Existing broader client UI and ownership-policy gaps remain in [RC58 acceptance](RC58-LAB-ACCEPTANCE-2026-09-06.md).

Recovery: reverting to RC58 leaves the new state inert but removes this protection, so disable native generation before a code rollback if budget protection is required. The ledger is not an editable reset button. A missing row is treated as first setup; external deletion/restoration of the options table can replenish allowance, so a future backup/restore flow must account for it. No claim of tamper resistance against a database administrator is made.

## Verification

The combined run completed; results and the focused fixture correction are recorded below. New executable fixtures cover synthetic insert/update contention, window rollover, key changes, corrupted/unavailable storage, backwards clocks, provider failures, 429 cooldown and the WordPress AI entry. These use actual allowance/provider code with synthetic database/provider boundaries. They do not prove MySQL concurrency, real provider transport, native settings form submission or browser layout. Those checks belong to the next grouped lab acceptance; no paid inference is needed for ledger proof.

The package version, root loader, generated token catalogue and manifest advance together to RC59. Source is uncommitted/unpushed on canonical main over 731a94ff48dd350bb7b168ec285fcc96afbdaa01, preserving earlier changes. The lab remains RC58. Key and Seam source remain unchanged in this batch, and no dirty Kiwe snapshot is copied into Seam.

## Completed local evidence

One combined run used PHP 8.4.23 / Node 22.23.1 and passed 54 of 55 baseline gates, all 22 selected PHP service lints, and 97 links across 20 maintained documents. The only failure was a syntax error in the newly authored test fixture (not runtime code). After fixing that fixture, the focused allowance gate passed all 33 behavior checks. No runtime/package bytes changed after the combined run, and the 54 unchanged gates were not repeated. This is combined evidence plus a focused repair, not a claim that a second full baseline run passed.

Receipts outside source: baseline-1788686640220-e5d63786-0643-4645-8bcd-b66ec80b8905.json (original failure retained), allowance-1788686734977-fcc71698-1186-4963-8e34-a4a9ed263d0a.json (33 checks passed), syntax-1788686615857-782ff01e-9c23-4be1-b861-00dfe6a1a64d.json, docs-1788686608228-972bd1d4-d0d3-49e2-86b7-f4e020527d24.json. Initial source hash c5cf7c202a8712be5e9d36f7bc555b862a3838a69abface38776cd2b0fb75953; corrected-fixture source hash 9d498805fa1788e8067086e435920f8266dbf7fecdedda963ac490bf4f267255. Results documentation follows those snapshots. The RC59 manifest contains 365 package files. No archive, signing or deployment occurred.
