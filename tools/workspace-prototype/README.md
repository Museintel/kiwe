**Current:** [RC86 candidate native React Team, Identity, Posts, taxonomy and DSA Menu surfaces](../../docs/CATEGORIES-MENU-REACT-NATIVE-RC86-2026-09-11.md) add six private native data journeys over the approved Metro surface. The standalone app remains synthetic; the opt-in native documents use WordPress `wp-element` and existing Team, Design Context, WordPress taxonomy and menu authorities. Run the matching `build-native-*.cjs --check` scripts, release contracts, browser receipts and `node audit-coverage.cjs <external-output>` alongside the grouped UI checks when these areas change. Stable release is blocked until all launch-required ledger rows have native and browser receipts.

**Card-controls follow-up:** Pages/category cards expose indexing and menus; service categories support create/assign/rename/move; Notifications is on Home and the header bell with personal preferences. [Audit and limitations](../../docs/WORKSPACE-CARD-CONTROLS-2026-09-10.md). All edits remain in memory and reset on refresh.

# Kiwe tile workspace direction

**Current, 10 September:** The approved Metro UI now spans Home and fourteen core task areas. People cards show WordPress/WooCommerce role origin and edit team title directly; opening a user shows 35 curated profile/address fields. Identity now has a private native app for site name, tagline, logos, icon and business classification. [Current build and native boundaries](../../docs/WORKSPACE-CARD-CONTROLS-2026-09-10.md) record the earlier 137 checks; native WordPress fixture acceptance remains pending for both journeys.

A local React interaction prototype for the founder's Windows 8/Lumia-inspired navigation direction. No WordPress sidebar, permanent native admin toolbar, remote service, real credential or database connection. This source is development tooling outside the deployable MU-plugin.

Run from this directory:

```sh
npm ci
npm start
```

Open http://127.0.0.1:8774/. Override the port with KIWE_PREVIEW_PORT. The server builds into memory and serves only generated assets and its root page. Node is required for this local tool; it is not a proposed production hosting dependency. Stop the process to close the preview. The dependency lockfile pins React, React DOM and esbuild.

Explore Home, Your website, Posts, Pages, Products, Orders, Categories, Media & design library, People, Navigation, Services, Search & sharing, Store setup and Inbox. Change a category name and see its menu/content projections update. Edit a team title directly on its user card, then open the profile for WordPress/Kiwe/Woo fields. Shared sample records update Home counts. All changes reset on reload; every view remains disconnected from WordPress.

The post textarea and product forms illustrate interaction only. They do not implement Gutenberg, media upload, Woo operations, server-side permissions, autosave, provider delivery or native persistence. RC82's client product gap remains open. The visitor app composition is illustrative. No example record is customer data.

[Architecture and acceptance boundaries](../../docs/decisions/2026-09-10-tile-workspace-shell.md) own the production plan. Shared production tokens remain owned by Seam_Token_Service; preview colors are scoped design exploration. These files do not change the MU-plugin version or package manifest.

The first scoped browser pass checks local navigation, lazy task loading, unsaved changes, sample saved-state continuity, role visibility, desktop/tablet/phone layout and no external requests. These checks are not native WordPress, traffic capacity or launch certification. Actual results are recorded in the architecture handoff after the pass.


## Historical full-context inventory

The original Home tile look was approved; the subsequent grouped Identity form was rejected. [Coverage](../../docs/DESIGN-CONTEXT-WORKSPACE-COVERAGE.md) still explains native owners and field scope. Run `node audit-context.cjs <absolute output.json>` to inventory static existing native form declarations. The old `context.jsx` and `context.css` remain historical source and are not loaded by the active preview.

This sample remains separate from [RC83 native UI work](../../docs/WORKSPACE-SHELL-RC83-2026-09-10.md). Its role selector and memory-only saves never authorize or update WordPress.

## Bounded UI acceptance

Start the preview on port 8774 after source changes; it builds once when started. With Playwright and a supported browser available, run from this directory:

```sh
node check-ui.cjs /absolute/evidence-directory-outside-repository
```

If Playwright is supplied by a workspace runtime, set `KIWE_PLAYWRIGHT_MODULE` to its module directory. For an installed Chrome binary, set `KIWE_CHROME_PATH` to that executable; otherwise Playwright uses its own browser. These are development environment inputs, not plugin runtime dependencies. The check makes requests only to the loopback preview, uses synthetic records and writes source hashes/results/screenshots to the supplied directory.

The current grouped pass has 180 checks including Home/task navigation, identity/profile/title edits, shared record projections, draft handling, role omissions, four layout widths and no external requests. `check-ui.cjs` delegates to `check-full-ui.cjs`; earlier 38-check receipts remain historical. Run after relevant changes or failures rather than repeating unchanged native release suites. A pass proves local sample interactions only. Keep failed receipts when repairing a check. The source remains separate from the deployed MU package.
