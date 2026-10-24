# RC55.4 production retention backport and lab workspace setup

10 September 2026. The founder authorized the next production backport and reported reaching the standard dashboard after signing into the testing site. Development remains RC82; this is a scoped production package and configuration investigation, not a new full development release.

## Backport scope and evidence

The [frozen RC55.4 builder](../tools/release/build-securetrack-rc55-retention-backport.cjs) exports reviewed RC55 and overlays the four pinned SecureTrack source files. Compared with installed RC55.3, only securetrack-core.php and version metadata change. PhoneKey, IP trust, schema prevention, optional-telemetry gates and immediate denial remain unchanged. No pending client-workspace or AI features are included.

Artifact: 1,491,043 bytes, 358 archive entries/355 manifest files. SHA-256 8a5e4ba929ac0669164431f03c855018eed7419df60ae7da1cbba52cbd8b9bd1. All 52 backport release gates passed. The private lab mount passed the same 19 native retention checks as RC82, with cleanup=true on PHP 8.2.33 / WordPress 7.1. The actual lab installation remained RC82 and all normal settings were preserved. The [RC82 record](SECURETRACK-RETENTION-RC82-2026-09-10.md) defines row budgets, backlog behavior and measurement limits.

Ascendants preflight verified its exact RC55.3 package, SecureTrack/Key enabled, visitor/page tracking off and all debug flags off. The private previous-code and settings recovery directory is kiwe-retention-recovery-20260910 outside public_html. Stage comparison accepts only cleanup-code/version changes. Native PHP lint precedes installation. Deployment verification rejects unexpected SecureTrack DDL or deletion queries; it does not execute real production retention or change settings. Code rollback does not restore the database or replay old settings.

RC55.4 was installed and verified at 06:40 UTC on 10 September. The complete package passed verification; stp_settings, dsa_settings and pk_settings_v3 were preserved, as were debug-off policy, SecureTrack/Key, passkey handlers and IP trust. Disabled ordinary page-view callbacks still executed zero SQL after bootstrap. No unexpected SecureTrack DDL or retention deletion was attempted. A fresh cache-miss homepage returned 200 and expected Ascendants content; signed-out wp-admin returned its login redirect without a database error. Keep source, built, installed, verified and signed status distinct. No production load test, database restoration or content change is part of this release.

## Lab workspace setup and cleanup

The initial lab readback had ownerPolicy=false, explaining why an unconfigured Administrator saw the standard dashboard. The founder subsequently authorized keeping the usual setup account as Super Admin and creating a separate temporary client Administrator. Ownership is now active, the client has logged in normally to Kiwe Home, and a categorized native draft was saved, read back and removed. Desktop/phone Home was inspected and the client session is left open. [Full account, security, evidence and remaining Woo integration scope](CLIENT-LAB-SETUP-2026-09-10.md).

At 06:43 UTC the production/backport cleanup receipt confirmed 16 exact helper/archive removals across the two scoped work directories. Protected receipts and private previous-code recovery remain. All four backport jobs were removed. The subsequent account setup helper also removed itself, its job was deleted and the final account cron list was empty.

## Continuation

The next cohesive runtime batch closes WooCommerce's literal-role incompatibility with the Kiwe owner and the missing client Administrator product permissions, with owner/client denial checks and grouped native/browser acceptance. Then continue full-bootstrap/security-event profiling and supported identity/provider and launch gates. The client setup pass does not prove physical passkey/provider success, product management or simultaneous visitor capacity. No signed release, commit or push is implied.
