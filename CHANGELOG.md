# Changelog

## 8.0.0-rc.8 — 2026-08-29

- Replaced the multi-form Staging Seed operator surface with one guided Connect → Review changes → Import → Accept or roll back flow.
- Removed the redundant standalone preflight credential form; connecting now authenticates, preflights, pulls, verifies and calculates changes in one action without storing the Application Password.
- Made an active imported site show only its review, rollback and acceptance actions while package, preflight and closed-ledger internals remain in advanced history.
- Detects a separate test baseline before offering import and links directly to its controls with its label and capture time; direct import errors now provide the same precise route.

## 8.0.0-rc.7 — 2026-08-29

- Corrected staging revision authority so menu hashing excludes the SiteGraph response envelope's per-request `generatedAt` timestamp.
- A source that remains unchanged now produces the same opening and closing revision, while genuine content, menu, Design Context or page-authority changes still fail the transfer safely.
- Added a release regression gate preventing volatile SiteGraph envelopes from re-entering staging package revision hashes.

## 8.0.0-rc.6 — 2026-08-29

- Made source page authority and clean reconciliation explicit SiteGraph capabilities that fail preflight before any incomplete package can be saved.
- Replaced ambiguous legacy package rows with a v2 verified-package ledger that identifies the source Kiwe version and visibly proves whether clean reconciliation is ready.
- Added per-request cache busting and no-cache request headers to staging manifest/resource pulls so hosting caches cannot replay an older exporter contract.
- Automatically retired obsolete pre-authority package files and removed their reconciliation actions; importing continues to require the existing rollback boundary.

## 8.0.0-rc.5 — 2026-08-29

- Added opt-in, rollback-safe clean reconciliation for staging public pages/posts and WooCommerce products so historical compiler-test records cannot contaminate a verified source mirror.
- Added source authority for the WordPress front/posts pages and WooCommerce Shop, Cart, Checkout and My Account assignments, with destination ID remapping after import.
- Dry runs now report unmatched target content and product removal candidates before the explicit staging import confirmation.

## 8.0.0-rc.4 — 2026-08-29

- Added the controlled SiteGraph Staging Seed import lane, gated by a destination-bound verified package, a fresh blocker-free dry run, exact revision confirmation and an automatic private target baseline.
- Added crash-resilient per-object mutation ledgers plus rollback/accept controls for public content, referenced media, menus, Design Context and simple, variable, grouped and external WooCommerce products.
- Kept users, customers, orders, coupons, credentials, messages, payment state, webhooks and protected download-file URLs outside the transfer boundary; webhook delivery is suppressed while staging mutates.

## 8.0.0-rc.3 — 2026-08-29

- Added the second SiteGraph Staging Seed gate: a complete bounded server-to-server pull into a destination-bound, HMAC-verified package outside the public web root.
- Rechecks the source manifest after the final resource page and fails closed if production changed during transfer.
- Added deterministic create/update/reuse/conflict dry-run mapping for terms, referenced media, public content, WooCommerce products and menus, with no content mutation or credential persistence.

## 8.0.0-rc.2 — 2026-08-29

- Added the SiteGraph Staging Seed preflight: an administrator-authenticated, server-to-server manifest and paged resource contract for published business content, WooCommerce catalog/variation facts, media, menus, public CPTs/taxonomies and approved Design Context.
- Kept SiteGraph read-only and fail-closed: the export categorically excludes users, customers, orders, credentials, payment data, sessions, webhooks, provider settings, logs and downloadable-file URLs.
- Added a target-side HTTPS preflight using a temporary WordPress Application Password that Kiwe never stores, plus a credential-free audit ledger and explicit baseline/import/rollback gates.
- Did not add content import in this release rung; mutation remains reserved for the next Controlled Executor batch after live source-manifest proof.

## 8.0.0-rc.1 — 2026-08-25

- Replaced the historical SeamFlow command graph with six strict SEAM commands and one compiler authority.
- Made SiteGraph with embedded Design Context an adaptive input instead of a command; `/ideate` now discovers new-build, redesign and existing-source enhancement lanes and selects a safe available SiteGraph transport.
- Defined `/convert /bricks` as browser-AI binding preparation only: dynamic tags plus query loops by default, or one lane through `/dynamictags` or `/queryloop`; only the `seam.kiwe` application emits Bricks JSON.
- Made Framework an explicit `seam.kiwe` compilation option.
- Reduced the external AI API to scoped SiteGraph reads, deterministic validators and dry-run planning; removed remote mutation, studio and duplicate compiler routes.
- Consolidated WordPress administration into seven operator-facing sections while retaining specialist tools behind those hubs.
- Replaced accumulated settings compatibility migrations with one clean RC schema; fresh installations enable only read-only SiteGraph, existing explicit choices remain intact, and missing capabilities stay disabled.
- Rebuilt the public start registry without historical contracts and retained executable Bricks, bindings, Framework, theme-style and accessibility validators.

## 7.27

- Unified public SiteGraph and Design Context into one `kiwe.site-graph.v1` handoff for onboarding, browser AI, SEAM and connected tools.
- Removed the separate Design Context export, REST route, WordPress ability, MCP tool and command alias so one packet owns target-site evidence.
- Updated `/ideate` to consume the unified SiteGraph packet and regenerated the canonical SeamFlow 7.29 command registry.

## 7.26

- Added the client-first SiteGraph design-context and onboarding connections that were completed between the PhoneKey 7.25 release and the unified 7.27 handoff.

## 7.25

- Promoted PhoneKey from OTP-only transport to Kiwe's bounded, signed WhatsApp channel for opted-in campaigns, saved-cart recovery, WooCommerce order status, and private owner events.
- Added per-user topic/channel consent enforcement, verified contact resolution, independent gateway tenant/recipient limits, and email fallback only when the same recipient also opted into email.
- Added WooCommerce order-status notifications, PhoneKey-first abandoned-cart automation without duplicate channel sends, and owner delivery for orders, comments, live visitors, and visitor summaries.
- Added encrypted-at-rest RC capture for explicitly consented outbound notification text while preserving permanent OTP redaction, recipient hashing/masking, capped retention, and the protected operator timeline.
- Unified notification readiness in Kiwe admin, retained generic WhatsApp webhooks only as a legacy fallback, and kept fresh installations fail-closed with SiteGraph as the sole enabled capability.
- Added an explicit Email + phone two-field Kiwe Auth mode with sequential email and WhatsApp/SMS verification, restored true phone-first signup for the existing Phone-only and Email-or-phone modes, and corrected post-verification routing for returning passkey users.

## 7.24

- Added opt-in RC PhoneKey observability with a bounded single-file timeline, AES-256-GCM encryption for consented inbound test text, hashed recipients, delivery receipts, and strict non-retention of OTP bodies.
- Added signed WordPress-to-PhoneKey outcome reporting so the central RC view distinguishes WhatsApp unavailability from email-fallback acceptance or failure without receiving the OTP.
- Added Hostinger Email and Titan SMTP presets, encrypted credential reuse, a tested fallback-readiness diagnostic, and an explicit PhoneKey warning until Kiwe Email passes a live delivery test.
- Kept public-mode privacy as the target default: RC history is configurable, time/event capped, protected, and can disable inbound content independently.

## 7.23

- Added the Kiwe PhoneKey WhatsApp Gateway RC1: one low-memory WhatsApp session, no chat/history persistence, strict site/tenant allowlists, signed fresh requests, replay protection, idempotency, bounded rate limits, and no OTP or full-phone logging.
- Replaced PhoneKey's unauthenticated success-assuming WhatsApp webhook path with an encrypted tenant secret, exact-body HMAC, nonces, timestamps, redirect refusal, strict 2xx/result validation, and an immediate same-code email fallback whenever WhatsApp is unavailable, rejected, or times out.
- Added an isolated shared-hosting profile for current client-only use and a parallel pinned VPS profile using Evolution API 2.3.7, PostgreSQL 15, and Redis 7 without exposing Evolution's global management API publicly.
- Added CI contracts and behavioral tests for valid delivery, invalid signatures, stale/replayed requests, unapproved site origins, resource boundaries, and deterministic email-fallback signals.
- Provisioned `phonekey.kiwelaunch.com` as an independent Node.js service so PhoneKey transport load cannot share a PHP/WordPress request lifecycle or overwrite an active website.

## 7.22

- Made bare `/ideate` automatically recognize attached `kiwe.sitegraph-design-context.v1` and `kiwe.seam-design-context.v1` files, without requiring users to restate SiteGraph command tokens.
- Added an explicit three-layer ideation authority model: owner facts stay locked, owner preferences stay preserved, and layout/art direction remain AI-writable only for the current draft.
- Reduced Design-Context-led ideation to missing project relationship, reuse-versus-inspiration references, and material creative gaps; answered identity, audience, brand, commerce, SEO and content-plan questions may not be repeated.
- Embedded a read-only `kiwe.ideation-context.v1` contract in future Design Context exports so browser AIs can understand the attachment without a long prompt.
- Removed stale Framework-neutral-versus-Seam-ready choices from `/ideate`; creative output is always Framework-neutral and Seam Framework remains a later explicit migration.

## 7.21

- Expanded the live SEAM Appsite vocabulary with canonical `data-kiwe-contact` and `data-kiwe-social` actions for phone, email, WhatsApp, directions, and public business profiles.
- Added Bricks-native URL tags for phone, email, WhatsApp, and directions plus native Icon, Button, and Text Link controls backed by the approved Kiwe Design Context.
- Kept native Bricks Map authority: map elements compose their address from Kiwe store-address dynamic tags instead of receiving an iframe or competing JavaScript runtime.
- Added safe frontend resolution with enumerated actions, missing-destination fail-closed behavior, bounded WhatsApp starter messages, and server-owned public destinations.
- Added deterministic SEAM compiler inference for existing telephone, email, WhatsApp, map/directions, and recognized social links, plus no-loss Bricks conversion validation.
- Clarified that business social profiles are stored once in DSA Links/Design Context; visitor Profile remains account authority and does not duplicate store identity.

## 7.20

- Replaced the eight always-visible planned-page inputs with a progressive one-row repeater that supports up to 20 clean, removable page intentions.
- Added bounded nontechnical SEO evidence for legal name, founding year, primary website goal, likely customer search intent and verifiable proof points without meta-keyword stuffing or invented claims.
- Added the hash-bound `kiwe.design-context-enhancement.v1` proposal/import lane: SiteGraph exports its locked/writable contract, Kiwe > Framework validates and approves it, and stale or owner-authority-violating files fail closed.
- Kept owner evidence in a separate immutable layer. Approved AI wording/design suggestions and missing semantic colors resolve for SiteGraph, native SEO, Bricks and WordPress 7 bindings without rewriting owner data or silently enabling Seam Framework.
- Blocked Framework profile imports that conflict with owner-selected semantic colors, while permitting explicit nested Framework opt-in from a validated enhancement artifact.
- Connected completed onboarding SEO readiness to the DSA Links score only as a labelled fallback; a manually configured Site Score remains authoritative.
- Added canonical `/create /designcontextenhancement` command routing and synchronized Kiwe, SeamFlow and generated SEAM Compiler contracts to 7.20.

## 7.19

- Refined Owner Onboarding after the first Mishtanna live export: optional mood can be cleared, save/validation returns to the relevant step with visible progress, and saved journeys remain on the review screen.
- Expanded native owner context with inverse logo, WhatsApp phone reuse, Facebook/Instagram/X/YouTube/Pinterest/LinkedIn URLs, and corresponding SiteGraph, Bricks and WordPress binding surfaces.
- Aligned selectable colors to the real SEAM anchors (`color-brand`, `color-accent`, `color-hero`, `color-neutral`, and `color-surface`); technical contrast, state, border, raised/sunken and dark-mode colors remain derived rather than owner-engineered.
- Added WooCommerce-native selling/shipping country coverage, currency position/decimals and measurement units while preserving WooCommerce authority for products, rates, zones and checkout behavior.
- Separated brand story/audience from SEO metadata authority. Only the dedicated homepage search description may populate homepage meta/schema description when no recognized SEO suite is active.

## 7.18

- Added the seven-step Kiwe Owner Onboarding journey for native WordPress identity, public contact, SEO foundations, nontechnical brand preferences, page search roles and WooCommerce-aware store planning.
- Added secure seven-day, account-bound onboarding invitations deliverable through WordPress email, configured Kiwe SMS/WhatsApp channels, or manual copy.
- Added separate SEO-strength and SEAM Design Context readiness scores, native WordPress sitemap/noindex handling for secondary utility pages, and conflict-aware homepage metadata/schema output.
- Added `kiwe.seam-design-context.v1` to SiteGraph design-context exports while keeping SEAM Framework strictly opt-in and operational/private data redacted from anonymous reads.
- Added onboarding release contracts covering required fields, native authority ownership, invitation security, SEO-plugin coexistence, SiteGraph safety and the no-page/no-product/no-shipping-zone mutation boundary.

## 7.17

- Expanded SiteGraph design context from standard posts/pages/products into the full bounded public data model: public custom post types, all public taxonomies, safe custom-field contracts, bounded published values and safe term metadata.
- Added explicit public Kiwe business identity and contact data while continuing to exclude WordPress administrator email, credentials, drafts, orders, visitors, payment data, filesystem paths and arbitrary protected metadata.
- Added WooCommerce grouped/bundled product relationships, cross-sells, upsells, price facts, Kiwe linked offers/discounts and configured bestseller taxonomy evidence without transferring money or cart authority away from WooCommerce.
- Extended file exports, task capsules, OpenAPI, WordPress Abilities and command guidance with independent custom-content and taxonomy budgets plus contract gates for the expanded context.

