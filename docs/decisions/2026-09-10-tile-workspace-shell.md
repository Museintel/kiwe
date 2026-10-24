# Tile-based client application on the WordPress origin

10 September 2026. **Status: accepted product direction and lead architecture decision; local interaction prototype only.** This supersedes the earlier assumption that a restyled wp-admin sidebar plus Home shortcuts would satisfy the final client experience. It does not change the installed RC82 runtime or its native authority/security model.

## Trigger and desired experience

After using the actual client account, the founder explained that Windows 8 and Nokia Lumia are the navigation reference: varied-size, colored tiles should be the primary way to enter business tasks. The visible WordPress sidebar and cards leading into ordinary admin screens are an incomplete interpretation of that vision. The desired client product is a coherent application, not just a prettier Home page. React is a suggested means; beauty, understandable workflows and measured speed are the goals.

The client lands on a full-width tile Home with no permanent WordPress admin sidebar or admin toolbar. A stable top bar, Home/back controls, task headings and browser history keep navigation legible. Tile colors identify task families, always alongside text/icons; colors are not the only status signal. Tile sizes reflect task priority and useful content. Do not reproduce hidden Windows edge gestures, auto-flipping tiles, decorative live counters or mandatory animations. Mobile layouts reflow rather than horizontally panning a desktop Start screen.

## Architecture

| Surface | Rendering and ownership | Loading boundary |
| --- | --- | --- |
| Public website | Bricks/theme continues rendering normal WordPress pages, native loops and dynamic fields | Public HTML, cacheability, SEO and art direction stay independent of the management app |
| Client workspace | Dedicated same-origin React application delivered by the Kiwe plugin; WordPress owns accounts, sessions, capabilities and records; Woo owns commerce | Shell and permitted task modules only; task bundles and paginated data on demand |
| Visitor DSA | Existing lightweight visitor surface, incrementally aligned with the same tokens and interaction primitives | No administrative module imports, private bootstrap or dashboard responses on visitor pages |
| Technical owner | Existing explicit Kiwe owner retains WordPress/Bricks technical access and recovery paths | Separate role and surface; client navigation is never a replacement for server authorization |

The intended first implementation serves compiled static JavaScript/CSS through the existing PHP/WordPress installation. It requires no production Node process, Next.js server, new hosting account or separate database. WordPress's REST API supports same-site cookie authentication with a REST nonce and server-side capabilities. WordPress also provides a React-based element package. These are implementation facilities, not performance guarantees. [REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/), [WordPress element package](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-element/).

Reserve a dedicated workspace route after checking site collisions; the route name is not an authentication mechanism. Gate its private bootstrap server-side, return private/no-store responses, and do not invoke the standard WordPress admin chrome for the completed shell. Keep the native fallback routes until their task coverage is verified. New routes must coexist with existing Key login return paths, explicit owner selection, wp-admin entry redirects, webhooks, REST, uploads and admin-post actions. No per-request rewrite flush or schema work.

REST adapters reuse existing domain services, validation, WordPress/Woo writers and cache invalidation hooks. Each exposed task needs capability and object checks, a bounded read model, request validation, stale-edit protection and a real saved-state readback. Reuse existing compatible endpoints instead of duplicating them. A bootstrap supplies only the signed-in person's permitted task catalog and minimal identity/context; hiding an unauthorized tile never grants or revokes permission. Do not ship API keys or Woo consumer secrets to the browser.

Use React for the private client application's routing, forms and state. Keep the shell mounted as complete task screens change, and lazy-load route code. [React lazy loading](https://react.dev/reference/react/lazy). Decide the production React packaging against the supported WordPress version and editor integration: reuse registered WordPress dependencies when appropriate; never mount incompatible copies into the native editor. The independent preview pins its own React version for reproducibility; this is not a decision to bundle a second React into every WordPress screen.

Share a design system and small audience-neutral components with DSA; do not equate reuse with loading one large application everywhere. Seam_Token_Service remains the current token authority. Map the proposed tile family colors into that system during integration; the preview's scoped colors are exploratory design values, not new stored site settings. Keep DSA's existing visitor runtime until a measured component migration justifies changing it. Bricks does not need to become React for either surface to work.

## Speed and shared-host constraints

React can preserve navigation/form state and reduce full-page reloads; it does not accelerate MySQL, PHP bootstrap, a slow REST handler or a hosting outage. Keep public Bricks pages cacheable. Administrative code must contribute zero bytes to ordinary public pages. Read only the current screen, use server pagination and shared request results, cancel stale reads, and do not query every tile independently. Do not poll every tile or store per-visitor counters merely to make Home feel alive.

Initial engineering targets for the connected reference slice are at most 100 KiB compressed for first-load shell JavaScript plus CSS, excluding a task editor loaded on demand; at most two startup API calls after private bootstrap; and zero automatic background polling while idle or hidden. These are design targets to validate, not current WordPress benchmarks. Measure cold/warm transfer, API/query cost, CPU/interaction time and navigation on the same supported lab. Cache safe assets; never put private responses or drafts in visitor service-worker caches. Offline administration remains out of scope until conflict, expiry and security handling are designed.

## Editing and compatibility

