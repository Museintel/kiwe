# Kiwe Apps: small core and independently installed capabilities

Date: 23 October 2026, Asia/Kolkata. **Status: proposed architecture and working batch forecast. No implementation, package split, hosting publication, site installation or update-policy change is authorized or accepted by this planning turn.**

24 October implementation boundary: the founder subsequently authorizes **bundled local App switches** before physical package distribution. SEO is isolated and the complete Core authoring flow is accepted on RC425; [Store conditional runtime and connected UI](../STORE-APP-RC427-2026-10-24.md) are accepted on RC427 on the lab. This supersedes the original forecast for those local-engine outcomes, not its future remote installer/updater proposal. Neither production migration nor Ascendants/provider replacement is accepted by the lab result.

## Problem and intended outcome

The founder proposes a main Kiwe Apps area with installable Ecommerce and SEO capabilities, packages served from Kiwe Launch, independent automatic updates, and future POS, LMS, Membership, Bookings and Table Management apps. Paid apps should share WooCommerce for orders, payments and checkout. Clients should install what they need with minimal decisions; unrelated sites should not execute or download unused app functionality.

The current implementation confirms a real boundary problem. `includes/Plugin.php` constructs cart, store analytics, linked products, abandoned carts, checkout, commerce context and order-notification services before its later registration conditions. PhoneKey, Search, Saved, Notifications, public Assets and Runtime Hydration have commerce constructor dependencies. `Modules/Module_Registry.php` is a visitor-screen registry, not an installable package catalogue. Splitting directories alone would not remove those dependencies or their request cost.

The [Ascendants incident](../ASCENDANTS-503-INCIDENT-2026-10-23.md) identifies concrete old-build cart/Saved startup requests and public Admin loading alongside shared resource saturation. The Apps programme must not postpone a scoped correction of those known problems or be sold as proof that a hosting incident is resolved.

## Proposed boundaries

| Owner | Responsibility |
| --- | --- |
| Kiwe Core | App shell/Home/Dock/shared Sheets, Seam tokens/attributes/icons, native account/role/security boundaries, Design Context and SiteGraph, shared inbox/operation contracts, common native content/media/builder integration and the small Apps manager. Core services remain lazy by route/task. |
| Kiwe Ecommerce app | Kiwe's Woo integrations: product/offer/cart/checkout behavior, orders, saved-cart capture/journey, store reports, commerce-specific notifications and Bricks/dynamic bindings. Woo remains transaction/catalog/tax/shipping authority. |
| Kiwe SEO app | The existing Kiwe SEO metadata/canonicals/schema/redirects, ordinary and qualifying News XML, per-record SEO controls and goal-based suggestions/reports. Business identity and SEO goal remain in canonical Design Context; the app consumes them. |
| Later paid-business apps | POS, paid LMS, Membership, paid Bookings and paid Table Management use the same Ecommerce/Woo transaction authority, plus their own necessary domain state. No second order/payment engine. Free-only functionality should not require Woo solely because a paid mode exists. |
| Kiwe Launch distribution | Catalogue, documentation, immutable signed package manifests/downloads and release metadata. It is not a public-render or checkout dependency. Installed PHP still executes on the client host. |

Article bookmarks and the shared notification/account/Seam facilities remain Core capabilities. Ecommerce registers product wishlist/cart/order extensions through shared contracts; moving every Saved or Notification class wholesale into Ecommerce would break non-Woo sites. Saved-cart capture remains automatic whenever the Ecommerce/Woo capability is operating, with no new capture on/off switch. Optional external delivery keeps its existing consent rules.

SEO should be **included by default on a new site without another SEO owner**, rather than another grey card every new client must discover. Where Kiwe already owns SEO, preserve the installed SEO app automatically. Where Rank Math or another SEO provider already owns it, retain that provider and leave the Kiwe SEO engine uninstalled until a reviewed handover. Ecommerce is added for stores, with existing Woo sites adopted into the compatible installed state during migration. Do not create another feature enable switch after installation. Design Context recommends appropriate apps when activities change; a recommendation does not authorize a silent executable installation or select a payment provider.