## 7.16

- Added `kiwe.sitegraph-design-context.v1`, a public-only, read-only design evidence packet covering site identity/logo, menus, products, galleries/attributes, searchable media metadata, public content and target builder capabilities.
- Added downloadable browser-AI export, bounded public and task-capsule REST routes, MCP and WordPress Abilities surfaces, without credentials, private commerce/customer data, filesystem paths or mutation authority.
- Added canonical `/usesitegraph /for /designcontext` and file-only `/usesitegraph /for /designcontext /nonai` command contracts while keeping design context framework-neutral and separate from Bricks binding/conversion phases.
- Added stable raw-source media/dynamic markers, request/row/resource/rate budgets, package drift gates and release proof for the complete design-context lane.

## 7.15

- Added a reduced task-capsule-only OpenAPI contract so ordinary browser-AI, IDE and conversion clients cannot even discover staging, publishing, runtime or mutation tools.
- Added a public secret-free adapter catalog and included OpenAPI, generic HTTP, MCP, Claude, Cursor and maintained Chrome-extension setup descriptors in each downloaded SiteGraph connection.
- Added a local stdio MCP bridge that accepts only short-lived `kiwe_task_*` capsules, requires HTTPS outside localhost, refuses redirects, caps response/time budgets and exposes only read/convert/validate tools.
- Added adapter/runtime drift gates proving that every client remains subordinate to Kiwe's one task-capsule authority and cannot silently gain permanent-key or mutation capability.

## 7.14

- Added vendor-neutral OpenAPI 3.1 and client-manifest discovery routes for ChatGPT-compatible actions, Claude/Cursor adapters, MCP clients, IDEs and standards-based HTTP tools.
- Added downloadable SiteGraph client connection packages containing short-lived, hash-only task capsules with expiry, request, row, field, resource and sample budgets.
- Enforced a hard public-data/read-convert-validate boundary for task capsules; staging, themes, publishing, runtime execution and controlled mutation remain permanent-key-only capabilities.
- Added per-origin authentication throttling and per-credential/per-scope rate limiting across the complete Kiwe AI REST surface.
- Clarified in Kiwe > SiteGraph that `/wp-json/dsa/v1/ai` is an API namespace rather than a webpage, added active capsule usage/revocation diagnostics, and documented safe secret handling.
- Added runtime and release contracts for capsule hashing, revocation, expiry, request budgets, data-policy enforcement, OpenAPI path conversion, authority annotations and documentation drift.

## 7.13

- Added Kiwe > SiteGraph as the single admin control plane for graph export, content-free compiler pairing, binding validation, and trusted apply staging; SiteGraph actions now return to that workspace.
- Added a shared, stateless Kiwe AI broker with isolated Studio, SiteGraph, SecureTrack, and Bricks service profiles, per-service capabilities, data boundaries, prompt budgets, rate limits, output validation, correlation IDs, and metadata-only audit records.
- Connected WordPress 7's provider-agnostic AI Client through its official server-side prompt API, allowing WordPress Settings > Connectors to own provider credentials. Kiwe's encrypted provider transport remains a compatibility fallback.
- Removed SecureTrack's duplicated AI credential and direct cloud-provider request path. SecureTrack now submits only hashed/redacted security packets through its broker profile and retains deterministic local enforcement authority.
- Added an extension hook and Plugin broker accessor so approved plugins can register least-privilege AI profiles and request bounded results without reading the configured provider secret.
- Added mandatory release contracts proving SiteGraph relocation, request isolation, credential boundaries, WordPress 7 routing, SecureTrack broker use, and absence of direct SecureTrack provider calls.

## 7.12

- Added Kiwe > Database & Cache as a dedicated evidence-first workspace and moved persistence, residue, inode, runtime-recovery, and cache controls out of the Developer workflow.
- Added whole-site database inventory with exact WordPress Core and Kiwe ownership, strong WooCommerce-prefix evidence, active/inactive plugin-slug heuristics, unknown-owner protection, table sizes, autoload budget reporting, and read-only cleanup candidates.
- Added layered cache purging for Kiwe runtime data, expired WordPress transients, persistent object cache, and registered page-cache adapters. Every result reports completed, skipped, and failed layers instead of claiming an unsupported purge.
- Restricted native WordPress cache layers to all-device scope. Desktop/tablet/mobile choices activate only when an evidence-backed adapter proves it has a corresponding device namespace.
- Added a Kiwe Cache admin-toolbar entry and bound Kiwe asset plus service-worker cache generations to the runtime epoch so an all-device Kiwe purge produces real cache-busting URLs and cache names.
- Added high-impact object-cache confirmation, shared-host warm-up warnings, and mandatory database/cache release contracts.

## 7.11

- Added a read-only Kiwe persistence and inode-residue inventory under Kiwe > Developer, covering current and unknown legacy Kiwe/SecureTrack/PhoneKey tables, option and meta footprints, cron events, unexpected canonical-package files, and possible old top-level MU-plugin copies.
- Added a conservative maintenance action for expired Kiwe-family transients, expired operational rows, and cron events owned by disabled features. Content, orders, users, consent, logs, and active PhoneKey credentials/factors remain outside this cleanup boundary.
- Added separately confirmed removal for only listed unknown legacy tables and manifest-proven unexpected package files; possible old top-level copies remain report-only because filename similarity is not sufficient ownership proof.
- Corrected fresh-install lifecycle registration so disabled PhoneKey, push, preferences, analytics, abandoned-cart, commerce, surface, and Bricks features do not silently install storage or runtime hooks. The SiteGraph security rate-limit table remains the single bounded persistence dependency of the default read-only SiteGraph lane.
- Changed Developer reset to the SiteGraph-only safe baseline and added mandatory persistence-maintenance and fresh/existing-install release contracts.

## 7.10

- Added a fail-closed fresh-install profile: every optional Kiwe runtime capability starts disabled while the read-only SiteGraph remains available for browser-AI and export workflows.
- Preserved existing installations by applying the profile only when Kiwe has never stored its primary settings option; upgrades keep their configured and historical fallback behavior.
- Added release coverage proving the dock, AppShell, SecureTrack, PWA, commerce, communications, tracking, metrics, schema, tokens, Bricks enhancements, and all other boolean settings cannot activate on a fresh installation.

## 7.09

- Corrected clean-run template isolation to use Bricks 2.3.10's actual `bricks/database/bricks_get_all_templates_by_type_args` query hook. The previous similarly named hook was never called, so legacy default headers and footers could still win even when a newly imported product template was active.
- Kept the fix project-neutral and query-only: every pre-run header, footer, product, archive, popup, and other Bricks template remains published and unchanged, while only templates imported inside the acceptance window can resolve until exact restoration.
- Updated the deterministic isolation regression and synchronized the MU loader, package entry, SeamFlow contract, generated token catalog, and release proof to 7.09.

## 7.08

- Extended Clean Conversion Test Run from global-style isolation to Bricks template-resolution isolation. Every published template that existed before the snapshot remains published and editable but is excluded from Bricks' active-template query for the duration of the run, preventing an older project's header, footer, product, archive, or default condition from contaminating the current conversion.
- Kept the isolation query-only and hash-verified: no template status, content, condition, product, order, user, or media record is changed, and restoring the snapshot immediately returns the exact pre-test resolution state.
- Added a deterministic release test for template snapshot, exclusion merging, cache invalidation, and exact restoration; synchronized SeamFlow, generated contracts, package proof, and release metadata to 7.08.

## 7.07

- Added a hash-verified, reversible Clean Conversion Test Run to Kiwe Developer with isolated Raw, Woo native, and Woo native + Kiwe profiles. It snapshots and exactly restores Bricks classes, variables, palettes, theme styles, the element manager, and Kiwe settings without deleting content or commerce data.
- Extended the content-free SiteGraph calibration contract with disabled Bricks element names, allowing SEAM Compiler to report target capability blockers instead of misdiagnosing missing WooCommerce output as a CSS conversion defect.
- Woo test profiles temporarily activate the native Bricks commerce elements needed by product, archive, cart, checkout, and account templates while preserving all pre-test element-manager permissions and state for restoration.
- Synchronized the MU loader, package entry, command contract version, and package manifest for folder-based Hostinger deployment.

## 7.06

- Added a Framework-free Bricks accessibility lane that fills only missing native `dark` values in existing non-Kiwe Color Manager palettes. It preserves palette identity and order, every light value, every explicit designer dark value, and all variables, classes, and Theme Styles.
- Added an independent pre-write backup and an exact generated/preserved/skipped report. Legacy colors that Bricks has not converted to its current `light`/`dark` model are skipped instead of being rewritten ambiguously.
- Renamed the existing dark-palette action to identify it explicitly as the Framework lane, preventing a plain Bricks project from accidentally installing Kiwe palette data.

## 7.05

- Made `/accessibility` an independent current-artifact command for raw `/ideate` HTML/CSS/JS, framework-neutral Bricks output, and Seam Framework packages. Automated evidence scores now exclude named manual checks and never claim WCAG conformance.
- Added adaptive brand-aware Bricks dark palettes with tiered tinted surfaces and measured foreground contrast. Explicit project dark values remain authoritative.
- Added Kiwe > Framework actions to generate/push dark palettes, import the standalone compiler palette without installing Seam Framework, and explicitly enable the Kiwe dock for light/dark preview while Raw Convert Test Mode remains active.
- Kept visual-difference testing isolated by leaving the dock-preview override off by default and warning designers to disable it before clean captures.
- Bound accessibility-enhanced template variables to Bricks' native dark-mode root state so source-scoped variables cannot shadow the installed light/dark palette.

## 7.04

- Aligned SeamFlow browser-AI fallback with SEAM Compiler 0.13.0: hosted, official local, and Kiwe REST routes share one fail-closed authority contract; browser AI may never synthesize production Bricks JSON, converted-project Framework Profiles, measured accessibility ratios, or PASS proof.
- Updated the plugin authority bridge to the Munaf-owned public compiler and removed its generic accessibility-plan fabrication path. Accessibility creation now requires real project evidence and executable validation.
- Restricted `/create /frameworkprofile` to explicit blank-foundation work; converted projects use the one-pass deterministic `/convert /bricks /seamframework` path so raw rendered evidence is not lost.

All notable pre-1.0 release-candidate changes are recorded here. Architectural history remains in `docs/DSA-ARCHITECTURE.md`.

## Unreleased

- Added an explicit Raw Convert Test Mode in Kiwe Developer that suppresses public AppShell assets and Surface markup while leaving WordPress and native Bricks page content active for uncontaminated source-to-output visual acceptance.
- Kept the mode independent from diagnostic logging so a test site can isolate conversion output without enabling frontend debug data or console traces.

## 7.03 - 2026-08-13

- Aligned Kiwe with SEAM Compiler 0.12.0: raw conversion remains self-contained and Framework-neutral, while `/seamframework` emits one project-wide profile, stable Bricks class IDs, dependent templates, and executable audit proof.
- Added the deterministic Framework package validator used by `/audit /seamframework`. It fails unless package integration is 100%, raw structure/content parity passes, references resolve, no variable fallback is present, and Theme Style, project classes, and elements have single style ownership.
- Updated Kiwe Framework import/push to preserve compiler-issued project-class IDs so dependent Bricks templates bind to the exact classes installed in Bricks Style Manager.
- Deferred accessibility from this release gate; it remains a separate later phase.

## 7.01 - 2026-08-12

- Added `/ideate` as a first-class adaptive Start-link workflow: it collects project identity, site type, audience, goal, logo/brand evidence, art direction, homepage content, and constraints before generating one HTML/CSS/JS homepage.
- Added a final explicit framework choice. Framework-neutral output remains free of Seam/Kiwe metadata; Seam-ready output adds only headless semantic context, exact universal/project tokens, and Geometry Engine fluid fallback without influencing the creative visual thesis.
- Added normal conversational refinement after the initial draft plus matching CLI, MCP, machine-manifest, and Kiwe Companion routes.

## 7.00 - 2026-08-09

- Made compiler-batch ownership evidence-based after live 6.99 inventory proved that ordinary five-letter Seam utility segments such as `seam-align-*` can resemble generated hashes. A namespace is now registered only through explicit compiler class metadata or class-ID references from an active Bricks template tagged `SEAM Compiler`; name shape alone can never authorize deletion or exclude Framework ownership.

## 6.99 - 2026-08-09

- Added a reference-aware Bricks compiler batch manager to Kiwe Developer: it inventories exact hashed `seam-xxxxx-` namespaces, backs up the active/trash registries, preserves the selected current batch, protects classes referenced by live Bricks content, moves only unused older classes to Bricks trash, and queues native Bricks CSS regeneration.
- Separated compiler-template ownership from Kiwe Framework ownership so Framework push/clear operations can no longer delete isolated converter classes merely because their names begin with `seam-`.

- Added `tools/release/verify-green-baseline.cjs` as the canonical local and CI source gate, including package integrity, Seam/token audits, positive and negative fixtures, toolkit/connector contracts, RC contracts, and JavaScript syntax.
- Made the SeamFlow source contract derive the active package version instead of pinning an obsolete release number, preventing routine version changes from creating false failures.
- Taught the runtime token-purity audit that the combined Kiwe/legacy dark-theme selector is a legitimate token authority while keeping component-level literals fail-closed.
- Added the M1 SEAM Compiler foundation with six strict versioned contracts, dependency-free validation, generated TypeScript/PHP declarations, and a generated 108-token catalog tied to Kiwe's canonical token service.
- Added deterministic Capture IR to Page/Behavior/Asset IR compilation, a Bricks 2.3.10 capability profile extracted from the supplied source, capability-proven native Bricks serialization, AppSite package output, a valid SEAM Framework Profile, and Page Geometry evidence.
- Quarantined the former browser converter as a legacy scaffold and made AI-direct Bricks JSON an explicit unsupported path; supported output can only serialize from a validated `seam.bricks-plan.v1` with provenance.
- Added two unrelated compiler fixtures plus the supplied National Chikki homepage as a hash-tracked golden source pending the M2 rendered capture engine.

