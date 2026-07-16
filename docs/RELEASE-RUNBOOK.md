# Kiwe Release Runbook

## Release Unit

A release is one indivisible pair:

```text
wp-content/mu-plugins/dsa.php
wp-content/mu-plugins/dsa/
```

Never combine a loader from one release with a package from another. `dsa/package-manifest.json` is generated, not hand-edited.

## Build Proof

1. Update both version declarations and release documentation.
2. Run all applicable source and UI contracts.
3. Run `node tools/release/build-package-manifest.cjs` last.
4. Run `node tools/release/verify-package.cjs` after the manifest is generated.
5. Record the version, file count, test results, and known live-proof gaps.

The canonical folder-copy deployment does not require rebuilding a ZIP.

## Upgrade

1. Back up the database, current root loader, and complete current `dsa/` package.
2. Keep SecureTrack enforcement and experimental delivery modes off during the first deployment proof.
3. Prefer uploading the new package to a temporary sibling directory and renaming it into place atomically when the host supports that safely.
4. If Hostinger File Manager cannot provide an atomic rename, use a quiet maintenance window. Upload the complete `dsa/` directory, then the matching root loader immediately.
5. Open wp-admin first. A mismatch or incomplete inventory must show an admin notice while ordinary WordPress remains available.
6. Run the smoke matrix below before enabling enforcement or pilots.

## Smoke Matrix

- Anonymous public page, post, archive, and no-JavaScript navigation
- Logged-in profile and PhoneKey entry/recovery
- Bricks editor and one Bricks-rendered public page
- REST runtime hydration and mutation rejection without Kiwe proof
- Woo add, quantity, remove, Cart, Checkout, Place Order validation, and one test gateway when Woo is active
- PWA manifest/worker and Push readiness when enabled
- Dock geometry at 320px, 390px, tablet, desktop, zoom, safe area, and browser chrome resize
- Search, Menu context, Saved, Links, AI notifications, dark/light mode, and browser back gesture
- Cron/readiness, debug log, frontend console, and production-quietness check

## Incomplete Upload Drill

On staging only, remove one non-entry package file after backing it up. The next uncached manifest verification must disable Kiwe, write a bounded diagnostic, show an admin notice, and leave WordPress usable. Restore the exact file and clear `dsa_package_manifest_proof` or wait for the release stamp/TTL before retesting.

## Rollback

1. Disable the root loader by renaming it if the site cannot boot.
2. Restore the previous root loader and complete package as one pair.
3. Do not manually delete options, tables, user meta, order meta, or Push/PhoneKey state.
4. Confirm the previous release can read state written by the newer release. If not, keep Kiwe disabled and use the reviewed forward repair rather than destructive rollback.
5. Repeat the smoke matrix, then re-enable governed features incrementally.

## Evidence Boundary

Source contracts prove package composition and invariant intent. RC13 owns live commerce/identity evidence. RC14 owns SecureTrack, Push vendors, Redis/no-cache, CDN/crawler/AdSense, incomplete-upload, fatal recovery, and final rollback certification.
