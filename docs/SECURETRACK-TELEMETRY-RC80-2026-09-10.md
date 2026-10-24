**Follow-up:** RC81 includes this correction; native acceptance passed and RC55.3 is live on Ascendants with only visitor/page tracking off. [Current deployment and evidence](TRAFFIC-RELIABILITY-RC81-2026-09-10.md). The RC80 local-only status below is historical.

# RC80 — optional traffic logging without disabling security

10 September 2026. Local candidate **8.0.0-rc.80**; disposable lab stays RC79; Ascendants last verified RC55.2. No live switch change, deployment, signing, commit or push. Work remains on main at committed HEAD 731a94ff48dd350bb7b168ec285fcc96afbdaa01; unrelated accumulated work is preserved.

## Founder contract and problem

SecureTrack stays enabled. Reduce optional ordinary visits and navigation/timing history; preserve PhoneKey, passkeys, recovery, trusted-IP handling, enforcement and security audit events. This supersedes the previous whole-engine shutdown interpretation. The earlier temporary attempt and cron jobs were already removed; its local operator template is now withdrawn too.

Existing settings remain authoritative: stp_settings.track_visitors, track_pages and exclude_admin. Dashboard activity retains its separate existing track_admin_activity setting. No second control, storage authority, new schema, scheduler or automatic traffic detector was added.

Before this correction, the footer could emit a timing beacon when visitor tracking was off. Existing tabs sent it every 15 seconds. The endpoint did not recheck either tracking setting, so disabling tracking could leave rate-limit and page/session queries running. Disabled visitor callbacks also wrote diagnostic state, and direct ordinary page-view logging reached table/IP work before checking exclusions.

## Behavior

- Ordinary page-view events return before table/IP/session/diagnostic work when visitor tracking is off or the administrator is excluded. Security-labelled/named page events are retained; unfamiliar event fields are conservatively kept for audit.
- The ordinary visitor callback exits before its diagnostic, IP counters or event work when disabled/excluded.
- Public timing requires both visitor and page tracking. Excluded administrators do not get public timing scripts. The honeypot stays available under its own protection setting.
- Already-open or cached pages may still send beacons, but disabled timing is discarded with HTTP 204 before nonce/rate-limit/database work. When public tracking is off, anonymous beacons stay suppressed even if dashboard activity is enabled.
- Explicitly enabled dashboard timing remains available to signed-in actors for the native admin URL. Its existing nonce/rate-limit/database path is retained when permitted.
- PhoneKey source, IP resolution/trust source and the authentication/enforcement hooks are unchanged. pk_auth, pk_auth_fail, native login/logout, WAF, protection, behavioral and content/configuration audit events remain outside the optional ordinary-visit gate.

The native Tracking form explains the existing switches and their effect. There is no production engine-disable helper in this candidate.

## Verification

The deterministic harness executes the actual event class, native visitor/footer/exit callback source and PhoneKey pk_log against synthetic WP/SQL boundaries. RC79 reproduced **13 failures out of 43 checks**. RC80 passes **43/43**. Two 4,000-call loops verify no optional telemetry side effects at the named boundaries when disabled; they are not concurrent web traffic or a database throughput benchmark.

Checks include all public tracking flag combinations, excluded administrators, retained honeypots, enabled public/admin timing, disabled stale beacons, invalid nonces, security-labelled page events, and PhoneKey passkey success/failure, OTP/TOTP failure and recovery-block audit dispatch. The retained event checks establish entry into the existing security path, not full security-engine execution or real WebAuthn/provider/browser acceptance.

Run: node tools/runtime/securetrack-telemetry-behavior.cjs. Focused reserve recipe: telemetry. Full release gate and manifest checks are recorded in the final receipt below. External evidence: work/rc80-evidence/before.json and the source-bound reserve receipts.

## Rollout and remaining limits

No WordPress settings were modified. Local code is not installed on either live site. When hosting is reliable, first verify this candidate's native PHP 8.2/WordPress behavior on the disposable lab, then review an Ascendants RC55.2 backport without unrelated candidate features. Use the original two tracking controls and native invalidation; keep the engine/protection profile active. Verify native saved settings and PhoneKey/passkey/recovery/IP-trust continuity.

Already-open clients still have HTTP/PHP startup overhead until refreshed. The full engine's enabled security checks, security audits, optional enabled visitor tracking and diagnostic/geo/brain work are not globally budgeted by this change. No sampling policy or autonomous peak-hours switch is implemented. Compare actual cache/PHP ratios, DB queries/writes, memory and worker limits on a controlled lab before promising 3–4k simultaneous visitors or attributing the incident to Kiwe.

No schema/data migration or content deletion. A package rollback restores the previous implementation; reverting telemetry settings requires restoring only their original values. UI/Design Context, PWA runtime, Key control plane and Seam behavior are unaffected except generated Kiwe version metadata. The completed RC79 PWA report remains authoritative. Authentication/session/provider acceptance remains next; the clarified telemetry concern adds a bounded correction, not a restart of the launch roadmap.


Final local verification: **62 release gates passed**, including the 43 telemetry checks and the existing PhoneKey/security gates. All three changed PHP runtime files linted cleanly under PHP 8.4.23. Package manifest verifies 381 files. PhoneKey core and SecureTrack IP service hashes match their pre-change values. Baseline receipt: baseline-1788979616455-439fd144-0d0c-4297-89ff-3abdc198f986.json. PHP 8.2/native WordPress and live login/provider acceptance remain pending; no production settings were changed.

Built local folder-copy archive: 1558417 bytes, 384 entries/381 manifest files; SHA-256 d3cf35d8759d64956889e59c2b54bcb09f335abe5abf501d8002678d27893c0d. Archive entries were compared byte-for-byte to their source by the existing builder. This unsigned lab candidate has not been installed.