## 6.98 - 2026-08-01

- Clarified that `/audit /frameworkprofile` is not an alias of `/audit /brickstheme`; Framework profile `bricks_theme_style` global slots are valid and must not be stripped down to only `enabled`, `id`, and `label`.
- Tightened `/audit /bricksconversion` so both conversion envelopes and direct native Bricks template exports fail when Bricks-native settings or Kiwe-owned global classes contain untokenized literal design lengths such as raw `28px`, `2rem`, or fixed grid/flex measurements. Framework profiles provide the design tokens, but they do not magically rewrite hardcoded Bricks JSON after conversion.
- Synced the native Bricks token-purity rule through the browser-AI workflow/context files, toolkit validator, WordPress REST validator, Bricks AI context, and Companion review cards so `/audit` and `/fix /bricksconversion` can identify the real grid/flex/token problem without spoon-fed symptoms.
- Rejected no-op clamp wrappers such as `clamp(22px, 22px, 22px)` in `/audit /bricksconversion`; these are disguised literals, not real Seam/Geometry tokenization. Browser/IDE AIs must use existing universal tokens, declared project tokens, or real fluid clamps with distinct min/preferred/max behavior.

## 6.88 - 2026-08-01

- Added a cross-phase route fallback ladder: if Plugin REST or Companion cannot be reached, tool-capable AIs must fall back to the official Git/Node compiler or validator for the current phase before returning `WARN/UNVERIFIED`.
- Applied the fallback law to Framework Profile, Bricks conversion, Accessibility, and every other validator-backed phase, not only `/rebuild /seamframework`.
- Synced the rule through Start, machine entry, command manifest, planner output, and smoke tests so browser AI cannot treat REST failure as a final blocker when `validate-framework-profile.cjs`, `validate-bricks-conversion.cjs`, or `validate-accessibility.cjs` can run.

## 6.87 - 2026-08-01

- Made the no-command SeamFlow startup smarter for non-technical users: raw HTML/CSS/JS drafts now recommend `/execute /stepbystep /audit /fix /eachstep /report` as the safest default instead of the low-level `/rebuild /seamframework` phase.
- Clarified that `/rebuild /seamframework` is the first internal phase of the guided command, so browser AI can start the full audited SeamFlow path without making the human choose lanes manually.
- Synced the raw-draft default through `KIWE-START.md`, `entry.json`, the command manifest, the local planner, and smoke tests.

## 6.86 - 2026-08-01

- Tightened the SeamFlow first interaction so browser/IDE AIs classify supplied artifacts, recommend the next command, then present route choices: Browser/raw, Git/Node, Plugin REST, or Plugin REST + Companion.
- Added explicit API-key prompting rules for Plugin REST and Companion routes, including where to create a Kiwe AI key and which scopes are accepted.
- Synced the route-choice prompt through `KIWE-START.md`, `entry.json`, command manifest, and local SeamFlow planner tests while keeping `/list` as the final compact-command hint.

## 6.85 - 2026-08-01

- Added plugin-hosted SeamFlow REST routes under `/wp-json/dsa/v1/ai/seamflow/*` for deterministic artifact classification, Seam rebuild, Framework profile generation, Bricks conversion handoff, accessibility plan/audit, and step/full-flow orchestration.
- Added the `seamflow` AI key scope, with `studio_ai`, `bricks_ai`, and `all` accepted for broader AI workflows.
- Updated `KIWE-START.md`, `entry.json`, and the command manifest so browser/IDE AIs can prefer the plugin validator routes when `KIWE_REST_BASE` and `KIWE_AI_KEY` are supplied, avoiding manual-only PASS claims when local validators are unavailable.
- Regenerated the MU-plugin package manifest for the folder-based Hostinger upload path.

## 6.54 - 2026-07-28

- Tightened `/audit /frameworkprofile` and `/fix /frameworkprofile` so browser/IDE AIs no longer need a spoon-fed list of missing live variables.
- Added deterministic core token coverage checks for Framework profiles: brand/accent/surface/text/border/font/type/spacing/radius/shadow tokens must be covered through official Kiwe universal token overrides or mapped Bricks theme-style slots.
- Updated Kiwe Companion review to return the same `missing_core_token_coverage` errors as the local toolkit when a Framework profile would import but leave Bricks/Seam variables incomplete after push.

## 6.51 - 2026-07-27

- Made Kiwe Framework profile imports self-contained for Bricks: `settings.tokens.bricks_theme_style` now auto-enables the Bricks theme-style lane when a profile carries global style metadata, derives a safe id/label from the profile when missing, and normalizes safe global style slots back into official Kiwe universal token overrides.
- Updated both Kiwe > Framework admin pushes and AI staging executor pushes to use Bricks global-variable helpers when available and explicitly regenerate Bricks theme-style CSS after writing `bricks_theme_styles`.
- Tightened `/create /frameworkprofile`, `/audit /frameworkprofile`, and generic output audit contracts so browser/IDE AIs must produce a complete Bricks foundation profile instead of a partial style blob that imports but does not create the expected Bricks Theme Style.

## 6.50 - 2026-07-27

- Split the browser-AI command flow so `/create /frameworkprofile` produces the single Kiwe > Framework import/push artifact, `framework/kiwe-framework-profile.json`, while `/create /brickstheme` produces only a native Bricks Theme Styles JSON file for direct Bricks import.
- Added Bricks Theme Styles context, schema, fixture, CLI validator, and MCP validator so browser/IDE AIs can generate a real Bricks global theme-style file instead of wrapping it in extra handoff folders.
- Hardened `/convert /bricks` so conversion stops when the page artifact has no framework/profile/theme foundation, nudging the user to create or push the Framework profile first instead of producing Bricks JSON that renders without the intended Seam tokens, colors, and typography.
- Extended the “documentation only when `/document` is requested” rule across framework profiles, Bricks theme styles, Site Graph bindings, Bricks conversion, accessibility, website, theme, and combined handoff contexts.

## 6.49 - 2026-07-27

- Added a Kiwe > Framework clear action that resets the active Framework token profile and removes only Kiwe-owned Framework data from Bricks: `kiwe-*` variables, Kiwe Universal palette/category entries, the active/default Kiwe global theme style, and Seam Class Vocabulary classes/categories.
- Preserved non-Kiwe Bricks variables, classes, palettes, and theme styles during clear, with a safety backup stored before mutation.
- Added `/document` as the explicit documentation phase and changed `/rebuild /seamframework` to emit only `website/bricks-paste.html` by default, reducing browser-AI token spend and preventing unnecessary `bricks-notes.md` output unless requested.
- Synced the lean Seam rebuild output shape through the public workflow context, root AI entrypoint, MCP routing copy, toolkit command list, handoff scaffold, website validator, and generic audit tool.

## 6.48 - 2026-07-27

- Hardened Bricks template-upload validation after National Chikki testing exposed direct-template JSON that imported with no title, missing template type, custom-CSS dependency, unsupported semantic element names, and too few native Bricks style/layout controls.
- Updated the Node validator, REST validator, Bricks conversion context, and audit context so `/audit /bricksconversion` rejects broken direct Bricks template objects instead of treating them like complete conversion packages.

## 6.47 - 2026-07-27

- Added first-class `/create /accessibility` and `/audit /accessibility` workflow lanes for existing page/theme/combined/framework/Bricks artifacts, focused on color contrast and native light/dark support while keeping font-size/readability scaling as a later lane.
- Added `accessibility/kiwe-accessibility-plan.json` plus `ACCESSIBILITY-NOTES.md` as the canonical output contract, with Kiwe/Seam token-pair proof and safe Bricks global theme-style color mapping.
- Added deterministic accessibility validation across toolkit CLI, MCP, REST API, WordPress Abilities, Site Graph discovery, Internal AI context, Companion review, Audit Companion, and valid/invalid fixtures.
- Hardened the AI/tool loop against white-on-white, light-on-light, black-on-black, and dark-on-dark literal color pairs, missing dark-mode proof, unmapped private color variables, and isolated Bricks palettes.

## 6.46 - 2026-07-27

- Source-verified the Bricks conversion fidelity rules against the local Bricks 2.4 beta `html-to-bricks` converter, generated control index, and breakpoint manager.
- Hardened `/convert /bricks` and `/audit /bricksconversion` so responsive Bricks layout controls are detected as `controlKey:breakpoint`, including native `_flexDirection:<breakpoint>`, grid controls, `_cssCustom:<breakpoint>`, spacing/sizing controls, and custom site breakpoint keys.
- Updated the seam-spread mobile-direction guard so Bricks-native `_flexDirection:*` mistakes are rejected the same way as copied `_direction:*` mistakes, preventing approved row/spread layouts from silently becoming mobile columns.
- Synced the stricter Bricks 2.4 responsive-control language across the Node validator, audit CLI, REST validator, Companion review, Bricks AI context, Site Graph capability copy, and public toolkit contexts.

## 6.45 - 2026-07-27

- Tightened Bricks conversion fidelity audits so bento/campaign/editorial grids, CSS grid placement, media-query layout changes, and Bricks breakpoint overrides require `fidelity.responsiveIntent`.
- Added deterministic failures when complex bento/grid/campaign regions are not named in `fidelity.sourceSelectors`, preventing visually broken Bricks imports that still look structurally valid.
- Synced the new responsive-grid rule across the Node validator, generic audit tool, REST validator, Companion review, Bricks AI context, and public `/convert /bricks` / `/audit /bricksconversion` contexts.

## 6.44 - 2026-07-27

- Tightened Kiwe Bricks conversion audits so `/audit /bricksconversion` also rejects conversion JSON that misses the required `target`, `conversion`, `fidelity.sourceSelectors`, or `report.manualReview` contract lanes.
- Hardened Seam selector purity checks to catch scoped project selectors such as `.brand .seam-card` or `.nc-page .seam-visually-hidden`, not just selectors that start directly with `.seam-*`.
- Updated Bricks conversion validation for Bricks 2.4 by accepting the native `html` element while keeping unknown-element warnings for genuinely unsupported element names.
- Clarified public toolkit contexts that Seam selector declarations remain invalid in project CSS even when scoped under a project class.

## 6.43 - 2026-07-27

- Hardened Kiwe AI Toolkit, Companion review, and Bricks conversion validation against project CSS that redefines bare Seam framework selectors, keeping Seam classes/attributes as shared headless vocabulary instead of one-off design hooks.
- Added rail-wrapper audit coverage so AIs cannot put `.seam-horizontal-rail` / `data-flow="horizontal-rail"` on an outer sticky nav/container and shrink the real Bricks rail into a single narrow item.
- Added a runtime dock-material guard: split dock shells stay transparent while solid/glass material is applied to inactive dock controls, preventing the dark slab effect and making the material setting visually meaningful.
- Updated public toolkit contexts and framework handoff notes with the project-class styling rule and rail-placement boundary for future AI/browser-tool workflows.

## 6.42 - 2026-07-27

- Added the browser-AI terminal entry pattern `explore: https://github.com/Museintel/kiwe` followed by `/list`, so users can start Kiwe like a command shell without prompt-engineering the whole toolkit path.
- Hardened slash-command parsing so `explore:` URL path segments are stripped before diagnostics, routing, and `/usecompanion` handling; GitHub paths cannot become fake Kiwe commands.
- Documented the exact `explore:` flow in `KIWE-AI.md`, the toolkit README, and `workflow-lite.md`, with explicit boundaries against repo crawling and token-wasting discovery.

## 6.41 - 2026-07-26

- Added first-class `/list` and `/fix` command routes to the Kiwe AI Toolkit so browser/IDE AIs can discover supported phases and repair failed artifacts without restarting creative work or creating unrelated packages.
- Promoted `/usesitegraph` as the canonical Site Graph command, with explicit `/replacepreviewdata`, `/websitename`, and `/nonai` variants; legacy `/dynamic /sitegraph` remains accepted internally but is no longer the public command to teach.
- Added `kiwe_list_commands` to the MCP surface and `kiwe list` / `kiwe commands` to the CLI, while keeping command diagnostics strict enough to reject typos with suggestions instead of guessing.
- Tightened Site Graph routing so target-site truth may come from API-key routes, exported `kiwe.site-graph.v1` JSON, or the AI-less public Site Graph Data lane, and frontend scraping remains forbidden as a fallback.
- Clarified `/create /brickstheme` as a Kiwe Framework / Bricks global token profile only, and restated `/convert /bricks` as a page-only conversion whose canonical output is `bricks-conversion/kiwe-bricks-conversion.json`.

## 6.40 - 2026-07-24

