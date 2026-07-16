# DSA — Dual-Surface Architecture

**Document purpose:** Explain what DSA (Kiwe) is at every layer — from shared hosting PHP to PWA install prompts — and why the engineering is structurally different from plugins that bolt JavaScript onto WordPress instead of re-wiring how WordPress already works.

**Audience:** Founders, engineers, agency partners, and future contributors who need to understand *why* DSA exists and *how* each layer was cut, bound, patched, and bridged.

**Version alignment:** WordPress 7.x · PHP 8.2+ (prefer PHP 8.3/8.4 when the host and plugin stack are clean) · Bricks-first · Shared hosting as the default environment, not the exception.

**Platform stance:** DSA is not choosing between a custom MU-plugin shell and WordPress 7 native APIs. The winning architecture is **DSA-first, WP7-native where it makes DSA stronger**: the MU-plugin owns the appsite Surface and its document-lifetime continuity; WordPress 7 supplies abilities, AI, bindings, interactivity, PHP-only blocks, and admin data surfaces behind it. Cross-document persistence is earned APEX work, not assumed current behavior.

---

## One Sentence

DSA is an **appsite shell** — a persistent, customizable front layer that turns a WordPress/Bricks/WooCommerce website into something that *feels* like a native app (dock, home screen, transitions, games, checkout ritual, trust badges, reactive notifications) while keeping SEO-critical pages as fast, server-rendered HTML that shared hosting was built to serve.

---

## The Problem DSA Solves

Speed alone does not close the gap between websites and apps. A store can score 95 on Core Web Vitals and still feel wrong: no persistent profile, no cart that follows you, no delight between routes, no trust surface at a glance, no reason to install anything, no social proof beyond star ratings.

Traditional WordPress solves this by adding **more plugins** — each with its own React bundle, database tables, admin screens, and global asset enqueue. On shared hosting (where most of the 43% of the web lives), that model fails quietly: 22 plugins, 22 universes, one exhausted PHP worker.

DSA inverts the model:

| Old approach | DSA approach |
|---|---|
| Replace the theme with a page builder SPA | Keep Bricks for SEO pages; add a **shell** for everything else |
| Load JS on every page for one feature | One **shared runtime**; modes/modules load on demand |
| Rebuild WooCommerce templates in React | **Fragment-render** Bricks/WC content; shell stays mounted |
| Trust via footer fine print | **Links/Trust module** with live SSL/payment/auth badges |
| Engagement via popups ( fights the page ) | **Transition + Game modes** during navigation dead time and decision moments |
| Admin edits in wp-admin only | **Composed Action Mode** with WP-native forms bound to meta |

The resources were always there: MU-plugin load order, Bricks render hooks, REST, transients, wp_cron, first-party cookies, the Block/Interactivity/Abilities APIs in WP 7, browser View Transitions, Web Share, Push, and PWA install. Nobody had **engineered them into one coherent appsite system**.

The rule is simple: DSA should use the maximum native power available, but through feature-detected adapters. If a host runs WordPress 7.0 with PHP 8.2+, DSA should light up Abilities, AI Client, Interactivity API, Block Bindings, PHP-only blocks, View Transitions, PWA install, and browser media APIs. If a specific API is missing or a site has a compatibility issue, the shell must degrade to its existing REST/PHP/JS path without breaking checkout, profile, navigation, or trust.

---

## The Between-The-Pages Thesis

DSA's unique surface is the **interstice**: the transition after a click but before the next page is ready, the idle moment before a visitor leaves, the trust moment before payment, the permission prompt after value has been proven, and the waiting state that usually belongs to the browser spinner.

That is why DSA is not a popup plugin, a SPA theme, or an analytics widget. A popup fights the page. A SPA replaces the page. An analytics widget observes the page. DSA owns the moments around the page while leaving SEO-critical WordPress output intact.

The promise is not that loading disappears. The promise is **instant response**: when a visitor acts, the Appsite Surface can respond in one frame with brand, trust, navigation, a message, or a small playable moment while the real page continues loading safely behind it. If the destination is checkout, login, payment, reset, account, or order state, safety wins over magic.

This is also the trust filter for every future idea. Partner moments, commerce suggestions, games, identity, analytics, AI, permissions, and notifications only belong in DSA when they make the visitor feel safer, more informed, or more in control.

---

## The DSA Appsite Surface Model

DSA is not a set of popups and it is not five separate pages. DSA is **one persistent Appsite Surface** with modes, modules, triggers, and backend intelligence. The visitor feels one native layer; the admin configures when that layer appears and what it knows.

```
┌────────────────────────────────────────────────────────────────────┐
│                    ONE DSA APPSITE SURFACE                         │
│ persistent dock · first paint home · route transitions · trust      │
│ identity · games · checkout protection · admin/AI intelligence      │
├──────────────────────┬─────────────────────┬───────────────────────┤
│ SURFACE MODES        │ DOCK MODULES        │ EVENT TRIGGERS         │
├──────────────────────┼─────────────────────┼───────────────────────┤
│ Appsite Home         │ Menu                │ First session           │
│ Dock Module          │ Profile / PhoneKey  │ Link click              │
│ Transition           │ Cart                │ Route match             │
│ Game                 │ Links / Trust       │ Idle                    │
│ Protected Flow       │ AI / Copilot        │ Checkout/payment state  │
│ Composed Action      │ Secure / Admin      │ Admin-defined events    │
└──────────────────────┴─────────────────────┴───────────────────────┘
          ▲                    ▲                         ▲
          │                    │                         │
   WP7 native APIs       Backend data engines       Browser/PWA events
   Abilities · AI        Trust · PhoneKey · WC      View Transitions
   Bindings · Blocks     SecureTrack · Registry     Push · Install
                         PJM · Commerce · IAM
                         Schema/GEO · Rewards
```

**Surface modes** define behavior and safety: how the surface opens, closes, blocks, animates, and protects the user.  
**Dock modules** define content and entry points: menu, profile, cart, links, AI, secure, and future modules.  
**Event triggers** decide when a mode or module appears: first session, idle, route, click, checkout, order state, game reward, admin rule.

This model gives DSA room to grow without fighting its own language. A Links view, Profile view, and Menu view are real DSA experiences, but architecturally they are dock modules rendered inside Dock Module Mode. Checkout is not special because it is visually a screen; it is special because it is a **Protected Flow** with payment/session/account risk.

### Surface Modes

| Mode | Role | Engineering contract |
|---|---|---|
| **Appsite Home Mode** | First-session appsite front door, idle recall, PWA install, brand ritual, returning visitor shortcut. | Current server fallback is emitted at `wp_footer` before the runtime takes ownership; session-scoped visibility, scroll/touch dismiss, PWA actions, and appsite theme variables are live. Earlier shell placement is an APEX delivery concern. |
| **Dock Module Mode** | Normal dock-opened experiences: Menu, Profile, Cart, Links, AI, Secure. | Opens from dock, uses shared visual language, closes on safe outside click, preserves interactive fields, respects module permissions. |
| **Transition Mode** | Owns navigation dead time with messages, trust moments, commerce context, or lightweight play. | Link interception, one-frame surface response where possible, min/max duration, artificial delay, hover-hold, back/forward safety, dynamic extension while loading, visible release/fallback, full navigation fallback. |
| **Game Mode** | Challenge/reward surface for discount, retention, social loop, leaderboard, and commerce ritual. | Explicit start, stricter close rules, attempt limits, reward tiers, confetti, route/frequency caps, PhoneKey score promotion. |
| **Protected Flow Mode** | Checkout, payment, login, password reset, address update, order received, sensitive account actions. | Never clever at the expense of safety. WooCommerce/session state stays authoritative; fragment navigation is excluded unless proven safe. Trust layer is visible. |
| **Composed Action Mode** | Admin-created forms, buttons, app actions, POS panels, account tasks, WP7 block-bound workflows. | Server-rendered PHP-only blocks where possible, Block Bindings, Interactivity API islands, REST validation, PhoneKey step-up for sensitive actions. |

### Dock Modules

| Module | Why it exists | Primary mode |
|---|---|---|
| **Menu** | Appsite navigation, pages/posts/categories chosen by admin. | Dock Module |
| **Profile / PhoneKey** | Identity, login, profile, account continuity. | Dock Module / Protected Flow for sensitive edits |
| **Cart** | Cart glance, totals, checkout approach. | Dock Module / Protected Flow when committing checkout |
| **Links / Trust** | Public trust dashboard: socials, shop, latest posts, SSL, payment, PhoneKey, testimonials. | Dock Module |
| **AI / Copilot** | Admin intelligence and eventually visitor-safe assistance. | Dock Module / Composed Action |
| **Secure** | Admin-only SecureTrack status and actions. | Dock Module / Protected Flow for security actions |
| **Future POS / Notifications / Rewards** | Storefront bridge, order updates, game economy. | Composed Action / Protected Flow / Game |

### Event Trigger Engine

DSA should not scatter "when this appears" logic across App, Visual Effects, Games, Links, and Dock settings. The target model is a single trigger engine:

`Event -> Conditions -> Surface Mode / Module -> Duration -> Dismiss Rule -> Frequency Cap -> Safety Guard`

Examples:
- First session -> Appsite Home Mode -> scroll/touch dismiss -> once per session.
- Any safe link click -> Transition Mode -> min duration -> click/Escape release.
- `/shop` route -> Game Mode -> three attempts -> reward tier -> frequency cap.
- Idle 60 seconds -> Appsite Home Mode -> scroll/touch dismiss -> only when no form/checkout/game is active.
- Checkout route -> Protected Flow Mode -> trust layer -> no fragment navigation.
- Admin user -> Secure module badge -> admin-only visibility.

Mode arbitration must be explicit, not accidental. When more than one surface wants to open, DSA should evaluate a hard priority stack:

1. Protected Flow
2. Game
3. Transition
4. Dock Module
5. Appsite Home idle/recall

The runtime should maintain one `current_active_mode`, with each mode declaring whether it can be interrupted, dismissed, delayed, or queued. This prevents stuck loaders, Home/Transition collisions, games closing like normal overlays, and checkout/account routes being treated like ordinary pages.

The trigger engine is what makes DSA feel inevitable: admins configure moments, not code paths.

### Core Engines Behind The Surface

#### Trust Engine

DSA trust must be one service, not repeated logic inside Links, Checkout, Profile, and AI. `Trust_Service` should provide:

- SSL status/provider, with admin override and confidence.
- WooCommerce payment gateway labels.
- PhoneKey availability and secure-login status.
- SecureTrack status and admin-only warnings.
- Site score and health signals.
- Manual testimonials and Google review bridge.
- Checkout trust recommendations for admins.

AI may explain trust or suggest copy; it must not invent trust. Visitor-facing trust badges come from deterministic site, server, WooCommerce, PhoneKey, and SecureTrack state.

#### Protected Flow Guard

Protected Flow Mode is the safety contract for money and identity. It covers checkout, payment, cart commits, login/register, reset password, address update, order received, profile edits, POS writes, and sensitive security actions.

Rules:
- Prefer full navigation when WooCommerce or auth state is involved.
- No accidental outside-click close during critical payment/account steps.
- Trust layer is visible and sourced from Trust Engine.
- PhoneKey step-up can be required for sensitive actions.
- Fragment navigation is opt-in only after proof on that route class.
- Recovery/fallback beats smoothness.

DSA Checkout uses a two-stage protected contract. The Surface may collect WooCommerce billing, shipping, account, and order-note fields into the current Woo session, using Woo's active field definitions and sanitization rules. Payment methods, final checkout validation, and **Place order** remain on the server-rendered WooCommerce checkout page so Bricks and gateway integrations keep their normal authority. If Woo rejects the final form, the Surface reopens with the rejected fields highlighted; corrected values are written back into the page before Woo recalculates checkout. The Surface must never submit the order itself.

#### Permission Journey Manager

Permission prompts should be earned, not begged. The Permission Journey Manager is an adopted-now architecture that gates PWA install, push, location, camera/scanner, and future device permissions through trust state.

It should track each permission as a state machine:

- `never_asked`
- `asked_and_dismissed`
- `asked_and_denied`
- `granted`

The Trigger Engine decides when an ask is allowed. Examples: PWA install after a returning visit or meaningful Appsite Home interaction; push after order placement or shipment interest; location only when pickup/delivery context exists; camera/scanner only after the visitor or admin starts a scanner/POS action.

Rules:
- Never fire multiple permission asks in one session unless the user explicitly asks.
- Never ask during checkout/payment/login/reset unless the permission directly supports that flow.
- Record which earned-trust moment produced the grant.
- Store only the minimum consent state needed for UX, debugging, and compliance.

**Current implementation (`0.4.86`):** PWA installation and offline push permission are separate journeys connected by a persistent preference contract. "Personalize your Appsite" records topics, categories, products, and available channels before branching to PhoneKey identity, Android permission, or the dedicated iOS Home Screen guide. Woo products without a price or stock expose a Notify me entry point; order completion can offer a passive order-status journey through AI. iOS preserves the visitor's choices until the Home Screen app opens, then requests notification permission only from an explicit OK gesture. `data-kiwe-notifications` opens the same preference journey instead of bypassing consent context. Granted App choices create an encrypted PushSubscription tied to a site-specific VAPID key; removing App consent unsubscribes the device and retires its server record. Standalone launch now resolves server preferences before arbitration and enforces identity -> verification -> notification choice in that order. Owner accounts receive required AI primers for private order/comment alerts, a compact visitor-activity summary, and a replacing event-aware live-visitor alert when the owner has Kiwe open. Explicit browser denial is a valid saved choice and never becomes an inescapable loop.

PWA adoption is measured honestly as a funnel: badge-tap intent, Android primer acknowledgment, native prompt accepted/dismissed, browser-confirmed `appinstalled`, and first standalone launch for iOS/Home Screen confirmation. A native prompt acceptance is not counted as an install until the browser reports installation or the app later launches in standalone mode. Browser-notification grants and denials are separate events. Kiwe App Adoption counts unique salted visitor/IP hashes rather than retaining raw IP addresses; when a later event on the same visitor hash carries a WordPress/PhoneKey user, the adoption ledger resolves that anonymous row to the user.

The native Android prompt is browser-owned. `beforeinstallprompt` has limited browser support and no guaranteed firing time; DSA can retain and invoke an event the browser supplied, but cannot manufacture one. HTTPS and a valid manifest with install metadata/icons are site-controlled requirements. Already-installed state, previous dismissal/cooldown, private browsing, browser policy, and browser-family behavior can suppress the prompt without exposing a precise reason to page JavaScript. `window.DSA.inspectAppInstall()` returns Kiwe's detectable readiness state for support; visitor AI copy must distinguish detected site configuration failures from opaque browser suppression. iOS remains Safari Share -> Add to Home Screen -> Add.

#### Commerce Context Engine

Commerce context is useful when it strengthens a visitor's decision, not when it blocks navigation. This is the modified adoption of the CartContextEngine idea.

The engine should read safe WooCommerce context during transition or protected flow setup:

- Cart items, subtotal, coupons, and shipping/payment readiness.
- Product/category route context.
- Recent viewed products where available.
- Trust Engine signals relevant to checkout confidence.
- Admin-approved cross-sell or complement rules.

The output should be a small decision payload such as `trust_confirmation`, `cart_reminder`, `product_complement`, `checkout_readiness`, or `neutral_message`. Add-to-cart actions may be offered later, but they must be optional, asynchronous, and fail open. DSA should never require "add to cart must complete before destination" as a navigation contract.

#### WP7 Native Adapter Layer

WordPress 7 APIs should not be sprinkled randomly through DSA. They need a feature-detected adapter layer:

```
includes/WP7/
  Abilities_Service.php
  AI_Client_Service.php
  Interactive_Blocks_Service.php
  Bindings_Service.php
  DataViews_Service.php
```

The shell remains stable even if an API is missing. The adapter lights up native power when available: Abilities for commands, AI Client for admin intelligence, Interactivity API for reactive islands, Block Bindings for real data, PHP-only blocks for shared-host-friendly action components, DataViews for admin surfaces.

#### AI As Admin Copilot

The AI module should not begin life as a generic visitor chatbot. Its first job is making the site owner feel safer, smarter, and faster:

- Explain why checkout trust is weak.
- Generate transition messages from category/product/page context.
- Improve the Links/Trust screen.
- Explain SecureTrack alerts.
- Suggest game reward copy.
- Summarize a route using the Bricks registry.
- Recommend what to fix before advertising a page.

Visitor-facing AI can come later and must be bounded by trust, privacy, and site owner intent.

#### PhoneKey Identity Spine

PhoneKey is more than login. It should become the identity spine for:

- Profile continuity.
- Game scores and rewards.
- Customer score.
- App install continuity.
- Secure action step-up.
- Future cross-site DSA identity.

The long-term promise: the dock becomes "my account on this appsite," and eventually "my account across DSA appsites."

For cross-site identity, DSA should decide the rails now and ship later. Future PhoneKey network tokens must use an opaque subject, stable one-way hash strategy, no phone number exposure, explicit consent, deletion/export support, a central issuer, short-lived tokens, and revocation. Local PhoneKey login can ship now; cross-site trust must remain future platform expansion until the legal, privacy, issuer, and operational model is ready.

#### Module Registry

Dock modules should be registered, not hardcoded. A module should declare:

- `id`
- `label`
- `icon`
- visibility/capability
- data callback
- render callback
- bind callback
- dismiss behavior
- route restrictions
- protected-flow escalation rules

This lets Menu, Profile, Cart, Links, AI, Secure, POS, Rewards, Notifications, and future modules grow without turning `surface.js` into a maze.

#### Game & Reward Engine

Games are not decorations. They are commerce moments. The reward engine should define:

- Game id and renderer.
- Trigger rules and frequency caps.
- Attempts and scoring.
- Reward tiers.
- Coupon or offer behavior.
- Retry copy.
- Confetti/color behavior.
- Guest-to-PhoneKey score promotion.
- Abuse prevention.

DSA should schedule games in moments the visitor already experiences as waiting or decision time, not as random interruptions.

Reward credibility requires abuse prevention in the first serious release: attempt ledgers, route and session caps, PhoneKey-linked score promotion, IP/transient throttles that are disabled by default until configured safely, server-authoritative coupon creation, coupon expiry, minimum game-time checks for high scores, and audit logs for reward generation. A game discount is a financial promise, so the server must own the result that creates money value.

#### Interstice Attention Metrics

DSA needs a proof layer that shows merchants what the Appsite Surface is improving without turning into surveillance. Interstice Attention Metrics should begin as privacy-light, aggregate analytics:

- Transition engagement and hold/release behavior.
- Dock module opens.
- Links/Trust interactions.
- Game starts, completions, rewards, and coupon use.
- PWA install prompts and accepted installs.
- Permission conversion by earned-trust moment.
- Revenue/session comparison for sessions that used DSA surfaces versus those that did not.

No raw phone numbers, payment data, or private message content should be stored for analytics. The first implementation can be batched aggregates or daily counters; precision matters less than proving trust and adoption lift safely.

#### Schema/GEO Engine

The Bricks registry is not only for editing and AI context. It can become the source for high-confidence structured data and Generative Engine Optimization, but only where DSA has authority.

Principles:
- Start with WooCommerce Product/Offer/Review/Breadcrumb cases where Woo and WordPress already expose reliable data.
- Use registry snapshots to improve labels, page sections, FAQs, and article context, but keep admin review/toggles for ambiguous output.
- Do not claim automatic schema for every page.
- Cache schema against registry snapshots, post modified time, product modified time, and relevant settings version.
- Make GEO audit admin-only at first: identify unclear headings, missing trust facts, weak FAQs, and pages that are hard for answer engines to cite.

#### Fragment Navigation Must Be Earned

DSA can feel app-like through Home, Dock, Transition, Trust, and View Transitions before broad fragment navigation is complete. Fragment navigation should remain conservative until route exclusions, Bricks render context, asset reconciliation, registry refresh, title/meta updates, and failure fallback are proven.

A smooth full navigation with a beautiful DSA transition is better than a broken SPA illusion.

#### Admin Appsite Profiles

DSA should be exportable as an appsite profile:

- Theme colors and visual language.
- Dock modules.
- Links/trust settings.
- Appsite Home.
- Transition messages.
- Game/reward config.
- Trigger rules.
- Secure defaults.

This makes DSA repeatable for agencies and fast to deploy across stores without turning every setup into handwork.

---

## Architectural Constraints From Future Platform Decisions

This section is not a shipping roadmap. It is the set of rails today's code must not block.

| Constraint | Decision now | Ships when |
|---|---|---|
| **PhoneKey REST-first auth** | PhoneKey core auth must be token-capable. WordPress nonces can remain the WordPress adapter, but the identity contract cannot depend deeply on nonce generation. | After local PhoneKey and Protected Flow are stable. |
| **Cross-site identity token** | Future token principles are fixed now: opaque subject, stable one-way hash strategy, no phone number exposure, explicit consent, deletion/revocation, central issuer, token expiry, minimal claims. | Future platform expansion only. |
| **Manifest-as-config** | `/dsa/v1/manifest` should remain the single source for modules, theme tokens, route exclusions, shell version, feature flags, and future worker/edge readers. | Current and ongoing. |
| **Module registry extensibility** | Modules register through contracts, not hardcoded branches. POS, partners, marketplace profiles, rewards, Secure, AI, and future tiers extend the registry. | Current and ongoing. |
| **Schema cache model** | Registry-derived schema must be cacheable with registry snapshots and invalidated by post/product/settings changes. No request-time full scanning dependency. | Starts with Schema/GEO Engine. |
| **Cloudflare-compatible boundaries** | Do not build the runtime so tightly around WordPress globals that an edge reader can never understand the manifest, modules, exclusions, theme tokens, or shell version. | Future platform expansion only. |
| **Partner trust moments** | Future partner integrations must be merchant-opt-in trust moments, not default ads. The surface belongs to the merchant and visitor trust comes first. | Future platform expansion only. |
| **Universal design-system adapter** | DSA keeps its own stable Surface tokens and must not depend on site-specific Bricks variables or classes. A future adapter may validate a versioned universal-token schema and merge namespaced records through builder-specific contracts with collision reporting, backup, rollback, and CSS regeneration. A `design.md`-style artifact is reference/configuration, never executable runtime. | Design now; import UI and builder adapters ship later. |

The point is to make future expansion an extension, not a rewrite. PhoneKey REST auth, manifest-as-config, module registry contracts, schema caching, and edge-readable boundaries are architectural commitments now even when the product features ship much later.

---

## Layer-by-Layer Architecture

### Layer 0 — Device & Install Surface

| Concern | DSA choice | Why |
|---|---|---|
| PWA | Install prompt on home; manifest version keyed shell cache | Appsite without App Store |
| Push | Browser notifications for order/score/post events | Offline engagement |
| Idle recall | Optional Home recall after configurable idle; off by default | Re-engagement without surprise logout or forced locking |
| Cross-platform | Single web codebase — macOS, Windows, iOS, Android | No native maintenance |

**Bridge:** PWA manifest and installed-app presentation align with the **Responsive Geometry Engine**. This is the shipped successor to the historical Phantom Viewport concept: one visual-viewport, safe-area, orientation, zoom, and dock-reserve contract serves browser and installed contexts.

---

### Layer 1 — Browser Shell (Client)

| Component | Implementation | Innovation |
|---|---|---|
| Runtime | Current: one `surface.js` boot and one Surface mount point. Target: lifecycle-governed module split | One owned appsite subtree without pretending the theme is already an SPA |
| State | Current custom-event/local state contracts; feature-detected Interactivity adapters are pending native islands | Reactive migration without replacing deterministic REST/PHP authority |
| Registry | `#dsa-element-registry` JSON + `window.DSA.registry` API | Page intelligence without crawler |
| Navigation | Full navigation with immediate DSA transition framing; fragment setting hard-disabled | App-like response while Woo/auth/payment boundaries remain real documents |
| Focus | Overlay root, scrim, escape contract, background-scroll ownership; formal external focus stack remains roadmap | Accessibility + popup coexistence |
| Events | `surface:*` custom events | Third-party modules hook without forking |

**The cut nobody made:** DSA hydrates one owned subtree, `#dsa-surface`, rather than hydrating the page body. That subtree owns continuity for the current document and rehydrates after ordinary navigation. S15 can preserve it across an off-by-default, blocker-free static WordPress editorial morph, but Bricks `#brx-content` swapping and broad cross-document continuity remain prohibited until the S16 live compatibility matrix proves their lifecycle.

**Popup / lightbox coexistence (engineering rule):**
- DSA registers a **focus stack** — when Bricks popup, WC notice, cookie banner, or chat widget owns focus, shell scrim yields (`data-dsa-defer-scrim`).
- z-index budget documented: shell < modals < games-with-close < critical WC checkout.
- Escape key resolves topmost layer, not shell blindly.
- This is the difference between "layer on layer" and **one negotiated front end**.

---

### Layer 2 — REST Bridge, Full Navigation, And Earned Fragment Navigation

```
Visitor click
     │
     ▼
isEligibleLink()? ──no──► full navigation
     │
    yes
     ▼
Transition / Protected Flow / Game decision
     │
     ├── protected money/auth/account route? ──► full navigation + trust frame
     ├── safe ordinary route? ────────────────► full navigation + transition frame
     └── future fragment-enabled route? ──────► fragment envelope after route proof
     │
     ▼
browser completes the real page; DSA releases safely
```

| Endpoint | Role |
|---|---|
| `GET /dsa/v1/manifest` | Shell version, footprint, route exclusions |
| `GET /dsa/v1/registry` | Semantic element map for AI/edit |
| `POST /dsa/v1/links` | Admin link hub (trust badges, socials) |
| `POST /dsa/v1/links/logo` | Admin site logo upload for Links/Appsite surfaces |
| `GET /dsa/v1/copilot` | Admin-only deterministic AI readiness report |
| `POST /dsa/v1/cart/*` | Woo cart read, add, quantity, nonce, and upsell-claim routes |
| `POST /dsa/v1/metrics/*` | Privacy-light interstice metrics |
| `POST /dsa/v1/permissions/*` | Permission Journey decision/outcome routes |
| `POST /dsa/v1/rewards/*` | Game/reward attempt routes |
| PhoneKey REST | Auth bridge (embedded core) |
| Account REST | Avatar, profile (authenticated) |

