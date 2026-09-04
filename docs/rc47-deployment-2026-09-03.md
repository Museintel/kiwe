# RC47 deployment — 3 September 2026

## Targets and outcome

- Account: `u406734797`.
- Signed candidate feed: `https://app.kiwelaunch.com/updates/kiwe/v1/candidate.json`.
- WordPress site: `https://ascendants.in`, installation `17159603`.
- Installed version: `8.0.0-rc.47` (previously RC46).
- Deployment used the existing Kiwe Developer **Check signed feed now** and **Install Kiwe 8.0.0-rc.47** controls, not manual replacement through Hostinger File Manager.
- Kiwe confirmed a verified boot, matching loader/package versions, a runnable 349-file manifest, and RC47 as the last build installed by the updater.

## Release publication

The existing release signer produced a 1,489,270-byte package. Only the RC47 archive and release manifest were uploaded first. The remote archive SHA-256 matched the local signed metadata before the candidate pointer was published. The remote candidate metadata matched the local signature/version, and WordPress independently accepted the signed feed.

## Validation

- Full local green baseline, PHP lint and native Design Context regression tests passed before release.
- Live Design Context shows seven contiguous steps with no Store or Resources form on this non-commerce site.
- Services is a separate, initially disabled opt-in. Revealing/hiding its fields was tested without saving changes.
- Review links to WordPress Media Library as the design resource source.
- Story links public-team management to WordPress Users.
- Existing staff profile shows admin team selection and title immediately below Role, plus public LinkedIn contact field.
- New-user form reveals team controls for Author and hides them for Subscriber; no account was created or modified.
- WordPress Users list has the Public team column and was left open in Chrome.
- Live onboarding JS, profile-panel JS, Surface JS and Surface CSS matched local RC47 files after line-ending normalization.
- Public homepage returned HTTP 200 and referenced RC47 assets.

## Cache outcome and limits

Both Hostinger cache endpoints returned HTTP 500, so a Hostinger/CDN purge cannot be claimed from their API responses. WordPress subsequently displayed a LiteSpeed success notice. Kiwe's scoped cache control explicitly completed its runtime and detected LiteSpeed page-cache layers: **2 completed, 0 skipped, 0 failed**. The runtime epoch advanced to `1788378913`. The entire WordPress object cache and unrelated database/content cleanup were not selected.

No business details, team memberships, user roles, profile values, posts or media were changed for validation. Automatic candidate updates remain off; the existing manual updater was used. The legacy administrator's incomplete strict-enrollment notice remains present and was not bypassed by changing security policy; maintenance used the existing recovery route and Hostinger temporary sign-in.

See [native Design Context guide](design-context-native-records.md) for field ownership and public-data boundaries.
