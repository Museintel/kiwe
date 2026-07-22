# Changelog

All notable pre-1.0 release-candidate changes are recorded here. Architectural history remains in `docs/DSA-ARCHITECTURE.md`.

## Unreleased

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