- Fixed sticky admin list settings by making submitted numeric/list settings replace the previous saved list instead of recursively merging old values back in. This addresses SecureTrack role checkboxes and similar admin options that appeared to save but could not be cleared.
- Added opt-in abandoned-cart email automation with hourly-maintenance batching, campaign-safe email delivery, site-logo branded HTML recovery emails, cart item details via `{cart_items}`, and a stronger default recovery message to encourage revisit and checkout completion.
- Added an explicit encrypted guest-checkout email recovery lane for abandoned carts, disabled by default, so live stores can choose guest reminders without storing plain-text checkout email in the cart table.
- Updated Abandoned Cart admin settings with automatic-email, encrypted guest-email, and batch-limit controls while keeping tracking/manual reminders separate from automation.
- Improved PhoneKey post-login routing so privileged users who complete the required high-assurance login path land back in WordPress admin instead of Woo/My Account. Admin access still intentionally requires the full privileged assurance policy, including verified phone setup where configured.
- Hardened sheet-mode panel entry so Cart/Profile/Search panels cannot remain stuck partially offscreen or semi-transparent after dock opens, async rerenders, or theme animations.
- Added staging evidence notes for public cart/checkout availability, mutation-proof rejection, same-site mutation protection, PhoneKey config reachability, and the confirmed Secure settings save regression found on live 6.39.

## 6.39 - 2026-07-24

- Promoted Seam from “tokens/classes only” into a universal Appsite capability attribute layer, with canonical live hooks for DSA launchers, save/wishlist/bookmark, browser-notification journeys, outside-dock light/dark toggles, menu context sections, and Bricks dynamic/query-loop intent.
- Wired the attribute library into `/rebuild /seamframework`, `/audit /seamframework`, `/dynamic /sitegraph`, `/convert /bricks`, Bricks AI context, Companion cards, MCP tools, CLI commands, framework handoffs, and Kiwe Developer docs.
- Added frontend support for `data-kiwe-theme-toggle` so real page/header/Bricks controls can toggle Kiwe/Bricks light-dark state without duplicating runtime JavaScript.
- Hardened Bricks conversion validation so live Kiwe/Appsite capability attributes from source HTML must survive conversion into the reviewable Bricks package.

## 0.6.38 - 2026-07-24

- Added a deterministic Kiwe command gate (`kiwe.command-diagnostic.v1`) so invalid, missing-input, forbidden-lane, and no-op slash commands stop before browser/internal AI spends tokens on nonexistent or useless work.
- Exposed command diagnostics through the toolkit CLI (`kiwe diagnose`), MCP (`kiwe_diagnose_command`), route output, and Companion `commandGate` cards/answers.
- Documented stable diagnostic codes for command typos, unsupported preview targets, duplicate website preview requests, missing Bricks conversion sources, missing conversion artifacts, missing Site Graph context, and staging authority gaps.

## 0.6.37 - 2026-07-24

- Hardened `/convert /bricks` so it can only convert the approved page lane, `website/bricks-paste.html`, and now rejects combined previews, AppShell theme previews/imports, screen/sheet/dock/navbar markup, `theme-package.json`, and `theme.css` as Bricks conversion sources.
- Added canonical `/create /preview /dsatheme` and `/create /preview /combined` routes across toolkit routing, MCP, Companion phase cards, workflow docs, and smoke tests so preview-proof work does not get confused with Bricks conversion.
- Added a negative Bricks conversion fixture and connector-contract checks proving the page-only Bricks boundary and normalized `/create` command language stay intact.

## 0.6.36 - 2026-07-24

- Added the `/convert /bricks` and `/audit /bricksconversion` lanes so approved HTML/CSS/JS page artifacts can become reviewable Bricks-native JSON packages without reading the full codebase or mutating WordPress/Bricks.
- Added deterministic Bricks conversion validation across toolkit CLI, MCP, REST API, WordPress Abilities, Site Graph discovery, Bricks AI context, Companion review, and connector contracts.
- Added a valid Bricks conversion fixture proving Seam class preservation, Kiwe launcher preservation, query-loop/dynamic intent, source-element fidelity maps, notes, and linked binding validation.

## 0.6.35 - 2026-07-24

- Added the Kiwe AI phased workflow/router for higher-quality external AI outputs: `/ideate /webdraft`, `/rebuild /seamframework`, `/audit /seamframework`, `/create /brickstheme`, `/create /dsatheme`, `/assemble /combined`, and `/dynamic /sitegraph` now route to smaller contexts before one-shot combined work.
- Exposed the workflow through `KIWE-AI.md`, `kiwe-ai-toolkit/contexts/workflow-lite.md`, CLI commands (`workflow`, `route`), and MCP tools (`kiwe_get_workflow`, `kiwe_route_command`) so browser and IDE AIs can avoid reading the full repo or being overloaded with every contract at once.
- Added `/usecompanion` as an optional phase flag: browser/IDE AI can ask Kiwe Companion for compact cards or Audit Companion findings at any command level, but failures fall back to the original route without blocking output or spending native model tokens.

## 0.6.34 - 2026-07-24

- Retired the htmx/Alpine enhancement pilot after lead review: DSA core remains the default authority, and future hybrid adoption requires a named adapter with evidence that it beats the native DSA/Seam/WordPress stack for that narrow job.
- Removed the `Kiwe > Developer` controlled web-app enhancement card, htmx package-proof AJAX refresh, enhancement settings lane, appsite profile import/export support, frontend/admin enqueues, public boot metadata, and vendored htmx/Alpine assets.
- Replaced the old htmx/Alpine source contract with a retirement contract that keeps those libraries out of runtime/admin/package manifests unless a future scoped adapter is deliberately introduced.

## 0.6.33 - 2026-07-24

- Removed the duplicate `Architecture status` and `Production readiness` panels from `Kiwe > Developer`; Developer now stays focused on diagnostics, runtime recovery, builder attributes, portable export/reset, and deployment tooling.
- Kept the underlying production-readiness report on the main Kiwe overview, where it belongs as a release-support view rather than a Developer-page wall of legacy audit text.
- Added a release regression check so the retired Developer-page architecture/readiness panels cannot quietly reappear.

## 0.6.32 - 2026-07-24

- Retired the S18 generated asset delivery pilot from `Kiwe > Developer`: removed its diagnostics checkboxes, build-status card, queue action, architecture-status row, readiness check, and APEX generated-build evidence.
- Stopped registering the S18 runtime service and removed the generated asset build service from the deployable package, so Kiwe always serves the packaged stylesheet authority while old generated uploads remain harmless inert files.
- Scrubbed legacy `asset_build_*` diagnostics keys on save and updated release/operations docs so generated delivery is no longer presented as a pending production lane.

## 0.6.31 - 2026-07-24

- Expanded AppShell theme token-purity validation from raw `px` literals to anonymous raw CSS literals, including hardcoded length units, color literals/functions, and literal shadow/effect recipes in importable `theme.css`.
- Updated `validate-package`, `validate-output`, `audit-output`, and the REST Audit Companion so browser AI, internal AI, and human theme authors receive the same deterministic failure before staging.
- Refreshed the Kiwe AI Toolkit contexts, UI-system prompt, and invalid fixture so concrete design values are placed in `theme-package.json settings.tokens` or core token registries while installed theme CSS consumes official Kiwe/Seam tokens or Geometry Engine variables.

## 0.6.30 - 2026-07-23

- Hardened the marketplace AppShell theme boundary so importable `theme.css` now fails on anonymous raw pixel literals such as `22px`, `35px`, `1px`, or `999px`; theme CSS must consume official Kiwe/Seam tokens, documented theme aliases, or Kiwe/DSA Geometry Engine variables instead.
- Updated the package validator, combined output validator, broad AI audit, and REST Audit Companion to report the same token-purity error before staging or live install.
- Clarified public toolkit contexts and UI-system prompts that concrete numeric values belong in `theme-package.json settings.tokens` or Kiwe core token registries, not inside installable AppShell theme declarations.
- Normalized the prototype Search input font weight to a standard browser weight and refreshed the RC9 presentation contract around the current token-bridge/font-token architecture.

## 0.6.29 - 2026-07-23

- Removed AppShell root painting from the BioVantage marketplace theme package and reinstalled the corrected `biovantage-clinical-botanics` theme on staging as `1.2.1`, so sheet mode no longer gets a page-sized background plate behind the sheet/dock.
- Tightened `validate-output`, `audit-output`, `tools/ui-theme/validate-package.cjs`, public lite contexts, UI prompt guidance, `screen-payloads.json`, and Audit Companion so importable AppShell theme CSS may set tokens/inherited typography on the protected surface root but must not paint it with background, border, shadow, opacity, or filters.
- Aligned the Seam/AppShell styling boundary: protected `data-seam-*` shadow metadata remains for tooling and AI understanding, while installable theme CSS must skin live DSA through documented `data-dsa-part` hooks and public Seam vocabulary.

## 0.6.28 - 2026-07-23

- Completed the BioVantage TEST 2 staging gate through the live Kiwe AI API: installed/activated the corrected AppShell theme package, validated bindings, prepared the Bricks apply plan, and successfully created a UTF-8 Bricks staging page through `bricks.page.from-html`.
- Hardened DSA Search opening behavior so narrow/coarse-pointer viewports no longer autofocus the search input on panel open, preventing mobile keyboards from immediately shrinking the sheet/screen.
- Confirmed the UTF-8 import path works when AI/API clients send JSON bodies as UTF-8 and split CSS through the controlled executor; the older mojibake staging artifact came from a non-UTF-8 client request, not the BioVantage source file.

## 0.6.27 - 2026-07-23

- Exposed live DSA screen/sheet theme hooks by adding `data-dsa-screen-name` and `data-dsa-part` metadata alongside the existing protected Seam shadow landmarks after each AppShell panel render.
- Tightened `validate-output`, `audit-output`, public lite contexts, `screen-payloads.json`, and Audit Companion so marketplace AppShell themes fail when importable `theme.css` only skins broad panels/colors and never targets live Seam/AppShell part hooks.
- Demoted the confusing `Kiwe > Theme` adapter choice into an advanced compatibility state: normal users now choose installed themes, while custom marketplace themes default to the modern Kiwe 2027 runtime foundation unless they explicitly declare Legacy.

## 0.6.26 - 2026-07-23

- Tightened the combined handoff validator, AI output audit, public lite contexts, and Audit Companion so primary combined previews fail when DSA screen/sheet visuals depend on preview-only `.kiwe-preview-panel-*` fixtures instead of live Kiwe runtime selectors.
- Reclassified the BioVantage combined handoff as a failed preview/live AppShell match: its preview styled fake panel interiors that do not exist on Hostinger, while the installable theme only lightly skinned real Kiwe 2027 panels.
- Clarified `Kiwe > Theme` admin copy so installed themes are the actual AppShell theme packages, while Legacy/Kiwe 2027 is labelled as the base UI adapter/fallback layer underneath marketplace themes.

## 0.6.25 - 2026-07-23

- Aligned the static handoff audit with live AI screen selectors by allowing `.dsa-ai-insight` and `.dsa-ai-chat-placeholder` in importable AppShell theme CSS when they target Kiwe runtime markup instead of preview-only fixtures.
- Neutralized brand-specific examples in the public combined-lite toolkit context so Audit Companion and browser AI guidance remain marketplace/general-purpose rather than biased toward prior National Chikki tests.
- Hardened the controlled Bricks staging executor after the BioVantage combined-handoff live test exposed that dynamic query-loop bindings were validated but not consumed during fallback conversion.
- Split embedded `<style>` blocks into the CSS lane before Bricks conversion so browser-AI handoffs can paste naturally while the executor still applies CSS through the controlled page-settings path.
- Passed `kiweBindings` into the fallback Bricks converter, mapped `data-kiwe-query-template` to sanitized Bricks query settings, and preserved dynamic URL/image tags such as `{post_url}` and `{featured_image}` instead of flattening them into static text.

## 0.6.24 - 2026-07-23

- Added an explicit Audit Companion lane with `/wp-json/dsa/v1/ai/audit-companion/context` and `/review` so browser AI can submit generated handoff files and receive compact `mustFix`, `shouldFix`, and `passed` maps before spending another broad revision pass.
- Tightened deterministic Companion review coverage for combined output shape, AppShell theme-package schema, screen-copy settings, token profile purity, custom dock-link authority, controlled Seam `data-role` values, private runtime token leakage, fixture selectors, and encoding artifacts.
- Mirrored Audit Companion through WordPress Abilities, Studio review, Kiwe > AI endpoint hints, and public toolkit contexts so self-audit is guided by deterministic gates instead of prompt-level spoon-feeding.

## 0.6.23 - 2026-07-22

- Aligned the public Kiwe AI Toolkit, AppShell/theme prompts, framework handoff docs, and audit loop around Seam token purity.
- Made package and handoff validators reject private generated `--dsa-runtime-token-*` bridge variables in theme output so AIs cannot mistake Kiwe core migration tokens for public design tokens.
- Added an invalid runtime-bridge-token fixture plus CI/release/AI contract checks to prove the validator catches that boundary.

## 0.6.22 - 2026-07-22

- Completed the runtime Seam purity pass for DSA Surface CSS: legacy raw component values now live in a token-authority bridge and runtime declarations consume variables.
- Added `tools/ui-theme/audit-runtime-token-purity.cjs` so future runtime CSS cannot reintroduce hardcoded colors, spacing, sizing, radii, shadows, blur, timing, or viewport values in component declarations.
- Moved Bricks Studio AI floating-editor CSS onto Seam/Kiwe tokens so the native AI UI follows the same runtime token discipline.
- Kept visual behavior stable while adding about 5.5 KB gzip to `surface.css`, preserving the current UI before staging tests.

## 0.6.21 - 2026-07-22

- Ran a focused Seam/token audit across theme/toolkit contracts, framework profiles, package validators, and core `surface.css`.
- Fixed the Search alphabet chip alignment by removing the manual letter offset and moving chip sizing/gap onto DSA geometry tokens.
- Tokenized desktop and mobile dock edge/context offsets so dock placement no longer depends on repeated component-level `18px`/safe-area magic values.
- Recorded the remaining module-interior token-hardening debt instead of claiming the whole legacy surface CSS is already token-pure.

