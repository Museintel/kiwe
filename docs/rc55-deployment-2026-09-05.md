# RC55 deployment — 5 September 2026

## Targets and outcome

- Account: `u406734797`.
- Signed candidate feed: `https://app.kiwelaunch.com/updates/kiwe/v1/candidate.json`.
- WordPress site: `https://ascendants.in`, installation `17159603`.
- Installed version: `8.0.0-rc.55`.
- Release commit: `19634ec` (`Fix Key new-device passkey recovery`).
- The immutable release archive and manifest were independently verified before the signed candidate pointer changed.
- Ascendants received all 358 MU-plugin files through the scoped Hostinger upload channel. The nested package completed before the root loaders were written, preventing an observable mixed-version boot.

## Release proof

- Package bytes: `1,528,042`.
- Package SHA-256: `2be311c878a0186dd3acb18278351856b39ed91bbede857ceb3d486c96cc2a74`.
- Signing key id: `kiwe-release-2026-01`.
- The live candidate metadata matched the local version, byte count, digest and signature.
- The full local green baseline passed before publication, including release, runtime, identity, security, commerce, role, UI, compiler and strict PhoneKey-flow contracts; syntax checks passed for 19 browser JavaScript files, 57 tool files and 30 compiler-package files, and PHP 8.4 lint passed.

## Behaviour delivered

- Password creation and recovery remain under WordPress's single password authority, but the isolated private route now presents the site's Design Context logo and a responsive Key.kiwe interface instead of WordPress branding.
- The private password route is isolated from the public theme, advertising, analytics and referrer-bearing front-end application shell.
- A successful new-device recovery now distinguishes an account with an existing passkey from one without a passkey. Existing or synced credentials are asserted first; a new credential is offered only when the device actually needs one.
- Duplicate WebAuthn credential errors return the user to the existing-passkey path with useful guidance instead of exposing the browser's relying-party error.
- Embedded mail/app webviews are detected and offer an explicit full-browser continuation because passkeys and device trust are isolated by browser context.
- OTP destination wording is deterministic: an email identifier uses email; a phone identifier uses the configured WhatsApp/SMS lane; an enabled email fallback is disclosed rather than silently switching channels.

## Live validation

- Ascendants' manifest endpoint returned HTTP 200 and `8.0.0-rc.55`; the homepage returned HTTP 200 and referenced the versioned RC55 runtime.
- The live `surface.js` contained the assertion-first new-device flow and the embedded-browser guard.
- The live lost-password route returned HTTP 200 with `kiwe-key-auth`, Key.kiwe wording and the Ascendants site logo. A headless Chrome probe passed at 1280×800 and 390×844: the form remained within the viewport, no page errors occurred, and no AdSense or DoubleClick asset was present.
- A 390×844 Android Chrome probe found exactly one DSA surface and one runtime, then opened Search, Profile and Menu from the live header successfully. Its only console messages were page-owned AdSense slot-width warnings, not Kiwe errors.
- LiteSpeed accepted an independent purge retry. Hostinger's outer website-cache endpoint returned HTTP 500 on repeated attempts; this did not leave RC54 active because ordinary live page HTML, the installed manifest and the candidate feed already served RC55 with cache-keyed assets.

No posts, pages, roles, profile contacts, business data, notification preferences, SecureTrack policy, Key.kiwe transport settings, passwords or credentials were changed. No OTP was sent during deployment or validation.
