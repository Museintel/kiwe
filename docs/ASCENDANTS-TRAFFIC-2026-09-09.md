**Current, 10 September:** Ascendants RC55.3 is verified live. Only track_visitors and track_pages are off; SecureTrack and Key remain enabled, and all other security/identity settings are preserved. [Targeted production mitigation and limitations](TRAFFIC-RELIABILITY-RC81-2026-09-10.md). The earlier deferred investigation below is historical; the outage cause is still not established.

# Ascendants traffic and database incident — 9 September 2026

Historical 9 September status: **deferred at the founder's request**. Resume RC79 continuation. No scheduled polling. The earlier whole-engine disable request has been clarified as optional telemetry only; no live telemetry setting change is verified.

## Observed and reported evidence

The founder reported a WordPress database-connection error on wp-admin, temporarily disabled Kiwe, then re-enabled it and asked to disable only SecureTrack. Hostinger's supplied one-hour analytics show 212,221 requests and 2.24 GB: about 59 requests per second on average. Requests include assets and bots and do not establish the number of visitors, concurrent PHP workers or SecureTrack events.

[Hostinger status](https://statuspage.hostinger.com/) displayed Database Service Maintenance, investigating from 13:44 UTC on 9 September. It described temporary site/DB unavailability on some servers. The announcement does not establish that this site's specific server was affected or exclude a Kiwe contribution.

Bounded public probes obtained a cached LiteSpeed homepage (HTTP 200), a wp-admin HTTP 408, one older image HTTP 200 and two newer raw upload-image URLs HTTP 408. A cached homepage is not proof of database recovery. Native upload URLs and ordinary WordPress/LiteSpeed rewrite rules were observed; no Kiwe image proxy was involved in those URLs. File existence, cache origin behavior and the image failure cause remain unverified.

Hostinger's control-plane database inventory showed the expected database assigned and below its reported size allowance, with permissions granted. That inventory is not a live SQL connection or table-health test. File access/upload authorization for Ascendants repeatedly failed with hosting 503 errors. No database credentials, tables, content, restores, cache purges or full plugin rollout were changed during this investigation.

## Source findings, without a causal claim

The bundled loader respects the existing dsa_enable_bundled_securetrack filter before including the engine. This filter also exists in the RC55 baseline used by the installed RC55.2 backport. A dedicated engine-off override can therefore preserve the rest of Kiwe.

When visitor tracking reaches SecureTrack_Event_Service::log, it can upsert IP and session records, run several context/reputation/threat lookups, read the previous event hash and insert an event. Some rejection occurs after early record work. This is plausible database load amplification. Cache hits and static requests may bypass PHP, and the current live tracking settings and query/write share have not been measured. The earlier RC55.2 repeated-ALTER repair addresses schema migrations; it does not certify the cost of every event.

A monitor-only/emergency enforcement mode still logs, so it is not equivalent to disabling the tracking engine. Inspect the actual module switch when reducing tracking work.

## Temporary operation and cleanup

Direct upload authorization was unavailable. A short maintenance job was attempted to install only a temporary MU off switch. The hosting job API altered an escape in the submitted command, so the candidate was withdrawn rather than accepted as a valid deployment. The file's final content and whether WordPress executed it were not established.

The installer job was deleted. A narrowly scoped cleanup removed only the task-created temporary off-switch path; its job returned KIWE_TEMPORARY_SWITCH_REMOVED. The cleanup job was deleted and the account cron inventory was empty. The local whole-engine operator template was withdrawn on 10 September because it does not meet the clarified requirement. It is neither packaged nor installed.

Kiwe was last reported re-enabled by the founder. Effective live SecureTrack state and post-cleanup uncached WordPress/database health remain unverified. No support message was sent for this incident.

External evidence is in the task workspace work/ascendants-db-20260909/: http-initial.json, images.json and cleanup.json. No visitor IPs, credentials or customer records belong in the repository.

## Original resumption procedure — superseded by RC55.3 verification

1. Read the installed version and existing native SecureTrack setting; do not infer module state from the whole-plugin switch.
2. Keep SecureTrack enabled. Read back and disable only the original track_visitors and track_pages settings, preserving other options and their native cache invalidation. The installed RC55.2 needs a reviewed targeted backport of the RC80 server-side timing gate before disabled beacons can be assumed cheap. Verify PhoneKey/passkey/recovery/IP-trust paths; no whole-engine override.
3. Compare uncached admin/database health and representative upload-file responses at low request volume. Correlate hosting errors, PHP/DB resource metrics, top paths and cache hit ratios. Keep data collection bounded; no load test on production.
4. Measure baseline, engine-enabled and visitor-tracking-enabled query/write counts on the disposable lab. Design limits for ordinary traffic logging, retention and diagnostic updates while preserving security-relevant authentication/abuse decisions.
5. Restore only the two original telemetry flags when requested; verify effective settings and retained security functions. No full-engine switch is part of this procedure.

A fixed high-traffic budget and any sampling/retention policy remain engineering work to review with measured evidence. Do not deploy an untested logging rewrite to resolve an unproven cause.