## 0.6.20 - 2026-07-22

- Replaced the Sheet grabber's local chrome offset and bar metrics with Geometry Engine tokens so the handle position, hit area, and visible bar size flow from Kiwe/Seam variables instead of component-level hard values.
- Added `--dsa-sheet-chrome-inset-block-start`, `--dsa-sheet-grabber-hit-size`, `--dsa-sheet-grabber-bar-inline-size`, and `--dsa-sheet-grabber-bar-block-size` as shell-owned chrome tokens derived from existing dock/control geometry.
- Clarified the architectural boundary: base tokens may have numeric source values, but AppShell/theme components should consume named tokens rather than embedding one-off magic numbers.

## 0.6.19 - 2026-07-22

- Tightened Companion review so direct protected AppShell surface geometry in importable `theme.css` is an error, not a missed case hidden behind dock-specific selectors.
- Added Companion review coverage for private primary combined-preview fixture classes such as `.dsa-screen-head` and `.dsa-profile-card`, aligning the lightweight Companion audit with the official combined-output validator.
- Added connector contracts so the Companion cannot drift away from the validator/audit loop used by browser AIs.

## 0.6.18 - 2026-07-22

- Anchored the Sheet close/drag handle to the sheet chrome instead of letting generous theme/module top padding push it down into the content area.
- Verified staging `0.6.17` served the National Chikki theme and opened a single Cart sheet after the DSA entry layer was dismissed; the remaining grabber offset was confirmed as a core chrome-flow issue, not a National-theme-only defect.
- Kept the National v4.8 preview/live mismatch classified as an AI handoff issue now caught by the tightened combined-output validator.

## 0.6.17 - 2026-07-22

- Moved the core sheet grabber/close handle closer to the sheet edge by decoupling it from large responsive panel top gutters, preserving the touch target while removing the excessive empty band above sheet content.
- Tightened Studio native-token saving so context packets reserve provider prompt overhead before Gemini/OpenAI-compatible calls instead of only checking raw context JSON size.
- Cleaned Site Graph Data envelopes so product/page reads report `resource: products` or `resource: pages` instead of forcing clients to infer those from a generic posts envelope.
- Tightened the combined-mode handoff validator and lite audit/toolcall docs so the primary combined preview may not use private AppShell fixture wrappers that Kiwe core does not render live.
- Verified the rule against National Chikki v4.8: the validator now fails previews that visually depend on `.dsa-screen-head`, `.dsa-screen-body`, `.dsa-profile-card`, `.dsa-score-card`, `.dsa-links-identity`, `.dsa-account-rows`, `.dsa-link-list`, `.dsa-install-steps`, or `.dsa-game-frame`.

## 0.6.16 - 2026-07-22

- Hardened the AI-less Site Graph Data route so headless clients can use simple query-string reads like `resource=products&limit=3` and documented batch POST reads without falling back to default posts.
- Added a compact `resources` batch shorthand that expands into the same normalized Site Graph Data envelopes as explicit `queries`, keeping the strict GraphQL-like route and the lightweight external-AI route aligned.
- Updated the Site Graph schema examples and rebuilt the package manifest after live staging showed `0.6.14` was still one upload behind the local AI/Companion hardening.

## 0.6.15 - 2026-07-22

- Hardened `Kiwe > AI` API-key creation so the one-time full secret reliably renders on shared hosts by using a short-lived option fallback beside the transient.
- The plain key is still deleted immediately after display and never stored as the long-lived credential; Kiwe continues to store only the hash for authentication.
- Clarified `/wp-json/dsa/v1/ai/themes` output with an explicit per-record `active` boolean for external AI/tool clients.
- Tightened Studio native-context compaction so Bricks intelligence is reduced before native model calls, and added sanitized provider error details for empty/non-2xx AI responses.
- Tightened Companion output review so protected AppShell geometry is caught for both `#dsa-surface` and `[data-dsa-surface]` theme selectors.
- Added release proof and rebuilt the package manifest so external AI / Studio / Site Graph connector testing can start from a usable freshly created key instead of a prefix-only table value.

## 0.6.14 - 2026-07-22

- Simplified `Kiwe > AI` SecureTrack controls so SecureTrack is no longer presented as a separate provider/model/API-key lane.
- Redacted SecureTrack brief sharing is now a Companion/API-scope toggle, while SecureTrack Site Brain cloud review syncs from the shared Native AI provider/key when the selected provider is supported by SecureTrack.
- Kept SecureTrack-local Site Brain review mode, batch minutes, local auto-block recommendation, and future pattern-sharing settings available without creating a separate SecureTrack key field.
- Updated docs/contracts/release proof and rebuilt the package manifest for Hostinger MU-plugin deployment.

## 0.6.13 - 2026-07-22

- Added Bricks AI Intelligence as a read-only Bricks-native context service for external AI tools, exposing available elements, compact element controls, query-loop options, dynamic tags, conditions, interactions, Seam rules, and Kiwe launcher/runtime boundaries.
- Added `bricks_ai` API-key scope plus `/wp-json/dsa/v1/ai/bricks/context` and `/wp-json/dsa/v1/ai/bricks/plan`; `studio_ai` keys can also read the Bricks intelligence packet for Studio workflows.
- Embedded Bricks AI Intelligence into Kiwe Studio AI packets so native/browser companion flows can plan Bricks-native pages without crawling Bricks or Kiwe source.
- Added an optional Kiwe Studio companion panel for the Bricks front-end editor under `Kiwe > AI`, with nonce-auth context, plan, and bounded native-draft buttons; the panel is read-only and does not save Bricks content.
- Updated Kiwe AI docs, lite toolkit contexts, development plan, connector contracts, and release proof so browser AI, native Kiwe AI, and editor companion flows share one Bricks-aware planning contract.
- Bumped the MU loader and nested Kiwe package to `0.6.13` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.12 - 2026-07-22

- Added Kiwe Studio AI as the workflow layer above Companion, with `native`, `browser_companion`, and `browser_only` operating modes under `Kiwe > AI`.
- Added encrypted native provider controls for WordPress AI Client detection, OpenAI-compatible chat completions, Gemini, Groq, and xAI, plus max context/output budgets and explicit native-generation consent.
- Added `studio_ai` and `native_ai` API key scopes and `/wp-json/dsa/v1/ai/studio/status`, `/start`, `/draft`, and `/review` routes; normal Studio keys can request context/review while `native_ai` is required to spend provider tokens.
- Added WordPress 7 ability mirrors for `dsa/start-studio-project` and `dsa/review-studio-output` and advertised Studio/Companion routes through the Site Graph connector map.
- Updated Kiwe AI docs, toolkit lite contexts, development plan, and release proof so browser AI, IDE AI, and future GitHub/tool-call clients use token-saving Studio packets instead of reading the whole plugin.
- Bumped the MU loader and nested Kiwe package to `0.6.12` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.11 - 2026-07-22

- Added Kiwe Companion AI as a deterministic, token-efficient context broker and reviewer under `/wp-json/dsa/v1/ai/companion/*`, with compact mode cards, safe answers, review-output checks, and privacy-safe finding memory.
- Added `companion` and `companion_securetrack` API key scopes so external AIs can be granted Companion access without WordPress admin credentials, and revoked instantly by deleting the key.
- Moved SecureTrack cloud AI provider/model/key controls into `Kiwe > AI`, while `Kiwe > Secure` remains focused on security enforcement and local Site Brain controls.
- Gated redacted SecureTrack AI briefs behind both `Kiwe > AI` consent and a security-capable key/ability path; internal AI context now emits a gated/off stub instead of silently including security context.
- Mirrored Companion context, ask, and review surfaces through WordPress 7 Abilities where available, preserving REST as the fallback.
- Updated Kiwe AI docs/toolkit lite contexts and the connector contract runner so external AI tools discover Companion without reading the whole codebase.
- Bumped the MU loader and nested Kiwe package to `0.6.11` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.10 - 2026-07-22

- Hardened the AppShell runtime contract guard so split compact dock spacing remains Geometry Engine-owned after installed theme CSS is applied.
- Fixed mobile split dock right-bias where a later/generated theme gap could make the actual button span overflow the centered dock shell.
- Kept the split focus/action button spacing tokenized through `--dsa-dock-split-focus-gap` and `--dsa-dock-split-focus-gap-narrow` while preventing themes from owning dock arrangement.
- Bumped the MU loader and nested Kiwe package to `0.6.10` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.9 - 2026-07-22

- Fixed the hidden PhoneKey privileged reauth timeout path that could still log out administrators while `Kiwe > Secure` Role-Based Auto Logout was off.
- PhoneKey session timeout controls now consistently treat `0` as disabled, including the REST session-status response and the admin polling guard.
- Added a one-time migration that turns the old 30-minute privileged-session defaults off unless the site owner re-enables a timeout intentionally.
- Updated the PhoneKey admin copy so “Privileged reauth minutes” clearly says `0` disables Kiwe-initiated privileged-session logout; normal WordPress cookie expiry remains separate.
- Bumped the MU loader and nested Kiwe package to `0.6.9` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.8 - 2026-07-22

- Confirmed `Kiwe > Secure` Role-Based Auto Logout is disabled on Hostinger staging; the 30-minute field is inert unless `secure[auto_logout_enabled]` is checked.
- Added the safe runtime proof hook `window.DSA.previewNotification(...)`, which seeds Kiwe's real body-level notification stack for deterministic browser/UI tests without creating push-permission, AI-action, or theme-owned notification authority.
- Tightened notification-stack geometry so transient notices stay top-right on desktop, top-safe-area on mobile, collapse into a compact cascade, and expand on hover/focus for actions.
- Polished sub-340px split compact dock geometry so the final dock control does not hang over the viewport edge on 320px stress checks.
- Updated the AI Toolkit lite contexts so future combined/audit loops use Kiwe's live notification hook instead of inventing dock-attached notification fixtures.
- Bumped the MU loader and nested Kiwe package to `0.6.8` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.7 - 2026-07-22

- Added `site-graph-system/release-proof-0.6.7.md`, a built-in Hostinger/staging verification checklist for Site Graph, Site Graph Data, SecureTrack brief, internal advisor, advisor enrichment, WordPress 7 abilities, AI access keys, staging executor boundaries, dynamic handoffs, and browser smoke checks.
- Closed the Site Graph + internal AI phase with explicit release boundaries: Kiwe may inspect, advise, enrich summaries, validate, prepare, stage, and controlled-execute on confirmed staging, but still must not silently publish, save Bricks, mutate WooCommerce, run checkout/cart/auth, process payments, or change security enforcement.
- Bumped the MU loader and nested Kiwe package to `0.6.7` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.6 - 2026-07-22

- Added `kiwe.internal-ai.enrichment.v1` through `/wp-json/dsa/v1/ai/advisor/enrich`, returning deterministic summaries, priority ordering, and the bounded model envelope for future WordPress AI Client enrichment without calling a model or mutating the site.
- Added the WordPress 7 ability `dsa/enrich-internal-ai-advisor` and advertised it in Site Graph/internal context metadata so native AI clients can discover the enrichment seam beside the deterministic advisor.
- Updated the `Kiwe > AI` Advisor panel with enrichment style controls, deterministic summary output, native-client readiness, and the matching route/ability references.
- Bumped the MU loader and nested Kiwe package to `0.6.6` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.5 - 2026-07-22

- Added a server-rendered Kiwe Advisor panel to `Kiwe > AI`, showing deterministic read-only findings, recommendations, safe next actions, model availability, context hash, focus filters, and the matching `/ai/advisor` route / `dsa/run-internal-ai-advisor` ability.
- Styled the advisor as a first-class admin control surface while preserving the no-mutation boundary: refresh recomputes the safe context and advisor output only.
- Bumped the MU loader and nested Kiwe package to `0.6.5` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.4 - 2026-07-21

- Added the deterministic Kiwe internal AI advisor at `/wp-json/dsa/v1/ai/advisor`, producing read-only findings, recommendations, safe next actions, model availability, and mutation boundaries from the fused internal context packet.
- Added the WordPress 7 ability `dsa/run-internal-ai-advisor` so native AI/tool clients can run the same advisor without crawling plugin code or inventing their own audit rules.
- Updated Site Graph connector metadata, internal context route maps, AI toolkit docs, and Site Graph docs so browser AI clients discover the advisor alongside Site Graph Data, SecureTrack brief, and staging-plan lanes.
- Bumped the MU loader and nested Kiwe package to `0.6.4` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.3 - 2026-07-21

- Added the first Kiwe internal AI context pack at `/wp-json/dsa/v1/ai/internal-context`, combining Site Graph summary/hash, Site Graph Data schema, redacted SecureTrack intelligence, WordPress 7/Abilities availability, connector routes, and safe operating boundaries without calling a model.
- Added redacted SecureTrack AI security brief support at `/wp-json/dsa/v1/ai/security-brief`, summarizing posture, local Site Brain status, AI queue status, threat lanes, alerts, and recommendations without exposing raw IPs, usernames, secrets, full URLs, request payloads, or visitor trails.
- Added AI-key routes for Site Graph Data under `/wp-json/dsa/v1/ai/site-graph-data/schema` and `/wp-json/dsa/v1/ai/site-graph-data`, with new `site_graph_data`, `security_brief`, and `internal_ai` API scopes.
- Expanded WordPress 7 Abilities integration with `dsa/get-site-graph-data-schema`, `dsa/query-site-graph-data`, `dsa/get-securetrack-brief`, and `dsa/get-internal-ai-context` so native WordPress AI/tool clients can discover the same read-only context surfaces.
- Updated the Site Graph connector manifest, AI entrypoint, dynamic context, and Site Graph docs so future AIs discover the new routes instead of scraping frontend data or guessing security context.
- Bumped the MU loader and nested Kiwe package to `0.6.3` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.6.2 - 2026-07-21

