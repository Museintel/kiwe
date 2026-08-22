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
- A fresh install opens the owner-facing `Kiwe > Onboarding` journey once. It keeps optional Kiwe runtimes disabled, writes identity/store values to their native WordPress/WooCommerce owners, and makes the additional SEO/design brief available through read-only SiteGraph.

## Release Integrity

`dsa/package-manifest.json` inventories every production package file by byte length and SHA-256. Runtime performs the full verification only when the release stamp changes or its cached proof expires. Missing, changed, or mixed-version packages disable Kiwe for that request without taking WordPress down.

Release commands:

```text
node tools/release/verify-green-baseline.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
node tools/release/rc12-contracts.cjs
```

`verify-green-baseline.cjs` is the mandatory source gate before a release commit. Browser accessibility and responsive geometry remain a separate Playwright-backed CI contract because they require Chromium.

No ZIP is required for the canonical Hostinger copy workflow. Do not mix files from historical folders.

## Development And Verification

The architecture and decisions live in `docs/DSA-ARCHITECTURE.md`. The short execution truth is `docs/DEVELOPMENT-PLAN.md`; security acceptance lives in `docs/SECURITY-AUDIT.md`; the UI marketplace contract lives in `docs/DSA-UI-CONTRACT.md`.

The next-generation deterministic HTML/CSS/JS-to-AppSite direction, including the SEAM Compiler (formerly BRX Shift), SiteGraph binding, Geometry inference, native Bricks compilation, visual proof, and controlled deployment, is defined in `docs/SEAM-APPSITE-PLATFORM.md`.

Contract runners are grouped under `tools/`. CI checks PHP platform metadata across 8.2-8.4 and runs the canonical green source baseline for package integrity, token purity, fixtures, toolkit/connector contracts, release contracts, and JavaScript syntax. Local PHP lint remains intentionally excluded unless the project owner explicitly resumes it.

### SEAM Compiler and rendered capture

The compiler plane now starts under `packages/`: strict Capture/Page/Behavior/Asset/Bricks Plan/AppSite schemas, generated TypeScript and PHP declarations, the deterministic IR compiler, a Bricks capability extractor, and the native Bricks serializer. It does not run on public WordPress requests.

```text
node packages/seam-compiler-core/test/compiler-foundation.cjs
npm ci --prefix packages/seam-capture --no-audit --no-fund
node packages/seam-capture/test/render-capture.cjs
node packages/seam-capture/tools/capture-page.cjs input.html output-directory
node packages/seam-bricks-adapter/tools/extract-bricks-capabilities.cjs /path/to/bricks packages/seam-bricks-adapter/profiles/bricks-version.json
node packages/seam-compiler-core/tools/compile-capture.cjs capture.json bricks-profile.json output-directory "Template title"
node packages/seam-capture/tools/summarize-evidence.cjs capture-directory compile-directory evidence.json
```

The former browser converter is quarantined under `packages/seam-bricks-adapter/scaffold/` and is not imported by the supported pipeline. Rendered capture is a compiler-plane job, never a WordPress request: it records computed style, cascade evidence, accessibility semantics, resources, screenshots, and a canonical responsive matrix before deterministic geometry and Bricks compilation. The M3 component compiler uses capability-proven direct, aggregate, semantic-layout, or review adapters instead of guessing. The National Chikki golden page currently compiles 279 source nodes to 267 native Bricks elements, including 12 sanitized native SVG elements, with zero Code elements, 100% semantic coverage, 99.9% native-control coverage, and three explicitly reported scoped-CSS declarations where Bricks 2.3.10 has no equivalent control. These are compiler metrics, not yet a claim of pixel-perfect visual proof.

### SEAM Framework package boundary

Kiwe 7.03 consumes SEAM Compiler 0.12.0 Framework packages. Raw `/convert /bricks` output remains self-contained and Framework-neutral. `/seamframework` then emits one project-wide Framework Profile, stable Bricks class IDs, profile-dependent templates, and `framework/audit-seamframework.json`. The profile must be pushed from Kiwe > Framework before its templates are imported. `/audit /seamframework` fails closed unless integration is 100%, source structure/content parity passes, every class and variable reference is installed, and each visual setting has one owner. Accessibility is deliberately outside this release gate and remains a later, separate phase.

## Emergency Disable

Rename `wp-content/mu-plugins/dsa.php` to `dsa.disabled.php`. Restore it only with a complete, matching loader/package release. Do not delete Kiwe tables or options as a routine rollback.
