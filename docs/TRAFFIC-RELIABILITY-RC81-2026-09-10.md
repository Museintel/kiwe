**Follow-up:** RC82 now bounds daily retention and passes native lab SQL acceptance. Ascendants remains RC55.3; its retention backport is still pending. [Current development checkpoint](SECURETRACK-RETENTION-RC82-2026-09-10.md). This RC81 deployment record remains historical evidence.

# RC81 — traffic reliability and native telemetry acceptance

10 September 2026. Local candidate RC81 includes RC80 optional telemetry gates and replaces delayed severe-request denials with immediate HTTP 429. The original tarpit_enabled control is retained with an accurate label; no silent settings migration. SecureTrack, PhoneKey/passkeys, recovery, IP trust and existing enforcement remain available. RC81 is installed on the disposable lab; its narrow RC55.3 backport is installed on Ascendants.

## Reproduced risk and correction

An opted-in tarpit held a PHP worker for eight seconds per denied request. An attacker could accumulate occupied workers despite being denied. The actual helper failed two of twelve virtual-boundary checks before correction; all twelve pass afterward. The corrected response retains 429 and adds Retry-After: 60 and private/no-store. Inactive, monitor-only and headers-already-sent branches preserve their prior return behavior. This change does not add sleeps, queues or provider calls.

RC80 already reproduces and corrects disabled visitor callback/event work and stale timing beacons. The new native fixture measures those callbacks after WordPress bootstrap, checks IP lookup and PhoneKey/passkey function availability, and restores process-local settings projections without generating records. It does not exercise a physical passkey or concurrent web traffic.

## Audit boundaries and remaining risks

Current-source inspection: ordinary endpoint guard defaults to rate-limit only XML-RPC before authentication, so a generic visitor counter does not run on every homepage or Key request. WAF/IP block checks remain necessary reads. IP/session upserts belong to actual events, explicit trust mutations or opted-in visitor history. Schema work is explicit after RC55.2. The loader/settings normalization and optional cron diagnostics still need full-bootstrap profiling; security events can still trigger expensive context/brain queries. Enabled full visitor tracking, sustained attacks, third-party plugins and hosting limits are not certified by this batch.

A cached page can bypass PHP entirely; an uncached request, login, search or upload uses different resources. Acceptance must measure both and distinguish visitor counts, HTTP requests and concurrent PHP work. A 3–4k visitor capacity is not yet established. No production load test is authorized or scheduled; controlled measurements belong on the disposable lab.

## Deployment and recovery

RC81 native acceptance passed 23/23 checks with cleanup=true on PHP 8.2.33 / WordPress 7.1. The retry preserved its starting native options and returned the lab to its normal module policy; SecureTrack is loaded only inside the fixture when normally disabled. Keep the previous lab package directory; no full lab backup was taken. Ascendants RC55.3 was installed at 19:27 UTC on 9 September (10 September IST). The earlier whole-engine shutdown helper remains withdrawn.


## Local gates and identified follow-up

RC81 passes 63 local release gates, including 43 telemetry boundary checks and 12 denial-helper checks. Baseline: baseline-1788980753968-c0b205e4-1864-485e-b8bb-97f0d3f0f318.json. Artifact: 1,558,518 bytes, 384 entries/381 manifest files; SHA-256 eeb13d7fa63e73289cf3f9bf4e4f89e4f9bc94285b75a55d205646e18043684b. Native lint occurs before any lab swap.

The request-path audit also identified a remaining daily cleanup risk: event and completed/pending AI-queue deletion statements have no row cap. Session cleanup can delete parent sessions while page deletion is capped, potentially leaving excess page rows orphaned. This must be corrected and verified in a dedicated retention batch before broad high-traffic certification. Do not silently delete red evidence or remove rate-limit/security checks to meet a budget. No production cleanup query was executed in this audit.

A separate [RC55.3 backport builder](../tools/release/build-securetrack-rc55-traffic-backport.cjs) exports reviewed RC55 and overlays only the four pinned SecureTrack files. It keeps the 355-file production package shape and changes no PhoneKey/IP-trust source. Its exact inputs are frozen; any drift requires a new immutable version. The older RC55.2 builder now rejects mismatched inputs instead of packaging current mutable code under the already-published version. The backport passed 51 local gates and the same 23/23 native checks using a private lab mount without replacing the installed RC81 package. Artifact: 1,488,991 bytes, 358 archive entries/355 manifest files; SHA-256 80d37670e56dddb031fc96788d5d5d39c2a4444a0a40f4d89a10ec58826456f7.

Initial native RC81 acceptance passed 21 functional checks, including zero SQL for disabled callbacks, but failed settings preservation when loading the normally disabled module initialized defaults. The deployment automatically restored RC79. Kiwe main settings remained unchanged. The fixture was corrected to use a connection-local temporary native options table and request-local cache, preserving the original settings/cache on exit. The first receipt remains historical. Initial defaults created by the first attempt were not reconstructed from an unavailable pre-fixture snapshot; no claim of exact pre-attempt SecureTrack-option restoration is made. This occurred only on the disposable lab.


## Production verification and recovery record

Preflight verified every installed RC55.2 manifest file, SecureTrack/Key enabled, visitor/page tracking enabled and all debug flags off. A private settings snapshot and previous code package were retained outside public_html. The staged RC55.3 comparison permits changes only to the reviewed event/core/admin files and version metadata; PhoneKey, IP service and the schema correction stay unchanged. Native PHP lint passed before the swap.

After installation, only native stp_settings.track_visitors and track_pages changed to zero, with native cache invalidation/readback. All other SecureTrack values and the dsa_settings / pk_settings_v3 digests matched the preflight. SecureTrack/Key stayed enabled; passkey and IP trust handlers loaded; debug remained off; complete package verification passed; 100 ordinary page-view logger calls executed zero SQL. No SecureTrack table DDL was attempted. These are post-bootstrap callback results, not full-request throughput or a physical passkey login.

A cache-miss homepage returned 200 with expected site content, one upload JPEG returned 200, and signed-out wp-admin returned 302 without a database error. No full cache purge was needed; existing cached/open pages may send old timing beacons, now discarded before optional logging work. No production load test, table optimization, database restore or content change was performed. This mitigation does not prove what caused Hostinger’s earlier outage.

The private recovery directory kiwe-traffic-recovery-20260910 retains the previous RC55.2 package and settings-before.dat with restrictive permissions. If rollback is necessary, restore only reviewed previous code and the two telemetry fields, respecting any later operator changes; never restore an entire database for this code/option update. Normal operation does not read this recovery snapshot. Sanitized receipts are in the task workspace work/rc81-evidence/production.json and production-public.json; credentials and private settings remain on the host.


Final cleanup removed 35 exact helper/archive files across the three lab working directories and the production working directory, including the cleanup helper itself. Protected sanitized receipts and private recovery copies remain. The account cron inventory was empty after removal of the temporary cleanup job. Final documentation and expanded telemetry reserve recipes passed; the runtime package was unchanged after its 63-gate baseline.