An original React screen can manage ordinary structured tasks: category organization, teams, navigation, business facts, selected media and supported product fields. A handwritten textarea is not a substitute for Gutenberg or arbitrary builder content. Preserve native WordPress block semantics, revisions, autosave, upload permissions and extension behavior. Integrate the supported editor with branded task chrome where practical, loading it only for editing; use an explicit temporary compatibility boundary for complex native workflows until equivalent functionality is proven. Do not fetch arbitrary wp-admin HTML into React or use a blanket iframe as the architecture.

The first connected batch must fix the already verified Woo owner-role compatibility issue and client Administrator product-permission gap. These remain server-side prerequisites under either UI. Do not make clients technical owners to get the new screens working, disable Key enrollment, or clone business settings into a new React store.

## Deliverable and migration gates

1. This turn: canonical local React prototype of the tile Home, internal task navigation, sample forms, role previews, unsaved-edit handling and a related visitor DSA composition. No live API connection or deployment. [Prototype instructions](../../tools/workspace-prototype/README.md).
2. Next connected candidate: server-gated shell and capability-derived task manifest; resolve owner/client commerce boundaries; one end-to-end native read/edit/save/readback workflow in the shell, including denied, expired-session and stale-write states. Test PHP 8.2+ and supported WordPress/Woo versions on the disposable lab.
3. Migrate remaining daily tasks in coherent batches. Verify native block editing, category assignment, product/media operations and role-specific omissions, navigation/history, keyboard/focus and mobile/tablet layouts. Retain recovery routes until task coverage is complete. Feature cards alone are not acceptance.
4. Promote only after grouped native/browser/performance checks and a rollback route. Public Bricks rendering and DSA asset isolation must be regression-tested. Production Ascendants remains RC55.4 until a separate verified rollout.

The first-launch estimate must be revisited after the connected reference slice. The previous card-navigation acceptance proved those specific routes and writes; it did not prove the newly clarified application experience. No full ecosystem or launch-completion claim follows from this prototype.

## Evidence and recovery

All preview edits are synthetic, held in memory and reset on reload. The preview role selector demonstrates proposed visibility; it is not a security implementation. Administrator product tiles illustrate intended future access and do not imply that RC82 has that permission. The visitor composition is illustrative, not a functional Key/commerce session. The local server listens on loopback and accepts only GET/HEAD with no outbound API connectivity.

No live website, role, setting, schema or security factor changes are part of this decision. Existing RC82/RC55.4 packages remain immutable. Restore the previous local preview simply by opening its existing URL; the new prototype is a separate process/port. Product docs and the next handoff must distinguish this concept from deployed functionality.


## RC83 migration amendment, 10 September

The founder approves the preview and prioritizes existing native behavior plus thoughtful interaction design. [RC83](../WORKSPACE-SHELL-RC83-2026-09-10.md) introduces an opt-in server-rendered document bridge around the existing registered callbacks. React powers Home using WordPress's registered element dependency; native tasks currently use full-document navigation and retain their own forms and media scripts. No arbitrary HTML fetching or iframe integration is added. This is a transitional choice to reuse working native controls, not completion of the planned SPA/task migration.

The original Identity view now has editable brand presentation and adjacent variants. Other native forms require the same subject-oriented redesign, including related actions across former groups. [Coverage](../DESIGN-CONTEXT-WORKSPACE-COVERAGE.md) owns the interaction and data projection contract. The guard stays off until native compatibility, private responses, media/security hooks and switch-off are verified. Full WordPress React/editor packaging, original-control readback and measured performance remain acceptance gates.


## Founder correction after RC83, 10 September

The supplied brief rejects the form-oriented visual approach. The migration amendment above is preserved as historical implementation evidence, not the current UI acceptance plan. [Metro working tiles](../METRO-WORKSPACE-CORRECTION-2026-09-10.md) supersede its presentation direction: data belongs on tiles and focused editors open from the subject itself, including site icon/name and existing-user team membership. The permanent private same-origin application architecture remains accepted. RC83's guarded document bridge remains off by default; do not treat passing wrapper checks as native app acceptance.

Next reference slice is the corrected People/Identity UI using the existing native services with bounded read models, server authorization, stale/edit-error states and confirmed native/public readback. The brief does not authorize a second user roster, settings store, role widening or public admin asset loading. All other daily tasks remain required; their form samples are not accepted final designs.


## Approved expansion and People boundary, 10 September

The founder approved the working-tile visual family and requested a full core UI pass. [The new build](../FULL-WORKSPACE-UI-2026-09-10.md) covers Home and thirteen task areas in the synthetic React app. The profile is an explicit WordPress/Woo/Kiwe field projection: role labels are read-only, public team title is directly editable on the card, and private addresses remain separate from public selection. Do not enumerate all plugin user meta or inject plugin profile HTML. Bricks/Rank Math and technical plugin controls remain on the owner's surface.

Native connection must derive its bootstrap and task catalog from real capabilities, return only permitted records/fields, preserve native writers and reject stale/session/object failures. The sample role switch cannot serve as security. No real sample-data bootstrap may be shipped. Native editor coverage, Woo custom-owner/client gaps, locale-specific validation, real provider/security flows and measured concurrency remain separate acceptance gates.