**Current production stance (`0.4.63`):** broad fragment navigation is deliberately **hard-disabled**. The old fragment REST controller was removed after audit because it was not production-safe: asset reconciliation, script lifecycle, forms, registry refresh, and route classes were not proven. The public manifest now reports navigation disabled, old saved `fragment_navigation` settings are normalized to `false`, and popstate fragment handling is only bound if a future manifest explicitly enables it.

**Innovation retained:** DSA can still own the interstice without partial-swapping pages. The safer MVP is full navigation with one-frame transition, trust, game, or protected-flow framing. Fragment navigation remains an earned layer: it should return only after route classes, Bricks render context, asset reconciliation, form/script safety, title/meta updates, registry refresh, and fallback behavior are proven on real sites.

**Persistence cut (current → target):**
- *Today:* Element registry is built live during Bricks render and can fall back to a save-time `_dsa_registry_snapshot` when live elements are unavailable.
- *Target:* Snapshot cache extends from post meta into object-cache-aware readers and future fragment envelopes. AI and point-to-edit read stable structure without request-time page scanning.

---

### Layer 3 — WordPress / PHP Services (MU Plugin)

```
wp-content/mu-plugins/dsa.php          ← WP auto-load entry (fatal logging)
wp-content/mu-plugins/dsa/
  includes/Plugin.php                    ← Singleton service wiring
  includes/Environment.php               ← Builder-safe gating
  includes/Settings.php                  ← wp_options (autoload conscious)
  includes/Element_Registry.php          ← Semantic classification
  includes/Trust/Trust_Service.php       ← SSL/Woo/PhoneKey/SecureTrack trust engine
  includes/Trigger/Trigger_Service.php   ← Event → mode/module scheduling
  includes/Protected_Flow/Flow_Guard.php ← Checkout/auth/payment safety rules
  includes/WP7/                          ← Abilities, AI, bindings, blocks adapters
  includes/Bricks/Bricks_Integration.php ← data-dsa-bricks-* markers
  includes/Public_Endpoint/              ← Surface renderer + assets
  includes/Rest/                         ← Settings, account, cart, rewards, metrics, permissions
  includes/PhoneKey/                     ← Auth bridge + embedded core
  includes/Secure/                       ← Bundled SecureTrack hardening, policies, services, admin
  includes/Modules/Module_Registry.php   ← Extensible dock modules
  includes/Utilities/Origin_Checker.php  ← Shared same-site REST origin/rate guard
```

**Why MU-plugin:**
- Loads before ordinary plugins — shell and security run early.
- Cannot be deactivated by client admin accidentally — appsite is infrastructure.
- Matches "this site *is* an appsite" positioning.

**Environment gating (`Environment::should_render_frontend`):**
- Skip: admin, AJAX, cron, Bricks builder, builder AJAX calls.
- **Cut:** Never pollute editor canvas — registry collects on frontend render only.

**PHP runtime contract and 8.2+ leverage:**

DSA's production minimum is PHP 8.2 from `0.4.63`. Both MU headers declare `Requires PHP: 8.2`, package boot refuses older runtimes before the PHP 8 classes are autoloaded, and Production Readiness treats 8.2+ as the valid baseline. PHP 8 syntax remains a maintainability tool, not a substitute for profiling, caching, or safer request contracts.

- Use scalar/union types, constructor promotion, and `match` incrementally in internal code. Batch 26.17 begins with internal services and Protected Flow; the broader WordPress/Woo/Bricks matrix remains a live QA requirement before mass conversion.
- Introduce enums and readonly DTOs only at new, stable internal boundaries such as registry snapshots or normalized module descriptors. Do not rewrite existing hook payloads, options, JavaScript contracts, or Woo arrays wholesale.
- Keep WordPress REST route registration explicit. Attributes may later describe extension metadata, but DSA will not add a reflection-driven REST framework without measured boot and compatibility benefits.
- Avoid named arguments when calling WordPress, WooCommerce, Bricks, or third-party functions; external parameter names are not a dependable compatibility contract.
- Do not use Fibers or raw `curl_multi` to imply parallelism inside synchronous WordPress requests. Network fanout belongs in Action Scheduler, batched cron/queue workers, or a future edge service so WordPress HTTP policy, proxies, SSL handling, filters, and failure isolation remain intact.

### PHP 8.2, REST, and Cache Audit Decision (Batch 26.16)

The attached modernization review was checked against canonical code rather than accepted as a blanket rewrite plan.

| Finding | Verdict | Engineering decision |
|---|---|---|
| Public Cart REST routes had no origin permission check | **Valid** | Batch 26.16 adds shared same-site permission callbacks to Cart reads and mutations and rejects an explicit `Sec-Fetch-Site: cross-site`. Existing mutation rate limits remain in the handlers. Missing browser headers remain accepted for compatibility. |
| Rewards REST had no rate limiting | **Partly valid** | Reward Service already has signed, short-lived, single-use play tokens plus visitor/user/IP daily ledgers. Batch 26.16 adds a 60-request/minute controller flood guard as defense in depth; it does not replace reward-economic controls. |
| Eleven address meta writes can be replaced by one `update_user_meta()` array call | **Incorrect premise; valid ownership concern** | WordPress has no generic one-call API for eleven arbitrary Woo billing/shipping meta keys. Batch 26.17 moves supported billing/shipping fields through `WC_Customer` setters plus one `save()`, retaining user-meta fallback only for absent Woo APIs or nonstandard fields. Direct SQL remains rejected. |
| No object cache is used | **Incorrect** | SecureTrack already uses `wp_cache_*`, and WordPress Transients automatically use a configured persistent object cache. Cache only measured hot paths; do not replace durable transients indiscriminately. |
| Package preflight `file_exists()` calls happen per request | **Valid cost; unsafe proposed cache** | Keep the fail-open preflight because it prevents partial Hostinger uploads from causing public fatals. Batch 26.17 did not cache it: a successful cached checksum can hide a file removed later, while manual Hostinger folder uploads are not atomic. A release manifest may replace it only after an atomic upload/completion-marker process exists. |
| Safety migration flags query non-autoloaded options repeatedly | **Valid** | Batch 26.16 adds one autoloaded migration-version sentinel. Legacy flags are read only on the first request that has not completed the migration. |
| Settings/manifest resolution repeats option reads and recursive merges | **Valid adjacent finding** | Batch 26.16 memoizes resolved Settings and manifest data for the current PHP request and invalidates both after updates. |
| PHP 8 features are broadly unused | **Valid observation; overstated performance benefit** | Align the runtime contract first, then modernize narrow internal seams. Constructor promotion, `match`, enums, readonly objects, attributes, and union types do not themselves remove database or network cost. |

**Safe lane, executed in `0.4.62`:** Cart same-site guards; explicit cross-site Fetch Metadata rejection; reward controller flood limiting; request-scoped Settings/manifest memoization; one-time safety migration sentinel.

**Medium lane, first controlled pass executed in `0.4.63`:** raised the package minimum to PHP 8.2; added promoted typed constructors to selected internal services; introduced `Flow_Context` and readonly `Flow_State` behind the existing Protected Flow array contract; replaced its message switch with `match`; moved supported address writes/reads through `WC_Customer`; and added opt-in cache timing without changing cache semantics.

**Medium lane still profile- or deployment-gated:** run the PHP 8.2 WordPress/Woo/Bricks/live-gateway matrix before wider syntax conversion; compare cache profiles on a no-object-cache host and Redis/Memcached host; modernize more internal seams only when touched; and defer generated package-checksum replacement until deployments can publish a completion marker atomically.

**Cache profiling contract:** temporarily define `DSA_PROFILE_CACHE` as `true` on a test site. Kiwe writes one privacy-safe shutdown summary containing backend type, operation counts, request-cache hits/misses, total time, average time, and maximum time. It currently measures Settings/manifest resolution, the shared transient rate limiter, and Schema/GEO transient access without logging keys, IPs, users, or payloads. Disable the constant after collecting comparable traces. Static call-site review found the largest transient families in SecureTrack, Commerce, Rewards, Notifications, and REST; many are security locks, replay tokens, or expiry contracts and must not be replaced merely because they are frequent.

**Risky or rejected lane:** named arguments into external WordPress/Woo/Bricks APIs; reflection-based REST registration; broad enum/readonly rewrites; direct-SQL user-meta batching; replacing all transients with `wp_cache_*`; hiding package preflight behind persistent cache; Fibers/raw cURL fanout in visitor requests.

---

### Layer 4 — Bricks Integration & Element Intelligence

During `bricks/frontend/render_element`:

1. Inject `data-dsa-bricks-id` + `data-dsa-bricks-type` on root nodes.
2. Classify rendered HTML (heading, form, image, navigation, layout, region).
3. Accumulate registry: label, selector, confidence, `editable`, `aiVisible`.

**Why this matters:**
- AI doesn't parse DOM blindly — it reads **structured registry**.
- Admin "point to edit" resolves to `[data-dsa-bricks-id="…"]` — stable across caches.
- SEO pages stay semantic; shell consumes metadata side-channel.

**Innovation:** Page builders store JSON in post meta already. DSA **observes render output** to build a live semantic map — no manual tagging, no duplicate schema in ACF. The builder did the work; DSA **listens**.

---

### Layer 5 — Data & Identity

| Data | Storage | Pattern |
|---|---|---|
| Kiwe settings | `dsa_settings` option | Recursive defaults merge; avoid autoload bloat for large blobs |
| Shell manifest | `dsa_shell_manifest` option | Version key for SW/cache invalidation |
| Game scores (guest) | Transient / custom table | Short TTL; no PII in key |
| Game scores (user) | User meta / custom table | Leaderboards per game/page/site |
| Customer score | User meta composite | Feeds SSO reputation (roadmap) |
| Registry snapshot | Post meta `_dsa_registry_snapshot` | Built on save; future fragment/schema readers use cache |
| Analytics | Privacy-light custom event tables plus bounded aggregates/retention | Avoid raw identifiers and unbounded write/read paths; profile before changing storage |

**Future identity network (platform expansion, not current implementation):**
- PhoneKey verification remains the local root identity first.
- Cross-site trust would require a central DSA identity service or federated PhoneKey issuer, with consent, deletion, expiry, and opaque tokens.
- The long-term ambition is that the dock can become **your account on the web**, but today's work must only preserve the rails for that future.

---

### Layer 6 — Shared Hosting Economics

Every architectural decision assumes: **64 MB PHP memory, no Redis, unreliable cron, no Node on server.**

| Technique | Benefit |
|---|---|
| Server-rendered screens | Full-page cache compatible |
| Interactivity API vs React forms | ~10 KB shared vs 80–150 KB per plugin |
| Surface screens = server contracts + light hydration | No heavy frontend framework required |
| Registry cached at save | No regex scan at view time |
| Flat-file metrics | No DB write per pageview |
| MU-plugin early gating | Skip loading heavy services in admin/AJAX |
| Browser image processing | CPU stays off host |
| Deferred game/screen JS | Shell boot < critical path |
| Transients for rate limits | Works without Redis |

**The bind:** Shared hosting is not the enemy — **uncoordinated plugins** are. DSA is one coordinated system with one asset budget.

---

### Layer 7 — WordPress 7 Native Integration

**Platform verification note (2026-06-19):** official WordPress docs place the Interactivity API and Block Bindings API in WordPress 6.5+, DataViews/DataForm as a maturing admin/UI package through 6.9 and 7.0, Abilities as a core command surface introduced before/into the 7.0 cycle, and the WP AI Client as a WordPress 7.0 core capability. DSA should therefore treat WP-native APIs as real acceleration rails, but still feature-detect every call and keep the MU shell stable when an API or host configuration is missing.

| WP 7 API | DSA usage |
|---|---|
| **Abilities API** | DSA command registry: `dsa/summarize-route`, `dsa/audit-trust`, `dsa/update-link-hub`, `dsa/create-transition-message`, `dsa/generate-game-offer`, `dsa/scan-security`, `dsa/explain-checkout-risk`. These abilities are machine-readable, permission-scoped, and reusable by AI agents, admin tools, WP-CLI, and future automation. |
| **WP AI Client** | Admin/owner intelligence: generate transition copy, suggest trust copy, explain SecureTrack alerts, improve link hub, summarize page registry, recommend checkout trust fixes. Visitor trust must never depend on AI; SSL/payment/auth signals remain deterministic. |
| **Connectors** | No duplicate API key storage when WordPress connectors are available. Site owner configures providers once; DSA consumes the native connector layer. |
| **Interactivity API** | Reactive DSA states: notification header, profile state, appsite home idle state, checkout trust updates, cart/account microstates, interactive screen forms. The persistent shell still boots in `surface.js`; Interactivity handles reusable reactive islands. |
| **Block Bindings** | Bind DSA UI to real WordPress/WooCommerce data: site logo/title/tagline, user profile fields, Woo order/account data, product/category data, trust provider labels, PhoneKey state, SecureTrack status. |
| **PHP-only blocks** | Server-rendered interactive screen components: trust badge, app buttons, account field, secure action, POS/scanner panel, checkout assurance panel, testimonial/review item. Useful for shared hosting because no React build is required for simple server-rendered blocks. |
| **DataViews** | Kiwe admin for games, dock, link hub, appsite events, POS config, SecureTrack events, and surface trigger rules. |
| **Browser image/media APIs** | Avatar upload, profile image crop, AI image preview, PWA icons, client-side media transforms where the browser can spare shared-host CPU. |

**Implementation doctrine:**
- The MU-plugin shell is the permanent appsite runtime. It renders early, owns the dock, owns full-screen DSA screens, and survives navigation where possible.
- WordPress 7 APIs are adapters behind the shell. They should improve admin power, trust, editing, automation, and reactive islands without making the visitor experience fragile.
- Every WP7 feature must be feature-detected (`function_exists`, script handle availability, package availability, capability checks). Missing APIs fall back to the existing DSA REST/PHP/JS behavior.
- Checkout, cart, login, reset password, and payment remain hard boundaries. If a native API introduces uncertainty there, DSA chooses full navigation and trust framing over cleverness.

**Current code state (`0.5.11`):**
- `includes/WP7/Native_Service.php` and adapter classes exist for Abilities, AI Client, Interactivity, Bindings, DataViews, and interactive blocks.
- Availability summaries remain for every adapter. Abilities now register two bounded admin-only readonly executions, and Interactivity receives one native event-store bridge; all other native adapters remain fallback-aware and non-authoritative.
- The next useful native step is not "replace `surface.js`." It is to move specific stable islands into native APIs: admin DataViews tables, Block Binding sources for trust/profile/cart data, and Interactivity API stores for small reactive account/cart/permission components.
- Abilities and AI Client should begin admin-only and read-first. Visitor trust, payment confidence, and security decisions remain deterministic.
- The AI notification runtime publishes `surface:ai:notifications`; PWA and browser-notification state publish `surface:app:adoption`. These normalized contracts let native islands subscribe without taking ownership of the shell or server-authoritative actions.
- The AI notification bridge, app-adoption bridge, and read-only data bridge are the first native-island baselines. When the Interactivity API is available, DSA seeds `kiwe/ai`, `kiwe/app`, and `kiwe/data` state namespaces; fallback bridges mirror `surface:ai:notifications`, `surface:app:adoption`, and read-only native data into `window.DSA.nativeStores` and republish island events. Cart mutation, discount calculation, checkout, PhoneKey, and SecureTrack remain on server-verified contracts.
- `WP7\Bindings_Service` now registers a safe `kiwe/site` Block Binding source on `init` when WordPress exposes the binding API. It returns public site identity values only: title, tagline, home URL, site icon, and Kiwe full-size logo variants.
- `Site_Identity_Service` owns Kiwe site logos separately from WordPress Site Icon: a normal full-size logo and a full-size inverse logo are available in Settings > General and Customizer > Site Identity. Legacy light/dark logo options are read only as inverse fallbacks during upgrades. DSA surfaces and Bricks tags use full logo image URLs; PWA install icons use only the square WordPress Site Icon.
- Kiwe Analytics is no longer WooCommerce-named or WooCommerce-gated. The default Analytics tab is the visitor/identity/funnel view, so non-commerce Appsites still get privacy-light visitor insight. WooCommerce tabs appear only when WooCommerce is active.
- Admin AI notifications currently include three automatic owner-facing event families: aggregated visitor activity for today versus yesterday, new WooCommerce orders, and new comments/reviews. Visitor activity is aggregated into one daily AI item that links to Kiwe > Analytics; order and comment notifications link to the relevant Woo order or WordPress comment backend page.
- PhoneKey privileged login is passkey-first. High-privilege enrollment now requires a fresh, five-minute, client-IP-bound WordPress password proof; privileged WebAuthn registration and login require the authenticator's user-verification flag, and role elevation invalidates prior password binding, high-assurance state, and trusted devices. Kiwe asks for an authenticator app code only when a verified authenticator factor actually exists.
- Owner AI now has two visitor modes: a daily aggregated visitor summary and a live latest-visitor alert. Live visitor alerts replace the prior live visitor item in the AI screen, poll only while the owner has the site/app visible, and link to Kiwe > Analytics.
- Live visitor intelligence excludes administrators, shop managers, and editorial staff from visitor capture. It distinguishes arrival, revisit, route movement, product/cart/checkout context, cart additions, saves, and identity conversion; identical route or activity events are throttled rather than emitted repeatedly while the visitor is idle.
- Ordinary AI popup notifications default to 3.2 seconds and can be configured from 2-15 seconds in Kiwe > App > Browser Notifications. Required identity, install, and permission actions remain locked until their explicit action is completed.
- Saved is a default-on registered Surface module. Without WooCommerce it is a bookmark screen; with WooCommerce it combines Wishlist and Bookmarks behind an animated bookmark/heart dock glyph. `data-kiwe-save` gives Bricks and ordinary templates a builder-neutral save trigger, anonymous state remains local-first, and signed-in state is synchronized to user meta through a same-site, rate-limited REST contract.
- Light/dark mode is a dock action, not a DSA screen. Kiwe persists and emits the same `brx_mode` plus `data-brx-theme` contract used by Bricks 2.3.7, observes Bricks' own toggle, mirrors the resolved mode to `data-kiwe-theme`, switches configured inverse logos, and applies a dark glass Surface treatment. Bricks page colors change only where the developer has configured dark color variants in Bricks.
- SecureTrack remains registered and its Secure DSA renderer remains intact, but the Secure module is internal and no longer appears in the admin dock or Dock Icons settings.
- Responsive geometry is now a shell-level contract rather than a mobile dock patch. The server emits enabled main/AI dock-item counts; one runtime formula resolves horizontal or vertical control, icon, badge, gap, padding, cluster footprint, safe-area reserve, and admin-bar offset from the visual viewport. Panels, the cart checkout action, the AI composer, and dock reserve consume the same resolved variables.
- The DSA menu can now use an existing WordPress navigation menu from Appearance > Menus or Customizer > Menus. Custom DSA menu rows remain as a fallback. Bricks 2.3.7 was checked: it has Nav Menu element support, but no generic menu dynamic tag in the dynamic-data providers, so Kiwe adds `{kiwe_menu_<menu_id>}` tags under the Kiwe group for template contexts that need a saved WordPress menu.
- DSA Live Search is implemented as an additive module, not a global search runtime. The Search dock screen carries only endpoint/module metadata at boot and dynamically imports its ES module on first open. It uses route-aware Product/Post/Author scope, one canonical in-Surface card renderer, AbortController, adaptive debounce, ghost results, server-side highlighting, role/user/location-aware object-cache and transient keys, and a private-cache REST response. Shop/product archives select Products; the Posts page and post archives select Posts; author archives select Authors. Bricks integration delegates page-result rendering to Bricks 2.3.7's native ordinary AJAX Filter - Search or Live search query contract, preserving Bricks query ownership. The full search infrastructure does not load on pages where the visitor never opens Search.
- Frontend diagnostics are admin-gated. `WP_DEBUG` no longer turns Kiwe Surface or Bricks adapter console traces on by itself; admins can expose runtime debug state and console logging from Kiwe > Surface > Diagnostics, while `?dsa_debug=1` and localStorage `DSA_DEBUG=1` remain temporary browser-only overrides.
- `Design\Seam_Token_Service` is the canonical public design-token contract for both Kiwe Surface and page builders. Active/hover/hero/blur settings override the corresponding universal values at runtime and in Bricks export; responsive dock controls, icons, badges, gaps, padding, viewport gutter, typography, layout, motion, radii, colors, and z-index all live in the same `kiwe-*` vocabulary.
- `Design\Token_Schema` remains only a versioned `dsa-*` compatibility adapter for existing CSS and consumers. It aliases canonical `kiwe-*` values and publishes derived footprint/content calculations, but its duplicate admin table was removed. Developer attributes moved from Kiwe > App to the dedicated Kiwe > Attributes area.
- Runtime performance profiling is observe-only and admin-controlled. Kiwe > Surface > Diagnostics can write privacy-light request, service, cache, mark, and optional `SAVEQUERIES` totals to `debug.log`; no optimization, routing, or cache behavior changes when it is enabled.
- Asset ownership manifests are observe-only and admin-controlled. Kiwe > Surface > Diagnostics can log active script/style handles, dependency lists, owner guesses, placement, inline byte counts, local file size, and privacy-light route context before any asset dedupe, critical CSS, or delivery optimization is attempted.
- `Element_Registry` now has a save-time snapshot baseline. On post save it stores a versioned `_dsa_registry_snapshot` from WordPress content and known Bricks data keys, then live-rendered registry data remains preferred at runtime with snapshot fallback metadata for schema, AI, bindings, and future fragment envelopes.

