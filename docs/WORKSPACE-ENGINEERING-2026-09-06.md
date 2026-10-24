# Workspace engineering record — 6 September 2026

**Subsequent 7 September update:** RC64 is installed with [grouped AI/SEO native and browser acceptance](RC64-LAB-ACCEPTANCE-2026-09-07.md). The current build priority moves to shared app UI; earlier entries below remain dated evidence.

**Subsequent lab upgrade:** [RC58 acceptance](RC58-LAB-ACCEPTANCE-2026-09-06.md) supersedes this record's RC56 installed-state snapshot. Preserve the earlier evidence below as historical.

Work unit: preserve continuing founder direction, make Categories/Teams client tasks concrete, establish deterministic reserve-session evidence, and validate a distinct RC56 candidate on the authorized Hostinger testing installation. [Founder Intent](FOUNDER-INTENT-REGISTER.md), [Task Contract](CLIENT-WORKSPACE-TASKS.md) and [Reserve Tasks](RESERVE-TASKS.md) are the maintained entrypoints.

Latest state: the lab remains on RC56; subsequent local source is RC57 in the [content operations batch](CONTENT-OPERATIONS-BATCH-2026-09-06.md). The browser adapter became available after the native Windows graphics failure described below. Browser category creation, rename and selected-post movement, plus team selection/title/order saves, completed with disposable records. Native readback passed all five resulting checks, including the preserved category slug and other relationship. Cleanup passed at `2026-09-06T01:26:02Z`. Teams was visually inspected at 390px and 1280px widths without document horizontal overflow; the complete phone form was visible at 390×600. The viewport override was reset. Category forms were exercised at desktop width. These are emulated browser checks in the existing unrestricted Administrator session, not physical-device or ownership-policy UI certification.

Final inventory after this browser pass found only `.htaccess`, `cleanup-result.json` and `browser-fixtures-result.json` in the denied lab directory. The extra fixture launcher removed itself, all synthetic records were removed, and all temporary lab cron jobs were deleted. No operational executable or upload archive remains in the public directory.

## Source and scope

Canonical Kiwe checkout remains on main, based on `731a94ff48dd350bb7b168ec285fcc96afbdaa01`. Existing local lead-transition changes in Kiwe, Key and Seam were preserved. The workspace verifier confirmed the expected branches, origins and handoffs; its clean-worktree check fails because these documented edits are still present. Source is uncommitted; nothing was pushed or published to a signed update feed. Ascendants is a separate production target and was not changed in this work unit.

RC56 adds `Access/Category_Workspace_Service.php`, `Access/Team_Workspace_Service.php` and two page-scoped CSS files; composition, client routing and Home task cards were updated. No new table, generic role grant or visitor script is introduced. Writes target native category relationships and the existing three public-team metadata keys. Home no longer invents health/security status; channel labels describe configuration or the recorded email test.

The installed artifact's loader/package declarations are `8.0.0-rc.56`. Its generated token catalog was regenerated through its declared tool for that version; its only semantic change is the plugin-version field. Its package manifest contains 359 files. That archive remains intact while local source advances. Existing immutable RC55 artifacts remain untouched. Seam's independent checked-in upstream snapshot remains pinned to its earlier declared revision; synchronize it through its own tool only after choosing the next canonical source revision.

## Verification

- PHP 8.4.23 / Node 22.23.1 local: 32 category behavior checks, 30 team behavior checks, 87 role-policy checks, 25 native-page/indexing checks and 13 admin-shell source contracts passed.
- The full Kiwe green baseline passed after regenerating the version-bearing contract catalog. The first RC56 run correctly detected that stale generated version; it was repaired through `packages/seam-contracts/tools/generate-types.cjs`.
- Package manifest generated last and `tools/release/verify-package.cjs` passed for all 359 files.
- `tools/maintenance/run-reserve-task.mjs` executes fixed recipes, records before/after source hashes and distinguishes passed, failed and inconclusive evidence. Receipts belong outside the ecosystem source directories. Test output receipts contain synthetic gate summaries, never raw provider or customer data.
- `tools/release/test-wordpress-workspaces.php` is an explicit CLI-only test fixture suite for a test-site launcher. It uses actual native users/terms/posts/menus and effective Kiwe permissions, blocks external HTTP/mail during fixtures, and removes its synthetic records. Local stubs and real WordPress evidence remain separate.

## Hostinger test installation

