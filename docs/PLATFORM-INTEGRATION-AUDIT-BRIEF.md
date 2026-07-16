# DSA Platform Integration Audit Brief

## Purpose

This package is being shared with lead engineers familiar with WordPress 7.x, WooCommerce, and Bricks 2.3.7 so DSA can verify three things:

1. Whether its current integrations use supported platform contracts correctly.
2. Which custom DSA responsibilities can safely move to native platform APIs.
3. Which attractive integrations would weaken DSA's fail-open, trust-first, shared-host architecture.

This is an architectural review, not a request to rewrite code. Findings return to the DSA lead for adoption, modification, deferral, or rejection.

## Review Baseline

- Canonical package: `wp-content/mu-plugins/dsa`
- Current DSA version: `0.4.95`
- Runtime target: WordPress 7.x, PHP 8.2+, WooCommerce when installed, Bricks 2.3.7-first
- Hosting baseline: ordinary shared hosting without Redis, queues, edge workers, or shell access being assumed
- Architecture truth: `DSA-ARCHITECTURE.md`
- Short execution truth: `DEVELOPMENT-PLAN.md`
- Production proof remains open even when a code baseline exists

## DSA Invariants

Recommendations that violate these constraints should be marked incompatible rather than presented as improvements.

- WordPress remains content, user, REST, SEO, and server-render authority.
- WooCommerce remains cart, coupon, tax, checkout, payment, order, refund, inventory, and session authority.
- Bricks remains page-design and builder-editing authority.
- DSA owns the persistent appsite Surface, dock, interstice, journey orchestration, trust presentation, and cross-module contracts.
- Visitor-facing trust, money, identity, and security decisions are deterministic and server-verified.
- Protected, personalized, transactional, builder, and unknown routes fail back to full WordPress navigation.
- Native APIs are feature-detected adapters. Their absence cannot break the Surface.
- No recommendation may require Node, a build server, persistent cache, Action Scheduler, CDN, or edge worker for basic operation.
- SEAM is a retained future page-design framework track. It must not become a second DSA Surface token authority.

## Required Finding Format

For every finding provide:

```text
ID:
Platform and exact version/source inspected:
Current DSA file and symbol:
Current behavior:
Verdict: correct | safe improvement | medium migration | risky experiment | reject
Native contract that could replace or strengthen it:
What DSA should continue to own:
Compatibility and deprecation horizon:
Failure/fallback behavior:
Migration steps:
Tests required:
Measured or expected benefit:
Primary-source references:
```

Do not use “native is better” as a conclusion. State the ownership, lifecycle, fallback, and measurable benefit.

## Safety Classification

### Safe

- Additive, feature-detected, read-only, reversible, and no protected-flow authority changes.
- Examples: native metadata adapters, registered bindings, admin DataViews, supported lifecycle events, documented converter hooks, cache hints, diagnostics.

### Medium

- Changes an internal authority or storage/lifecycle path but retains deterministic fallback.
- Examples: moving address writes to `WC_Customer`, migrating admin tables, replacing custom cart reads with a supported Store API adapter, changing Bricks render hooks, Action Scheduler migration.

### Risky

- Touches money, identity, security enforcement, page replacement, shared caching, offline behavior, or builder serialization.
- Examples: Checkout Blocks mutation, Store API cart authority, fragment navigation for Bricks, cross-site PhoneKey, edge HTML injection, converter internals, shared personalized cache.

## WordPress Review

Please audit:

1. **Abilities API**: exact WordPress 7 registration, permission, discovery, schema, execution, and deprecation contracts. Recommend the smallest read-first abilities DSA can register now.
2. **WP AI Client**: provider/configuration ownership, capability boundaries, data handling, streaming, errors, and whether DSA should consume it only for admin explanations.
3. **Interactivity API**: whether the existing `kiwe/ai`, `kiwe/app`, and `kiwe/data` display-state bridges match current state/store/directive contracts; identify small islands suitable for migration.
4. **Block Bindings**: validate the public `kiwe/site` source and propose safe public trust/profile/cart display bindings without exposing private data.
5. **DataViews/DataForm**: identify Kiwe admin tables that can migrate without destabilizing settings, bulk actions, or shared hosting.
6. **Script Modules/import maps**: determine whether lazy Search/reconciliation/native islands should use current core modules and what fallback is required.
7. **REST and cache semantics**: validate permission callbacks, nonce/token boundaries, origin policy, cache headers, ETags, private/no-store behavior, and public manifest/profile caching.
8. **Metadata and object cache**: identify repeated reads that benefit from core object cache while remaining correct with no persistent backend.
9. **Cron/queues**: classify which Push, analytics, SecureTrack, schema, and asset jobs belong in WP-Cron versus Action Scheduler when available.
10. **View Transitions and navigation**: validate cross-document support, WordPress lifecycle assumptions, bfcache behavior, and the controlled editorial fallback contract.
11. **PWA/Push**: identify core/browser responsibilities DSA must not duplicate and validate service-worker scope and update behavior.
12. **PHP 8.2 modernization**: identify stable internal boundaries for types/readonly DTOs without named arguments into external APIs or wholesale hook-payload rewrites.

