# RC48 release and activation handoff — 3 September 2026

## Status: published, not installed

**Superseded before installation:** the user's subsequent native-Pages and Bricks requirement is implemented in RC49. Do not install RC48 for the revised workspace rollout. Its signed assets remain immutable.

- Canonical MU-plugin source is `8.0.0-rc.48`.
- Full green baseline passed, including the new client workspace gate. PHP lint passed for 167 files using the local PHP 8.4.23 runtime. This is not a PHP 8.2 runtime test or a complete live multi-user integration test.
- Signed candidate release published to `app.kiwelaunch.com` under account `u406734797`.
- The 1,499,141-byte remote release archive matched its signed SHA-256 before publishing `candidate.json`.
- The remote candidate metadata matched the local version, signature and archive hash.
- Ascendants' existing Developer updater independently verified RC48 and displays **Install Kiwe 8.0.0-rc.48**.
- Ascendants still runs RC47 with matching RC47 loader/package and runnable 349-file manifest. Automatic candidate updates remain off.
- No account, role, ownership policy or business content was changed. No cache purge was requested as part of this pending install.

## Pending live steps

The browser-use confirmation rule requires action-time approval before installing software and changing permissions. The current Chrome updater tab is retained for this handoff.

1. Install RC48 using the existing signed updater; verify the first-boot result, matching versions and runnable 350-file manifest.
2. Open Users → Access & ownership while signed in as `root`; activate that existing account as the sole Super Admin. Do not convert Ascendants to Multisite or delete accounts.
3. Map the one occupied Writer role (AscendantsDesk, user ID 2, 480 posts observed) to Author. Retire unused SEO Editor/SEO Manager roles. Preserve accounts, authorship and role recovery records.
4. Keep client Rank Math access at **Post editing controls only**.
5. Check the actual-account effective capability table, standalone business sections, owner full access and frontend. Validate client sessions when available; capability-table evidence alone is not a signed-in end-to-end session test.
6. Purge only appropriate runtime/page caches after installation, record the actual outcome, and leave Users open.

See [client workspace and ownership](client-workspace-ownership.md) for behavior, scope and recovery.