Target: `plum-giraffe-539524.hostingersite.com`. Initial inventory: WordPress 7.0.4; PHP web setting 8.3; actual CLI PHP 8.2.33; Bricks 2.3.10; WooCommerce 11.0.1; Kiwe RC10. Surface and Key were enabled, while RC10 had no current owner-workspace policy. This was an older environment, not a matching RC55 feature fixture.

Hostinger inventory flagged LiteSpeed Cache 7.9 with an available security update. Its update request returned HTTP 500, but independent follow-up inventory confirmed active 7.9.1, no pending update and no reported vulnerabilities. WordPress/core and other third-party upgrades were not performed.

Backups verified at `2026-09-06T00:14:02Z`: complete old MU package archive, 647 files, 2,622,311 bytes, SHA-256 `cd33624fa734ff3ddfb37ba3cc1dd726407bc196c98463a44b89deef63a10fe4`; native SQL export, 38,219,907 bytes, completion marker and SHA-256 `a17ffa5b8943477592815eb0659a9f250a658df0d407baa65de28ee81a1896d6`. Both remain in private host storage outside public_html, under `kiwe-private-20260906`. Database restore has not been rehearsed. Temporary database-client credentials were used only in that private host directory and removed by the export job.

Installed candidate archive SHA-256: `2d85e97b61a37b266f2aa2461f28028c0f8d0ae4ff788d78daea29bd10cd8b63`. The first Windows tar archive passed transport hashing but failed the host's `ZipArchive::CHECKCONS` with code 21 before any live file swap. It was rebuilt with .NET ZIP APIs; strict verification remained enabled. `tools/release/build-folder-copy-archive.ps1` now provides a repeatable unsigned folder-copy archive builder with entry readback/hash checks; archive bytes can differ between .NET runtime versions. Do not infer a signed-feed change from this tooling.

The deployment staged and hashed all 359 package files, linted PHP on the host, retained the prior directory, swapped the package and replaced loaders last. This is a package directory swap with individually replaced loaders, not a claim of an atomic whole-site transaction. First-boot/fixture failure invokes package rollback; database restoration is deliberately separate.

Installed RC56 at `2026-09-06T00:34:11Z`. All 17 real WordPress fixture checks passed on PHP 8.2.33, including native menu labels/custom overrides, category moves, team context/cache behavior and denial paths. Synthetic users, menus, categories and draft post were removed. The ownership policy was simulated by a process-local option filter for fixtures; no client/owner policy was activated on the installation. Package proof passed; rollback was not invoked. A database restore drill remains unperformed.

Independent HTTP reads verified the public package as RC56 and an uncached homepage as HTTP 200 without a critical-error page; lab tooling returned HTTP 403. A read-only diagnostic at `2026-09-06T01:10:03Z` verified that Raw Convert Test Mode is enabled, Accessibility Preview Mode is disabled and the surface is intentionally suppressed. Those settings were preserved for later Seam work. This does not certify enabled visitor DSA behavior.

The initial browser attempt confirmed page text, but native Windows capture failed with `SetIsBorderRequired failed: No such interface supported (0x80004002)` and indexed input lacked coordinate geometry. That attempt alone proved no interaction/visual acceptance. The subsequent functioning browser adapter and completed form checks are recorded at the top of this report.

Cleanup completed at `2026-09-06T01:11:01Z`. Temporary public-root executables, upload archive and lock directories were removed. Independent directory inventory found only `.htaccess` and the non-sensitive cleanup receipt under the denied lab directory. All temporary lab cron launchers were deleted; follow-up cron inventory found none remaining. Installation/fixture receipts were copied to private `evidence/`, and the original package, SQL export and prior directory were retained. Private `RECOVERY.txt` explains the prepared, uninvoked package-only rollback and the separate, unrehearsed database restore. None of these operational artifacts are part of the MU package.

## Next acceptance boundaries

The founder requested grouped implementation followed by combined verification. Continue the local RC57 content operations batch and group its remaining lab acceptance with the next related features. Category phone layout, physical devices and the activated ownership-policy UI remain unverified. When testing enabled DSA, record and restore the lab's conversion-test settings explicitly. Full Woo checkout, auth recovery, provider delivery, PWA/push, AdSense/popups, performance comparisons and database restoration remain independent checks. Native-menu membership, category indexing, Guest edit/resubmit and large-audience delivery remain planned work.

The founder's backup additions are recorded in [Backup and Media Strategy](BACKUP-AND-MEDIA-STRATEGY.md): provider-aware status, bounded local/remote backup and restore, design/settings snapshots, Drive archive storage and a separate active-media offload adapter. These remain a researched proposal; this deployment's private operational backups do not establish a shipped backup product.