## WooCommerce Review

Please audit:

1. DSA Cart REST mutations, nonce refresh, cart keys, fragments, session initialization, variation handling, stock errors, and concurrency.
2. Whether Store API can safely serve as an adapter for reads or mutations without breaking classic checkout, Bricks mini-cart, extensions, or guests.
3. DSA Checkout draft hydration across classic checkout, Checkout Blocks, custom fields, shipping toggles, gateways, validation, and Place order handoff.
4. Pair-offer implementation using virtual Woo coupons: reverse pairs, overlap rejection, percent/fixed math, quantities, taxes, sessions, order persistence, refund behavior, and reporting.
5. HPOS compatibility for orders, refunds, analytics, abandoned-cart identity, and owner notifications.
6. `WC_Customer` migration for address writes versus direct user meta, including custom fields and save hooks.
7. Add & Save as one add-then-claim transaction and its failure/rollback behavior.
8. FBT/co-purchase, cross-sell, bestseller, linked-product, and Cart Picks queries against current analytics/product APIs.
9. Inventory, out-of-stock Notify Me entry points, product visibility, catalog restrictions, subscriptions/backorders, and variable products.
10. Paid/refund Analytics authority hooks and duplicate-event prevention.
11. Action Scheduler opportunities that are optional accelerators rather than hard dependencies.
12. Which DSA commerce UI may be offloaded while Woo remains the only monetary authority.

## Bricks Review

Please audit against Bricks 2.3.7 source and state whether each API is public, stable, internal, or undocumented:

1. `bricks/element/render_attributes` and `bricks/frontend/render_element` registry markers/observation.
2. Dynamic tags and mixed-content rendering for Kiwe logos, Woo store identity, menus, and product weight.
3. Element control hooks for mini-cart, product add-to-cart, product upsells, quantity controls, stock badges, FBT, and offer states.
4. Root-attribute and element-settings hooks used by the add-to-cart enhancer.
5. Bricks mini-cart lifecycle, fragments, `cartDetails`, and refresh events.
6. Theme-mode contracts using `data-brx-theme` and `brx_mode`.
7. Variables, color palettes, classes, theme styles, components, and additive SEAM export without overwriting project data.
8. HTML & CSS to Bricks converter behavior introduced in 2.3: supported elements, attributes, selectors, variables, at-rules, pseudo states, responsive CSS, image import, scripts, unsupported markup, and any official extension hooks.
9. Whether custom `data-kiwe-*` attributes survive conversion reliably; if not, recommend a supported post-conversion path.
10. Builder detection, iframe/canvas lifecycle, preview refresh, save hooks, and DSA suppression while editing.
11. S13-S16 editorial rendering/morph lifecycle: identify whether any supported Bricks API can serialize, hydrate, mount, unmount, or reconcile a rendered page. Do not infer support from `render_content()` alone.
12. Accessibility and semantic preservation during HTML conversion, including headings, landmarks, forms, buttons, links, lists, figures, tables, and native Woo elements.

## Explicit Questions

- What custom code can be deleted because a stable native contract now provides the same behavior and fallback?
- What code should remain an adapter because DSA has cross-platform ambitions?
- Which integrations are correct but need version guards or deprecation handling?
- Which DSA features currently depend on undocumented internals?
- Which native APIs would increase boot cost or database traffic despite reducing source lines?
- Which changes improve shared-host behavior rather than benchmark-only performance?
- Which proposals would make future non-WordPress/non-Bricks expansion harder?

## Expected Output

1. Executive verdict for each platform.
2. Safe, medium, and risky tables ranked by benefit and migration cost.
3. Current integration correctness matrix.
4. Supported API/deprecation matrix with primary-source links.
5. Recommended offload boundary: what moves and what DSA retains.
6. Required live-test matrix.
7. Explicit “do not change” list.
8. Proposed batches sized XS/S/M/L using DSA’s documented capacity model.

Do not edit DSA code. Return the audit for architectural review.
