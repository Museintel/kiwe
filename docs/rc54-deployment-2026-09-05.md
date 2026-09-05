# RC54 deployment — 5 September 2026

## Targets and outcome

- Account: `u406734797`.
- Signed candidate feed: `https://app.kiwelaunch.com/updates/kiwe/v1/candidate.json`.
- WordPress site: `https://ascendants.in`, installation `17159603`.
- Installed version: `8.0.0-rc.54`.
- The immutable release archive and release manifest were uploaded and independently verified before the signed candidate pointer changed.
- Ascendants received all 358 MU-plugin files through the scoped Hostinger upload channel. The nested package completed before the two root loaders were written, preventing an observable mixed-version boot.

## Release proof

- Package bytes: `1,524,916`.
- Package SHA-256: `b9262d8eb20c95eeffc86a74036e5ccea6de066e0bf0a5097f861dbe28cb3dd2`.
- Signing key id: `kiwe-release-2026-01`.
- The live candidate metadata verified against Kiwe's pinned Ed25519 public key.
- The full local green baseline passed before publication, including identity, strict-flow, progressive-verification, roles, commerce, Guest, notifications, SecureTrack, DSA geometry, search, WordPress identity continuity, packaging and signed-update gates.

## Live validation

- Ascendants' manifest endpoint returned HTTP 200 and `8.0.0-rc.54`; the homepage returned HTTP 200 and referenced the versioned RC54 runtime.
- Live versioned assets contained the privileged password handoff, deferred OTP delivery, TOC history continuity and single approved-Guest presentation.
- `munaf.m.patni@gmail.com` resolved to the existing Administrator account in `privileged_setup` mode. Its WordPress-native one-time set-password message was accepted for delivery to the masked account mailbox; no password or authentication token was exposed in logs or release evidence.
- A 390×844 Android Chrome probe opened Search, Profile and Menu from the header with zero page errors.
- A second live mobile probe opened the reported article mid-page, confirmed the Menu sheet began at its own top, found three TOC targets, selected one, closed the sheet, retained the selected hash and placed the heading 81 px below the viewport top without a history rollback.
- LiteSpeed accepted its purge. Hostinger's outer website-cache endpoint returned HTTP 500 on repeated attempts; this did not leave the old runtime active because normal page HTML already references the cache-keyed RC54 asset and both the normal candidate URL and installed manifest report RC54.

No posts, pages, roles, Guest decisions, profile contacts, business data, notification preferences, SecureTrack policy, Key.kiwe transport settings or credentials were changed. The only user-specific action was issuing the requested native first-access password setup email.