Existing sites retain current functionality automatically during a reviewed migration. National and Dharwad already rely on Kiwe after Rank Math removal: the SEO app and Core transition must preserve metadata, indexing choices, canonicals, redirects, structured data, XML routes and public output without an intermediate loss of ownership. Ascendants retains Rank Math until its own separately accepted handover. App removal preserves business data by default; data erasure is a separate explicit native operation.

### Founder clarification: independently upgrade Ascendants before its SEO handover

The founder specifically wants the new Core to reach Ascendants while Rank Math continues serving the client's existing SEO, then install/prepare Kiwe SEO, complete the remaining SEO qualification and retire Rank Math afterward. This is a principal benefit of separate app lifecycles, distinct from eliminating unnecessary Woo requests. Core upgrades and SEO-provider replacement become independently qualified operations.

Recommended staged state: **new Kiwe Core + Rank Math, with no Kiwe SEO engine**; **new Core + Rank Math while a private Kiwe SEO handover is prepared**; **new Core + Kiwe SEO as sole output owner after an explicit qualified cutover**; **Rank Math removal only after acceptance and retained recovery**. In Apps, the initial SEO card should say Managed by Rank Math and offer a guided Switch to Kiwe SEO action. Merely installing the preparation tools must not trigger dual output or an automatic provider replacement.

The current `SEO_Defaults_Service::external_owner()/enabled()` recognizes Rank Math and guards many output paths. That is useful coexistence evidence, not complete extraction: `SEO_Context_Service::register()` still adds Rank Math robots/sitemap filters and metadata synchronization callbacks, and `Plugin.php` still constructs/registers SEO services. A real Core-only package must leave the Kiwe SEO engine's output, rewrite, import, background and mutation hooks absent. Keep only the small provider-neutral native indexing/context contract required by Core and the existing editor, with Rank Math remaining authoritative. Do not add a hidden global indexing toggle or silently change client metadata.

This pulls the SEO boundary and Core-only/Rank-Math qualification forward in the Apps work. The entire future app catalogue, POS/LMS implementation and actual Ascendants SEO handover need not finish before a separately accepted Core upgrade. The six Apps outcomes remain a working forecast, not a fixed execution order or accepted release. No Ascendants milestone is automatically closed: actual-source compatibility/recovery, editorial/account/advertising/front-end behavior and measured resource acceptance still apply. This clarification authorizes planning, not the production upload, Rank Math deactivation or retirement.

## Client journey

Add **Apps** beside the existing main dashboard groups. Each available app has a readable card, a muted uninstalled icon and an explicit state/action. Clicking opens the existing shared Sheet/modal with the outcome, dependencies and a single install action. Uninstalled cards remain keyboard-accessible; grey must not mean an unreadable or disabled control. Future unbuilt apps say Coming later and offer no fake Install action.

Example: **Install Bookings** explains that it also installs Kiwe Ecommerce and WooCommerce if absent. One user action starts a resumable dependency-ordered installation. Existing compatible dependencies and all native settings/data are reused. Show progress and useful failure/retry states rather than a fixed long request or a false success. Prefill public business settings from Design Context and ask only for genuinely missing choices. Installation is not payment activation: credentials, provider approval, tax decisions and any paid dependency remain explicit.

Apps owns installation/health, while Work/Daily/Setup retain their familiar operational tasks. Orders and saved-cart activity still appear in Daily. Store settings still have one native editing home. The existing Setup > App controls describe the site's Home/Dock/PWA experience, so clarify their label, for example App experience, rather than creating a second feature catalogue.

Only native-authorized site owners/technical administrators install or remove software. Staff use installed features according to existing roles. Do not grant Store Managers installation rights merely to make the button work. Multisite or filesystem restrictions receive an honest technical next action.