- Added the public-safe Kiwe Site Graph Data API at `/wp-json/dsa/v1/site-graph/data`, allowing headless clients, Bricks tooling, browser AIs, and external frontends to fetch normalized WordPress/WooCommerce posts, pages, products, media, terms, menus, and site identity without using the AI-only route.
- Added `/wp-json/dsa/v1/site-graph/data/schema` so clients can discover supported Site Graph Data resources, fields, examples, and boundaries without reading the plugin codebase.
- Added batch data queries so one request can fetch page-ready datasets such as site identity, primary menu, category product rails, latest posts, and media-rich cards together in a GraphQL-like envelope.
- Kept Site Graph Data read-only and public-safe by default: anonymous requests only return public/published data, authenticated administrators can request broader read fields, and all writes remain in the Controlled Executor.
- Bumped the MU loader and nested Kiwe package to `0.6.2` and rebuilt the folder-based package manifest with 232 verified files for Hostinger MU-plugin deployment.

## 0.5.93 - 2026-07-18

- Centralized registered DSA screen/sheet copy under `Kiwe > Theme > DSA screen/sheet copy` for Profile, Cart, Checkout, Search, Menu, Saved, Links, Notifications, iOS Install, Games, and AI, replacing the prior Cart-only theme-copy lane.
- Added a shared PHP screen-copy schema used by manual admin settings and installed theme packages, so `theme-package.json` `settings.screens` imports are sanitized consistently and remain presentation-only.
- Wired live DSA runtime adapters to consume installed/manual screen copy across Profile, Cart, Checkout, Search, Menu, Saved, Links, Notifications, iOS Install, Games, and AI while preserving Kiwe/WordPress/WooCommerce authority for data, state, actions, totals, search results, links, and checkout.
- Updated the theme-package schema, UI-system docs, Kiwe AI Toolkit lite contexts, and audit-output loop so browser AIs treat live-intended screen/sheet copy as part of the installed theme package instead of preview-only or Cart-only text.
- Bumped the MU loader and nested Kiwe package to `0.5.93` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.5.92 - 2026-07-18

- Added standalone Kiwe Framework profile import/export under `Kiwe > Framework`, using `schema: "kiwe.framework-profile.v1"` with a narrow `settings.tokens` payload for official universal token overrides and safe Bricks global theme-style metadata.
- Added the AI staging operation `kiwe.framework-profile.apply`, which applies a sanitized Framework token profile to Kiwe settings without implicitly pushing to Bricks; `kiwe.framework.push-bricks` remains the explicit Bricks mutation step.
- Updated full Appsite profile export/import so the new `tokens` and `theme_screens` lanes are preserved instead of silently dropped.
- Updated the Kiwe AI Toolkit, dynamic/audit lite contexts, handoff mode docs, source map, and audit-output tool so website-only Framework profiles, combined/theme `theme-package.json` token settings, and stale loose settings folders are clearly distinguished and checked.
- Added schema-backed Framework profile validation to the Kiwe AI Toolkit, including mirrored Framework-system contracts, CLI support, valid/invalid fixtures, generic output-validator integration, and connector contract checks that reject custom/private token names or AppShell/theme/page leakage from standalone `Kiwe > Framework` profiles.
- Bumped the MU loader and nested Kiwe package to `0.5.92` and rebuilt the folder-based package manifest for Hostinger MU-plugin deployment.

## 0.5.91 - 2026-07-18

- Added a safe installed-theme screen-copy lane: theme packages may now import sanitized `settings.screens.cart` labels such as cart title, empty-state copy, FBT heading, and checkout labels, so live Kiwe cart sheets can match AI/combined-preview theme copy without giving themes cart, checkout, product, price, or WooCommerce authority.
- Exposed the active installed theme's sanitized screen settings to the frontend runtime and wired the real cart adapter to consume those labels while preserving WooCommerce-owned cart data, quantities, totals, and checkout behavior.
- Fixed theme package activation so safe `style.visual_profile` presets are actually applied, letting marketplace themes opt into the modern Kiwe 2027 screen adapter instead of inheriting stale/legacy panel composition.
- Updated the UI-system, Kiwe AI Toolkit lite/full contexts, marketplace package docs, scaffold generator, and audit contracts so future AI handoffs treat preview cart copy as live only when it is declared in `theme-package.json`.

## 0.5.90 - 2026-07-18

- Fixed installed AppShell theme visual authority: custom theme CSS is now runtime-scoped to the active `#dsa-surface[data-dsa-surface].dsa-installed-theme-*` root so valid `[data-dsa-surface]` selectors can override core visual defaults while Geometry Engine placement/state remain core-owned.
- Removed core split-dock visual `!important` pressure from the focus launcher so imported themes can skin dock focus states instead of being forced back to the built-in Kiwe 2027 colors.
- Added stable live cart theme hooks (`[data-dsa-cart-line]`, `.dsa-cart-line`, `.dsa-line-thumb`, `.dsa-quantity`, `[data-dsa-cart-fbt-card]`, `.dsa-fbt-card`, `.dsa-fbt-img`) and documented them in the UI/toolkit/audit loop so preview cart skins map to the real runtime cart adapter.
- Mirrored Kiwe light/dark mode onto the Surface root as `data-kiwe-theme`, allowing theme CSS dark-mode selectors to work in production the same way they work in combined previews.

## 0.5.89 - 2026-07-18

- Fixed the Bricks HTML/CSS staging executor loop so `bricks.page.from-html` and `bricks.template.from-html` convert from the real AI handoff source while publishing only a hidden Kiwe-managed placeholder as normal WordPress content. This prevents `<style>`/CSS text from leaking visibly above the rendered Bricks page after AI imports.
- Stored the original source hash and byte budget metadata beside generated Bricks JSON so future audits can prove which handoff source created the page/template without re-publishing the raw paste payload.

## 0.5.88 - 2026-07-18

- Added a controlled Kiwe HTML/CSS-to-Bricks conversion lane for staging AI execution: `bricks.page.from-html` and `bricks.template.from-html` create/update pages/templates, convert clean Seam/HTML handoffs into Bricks element JSON, preserve classes/IDs/data/ARIA launchers, store safe CSS in Bricks page settings, and write rollback backup metadata.
- Added `Bricks_Html_Css_Converter_Service`, preferring Bricks native conversion when available and using a Kiwe fallback converter on current Bricks installs that do not expose the native server converter.
- Updated Site Graph, Site Inspection, preflight, and toolkit contexts so AI clients discover the controlled conversion path instead of depending on browser clipboard paste or hand-authored raw `_bricks` JSON.
- Added production AppShell contract hooks for theme authors: `[data-dsa-dock]`, `[data-dsa-dock-item]`, `[data-dsa-dock-focus]`, `[data-dsa-dock-primary]`, and `[data-dsa-screen]`/`data-dsa-screen-module`, while preserving legacy `.dsa-ai-launcher` compatibility.
- Relaxed safe CSS sanitization for normal CSS child selectors and `scroll-behavior` while continuing to block imports, executable URLs, expressions, legacy `behavior:`, bindings, and HTML/script payloads.

## 0.5.87 - 2026-07-18

- Added custom content discovery to AI Site Graph and Site Inspection: custom post types, custom taxonomies, registered post meta, and observed safe custom-field keys are now exposed with values redacted and secret-like keys excluded.
- Fixed controlled staging sanitization so Bricks template settings, Bricks raw payloads, and nested adapter payloads preserve case-sensitive keys such as `templateConditions`.
- Updated Bricks/template staging readiness so AI-created templates can carry front-page/home conditions without key-casing drift.

## 0.5.86 - 2026-07-18

- Completed the controlled mutation executor stage for AI/staging testing: WooCommerce product/order/settings mutations, cart runtime harnesses, checkout validation/pending-order harnesses, auth test-user runtime harnesses, and raw Bricks meta writes now run through the same explicit staging executor.
- Converted `/ai/mutations/bricks-page-save`, `/ai/mutations/wordpress-publish`, `/ai/mutations/woocommerce`, `/ai/runtime/cart`, `/ai/runtime/checkout`, and `/ai/runtime/auth` from hard locks into confirmation-required shortcuts to the staging executor.
- Added operation-specific gates for high-risk writes: `confirmWooCommerceMutation`, `confirmRuntimeExecution`, `confirmAuthRuntime`, and `confirmRawBricksJsonWrite`.
- Added rollback/audit breadcrumbs for Woo settings patches and raw Bricks meta writes, including hashes and backup meta keys for controlled staging review.
- Updated the Kiwe AI toolkit and connector contracts so future AI clients discover these capabilities without guessing or silently reaching for production authority.

## 0.5.85 - 2026-07-18

- Added the controlled staging executor for Kiwe AI API clients, gated by explicit staging confirmation before creating or updating WordPress pages/posts, Bricks templates, or Kiwe theme packages.
- Added read-only site inspection for AI clients, including installed plugin inventory, Bricks presence/version signals, safe Bricks option summaries, Bricks template inventory, page/post samples, and staging-host detection without exposing raw secrets or `_bricks` payloads.
- Added sanitized preview CSS preservation for staged AI pages/templates and a narrow `bricks.settings.patch` operation for staging-only Bricks settings probes.
- Added API scopes and REST routes for `/ai/site-inspection`, `/ai/staging/execute`, and `/ai/stages/{stageId}/execute-staging`; existing `all` keys automatically include the new capabilities.
- Updated Kiwe AI docs and connector contracts so staging tests can discover site/plugin/Bricks context first, then run only confirmed staging-safe operations.

## 0.5.84 - 2026-07-18

- Added Kiwe theme package install/export/activate as the official replacement for loose DSA settings import/export. Theme packages now carry the manifest, presentation CSS, and a safe theme settings preset, and imported themes appear under `Kiwe > Theme > Installed themes`.
- Added `Theme_Package_Service` with reserved built-in themes, CSS import safety checks, protected AppShell geometry rejection, sanitized activation overlays, and support for URL-only custom dock links inside theme package settings.
- Added `/wp-json/dsa/v1/ai/themes`, `/ai/themes/install`, and `/ai/themes/{themeId}/activate` for revocable-key AI clients to push and activate DSA theme packages without receiving arbitrary WordPress settings authority.
- Added explicit locked AI discovery routes for Bricks page saves, WordPress publish operations, WooCommerce mutations, and cart/checkout/auth runtime actions. These routes advertise the boundary and remain locked behind a future controlled staging-site executor.
- Updated the AI toolkit, UI brain, marketplace docs, and audit tooling so combined/theme handoffs produce a single importable `theme-package.json` instead of a separate `kiwe-settings` profile folder.

## 0.5.83 - 2026-07-18

- Split Kiwe AI connector administration into `Kiwe > AI`, leaving `Kiwe > Framework` focused only on Seam/Kiwe Framework variables, palette, class vocabulary, and Bricks framework push/download settings.
- Added revocable, scoped Kiwe AI API keys generated from `Kiwe > AI`. Keys are shown once, stored only as hashes, track last use, and can authenticate external tool clients with `Authorization: Bearer ...` or `X-Kiwe-AI-Key`.
- Added `/wp-json/dsa/v1/ai/*` API-key-protected connector endpoints for Site Graph discovery, binding validation, dry-run apply-plan preparation, trusted stage creation, and the full non-mutating trusted-apply artifact chain.
- Updated AI/tooling docs so browser AIs, IDE agents, and developers use `Kiwe > AI` for Site Graph export, binding-plan upload, trusted staging review, and API-key connector access.

## 0.5.82 - 2026-07-18

