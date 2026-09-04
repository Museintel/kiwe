# RC53 deployment — 4 September 2026

## Targets and outcome

- Account: `u406734797`.
- Signed candidate feed: `https://app.kiwelaunch.com/updates/kiwe/v1/candidate.json`.
- WordPress site: `https://ascendants.in`, installation `17159603`.
- Installed version: `8.0.0-rc.53`.
- The release archive was published before the candidate pointer and independently downloaded for an exact byte-count and SHA-256 check.
- The restarted browser no longer had an authenticated WordPress admin session, so the same verified RC53 MU-package files were installed through Hostinger's authorized file channel rather than weakening Key.kiwe or SecureTrack controls to reach the updater UI.

## Release proof

- Package bytes: `1,520,433`.
- Package SHA-256: `fb19ef82fcc1801f362f58aca72ed8beabc6462dd3534a600009cbc8d875f28c`.
- Signing key id: `kiwe-release-2026-01`.
- Remote candidate version: `8.0.0-rc.53`.
- Full local green baseline passed before publication, including the role-aware workspace contract and the existing runtime, compiler, packaging, PHP, PhoneKey, commerce, security, and Design Context gates.

## Live validation

- Ascendants' public homepage returned HTTP 200 after deployment and cache purge.
- The new `workspace-admin.css` asset returned HTTP 200 from the live MU-plugin path and contains the RC53 `kiwe-workspace-admin` shell rules.
- The Workspace shell remains server-rendered and wp-admin-only; it adds no frontend JavaScript or public-page asset tax.
- Super Admin remains on the classic full WordPress administration surface. The role-aware Workspace applies only to signed-in non-owner roles and preserves WordPress capabilities as the authority.
- Hostinger site/CDN cache and WordPress LiteSpeed cache both accepted purge requests.

No posts, pages, users, roles, business data, plugin settings, credentials, or Key.kiwe/SecureTrack policy values were changed during deployment.