## Runtime, native installation and distribution

Keep the essential small MU loader/Core where appropriate; package optional apps as normal WordPress plugins behind the Kiwe Apps interface. WordPress advises normal plugins for optional components needing activation/deactivation and normal update notifications; MU plugins run before regular plugins and do not get the same activation/update lifecycle. [WordPress MU guidance](https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/).

Use the original WordPress installation, filesystem and activation owners. Declare Woo dependencies with the native plugin-dependency mechanism and add Kiwe's compatibility checks for Core API and app version ranges. WordPress dependency metadata is useful but is not itself a complete one-click installer, version resolver or load-order guarantee. [Plugin dependencies](https://make.wordpress.org/core/2024/03/05/introducing-plugin-dependencies-in-wordpress-6-5/).

Core currently registers on `muplugins_loaded`, before normal apps/Woo are loaded. The new contract must allow normal apps to register lightweight capabilities after dependencies load and before the relevant WordPress actions run. Break Core's eager commerce constructors using small optional-provider contracts. Preserve early essential security hooks. A missing app must yield an accurate absent capability, not a dummy business result or a fatal class reference.

The local installed-capability index is refreshed on installation/update/administration, not through remote catalogue requests or whole-package scans on every visitor hit. Uninstalled apps have no routes, assets, business constructors, scheduled work or network calls. Installed apps load only the required controllers/renderers on relevant tasks. Ordinary editorial pages on a store should not automatically pay for unrelated commerce calculations.

