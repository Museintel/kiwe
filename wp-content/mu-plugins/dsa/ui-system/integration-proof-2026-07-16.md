# Kiwe UI System integration proof — 2026-07-16

This file is the release-readiness proof for the Seam/framework track before the final version bump and MU deployment test.

## Scope proven

- `ui-system/` is the portable design brain for Legacy, Kiwe 2027, and future marketplace themes.
- `seam-vocabulary.json` and `seam-vocabulary.md` define public Seam vocabulary, protected Kiwe shadow metadata, and the `appShellAdoption` map.
- `window.Seam` exposes vocabulary helpers, landmark discovery, and adoption lookup.
- `window.DSA.ui.seam` exposes the same AppShell-scoped inspection helpers.
- Bricks receives Kiwe universal tokens and curated Seam global class categories without becoming Kiwe/DSA authority.
- Theme packages remain CSS/assets/manifest only. They do not import PHP, JavaScript, htmx, Alpine, remote assets, service workers, cart/checkout/auth/Search/Bricks authority, or package manifests.
- Handoff previews are review-only and must keep mock data, preview JavaScript, and preview shell controls outside the importable package.

## Required proof commands

Run from the repo root:

```bash
node --check wp-content/mu-plugins/dsa/assets/js/seam.js
node --check wp-content/mu-plugins/dsa/assets/js/surface.js
php -l wp-content/mu-plugins/dsa/includes/Design/Seam_Vocabulary_Schema.php
node tools/ui-theme/audit-seam-adoption.cjs
node tools/ui-theme/validate-package.cjs tools/ui-theme/fixtures/valid
node tools/ui-theme/validate-handoff.cjs wp-content/mu-plugins/dsa/ui-system/handoffs/legacy-ui-review
node tools/release/verify-package.cjs
```

Regression fixtures must fail:

```bash
node tools/ui-theme/validate-package.cjs tools/ui-theme/fixtures/invalid-seam-class
node tools/ui-theme/validate-package.cjs tools/ui-theme/fixtures/invalid-seam-attribute
node tools/ui-theme/validate-package.cjs tools/ui-theme/fixtures/invalid-seam-shadow
```

## What passing means

Passing these checks means:

- the runtime JavaScript parses;
- the PHP Seam vocabulary source parses;
- the portable Seam vocabulary and production Seam CSS agree;
- public-adopted AppShell classes are applied only where approved;
- shadow-only roles are not accidentally promoted into live DSA public classes;
- the valid package fixture remains accepted;
- the Legacy handoff still satisfies the full preview/review contract;
- invalid Seam class, attribute, and protected-shadow usage remains rejected;
- runtime vocabulary checks cover both flat and nested contract shapes, and debug lint now catches shadow-only public classes inside live Kiwe roots;
- Bricks 2.4 beta's HTML-to-Bricks converter is treated as the review path for standalone framework previews, while Kiwe continues to push the framework itself through `Kiwe > Framework`;
- the MU package manifest matches the current package contents.

Passing does not mean a new visual theme is approved. Visual approval still requires reviewing the standalone preview and confirming selector fit, responsiveness, dock modes, sheet/classic modes, light/dark, absent optional data, FBT rail behavior, and no core/runtime authority drift.

## Final release boundary

After this proof passes, the next batch may be release prep:

1. bump the plugin version;
2. rebuild the package manifest;
3. verify the MU package;
4. deploy/upload the MU plugin folder to the Hostinger test site;
5. perform live smoke tests for dock, sheet/classic, Search, Cart, Checkout open, Profile, Links score absent/present, FBT rail, Bricks token export, and Seam inspection helpers.
