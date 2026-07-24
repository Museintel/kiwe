# Kiwe DSA Security Audit

**Reconciled:** 2026-07-03  
**Code baseline:** `0.5.61`  
**Scope:** canonical MU-plugin package, Surface REST/runtime, PhoneKey, SecureTrack, Woo commerce, PWA/Push, Saved, Analytics, controlled editorial delivery, and edge contracts.

## Current Security Boundaries

- The MU loader fails open and records Kiwe-owned startup failures rather than taking down WordPress where recovery is possible.
- REST writes have route-level permission callbacks, explicit Kiwe mutation proof, hostile cross-site rejection, and bounded rate limits. Live browser/CORS certification remains open.
- PhoneKey privileged enrollment requires recent WordPress password proof; privileged WebAuthn requires user verification; role elevation/revocation invalidates stale assurance and trusted-device state.
- OTP, TOTP, backup, passkey, and resend paths have purpose/flow binding and throttling. SMTP handoff and delivery are reported separately.
- SecureTrack is off/monitor-only by default, resolves trusted proxy headers only from approved proxy ranges, fails open on boot problems, and retains break-glass recovery.
- WooCommerce remains authoritative for cart, coupon, tax, checkout, payment, order, and refund state. Client UI never becomes money authority.
- Push secrets use the versioned secret store, subscription removal is user/visitor-owned, and VAPID rotation moves affected devices into explicit re-enrollment.
- Saved anonymous activity remains aggregate-only; registered-user collections require authorized access.
- Editorial morphing and offline editorial caching are gated and exclude protected, personalized, transactional, builder, and unknown routes.
- Public manifests/APEX profiles expose contracts and classifications, not secrets, filesystem paths, private user data, or host internals.

## Open Production Proof

### Pre-1.0 Confirmed Code Work

The July 2026 whole-codebase review added the following code-level release gates ahead of live proof:

| Boundary | Required correction |
|---|---|
| REST/AJAX mutations | **RC1 code complete in 0.5.12.** State-changing DSA/PhoneKey REST requests require `X-Kiwe-Mutation`; hostile cross-site signals are rejected; authenticated routes retain WordPress nonce/capability identity. Live browser regression remains in RC13/RC14. |
| Push subscriptions | **Ownership complete in 0.5.12; crypto recovery complete in 0.5.15; worker renewal complete in 0.5.18.** Save/remove requires current user or Kiwe visitor identity. Worker-only renewal uses a rotating one-way capability bound to the old endpoint and preserves its owner. VAPID key loss marks devices for re-enrollment; returning browsers replace mismatched subscriptions. Live vendor/browser delivery remains RC13/RC14. |
| Rewards | **RC2 code complete in 0.5.13.** Server elapsed time owns the minimum, tokens are consumed once, identity/IP completion is serialized, client score is marked untrusted, coupon issuance has a daily budget, and pair reconciliation rejects duplicate/overlapping affected products. Live Woo and concurrency proof remains RC13. |
| Account recovery identity | **RC3 code complete in 0.5.14.** New profile email addresses remain pending until OTP ownership proof. Privileged changes require current-password step-up; tokens are encrypted/atomic/stale-bound; completion reconciles the PhoneKey factor and revokes remembered trust, other sessions, and outstanding recovery challenges without deleting passkeys. Live SMTP, device, TOTP, backup-code, and session tests remain RC13. |
| Secret storage | **RC4 code complete in 0.5.15.** New writes use versioned Sodium secretbox or AES-256-GCM and fail closed. Legacy base64-equivalent values are read only for migration. Non-secret key IDs diagnose rotation; optional previous encryption keys support recovery. PhoneKey identity-HMAC mismatch is a critical state that requires restoring original WordPress salts. Live rotation/recovery remains RC14. |
| Cache isolation | **RC5 code complete in 0.5.16.** Reusable shell HTML is neutral; nonce, identity, cart/profile, role visibility, admin capability, protected flow, and personalized commerce hydrate from a same-site private/no-store endpoint. Bricks obtains mutation nonces just in time. Live CDN, Woo session, and logged-in bypass proof remains RC10/RC13/RC14. |
| Shared-host abuse controls | **RC6 code complete in 0.5.17.** Hot REST and analytics dedupe windows use atomic persistent-cache counters or one bounded SQL row per logical bucket, with expiry cleanup and readiness diagnostics. Unchanged abandoned-cart writes are heartbeat-bounded and retained analytics/cart rows have scheduled cleanup. Live Redis/no-cache contention and retention proof remains RC13/RC14. |
| Runtime bootstrap | **RC8/RC12 code complete in 0.5.25, cache-safe runtime hydration corrected through 0.5.50, coherent package version bumped in 0.5.51, token-aligned through 0.5.52, behavior-corrected through 0.5.53, controlled htmx-Alpine packaging proven in 0.5.54, and the broad htmx/Alpine pilot retired in 0.6.34.** Loader/package drift and a generated SHA-256 inventory disable Kiwe without taking down WordPress. Full verification is release-stamped and cached for 12 hours; diagnostics distinguish missing from changed files. Migration writes are explicit and idempotent, autoloading is exact and scan-free, admin-bar suppression is route/setting gated, and new direct PhoneKey/SecureTrack global consumers fail the source contract. The bootstrap keeps REST nonce, PhoneKey identity, cart, protected-flow, and personalized commerce out of cacheable HTML; current-host same-site requests remain accepted for Hostinger temporary/staging domains; private no-store hydration supplies live runtime state. Live partial-upload, option-store, and rollback drills remain RC14. |

These findings do not invalidate the existing fail-open loader, proxy resolver, protected-flow, or encrypted Push work. They define the remaining trust boundary needed before the plugin can call itself 1.0.

| Gate | Required evidence |
|---|---|
| Commerce | Live gateway, HPOS, tax, pair-discount overlap/reversal, Add & Save, checkout errors, refunds, and analytics reconciliation. |
| PhoneKey | Real passkey devices/browsers, role escalation/recovery, proxy identity, OTP/TOTP/backup recovery, SMTP and inbox delivery. |
| SecureTrack | Hostinger/Cloudflare provenance, enforcement recovery, CSP report-only observations, cron/scanner health, and break-glass drill. |
| Push | VAPID/OpenSSL, subscription renewal/removal, server cron, vendor/browser delivery, owner alerts, and denied/revoked permission behavior. |
| Runtime | No-cache and persistent-cache profiles, production quietness, safe-area/back-gesture behavior, and one staging loader-failure drill. |
| APEX pilots | S16-S17 matrices before any pilot is broadly enabled. |

## Remaining Hardening Tasks

- Add optional PhoneKey step-up for destructive/high-risk SecureTrack administration.
- Add explicit audit events for security-sensitive Kiwe settings changes.
- Finish health reporting for SecureTrack tables, cron, scanner permissions, and Push transport.
- Complete release-time dependency/package inventory and rollback verification.
- Keep future cross-site identity, partner, POS, and edge features behind separate privacy and threat-model reviews.

## Non-Negotiable Rules

- No AI decision controls visitor-facing trust, payment, authentication, or enforcement.
- No protected route is admitted to fragment/offline/shared-cache behavior by inference.
- No raw phone, email, IP, secret, or private selector is added to public registries, manifests, analytics, or edge profiles.
- No production gate is marked complete from static inspection alone.