Use WooCommerce's official distribution for Woo; do not maintain a copied Woo fork in Kiwe packages. The dependency bundle is orchestration, not a second copy of Woo. Existing gateways and Woo data remain native. Recurring membership billing needs explicitly supported recurring-payment functionality and a compatible gateway; Woo installation alone does not establish it. [Woo's recurring-payment extension](https://woocommerce.com/document/subscriptions/) is one current implementation, not a selected purchase or mandatory architectural dependency.

Catalogue/release downloads can use Kiwe Launch's existing trusted release origin, currently `app.kiwelaunch.com`, with a client-facing Apps page on `kiwelaunch.com` if desired. Do not introduce a second distribution authority or assume a new subdomain already exists. The initial catalogue/manifests can be static files behind a CDN; they do not require a new hosted job engine. Local scheduled updates still require WordPress's existing scheduling mechanism to run reliably.

## Independent updates and recovery

Automatic updates are possible, but neither declaring a URL nor hosting a ZIP creates a safe updater. Normal plugins can use an external `Update URI` and native update hooks; [WordPress supports these external update responses](https://make.wordpress.org/core/2021/06/29/introducing-update-uri-plugin-header-in-wordpress-5-8/) and [scheduled plugin auto-updates](https://wordpress.org/documentation/article/plugins-themes-auto-updates/).

Reuse Kiwe's existing Ed25519 trust, checksums, staged verification, package rules and recovery patterns through one release coordinator. The current `Runtime/Update_Service.php` is specifically for the MU package, with automatic updates defaulting to false; it is not yet a multi-app updater, and no live update choice was changed here. Do not use two independent updaters to write the same app directory.

Each signed release declares its package identity, version, Core API compatibility, WordPress/PHP requirements, app/Woo dependencies and hashes. Validate the complete dependency plan, space/inodes and native capabilities before activation. Handle interrupted downloads/installs, incompatible dependencies, stale catalogues, revocation and a failed new bootstrap. Compatible qualified stable updates can be automatic under the owner's chosen policy; dependency changes or destructive migrations require an understandable reviewed path. Woo's own update source/policy remains distinct and compatibility-tested.

Keep immediate code recovery and forward/backward-compatible data migrations. Never restore an old order database to reverse a code update. Already installed site/account/cart/checkout functionality must keep working when Kiwe Launch is unavailable; do not perform a vendor licence heartbeat on the visitor's critical path. Use bounded existing scheduling, not a new poller or separate hosting cron per app.

## Sequence and forecast

**First close two reliability batches/gates.** These are proposed next actions, not newly accepted work or permission to deploy on Ascendants.

1. **Ascendants scoped incident mitigation:** fix demonstrated old-build non-Woo requests/public-bootstrap work on a suitable lab, preserve Rank Math/editorial/security behavior, measure the actual non-Woo path, prepare exact recovery and obtain named live approval. Request provider fault detail only if separately authorized; unavailable historic process data must not prevent correcting a demonstrated bug. Avoid resource-heavy work in the shared production pool.
2. **APP118:** close the existing queued National/Dharwad cold/personalized page-response batch and verify critical flows under comparable cache/session conditions. Reuse shared lazy-loading changes and matching evidence; do not repeat or double-count the same optimization as a separate deliverable.

Then propose **six Apps implementation/acceptance batches**:

| ID | Complete outcome and acceptance |
| --- | --- |
| AP01 | Core/app dependency inventory, small versioned capability/provider contract, correct WP boot order and route-specific loading; executable non-Woo and app-absent denial/fallback proof. Inventory all affected shared services before extraction. |
| AP02 | Native Apps dashboard, cached signed catalogue and resumable install-with-dependencies journey; official Woo reuse/install/activation, existing settings, permissions, interruption, insufficient capacity, network failure and partial completion. |
| AP03 | Independent signed app releases integrated with native update status/scheduling; compatibility resolution, verified staging, one writer, rollback, central outage and an actually served test package/update on the lab. |
| AP04 | Extract Ecommerce and all Woo-owned adapters; guest/account/cart/checkout/offers/saved-cart/order/report and Bricks behavior preserved, no Woo functionality on the non-Woo fixture. One shared native transaction authority and no optional saved-cart capture switch. |
| AP05 | Extract and include SEO by default; native metadata/goal/indexing/redirect/schema/XML/News continuity, external SEO-owner coexistence, migrations and exact frontend output. No Rank Math reinstall or removal as an incidental side effect. |
| AP06 | Existing-site migration, whole-site/performance/recovery acceptance and reviewed publication; Core+apps version sets, data preservation, real install/update/rollback, public/role UI, Start/Seam capability library and MCP discovery contracts. Named production rollout approval and task-owned cleanup remain target-specific. |

This is a **working forecast of two prerequisite reliability batches plus six Apps batches**, not a guaranteed number of work turns. If extraction reveals a boundary too large for an accepted batch, report the revised forecast with the unfinished acceptance property. All tests/recovery/publication belong within the six outcomes rather than being silently added afterward. POS/LMS/Membership/Bookings/Table Management implementation is future scope, not included in six batches or represented as already built.

The **25+2 Google/MCP forecast remains queued with zero accepted**; do not finish that whole programme before Apps. Stabilize AP01 contracts before coupling new Google/MCP implementations to them, and preferably complete the initial Apps platform before the wider programme. Its existing Design Context/readiness work can inform Apps planning; any overlapping acceptance must be recorded once and explicitly mapped, not automatically marked complete. The four deferred Ascendants migration/SEO milestones remain separate. The accepted Woo launch work is not reopened by this proposal.

## Verification and reporting boundary

Measure current versus candidate in equivalent non-Woo, Woo guest, returning-session and signed-in profiles: loaded PHP files/constructor work, queries, origin/TTFB, assets, private reads and background work. File count or ZIP size is not proof of reduced request cost or concurrency capacity. Exercise absent/inactive/broken-dependency states, native roles, mid-update recovery and actual cross-surface behavior. Preserve all native content, SEO output, order/identity state and immediate recovery.

This planning change only records a researched proposal and source findings. Runtime, package versions, active plugins, update policies, Kiwe Launch, Key, Start, Seam, Google and all live sites remain unchanged. No baseline product tests are needed for documentation-only planning. No commit or push.
