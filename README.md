# Kiwe DSA

Kiwe turns a conventional WordPress site into an appsite with one persistent Dual Surface Area: a responsive dock, contextual screens, private runtime hydration, PhoneKey identity, commerce, PWA/Push, notifications, analytics, and Bricks-first design integrations.

Kiwe is a must-use plugin. WordPress pages remain server-rendered and indexable; the Surface is an additive application shell, not a replacement theme or client-side rendering requirement.

## Supported Baseline

- WordPress 7.x
- PHP 8.2, 8.3, or 8.4
- HTTPS for passkeys, PWA, and Push
- WooCommerce when commerce modules are enabled
- Bricks 2.3.7+ for Bricks-specific controls; other themes retain the core Surface

Production support still depends on the real-host matrices in `docs/DEVELOPMENT-PLAN.md`. A passing source contract is not a substitute for gateway, browser, SMTP, proxy, cron, or cache testing.

## Canonical Install

Deploy only these two items from `wp-content/mu-plugins/`:

```text
dsa.php
dsa/
```

The root `dsa.php` is the MU loader. The nested `dsa/dsa.php` is the package entry point and must not be installed or activated as a separate normal plugin. Loader and package versions must match.

For Hostinger, use `docs/INSTALL-HOSTINGER.md`. For upgrades, incomplete uploads, emergency disable, and rollback, use `docs/RELEASE-RUNBOOK.md` and `docs/OPERATIONS.md`.

## Safe Defaults

- SecureTrack enforcement and automatic logout are off until deliberately configured and tested.
- First-session Home, idle Home, morph navigation, and offline editorial delivery pilots remain governed or off by default.
- Personalized identity, cart, authority, and nonce state hydrates through private no-store REST responses instead of cacheable HTML.
- Visitor-facing trust is rendered by deterministic services. AI may explain or suggest; it does not invent trust or authorize mutations.

## Release Integrity

`dsa/package-manifest.json` inventories every production package file by byte length and SHA-256. Runtime performs the full verification only when the release stamp changes or its cached proof expires. Missing, changed, or mixed-version packages disable Kiwe for that request without taking WordPress down.

Release commands:

```text
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
node tools/release/rc12-contracts.cjs
```

No ZIP is required for the canonical Hostinger copy workflow. Do not mix files from historical folders.

## Development And Verification

The architecture and decisions live in `docs/DSA-ARCHITECTURE.md`. The short execution truth is `docs/DEVELOPMENT-PLAN.md`; security acceptance lives in `docs/SECURITY-AUDIT.md`; the UI marketplace contract lives in `docs/DSA-UI-CONTRACT.md`.

Contract runners are grouped under `tools/`. CI checks PHP platform metadata across 8.2-8.4, package integrity, JavaScript syntax, and the established release contracts. Local PHP lint remains intentionally excluded unless the project owner explicitly resumes it.

## Emergency Disable

Rename `wp-content/mu-plugins/dsa.php` to `dsa.disabled.php`. Restore it only with a complete, matching loader/package release. Do not delete Kiwe tables or options as a routine rollback.
