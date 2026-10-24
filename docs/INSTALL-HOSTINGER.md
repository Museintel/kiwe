**Current source and disposable lab, 25 September 2026:** RC165 is accepted on the lab; [its acceptance report](WORKSPACE-NAVIGATION-RC165-2026-09-25.md) records package and browser evidence. Ascendants remains verified RC55.4. The [Ascendants RC165 plan](ASCENDANTS-RC165-ROLLOUT-PLAN-2026-09-25.md) is prepared but no production upload, option write or code switch is authorized yet. Preserve exact RC55.4 recovery, keep Rank Math active and verify real Editor/SEO readback during the separately approved production batch. The historical notes below do not describe current installed versions.

**Latest, 8 September:** Mainline RC70 includes the native-tested schema correction. The targeted RC55.2 backport is deployed on Ascendants: 18 native checks, complete package, preserved settings, ready storage, no attempted schema alteration and debug flags off. The lab remains RC64; full browser admin acceptance remains pending completion of the existing sign-in flow. [Current deployment and continuation](SECURETRACK-DEPLOYMENT-2026-09-08.md). Older status paragraphs below are historical.

# Installing and upgrading Kiwe on Hostinger

**RC69 local schema correction:** [SECURETRACK-SCHEMA-RC69-2026-09-08](SECURETRACK-SCHEMA-RC69-2026-09-08.md) owns explicit SecureTrack schema maintenance, the new non-autoloaded attempt status, fresh-install setup, locking and failure behavior. Ordinary requests cannot run SecureTrack DDL. Restored Ascendants remains RC55; no new package is deployed. Native PHP 8.2/database acceptance and production recovery preparation remain required.

**Current source and lab: RC64.** [Grouped native/browser acceptance](RC64-LAB-ACCEPTANCE-2026-09-07.md) is complete: RC59–RC63 foundation checks and an eight-process race passed; RC64 fixes the native checklist form rendering blocker. Next is shared DSA/client-dashboard primitives, navigation and one complete native-data task. SEO features are paused. Source remains uncommitted/unpushed; Ascendants unchanged.

**Historical batch notes below record their then-current deployment state; they do not override RC64 above.**

**RC63 local update:** [Bounded checklist and acceptance](SEO-CHECKLIST-RC63-2026-09-06.md) passed grouped local verification; native installation/acceptance remains pending. Lab remains RC58. The next installation/acceptance batch combines RC59–RC63; use the disposable-lab previous-package retention exception and clean fixtures.

**Historical RC62 source / RC58 lab note.** Read [RC62 compatibility and native acceptance](SEO-JOBS-RC62-2026-09-06.md) before grouped installation. No routine full SQL/ZIP lab backup; retain the inexpensive previous-package directory, verify installed state, and clean synthetic artifacts as required by the maintainer contract.

**Disposable lab exception:** The founder waived routine SQL/ZIP backups on plum-giraffe-539524.hostingersite.com. Skip step 2's full backups there; retain the previous package during a swap and record settings readback. Use a shell cron to orchestrate PHP commands because PHP process execution is disabled. Remove temporary jobs/scripts afterward. [RC58 lab evidence](RC58-LAB-ACCEPTANCE-2026-09-06.md) records the successful flow. Production guidance below remains applicable.

Updated 6 September 2026. The [release runbook](RELEASE-RUNBOOK.md) and [MU-plugin handoff](KIWE-MU-DEVELOPER-HANDOFF.md) govern deployment and recovery.

## Source and package

Use the canonical Kiwe checkout on its required branch. The historical dsa-dual-surface tree and Downloads are not editable release sources. The deployment unit is the complete, version-matched MU package:

```text
wp-content/mu-plugins/
  dsa.php
  kiwe-incident-guard.php
  dsa/
    dsa.php
    package-manifest.json
    includes/
    assets/
```

WordPress discovers PHP loaders at the root of mu-plugins. The nested package is not a normal plugin. Do not use a normal WordPress plugin activation/deployment endpoint to install Kiwe.

Before deployment, select a unique release version, run applicable gates, regenerate the package manifest last and verify it. Keep published immutable releases unchanged. A folder-copy install and a signed feed release are distinct operations; signatures remain required by the feed/updater workflow.

For Windows folder-copy archives, `tools/release/build-folder-copy-archive.ps1 -OutputPath <absolute-new-zip-path>` verifies source and each archive entry using .NET ZIP APIs. The RC56 test deployment found a Windows tar-generated archive rejected by Hostinger's strict ZIP consistency check. Verify transport hash and the server-side inventory before swapping; never weaken ZIP validation to accept an archive.

## Upgrade procedure

1. Identify the exact site, installed Kiwe version, WordPress/PHP/theme/plugin versions, enabled features and available recovery access.
2. Back up the database and complete current MU package/loader pair to private storage outside the document root. Record backup verification without copying secrets or customer data into source or public evidence.
3. Upload the complete candidate to a sibling staging directory and verify every expected file hash. Prefer a controlled directory swap with a rollback record. Keep the old package recoverable.
4. If the host only supports ordered overwrite, use the runbook's quiet maintenance procedure: complete the nested package first, then replace matching root loaders. An ordered upload is not atomic and does not itself provide a backup or remove stale files.
5. Open wp-admin first. Verify package integrity, actual loaded version and ordinary WordPress availability before enabling any pilot.
6. Purge relevant caches and execute the runbook smoke matrix for the site's feature profile. Record what was tested, what failed and what remains unverified.

The existing `tools/release/upload-mu-plugin-tus.mjs` accepts credentials from environment variables and uploads package files before root loaders. Obtain credentials through the authorized Hostinger session; do not write them into commands, source or reports. Use its source directory and remote path deliberately.

## Fresh installs and acceptance

Fresh-install defaults disable optional features. A missing dock can be the correct disabled behavior. Confirm runtime diagnostics and enabled settings before expecting front-end Surface, Key, push or commerce output.

Validate PHP 8.2 compatibility separately from testing on a newer PHP runtime. Configure and prove individual channels before relying on delivery. Test ordinary public pages and navigation, signed-in access/recovery, Bricks, private hydration, and applicable Woo/PWA flows. Code presence does not certify payments, delivery, performance, advertising or popup coexistence.

## Emergency disable and recovery

Move `wp-content/mu-plugins/dsa.php` outside mu-plugins, or rename it to `dsa.php.disabled`. The resulting file must **not** end in `.php`. A name such as `dsa.disabled.php` still executes as a must-use loader and does not disable Kiwe.

The separate `kiwe-incident-guard.php` loader also remains active unless deliberately moved or renamed without a PHP extension. Record which entrypoints were disabled. The nested package can stay in place because WordPress does not discover nested MU files by itself.

Restore the previous complete package and matching loaders together. Preserve options, user metadata, transactions and factor state. If the previous version cannot safely read new state, retain the backup and use a reviewed forward repair. Verify recovery with the same relevant smoke checks.
