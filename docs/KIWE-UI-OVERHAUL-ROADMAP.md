# Kiwe UI Overhaul Roadmap

**Canonical deploy source:** C:\Users\munaf\Documents\dsa-dual-surface\wp-content\mu-plugins  
**UI proposal reviewed:** wp-content\mu-plugins - Copy  
**Decision:** selectively adopted; the proposal copy is not deployable and is not a second canonical tree.

## Lead Review

The proposal identified real defects: no tablet profile, repeated placement logic, weak sheet affordances, ambiguous admin controls, clipped cart labels, and sheet dismissal that inherited the Classic mental model.

It was not safe to merge wholesale. Its navbar fix added more than 200 lines of repeated profile selectors and important overrides, enlarged AI geometry independently of the Dock contract, and treated source/static checks as proof of interaction behavior. That approach would make marketplace themes brittle.

HTMX and Alpine.js were rejected for the `0.5.43` geometry overhaul because that work needed fewer ownership layers, not more. A later `0.5.54` pilot proved they could be packaged safely, but not that they improved Kiwe enough to stay in the default admin/runtime surface. As of `0.6.34`, the htmx/Alpine track is retired: DSA core remains the default authority, and Kiwe will adopt a hybrid library only for a specific future adapter where source and live-host evidence prove the library is materially better than the native DSA/Seam/WP stack for that narrow job. If such an adapter is approved, htmx may own only server-rendered, same-site fragments where WordPress remains authoritative; Alpine may own only isolated local widget state. Neither library may own PhoneKey/auth, checkout/payment authority, persisted cross-account state, service-worker policy, navigation history, focus trapping, cart reconciliation authority, or the core Surface lifecycle.

## Contract Decisions

1. Modules own semantic content and actions. They do not own shell placement.
2. Theme owns presentation: Classic Surface or Sheets.
3. Geometry Engine owns the active mobile, tablet, or desktop profile.
4. CSS consumes normalized runtime state: orientation, position, alignment, edge, layout, and density.
5. Dock context inherits the active Dock material, axis, and measured anchor.
6. Sheets close from backdrop clicks, but not from empty clicks inside the sheet panel. They also close through the handle, a valid drag, Escape, browser-back interception, the active Dock icon, or an explicit action.
7. Sheet close always uses the existing Surface lifecycle, history release, focus restoration, and mode arbitration.
8. AI uses the same geometry size as other Dock destinations. Color, motion, and badge communicate its role.
9. Marketplace themes may style tokens and recipes but may not replace state, REST, history, or geometry ownership.
10. Canonical code remains dependency-light by default. Future htmx/Alpine use requires a named adapter, a measurable weakness in native DSA, WordPress enqueue ownership, no bundled admin-wide gate, and proof that the dependency removes more risk/complexity than it adds.

## H1 - htmx/Alpine Pilot - Retired in 0.6.34

- [x] Batch 0: finish non-deferred hardening before introducing new runtime dependencies. PhoneKey auth/state-machine and privacy/master switches are deferred by product decision; SecureTrack optional-surface containment is complete.
- [x] Batch 1: add htmx/Alpine foundation behind settings/capability gates, with local/vendor asset handling and no CDN dependency in production packaging.
- [x] Batch 2: pilot htmx on low-risk server-owned fragment pilots such as selected admin/read-mostly panels and non-auth Surface partials. First pilot: Developer package-proof refresh.
- [x] Batch 3: pilot Alpine on isolated local-widget pilots only, with no persisted account/cart/auth state. First pilot: Developer enhancement-gate preview state.
- [x] Batch 4: remove redundant custom glue proven obsolete by the pilots, expand contracts, update docs, bump versions, and rebuild the package manifest. No redundant glue was removed from the first small pilots; source contracts, docs, version bump, and manifest rebuild are complete for folder-based MU deployment.
- [x] Batch 5: retire the broad enhancement gates after lead review. The pilot did not beat DSA core for the tested admin/package-proof path, so the admin UI, settings lane, boot metadata, frontend/admin enqueues, AJAX refresh route, and vendored library assets were removed. Future use is adapter-specific, not a global toggle.

The closed pilot taught the boundary without leaving a permanent dependency. DSA core remains the product path unless a future integration wins on evidence.

### H1 evidence verdict