- Began the Kiwe AI/connector integration track for dynamic WordPress + Bricks handoffs.
- Added the read-only `kiwe.site-graph.v1` service with admin-only REST access at `/wp-json/dsa/v1/site-graph` and a WordPress 7 Abilities API surface as `dsa/get-site-graph` when abilities are available.
- Added a `Kiwe > Framework` AI connector card and admin-only Site Graph JSON download so non-developers can hand a target site's real WordPress/Bricks/Woo/Kiwe context to an AI without exposing the full plugin or relying on public REST crawling.
- Added non-mutating `kiwe-bindings.json` intake on `Kiwe > Framework` with a PHP-side binding validator, so admins can upload an AI dynamic binding plan and see pass/fail/warning reports against the live Site Graph before any Bricks apply path exists.
- Added `connector-system/` as the third portable brain beside `ui-system/` and `framework-system/`, documenting how accepted Seam/AppShell handoffs become Bricks query-loop and dynamic-data binding plans without giving AI cart/search/auth/checkout/runtime authority.
- Added `kiwe-ai-toolkit/contexts/dynamic-lite.md`, CLI commands, and MCP tools for v5-style dynamic binding passes that consume a target Site Graph instead of guessing site categories, products, pages, Bricks dynamic tags, or query-loop object types.
- Added the `kiwe.bricks-bindings.v1` schema, `validate-bindings` CLI/MCP validator, and a fixture-backed test so dynamic passes can be checked for real Site Graph terms, Bricks query-loop object types, dynamic tags, canonical Kiwe launchers, and non-mutating apply authority.
- Added the `kiwe.bricks-apply-plan.v1` dry-run apply planner plus `prepare-apply-plan` CLI/MCP tooling. It turns a validated binding plan and Site Graph into preflight gates, Bricks query-loop/dynamic-data operations, Kiwe launcher/menu-context operations, manual-review items, and future adapter steps without mutating WordPress.
- Added the same dry-run apply-plan preview to `Kiwe > Framework > AI connector and Site Graph` after binding-plan upload, so admins can inspect planned Bricks/Kiwe operations and preflight gates against the live target site before any trusted adapter exists.
- Added a nonce-protected admin download for the live dry-run apply plan JSON, making the WordPress-reviewed `kiwe.bricks-apply-plan.v1` artifact portable for the next trusted-adapter stage without exposing secrets or writing Bricks data.
- Added the first trusted-adapter staging layer: admins can stage a validated dry-run apply plan as `kiwe.trusted-apply-stage.v1`, storing only a capped Kiwe-owned review candidate with plan hash, gates, blockers, counts, and future apply requirements. This still does not write Bricks/page data.
- Added the trusted-adapter proof layer: admins can run `kiwe.trusted-adapter-proof.v1` against a staged candidate and the current live Site Graph to verify Bricks/adapter capability signals, map operations for future review, surface blockers, and attach proof metadata without saving Bricks or WordPress page content.
- Added guarded future-apply authorization: a proven stage can receive `kiwe.guarded-apply-authorization.v1`, recording human/admin authorization for a future trusted adapter while explicitly refusing to mutate Bricks, WordPress, WooCommerce, or publish content in this batch.
- Added the pre-execution gate: authorized stages can receive `kiwe.pre-execution-gate.v1`, the final non-mutating checkpoint before any future trusted adapter exists. It revalidates stage/proof/authorization hashes and records rollback, rendered-preview, final-confirmation, smallest-mutation, post-apply audit, and browser-smoke requirements.
- Added the trusted execution preview: gated stages can receive `kiwe.trusted-execution-preview.v1`, a rehearsal artifact that maps operations to rollback, rendered-preview, final-confirmation, and post-apply audit requirements without saving Bricks, WordPress, WooCommerce, or publish state.
- Added the final apply confirmation lock: previewed stages can receive `kiwe.final-apply-confirmation.v1` only after an explicit admin checkbox confirms the exact execution preview. The artifact allows a future adapter to be built while still refusing immediate Bricks, WordPress, WooCommerce, or publish mutation.
- Added fresh Site Graph revalidation: confirmed stages can receive `kiwe.fresh-sitegraph-revalidation.v1`, checking the current live Site Graph for Bricks availability, post types, taxonomy terms, dynamic tags, warnings, and blockers before any future adapter is allowed to proceed.
- Added rollback readiness checkpoints: fresh-revalidated stages can receive `kiwe.rollback-readiness-checkpoint.v1`, locking artifact hashes and required rollback captures while clearly marking that no actual Bricks/WordPress revision has been captured or mutated yet.
- Added target resolution: rollback-ready stages can receive `kiwe.target-resolution.v1`, requiring an explicit target post/page/template ID and locking the future adapter scope to that exact WordPress object without saving or mutating content.
- Added rollback capture: target-locked stages can receive `kiwe.rollback-capture.v1`, storing a Kiwe-owned snapshot of the resolved target's WordPress fields plus relevant Bricks/Kiwe/DSA meta before any future adapter mutation. This writes only Kiwe staging metadata and still does not save Bricks/page content or create a WordPress revision.
- Added rendered target baseline inspection: rollback-captured stages can receive `kiwe.rendered-target-inspection.v1`, summarizing the locked target's current post content, Bricks meta shape, estimated Bricks nodes, and operation selector coverage as warnings/manual review before any future adapter mutation.
- Added the minimal adapter shell: render-inspected stages can receive `kiwe.minimal-adapter-shell.v1`, selecting the least-risk future apply route and allowed operation set while still refusing Bricks saves, WordPress updates, publish actions, and WooCommerce mutations.
- Added final save approval readiness: shelled stages can receive `kiwe.final-save-approval.v1`, an explicit-checkbox approval artifact that locks the exact shell, rollback capture, rendered inspection, post-apply audit plan, browser smoke plan, and rollback verification plan without executing a Bricks or WordPress save.
- Added safe connector WordPress Ability surfaces for MCP/AI clients: `dsa/validate-bindings`, `dsa/prepare-apply-plan`, and `dsa/stage-apply-plan` now sit beside `dsa/get-site-graph`, letting capable clients validate, dry-run plan, and stage Kiwe review candidates without saving Bricks, WordPress, WooCommerce, or publish state.
- Added the controlled executor skeleton: save-approved stages can receive `kiwe.controlled-executor.v1`, defining the future adapter interface, pre-mutation checklist, approved operation IDs, and audit/smoke/rollback obligations while explicitly keeping `adapterImplementationPresent`, `actualSaveExecuted`, and `mayExecuteMutationNow` false.
- Added the Bricks controlled adapter planning layer: executor-ready stages can receive `kiwe.bricks-controlled-adapter.v1`, translating approved query-loop, dynamic-field, launcher, and menu-context operation IDs into deterministic Bricks/Kiwe adapter instructions while keeping actual Bricks/WordPress mutation locked until post-apply verification and rollback proof are wired.
- Added post-apply verification and rollback proof planning: adapter-ready stages can receive `kiwe.post-apply-verification.v1`, selecting the smallest future controlled run, recording post-apply render/audit/smoke checks, and proving the rollback restore source from the captured snapshot while still refusing any Bricks/WordPress mutation.
- Source-reviewed local Bricks 2.4 beta AI abilities, query-loop, dynamic-data, HTML/CSS conversion, global-query, Woo setup, and import/export surfaces; updated Kiwe Bricks admin copy/version marker to reflect the 2.4 beta source review while preserving existing Bricks compatibility boundaries.

## 0.5.75 - 2026-07-16

- Completed the Seam/Kiwe Framework integration track: production-safe Seam CSS, canonical vocabulary, protected DSA shadow landmarks, public adoption map, runtime inspection helpers, and DSA-safe public class adoption for low-risk text/price landmarks.
- Exported the Kiwe Framework to Bricks as additive `kiwe-*` variables, the Kiwe Universal color palette, and curated Kiwe Seam global classes/categories while keeping Bricks as page-design authority.
- Added UI-system marketplace/handoff guardrails for theme authors: package validation, whole-handoff validation, adoption-map acknowledgement, standalone preview rules, FBT rail proof, dock mode/shape coverage, optional Links site-score absence, and invalid Seam fixture regression checks.
- Added `tools/ui-theme/audit-seam-adoption.cjs` and `ui-system/integration-proof-2026-07-16.md` so the framework brain can be verified before release prep.
- Renamed `Kiwe > Tokens` to `Kiwe > Framework` because the admin action now pushes the broader framework vocabulary to Bricks, not just raw tokens. The old `kiwe-tokens` admin slug redirects to the new Framework page for compatibility.
- Added `framework-system/` as a portable Kiwe Framework handoff folder for web developers, Bricks designers, and AI assistants. It separates framework usage from `ui-system/`, which remains the AppShell/theme brain.
- Tightened the framework after external review: Seam runtime now supports the flat vocabulary contract, mirrors collapsed state, avoids self-referential scene intensity, implements heading identity classes, and flags shadow-only public classes inside live Kiwe roots. Added `framework-system/HANDOFF-LITE.md`, clarified that Seam-built websites/pages are not Kiwe AppShell themes, and documented Bricks 2.4 beta HTML-to-Bricks conversion as the intended standalone website-preview path.
- Added `framework-system/handoffs/website-builder/` as a one-folder handoff for AI/web developers/Bricks designers building normal websites or pages with Kiwe/Seam. It contains only the practical prompt, token/vocabulary contracts, runtime CSS/JS, and Bricks docs needed for website work.
- Made Seam roles semantic/headless by default and removed the recipe layer entirely for now: `data-role` and `.seam-*` role classes now describe meaning without forcing generic card/button/modal padding, background, border, shadow, radius, flex layout, gap, or color. Bricks export stays focused on tokens, neutral flows, semantic roles, states, tones, motion, and explicit utilities; website art direction belongs in site CSS/classes backed by Kiwe/Seam tokens.
- Added the Seam Class Vocabulary as a neutral/searchable Bricks class library: 21 Kiwe Seam categories and 276 generic class handles covering core roles, content, commerce, navigation, disclosures, tables/data, media, forms, sizes, density, emphasis, placement, aspect, flow controls, and utilities. These classes are exported to Bricks for designers to style; they are not recipes and do not ship a default visual identity.
- Synchronized the root MU loader, nested package version, and package manifest for folder-based MU deployment.

## 0.5.61 - 2026-07-14

- Made the recent Sheet spacing/origin/width controls profile-neutral so Legacy and Prototype 2027 can both use the same shell geometry options.
- Restricted split dock rendering to `Presentation: Dock`; Navigation bar ignores split styling even if the split option is enabled.
- Fixed AI/action icon contrast in Navigation bar and split Dock modes so the emphasized icon remains visible.
- Refined Sheet handle and inset/above-dock corners with rounded bottom corners, stable scrollbar gutter, and a less intrusive sticky grab handle.

## 0.5.60 - 2026-07-14

- Fixed dock ordering so the frontend respects the exact `Kiwe > Dock` drag-and-drop sequence instead of re-centering AI after admin save.
- Added an inset sheet width percent control with a guarded 50-90% range and a 78% default, replacing the cramped fixed-width inset sheet behavior.
- Strengthened Prototype 2027 split-dock AI/action button contrast so the AI icon remains visible inside the emphasized center/action slot.

## 0.5.59 - 2026-07-14

- Added `Kiwe > Theme` Sheet controls for `space around` (`Edge-to-edge` or `Inset / space around`) and `starts from` (`Screen bottom` or `Above dock`) so bottom sheets can match the floating prototype card layout without forcing full viewport width.
- Added `Kiwe > Dock` split style for horizontal Prototype 2027 docks. The existing drag-and-drop dock order remains authoritative; split styling visually groups icons around the emphasized AI/action button wherever the site owner places it.
- Added renderer classes/data attributes for sheet spacing, sheet origin, and dock split state, with CSS scoped to the existing visual-profile/theme contracts.

## 0.5.58 - 2026-07-14

- Added `Kiwe > Theme` visual profiles: `Legacy UI` remains the default preserved baseline, while `Prototype 2027` becomes the isolated prototype-inspired app UI track.
- Scoped the prototype flat styling to the new `dsa-visual-prototype` runtime class instead of applying it globally to every contract-v2 Surface.
- Hardened context rail behavior so checkout, AI, and other relocated controls cannot float in Sheets or Prototype 2027. The rail can only run when explicitly enabled in Legacy Classic.
- Added `data-dsa-visual-profile` and visual-profile classes to the Surface renderer so future marketplace themes can target a profile without replacing the shell contract.

## 0.5.57 - 2026-07-14

- Restored real-world MU deploy tolerance: the package verifier now disables Kiwe only when required runtime files are missing or the manifest itself is unreadable/invalid. Non-critical manifest drift and host/FTP text normalization are logged as diagnostics instead of killing the Surface.
- Improved package failure diagnostics by logging and showing the missing required file sample, and by reporting runnable-with-drift status in `Kiwe > Developer`.

## 0.5.56 - 2026-07-14

- Emergency stabilization release after the first prototype-adoption batch: bumped loader/package/cache-busting version and rebuilt the folder-based MU package manifest.
- Changed dock context rails from default runtime behavior to an experimental opt-in Dock setting. Panel controls now remain inside their owning Surface screen by default, matching the prototype direction and reducing future theme fragility.
- Hardened the custom taxonomy alphabet SQL helper to use variadic `wpdb::prepare()` arguments instead of the array-argument form for broader WordPress compatibility.
- Removed frontend `:has()` selector dependency from the public Surface CSS so visitor UI does not depend on selector-list behavior in older embedded browsers.
- Updated the docs/contracts to treat context rails as optional legacy/experimental geometry instead of a required theme surface.

## 0.5.55 - 2026-07-14

- Began controlled adoption of the external `kiwe-surface-2027` prototype without editing or depending on the `ui/` reference folder.
- Replaced the heavier shadow/glass polish layer with a flatter contract-v2 Surface layer that uses solid token surfaces, hairline separators, stronger badges, and lower-paint hover/active states while leaving Appsite Home and transition screens unchanged.
- Added `Auto` Dock orientation to `Kiwe > Dock` for Desktop, Tablet, and Phone. The Geometry Engine now resolves Auto from measured viewport shape/space instead of user-agent assumptions.
- Added `Kiwe > Search` custom category filters through comma-separated public taxonomy slugs. Configured taxonomies expose a Categories filter and return term archive results alongside Products, Posts, and Authors.
- Fixed Menu table-of-contents clicks by waiting for the shared overlay-close lifecycle before scrolling to the target heading, with hash replacement and scroll-margin protection for dock/admin-bar reserves.
- Updated loader/package versions and package manifest for folder-based MU deployment; no ZIP artifact is required.

## 0.5.54 - 2026-07-13

