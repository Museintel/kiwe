# Kiwe DSA

**Current priority, 11 October — Dharwad design accepted:** [Native authored migration](docs/DHARWAD-DESIGN-MIGRATION-2026-10-11.md) publishes the preserved R49B/R52 layouts and enables the existing requested Munaf account through native Kiwe ownership. Product/article bindings, shop filters, homepage recommendations/pairings and phone/header behavior pass scoped native/Chrome checks; original business hashes and recovery custody remain intact. Signed RC311 is unchanged, and all migration helpers/jobs are retired. **39 actual attempts / 25 accepted tasks; two Woo milestones and four deferred Ascendants milestones remain. Next batch: task 21, live store operations and role/UI acceptance; task 22 follows with reliability and monitoring readiness.** Lab RC277 and Ascendants RC55.5 remain unchanged. No customer charge/send, feed publication, commit or push.

**Prior Ascendants forecast — historical subset:**

**Current priority, 8 October — original run 7 / attempt 2:** [RC296 release preparation](docs/ASCENDANTS-RELEASE-PREPARATION-RC296-2026-10-08.md) completes private signing (eight checks), inert current-source review (75 checks), exact original previous-package body revalidation and independent native/public retirement. Historical A remains failed on one changed ciphertext ctime; its binding is preserved, with separate current revalidation. Ascendants remains RC55.5 with Rank Math active; served lab RC277. Complete offline authentication awaits the founder's faster-connection instruction; actual-source restore/reversal requires another qualified destination because only 27,432 account entries remain for at least 138,095 source files. Run 7 stays **in progress**. **Two tasks through upload, four through live acceptance, sixteen overall including twelve Woo tasks; sixteen actual attempts/fourteen accepted tasks.** [Ledger](docs/generated/kiwe-delivery-run-ledger-2026-10-05.json) owns counts. STSEO-8 source acceptance and its retained numerical latency failure remain at their disclosed scope. No owned job/public helper/transfer process remains; two tiny synthetic local folders remain after automatic approval review blocked cleanup.

**Historical RC158 checkpoint, 24 September:** Then-current local and disposable-lab runtime: [RC158 tax classification](docs/TAX-CLASSIFICATION-RC158-2026-09-24.md) is accepted on the lab under PHP 8.2.33 with RC157 privately recoverable. The corrected 426-file package passed the local baseline, 78 responsive React checks, 23 native WooCommerce checks and authenticated client-role browser review. Temporary public staging/jobs are retired. Ascendants production remains RC55.4 and awaits its own explicit deployment approval. [The backend forecast](docs/LAUNCH-BATCH-FORECAST.md) is 0 non-WooCommerce, 5–7 WooCommerce and 5–7 total; 13–21 overall.

Historical runtime: [RC156 category-aware publisher alerts](docs/NOTIFICATION-PUBLISHER-ALERTS-RC156-2026-09-21.md).

Earlier AI checkpoint: [RC150 client historical SEO acceptance](docs/SEO-HISTORICAL-WORKSPACE-RC150-2026-09-20.md) connects bounded historical progress, control and proposal review to the role-aware SEO document without spending allowance on inspection or exposing provider credentials.

The public SEAM command entrypoint is [start.kiwelaunch.com](https://start.kiwelaunch.com/). Browser AI clients that cannot yet open a newly indexed domain may read the same canonical start contract from [KIWE-START.md](https://github.com/Museintel/kiwe/blob/main/KIWE-START.md); the live registry publishes both locations and immutable release hashes.

Incoming maintainers should start with the [ecosystem handoff](HANDOFF.md), then use the [Kiwe MU-plugin developer handoff](docs/KIWE-MU-DEVELOPER-HANDOFF.md) for source-level ownership, runtime wiring, release gates and recovery.

The [Product Vision](docs/PRODUCT-VISION.md) captures the founder's client-first direction. The [Engineering Roadmap](docs/ENGINEERING-ROADMAP.md) owns current priorities, and the [Maintainer Contract](docs/MAINTAINER-CONTRACT.md) requires every contributor to keep behavior, architecture, verification and handoff evidence aligned. Read the [lead-transition baseline](docs/ENGINEERING-BASELINE-2026-09-05.md) for the latest local audit and its limits.

Kiwe turns a conventional WordPress site into an appsite with one persistent Dual Surface Area: a responsive dock, contextual screens, private runtime hydration, Key identity, commerce, PWA/Push, notifications, analytics, and Bricks-first design integrations.

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

- The clean RC settings profile disables every optional runtime and enables only read-only SiteGraph.
- AppShell, Dock, PhoneKey, messaging, commerce enhancements, SecureTrack, AI services, PWA, Push and Bricks adapters are enabled deliberately after onboarding and testing.
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

The architecture history and decisions live in `docs/DSA-ARCHITECTURE.md`; the current implementation map is `docs/KIWE-MU-DEVELOPER-HANDOFF.md`. Current priorities live in `docs/ENGINEERING-ROADMAP.md`; `docs/DEVELOPMENT-PLAN.md` preserves earlier execution history. Security acceptance lives in `docs/SECURITY-AUDIT.md`; the UI marketplace contract lives in `docs/DSA-UI-CONTRACT.md`.

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

Raw `/convert /bricks` output remains a self-contained, Framework-neutral HTML/CSS/JS project plus `kiwe.bricks-bindings.v1`; it never contains Bricks JSON. Dynamic tags and query loops are both prepared by default, while `/dynamictags` or `/queryloop` selects only one binding lane. The user then submits the package to `seam.kiwe`, the sole native Bricks JSON compiler. Seam Framework is an explicit compiler option, not another slash command. Framework mode emits one project-wide Framework Profile, stable Bricks class IDs and profile-dependent templates. The profile must be installed from Kiwe > Build > Framework before those templates are imported. The deterministic Framework validator fails closed when a referenced class, variable, palette entry or theme-style owner is missing. Accessibility remains its own audit/fix stage and cannot silently rewrite an accepted design.

## Emergency Disable

Rename `wp-content/mu-plugins/dsa.php` to `dsa.disabled.php`. Restore it only with a complete, matching loader/package release. Do not delete Kiwe tables or options as a routine rollback.