- Frontend shell: htmx/Alpine did not add a pre-1.0 capability DSA lacked. Search, dock, sheet/classic presentation, notifications, menu context, cart reconciliation, focus, viewport sizing, and Surface lifecycle need one Kiwe-owned state machine rather than a second DOM/event owner.
- Backend/admin: the only real htmx pilot was Developer package-proof refresh. Evidence showed page reload is enough for that low-frequency proof, while the extra AJAX route, nonce, enqueue gates, settings lane, and admin copy increased the surface area.
- Local-widget state: the Alpine pilot only previewed enhancement checkboxes before save. Native admin JavaScript already handles local UI state without adding a permanent dependency.
- Package/runtime: 0.6.34 verifies that Kiwe runs without htmx/Alpine enqueues, public boot metadata, vendored assets, or manifest entries.
- 1.0 rule: no broad new JavaScript framework enters DSA core before 1.0. The work to 1.0 is strengthening the reactive AppShell, Geometry Engine, Seam framework, Site Graph, AI Companion, Bricks conversion, and theme/package loop.
- Post-1.0 rule: table, restaurant, POS, and similar future verticals may reopen htmx/Alpine only as named adapters with a measurable, repeatable win over native DSA/Seam/WordPress for one narrow task. An adapter must be Kiwe-owned, locally packaged or explicitly dependency-managed, and it must never own PhoneKey/auth, checkout/payment, cart reconciliation authority, service-worker policy, browser history, focus trapping, or the Surface lifecycle.

## U1 - Responsive Shell Foundation - Complete (0.5.43)

- [x] Add tablet defaults and persisted Dock settings.
- [x] Resolve mobile/tablet/desktop from measured viewport width.
- [x] Emit the active profile and normalized placement state at runtime.
- [x] Replace duplicated desktop/mobile navbar CSS with one runtime-state contract.
- [x] Keep Navigation bar flush to its configured edge with safe-area padding inside.
- [x] Add an accessible sheet handle.
- [x] Add pointer drag dismissal for bottom, left, and right sheets.
- [x] Route sheet close through the existing lifecycle/history/focus path.
- [x] Preserve Sheet backdrop-click dismissal while stopping empty panel click dismissal.
- [x] Keep AI control geometry equal to other Dock controls.
- [x] Clamp long cart and FBT titles without changing product semantics.
- [x] Consolidate placement settings into Desktop, Tablet, and Phone cards.

## U2 - Admin Preview And Conditional Controls - S/M

- Hide controls that do not apply to the chosen orientation/presentation.
- Add lightweight previews driven by the same normalized settings, not a second renderer.
- Explain Compact Dock versus Navigation bar in plain language.
- Show effective breakpoints and active inheritance.
- Add reset-to-profile-default actions without resetting modules or other Kiwe settings.

## U3 - Shared Module Layout Recipes - M

- Audit Profile, Cart, Search, Links, Menu, Saved, AI, Checkout, Notifications, and Games against the same narrow/compact/wide recipes.
- Remove remaining module-local viewport assumptions.
- Standardize title, metadata, cards, empty states, actions, and overflow.
- Preserve commerce and PhoneKey behavior exactly.
- Keep two-column cart density where geometry permits; collapse only when measured width cannot preserve usable controls.

## U4 - Sheet Interaction And Accessibility Proof - M

- Focus trap and focus-return verification.
- Escape, browser-back, active-icon, handle-click, and drag-dismiss matrix.
- Pointer cancellation and reduced-motion verification.
- Scroll containment, keyboard viewport, safe-area, zoom, and screen-reader checks.
- Confirm that required PhoneKey and permission journeys cannot be dismissed accidentally.

## U5 - Marketplace Conformance Expansion - M

- Extend canonical fixtures to Classic and Sheet presentations.
- Add Dock and Navigation bar across phone, tablet, resized desktop, and wide desktop.
- Validate context-width alignment, contrast, overflow, focus visibility, and touch targets.
- Run live Hostinger checks after static harnesses pass.
- A theme package fails conformance if it changes runtime ownership or needs site-specific selectors.

## Release Gate

The overhaul is production-ready only when:

- canonical package verification passes;
- JavaScript syntax and release contracts pass;
- UI fixtures pass across both themes and all profiles;
- live browser testing confirms Sheet drag/close, safe areas, zoom, and scroll behavior;
- no module behavior differs solely because presentation changed.

## Attempt Model

Priority = user impact + architecture unlock + adoption value - regression risk - context cost

- U1: M, complete.
- U2: S/M.
- U3: M, split by related module families if needed.
- U4: M.
- U5: M.

Estimated remaining: **four focused UI batches**, followed by live evidence rather than another styling patch.