- Lead handoff release: completed the pre-integration hardening plus controlled htmx/Alpine batch set, then synchronized loader/package/manifest versions for the canonical MU-plugin folder deployment.
- Hardened Schema/GEO JSON-LD output against script-tag breakout by encoding HTML-significant JSON characters before emitting the `application/ld+json` script.
- Removed browser-controlled frontend debug activation through `?dsa_debug=1` and `localStorage DSA_DEBUG=1`; console traces now require server-side diagnostics settings and pass through recursive secret redaction.
- Applied the same diagnostics redaction policy to Bricks mini-cart and add-to-cart inline runtimes.
- Wired the existing `service_worker` setting into the PWA runtime so the manifest can remain available while the service-worker endpoint/register path is disabled and stale Kiwe workers/caches are retired.
- Added latest-response guards for cart, checkout, lazy presentation, and account subview rendering to reduce stale async repaint races.
- Tightened SecureTrack containment before htmx/Alpine work: unauthenticated `stp_*` runtime bypasses are no longer broadly exempt, webhook URLs fail closed to HTTPS public hosts only, and CSV exports neutralize spreadsheet formula injection.
- Added Batch 1 htmx/Alpine foundation with local vendored htmx `2.0.10` and Alpine `3.15.12`, default-off Developer gates, sanitized/exportable enhancement settings, frontend enqueue handles, and public runtime capability metadata.
- Added Batch 2 and Batch 3 pilots: htmx now refreshes the Developer package-proof fragment through a nonce/capability-guarded admin AJAX endpoint, and Alpine is limited to local checkbox preview state inside the Developer enhancement card.
- Added a source contract for the htmx/Alpine boundaries so future work keeps these libraries local, gated, and out of PhoneKey/auth, checkout/payment, cart reconciliation, service-worker, history, focus, and Surface lifecycle authority.
- Corrected the MU release workflow after lead review: deployment remains the root MU loader plus the `dsa/` package folder, with no ZIP artifact required.
- Fixed page-level first-cart confetti by rendering cart celebration layers against the document body when no Cart/Checkout overlay is open.
- Preserved cart-mutation intent across WooCommerce fragment event bursts so `added_to_cart` cannot be overwritten by a later non-mutation refresh before first-cart confetti logic runs.
- Improved Appsite Home dismissal on laptops and desktops with trackpads: ArrowDown/PageDown/Space now dismiss the screen, precision wheel gestures accumulate reliably, and the prompt advertises the keyboard path.
- Scoped the Developer package-proof htmx refresh control so it is visible only when the htmx enhancement gate is enabled; otherwise the server-rendered static proof remains visible with explanatory copy.
- Hardened delegated Surface/Admin event handlers so non-Element event targets cannot break click/drag delegation through direct `event.target.closest(...)` calls.
- Added a lightweight modern app-shell visual layer for contract v2 surfaces: dock glass, sheet/classic panel materials, app-card/buttons, and Appsite Home styling now consume existing tokens without adding runtime work or new blocking assets.
- Updated the package manifest hashes for the edited source files and kept loader/package/manifest synchronized at `0.5.54`.
- Reopened htmx/Alpine as a controlled integration track after the earlier `0.5.43` rejection; adoption is now limited to server-owned fragments and isolated local UI state, not PhoneKey/auth, checkout/payment authority, or the Surface shell state machine.

## 0.5.53 - 2026-07-06

- Restored first cart-add confetti through a commerce-aware target that works from Cart, Checkout, and page-level cart mutation events.
- Centralized AI/push/permission notification dismissibility so ordinary notifications share swipe dismiss behavior while required-action prompts stay locked.
- Fixed Sheet checkout scroll origin so the first WooCommerce checkout fields, including first name, are reachable instead of starting above the visible sheet.
- Parked the remaining vertical full-height Navigation bar scroll/click interference in the UI audit instead of tracking it as fully fixed.
- Extended UI contracts for notification dismissibility, confetti targeting, and Sheet checkout scroll geometry.

## 0.5.52 - 2026-07-06

- Aligned Surface presentation CSS with the UI token contract without changing shell geometry or runtime ownership.
- Fixed reduced-motion handling so unread AI launcher ring motion is suppressed together with the badge pulse.
- Moved Search, Saved, notification, and iOS install headings to readable UI text tokens instead of active-state color.
- Tokenized approved semantic color paths for auth errors, checkout errors, logout, cart prices, loader title, and Classic radial glows.
- Normalized Surface control font stacks to `--kiwe-font-ui` and standard browser font weights.
- Added a WordPress-native admin accent alias so LPM and Theme admin accents follow `--wp-admin-theme-color` with the core blue fallback.

## 0.5.51 - 2026-07-05

- Bumped the coherent MU loader/package/cache-busting version to `0.5.51` so the corrective surface and checkout fixes are visible after deployment.
- Repaired Sheet-mode bottom handle geometry so Links and checkout sheets center the grabber on the visible panel instead of inheriting a shifted width.
- Scoped Sheet overlay height to the visual viewport and preserved vertical page gestures behind inactive full-height side navigation bars.
- Restored relocated Profile dock-context actions so Downloads, Addresses, and Password open their owned sheets from the desktop context rail.
- Persisted complete, valid logged-in billing and shipping draft address groups to WooCommerce customer addresses without changing final checkout authority.

## 0.5.50 - 2026-07-05

- Reasserted the cache-safe boot contract: the initial payload now carries neutral protected-flow state and public-only commerce availability/routes/settings, while live cart, protected-flow, and personalized commerce state hydrate through the private no-store runtime endpoint.
- Migrated DSA cart mutation throttles and Search analytics dedupe to the shared atomic rate limiter, and made Search result caching object-cache-only.
- Hardened controlled editorial host validation for current-host staging domains and gated the legacy `/fragment` client path behind the server fragment-navigation policy.
- Restored the strict RC9B payload contract by moving Menu, Saved, Games panel, notifications, iOS install, and dynamic Appsite Home markup into the pure first-use `surface-panels.js` presentation module.
- Extended RC5 and RC6 source contracts so neutral commerce boot, neutral protected-flow boot, cart throttles, Search result caching, and Search analytics dedupe cannot regress silently.

## 0.5.43 - 2026-07-05

- Added a first-class tablet Dock profile and normalized mobile/tablet/desktop geometry state for themes and marketplace modules.
- Consolidated Dock placement controls into clear Desktop, Tablet, and Phone cards.
- Replaced device-duplicated Navigation bar placement with one runtime-state CSS contract while retaining internal safe-area padding.
- Added an accessible Sheet handle with directional drag dismissal through the shared Surface lifecycle.
- Sheets no longer close from empty panel or backdrop clicks; Classic click-away behavior remains unchanged.
- Kept AI geometry equal to other Dock controls and improved long cart/FBT title containment.
- Reviewed and selectively absorbed the external UI proposal; HTMX, Alpine.js, repeated selector stacks, and a second deploy tree were rejected.
## 0.5.42 - 2026-07-05

- Removed the historical Kiwe Surface and Attributes menu entries; App, Dock, Theme, Games, Links, Search, Menu, and Developer now expose settings according to runtime ownership.
- Added an explicit Dock or Navigation bar presentation. Navigation bars fill their viewport axis, attach to a selected device-specific edge with zero outer gap, and retain safe-area padding inside.
- Isolated Classic-only visual controls from Sheet-only controls and stopped Sheet mode from receiving Classic glass, material, and directional motion classes.
- Made partial App, Theme, Menu, and Dock saves preserve settings owned by other admin pages.
- Moved PWA/permission journeys to App and production readiness plus builder-neutral attribute references to Developer.

## 0.5.35 - 2026-07-04

- Reproduced the Bricks Search-close regression on the live Hostinger shop and traced it to Kiwe's synthetic overlay-history cleanup leaking a `popstate` into Bricks.
- Programmatic Surface history release now stops third-party history handlers because it is overlay cleanup, not page navigation.
- Search now persists Bricks' native filter snapshot after the explicit `surface:history:released` lifecycle event rather than racing the pending history operation.
- Added source and browser contracts for the corrected history ownership boundary.

## 0.5.34 - 2026-07-04

- Made DSA-to-Bricks Search persistence own the post-close lifecycle instead of relying on delayed repainting.
- After a Surface close releases its synthetic history entry, DSA now performs one authoritative Bricks filter request and stores Bricks' native selected-filter and instance-value history snapshot.
- Extended the UI conformance harness to require durable Bricks history state as well as the visible filtered result.

## 0.5.33 - 2026-07-04

- Fixed the Bricks 2.3.7 Search bridge race where an older unfiltered query response could replace the DSA-filtered page results after the Search Surface closed.
- DSA now follows Bricks' native Filter Search sequence by aborting an in-flight query before registering and fetching the new term.
- Bridge reconciliation now repairs both stale input values and a dropped Bricks selected-filter registration, including a bounded check after the Surface closes.
- Extended the UI conformance harness to prove request abort, selected-filter recovery, close persistence, cards, alphabet drill-down, and product quick-add together.

## 0.5.32 - 2026-07-04

- Restored the RC5 cache-safe boot contract after the live regression sweep found that emergency hotfixes had reintroduced a session REST nonce and personalized PhoneKey/cart state into cacheable page HTML.
- Browser runtime hydration now uses a private, same-origin `admin-ajax` read. WordPress can authenticate existing login cookies there without embedding a user nonce in the reusable shell.
- The REST hydration route remains available for diagnostics; both hydration transports retain private/no-store, cookie-varying responses and cross-site rejection.
- PhoneKey now emits a neutral boot shape and receives identity, cart, admin capability, and fresh REST nonce only through private hydration.

## 0.5.31 - 2026-07-04

- Fixed a cross-admin settings bug where saving any non-Search Kiwe form could silently disable Products, Authors, progressive alphabet, product quick-add, context awareness, and the Bricks Search bridge.
- Added a one-time, signature-specific recovery for installations already collapsed to the unintended Posts-only state. Deliberate Search configurations are preserved.
- Search REST responses now declare enabled families and alphabet capability so live certification can distinguish disabled configuration from an empty catalog.
- Extended the RC13 public Hostinger preflight with Search capability-state diagnostics.

## 0.5.30 - 2026-07-03

- Recovery: completed the interrupted release so package proof and runtime files are coherent again; Kiwe no longer disables itself because edited files are missing from the generated manifest.
- Fixed stale WordPress REST nonce recovery across Cart, Saved, notifications, metrics, account reads, uploads, and other DSA runtime calls. A rejected cached nonce is refreshed once through the same-site nonce endpoint before the action is retried.
- DSA Search no longer sends a WordPress login nonce because search is a public same-site read. Cached HTML can no longer disable search with rest_cookie_invalid_nonce.
- Runtime hydration keeps logged-in identity when its nonce is current and refreshes once when cached HTML carries an expired nonce.
- PWA service workers now treat DSA assets and dsa/v1 REST traffic as network-fresh/no-store while preserving bounded offline fallbacks.
- Added Kiwe > Developer with targeted Kiwe runtime cleanup, this-browser service-worker/cache cleanup, portable settings export, and a separate configuration-only reset that preserves users, orders, PhoneKey credentials, analytics, and SecureTrack data.
## 0.5.29 - 2026-07-03

- Hotfix: hardened normal visitor runtime against host/page cache serving stale HTML. Runtime hydration, cart/search REST reads, cart/auth mutations, metrics, nonce refresh, and health probes now use explicit no-store headers plus a unique runtime cache marker.
- Hotfix: PhoneKey now forces a fresh runtime hydration and nonce refresh before surfacing cookie-check failures, reducing stale-page false failures after deployment or cache restore.
- Hotfix: DSA Search now receives the refreshed runtime nonce so cached boot payloads cannot leave the search module with stale credentials after hydration succeeds.
- Note: if a host is still serving an older script tag such as `surface.js?ver=0.5.26`, purge Hostinger/LiteSpeed/page cache once after upload so the browser can load this package.

## 0.5.28 - 2026-07-03

- Hotfix: added a browser-side Kiwe health report for Hostinger/live-site debugging. Open any page with `?dsa_debug=1` or run `await DSA.healthCheck()` in the console to verify boot payload, REST nonce, runtime hydration, cart/search endpoints, and lazy module asset availability without exposing cookie values.
- This does not change normal visitor behavior; it gives deployment proof when cart/search/PhoneKey/AI appear empty after upload so stale cache, mixed versions, blocked REST, or missing module assets can be identified directly.

## 0.5.27 - 2026-07-03

- Hotfix: restored critical first-paint PhoneKey, account, cart, protected-flow, and commerce state in the boot payload. Runtime hydration remains a refresh path, not the only source of live visitor state.
- This prevents empty cart/search/profile/AI states on hosts where hydration is delayed, cached, or blocked before the first interaction.

## 0.5.26 - 2026-07-03

- Hotfix: bootstrap now carries a REST nonce so cart, search, and PhoneKey can recover even if runtime hydration is delayed or rejected.
- Hotfix: same-site REST origin checks now accept the current request host as well as `home_url()`, covering Hostinger temporary/staging domains and www/non-www access without allowing cross-site mutations.
- Hotfix: PhoneKey retries once with a refreshed REST nonce when a cookie nonce fails during the first secure action.

## 0.5.25 - 2026-07-03

- Added deterministic full-package SHA-256 inventory and cached runtime verification.
- Added release metadata, compatibility matrix, CI contracts, upgrade/rollback runbook, and production-oriented README.
- Preserved fail-open WordPress boot behavior for incomplete or mixed-version uploads.

## 0.5.24 - 2026-07-03

- Added bounded WordPress 7 Abilities and a feature-detected Interactivity API bridge.

## 0.5.23 - 2026-07-03

- Closed source-level SEO, AdSense, consent, no-JS, and cache compatibility contracts.

## 0.5.22 - 2026-07-03

- Completed thin first-use presentation modules and the 71-variant UI conformance baseline.

## 0.5.12 - 0.5.21

- Closed mutation authorization, reward/commerce abuse, identity recovery, crypto, cache-safe boot, shared-host write budgets, PWA/Push efficiency, runtime boundaries, and the initial thin Surface milestones.

Earlier experimental history is preserved in the architecture document rather than reconstructed as release claims.
