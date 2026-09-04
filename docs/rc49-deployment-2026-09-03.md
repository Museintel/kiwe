# RC49 preparation — 3 September 2026

## Pending release

This supersedes RC48 before installation, incorporating the user's clarified native Pages and Super Admin-only Bricks requirements. No live website, ownership, indexing, role, page content or cache changes were made in this revision turn. The last observed Ascendants build is RC47. RC48 is published but was not installed by this task.

- Canonical source: `wp-content/mu-plugins`, loader/package `8.0.0-rc.49`.
- Manifest: 351 files.
- Locally signed candidate package: 1,501,786 bytes. RC49 assets and candidate pointer have not been uploaded; the remote feed remains RC48.
- Local PHP lint: 168 files passed using PHP 8.4.23 (not an assertion of an actual PHP 8.2 runtime run).
- 82 policy tests, 25 native Pages/indexing tests and 5 permission checks executing the supplied Bricks 2.3.10 classes passed. WordPress I/O is stubbed; live role sessions still need validation.
- Design Context tests cover native page inference, Rank Math indexing inference and preservation of legacy planning notes.
- The complete green-baseline release suite passed after the final changes. The new Design Context page fixtures required additional WordPress I/O stubs; after adding those, the full suite was rerun successfully.
- Actual Bricks 2.3.10 source was inspected in the user's Downloads folder; no vendor/theme file was edited or copied into the plugin.

## Rollout after confirmation

Publish the signed RC49 candidate assets to `app.kiwelaunch.com` on `u406734797`, then use the existing Ascendants Kiwe Developer updater. Verify its package/loader versions and boot result before activating the client policy. Set current account `root` as sole owner; map Writer to Author and retire only the reviewed unused SEO roles, preserving all accounts/posts. Keep Rank Math inline-only for the client. Verify native Pages, independent business sections, effective permissions and frontend; purge appropriate caches and leave Users open.

The Hostinger and browser-use skills require the specific deployment/permission-change confirmation. RC48's pending install approval was not treated as approval for a different revised package.

See [ownership and native Pages behavior](client-workspace-ownership.md).
