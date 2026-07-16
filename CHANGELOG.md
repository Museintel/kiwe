# Changelog

All notable pre-1.0 release-candidate changes are recorded here. Architectural history remains in `docs/DSA-ARCHITECTURE.md`.

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