**Official platform references checked for this roadmap:**
- [WordPress Interactivity API reference](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/)
- [WordPress Interactivity package reference](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-interactivity/)
- [Block Bindings API reference](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-bindings/)
- [WordPress 7.0 Field Guide](https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/)
- [DataViews/DataForm in WordPress 7.0](https://make.wordpress.org/core/2026/03/04/dataviews-dataform-et-al-in-wordpress-7-0/)
- [MDN Web application manifest](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Manifest)
- [MDN `beforeinstallprompt`](https://developer.mozilla.org/en-US/docs/Web/API/Window/beforeinstallprompt_event)
- [MDN making PWAs installable](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable)
- [web.dev installation prompt](https://web.dev/learn/pwa/installation-prompt/)
- [Apple Safari Add to Home Screen](https://support.apple.com/guide/iphone/turn-a-website-into-an-app-iph42ab2f3a7/ios)
- [MDN Notifications API](https://developer.mozilla.org/en-US/docs/Web/API/Notifications_API/Using_the_Notifications_API)

**Highest-value WP7 abilities to register first:**

| Ability | User value | Permission boundary |
|---|---|---|
| `dsa/audit-trust` | Explains why visitors may or may not trust checkout; powers Links and Checkout recommendations. | Admin only; read-only site/Woo/security data. |
| `dsa/create-transition-message` | Generates branded transition messages from page/category/product context. | Admin only; writes to DSA settings after confirmation. |
| `dsa/update-link-hub` | Lets admin/copilot update social links, trust labels, testimonials, and post category. | Admin only; nonce + capability + sanitized option write. |
| `dsa/summarize-route` | Gives admin and AI dock route context from registry and content. | Read-only; no private user/order data unless authenticated and allowed. |
| `dsa/scan-security` | Surfaces SecureTrack findings in Kiwe > Secure and admin DSA screen. | Admin only; throttled; never visitor-facing raw logs. |
| `dsa/explain-checkout-risk` | Explains checkout trust gaps and recommends fixes. | Admin only; deterministic sources first, AI only for explanation/copy. |

**Future SiteIQ-style bridge concept (not implemented):**
- SiteIQ audits site health; DSA Links/Trust module **surfaces** SSL/payment/auth trust to visitors.
- SiteIQ Abilities callable from DSA AI dock for owner-facing fixes; DSA registry feeds SiteIQ `ai_readiness`.

**Future Slate-style content bridge concept (not implemented):**
- Slate keeps page body lean (forms, TOC, consent in PHP blocks).
- DSA shell never duplicates Slate blocks — composes underneath.

---

## Trust & Transparency — Links Screen

The Links module is not link-in-bio cosplay. It is one presentation of the **Trust Engine**: a visitor-facing trust dashboard that also feeds Checkout, Profile, Admin AI, and Secure screens.

- Site score (configurable + future SiteIQ sync)
- Social proof (Google Places or manual testimonials)
- **SSL secured by {provider}** — detected from host/server hints or admin override
- **Payment protected by {gateway}** — live WooCommerce gateway list
- **Secure login by Kiwe Key** — PhoneKey availability
- Latest posts / shop entry

**Innovation:** Trust signals usually live in footer text nobody reads. DSA puts them in the **profile-adjacent dock module** and Protected Flow Mode — where users already look when deciding whether to buy, sign up, or reset sensitive account details.

---

## POS & Storefront Bridge (Roadmap)

Physical retail + web unified under one appsite:

- Admin registers POS devices (scanner keyboard wedge / camera API).
- Scan events → REST → WooCommerce order notes or custom order status.
- Protected Flow Mode shows **reactive header**: "Kitchen received order #1234."
- Web Push extends notification offline.
- Abilities API scopes which devices/users can post scan events.

**Cut:** POS usually means a separate SaaS. DSA binds scanner input to **WordPress order post type** — inventory the store already owns.

---

## Reactive Notifications

| Event | Channel | Screen |
|---|---|---|
| Order status change | Header bar + Push | Protected Flow / any |
| New blog post | Header bar + Push | Appsite Home / any |
| Leaderboard beaten | Header bar + Push | Game / Profile |
| Profile incomplete | Dock badge | Profile module |

**Engineering:**
- Interactivity API `@context` store in shell — single reactive tree.
- Server events via REST long-poll or Heartbeat API (shared-host safe) → optional WebSocket on managed hosts.
- Push subscriptions use the encrypted Kiwe subscription store; PhoneKey/WordPress identity may promote the privacy-safe device record.

**Innovation:** During one document lifetime the Surface owns notification continuity; browser Push continues when the page is closed. On ordinary full navigation the shell remounts and rehydrates persisted state. S15 adds a narrowly controlled static-editorial exception, disabled by default; proven Bricks continuity and broader earned morphing remain APEX roadmap work rather than production claims.

---

## Customization — Any Company, Their Brand

| Knob | Source |
|---|---|
| Colors | Canonical `kiwe-*` universal tokens with `dsa-*` compatibility aliases |
| Blur / glass / loader | Visual effects settings |
| Dock layout | Desktop vertical / mobile horizontal |
| Enabled dock items | Per-module toggles |
| Home hero copy | App settings + site tagline |
| Transition messages | Admin message pool |
| Surface triggers | Trigger Engine |
| Game rewards | Reward Engine |
| Trust badges | Trust Engine |

**No rebuild required** — branding is data driving CSS variables, not compiled Tailwind. Child agencies ship **appsite profiles** as JSON export/import.

---

## Security Model

- Fragment nav: same-site host validation only.
- REST write routes: capability + nonce.
- SecureTrack bundled for admin dock (WAF, rate limits, CSP report mode).
- PhoneKey step-up for sensitive profile/POS actions.
- Abilities API explicit permission boundaries for AI agents.
- Registry public read — intentionally metadata only; no private selectors.

---

## What Makes DSA Unique — The Cuts & Bridges

| Everyone else | DSA |
|---|---|
| Full-page React theme | Persistent shell + partial Bricks render |
| Popup for engagement | Surface modes scheduled in dead time and decision moments |
| 5 form plugins | Composed Action Mode with WP7-native blocks/bindings |
| Trust in footer | Trust Engine badges live from WC/server/PhoneKey/SecureTrack |
| AI chatbot widget | Registry-aware Abilities + point-to-edit |
| PWA = manifest only | Home ritual + Responsive Geometry Engine + install flow |
| Builder dynamic tags in PHP templates | Kiwe Bricks tags shipped; Block Bindings + persisted registry are the native next layer |
| Analytics JS | Optional PHP pulse; shell events either way |
| SSO = OAuth plugin | PhoneKey + customer score + federated dock (roadmap) |
| POS = separate app | Scanner → WC REST → shell notification |

**The meta-innovation:** DSA doesn't ask WordPress to become something else. It **assigns roles**:

- **WordPress/Bricks** → SEO body, commerce authority, forms data model
- **Appsite Surface** → continuity, identity, delight, trust, speed perception
- **WP 7 APIs** → AI, interactivity, bindings without npm
- **Browser** → PWA, push, view transitions, speculation, camera/scanner

The materials were on the table. DSA **cuts** along the SEO vs non-SEO boundary, **patches** fragment rendering through Bricks natively, **binds** trust data through one Trust Engine, **bridges** PhoneKey into the identity spine, and **engineers** one persistent Appsite Surface so every future feature has a mode, module, trigger, and safety contract without bloating the page.

---

## Historical Codebase vs Target Map

> **Historical record:** This table captures the direction before the batch 26.18 reconciliation. It is intentionally preserved to show how DSA evolved. The authoritative status is the **Current Code Audit & Production Forecast** and the authoritative forward order is the **Safe-First APEX Roadmap** plus **Production Gates** below.

| Capability | Status |
|---|---|
| Surface dock + overlays | Shipped |
| Phantom Viewport (historical name) | Shipped and superseded by the Responsive Geometry Engine |
| Bricks element registry | Shipped live plus save-time snapshot fallback |
| Legacy fragment navigation | Removed from the production route surface. The controlled S13-S16 editorial morph successor is implemented behind a gate; live compatibility proof remains open. |
| Surface modes/modules/triggers model | Target model documented; current code still partially hardcodes modules and events |
| PhoneKey + WC cart/profile | Shipped baseline |
| Links trust hub | Shipped |
| Games on navigation | Shipped |
| PWA/Appsite Home + idle | Controlled install foundation shipped: Home platform badges, manifest, safe-shell service worker, iOS guidance, browser-gated Android prompt, readiness diagnosis, standalone detection, session/scroll/idle behavior |
| Browser notifications | Preference-first topic/channel/category journey, explicit native permission, product Notify me entry points, passive order-status AI prompt, and iOS install continuation shipped; remote Web Push subscription/delivery is roadmap |
| POS scanner | Roadmap |
| Customer score + SSO | Roadmap |
| Permission Journey Manager | Shipped v1.5 with browser-honest install, preference-first notification consent, strict standalone PhoneKey/verification arbitration, remote push, owner order/comment alerts, and iOS standalone resume; location/camera remain future gated flows |
| Commerce Context Engine | Adopted target with safe/no-blocking navigation rules |
| Interstice Attention Metrics | Shipped v1.2 aggregate proof plus a distinct intent/primer/prompt/confirmed-install funnel and unique salted visitor/IP adoption ledger with later WordPress/PhoneKey identity resolution; revenue/session lift deferred |
| Schema/GEO Engine | Adopted target; high-confidence/admin-governed only |
| WP 7 Abilities + AI Client | Adapter shell exists; admin-only read-first execution is next |
| Interactivity API islands | Adapter shell plus normalized `surface:ai:notifications` and `surface:app:adoption` event contracts exist; first native island is next, not a shell replacement |
| PHP-only blocks + Block Bindings | Target for Composed Action Mode and admin-composed server-rendered components |
| Future platform rails | Architecture constraints documented now; Partner SDK, Cloudflare tier, marketplace, cross-site identity network are future expansions |
| Popup coexistence focus stack | Roadmap (documented contract) |
| Registry persist on save | Baseline shipped; object-cache/schema-envelope integration remains roadmap |

---

## Historical Architecture Issues & Execution Packs

This section preserves the calculation model that produced batches 1-26.18. Completed outcomes are recorded in the later Batch Ledger; unresolved work has been re-estimated in the current production and APEX roadmaps.

`Roadmap priority = trust impact + adoption impact + architecture unlock - implementation risk - context/token cost`

Context/token cost matters because DSA is now a cross-system product. A small visual fix, a checkout guard, a PhoneKey token decision, and a fragment navigation rewrite do not belong in the same attempt. The best roadmap is the one that preserves judgment.

| Attempt size | Token band | Meaning |
|---|---|
| **XS** | 2k-5k | Tiny doc/code pass, one small setting, wording, or visual correction. |
| **S** | 5k-9k | One narrow subsystem, minimal cross-state risk. |
| **M** | 9k-16k | Several related files or one meaningful behavior path. |
| **L** | 16k-26k | Cross-system behavior with state, settings, PHP, JS, and regression checks. |
| **XL** | 26k+ | Milestone-level work. Must be split into design and implementation passes. |

### Patch Review Decision

| Proposal | Decision | Reason |
|---|---|---|
| Permission Journey Manager | **Adopted v1.5** | PWA installation and notification permission remain separate but share saved preferences. Product/order entry points, topic/channel/category selection, strict standalone PhoneKey/verification arbitration, Android permission, iOS standalone resume, remote push, and owner order/comment alerts exist; location and scanner stay future. |
| Mode arbitration priority | **Adopt now** | Prevents Home, Transition, Game, Dock, and Protected Flow collisions. |
| Game abuse prevention | **Adopt now** | Rewards create money value; credibility requires server-side limits and logs. |
| Interstice Attention Metrics | **Adopted v1** | DSA now proves transition, dock, appsite home, PWA, protected-flow, game, and reward activity with privacy-light aggregates; revenue lift remains later. |
| GEO audit | **Adopt now** | Admin-only analysis can improve pages without risky visitor-facing automation. |
| Commerce context | **Adopt with changes** | Use cart/page context for helpful moments; do not block navigation or require add-to-cart before destination. |
| Structured data | **Adopt with changes** | Generate high-confidence structured data only from authoritative Woo/registry cases with admin control. |
| Perceived load wording | **Adopt with changes** | Use "instant response" or "one-frame surface response"; do not claim impossible load elimination. |
| Appsite profile marketplace | **Adopt with changes** | Build export/import and profile contracts first. Marketplace is future expansion. |
| Partner SDK | **Future rails only** | Partner moments must become merchant-opt-in trust moments later, not default transition ads now. |
| Cloudflare distribution | **Future rails only** | Keep boundaries compatible, but do not ship an edge tier until the WordPress product is stable. |
| Cross-site identity token | **Future rails only** | Decide token principles now; ship after local PhoneKey, privacy, consent, deletion, and issuer operations are mature. |
| Default partner ads | **Reject near-term** | Ads weaken the trust promise unless explicitly merchant-opt-in and context-aligned. |
| Navigation-blocking cross-sell add-to-cart | **Reject near-term** | Commerce suggestions must fail open and never trap the route. |
| "Zero load regardless of actual load" claim | **Reject** | DSA can make the site respond instantly, but actual loading still exists and must be respected. |

### Historical Calculated Roadmap

| Order | Execution pack | Size | Ship soon / design later | Why this priority is correct |
|---|---|---|---|---|
| 1 | **Appsite Home + Surface Token Reliability** | M | Ship soon | First impression, active/hover colors, session-once Home behavior, scroll dismissal, and visual unity determine whether DSA feels native or ornamental. |
| 2 | **Navigation + Transition Safety + Mode Arbitration** | M/L | Ship soon | Fix stuck loaders, back/forward traps, hover-hold release, fallback timers, route exclusions, and the priority stack before deeper experiences depend on it. |
| 3 | **Trust Engine + Protected Flow Guard** | Two M attempts | Ship soon | Checkout, payment, login, reset, account, order, and profile flows need deterministic trust and safety before monetization or automation. |
| 4 | **Trigger Engine + Surface Contract** | L | Ship soon | Converts scattered feature logic into `event -> condition -> mode/module -> duration -> dismiss -> frequency -> guard`. |
| 5 | **Permission Journey Manager** | M | Ship soon | Must exist before PWA, push, location, scanner, and notification asks become more active. |
| 6 | **Module Registry + Runtime Cleanup** | L split incrementally | Ship soon | Dock modules must register through contracts so Menu, Profile, Cart, Links, AI, Secure, Rewards, POS, and future modules do not hardcode the runtime. |
| 7 | **WP7 Native Adapter Layer** | M/L | Ship soon, feature-detected | Abilities, AI Client, Interactivity API, Block Bindings, DataViews, and PHP-only blocks should strengthen DSA without making the shell fragile. |
| 8 | **AI Admin Copilot** | M per workflow | Ship soon in bounded workflows | Start with audit-trust, transition copy, SecureTrack explanation, and audit-GEO. AI suggests; deterministic systems render visitor-facing trust. |
| 9 | **Commerce Context Engine** | M | Ship soon after trust/trigger basics | Cart-aware transitions and checkout confidence are valuable, but add-to-cart must remain optional and non-blocking. |
| 10 | **Schema/GEO Engine** | M/L | Ship soon in constrained form | Start with Woo Product/Offer/Breadcrumb and high-confidence registry cases, cached by registry snapshots and governed by admin toggles. |
| 11 | **Admin UX + Appsite Profiles** | M/L | Ship soon | Reorganize admin around Appsite, Surface, Dock Modules, Trigger Rules, Trust, Rewards, Secure, Advanced. Export/import before marketplace. |
| 12 | **Game & Reward Engine Hardening** | M/L | Ship soon after trigger/registry | Add attempt ledger, server coupon expiry, PhoneKey-linked top scores, anti-abuse, retry copy, and share-safe leaderboards. |
| 13 | **Interstice Attention Metrics** | M | Ship soon | Proof layer for transition engagement, dock opens, game completion, PWA install, permission conversion, and revenue/session lift. |
| 14 | **Fragment Navigation + Registry Persistence** | XL split | Earn later | High upside, high risk. Split into registry snapshots, fragment envelope, route classes, asset reconciliation, and fallback verification. |
| 15 | **Offline, Push, POS, Customer Score** | XL split | Earn later | Requires PJM, Trigger Engine, Trust Engine, Protected Flow, PhoneKey, and audit trail stability first. |
| 16 | **Future Platform Expansion Appendix** | Milestone-level | Design now, ship later | Partner SDK, Cloudflare tier, appsite marketplace, and cross-site identity network stay future expansions with constraints respected now. |

### Historical Fix Priority By Attempt Economics

| Club | Packs | Can be handled together? | Reason |
|---|---|---|---|
| **Immediate trust repair** | 1, 2 | Sometimes, if scoped | Home/session/token issues and transition safety share UI state, but back/forward fallback work should remain focused if regressions appear. |
| **Money and identity safety** | 3 | No | Protected Flow, checkout, payment, login, and PhoneKey step-up deserve isolated attempts. |
| **Operating-system spine** | 4, 5 | Split | Trigger Engine and Module Registry both shape future scale; doing both fully in one pass is too much context. |
| **Earned permissions and native WP power** | 5, 7, 8 | Split by workflow | Permission asks, Abilities, AI writes, and block-bound actions each need capability and privacy review. |
| **Commerce and proof** | 9, 10, 13 | Split | Commerce context, schema/GEO, and analytics all read site data differently; each needs clear privacy and caching boundaries. |
| **Rewards** | 12 | Mostly isolated | Game economics touch Woo coupons and PhoneKey identity, so reward money logic should not be mixed with visual game polish. |
| **High-risk expansion** | 14, 15, 16 | Always split | Fragment navigation, offline/POS/customer score, Cloudflare, Partner SDK, marketplace, and cross-site identity are milestone tracks. |

**Engineering constraint:** Avoid bundling high-intelligence packs together. Fragment navigation, Protected Flow Guard, SecureTrack request filtering, registry persistence, PhoneKey identity promotion, AI writes, POS, Woo coupon creation, and future token issuer decisions each deserve focused attempts.

---

## Current Code Audit & Production Forecast

Audit baseline: after batch 26.63, plugin version `0.5.9`, scanned from the only canonical deploy folder `wp-content/mu-plugins` with package code in `wp-content/mu-plugins/dsa`. Version `0.5.9` preserves unified viewport, measured context geometry, Search-family filtering, Profile status rail, directional motion, haptic, and material behavior while turning Search and Menu into governed Context Engine clients. Bricks Filter Search exposes an opt-in DSA control, generated element/query IDs remain site-owned, explicit markers win over backward-compatible automatic discovery, contextual pills stay selected, alphabet and product quick-add flags reach the lazy Search module, and Search cache generation can be advanced from admin. Menu combines multiple WordPress menus, DSA-only links, and route-governed rendered headings without PHP content scanning or builder-specific IDs; primary navigation precedes the editorial table of contents. Generated stylesheet URL/build status, cron generation, cache headers, offline replay, and fallback remain live proof items. S19 completes the approved APEX architecture by publishing a safe machine-readable `kiwe-apex-v1` acceptance profile, linking it from the authoritative manifest, emitting informational runtime/document/edge classification headers, measuring packaged Kiwe CSS/JS bytes, and presenting navigation, offline, asset, accessibility, edge, and production matrices in admin. Current HTML remains `origin-required`; the profile permits only versioned static assets and the isolated public-editorial contract as edge-readable surfaces. PhoneKey, cart, checkout, account, notifications, Saved, admin, personalized documents, and transactional routes remain origin/network-only. Architecture completion explicitly does not certify broad production: live PR1-PR6, S16-S18, browser, host, cache, accessibility, rollback, and performance evidence remains mandatory.

Current incremental baseline: after batch 26.65, plugin version `0.5.11`. This supersedes the version number in the preserved 26.63 audit narrative above; its broader architecture and production-proof cautions remain valid.

This section supersedes the older "Current Codebase vs Target" table above. "Complete" means a first wired implementation exists in code. It does not mean broad production QA has been completed across live stores, cache plugins, payment gateways, browsers, and Hostinger shared-host constraints.

### Implementation Status

| Capability | Code status | Production note |
|---|---|---|
| Surface dock + overlays | **Complete shared-shell baseline** | Dock modules render through `Module_Registry`; clicking the active module button closes it, active state is exposed through `aria-pressed`, and every overlay uses one viewport scroll owner. Desktop/mobile orientation and placement are independent: vertical docks support center/bottom; horizontal docks support left/center/right plus an explicit mobile center/bottom height choice. Enabled-item counts and one solver scale either orientation. Mobile Surface activation keeps the live responsive-geometry anchor rather than preserving a stale pixel top while browser chrome or panel scroll changes the visual viewport. Closeable modules claim one same-URL history entry so mobile back gestures close the Surface before navigating the underlying page; required app gates remain non-closeable. Real-device notch, browser-zoom, gesture, and theme regression QA remains required. |
| Responsive Geometry Engine (Phantom Viewport successor) | **Complete responsive-geometry and color-mode baseline** | Active, hover, hero, blur, layout, and geometry tokens are wired. One visual-viewport contract resolves control/icon/badge sizes, gaps, padding, full dock footprint, safe areas, admin-bar offset, panel reserve, sticky cart action, and AI composer position. A no-panel sun/moon dock action synchronizes Kiwe and Bricks light/dark state through `data-brx-theme`, `brx_mode`, and `data-kiwe-theme`; dark Bricks colors remain developer-authored in Bricks. The solver passed 44,184 generated fit cases from 80-1920 CSS pixels and explicit 320px/landscape/admin-bar scenarios; live device rendering is still a production gate. |
| Design token schema | **Complete compatibility adapter** | `Design\Token_Schema` publishes derived footprint/content metadata and legacy `dsa-*` aliases for compatibility. Its values resolve to the canonical `kiwe-*` variables; it is no longer presented as a second editable/admin token system. |
| Kiwe universal token adapter | **Complete canonical runtime/export foundation** | `Design\Seam_Token_Service` owns Kiwe's bundled vocabulary: semantic color, fluid type, spacing, radius, layout and responsive dock geometry, scene density, motion, z-index, and component aliases. The Surface emits and consumes these variables. Kiwe > Tokens shows the active set and exports the same site-specific brand/accent/hero/blur values additively into Bricks variables plus the separate `Kiwe Universal` palette. Existing non-Kiwe Bricks data is retained and backed up. Kiwe imports no project tokens. |
| Appsite Home + idle recall | **Complete controlled baseline** | First-session home exists with session storage, live date/time, explicit cue, app invitation, recognizable platform glyphs, and the same active/inactive Trust Service badge states used by Links DSA. Idle recall is off by default. Home locks the page beneath it and now follows one upward touch gesture directly, dismissing at a lower app-like threshold with snap-back for incomplete gestures. Needs cache, bfcache, incognito, iOS, Android, and protected-route QA. |
| Transition message layer | **Complete baseline** | Full-screen transition messages and commerce-aware copy exist; hover-to-read now uses cancellable timers and a grace release window. Needs timing tests on fast/slow hosts. |
| Navigation safety + mode arbitration | **Code-complete strong baseline; live morph proof pending** | Mode priority, protected exclusions, fallback timers, hover hold, full-page loader, DSA-internal link handling, popup/mini-cart trigger exclusions, stuck-loader release hooks, and popstate safety exist. The unsafe legacy fragment path is hard-disabled. S13-S16 provide the separate controlled editorial envelope, plan, apply gate, and proof harness, but broad application remains off pending its live matrix. |
| Protected Flow Guard | **Complete typed baseline** | Checkout, cart, account, login, payment, order, reset, and cart commits are protected from partial swaps. Internally, PHP 8.2 `Flow_Context` and readonly `Flow_State` replace magic-state mutation while `current()` preserves the established array contract. The visitor-facing protected rail remains admin opt-in and off by default. Needs live Woo checkout matrix QA. |
| DSA Checkout | **Complete controlled baseline** | Admin-on-by-default checkout Surface uses Woo's live checkout field contract, keeps a sanitized draft in the Woo session, supports conditional shipping/account fields, and hands payment plus Place order back to the normal Woo/Bricks page. Classic Woo validation errors reopen DSA for correction and corrected values hydrate the page. Needs live classic checkout, custom-field, gateway, and Checkout Blocks compatibility QA. |
| Trust Engine | **Complete baseline** | SSL, PhoneKey, SecureTrack, payment gateway/manual provider signals exist. Needs admin copy polish and conflict checks with payment plugins. |
| PhoneKey + Woo cart/profile | **P2 code-hardened baseline; live gate open** | Privileged enrollment requires fresh password proof and user-verified WebAuthn; mixed-role policy selects the strongest requirements; elevation/revocation clears stale assurance; public browser auth mutations enforce same-site headers while preserving headerless future token clients; OTPs are flow-bound and OTP/TOTP/backup attempts are throttled. Mail status distinguishes WordPress transport handoff from delivery and points admins to Kiwe Email/SMTP. Profile/cart/address behavior remains as before. Real email delivery, passkey device/browser coverage, proxy identity, and elevation/recovery tests remain mandatory. |
| Links trust hub | **Complete baseline** | Logo, branded Facebook/Instagram/X/YouTube/Pinterest/LinkedIn links, animated Share dock icon, posts, testimonial, health/payment badges, and admin editor exist. Home and Links render the same Trust Service badge list and status colors. Shop and in-Surface Cart actions appear only when WooCommerce and DSA Cart are available. Google reviews remain API-key/manual fallback dependent. |
| Saved bookmarks + wishlist | **Complete explicit enriched + admin analytics baseline** | `data-kiwe-save="wishlist"` and `data-kiwe-save="bookmark"` are distinct contracts and remain distinct even for the same Woo product; `auto` is convenience inference only. Server enrichment never reclassifies intent. Kiwe > Analytics > Saved reports registered users with saved collections, distinct saved objects, per-object user counts, Wishlist versus Bookmark totals, and an authorized per-user drill-down. Anonymous saves remain privacy-light aggregate events and are not reconstructed into personal collections. |
| SecureTrack integration | **P3 code-hardened baseline; live gate open** | SecureTrack remains off/monitor-only by default, boot failures fail open, emergency mode suppresses denials while logging, and private break-glass recovery remains rate-limited. Client IP resolution accepts Cloudflare headers only from current built-in Cloudflare ranges and X-Forwarded-For/X-Real-IP only from built-in or host-confirmed CIDRs configured in Kiwe > Secure. Admin/readiness surfaces show direct peer, resolved IP, source, ignored spoofable headers, schema, recovery, and enforcement status. Real Hostinger/CDN/proxy and false-positive recovery tests remain mandatory. |
| Bricks element registry | **Complete baseline** | Live render observation exists and remains preferred. Save-time snapshots now persist WordPress/Bricks structure as `_dsa_registry_snapshot` and are available as fallback metadata for schema, AI, bindings, and future fragment envelopes. |
| Bricks design/commerce bridge | **Complete controlled baseline** | Bricks 2.3.7 hooks provide mini-cart, add-to-cart, linked-product, and Kiwe dynamic-tag controls. Kiwe no longer duplicates Bricks' native Site title/tagline tags and no longer exposes visitor billing/shipping addresses as public builder data. The Kiwe tag group exposes full-size site logo, site logo inverse, WooCommerce store address fields, selling/shipping location policies, Kiwe store phone/email from Settings > General, saved WordPress menus as `{kiwe_menu_<menu_id>}`, and the missing Bricks 2.3.7 product-weight tag as `{woo_product_weight}`. Bricks owns visual styling while Kiwe owns shared commerce/navigation rules. Additional builders require separate adapters, not generic output rewriting. |
| DSA Live Search | **Complete additive S11 + 26.59 governed baseline** | Search is a registered dock module but its engine is a lazy ES module loaded only after the visitor opens Search. `Kiwe > Search` governs families, context, alphabet, product quick-add, result limits, and Bricks synchronization. Route context preselects Products, Posts, or Authors and the REST service runs only the selected family; no selected family returns all enabled families. The minimal route uses separate ID-only queries, same-site checks, rate limiting, plugin/scope/prefix/role/user/location-aware cache keys, five-minute invalidation versions, server-side `<mark>` highlighting, AbortController, adaptive debounce, a bounded client cache, and one canonical card renderer. Bricks page results are delegated to native 2.3.7 ordinary AJAX Filter - Search or Live search instances without adding a competing filter-history entry; a narrow post-popstate reconciliation preserves the active DSA term after the Surface close entry is consumed. Privacy-light demand counts appear under Analytics. Live WordPress/Woo cache, Bricks loop, accessibility, browser, multilingual alphabet, and relevance QA remain required. |
| Module Registry | **Complete baseline** | Dock modules register through a contract. Some runtime rendering branches in `surface.js` are still hardcoded by panel type. |
| Trigger Engine | **Partial/usable** | Server contract exists for first session, idle, safe link, scheduled game, protected flow. Permission journeys and richer admin trigger rules are pending. |
| WP7 Native Adapter Layer | **Bounded production baseline complete** | Feature detection and summaries exist for Abilities, AI Client, Interactivity, Bindings, PHP-only blocks, and DataViews. `dsa/audit-trust` and `dsa/summarize-route` are real admin-only readonly Abilities. AI, app, and data events use one native Interactivity script module on WordPress 7 and classic bridges elsewhere. `kiwe/site` remains a public read-only Block Binding source. The persistent shell, cart mutations, checkout, PhoneKey, SecureTrack, and visitor trust remain deterministic REST/PHP contracts. |
| AI Admin Copilot | **Complete v1.3** | Admin-only `/dsa/v1/copilot` reports trust, transition, SecureTrack, and GEO readiness. Visitor notifications are compact and viewport-owned, completion records can be permanently dismissed by X or swipe, ordinary popup lifetime is admin-configurable, the horizontal bell tray collapses when chat is engaged, and the static composer stays anchored above the dock. Owners receive private AI inbox events for new orders, comments/reviews, one aggregated visitor summary, and one replacing event-aware live visitor item. Staff traffic is excluded and repeated idle activity is throttled. Action-required records remain non-dismissible. No external visitor-chat dependency exists yet. |
| Commerce Context Engine | **Complete controlled baseline; P1 live proof pending** | Cart/product/category/routes/complements, transition copy, serialized quantity mutations, stock limits, FBT, cart offers, Analytics, validated discounts, and Add & Save auto-claim exist. Kiwe pair claims are canonicalized by sorted product IDs, reject overlapping affected products, cap percent bonuses at 100%, and attach virtual native Woo `percent`/`fixed_cart` coupons to eligible pair quantities. This replaces unsupported negative cart fees and lets Woo allocate coupon discounts to order lines, taxes, totals, and refundable order data. Cart summaries include coupon tax when reporting discount and pre-discount totals. Saved pair and discount notifications reconcile against the live cart; FBT uses explicit plus actions with an admin-controlled out-of-stock policy. The global Woo enhancer and Bricks controls share the canonical DSA mutation queue; emitted Woo events now provide the real button-shaped argument Bricks 2.3.7 dereferences. Checkout creation, paid conversion, and refunds are distinct Analytics events. Live checkout, HPOS, tax, gateway, refund, and Bricks browser matrix QA remains required. |
| Schema/GEO Engine | **Complete constrained v1** | Admin-governed JSON-LD for Woo Product/Offer, Breadcrumb, WebPage/Article, and high-confidence registry hints exists with transient caching. Registry snapshots now exist; schema cache integration with snapshot version/hash remains a later refinement. |
| Admin UX + Appsite Profiles | **Partial/strong baseline** | JSON export/import exists with sanitization and secret-safe export. Kiwe > WooCommerce and Kiwe > Bricks settings now exist, but admin information architecture still needs reorganization into Appsite, Surface, Modules, Trust, Rewards, Secure, Advanced. |
| Games: Dinosaur + Star Shooter | **Playable with server reward baseline** | Games, retry copy, bonuses, confetti, REST play tokens, daily attempt ledger, IP/user/visitor limits, and optional Woo coupon issuing exist. Leaderboards and deeper anti-cheat telemetry are pending. |
| Game & Reward Engine | **Partial/strong baseline** | Server-verified attempts and coupon expiry exist. Still needs live Woo coupon QA, PhoneKey-linked top scores, sharing, and admin reporting before broad reward campaigns. |
| Permission Journey Manager | **Complete v1.5 baseline** | Journey One install and Journey Two notification permission are distinct. The preference-first Surface persists topics, channels, categories, and product intent before PhoneKey, Android permission, or iOS installation. Standalone arbitration is strict: anonymous or unverified app users remain in PhoneKey; verified users must make an explicit notification choice. iOS resumes only from a confirmed standalone launch and an explicit OK gesture; product Notify me and passive order-status AI prompts enter the same contract. Admin PhoneKey remains password-and-passkey-first; authenticator step-up is used only after a verified authenticator exists. Remote push transport is live; location/camera remain disabled future journeys. |
| PWA install foundation | **Complete controlled baseline** | Dynamic manifest, site-icon metadata, root-scoped service worker, Android prompt, iOS Add to Home Screen guidance, `appinstalled`/standalone detection, Home DSA platform badges, automatic Home dismissal on install intent, and console readiness inspection exist. The worker caches only versioned Kiwe static assets and serves a branded offline fallback; personalized HTML, cart, checkout, account, auth, REST mutations, and payments are never cached. A square 512px WordPress Site Icon remains a production requirement and is intentionally separate from full-size Kiwe site logos; browser prompt timing/suppression is not site-controlled. |
| Interstice Attention Metrics | **Complete v1.3 baseline** | Privacy-light aggregates cover dock, transitions, Home, PWA, protected flow, games, rewards, and the site-wide visitor funnel. Kiwe Analytics is available without WooCommerce; Woo-only tabs appear only when WooCommerce is active. Kiwe App Adoption adds unique salted visitor/IP counts for install intent, primer acknowledgment, prompt accepted/dismissed, confirmed installs/standalone launches, and notification grant/denial. The visitor ledger can resolve a later same-IP event to a WordPress/PhoneKey user without storing raw IP, phone, or email. Shared-IP counts remain approximate. |
| Production Readiness Diagnostics | **Complete admin-only v1 baseline** | The readiness report remains in Kiwe admin and checks PHP/WP runtime, uploads, REST, protected routes, fragment safety, Woo/rewards, payment trust, HTTPS, SecureTrack mode/tables/rate limits, file editor, schema, metrics, permissions, and review-source configuration. The `/dsa/v1/readiness` REST mirror was removed in batch 26 to avoid exposing deployment diagnostics through REST. |
| QA diagnostics | **Complete controlled baseline** | Frontend runtime diagnostics are quiet by default and controlled from Kiwe > Surface > Diagnostics. Admins can separately expose `window.DSA.diagnostics` state, Surface/Bricks browser console traces, an observe-only runtime performance profile, and an observe-only asset ownership manifest. The asset manifest logs active script/style handles, owner guesses, dependencies, placement, inline byte counts, local file size, and route context without changing delivery. `?dsa_debug=1` and localStorage `DSA_DEBUG=1` remain temporary browser-only overrides for emergency QA. Server debug-log entries remain limited to explicit backend paths. |
| Fragment navigation + registry persistence | **S16 proof harness complete; live matrix pending** | The unsafe legacy Fragment Controller remains deleted and `fragment_navigation` remains false. S13 provides the guarded envelope, S14 constructs the plan, S15 owns the off-by-default apply gate, and S16 records scenario, cache/CSP, history/bfcache, fallback, and post-commit invariant evidence in browser session storage. Bricks, forms, runtime bindings, media/iframes, protected routes, asset changes, and executable inline changes still force full-document navigation. Authority cannot expand until the live browser/theme/plugin matrix passes. |
| Remote Web Push delivery | **P3 code-hardened transport; live gate open** | Permission preferences, encrypted PushSubscription storage, per-site P-256 VAPID, RFC 8291 encryption, unsubscribe, subscription renewal, dead-endpoint retirement, and service-worker display exist. Broadcasts run through five-device cron batches rather than holding one admin request; daily cleanup removes expired records after 30 days. Readiness reports OpenSSL/P-256/AES-GCM, table, cron, last attempt/success, and stale counts. Owner order/comment alerts retain privacy-safe lock-screen copy. Real Chrome/Firefox/Safari vendors, Hostinger outbound HTTP, and server-cron delivery remain mandatory. |
| Offline PWA strategy | **Complete safe-shell baseline** | Versioned Kiwe assets and a branded navigation fallback work offline without caching private or transactional state. Selective editorial/content caching and offline actions remain future work. |
| POS scanner / customer score / SSO | **Roadmap** | Should wait until trust, permissions, metrics, and PhoneKey rails are stable. |
| Partner SDK / Cloudflare / marketplace / cross-site identity | **Future platform rails only** | Architecture constraints are documented now; these are not production-blocking for the WordPress MU plugin. |

### Batch Ledger

| Batch | Pack | Current documented status | What landed / what remains |
|---|---|---|---|
| 1 | Appsite Home + Surface Token Reliability | **Partial complete** | Home screen, session-once behavior, scroll/touch dismissal, active/hover tokens landed. Needs cache/bfcache/mobile QA and cleanup of preloader/home naming. |
| 2 | Navigation + Transition Safety + Mode Arbitration | **Code complete; live morph proof pending** | Mode priority, protected exclusions, fallback timers, hover hold, full-page loader, back-forward safety, and the S13-S16 controlled editorial pipeline landed. Full-document navigation remains the production default; morph application stays guarded and off. |
| 3 | Trust Engine + Protected Flow Guard | **Complete baseline** | Deterministic trust signals and protected route guard landed. Needs live Woo/auth QA and wording review. |
| 4 | Trigger Engine + Surface Contract | **Partial complete** | Trigger contract exists for first-session, idle, safe link, scheduled game, protected flow. Needs admin trigger editor, permission triggers, metrics hooks. |
| 5 | Permission Journey Manager | **Complete v1 baseline** | Server decision/outcome endpoints, PWA thresholds, cooldowns, session ask limits, protected-flow guard, first-party visitor state, admin settings, and profile support landed. Push is now implemented through later batches; location/camera remain future. |
| 6 | Module Registry + Runtime Cleanup | **Complete baseline** | Module registry and frontend/manifest contracts landed. Runtime panel rendering still has hardcoded panel branches. |
| 7 | WP7 Native Adapter Layer | **Complete adapter shell** | Feature-detected adapter classes and manifest/admin exposure landed. Native execution remains incremental. |
| 8 | AI Admin Copilot | **Complete v1** | Admin-only deterministic Copilot report and dock panel landed. AI write workflows remain future. |
| 9 | Commerce Context Engine | **Complete read-only v1** | Woo/cart/product/category context and commerce transition copy landed; safe/no-blocking contract preserved. |
| 10 | Schema/GEO Engine | **Complete constrained v1** | Admin-governed JSON-LD and schema cache landed. Save-time registry snapshots now exist; wiring schema cache invalidation directly to snapshot version/hash remains later. |
| 11 | Admin UX + Appsite Profiles | **Partial complete** | JSON export/import contract landed. Admin IA reorganization remains. |
| 12 | Game & Reward Engine Hardening | **Partial/strong baseline** | Server reward REST endpoints, short-lived play tokens, daily attempt ledger, visitor/user/IP abuse limits, optional Woo percent coupons, coupon expiry, and admin safety toggles landed. Leaderboards, PhoneKey-linked high scores, and live coupon QA remain. |
| 13 | Interstice Attention Metrics | **Complete v1 baseline** | Aggregate REST event capture, rolling retention, admin summary cards, settings/profile support, frontend event pings, and same-site/rate guards landed. Revenue/session lift and permission conversion remain later. |
| 14 | Permission Journey Manager v1 | **Complete v1 baseline** | Earned PWA install ask flow landed with local earned counters, server journey state, cooldowns, admin thresholds, and no repeated asks during one browser session. |
| 15 | SecureTrack production hardening | **Complete hardening baseline** | Bundled SecureTrack no longer throws public boot fatals, has PHP-version guard, install lock, emergency monitor-only mode, admin pause/resume controls, and enforcement bypasses for WAF, rate limits, hardening gates, country-login denial, brute-force auto-blocks, tarpits, idle logout, and SecureTrack admin AJAX. |
| 16 | Production QA hardening | **Complete v1 baseline** | Admin-only production readiness report originally landed with a REST mirror; the REST mirror was removed in batch 26 so diagnostics stay admin-only. Profile update no longer clears email when omitted; password reset endpoint now has per-user/IP cooldown. Still requires debug-log/browser/Woo checkout smoke tests on the deployment host. |
| 17 | Live-test runtime fixes + cart DSA | **Complete baseline** | Profile/account/cart DSA links remember the previous Surface state for browser Back; transition hover hold now cancels pending hide timers and releases with grace; popup/mini-cart/offcanvas triggers are excluded from DSA navigation and common external UI events release stuck transitions; Appsite Home shows date/time and a scroll/swipe cue; cart dock screen now renders cart items with checkout-only action. |
| 18 | Woo cart controls + Bricks snippet absorption | **Complete baseline** | Draft checkout orders are filtered from DSA Orders; account subview Back became a Profile dock-return action; protected navigation rail is admin opt-in/off by default; DSA cart gained plus/minus quantity controls through `/dsa/v1/cart/item`; Kiwe > WooCommerce and Kiwe > Bricks pages landed. Old Bricks snippet was verified against Bricks 2.3.7 targets but not copied because its control-filter dependency is not a safe current contract. |
| 19 | Cart key reliability + Bricks mini-cart adapter | **Complete baseline** | Woo cart payloads now use the real `WC()->cart->get_cart()` array key, fixing "cart item not found" on DSA plus/minus updates. Cart REST updates now enforce sold-individually and stock max limits server-side, return refreshed totals/items, and expose stock badges. |
| 20 | Bricks-native controls correction | **Complete baseline** | Corrected ownership: stock badge thresholds/text moved to Kiwe > WooCommerce so DSA cart and Bricks native cart share commerce behavior. Kiwe > Bricks now only gates Bricks-native mini-cart and add-to-cart editor controls verified against Bricks 2.3.7 hooks. Bricks visual styling stays inside Bricks controls with low-specificity structural CSS; DSA active/hover colors no longer constrain Bricks native mini-cart styling. Added DSA `/cart/add` REST endpoint for the Bricks add-to-cart enhancer runtime. |
| 21 | Linked products + bestseller intelligence | **Complete baseline** | Absorbed the WC Advanced Cross-sells and Auto Bestseller snippets as native DSA/Woo services instead of pasted snippets. Kiwe > WooCommerce now controls linked-products service, DSA recommendation exposure, Woo product-editor category-to-cross-sell helper, bestseller category sync, analytics-table fallback, and sync-on-order cache refresh. Kiwe > Bricks now gates safe Bricks `product-upsells` presets verified against Bricks 2.3.7. Recommendations remain view-only and navigation-safe; no auto-add or partner ad behavior landed. |
| 22 | Frequently Bought Together cart rails | **Complete baseline** | Added the missing visible FBT rail from the Bricks mini-cart snippet. Kiwe > WooCommerce now controls FBT title/max/toggle. DSA cart renders a horizontal recommendation rail with explicit Add buttons. Bricks native mini-cart gets FBT section title/max/card styling controls under `cartDetails`, and renders before mini-cart buttons using DSA recommendations plus DSA REST add-to-cart. Source priority is Woo cart cross-sells, co-purchase lookup, then bestseller fallback when enabled. |
| 23 | Cart state ownership + Bricks FBT correction | **Complete baseline** | Corrected the snippet absorption boundary: FBT title/max/generation remain Kiwe > WooCommerce; Bricks mini-cart keeps only styling controls and a guidance note. Added `/dsa/v1/cart` so DSA can refresh from the server-authoritative Woo cart after native Woo fragments, Bricks cart changes, or DSA cart mutations. DSA now refreshes badge/cart state on Woo fragment events and stale-key failures, renders View instead of broken Add for non-simple/non-purchasable recommendations, and wraps Bricks mini-cart recommendation rendering in a frontend fail-open guard. |
| 23.1 | MU loader recovery hardening | **Complete recovery patch** | Debug log showed Hostinger was running a mixed/incomplete MU folder, not an FBT field fatal: missing `Trust_Service`, earlier missing `Plugin`, `PhoneKey_Bridge`, `Metrics_Controller`, and stale `Module_Registry`. The MU loader now fails open instead of rethrowing boot exceptions, shows an admin notice when possible, and the package bootstrap preflights required class files before booting. |
| 24 | Store Analytics + cart upsell absorption | **Complete baseline** | Absorbed the remaining snippet intelligence as DSA-native commerce infrastructure. Added Kiwe > Store Analytics with privacy-light cart tracking, product add rows, co-purchase analytics, linked product memory, bestseller status, and clear-events recovery. Added `dsa_store_events` table with PhoneKey-safe verified flags and salted customer hashes, not phones/emails/IPs. Added product-level Kiwe cart upsell fields, explicit DSA cart "Cart picks" rail, and server-validated discount metadata that applies through Woo fees only when the admin enables cart upsell discounts. Hardened DSA cart quantity updates with product/variation fallback metadata so stale Woo cart keys can recover instead of failing with "cart item not found." |
| 25 | Live cart diagnostics + offer flow correction | **Complete QA patch** | Hostinger console logs confirmed REST cart adds, fragment replacement, DSA rerendering, Bricks mini-cart updates, idle-home disabled state, and SecureTrack no longer auto-logging users out. Added temporary `DSA_DEBUG`/debug-log telemetry, fixed a DSA cart quantity error-handler `ReferenceError`, added one-time safety migrations for idle-home and SecureTrack auto-logout defaults, guarded SecureTrack idle logout at the enforcement point, and changed Add & Save so it adds the upsell product then immediately runs the validated discount-claim path. |
| 26 | SecureTrack semantic cleanup + Ponytail audit execution | **Complete architecture cleanup baseline** | SecureTrack now has explicit DB/admin-data service boundaries behind legacy `stp_*` wrappers; PhoneKey dependencies still use compatibility functions. Ponytail audit was executed selectively: dead fragment REST controller deleted, fragment settings hard-disabled, `/dsa/v1/readiness` removed, shared `Origin_Checker` added for Metrics/Rewards/Permissions REST callbacks, autoloader fallback cached with `glob()`, permission frontend contract trimmed to implemented PWA journey, and version bumped to `0.4.45`. Module Registry, Trust Service, and admin-only Production Readiness were intentionally retained because they are active architecture rails, not dead code. |
| 26.1 | Header transition capture + live cart synchronization + store funnel foundation | **Complete baseline** | Navigation click handling moved to capture phase so Bricks/header scripts cannot bypass safe-link transitions; popup/cart trigger detection was narrowed to preserve genuine header links. DSA cart now refreshes in stages after Woo, Bricks, and Woo Blocks cart events, watches native mini-cart DOM replacement as a fallback, and refreshes on `pageshow`. Store Analytics schema v2 adds salted visitor/contact hashes, visit/login/register/checkout/purchase events, order totals, funnel conversion rates, and one-hour abandoned-cart counts without storing raw IP, phone, or email. Asset cache-busting version is `0.4.46`. |
| 26.2 | DSA Checkout + Woo validation return bridge | **Complete controlled baseline** | Cart Checkout now opens a Woo-backed DSA form instead of storing Cart as a return panel. Checkout drafts stay server-side in the Woo session, use active Woo field definitions, and prefill the normal checkout page. Payment and Place order stay on the page. Woo classic validation errors reopen DSA with field-level corrections, then return corrected values to the page and request checkout recalculation. Admin can disable DSA Checkout under Kiwe > WooCommerce. Asset cache-busting version is `0.4.47`; live gateway/custom-field QA remains required. |
| 26.3 | Abandoned Cart + communications foundation | **Complete controlled baseline** | Kiwe > Abandoned Cart and Kiwe > Email now separate recovery analytics, cart identity, reminder eligibility, and channel configuration. Visitor/IP anchors remain salted; PhoneKey/Woo contact anchors can promote an abandoned session to a recoverable user without exposing raw identifiers in Store Analytics. Email is wired; SMS/WhatsApp remain provider-gated. |
| 26.4 | Responsive dock + AI notification queue | **Complete baseline** | Vertical and horizontal dock modes gained a distinct AI control, responsive popout placement, unread badge/ring state, persistent AI DSA history, returning-user greeting, and event-driven cart-offer guidance. The runtime publishes normalized `surface:ai:notifications` state for the first Interactivity API island. |
| 26.5 | Commerce notification lifecycle | **Complete controlled baseline** | Cart-match, Add & Save, pair-complete, discount-ready, and discount-applied messages now move through actionable and historical states instead of one stale popup. Cart totals expose discount and total-after-discount confirmation. Live browser and Woo fee/session QA remains required because these messages explain money-changing server state. |
| 26.6 | PWA + browser-notification adoption | **Complete controlled baseline** | Added dynamic manifest, root-scoped service worker, site icon metadata, Android prompt, iOS Add to Home Screen guidance, Home DSA platform badges, explicit `data-kiwe-notifications` triggers, permission state, local confirmation notification, and Store Analytics schema v3 adoption reporting by unique salted visitor/IP hash with later WordPress/PhoneKey user resolution. Version is `0.4.52`; offline caching and remote Web Push delivery are intentionally not included. |
| 26.7 | Android Journey One + App ownership | **Complete controlled baseline** | Moved adoption analytics from Store Analytics to Kiwe > App so non-commerce Appsites receive the same reporting; added developer attribute reference, shared active trust badges on Home, recognizable platform glyphs, a locked one-OK Android AI primer, native prompt outcome tracking, actionable AI retry, persistent install confirmation, and a privacy-safe offline shell. Version is `0.4.53`; iOS journey refinement and remote push delivery remain separate. |
| 26.8 | Browser-honest install intent + readiness diagnosis | **Complete controlled baseline** | Install badge taps now dismiss Home automatically and count as intent; native prompt acceptance has its own metric. Kiwe waits briefly for a late `beforeinstallprompt`, never promises a prompt it did not receive, replaces the stale OK loop with browser/site-specific AI guidance, exposes `window.DSA.inspectAppInstall()`, and warns admins when a reliable 512px WordPress Site Icon is missing. Version is `0.4.54`; Android-device matrix QA and iOS journey refinement remain. |
| 26.9 | Shared Surface viewport + dock toggle + design adapter rail | **Complete controlled baseline** | The active dock module now toggles closed from the same icon; all Surface modes share background-scroll ownership; Home, AI, Links, and other module panels use one safe-area-aware viewport scroll contract with vertical-dock side reservation instead of false bottom space. Home hero wrapping no longer depends on viewport-scaled type. Seam/Bricks prototypes were audited and preserved as non-executable references; direct option replacement and duplicate Woo runtime were rejected. A future namespaced design adapter is documented instead. Version is `0.4.55`; real-device visual QA remains. |
| 26.10 | Responsive dock + compact AI/Links/Cart polish | **Complete controlled baseline** | Added independent responsive dock placement settings; compact AI inbox/popouts with icon-origin squeeze motion and a static chat composer; animated Share/social dock identity with branded X and other social marks; Woo-gated Shop plus DSA Cart action on Links; immediate discount confirmation; compact mobile FBT plus controls; and one-swipe Home dismissal. Version is `0.4.56`; real-device placement/motion QA remains. |
| 26.11 | AI lifecycle + cart/trust reconciliation | **Complete controlled baseline** | Moved AI popouts to the viewport root so first paint no longer inherits Surface transforms; added permanent X/swipe dismissal only for completed notifications; added an open-by-default horizontal bell tray that collapses on chat engagement; anchored chat above the dock; unified Home/Links trust states; reconciled saved pair/discount history against the live Woo cart; and refreshed Woo totals once before discount payload serialization. Version is `0.4.57`; real-device motion and live discount-fee QA remain. |
| 26.12 | Preference-first notification journey | **Complete controlled foundation** | Added Personalize your Appsite with commerce/editorial topics, product/post categories, provider-aware App/Email/WhatsApp/SMS channels, anonymous-to-PhoneKey preference continuity, trust badges, unavailable-product Notify me buttons, passive post-checkout order-status AI entry, and an iOS-specific Home Screen-to-permission continuation. Preference saves now appear in Kiwe App adoption analytics. Version is `0.4.58`; remote push delivery, stock/price dispatch jobs, and live iOS/Android device QA remain. |
| 26.13 | App identity + notification audience operations | **Complete controlled foundation** | Added installed-app user vs anonymous analytics, an app-specific PhoneKey identifier/verification policy that may tighten but never weaken role policy, first-launch PhoneKey welcome for anonymous or unverified standalone visitors, and a required AI notification-setup continuation for authenticated app users. Notification choices now persist in a salted device ledger and promote to PhoneKey/WordPress identity without storing raw IP/contact data. Kiwe > App gained channel/topic/scope analytics and provider-backed Email/WhatsApp/SMS campaign sends to opted-in registered users, capped at 100 per action. Browser audience is counted but its send button remains disabled until PushSubscription + VAPID/provider delivery exists. Version is `0.4.59`; live provider/app-device QA remains required. |
| 26.14 | Native browser push + tactile interaction feedback | **Complete controlled transport baseline** | Added encrypted PushSubscription storage, site-specific VAPID key generation, RFC 8291 `aes128gcm` payload encryption, known-provider endpoint allowlisting, stale-subscription retirement, explicit unsubscribe, and Kiwe > App browser broadcasts capped at 100 devices per action. The service worker uses OS notification sound/vibration and active pages receive a subtle Kiwe chime; AI notifications, product adds, and quantity controls gained Web Audio plus supported-device vibration feedback. Notification choices now use neutral glass/hover states, Email and App remain selectable, and platform badges start their real journeys. Version is `0.4.60`; live Chrome/Firefox/Safari push delivery and host OpenSSL QA remain required. |
| 26.15 | Owner alerts + strict standalone onboarding | **Complete controlled baseline** | Added compact top-of-viewport mobile AI toasts, topic-targeted owner pushes for new Woo orders and comments, a capped private owner inbox that restores offline events into AI history, and privacy-safe lock-screen copy. Standalone arbitration now waits for server preferences and enforces PhoneKey login, verification, then notification setup. Required gates cannot close through Escape, dock toggles, background taps, or an X; owner order/comment choices are preselected, while Allow and Deny are both respected outcomes. Version is `0.4.61`; live PWA login, OpenSSL, cron, order, comment, and device delivery QA remain required. |
| 26.16 | PHP/REST claim verification + safe hardening | **Complete safe batch** | Verified the external audit claim by claim. Cart REST routes now use shared same-site permission callbacks and Fetch Metadata rejects explicit cross-site requests; rewards adds a controller flood guard above its existing token/ledger abuse model. Settings and manifest resolution are memoized per request, and an autoloaded migration-version sentinel removes two recurring legacy option reads. Kept package preflight fail-open checks, normal Woo meta writes, transient semantics, and explicit REST registration. Version is `0.4.62`; live cart, rewards, REST/proxy, and upgrade-path QA remain required. |
| 26.17 | PHP 8.2 medium lane + Woo ownership | **Complete controlled first pass** | Formally raised the MU package minimum to PHP 8.2, added selected promoted constructors and typed properties, introduced an enum/readonly Protected Flow boundary while preserving its public array contract, and moved supported account address persistence through `WC_Customer`. Added opt-in privacy-safe timing for Settings, manifest, shared rate-limit transients, and Schema/GEO cache behavior. Kept explicit REST registration, positional calls into external APIs, synchronous WordPress HTTP, transient semantics, and live package preflight. Version is `0.4.63`; PHP/Woo/Bricks matrix, address-extension compatibility, no-cache-vs-Redis traces, and atomic deployment design remain required. |
| 26.18 | Shared commerce controls + Bricks identity tags | **Superseded by 26.31 for dynamic tags** | Added empty-cart first-item confetti with reduced-motion respect; global Woo add-to-cart modes backed by the canonical DSA cart mutation queue; plus-only FBT actions; and one stock-eligibility rule shared by DSA and Bricks rails. The first Bricks dynamic-tag pass exposed too much: duplicated site title/tagline and current customer billing/shipping values. Batch 26.31 corrected this to public store identity/settings only. WordPress Customizer Site Identity receives Custom Logo support when the active theme does not declare it. Version was `0.4.64`; live Woo loop/theme coverage, Bricks editor media-tag rendering, variation products, and real-device cart celebration QA remain required. |
| 26.19 | APEX safe-first route capability observer | **Complete safe baseline** | Added a server-side route capability policy and a passive frontend route classifier. The runtime can now classify clicked links as local UI, external, unsafe mutation, asset, same-page anchor, protected full document, excluded full document, or safe full document, then publish `surface:route:capability` without changing navigation. This is the first safe APEX batch: observe and centralize route truth before View Transitions, Turbo/HTMX experiments, or fragment envelopes. Version is `0.4.65`; live route classification QA remains required. |
| 26.20 | Surface lifecycle event contract | **Complete safe baseline** | Added a versioned DSA-owned panel lifecycle around open, scheduled game mount, in-place panel replacement, suspend, resume, update, and destroy events. `window.DSA.surfaceLifecycle.current` and `surface:module:*` events now distinguish initial render/mount from same-panel updates such as Links editor/view, cart refresh, checkout rerender, and profile subviews. The contract is passive and does not change visitor behavior. Version is `0.4.66`; lifecycle listener QA remains required. |
| 26.21 | Diagnostics quieting and observability | **Complete safe baseline** | Moved frontend diagnostic exposure and Surface/Bricks console logging behind an explicit Kiwe > Surface > Diagnostics switch. `WP_DEBUG` is now reported as context but does not automatically produce public console logs. Browser-only overrides remain available through `?dsa_debug=1` or localStorage `DSA_DEBUG=1`. Version is `0.4.67`; admin setting/profile import QA remains required. |
| 26.22 | Design-token schema adapter | **Complete safe baseline** | Added a versioned read-only `kiwe.surface` token contract for current DSA CSS variables and fallbacks. The contract is exposed in `DSA_DATA.designTokens`, `/dsa/v1/manifest`, `window.DSA.designTokens`, and Kiwe > Surface without writing Bricks variables, site styles, or universal design-system files. Version is `0.4.68`; builder inspection QA remains required. |
| 26.23 | SEAM token import/export adapter | **Superseded by 26.24** | First pass added a token admin page and Bricks Variables JSON export, but incorrectly treated SEAM/project tokens as importable Kiwe state and allowed optional frontend emission. This was rejected because Kiwe must ship its own universal token vocabulary and DSA must not be restyled by imported page-builder tokens. |
| 26.24 | Kiwe universal token export correction | **Complete safe foundation** | Rebuilt `Design\Seam_Token_Service` around built-in Kiwe universal tokens informed by SEAM UTR concepts. Kiwe > Tokens now provides reference cards, slider/color aids, one-click additive export into Bricks global variables/categories, and a Bricks Variables JSON fallback. Batch 26.28 later added the separate Bricks color-palette export required by Bricks' storage model. `/dsa/v1/manifest`, `DSA_DATA`, and `window.DSA` expose `kiweTokens`; `seamTokens` remains only as a temporary compatibility alias. No import, no DSA frontend token emission, and no Appsite profile token payload. Direct Bricks writes are bounded to `kiwe-*` variables and `Kiwe` categories, leave existing non-Kiwe Bricks tokens untouched, and save `dsa_bricks_tokens_backup` before writing. Version is `0.4.70`; live Bricks round-trip QA remains required. |
| 26.25 | Universal token completion + runtime profiler expansion | **Complete safe APEX batch** | Filled the Kiwe universal-token gaps: micro type, content widths, grid minimum column, section/stack/grid gaps, input padding, and the lower z-index ladder. Added a real `Kiwe Layout` Bricks category while keeping scene coefficients out of Bricks variables. Added admin-controlled observe-only runtime profiling that records route context, service registration time, existing cache/transient samples, request duration, and optional `SAVEQUERIES` totals without changing behavior. Version is `0.4.71`; live debug-log/profile review and Bricks token export QA remain required. |
| 26.26 | Asset ownership manifest observer | **Complete safe APEX batch** | Added `Diagnostics\Asset_Manifest_Service` behind Kiwe > Surface > Diagnostics. When enabled, it writes frontend/admin asset manifests to `debug.log` with active script/style handles, registered counts, dependencies, owner classification, local/external/inline type, placement, inline byte counts, local file size, and privacy-light route context. It does not dequeue, reorder, dedupe, inline, extract critical CSS, or change caching. Version is `0.4.72`; live theme/plugin asset review remains required. |
| 26.27 | Registry snapshot baseline | **Complete safe APEX batch** | Added save-time `_dsa_registry_snapshot` persistence to `Element_Registry`, including WordPress content fragments, known Bricks data keys, source summaries, modified time, and content hash metadata. Runtime registry reads still prefer live Bricks/front-end observations and use snapshots only as fallback/metadata. Corrected `kiwe-cluster-gap` into the Layout export classifier. Version is `0.4.73`; live Bricks save/regeneration and schema-cache integration QA remain required. |
| 26.28 | Native AI island + Bricks color palette export | **Complete safe APEX batch** | Added the first native-island bridge for AI notification counts: DSA seeds `wp_interactivity_state( 'kiwe/ai' )` when WordPress exposes the Interactivity API and keeps a DOM-event fallback store through `assets/js/ai-island.js`. Corrected Bricks export so Kiwe color tokens also land in a separate additive `Kiwe Universal` Bricks color palette while non-color tokens remain additive `kiwe-*` variables. Version is `0.4.74`; live Bricks palette round-trip and native-island QA remain required. |
| 26.29 | Native app island + full-size Kiwe logo identity | **Superseded by 26.31 for logo variants** | Added the app-adoption display island: DSA seeds `wp_interactivity_state( 'kiwe/app' )` when available and mirrors `surface:app:adoption` through `assets/js/app-island.js` without initiating install or notification prompts. The initial logo model added normal, light, and dark logo fields; batch 26.31 corrected this to normal plus inverse only, with legacy light/dark read as inverse fallbacks during upgrades. PWA keeps the square WordPress Site Icon contract. Version was `0.4.75`; live Settings/Customizer media QA and native app-island QA remain required. |
| 26.30 | Read-only native data island + Kiwe site binding | **Complete safe APEX batch** | Added `assets/js/data-island.js` and `kiwe/data` state for public/display-only site, trust, profile, and cart summaries. Added an `init`-registered `kiwe/site` Block Binding source for public site identity fields only. This does not mutate cart, account, checkout, PhoneKey, SecureTrack, or discounts. Version is `0.4.76`; live WordPress binding compatibility and cache/private-state QA remain required. |
| 26.31 | Bricks store identity correction | **Complete corrective batch** | Corrected the Kiwe Bricks dynamic-tag surface to avoid duplicating Bricks native Site title/tagline and to avoid exposing visitor billing/shipping data in public templates. Kiwe now exposes full-size logo URL, inverse logo URL, WooCommerce store address line 1/2, city, country, state, postcode, selling locations, shipping locations, and Kiwe store phone/email from Settings > General. Logo tags are context-aware: text/link fields receive URLs while Bricks image/media contexts receive image-source arrays. Frontend/native data now uses `logoInverse`; legacy light/dark logo options remain read-only fallbacks. Version is `0.4.77`; live Bricks dynamic-tag rendering QA remains required. |
| 26.32 | Analytics rename + WordPress menu source | **Complete safe corrective batch** | Renamed Kiwe > Store Analytics to Kiwe > Analytics and made the default visitor/identity/funnel view available without WooCommerce; Woo-linked tabs are now conditional. Added an aggregated admin-only visitor summary notification using privacy-light Analytics counts for today versus yesterday, linking to Kiwe > Analytics instead of piling individual visitor alerts. Existing owner notifications remain new Woo order -> Woo order edit and new comment/review -> WordPress comment edit. Added a DSA menu setting to use a saved WordPress nav menu from Appearance/Customizer and Bricks dynamic tags for saved menus because Bricks 2.3.7 exposes menu elements but not menu dynamic tags. Version is `0.4.78`; live menu selection, Bricks tag rendering, and owner inbox QA remain required. |
| 26.33 | PhoneKey TOTP safety + live visitor alert + Live Search decision | **Complete safe corrective batch** | Corrected privileged PhoneKey policy so high-role accounts default to passkey-first with optional TOTP, and authenticator code screens are shown only when a verified authenticator exists. Existing admin password step-up before passkey enrollment remains. Added internal visit-recorded events from Analytics and a private latest-visitor owner AI alert that replaces prior live visitor notices while the owner has Kiwe open; the daily visitor summary remains separate. Approved DSA Live Search as a future zero-weight dock module rather than a global script: lazy ES module, native Popover/Anchor progressive enhancement, AbortController, adaptive debounce, ghost results, server-side highlighting, and cache-aware REST. Version is `0.4.79`; live admin login, owner inbox polling, and search implementation QA remain required. |
| 26.34 | S11 Live Search + shared product weight | **Complete additive batch** | Added Search as a default-on registered dock module with a zero-query recent view and product-first results when WooCommerce is active. Search computation lives in `assets/js/search.js` and is dynamically imported only on first Search open; the ordinary boot payload carries only endpoint/module metadata. Added a public-only, same-site, rate-limited REST service with ID-only product/post queries, role-aware object/transient caching, mutation-version invalidation, sanitized server highlighting, and Woo visibility checks. Verified Bricks 2.3.7 has no product-weight dynamic tag, then exposed `{woo_product_weight}` in Kiwe without duplicating future native tags. DSA cart items, FBT cards, and Search products now use Woo's formatted weight. Version is `0.4.80`; live relevance, cache, Popover fallback, keyboard, mobile, Woo-disabled, and Bricks loop QA remain required. |
| 26.35 | Saved module + visitor notification control | **Complete additive/corrective batch** | Added a default-on Saved dock module that becomes Bookmarks without WooCommerce and combined Wishlist/Bookmarks with WooCommerce. Added builder-neutral `data-kiwe-save` attributes, local-first anonymous state, signed-in user-meta synchronization, same-site/rate-limited REST mutation, analytics, AI confirmation, and the animated bookmark/heart dock glyph. Corrected owner visitor intelligence to exclude staff, report arrival/revisit/navigation/product/cart/checkout/save/identity contexts, replace the prior live visitor card, and suppress identical idle events. Added an admin-configurable 2-15 second ordinary AI popup duration with a 3.2-second default while preserving locked journeys. Version is `0.4.81`; live Bricks loop, Woo/no-Woo, anonymous/login, multi-device, admin inbox, and responsive visual QA remain required. |
| 26.36 | Bricks-aligned color mode + dock/cart viewport corrections | **Complete additive/corrective batch** | Removed SecureTrack from the dock and Dock Icons configuration while retaining the internal Secure DSA module and full Kiwe > Secure backend. Added a configurable sun/moon dock action with no panel; it shares Bricks 2.3.7's `data-brx-theme` and `brx_mode` contract, observes Bricks' native toggle, mirrors `data-kiwe-theme`, persists across navigation, switches inverse logos, and adds dark Surface glass styles. Mobile horizontal docks now flex their final icon set inside safe-area viewport bounds. The populated Cart checkout action is sticky above the dock like the AI composer. Version is `0.4.82`; live Bricks light/dark palette, non-Bricks, mobile-width, safe-area, and long-cart QA remain required. |
| 26.37 | Responsive Geometry Engine | **Complete shared-runtime baseline** | Superseded the component-specific horizontal-mobile sizing patch with versioned geometry tokens and one enabled-item-aware axis solver for both horizontal and vertical docks. `Surface_Renderer` emits main/AI counts; runtime reconciles the live dock and visual viewport, safe areas, zoomed CSS viewport, orientation, and WordPress admin-bar height. Dock controls/icons/badges/gaps/padding, cluster footprint, panel reserve, sticky Cart checkout, and AI composer now share resolved variables. The solver passed 44,184 generated fit cases plus explicit 320px, desktop, short-landscape, and 32/46px admin-bar cases with balanced CSS and JS syntax checks. Version is `0.4.83`; real iOS/Android safe-area, browser zoom, and live admin-bar visual QA remain required. |
| 26.38 | P1 commerce code hardening + active dock anchor | **Code complete; live P1 matrix pending** | Preserved the dock's measured resting position when overlay mode opens. Replaced Kiwe's negative-fee pair discounts with sorted-pair virtual Woo coupons using native `percent` or `fixed_cart` calculation, eligible-item/quantity filtering, overlap rejection, session restoration, and percent caps. Reverse A+B/B+A claims resolve to one coupon. Discount summaries now include coupon tax. Add & Save remains one serialized add-then-claim transaction, and Woo fragment events now pass a harmless real proxy button required by Bricks 2.3.7 instead of an empty jQuery collection. Analytics records checkout creation separately, counts purchases only at paid/processing/completed authority hooks, records deduplicated refunds, and reports net revenue. Static checks passed, JavaScript parses, structures balance, and 100,000 generated pair cases passed key/scope/cap/quantity/overlap invariants. Version is `0.4.84`; live WordPress 7/PHP 8.2/Woo/Bricks, HPOS, tax, gateway, partial/full refund, and browser tests remain mandatory. |
| 26.39 | P2 PhoneKey code hardening + canonical token/runtime cleanup | **Code complete; live P2 matrix pending** | Made Kiwe universal tokens authoritative for Surface runtime and Bricks export, retained `dsa-*` as compatibility aliases, removed the duplicate Surface token table, and moved developer attributes to Kiwe > Attributes. Added mobile horizontal center/bottom placement and enriched Saved product/post records. PhoneKey now requires same-site browser mutation context, fresh IP-bound admin-password proof, user-verification flags for privileged WebAuthn, strongest-role arbitration, and assurance invalidation on elevation/revocation. OTP rows are tied to their issuing flow, OTP/TOTP/backup attempts are throttled, target limits use persisted challenge history, and `wp_mail()` handoff is reported without claiming delivery. Version is `0.4.85`; live SMTP/inbox, Android/iOS/macOS/Windows passkey, recovery, proxy, elevation, and SecureTrack identity tests remain mandatory. |
| 26.40 | P3 SecureTrack/push code hardening + Saved intent correction | **Code complete; live P3 matrix pending** | Restored explicit `wishlist`/`bookmark` attributes end to end, retired the malformed local mirror generation, and immediately merges clean server enrichment. Added opt-in host proxy CIDRs, trusted-chain IP provenance, ignored-header diagnostics, and recovery readiness without broad forwarded-header trust. Push validates OpenSSL P-256/AES-GCM capabilities, renews changed subscriptions, schedules daily stale cleanup, reports transport/cron history, and queues broadcasts in five-device cron batches. Version is `0.4.86`; live enforcement recovery, Hostinger/CDN IP, real cron, outbound network, Chrome/Firefox/Safari push, order/comment, and stale-subscription tests remain mandatory. |
| 26.41 | P4 runtime/cache proof code + Surface history/viewport correction | **Code complete; paired host traces and real-device proof pending** | Mobile Surface activation no longer preserves a stale pixel dock anchor; shared responsive geometry remains authoritative while panels scroll and browser chrome resizes. Every closeable dock/game Surface claims one synthetic same-URL history entry, so browser back or the mobile edge gesture closes the foreground Surface first; required standalone gates do not claim a close entry. Runtime profiles identify cache backend, drop-in state, query count, peak memory, request duration, marks, and samples without keys or visitor data. Production Readiness treats no persistent cache as supported, warns when profiling is left on, and surfaces recent Kiwe-owned MU fatals recorded by the fail-open loader. Version is `0.4.87`. |
| 26.42 | S12 editorial View Transitions + owner/Saved administration | **Code complete; browser and scale proof pending** | Hid the WordPress frontend admin bar and added an admin-only, toggleable Dashboard utility beneath Menu DSA. Added registered-user Saved analytics with object popularity, Wishlist/Bookmark totals, cached user collection drill-down, and an anonymous aggregate-only boundary. Enabled browser-native cross-document transitions only for approved editorial links when both source and destination are editorial, with short-lived intent verification, protected/Woo/product exclusions, reduced-motion cancellation, and ordinary full-document WordPress loading. Version is `0.4.88`; browser fallback, bfcache, eligibility, reduced-motion, and large-user-meta profiling remain live QA gates. |
| 26.43 | S13 editorial fragment envelope | **Contract complete; no morphing** | Added a same-site, rate-limited, private/no-store envelope endpoint for published password-free posts/pages. It emits rendered editorial content, title/description/body classes, canonical route identity, registry snapshot metadata, observed script/style dependencies, content hash, cache policy, blocker list, and deterministic full-document fallback. Bricks 2.3.7 uses its verified Helpers/Frontend renderer; ordinary content uses `the_content`. Scripts and base tags are stripped, interactive blockers are declared, observed assets remain explicitly incomplete, and `surface.js` does not fetch or apply envelopes. Version is `0.4.89`; live Bricks/WordPress rendering and S14 reconciliation remain pending. |
| 26.44 | S14 observe-only document reconciliation | **Planner complete; no DOM application** | Added a lazy ES module exposed through `window.DSA.inspectEditorialReconciliation(url)`. It fetches the real destination HTML and S13 envelope with same-origin credentials, parses without executing it, and diffs title, canonical, metas, language/direction, body classes with Kiwe runtime preservation, external scripts/styles, inline style/script/JSON-LD hashes, and content roots. It emits proposed history, focus, scroll, anchor, and live-region operations plus blockers and full-document fallback. It is absent from ordinary boot cost until explicitly invoked, never mutates the document, and reports `applyEnabled: false`/`morphReady: false`. Version is `0.4.90`; live CSP, Bricks, SEO-plugin head, cache, and large-document proof remain. |
| 26.45 | S15 controlled static-editorial morph lifecycle | **Code complete; off by default; S16 proof pending** | Added a separately gated apply path for S14 plans. The runtime intercepts only route-approved editorial links, lazy-loads the planner, and commits only blocker-free static WordPress content. It reconciles document head semantics, body classes, content, registry state, URL history, focus, scrolling, anchors, and live-region feedback through a same-document View Transition when supported. Any uncertainty falls back to normal full navigation; back/forward reloads the authoritative WordPress document. Bricks is deliberately blocked until S16 proves its lifecycle. Version is `0.4.91`. |
| 26.46 | S16 fragment-safety matrix and invariant recovery | **Harness complete; live compatibility proof pending** | Added scenario classification, cache/CSP response evidence, post-morph title/canonical/content/shell/history invariants, and automatic full-document recovery. Added a non-mutating matrix runner for discovered editorial links and intentional mutation/admin/checkout failures, plus bounded session-only history, bfcache, morph, and fallback evidence. No telemetry leaves the browser and no Bricks authority was added. Version is `0.4.92`; real Bricks, comments, search, archives, forms, embeds, browser history, cache-plugin, accessibility, analytics, SEO-head, and intentional-failure runs remain mandatory. |
| 26.47 | S17 offline public-editorial pilot | **Code complete; off by default; live offline proof pending** | Added a dedicated public WordPress editorial contract rather than caching personalized WordPress HTML. It uses stored post/page content, removes shortcodes and interactive/executable markup, rejects Bricks, and emits bounded same-origin attachment hints. The network-first worker caches only responses carrying the server's public-editorial policy, limits and versions document/media caches, renders script-free CSP-locked offline reading output, and leaves all protected, transactional, builder, query-bearing, and unknown routes network-only. Disabling PWA/offline mode or upgrading Kiwe retires stale caches. Version is `0.4.93`; online refresh, airplane-mode replay, media, eviction, logout/cart/account isolation, iOS/Chromium, and worker-upgrade QA remain mandatory. |
| 26.48 | S18 content-addressed asset delivery pilot | **Code complete; off by default; generated-delivery proof pending** | Added a cron-backed build service for Kiwe-owned `surface.css`. It writes atomically, requires source/artifact SHA-256 equality, publishes a version/theme-fingerprinted manifest, retains three artifacts, inventories bounded same-origin WOFF2/site-identity media candidates, and exposes separate pilot/apply/hint controls with build status. Applied delivery uses a validated generated URL and build ID; every stale, absent, disabled, or failed state falls back to packaged CSS. No request-time extraction, regex minification, Bricks/theme rewriting, dedupe, dequeue, or media conversion was introduced. Version is `0.4.94`; cron, uploads permissions, checksum, fallback, cache headers, theme/version invalidation, visual parity, and performance proof remain mandatory. |
| 26.49 | S19 APEX acceptance and edge contract | **Approved architecture complete; production proof remains open** | Added `Diagnostics\Apex_Acceptance_Service`, a public noindex/cacheable `/dsa/v1/apex-profile`, manifest discovery metadata, and `X-Kiwe-Runtime-Profile`, `X-Kiwe-Document-Profile`, and `X-Kiwe-Edge-Policy` response classification. The public profile exposes contracts without PHP/WP/host internals; admin receives runtime versions, measured packaged asset bytes, accessibility commitments, S16-S18 proof state, and production-gate requirements. HTML remains origin-required and no edge worker/shared HTML cache was enabled. Version is `0.4.95`; this closes approved APEX architecture, not live production certification. |
| 26.50 | Haptic contract + independent Surface materials | **Complete configurable baseline** | Replaced unconditional haptic/chime behavior with `Kiwe > Haptic`: vibration and sound can be independently enabled, sound offers soft/bright/pop/bell profiles, events cover buttons (including Bricks), quantity controls, AI/Push notifications, and Surface-closing back gestures, and targeting supports website, installed Appsite, or both. All settings default on for testing and remain browser/device-policy bounded. Dock appearance now independently supports rounded-pill or square shape and glass or solid material; full DSA screens separately support glass or solid material. Solid mode is white in light mode and dark-surface aware. Version is `0.4.96`; live iOS/Android vibration, browser audio-policy, Bricks button, back-gesture, material-combination, and dark-mode QA remain. |
| 26.51 | Directional Surface motion + dock context rail | **Complete shared-geometry baseline** | Added left/right/top/bottom DSA screen entry and matching exit motion, with bottom as default and reduced-motion support. Quantity feedback now fires at pointer contact, explicitly recognizes DSA cart controls, avoids click duplication, and uses a stronger vibration pulse. A measured dock-owned context rail now receives the authoritative Cart checkout action, AI composer, and simple-product Woo cart form; its size feeds the Responsive Geometry Engine so scrollable Surface content ends above a subtle divider instead of flowing behind controls. Home remains outside this visible-dock rail. Version is `0.4.97`; live Android haptic, iOS non-vibration fallback, Bricks simple-product forms, variable products, all dock orientations, safe areas, and browser zoom remain QA gates. |
| 26.52 | Unified Surface UI Contract v1 | **Complete architecture and runtime baseline** | Replaced mixed root/panel scrolling with one visual viewport and one bounded module-body scroller. Responsive Geometry now exposes separate block, inline, dock-only, and context reserves. The dock context rail is fixed below module content for desktop vertical docks and above horizontal docks; it shares dock material/shape, never stacks, and safely relocates authoritative Cart, Checkout, AI, Profile, Links trust, Menu admin, and simple-product controls through reversible placeholders. Mobile Links/Profile/Cart density was tightened, dark Menu navigation restored, Search gained available-family filter pills, and transition messages reuse trust badges. Added the marketplace-facing `docs/DSA-UI-CONTRACT.md` and runtime marker `data-dsa-ui-contract="1"`. Version is `0.4.98`; live cross-browser visual, Bricks template, accessibility, safe-area, and all material/orientation combinations remain QA gates. |
| 26.53 | Context geometry, Search families, and Profile status refinement | **Complete UI-contract refinement** | Horizontal context rails now match the measured dock-cluster width; desktop vertical rails match the active panel and sit directly beneath it. Context content is non-scrolling and uses `kiwe-type-micro`, SVG actions, and shrinkable trust badges. Explicit panel-height clipping stops module content at the moving divider. Search removed the redundant Recent label/capsule, gained selectable Products, Posts, and privacy-safe published Authors families, and prevents filter interactions from dismissing the Surface. Profile removed its duplicate cart and Orders destination, replacing them with live Recent Order cards and status while retaining Downloads, Addresses, Password, and icon-only Logout in context. Search price output now bypasses verbose theme price filters. Version is `0.4.99`; live geometry, narrow-label, author archive, authenticated order, and dark-mode QA remain. |
| 26.54 | Unified SVG dock and Profile action state | **Complete UI-contract refinement** | Every enabled module now renders inside one Responsive Geometry Engine-owned toolbar. AI remains the sole enlarged protruding control while preserving its wobble, badge, lifecycle, and behavior. Utility icons come from a compact locally served official Lucide sprite with the upstream ISC license retained; social-brand cycling remains an intentional Links exception. Profile context keeps readable Downloads, Addresses, and Password labels with icons, marks missing address setup, marks unseen downloads locally per account, and adds View all beneath Recent Orders. Version is `0.5.0`; live 320px, vertical-dock, dark-mode, download-state, address-state, and assistive-technology QA remain. |
| 26.55 | Surface UI Contract v2 and container-led geometry | **Complete architecture correction; live matrix pending** | Removed the shell's split-brain responsive authority. Geometry now publishes `wide`, `compact`, or `narrow` from usable inline Surface space and `dense` or `comfortable` from usable block space after safe areas, dock orientation, context reserve, browser chrome, zoom, and admin-bar reserve. Built-in Profile, Cart, Menu, and Links layouts consume those states through final contract rules instead of adding more component breakpoints. Context controls can declare generic reversible `data-dsa-context-slot` ownership with `dock` or intrinsic `content` width, creating the stable presentation-only boundary required by marketplace screens. Profile context flex allocation and Menu Dashboard contrast were corrected at the contract level. Version is `0.5.1`; live viewport/zoom/orientation/material/theme and third-party fixture validation remain mandatory. |
| 26.56 | UI-1 harness, coordinate-space correction, and PAYLOAD-1A | **Harness baseline complete; payload split in progress** | Added an out-of-package Playwright harness with canonical Menu, Profile, Cart, Links, and Search fixtures across 70 viewport/theme/orientation combinations. It validates geometry, dock/context alignment, context placement, accessible names, form/media names, duplicate IDs, one-dialog ownership, target size, and emits screenshots plus JSON evidence. The harness exposed transformed-ancestor coordinate leakage and a duplicate vertical reserve: context is now a Surface sibling of the dock cluster, horizontal rails anchor to the rendered dock rectangle, viewport arbitration uses the smallest trustworthy measurement, context offsets include viewport gutter, and vertical widths are no longer dock-reserved twice. All 70 variants pass. PAYLOAD-1A moved Dinosaur Jump and Star Shooter simulation/drawing into a first-use ES module; shell lifecycle, rewards, triggers, and game orchestration remain authoritative. Version is `0.5.2`; real devices, zoom, safe-area emulation, screen readers, and PAYLOAD-1B-1E remain. |
| 26.57 | Page-aware Search, native Bricks query bridge, and PAYLOAD-1B start | **Complete additive Search batch; payload split remains in progress** | Search context now derives from authoritative WordPress/Woo route state: Shop/product archives select Products, the Posts page and post archives select Posts, and author archives select Authors. The selected family is sent to REST so unneeded product/post/author queries do not run, and scope participates in cache identity. On Bricks 2.3.7 pages, DSA forwards the term through Bricks' own Filter Search contract and lets Bricks own AJAX replacement. The later explicit editor control supersedes legacy `data-dsa-live-search` markup; the attribute remains compatibility-only. PAYLOAD-1B now lazy-loads authenticated Profile markup through the shared presentation-module loader; PhoneKey gating, account mutations, lifecycle, and geometry remain resident. Version was `0.5.3`; live Bricks loop, archive-template, cache, and authenticated Profile hydration QA remain required. |
| 26.58 | Governed Search, Filter - Search persistence, alphabet discovery, quick-add, and analytics | **Complete additive subsystem batch** | Added `Kiwe > Search` for family availability, context awareness, alphabet discovery, product quick-add, Bricks synchronization, and result limits. Search scope now lives in module memory and remains selected across focus and panel reopen. The Bricks adapter calls the native Filter - Search/query utilities directly and suppresses only their redundant `pushState`, so page results remain after the DSA panel closes while DSA's synthetic back entry still closes the Surface. Result cards remain cards on mobile; simple purchasable products use the canonical serialized cart mutation and Woo fragment reconciliation pipeline. A progressive, availability-backed alphabet index drills from `A` to existing `Aa`/`Ab` branches without empty choices. Search terms and alphabet paths are recorded in the privacy-light Analytics ledger with five-minute visitor/term deduplication and shown under `Kiwe > Analytics > Search`. Version is `0.5.4`; live Bricks filter, large-catalog query-plan, multilingual alphabet, cart, and analytics retention QA remain required. |
| 26.59 | Search presentation and Bricks state correction | **Complete corrective batch** | Removed the Search-only top-layer popover because it covered contextual pills/alphabet and created a second list-like geometry. Product results now use explicit narrow-screen cards and default-on quick-add unless administratively disabled. The Bricks adapter now supports both ordinary AJAX Filter - Search and Live search query instances, and reconciles the active DSA term after Bricks observes DSA's synthetic history close. Search response caches include the plugin version so an upgrade cannot temporarily reuse payloads without alphabet/addable fields. The conformance harness now asserts one selected context, visible alphabet, card wrappers, and quick-add actions. Version is `0.5.5`; live Hostinger proof against the supplied `tgbzzq` query remains required. |
| 26.60 | Reusable Bricks Search bridge and governed cache reset | **Complete corrective batch** | Added a Bricks 2.3.7 Filter Search control, **Use as DSA Search bridge**. Enabled elements emit semantic markers and their current Bricks-owned query reference; no site-generated element or query ID is embedded in plugin runtime code. Explicitly marked filters win, while automatic discovery remains only for backward compatibility. Active contextual pills no longer toggle themselves off, product quick-add binds directly to rendered actions, `Kiwe > Search` can advance the cache generation, and version `0.5.6` forces deployment asset/cache separation. The public Hostinger `0.5.5` payload was inspected and confirmed to return Products scope, alphabet `C/L/M/P/S/V`, quick-add enabled, and addable products; remaining proof is a fresh `0.5.6` upload and interaction pass. |
| 26.61 | Menu Context Engine client and Search lazy-module correction | **Complete controlled baseline** | Added `Kiwe > Menu` as the sole admin owner for multiple WordPress menu sources, DSA-only targets, the admin Dashboard utility, and route-governed page context. On allowed routes, Menu reads rendered H1-H6 elements from the authoritative page main region, excludes shell/header/footer/navigation content, assigns stable local anchors where required, and closes the Surface before smooth in-page navigation. No Bricks parser, stored element ID, or request-time PHP content scan is used. Search now passes alphabet, product-add, and Bricks-bridge feature flags into its lazy module; this fixes enabled alphabet controls remaining hidden after deployment. Version `0.5.7` separates the corrected assets from `0.5.5/0.5.6` caches. The 71-variant UI conformance harness passes with zero failures; live heading extraction, multiple-menu, Bricks bridge, and device QA remain required. |
| 26.62 | Editorial Menu order, contrast, and progressive alphabet hardening | **Complete corrective batch** | Primary WordPress/DSA navigation now renders before the contextual table of contents. The context list inherits the authoritative light/dark Surface foreground instead of the legacy root foreground token, defaults to H1-H3, and excludes headings inside filters, forms, sidebars, query loops, and Woo product grids. Existing `On this page` defaults normalize to `Table of contents`. Progressive alphabet controls now receive direct per-render bindings rather than relying on delegated panel clicks; selecting `C` requests the canonical `prefix=C` REST result, then exposes the next available token such as `Ca`. Version `0.5.8` separates corrected assets from prior caches. |
| 26.63 | Product context isolation, cross-device recovery, commerce density, and AI inbox | **Complete corrective baseline** | The single-product context rail no longer relocates a theme/Bricks-owned Woo form into the fixed shell; a compact Kiwe control mirrors quantity and invokes the authoritative Woo button while the original form remains in its document context. PhoneKey identifies unknown browsers, explains passkey-provider sync limits, and offers verified-email recovery without exposing TOTP or backup-factor state. Search product cards reuse Cart Payload stock-urgency rules and include Woo-formatted weight. Cart items use an auto-fitting wide/compact grid with a one-column narrow contract. The visitor AI Surface no longer renders the admin Site Intelligence Audit; notifications are a collapsed vertical inbox, become read only when opened, and use a reduced-motion-safe unread bell animation. Version is `0.5.9`; live Bricks simple/variable product, passkey-provider/device, email delivery, cart density, and assistive-technology QA remain required. |
| 26.64 | New-device passkey ceremony, transition handoff, and narrow commerce refinement | **Complete corrective baseline** | Existing PhoneKey accounts on unknown devices enter an explicit OTP ceremony and then receive a short-lived, IP-bound passkey-enrollment flow; only successful passkey registration completes high-assurance login, creates the trusted-device cookie, and advances IP trust. Existing privileged first-enrollment still requires the WordPress password. Full navigation from an open DSA module now performs an immediate mode handoff before starting the common transition contract, removing the Search-result overlay/transition race while preserving configured minimum duration. The product context uses an isolated two-control grid, Profile context SVGs are hard-bounded to the control icon contract, and narrow Cart uses a dense two-column card grid above 350 CSS pixels with a one-column safety fallback below it. Version is `0.5.10`; live OTP transport, multi-device passkey, SecureTrack trust, Bricks product form, and real-device visual QA remain required. |
| 26.65 | Governed Dock composition and external Bricks launchers | **Complete controlled baseline** | Added `Kiwe > Dock` as the sole owner of dock visibility and drag order. Order is stored against stable Module Registry IDs and is shared by rendered dock and manifest contracts; hiding a destination removes only its dock button, not the registered Surface capability. Bricks 2.3.7 Icon elements gain a default-on Kiwe control that can launch Menu, Search, Profile, Links, Saved, Cart, AI, or theme mode through semantic `data-dsa-open-module` markup with keyboard support and no site IDs. Surface appearance no longer duplicates icon visibility settings. AI now uses the same geometry size and alignment as every other dock control while retaining its colored identity, unread animation, and badge. Product context payload columns are fully shrinkable and clipped inside the invariant dock-owned context frame. Version is `0.5.11`; live Bricks editor, hidden-module launcher, drag persistence, product rail, and real-device QA remain required. |

### Completion Snapshot

These percentages are engineering estimates, not marketing claims. The scoring model weights visitor safety, money/identity correctness, architecture unlock, and live verification. A wired baseline earns more than a stub, but less than a feature that has passed real-host and device matrices.

| Scope | Complete | Pending | Interpretation |
|---|---:|---:|---|
| **Current WordPress implementation** | **97%** | **3%** | The Surface, trust, commerce, PhoneKey hardening, PWA, notifications, analytics, Bricks, registry snapshot, native read-only islands, Search, enriched Saved, canonical tokens, responsive geometry, controlled offline editorial, generated Kiwe asset delivery, and APEX acceptance contracts exist. Remaining implementation is concentrated in runtime cleanup, admin trigger/IA work, schema cache refinement, account saved-state migration, live proof, and release operations. |
| **Controlled production readiness (historical pre-audit estimate)** | **84%** | **16%** | Superseded by the stricter July 2026 pre-1.0 audit below. Retained to preserve the evolution record. |
| **Approved APEX architecture** | **100%** | **0%** | The approved DSA interpretation of APEX is architecturally implemented: persistent Surface ownership, route policy, lifecycle contracts, native adapters, registry snapshots, S12-S16 earned navigation, S17 isolated offline editorial delivery, S18 asynchronous content-addressed Kiwe asset delivery, and S19 edge/cache/acceptance contracts. This percentage measures architecture implementation only; controlled production readiness remains separately scored and requires live proof. |
| **Broader DSA platform vision** | **62%** | **38%** | POS, customer score, selective offline actions, partner/edge tiers, marketplace, and cross-site identity remain later platform tracks. They are not required to complete the approved APEX delivery architecture. |

The percentages deliberately separate **implemented**, **proven**, and **architecturally complete**. Reaching 100% of approved APEX does not mean shipping rejected mechanisms or every brainstorm item. It means completing the safe DSA interpretation defined below.

### Production Gates To Release Readiness

DSA still needs **six production gates** before it should be presented as a broadly deployable WordPress release. The safe-first APEX batches are allowed to proceed in parallel only when they stay observable/additive. A narrowly controlled pilot can begin after the first four production gates pass on the target host, but packaging and admin/recovery clarity remain mandatory before wider distribution.

| Gate | Size | Production impact | What it should do |
|---|---|---|---|
| P1 | M | Very high | **In progress: code-hardening pass complete.** Verify PHP 8.2 with WordPress 7, WooCommerce, Bricks 2.3.7, PhoneKey, SecureTrack, HPOS, and active gateways on the target host; test billing/shipping CRUD plus custom address extensions, DSA checkout drafts/validation return, serialized Add & Save, one-coupon reverse pairs, overlap rejection, native coupon persistence, inclusive/exclusive tax, partial/full refunds and order totals, paid/refunded Analytics rows, DSA/Bricks cart cards, and fragment refresh. |
| P2 | M | Very high | **In progress: code-hardening pass complete.** Verify fresh IP-bound admin password proof, privileged user-verifying passkeys, mixed/elevated roles, explicit-close OTP UI, flow/target/attempt throttles, recovery codes/TOTP, real SMTP handoff and inbox delivery, and SecureTrack/PhoneKey proxy identity across supported devices and browsers. |
| P3 | M | Very high | **In progress after 0.4.86 code hardening:** run monitor-only/enforcement/break-glass recovery, role logout, direct/Cloudflare/Hostinger proxy identity, VAPID/OpenSSL, real server cron, outbound vendor delivery, order/comment push, subscription renewal/expiry, and loader fail-open tests on the target host. |
| P4 | S/M | High | **Code complete in 0.4.87; proof pending:** collect matched no-cache and Redis/Memcached traces with `DSA_PROFILE_CACHE`, verify the mobile bottom dock across browser-chrome resize and Surface scroll, exercise back gesture/X/dock/background close paths, induce one staging-only package failure to prove MU fail-open recovery, then disable profiling. |
| P5 | M/L | Medium/high | Admin IA and readiness consolidation: organize Appsite, Surface, Dock Modules, Trigger Rules, Trust, Rewards, Analytics, Secure, Bricks, WooCommerce, and Advanced without breaking existing option contracts; add deployment and recovery checklists. |
| P6 | S/M | High | Release packaging: defaults/copy review, version/changelog, known limitations, canonical Hostinger upload path, rollback/recovery notes, and live smoke checklist. Keep package preflight unless an atomic completion-marker deployment is proven. |

### Production-Blocking vs Post-Production

| Class | Items | Decision |
|---|---|---|
| **Production-blocking** | Woo checkout/virtual pair-coupon live matrix QA, reward/coupon live QA, PhoneKey admin/passkey/OTP hardening, SecureTrack enforcement/recovery QA, Chrome/Firefox/Safari push plus host OpenSSL/cron event QA, release diagnostics/cache profiling | Must be done before recommending deployment on real customer stores. The readiness surface and generated math tests do not replace real host transactions. |
| **Production-important but not launch-blocking** | Admin IA consolidation, readiness wording polish, deployment checklist, profile import/export tests | Should be done before wider agency deployment. |
| **Post-production roadmap** | Native islands/read-only bindings, earned fragment navigation, selective offline caching, automated customer stock/price/order-status push rules, POS scanner, customer score, Partner SDK, Cloudflare tier, marketplace, cross-site identity | Do not block first controlled production deployment. |

### Cumulative Patch Review Decision Through Batch 26.18

| Proposal | Decision | Current state |
|---|---|---|
| Permission Journey Manager | **Adopted v1.5** | PWA install and browser permission remain separate; saved notification preferences bridge product/order intent, PhoneKey identity, Android permission, iOS standalone continuation, and targeted owner alerts. Remote push transport is live; customer event automation, location, camera, and scanner remain future journeys. |
| Mode arbitration priority | **Adopted** | Baseline exists in runtime. Needs QA, not a new architecture pack. |
| Game abuse prevention | **Adopted baseline** | Server play tokens, attempt ledger, coupon expiry, and visitor/user/IP limits exist; leaderboards and deeper anti-cheat remain. |
| Interstice Attention Metrics | **Adopted v1** | Aggregate proof exists. Revenue/session lift and permission conversion attribution remain later. |
| GEO audit | **Adopted v1** | AI Copilot GEO readiness and Schema/GEO v1 exist. |
| Commerce context | **Adopted with changes** | Read-only v1 exists; no navigation-blocking add-to-cart. |
| Structured data | **Adopted with changes** | High-confidence JSON-LD exists with admin toggles. |
| Appsite profile marketplace | **Future** | Export/import exists; marketplace remains future expansion. |
| Partner SDK | **Future rails only** | Keep boundaries compatible, no default partner ads. |
| Cloudflare distribution | **Future rails only** | Do not ship edge tier until the WordPress product is stable. |
| Cross-site identity token | **Future rails only** | Decide token principles now; ship later after local PhoneKey/privacy/issuer maturity. |
| Navigation-blocking cross-sell add-to-cart | **Rejected near-term** | Current commerce engine preserves fail-open navigation. |
| Production readiness diagnostics | **Adopted v1** | Admin-only deterministic report exists. It guides launch readiness but does not claim automated certification. |
| Cart as DSA surface | **Adopted v2.1** | Cart dock screen now renders cart contents directly, exposes checkout only, and supports server-authoritative plus/minus quantity updates using real Woo cart keys. Removals are quantity-to-zero; coupon entry, shipping estimates, and checkout confidence orchestration remain future cart iterations. |
| Temporary cart diagnostics | **Adopted behind controls** | Console probes proved the live cart path and are now quiet unless Kiwe > Surface > Diagnostics enables frontend debug plus console logs, or a developer uses a temporary browser-only override. Backend debug-log paths remain for explicit server troubleshooting and should be reviewed again during P4 release diagnostics. |
| Old Bricks mini-cart snippets | **Absorb concept, reject paste-in** | Bricks 2.3.7 confirms `woocommerce-mini-cart`, `cartDetails`, `.mini-cart-link`, `.cart-detail`, `widget_shopping_cart_content`, `bricks/elements/{name}/controls`, `control_groups`, `bricks/element/settings`, `bricks/element/set_root_attributes`, and `bricks/frontend/render_element`. DSA now uses those contracts to expose native Bricks controls when Kiwe > Bricks toggles are on. Visual styling remains Bricks-owned; shared stock badge thresholds/text live in Kiwe > WooCommerce. |
| Old Bricks add-to-cart enhancer | **Absorb concept, rebuild runtime** | Bricks 2.3.7 confirms `product-add-to-cart` and current Bricks control/root/render hooks. DSA exposes the behavior/style controls in Bricks when enabled from Kiwe > Bricks, but uses DSA REST endpoints instead of copying the old snippet's `admin-ajax`, custom tracking table, and dashboard code. Event tracking should later merge into Interstice Attention Metrics rather than a separate snippet table. |
| WC Advanced Cross-sells Manager | **Absorb concept, rebuild as Woo service** | Product-editor category-to-cross-sell helper landed under the native Woo product editor and stores results as normal WooCommerce cross-sell IDs. DSA surfaces read those IDs as view-only recommendations. Bricks native `product-upsells` continues to render normal Woo data; Kiwe only adds optional source presets. |
| Woo Auto Bestseller Categories | **Absorb concept, rebuild conservatively** | Bestseller sync landed as an optional Kiwe > WooCommerce feature. It uses Woo analytics lookup tables when available, falls back to `total_sales`, creates Week/Month/Year child product categories, and cache-busts on completed/processing orders. It does not force front-end rendering or mutate navigation. |
| Bricks mini-cart FBT cards | **Adopted with Bricks-owned styling** | FBT cards now render in Bricks mini-cart when Kiwe > Bricks linked-product controls and the element-level FBT toggle are on. Styling controls live in Bricks; title/max defaults live in Kiwe > WooCommerce and can be overridden per Bricks mini-cart element. |
| Ponytail: delete Module Registry | **Rejected** | The registry is an active DSA architecture rail for future dock/POS/rewards/partner modules. It is intentionally small now; deleting it would trade a little code for a future rewrite. |
| Ponytail: delete Trust Service | **Rejected** | Trust data is used by Links, protected flow, copilot, readiness, and visitor-facing badges. It must stay deterministic and centralized. |
| Ponytail: delete Production Readiness | **Modified adoption** | The admin readiness panel stays because it helps controlled deployments. The REST mirror was removed. |
| Ponytail: delete Fragment Controller | **Adopted** | The unsafe controller was removed; `fragment_navigation` is hard-disabled and normalized to false. Its controlled S13-S16 editorial successor is implemented separately and remains off until live proof. |
| Ponytail: shared origin checks | **Adopted** | `Origin_Checker` now centralizes same-site origin validation and transient rate limiting for Metrics, Rewards, and Permissions REST callbacks. |
| Ponytail: permission stubs | **Adopted with changes** | Only implemented journeys enter the frontend contract. PWA install and browser notifications are live; location/camera/scanner remain admin roadmap signals until implemented. |
| Ponytail: autoloader cold path | **Adopted** | Case-insensitive fallback now uses cached `glob()` lookup instead of repeated full directory scans. |
| WP7 Interactivity / Abilities / AI Client | **Design now, ship incrementally** | Official platform rails are real. DSA should begin with read-only/admin-only abilities, bindings, and small Interactivity islands; do not replace the shell or touch protected checkout/auth flows first. |
| Ponytail: remove retained SEAM references | **Rejected** | `references/seam/` is retained, non-executable source material for the approved SEAM framework track. It may leave the deploy package only after its contracts are promoted into canonical code and preserved elsewhere; it is not disposable documentation. |
| Ponytail: delete S18 Asset Build Service | **Rejected pending proof** | S18 intentionally establishes asynchronous generation, atomic publication, checksum identity, bounded retention, invalidation, and packaged fallback. Its first artifact is byte-equivalent by design; transformations are not permitted until delivery correctness is proven. |
| Ponytail: delete S19 APEX profile | **Rejected** | The profile is the stable public/private acceptance and future-reader boundary. Low current consumption is expected for an architecture rail; it must remain cache-safe and free of host/private internals. |
| Ponytail: delete S16 safety harness | **Deferred** | Keep the bounded browser-only matrix until live S16 certification is complete. Afterwards move the matrix runner to development tooling while retaining production invariant recovery and full-navigation fallback. |
| Ponytail: collapse WP7 adapters | **Rejected for now** | Feature-detected adapters are an explicit architectural boundary. Small adapters may gain real execution incrementally; WordPress-native calls must not spread through shell, commerce, identity, or security code. |
| Ponytail: remove disabled/unused settings | **Adopt after migration verification** | Repeated `fragment_navigation` UI/state plumbing and unread `service_worker`/`bricks_first` defaults are cleanup candidates. Preserve one migration normalizer and explicit capability state until historical options have crossed a documented release window. |

## APEX Evolution Decision

The APEX brainstorm correctly identifies the missing delivery layer between WordPress and an app-like experience. It does **not** replace DSA. DSA already owns the business, trust, identity, permission, commerce, notification, registry, and persistent-Surface contracts that APEX assumes but does not specify.

The approved direction is therefore **DSA evolving into an APEX-grade Appsite runtime**, not a second framework and not a wholesale rendering rewrite.

### Documentation And Decision Governance

- The Batch Ledger is append-only history. Later corrections may mark an earlier claim superseded, but do not erase how the architecture evolved.
- The Current Code Audit is authoritative for what exists now; roadmap prose never upgrades a stub or adapter shell into a shipped capability.
- Every external proposal receives one of: adopt, adopt with changes, defer behind a gate, future rail only, or reject.
- Rejected proposals remain recorded with their reason. They may be reopened only when the blocking premise changes and a new superseding decision is documented.
- Versioned behavior, production proof, and architectural completion are separate statuses.
- Canonical code under `wp-content/mu-plugins` wins whenever prose and implementation disagree; the document must then be corrected in the next reconciliation pass.
- Product/design tokens, identity tokens, reward tokens, API tokens, and any future economic token system have their own contracts. They are never inferred from Codex batch/context accounting.

### What “Full APEX Adoption” Means In DSA

Full adoption means all of the following are true:

1. The Surface and each Kiwe module implement a documented mount/update/suspend/resume/destroy lifecycle.
2. Route capability profiles decide full navigation, cross-document transition, or earned editorial fragment handling.
3. Bricks registry data persists as save-time snapshots and feeds schema, AI, bindings, and safe route envelopes.
4. DSA-owned reactive regions use feature-detected WordPress Interactivity stores with the existing event/REST fallback.
5. Normal full navigations gain progressive browser View Transitions without weakening protected routes.
6. Editorial fragment navigation reconciles document metadata, body classes, assets, focus, history, registry state, and failures before it is enabled.
7. Asset and query tooling observes ownership and cost before any optimization changes execution.
8. Critical CSS, media optimization, public-shell caching, and edge compatibility are generated/cached delivery contracts, never per-request guesswork.
9. WooCommerce, PhoneKey, SecureTrack, checkout, payment, password, and account mutation remain deterministic and server-authoritative.
10. Every acceleration fails back to valid WordPress navigation with no trapped visitor, stale cart, identity leak, or missing script.

Cloudflare distribution, Partner SDK, marketplace, POS, customer scoring, and cross-site PhoneKey may use these rails later, but are not required to declare the approved APEX runtime complete.

### APEX Proposal Register

This register is cumulative. Earlier rejected Ponytail, snippet, partner-ad, fragment-controller, and identity proposals remain in the preceding decision tables and are not erased by this new direction.

| APEX proposal | Decision | DSA engineering interpretation |
|---|---|---|
| Progressive sovereignty | **Adopt** | Every capability is independently enabled and preserves a working WordPress fallback. |
| WordPress PHP rendering + progressive app navigation | **Adopt** | Keep real server-rendered pages as the source of truth; add route-aware transitions and islands around them. SEO, caching, AdSense, Woo checkout, and plugin compatibility stay native. |
| React/SPA rebuild | **Reject** | A SPA would add weight, build/deployment complexity, hydration risk, SEO/ads workarounds, and a second rendering authority. DSA’s advantage is appsite behavior without abandoning WordPress HTML. |
| Turbo Drive everywhere | **Reject as global default** | Turbo-style navigation may be tested only after route capability profiles and fragment envelopes exist. Checkout, payment, PhoneKey, account, cart mutation, and protected/security routes remain full-document. |
| Turbo/HTMX as implementation choices | **Defer behind route envelope** | The route contract matters more than the library. If later used, they must obey DSA lifecycle, asset, focus, history, and fallback rules. |
| Formal component lifecycle | **Adopt** | Apply first to Kiwe modules and native islands; arbitrary builder components remain adapter-owned. |
| Registry snapshots | **Adopt** | Build on save with version, dependencies, confidence, and invalidation metadata. |
| Design tokens as CSS variables | **Adopt with namespace/versioning** | DSA already uses variables and now has both a protected `kiwe.surface` schema and a Kiwe universal-token export adapter informed by SEAM. Map builders through explicit adapters; never import arbitrary project tokens into Kiwe and never overwrite site or Bricks variables blindly. |
| MCP design/developer workflow | **Future rail only** | A registry readable by AI/dev tools is good. AI writing executable plugin files directly is not a near-term product feature without permissions, review, signing, audit trail, rollback, and staging boundaries. |
| Cross-document View Transitions | **Adopt progressively** | Feature-detect on ordinary same-origin routes; protected flows remain full navigation without shared-element assumptions. |
| Interactivity islands | **Adopt progressively** | Start with AI and app-adoption display state, then read-only trust/account/cart summaries. Mutations remain on server-verified contracts. |
| Service container | **Modify** | Split `Plugin.php` into explicit domain composition roots/factories. Reject reflection autowiring and runtime magic. |
| Event sourcing | **Modify** | Add an append-only audit/projection stream where useful. Never replace Woo order/payment state or WordPress hooks. |
| App shell | **Modify** | The persistent Kiwe Surface is the shell. Theme/header/footer persistence is earned later per route, not assumed universally. |
| HTMX | **Optional implementation detail** | A safe fragment-envelope contract matters more than this library. Do not add HTMX merely to claim partial navigation. |
| DOM morphing | **Controlled experiment** | Use only after route and asset envelopes exist; preserve focus/forms/media and provide hard fallback. |
| Streaming HTML | **Defer/narrow** | Test only on DSA-controlled public endpoints. General WordPress output is vulnerable to plugin buffers, late headers, proxies, and shared-host behavior. |
| Critical CSS extraction | **Build/cache only** | Never parse arbitrary builder CSS on every request. Generate offline or asynchronously, retain full stylesheet fallback, and invalidate by content/theme/build version. |
| Asset ownership manifest | **Adopt observe-first** | Record handles, dependencies, inline data, owners, route use, size, and timing before changing delivery. |
| Content-hash asset deduplication | **Reject as a generic runtime rule** | Equal bytes do not prove equal dependency, localization, execution-order, module, or lifecycle contracts. |
| Universal final-output transformer | **Reject** | It contradicts zero regression and risks forms, scripts, accessibility, SEO, and builder state. Use builder-specific adapters. |
| Automatic image rewriting | **Modify** | Prefer WordPress attachment metadata and builder/media APIs. Never rewrite unknown third-party markup blindly. |
| Query budget | **Observe, do not enforce** | Report route/service budgets and regressions. Never stop valid plugin queries or pretend semantically similar SQL is safely deduplicated. |
| Remove jQuery | **Reject blanket removal** | DSA code can remain modern and dependency-light while respecting themes/plugins that legitimately require jQuery. |
| Fibers for DB/HTTP concurrency | **Reject** | Fibers do not make blocking WordPress I/O asynchronous. Queue network work through Action Scheduler, cron, workers, or future edge services. |
| Attribute/reflection routing | **Reject for current REST** | Keep explicit `register_rest_route()` definitions and capability callbacks. Attributes may document internal metadata only if later measured useful. |
| Middleware before `wp-settings.php` | **Infrastructure-only future** | Requires an advanced-cache drop-in, server/CDN configuration, or edge worker; an MU plugin cannot provide this before WordPress boots. |
| Static shell cached indefinitely | **Reject for personalized surfaces** | Public shells require strict public/private partitions. PhoneKey, cart, checkout, account, nonce, and personalized HTML must never enter shared caches. |
| Automatic query deduplication | **Reject** | SQL equality does not guarantee equivalent side effects, cache state, object hydration, or caller expectations. |
| Hard LCP/INP guarantees | **Reject as product promise** | DSA can set budgets and measure results, but themes, hosts, plugins, media, geography, and third parties remain outside absolute control. |
| Swipe navigation and pull-to-refresh | **Defer/route-specific** | Browser back gestures, carousels, forms, and accessibility take priority. Add only where conflict testing proves value. |
| Guaranteed browser install prompt | **Reject as promise** | Android `beforeinstallprompt` is useful when available but not guaranteed. DSA can capture, explain, retry, and guide manual install; it must never claim control over browser/private-mode/device rules it cannot observe. |
| Desktop install nagging | **Reject by default** | Desktop users should feel the appsite through speed, continuity, and trust, not through early install pressure. Install journeys remain earned and contextual. |

### Batch-Capacity Calculation

This calculation concerns **Codex implementation context and engineering review capacity only**. It is completely separate from DSA design tokens, authentication tokens, reward tokens, API tokens, or any future economic/token system.

The established attempt bands remain:

- **S:** 5k-9k working tokens; one narrow subsystem.
- **M:** 9k-16k; several closely related files and one behavioral contract.
- **L:** 16k-26k; cross-system behavior and mandatory staged verification.
- **XL:** over 26k; not accepted as one batch and must be split.

Calculation:

`22 completed safe/design/runtime/APEX batches + 0 remaining APEX batches + 6 production-readiness gates = 28 focused batches`

`28 × 1.30 uncertainty reserve = 36.4, rounded up to 37 maximum expected batches`

The reserve covers live-host discoveries, browser differences, builder updates, gateway behavior, cache layers, accessibility, and regression repairs. It is not permission to enlarge individual batches.

### Safe-First APEX Roadmap

This is the authoritative APEX execution order from version `0.4.65` onward. Safe-first means additive, observable, and reversible work comes before navigation rewriting, DOM morphing, critical CSS extraction, or protected-route acceleration.

| APEX batch | Size | Safety class | Deliverable and acceptance boundary |
|---|---|---|---|
| S1 | S/M | **Complete in 0.4.65** | Route capability observer: server policy plus frontend classifier publishes route capabilities without changing navigation behavior. |
| S2 | M | **Complete in 0.4.66** | Surface/module lifecycle contract: mount, update, suspend, resume, destroy events for DSA-owned panels; no arbitrary builder lifecycle claims. |
| S3 | S/M | **Complete in 0.4.67** | Diagnostics quieting and observability: frontend debug exposure and console logging are admin-gated, with temporary browser-only overrides for QA and no visitor data leakage. |
| S4 | M | **Complete in 0.4.68** | Versioned design-token schema and read-only adapter: document Kiwe token names/fallbacks and expose builder-readable metadata without writing site styles. |
| S4a | M | **Complete in 0.4.74; promoted in 0.4.85** | Kiwe > Tokens began as an additive Bricks export adapter. Batch 26.39 promoted the same universal set to the authoritative Surface runtime; legacy `dsa-*` values are compatibility aliases. Kiwe still imports no project token files and never overwrites non-Kiwe Bricks variables or palettes. |
| S4b | S | **Complete in 0.4.71** | Universal token completion: add micro type, layout width/grid/gap tokens, input padding, and the lower z-index scale; keep scene coefficients in CSS/runtime instead of Bricks variables. |
| S5 | M | **Complete in 0.4.71** | Performance profiler expansion: route/service/cache/query timings report only; no enforcement, dedupe, or runtime optimization changes. |
| S6 | M | **Complete in 0.4.72** | Asset ownership manifest observe-only: record handles, dependencies, inline data, module ownership, route use, and sizes before any delivery change. |
| S7 | M/L | **Complete in 0.4.73** | Registry snapshots on save: versioned snapshots with invalidation and live-render fallback for schema, AI, bindings, and future fragment envelopes. |
| S8 | S/M | **Complete in 0.4.74** | Native AI island: AI badge/header/history count can consume `surface:ai:notifications` through a namespaced `kiwe/ai` Interactivity state when available; DOM-event fallback stays. |
| S9 | S/M | **Complete in 0.4.75** | Native app-adoption island: PWA/standalone/notification display state consumes `surface:app:adoption` through `kiwe/app` when available; permission prompts remain explicit user gestures. |
| S10 | M | **Complete in 0.4.76** | Read-only native data and Block Bindings baseline: `kiwe/data` exposes site, trust, profile, and cart display summaries; `kiwe/site` exposes public site identity. No money, account, identity, checkout, or security mutations moved to native APIs. |
| S11 | M | **Complete in 0.4.80** | Zero-weight DSA Live Search: Search dock module, near-zero boot metadata, lazy ES module, product-first/post-second public results, top-layer Popover/CSS Anchor progressive enhancement with inline fallback, AbortController, adaptive debounce, ghost results, server-side highlighting, role-aware object/transient cache keys, and a minimal same-site REST endpoint. The full search module is not loaded unless a human opens Search. |
| S11a | M | **Complete in 0.4.83** | Responsive Geometry Engine: versioned geometry tokens, enabled dock-item emission, one horizontal/vertical visual-viewport solver, shared panel/sticky/composer reserves, safe-area and admin-bar inputs, and removal of the mobile-only final-icon calculation. Live real-device regression remains part of P4/browser proof rather than being claimed from generated tests. |
| S12 | M | Medium | **Code complete in 0.4.88; live proof pending.** Native full-document transitions require explicit editorial link eligibility plus source/destination verification. Unknown, Woo/product, protected, stale, unsupported, and reduced-motion journeys fall back to ordinary navigation. |
| S13 | M/L | High | **Contract complete in 0.4.89; live rendering proof pending.** The observe-only editorial envelope includes content, title/meta, body classes, registry version/hash, observed assets, canonical URL, private no-store policy, blockers, and full-document fallback. It cannot morph and is not consumed by the runtime. |
| S14 | L | High | **Observe-only planner complete in 0.4.90; live proof pending.** Lazy inspection compares current and destination documents plus S13, producing head/body/asset/inline/history/focus/scroll/live-region operations and blockers. Application was disabled in S14 and is consumed only by S15's separate opt-in gate. |
| S15 | L | High | **Controlled static-editorial baseline complete in 0.4.91; compatibility proof pending.** Application is off by default, Bricks and interactive/runtime-bound content are blocked, uncertainty falls back to a full document, and reverse history reloads authoritative WordPress output. HTMX/Turbo remain unnecessary implementation details under the DSA envelope. |
| S16 | L | High | **Proof harness complete in 0.4.92; live matrix pending.** Scenario classification, response evidence, post-commit invariants, bfcache/history outcomes, bounded browser-only evidence, and intentional protected-route failures are implemented. Bricks remains blocked until real Bricks pages, comments, search, archives, forms, embeds, cache plugins, browsers, accessibility, analytics, and SEO-head behavior pass. |
| S17 | M/L | Medium/high | **Controlled pilot complete in 0.4.93; live proof pending.** A dedicated sanitized public WordPress editorial contract, bounded same-origin media hints, versioned cache limits, script-free offline renderer, and explicit network-only partitions are implemented. The feature is off by default and Bricks remains ineligible. |
| S18 | L | High | **Controlled delivery-build pilot complete in 0.4.94; live proof pending.** Asynchronous generation, atomic publication, source/artifact checksums, plugin/theme invalidation, bounded font/media candidate inventory, optional validated generated CSS delivery, and packaged fallback are implemented. No per-request extraction or arbitrary builder optimization is allowed. |
| S19 | L | High | **Approved APEX architecture complete in 0.4.95; live production certification remains separate.** Added public/private edge contracts, manifest discovery, response classifications, measured asset evidence, accessibility/runtime matrices, and a final admin acceptance profile. HTML stays origin-required and personalized/transactional state stays network-only. |

S19 is the approved **full APEX adoption** point. It does not revive rejected runtime rewriting, Fiber concurrency, unsafe caching, universal builder mutation, or AI writing executable files directly.

### Production Gates After Safe Foundations

These gates still decide whether the plugin is deploy-ready for real customer stores. They can begin earlier for controlled pilots, but they should not be mixed with unsafe navigation experiments.

| Gate | Size | Production impact | What it should do |
|---|---|---|---|
| P1 | M | Very high | **In progress after 0.4.84 code hardening:** WordPress 7, PHP 8.2, WooCommerce, Bricks 2.3.7, HPOS, active gateways, DSA checkout, Add & Save, duplicate/reverse/overlap pair math, native coupon/tax persistence, partial/full refunds and order totals, paid/refund Analytics, cart cards, and fragment refresh still require the live matrix. |
| P2 | M | Very high | **In progress after 0.4.85 code hardening:** run the live admin-password, privileged WebAuthn UV, mixed-role/elevation, OTP/TOTP/backup recovery, SMTP/inbox, proxy identity, and SecureTrack integration matrix. |
| P3 | M | Very high | **In progress after 0.4.86 code hardening:** live enforcement recovery, proxy provenance, VAPID/OpenSSL, server cron, vendor delivery, owner alerts, renewal/cleanup, and fail-open proof remain. |
| P4 | S/M | High | **Code complete in 0.4.87; live proof pending:** paired cache traces, real-device dock/back-gesture matrix, and one staging-only fail-open recovery drill remain. |
| P5 | M/L | Medium/high | Admin IA and deployment readiness: organize Kiwe areas without option-contract breakage, add deployment/recovery checklists, and clarify pilot vs production status. |
| P6 | S/M | High | Release packaging: version/changelog, known limitations, canonical Hostinger upload path, rollback notes, and live smoke checklist. |

### Fix Priority By Attempt Economics

| Club | Packs | Can be handled together? | Reason |
|---|---|---|---|
| **Safe observation foundations** | S1-S10 | Split by contract, but individually safe | Route classification, lifecycle, diagnostics, tokens, profiler, assets, registry snapshots, AI display state, app-adoption display state, and read-only native data are additive/observable. They prepare APEX without changing money, identity, or navigation authority. |
| **Native islands and read-only data** | S8-S10 | Complete safe baseline | Display state moved first; money, identity, checkout, SecureTrack, and cart mutations remain server-owned. |
| **Search foundation and earned navigation** | S11-S16 | Always split | S11 Search, S12 native transitions, S13 envelopes, S14 planning, S15 controlled static application, and the S16 proof harness are code-complete. The live S16 matrix remains a release gate; Bricks lifecycle admission is not implied by harness completion. |
| **Delivery infrastructure** | S17-S19 | Always split | Offline public content, generated optimization, and edge contracts require separate invalidation and privacy reviews. |
| **Release-candidate commerce safety** | P1 | No | Discount math, native coupon/tax persistence, cart mutation, Analytics, Bricks mini-cart, and DSA cart all touch money and session state. Keep this gate focused. |
| **PhoneKey identity safety** | P2 | No | Admin password step-up, passkeys, OTP resend, SMTP assumptions, role escalation, and SecureTrack trust should be audited without commerce churn. |
| **Security and delivery proof** | P3 | No | SecureTrack enforcement and Web Push transport both require real-host failure testing; do not mix feature additions into this batch. |
| **Production quieting / admin / packaging** | P4-P6 | Split but can follow closely | Diagnostics, admin IA, deployment checklists, versioning, Hostinger upload path, and rollback notes belong after core live-host behavior is proven. |
| **Later platform expansion** | After S19/P6 | Milestone tracks | POS/customer score, Cloudflare distribution, Partner SDK, marketplace, and cross-site identity are not silently included in APEX completion. |

**Engineering constraint:** Avoid bundling high-intelligence packs together. Reward coupons, metrics, permission prompts, SecureTrack request filtering, earned fragment navigation, PhoneKey identity promotion, AI writes, POS, and future token issuer decisions each deserve focused attempts.

### Post-APEX Authoritative Work Queue

S19 closes the approved architecture, not the product or production journey. This queue supersedes older forward-looking task prose while leaving the historical batch ledger intact. The short execution mirror lives in `DEVELOPMENT-PLAN.md`.

`Priority = trust impact + adoption impact + architecture unlock - implementation risk - context cost`

| Task | Size | State | Dependency and acceptance boundary |
|---|---|---|---|
| **PR1 Commerce live matrix** | M | Pending live proof | Certify Woo/Bricks/HPOS, tax, gateways, DSA Checkout, Add & Save, pair overlap/reversal, refunds, cart fragments, paid/refund Analytics, and rollback. No new commerce feature is mixed into this gate. |
| **PR2 PhoneKey live matrix** | M | Pending live proof | Certify admin password step-up, WebAuthn UV, role elevation/revocation, OTP/TOTP/backup recovery, SMTP delivery, proxy identity, and standalone onboarding. |
| **PR3 SecureTrack + Push live matrix** | M | Pending live proof | Certify enforcement recovery, trusted-proxy provenance, VAPID/OpenSSL, cron, subscription renewal/cleanup, owner delivery, and fail-open behavior. |
| **PR4 Runtime/cache/device proof** | S/M | Pending live proof | Capture matched no-cache/persistent-cache traces; verify safe areas, zoom, browser chrome, Surface scroll, back gestures, bfcache, and one staging-only loader failure. Disable profiling afterwards. |
| **PR5A S16 editorial lifecycle proof** | M | Pending; feature remains gated | Run the documented browser, Bricks, SEO-head, forms, embeds, accessibility, history, cache, and intentional-failure matrix. Do not admit Bricks or broaden route classes from static confidence. |
| **PR5B S17 offline editorial proof** | M | Pending; off by default | Prove update, airplane replay, media bounds, eviction, worker upgrade, logout/cart isolation, iOS, and Chromium behavior. |
| **PR5C S18 generated delivery proof** | M | Pending; off by default | Prove cron, uploads permissions, checksum/fallback, cache headers, invalidation, artifact retention, visual parity, and measured benefit before any transformation is proposed. |
| **PR6 Release candidate and admin IA** | M | Pending | Reconcile Kiwe navigation without option breakage; finalize version/changelog, deployment inventory, Hostinger smoke test, known limitations, rollback, and production quietness. |
| **SEAM-1 Contract Reconciliation** | M | Parked; excluded from 1.0 count | Preserve the bundled references; resume only by product decision after the RC1-RC14 release path. |
| **SEAM-2 Additive Bricks Framework Export** | L | Parked after SEAM-1 | Export remains additive and may never overwrite existing Bricks project data. |
| **SEAM-3 Page Authoring Aids** | M/L | Parked after SEAM-2 | Page-authoring controls remain separate from DSA Surface appearance and authority. |
| **UI-1 Marketplace conformance harness** | M | Baseline complete; live proof pending | The 70-variant screenshot and contract harness is operational outside the deploy package. Extend it with actual safe-area/browser zoom emulation, keyboard/focus journeys, screen readers, reduced motion, forced colors, and third-party package fixtures before enforcing marketplace admission. Reject packages that add shell breakpoints, clone deterministic controls, hide slots, overflow reserves, or fail accessibility assertions. |
| **PAYLOAD-1 Thin Surface shell** | L split | Presentation boundary complete; deeper event-domain splitting remains profile-led | Games, Profile, Cart/Checkout, Links/editor, and AI panel/inbox/report presentation load on first use. The resident shell is 347,324 raw / 75,495 gzip and retains geometry, lifecycle, history, event binding, cart reconciliation, identity gates, AI arbitration, and deterministic server authority. Further extraction requires production profiling and must not duplicate state or mutation ownership. |
| **CLEAN-1 Runtime and migration cleanup** | S/M | After PR4 | Remove only proven unread flags, retire completed temporary probes, preserve migration readers, and reduce duplicate disabled-navigation plumbing. |
| **CORE-1 Trigger Journey Manager** | L split | After PR1-PR4 | Move remaining scattered event behavior into admin-governed trigger contracts with priority, frequency, dismiss, duration, route, identity, and protected-flow guards. |
| **NATIVE-1 WP7 bounded execution** | M per adapter | After PR4 | Begin read-first Abilities, suitable DataViews admin tables, and small Interactivity display islands. Keep `surface.js` as shell authority and keep money/identity/security server-owned. |
| **DATA-1 Registry/Schema/Analytics refinement** | M/L split | After PR1/PR4 | Snapshot-keyed schema cache invalidation, GEO confidence evidence, revenue/session and permission attribution, and scalable Saved storage/migration. |
| **PRODUCT-1 Commerce, rewards, and permissions v2** | M per workflow | After matching gates | Shipping/coupon confidence, leaderboards and campaign budgets, location/camera/scanner journeys, and richer deterministic AI notifications. |
| **PLATFORM-1 Later network expansion** | XL split | After PR6 and product proof | POS, customer score, merchant-opt-in partners, marketplace, edge distribution, and cross-site identity remain separately approved milestone tracks. |

**Historical completion interpretation (`0.5.11`, before the pre-1.0 audit):** implementation breadth was approximately **96%** of the present WordPress MVP, controlled production readiness approximately **84%**, approved APEX architecture **100%**, and the broader platform vision approximately **62%**. These dimensions must not be combined into one marketing percentage. The stricter release audit below supersedes the readiness percentage without erasing this historical measurement.

### Pre-1.0 Whole-Codebase Audit Decision (July 2026)

The pre-1.0 audit re-read the boot path, REST mutation boundaries, PhoneKey, SecureTrack, Cart/Checkout, Rewards, Push/PWA, Search, private frontend boot data, caching, analytics, abandoned carts, APEX pilots, Bricks adapters, and the current documentation queue. Two external reviews were treated as leads, not authority. Claims were accepted only when the current canonical package still demonstrated them.

The audit changes the release interpretation to **78% engineering closure** and **64% production certification**. This is not feature regression. It is a stricter denominator that includes cache isolation, mutation proof, crypto recovery, no-cache-host write budgets, SEO/AdSense compatibility, live identity/commerce proof, and release operations.

Confirmed production blockers are: fail-open mutation request evidence, push unsubscribe ownership, reward minimum-duration trust, immediate account-email replacement, personalized `DSA_DATA` in cacheable HTML, plaintext-equivalent SecureTrack secret fallback if reachable, and missing live commerce/identity/security matrices. High-priority non-blockers include abandoned-cart pageview writes, salt-rotation recovery, fixed-window option-backed rate limits, worker refresh cost, unconditional admin-bar hiding, constructor migrations, and loader version drift.

The audit rejected several over-broad conclusions. Current Search does not create one transient per typed query; it uses the WordPress object-cache API. The root MU loader and package bootstrap are not two normally activatable plugins, although their displayed versions must agree. PhoneKey and SecureTrack will not be ripped into independent plugins before 1.0; their integration boundaries will be strengthened instead. A wholesale rewrite to enums, readonly classes, Interactivity, Abilities, Script Modules, Action Scheduler, or a third-party WebAuthn library is not a release strategy. Each may be adopted at a measured stable boundary without changing external WordPress/WooCommerce/Bricks contracts.

SEO and advertising safety are explicit 1.0 gates. Kiwe preserves server-rendered WordPress documents, canonical/meta/schema authority, ordinary full navigation, and no-JavaScript reachability. The audit must additionally prove crawler parity, no cloaking, ad-script survival, consent compatibility, layout stability, cache/CDN behavior, and non-intrusive initial defaults. S15, S17, and S18 remain off-by-default pilots unless separately certified.

The authoritative execution sequence is RC1-RC14 in `DEVELOPMENT-PLAN.md`. It is calculated as **14 batches, expected 18 attempts, responsible range 15-23 attempts**. SEAM is deliberately parked and excluded from that count. Its retained references remain architectural source material for a later product decision.

### RC1 Mutation Trust Boundary (`0.5.12`)

RC1 inventoried DSA REST, PhoneKey REST, admin-post, admin AJAX, and SecureTrack AJAX mutations. Public read contracts remain public. Every DSA and PhoneKey REST state change now requires an explicit non-simple `X-Kiwe-Mutation: 1` header in addition to rejecting hostile Fetch Metadata/Origin evidence. A cross-origin HTML form cannot set this header, and a cross-origin fetch must pass CORS preflight while the origin check still rejects it. Authenticated account/admin writes continue to require WordPress REST nonce authentication and capabilities.

Cart, Checkout, Saved, notification preferences, permission outcomes, metrics, rewards, Push, Account, Settings, admin notification acknowledgement, PhoneKey public flows, PhoneKey account-factor changes, Bricks cart clients, Surface clients, uploads, and worker Push save were migrated to the shared contract. Existing admin-post/admin AJAX and SecureTrack writes retained their native WordPress nonce/capability or flow-token checks.

Push deletion now resolves the encrypted endpoint hash to its stored owner and requires either the same authenticated user or the same Kiwe visitor hash. Anonymous subscription creation without a visitor identity fails closed. The service worker no longer deletes an old endpoint during `pushsubscriptionchange`, because a worker-only event cannot currently prove the browser visitor identity. Delivery cleanup can expire rejected endpoints; RC7 owns a durable ownership-safe renewal handoff.

Static verification covered the complete route inventory and JavaScript syntax for all nine shipped JS files. PHP lint was intentionally not run under the standing project instruction. Live anonymous cart, authenticated account, PhoneKey, Bricks mini-cart, Push subscribe/unsubscribe, CORS/preflight, and cache tests remain part of RC13/RC14 certification rather than being inferred from static inspection.

### RC2 Rewards and Commerce Abuse Safety (`0.5.13`)

Reward completion no longer accepts client-reported duration as proof. The server stores millisecond start time, consumes each attempt token under an atomic option lock, and enforces minimum/maximum duration only from server elapsed time. Identity and IP ledgers are separately locked while an attempt is committed, preventing concurrent tokens from racing the daily limit. Client scores remain accepted for immediate game feedback but are returned and stored with `scoreTrusted: false`; they cannot become authoritative leaderboard or campaign-budget evidence without a future signed-event design.

Coupon generation remains off by default and now has an admin-governed daily issuance budget. Budget reservation is serialized and released when coupon creation fails. Generated coupons remain percent, individual-use, single-use, expiring, identity-tagged, and email-bound when an authenticated address exists. Budget counters are operational state rather than analytics authority; retention and generalized hot-key storage remain RC6 work.

The existing pair-discount architecture was retained: sorted pair identity, native Woo virtual coupons, affected-item/quantity filtering, scope normalization, and pre-claim overlap rejection were already correct. RC2 added reconciliation for legacy/session/race residue. During cart coupon synchronization, claims reserve their affected products deterministically; duplicate reverse-pair or overlapping claims have their Kiwe claim metadata removed before coupons are applied. This prevents two virtual coupons from discounting the same product through stale cart state.

`tools/commerce/rc2-invariants.cjs` verifies the source contracts and runs 100,000 randomized reverse-pair and scope-cardinality cases. All nine shipped JavaScript files parse. PHP lint was not run under the standing instruction. Real WooCommerce session, tax-inclusive/exclusive, gateway, HPOS, refund, coupon persistence, multi-request concurrency, and order-total reconciliation remain mandatory in RC13.

### RC3 Identity and Recovery Integrity (`0.5.14`)

Profile email is now a verified recovery-anchor transition rather than an ordinary user-field write. First name, last name, and display name can save immediately, but a changed email remains pending. PhoneKey issues a short-lived OTP challenge to the new address, stores the pending address encrypted, binds the request to the previous email hash, and consumes the challenge atomically. A stale or replayed request cannot replace the current anchor, and email uniqueness is checked both before delivery and at completion.

High-privilege users must provide their current WordPress password before an email-change challenge is issued. On successful ownership proof, WordPress email and the verified PhoneKey email factor move together. Remembered devices, trusted IP state, other WordPress sessions, and outstanding email/recovery/step-up challenges are revoked; existing WebAuthn credentials remain enrolled so recovery hardening does not destroy legitimate multi-device passkeys.

The existing new-device contract remains: a known account on an untrusted device proves its verified email or phone OTP, then enrolls a passkey for that device. Registration excludes existing credential IDs but stores each new credential separately, while login advertises all credentials for the user. Privilege elevation and privilege removal both clear stale password binding, high-assurance markers, trusted IPs, and trusted-device records.

`tools/identity/rc3-contracts.cjs` checks 17 identity/recovery contracts, including pending-email behavior, encrypted metadata, atomic consumption, stale-request rejection, privileged step-up, trust revocation without credential deletion, new-device enrollment, multi-passkey support, WebAuthn challenge/origin/RP binding, counter replay rejection, role-boundary invalidation, and shared mutation proof. All nine shipped JavaScript files parse as modules. PHP lint was intentionally not run under the standing instruction. SMTP delivery, real platform/roaming authenticators, multiple browsers/devices, TOTP and backup-code recovery, role transitions, session invalidation, proxy identity, and lockout recovery remain mandatory RC13 evidence.

### RC4 Secrets and Crypto Recovery (`0.5.15`)

Kiwe now has one versioned secret-storage authority. New material is written as `dsa-secret:v2` using Sodium secretbox when available or OpenSSL AES-256-GCM otherwise. Both are authenticated encryption. If neither primitive is available or secure randomness fails, writes return no ciphertext and callers preserve the last valid secret rather than creating plaintext-equivalent storage. Previous `dsa-sodium:`, `dsa-gcm:`, PhoneKey `gcm:`/`b64:`, and SecureTrack `enc:v1:`/`legacy:` values remain read-only compatibility inputs; successful SecureTrack and VAPID reads are migrated forward. No new writer emits the old base64-equivalent formats.

The encrypted-field inventory is explicit:

- shared store: SMTP password and outbound channel API tokens;
- Push: endpoint, browser P-256 public material, auth secret, and VAPID private key;
- PhoneKey: phone/email factor values, TOTP secret, and pending verified-email anchor;
- SecureTrack: AI provider key, webhook URL, and webhook signing secret.

Hashes used for lookup, ownership, rate limiting, and identity are not reversible encrypted fields. PhoneKey HMAC identity remains derived from WordPress authentication salts and the site URL. Kiwe records only a short non-secret key ID and raises a critical readiness error if it changes. `DSA_SECRET_STORE_PREVIOUS_KEYS` or the `dsa_secret_store_previous_keys` filter can supply earlier encryption keys for controlled data recovery, but those keys cannot repair changed PhoneKey identity HMACs; the original WordPress salts must be restored.

Push no longer silently leaves a dead audience after VAPID private-key loss. Each subscription records the current public-key ID. If stored VAPID material cannot be decrypted, Kiwe generates a deliberate replacement key, marks active subscriptions `reenroll_required`, exposes only the public key and non-secret key ID, and reports the affected count. Returning browsers compare their actual `applicationServerKey`, with a local key-ID fallback, unsubscribe the obsolete subscription, and enroll against the replacement key. Re-enrollment rows are retained for 30 days before cleanup so recovery remains observable.

`tools/security/rc4-contracts.cjs` verifies 16 format, fail-closed, migration, key-diagnostic, inventory, redaction, VAPID-rotation, schema, and browser re-enrollment contracts. The RC3 suite still passes, and all nine JavaScript files parse as modules. PHP lint was not run under the standing instruction. Real Sodium/OpenSSL host variants, wp-config salt restoration, previous-key recovery, VAPID rotation, Chrome/Firefox/Safari re-enrollment, and vendor delivery remain mandatory RC13/RC14 certification.

### RC5 Cache-Safe Boot Contract (`0.5.16`)

DSA now treats reusable page HTML as a public shell rather than a session snapshot. The initial payload contains site identity, theme/tokens, route facts, public module configuration, public trust presentation, and enough neutral dock geometry to paint the first frame. It does not inline a WordPress REST nonce, logged-in identity, profile/cart counts, account links, protected-flow state, personalized commerce decisions, admin dashboard/Secure links, Copilot capability, or Links editor authority. Profile and cart badges begin at zero and PhoneKey-dependent dock visibility begins from a neutral complete shell.

Private runtime state arrives from the explicit same-site `GET /dsa/v1/runtime/hydrate` contract. Its response is `private, no-store, no-cache`, varies by cookie, and is excluded from indexing. It supplies the fresh REST nonce, PhoneKey/account state, profile and cart summaries, protected-flow state, Woo-owned commerce context, admin-only capabilities, Links editing data, and role-dependent dock visibility. The client mutates the existing runtime objects, updates native-data islands and badges, hides role-ineligible modules, and recalculates geometry from the visible item count before opening a dock module. Failure leaves the neutral shell intact rather than inventing authenticated or monetary state.

Bricks mini-cart and add-to-cart integrations no longer embed a page nonce. They call the existing no-store nonce endpoint immediately before their first mutation and retain one guarded refresh/retry for an expired nonce. The boot seed is deterministic rather than timestamped, so DSA itself no longer makes otherwise reusable anonymous HTML unique on every request. Admin Links editing remains available, but its capability and editor payload now cross the private hydration boundary.

`tools/cache/rc5-contracts.cjs` verifies 19 route, cache-header, neutral-shell, identity-branch, badge, deterministic-seed, private merge, role-geometry, Links-authority, and Bricks nonce contracts. All nine shipped JavaScript files parse as modules. PHP lint was intentionally not run under the standing instruction. RC5 is source-level cache isolation, not a claim about every host: anonymous-versus-authenticated CDN reuse, Woo session cookie variation, logged-in account-page bypass, cache-plugin behavior, and stale-session recovery still require RC10/RC13/RC14 staging evidence.

### RC6 Shared-Host Write Budget (`0.5.17`)

Kiwe no longer implements hot REST windows as WordPress transients keyed by IP and minute. Those keys were functionally bounded in time but became many `_transient_*` option rows on a host without Redis. `Atomic_Rate_Limiter` is now the shared request-budget authority. A persistent object cache uses atomic `wp_cache_incr()` counters with native expiry. An ordinary shared host uses `wp_dsa_rate_limits`, keyed by a salted bucket hash, with one atomic-upsert row per logical route/identity bucket instead of one row per time window. The limiter resets in place, cleans expired rows hourly in bounded batches, fails open if its storage cannot be established, and exposes backend/table/cron health through Production Readiness.

Abandoned-cart tracking now distinguishes semantic cart changes from passive browsing. Product, quantity, identity, checkout, clear, recovery, and conversion changes remain immediate. An unchanged cart within the default five-minute heartbeat performs no write; after the heartbeat it updates only identity, status, and activity columns rather than serializing the cart again. Duplicate captures in one request collapse by cart hash. Hourly maintenance purges reminder logs and terminal converted, cleared, or abandoned carts after configured retention even when tracking has subsequently been disabled.

Store Analytics visit/adoption dedupe uses the same bounded limiter and event rows receive daily retention cleanup, defaulting to 180 days through a filterable policy. Interstice Metrics remains a compact daily aggregate but now caps distinct context keys at 100 per day and folds overflow into an event-specific `other` bucket. Saved items remain explicit user actions capped at 100 objects; reward attempt/state records retain their existing short TTL and daily attempt bounds. Store Analytics, Metrics, Saved, Rewards, and abandoned-cart writes now emit named samples only when Kiwe runtime profiling is explicitly enabled, allowing the same journey to be compared on Redis and no-cache hosts without permanent production logging.

`tools/runtime/rc6-contracts.cjs` verifies 18 backend, atomicity, fail-open, cleanup, dedupe, heartbeat, narrow-update, retention, cardinality, and profiling contracts. Its 100,000-request model confirms one logical bucket remains one row, accepts only its configured allowance, and resets after the window. RC3 through RC5 regressions remain green and all nine JavaScript modules parse. PHP lint was intentionally not run. Actual Redis/Memcached atomic behavior, no-cache database latency/contention, cron execution, and long-running retention remain RC13/RC14 host evidence.

### RC7 PWA, Push, and Worker Efficiency (`0.5.18`)

Push subscription renewal now has a capability narrow enough for a worker that has no access to WordPress nonces, PhoneKey state, or page local storage. An identity-owned subscription save returns a random renewal token; only its salted hash is stored beside the encrypted endpoint and browser keys. The page transfers the token and issuing endpoint to a private service-worker cache. `pushsubscriptionchange` must present both the old endpoint and its token. The server compares both in constant time, updates the row to the new encrypted subscription while preserving its user/visitor owner, and rotates the token. A stale client token receives one recovery attempt through the ordinary current identity contract, never through anonymous renewal authority. Explicit unsubscribe removes both page and worker capability state.

The service worker is now deliberately narrower. It no longer intercepts and caches every same-origin image, which could retain avatars or transactional media reached on private pages. Only media hints returned by the server-approved public editorial contract are eligible. Shell assets match normalized same-origin pathname plus the packaged version instead of fragile full-URL equality. Cart, checkout, account, login, REST, order, API, cross-origin, and query-bearing navigation remain network-only; the offline contract must still declare `public-editorial-v1` and reject private/no-store responses.

Offline editorial enrichment is an off-by-default pilot and no longer performs its extra REST fetch on every eligible navigation. Cached contracts carry a deterministic fetch timestamp and remain fresh for 15 minutes. Browser or request Save-Data disables editorial refresh and media downloads. Editorial and media caches retain their 30/40-entry caps and 3 MB media ceiling. Notification clicks are constrained to same-origin destinations before focusing or opening a window.

Push campaign delivery remains shared-host safe in five-device cron batches, now protected by an expiring per-job option lock so overlapping WP-Cron runners cannot duplicate a batch. Daily cleanup removes expired/re-enrollment rows and stale campaign jobs; push-service 404/410 responses expire subscriptions immediately. VAPID key identity, OpenSSL P-256/AES-GCM support, table state, cleanup scheduling, disabled WP-Cron, last success/attempt, re-enrollment count, and queued jobs remain visible in readiness diagnostics.

`tools/pwa/rc7-contracts.cjs` verifies 23 renewal, ownership, rotation, unsubscribe, cache-scope, Save-Data, freshness, route-policy, asset-version, cache-bound, click-origin, delivery-lock, stale-job, vendor-expiry, and diagnostics contracts. It extracts and independently parses the generated service-worker body, while all nine shipped JavaScript modules parse separately. PHP lint was intentionally not run. Chrome/Firefox/Safari renewal events, VAPID delivery vendors, permission denial/revocation, iOS installed-app Push, WP-Cron timing, and subscription expiry remain RC13/RC14 live evidence.

### RC8 Runtime Boundaries and Bootstrap (`0.5.19`)

Kiwe boot now has one explicit loader/package release boundary. The root MU loader and package expose matching versions and stop Kiwe without stopping WordPress when they differ. Package integrity is described by `Runtime\\Package_Manifest`: one cheap release stamp is read per request, while the complete required-file inventory is verified only after a changed upload/release or after the 12-hour proof expires. Missing files are recorded in Kiwe diagnostics and disable the package for that request; they no longer raise a package-owned runtime exception. This preserves fail-open recovery while removing dozens of normal-request filesystem probes.

Settings safety migrations no longer run inside object construction. `Plugin::register_services()` invokes the idempotent migration lifecycle before the first settings read, preserving the idle-Home and SecureTrack auto-logout safety corrections without hidden constructor writes. Autoloading is now Linux-exact: the namespace/file inventory has no casing mismatches, directory glob fallback is gone, and an unexpected class miss is logged at most once per request.

The WordPress frontend admin bar is no longer suppressed globally merely because the MU plugin loaded. `Kiwe > Dock` owns an explicit setting, enabled by default to preserve the current product decision, and the filter applies only when DSA itself is enabled and the current frontend route is eligible for the shell. Admin, REST, excluded, and non-DSA contexts retain ordinary WordPress behavior.

PhoneKey and SecureTrack remain integrated modules for 1.0. Their legacy procedural cores are not being rewritten under release pressure. Instead, `tools/runtime/rc8-contracts.cjs` freezes every current direct `pk_*`/`stp_*` consumer outside those cores. A new consumer fails the contract, forcing new runtime work through the PhoneKey/SecureTrack adapter boundary. Existing allowlisted debt can now be reduced incrementally without silently expanding. The same suite verifies version synchronization, cached fail-open verification, explicit migrations, exact class paths, scan-free autoloading, and shell-gated admin-bar behavior (11/11). Partial upload, option-store failure, and rollback behavior still require RC12/RC14 staging drills.

### Theme Presentation Without Surface Forks (`0.5.39`)

The failed external style-preset experiment is superseded. `Kiwe > Theme` now owns only the presentation shell: Classic Surface or Sheet presentation, sheet side, sheet backdrop, sheet animation, animation duration, sheet height, and shared DSA active/hover/hero runtime colors. The removed presets (`neumorphic`, `glassmorphism`, `bold-vibrant`, and `minimal-clean`) are not part of the 1.0 runtime because they tried to make appearance own geometry and module behavior.

The canonical renderer, geometry variables, dock/context ownership, panel lifecycle, module registry, transition state, Home journey, and accessibility semantics remain singular. Classic Surface keeps the original full-screen DSA screen contract. Sheet presentation uses the same module data, REST endpoints, PhoneKey, cart, search, AI, and notification logic, but renders the module panel as a bottom/right/left sheet with a handle, configured backdrop, and dock/context reserve. The bottom sheet paints its dock reserve with the same `--dsa-ui-surface` token so a white strip cannot leak from Classic screen background settings.

Runtime ownership is deliberately split:

- `Kiwe > Surface` owns whether the Appsite shell renders plus high-level build/readiness status.
- `Kiwe > Theme` owns Classic-vs-Sheet presentation and DSA runtime colors.
- `Kiwe > Dock` owns icon order, enabled destinations, orientation, placement, material, admin-bar visibility, and profile/auth visibility.
- `Kiwe > Menu` owns WordPress menu sources, custom menu items, dashboard utility, and contextual table-of-contents behavior.
- `Kiwe > Search` owns Search families, context awareness, alphabet index, quick-add, and Bricks search bridge behavior.
- `Kiwe > App` owns install/adoption and notification journeys.
- `Kiwe > Developer` owns diagnostics, runtime cache cleanup, S18 generated-asset pilot controls, export/reset, and unfinished architecture gates.

The old fragment navigation toggle and surface-width field are no longer site-owner controls. The original fragment renderer is deleted and remains hard-disabled. Its controlled S13-S16 editorial successor is implemented behind a Developer gate, but cannot become a production default until live lifecycle, fallback, cache, advertising, SEO, and accessibility evidence passes. The legacy `surface_width` value is retained only as a Phantom Viewport fallback for old integrations; Responsive Geometry Engine tokens are the production layout source of truth.

### RC9A Lazy Commerce Presentation (`0.5.21`)

The Surface shell must stay present because it owns continuity, but every destination's HTML factory does not need to arrive on every page. RC9 extends the existing Profile presentation loader instead of adding another module system. Cart and Checkout now advertise the same `commerce-panels.js` URL in the public presentation map. Opening either destination first renders the canonical loading panel, imports the shared module once through the browser module cache, replaces the placeholder through the normal Surface lifecycle, restores the context rail, and binds the existing core event handlers.

This is a presentation boundary, not a commerce authority migration. Cart state, queued mutations, mutation proof, nonce acquisition, Woo fragment reconciliation, FBT/Add & Save actions, discount state, checkout contract fetch/save/validation, correction focus, route decisions, and full navigation remain in `surface.js` and the server. The lazy module receives a plain snapshot and returns escaped canonical HTML. It has no `window`, `document`, fetch, storage, REST, or Woo mutation access.

Eight heavy Cart/Checkout render helpers left the persistent shell. Its raw payload moved from 375,443 to 360,999 bytes; the current shell is 78,319 gzip. The shared module is 16,122 raw / 3,814 gzip and is paid only by sessions opening Cart or Checkout. `tools/ui-contract/rc9-commerce-lazy.cjs` verifies 11 module-map, delegation, hydration, payload-budget, purity, server-authority, escaping, Cart semantic, and Checkout form contracts. RC9B still owns Links and eligible AI presentation extraction plus the complete marketplace accessibility and responsive-geometry fixture matrix.

### RC9B Lazy Links and AI Presentation (`0.5.22`)

Links and AI now use the same first-use presentation boundary rather than adding destination-specific loaders. `links-panel.js` owns escaped Links view/editor markup, social glyphs, posts, review, health, and commerce-action presentation. `ai-panel.js` owns escaped panel, inbox-card, empty-state, and deterministic report markup. Neither module can access browser globals, storage, transport, or mutations. Links persistence/uploads and AI queue arbitration, read state, actions, notification popouts, timing, sound, and server workflows remain resident in the Surface shell.

The current audited shell after the 0.5.50 corrective pass is 348,280 raw and remains below the 350,000-byte RC9B ceiling. Links is 10,537 raw, AI is 5,346 raw, and `surface-panels.js` carries Menu, Saved, Games panel, notifications, iOS install, and dynamic Appsite Home markup; these costs are paid only on first open and then browser-module cached. `tools/ui-contract/rc9-presentation-lazy.cjs` enforces 13 mapping, manifest, delegation, payload, purity, authority, escaping, and semantic fixture contracts. The canonical marketplace harness renders 71 phone, resized-desktop, tablet, desktop, light/dark, horizontal/vertical variants and reports zero accessibility or geometry failures. RC9 is code-complete; live screen-reader, zoom, browser safe-area, third-party theme, and device evidence remains part of release certification rather than inferred from source checks.

### RC10 SEO, Advertising, and Cache Compatibility (`0.5.23`)

DSA remains an additive app shell over WordPress SSR. It does not become canonical, title, indexability, product-schema, or consent authority. Feed, robots, trackback, embed, preview, favicon, Customizer, REST, JSON, XML-RPC, AJAX, cron, admin, and builder requests do not receive frontend shell output. The full-screen first-session Home journey is now off by default for new installs and produces a readiness warning when deliberately enabled; existing configured sites retain their choice. Home is hidden without JavaScript, contributes no second H1, and both Home and Surface UI are marked `data-nosnippet` so interstitial copy is not mistaken for page content.

Schema/GEO now emits only for public, indexable requests. Canonical IDs prefer WordPress canonical URLs, tracking parameters are removed, and non-singular cache identity uses the last public content modification rather than the current clock. This closes a transient-churn bug where archive keys changed every request. DSA defers its graph when Yoast, Rank Math, AIOSEO, SEOPress, or The SEO Framework is active, and suppresses its Product graph when WooCommerce structured data owns that page.

Cross-document View Transition CSS is emitted only when the server classifies the current document as editorial. Protected, commerce, search, preview, 404, and excluded routes stay ordinary full-document navigation. AdSense containers and common consent-banner containers are navigation exclusions; experimental morphing remains off by default. `tools/compatibility/rc10-contracts.cjs` verifies 18 eligibility, no-JS, heading, snippet, schema-provider, canonical/cache, transition, advertising/consent, navigation, hydration, and authority boundaries. Real crawler rendering, Rich Results, AdSense policy, consent vendors, CDN/cache plugins, and Core Web Vitals remain certification evidence, not source-level claims.

### RC11 WordPress 7 Native Adapters (`0.5.24`)

Kiwe now uses the WordPress Abilities API as a real discovery surface rather than an availability badge. `Abilities_Service` registers the `kiwe-appsite` category on `wp_abilities_api_categories_init` and registers `dsa/audit-trust` plus `dsa/summarize-route` on `wp_abilities_api_init`. Both are administrator-only, readonly, schema-validated, and exposed through the core Abilities REST surface. Trust output is deterministic and flattened; route output contains only canonical route identity, post ID, element count, registry source, and a capped type/count list. Raw settings, element content, visitor state, secrets, security events, and mutation callbacks are excluded.

The existing AI, app-adoption, and public-display event islands now use one WordPress script module importing `@wordpress/interactivity` when the native functions exist. It updates the server-seeded `kiwe/ai`, `kiwe/app`, and `kiwe/data` stores from the same canonical Surface events and mirrors the established fallback events for compatibility. It does not render the dock, fetch data, read storage, or own actions. Older or partial hosts enqueue the existing three classic bridges instead, so native support is acceleration rather than a boot dependency.

This batch deliberately does not register write abilities, execute the WP AI Client, replace Kiwe admin screens with DataViews, use reflection for REST, or move checkout, PhoneKey, SecureTrack, Push, rewards, and commerce authority into Interactivity. `tools/wp7/rc11-contracts.cjs` enforces 16 hook, category, schema, permission, bounded-output, no-write, module/fallback, event, binding, explicit-REST, and deterministic-authority contracts. Live WordPress 7 Ability discovery/execution and script-module loading remain RC13/RC14 evidence.

### RC12 Quality and Package Engineering (`0.5.25`)

The deployable release is now a reproducible unit rather than an assumed folder copy. `tools/release/build-package-manifest.cjs` walks the canonical nested package, sorts every production path, and writes byte length plus SHA-256 for all 126 files. `Runtime\\Package_Manifest` validates the manifest schema and matching package version, rejects unsafe relative paths, and distinguishes missing files from changed files. The full inventory is read and hashed only after the manifest stamp changes or the cached 12-hour proof expires; normal requests retain the cheap stamp check established by RC8. A failed proof disables Kiwe for that request, records bounded diagnostics, and leaves WordPress available.

The root loader and nested entry point remain one indivisible release pair. The repository now has a production-oriented README, changelog, editor/export rules, an explicit PHP 8.2-8.4 and WordPress 7 compatibility declaration, and a release runbook covering quiet-window uploads, partial-upload drills, smoke tests, non-destructive rollback, and the limits of source evidence. CI declares PHP 8.2, 8.3, and 8.4 lanes, verifies package composition and all established static source contracts, checks JavaScript syntax, and installs the Playwright UI harness explicitly. It does not pretend that a public CI runner can certify private Bricks builds, gateways, SMTP, proxies, browsers, Redis, or Hostinger behavior.

`tools/release/rc12-contracts.cjs` enforces 16 synchronization, runtime-integrity, deployment, rollback, compatibility, CI, and changelog contracts. The generated package verifies all 126 files. Local PHP lint remains paused under the standing project instruction. RC13 and RC14 own executed WP/Woo/Bricks, incomplete-upload, rollback, live-device, vendor, cache, crawler, and production-host evidence.

### RC12 Hotfix (`0.5.26`)

Bootstrap data now includes a REST nonce, and PhoneKey falls back to the shared nonce before retrying once. This protects the first user action when the thin Surface shell opens before runtime hydration finishes.

Same-site REST checks now accept the current request host as well as `home_url()`. This keeps Hostinger temporary domains, staging domains, and www/non-www testing from falsely blocking hydration, cart, search, PhoneKey, and notification endpoints while still rejecting cross-site mutation attempts.

### RC12 Hotfix (`0.5.27`)

Critical first-paint state is restored to the boot payload: PhoneKey identity/config, account links, cart payload, protected-flow context, and commerce context. Runtime hydration remains valuable as a no-store refresh path, but DSA no longer depends on hydration as the only source of live visitor state. This restores the pre-thin-shell behavior expected on new installs while keeping presentation modules lazy.

### Cache-Safe Boot Correction (`0.5.50`)

The `0.5.27` first-paint rollback is historical rather than the current production contract. The current boot payload keeps REST nonce, PhoneKey identity, cart/profile summaries, protected-flow state, and personalized commerce decisions out of cacheable HTML. It carries only neutral PhoneKey shape, neutral protected-flow state, and public commerce availability/routes/settings/current-page context. Live nonce, cart, protected-flow, admin authority, and personalized commerce state arrive from private no-store runtime hydration.

### RC Admin Ownership Cleanup (`0.5.39`)

The admin information architecture now follows runtime ownership instead of historical build order. `Kiwe > Surface` no longer presents fragment navigation, surface width, dock presentation, or diagnostics as normal production settings. Those controls either moved to the owning page or to `Kiwe > Developer` as explicit gated architecture status. This prevents a site owner from believing disabled fragment/morph work, S18 generated delivery, or Phantom Viewport fallback dimensions are production configuration.

`Kiwe > Developer` now carries runtime recovery, diagnostics, S18 Asset Build status/queue action, export/reset, and architecture gates. `Kiwe > Dock` owns all dock geometry/material/visibility settings. `Kiwe > Theme` owns Classic versus Sheet presentation and shared DSA runtime colors. Sheet mode uses the same module contracts as Classic and reserves dock/context space inside one continuous Sheet panel so neither the page nor a detached footer layer can show through behind the dock.

### Dock Geometry Simplification (`0.5.40`)

The Classic full-axis dock rail is removed from the production admin surface. It created a second visual contract where the dock became a tall or wide rail instead of a compact Appsite control, which broke the responsive geometry promise and made Sheet/Classic behavior appear to bleed into each other. `Kiwe > Dock` now keeps both the dock and dock context on the compact material contract. Old saved `fill_axis` values are ignored by the renderer and sanitized back to `false` on save.

### Sheet Continuity And Navigation Status (`0.5.41`)

Bottom Sheet presentation is one contiguous material from its rounded top edge to the safe-area edge. Dock and context reserve live inside the Sheet panel; no detached tray or footer slab is rendered. The admin readiness report now uses the same navigation language as this architecture: legacy fragment navigation was removed and is hard-disabled, production uses full-document navigation with the transition Surface, and the separate S13-S16 controlled editorial morph pipeline remains off by default pending the real-site compatibility matrix. Architecture implementation is complete; broad morph enablement is not production-certified.

Full-axis rails are not deleted as a design idea forever; they are rejected from the 1.0 production contract. If they return, they must return as a complete theme with its own geometry, accessibility, safe-area, dock-context, and marketplace-conformance proof rather than as a checkbox inside the Classic dock controls.

---

## Summary

DSA is not a plugin feature. It is a **vertical slice through the stack** — MU-plugin boot, Bricks render hooks, REST contracts, one Appsite Surface that owns continuity during each document lifetime, modes/modules/triggers, trust and identity engines, and WordPress 7 native-interactivity rails — engineered so a shared host can serve an experience that feels like installing an app. APEX evolution extends that continuity across approved routes without pretending the current full-navigation shell already survives document replacement.

The audience grab comes from **interactivity done in interstices**: games while navigating, trust while profiling, notifications while browsing, POS while selling — never by fighting the page for attention.

That is the system: **fast where SEO demands it, alive everywhere else.**

---

*Kiwe / DSA · Architecture reference · Align with `DEVELOPMENT-PLAN.md`, `BRICKS-INTEGRATION.md`, `SECURITY-AUDIT.md`*

External WordPress/WooCommerce/Bricks reviewers should use `PLATFORM-INTEGRATION-AUDIT-BRIEF.md`. Page-generation systems targeting Bricks conversion should use `AI-PAGE-GENERATION-PROMPT.md`.

## Owner Pages And Dock Presentation (`0.5.42`)

The old Surface admin page was a construction-era umbrella and is no longer a user-facing menu. Runtime ownership is reflected directly in the admin information architecture:

- `App`: shell enablement, Home, PWA, adoption, and permission journeys.
- `Dock`: module order/visibility plus compact Dock or edge-attached Navigation bar geometry.
- `Theme`: Classic-only styling, Sheet-only styling, shared semantic colors, and transition presentation.
- `Games`, `Links`, `Search`, and `Menu`: their own content and behavior settings.
- `Developer`: diagnostics, runtime recovery, readiness, architecture gates, portable configuration, and builder attributes.

Navigation bar is a named geometry presentation, not an ambiguous style checkbox: horizontal bars use `100dvw`, vertical bars use the usable `100dvh`, the selected viewport edge has zero external gap, safe-area insets are internal padding, and the cross-axis reserve remains token-driven. Compact Dock behavior is unchanged. The renderer emits one presentation class and device-specific edge classes; no site-specific calculation or theme leakage is allowed.

Classic and Sheet remain presentation adapters over the same Surface lifecycle. Sheet does not receive Classic blur, glass intensity, screen material, or directional screen-motion classes. Saving settings on one owner page cannot reset another owner page's values.
