# Kiwe Marketplace Theme Package Rules

This file defines the first portable package boundary for Kiwe DSA visual themes.

A Kiwe theme package is presentation. It is not a WordPress plugin, not a WooCommerce extension, not a PhoneKey provider, not a Bricks template importer, and not a service-worker bundle.

## Package shape

Recommended folder:

```text
theme-id/
  theme-package.json
  theme.json
  css/
    theme.css
  assets/
    optional.svg
  README.md
```

`theme-package.json` is the single Kiwe admin/API import/export file. It contains the manifest, safe settings preset, and inline CSS so imported themes appear under `Kiwe > Theme > Installed themes`.

`theme.json` remains a manifest-only validator file and must follow `theme-manifest.schema.json`. Do not put settings into `theme.json`.

Example:

```json
{
  "schema": "kiwe.surface-theme.v1",
  "id": "studio.account-cards",
  "name": "Account Cards",
  "version": "1.0.0",
  "profile": "marketplace",
  "mode": "css-only",
  "description": "Card-led account and commerce styling for Kiwe DSA.",
  "author": "Studio",
  "css": ["css/theme.css"],
  "assets": ["assets/badge.svg"],
  "screens": ["profile", "cart", "search", "menu", "saved", "links", "notifications", "ios-install", "ai"],
  "requires": {
    "uiContract": "kiwe.surface-ui.v2",
    "tokenContract": "kiwe.universal",
    "minKiwe": "0.5.73"
  },
  "supports": ["light", "dark", "sheet", "classic", "dock", "split-dock", "full-dock", "navigation-bar", "dock-shape-pill", "dock-shape-box", "dock-shape-square", "horizontal", "vertical", "reduced-motion"],
  "budgets": {
    "cssKb": 40,
    "jsKb": 0,
    "blockingAssets": 0
  },
  "forbidden": ["remote-code", "trackers", "php", "service-worker", "history-owner", "cart-owner", "checkout-owner", "phonekey-owner", "bricks-owner"]
}
```

Minimal `theme-package.json` wrapper:

```json
{
  "type": "kiwe-theme-package",
  "schema": "kiwe.theme-package.v1",
  "schemaVersion": 1,
  "theme": {
    "schema": "kiwe.surface-theme.v1",
    "id": "studio.account-cards",
    "name": "Account Cards",
    "version": "1.0.0",
    "profile": "marketplace",
    "mode": "css-only",
    "css": ["css/theme.css"],
    "assets": [],
    "screens": ["profile", "cart", "checkout", "search", "menu", "saved", "links", "notifications", "ios-install", "games", "ai"],
    "requires": { "uiContract": "kiwe.surface-ui.v2", "tokenContract": "kiwe.universal", "minKiwe": "0.5.84" },
    "supports": ["light", "dark", "sheet", "classic", "dock", "split-dock", "full-dock", "navigation-bar", "dock-shape-pill", "dock-shape-box", "dock-shape-square", "horizontal", "vertical", "reduced-motion"],
    "budgets": { "cssKb": 40, "jsKb": 0, "blockingAssets": 0 },
    "forbidden": ["remote-code", "trackers", "php", "service-worker", "history-owner", "cart-owner", "checkout-owner", "phonekey-owner", "bricks-owner"]
  },
  "settings": {
    "style": { "active_theme_id": "studio.account-cards", "visual_profile": "kiwe2027" },
    "dock": { "presentation": "dock", "split_style": true, "shape": "pill", "focus_item": "ai" },
    "screens": {
      "cart": {
        "label": "Bag",
        "title": "Your tea-time bag",
        "fbtTitle": "Pairs well with",
        "checkoutLabel": "Checkout"
      }
    }
  },
  "css": "/* same presentation CSS as css/theme.css */"
}
```

## Import rule

An imported theme may add scoped CSS, static local image assets, and a safe theme settings preset for `style`, `dock`, `dsa_theme`, `visual_effects`, and `screens`.

`settings.screens` is presentation/copy only. Current cart copy keys include `label`, `eyebrow`, `title`, `emptyTitle`, `emptyText`, `fbtTitle`, `checkoutLabel`, and `checkoutEmptyLabel`. Themes must not put product IDs, prices, totals, checkout URLs, JavaScript, endpoints, cart state, or checkout/payment authority in screen settings.

An imported theme must not add PHP, visitor-facing JavaScript, remote assets, tracking pixels, fonts, REST routes, service workers, arbitrary WordPress options, WooCommerce hooks, Bricks templates, dynamic tags, or database tables.

If a future theme needs htmx or Alpine, it must be approved as a Kiwe-owned runtime feature first. Marketplace packages do not import their own htmx/Alpine runtime.

## Export rule

A theme export may include:

- `theme-package.json` as the single re-importable artifact
- `theme.json`
- CSS files listed by `theme.json`
- static image assets listed by `theme.json`
- a human README

A theme export must not include:

- user data
- orders, carts, coupons, addresses, profile data, notification preferences, Push subscriptions, or PhoneKey state
- Bricks post meta or site-specific generated Bricks element IDs
- SecureTrack logs or settings
- service-worker files or PWA manifests
- package-manifest hashes from the plugin release

## Acceptance checks

Reject a package when any of these are true:

- It contains PHP, executable visitor JavaScript, remote scripts, remote stylesheets, remote fonts, trackers, or analytics beacons.
- It changes or removes required `data-dsa-*` selectors from `screen-payloads.json`.
- It creates another cart, checkout, auth, profile, notification, Search, Bricks, service-worker, history, focus, or geometry authority.
- It hardcodes per-site Bricks IDs, post IDs, product IDs, user IDs, order IDs, URLs, or filesystem paths.
- It uses viewport magic numbers instead of Geometry Engine attributes and CSS variables.
- It hides required close/focus controls, fails keyboard navigation, ignores reduced motion, or places panel content behind the dock.
- It needs a package version mismatch, manifest drift, or incomplete-upload bypass to work.

## Runtime bridge

Themes may read `window.DSA.ui` when present:

- `contract`
- `adapterVersion`
- `visualProfile`
- `adapterScreens`
- `colorModel.active`
- `colorModel.hover`
- `getVisualProfile()`
- `withProfile(payload)`
- `seam.landmarks(scope?, filters?)`
- `seam.describe(element)`
- `seam.adoption(role?)`
- `seam.activePanel()`

This bridge is informational and presentational. It does not grant authority to mutate cart, account, checkout, PhoneKey, notifications, Search, service-worker, history, Bricks, or geometry state.

Use `window.Seam.landmarks()` for platform/page-level Seam discovery. Use `window.DSA.ui.seam.landmarks()` when reviewing the active Kiwe AppShell panel. Landmark filters may include `role`, `flow`, `tone`, `scene`, `state`, `slot`, `surfacePanel`, `root`, and `authority`.

Use `window.Seam.adoption(role)` or `window.DSA.ui.seam.adoption(role)` before assuming a public Seam class is safe inside live Kiwe sheets/screens. Public WordPress/Bricks page sections may use the normal Seam vocabulary, but DSA internals follow the `appShellAdoption` map from `seam-vocabulary.json`.

Example:

```js
window.DSA.ui.seam.landmarks(null, { surfacePanel: 'cart', role: 'card' });
window.DSA.ui.seam.landmarks(null, { slot: 'fbt-rail' });
window.DSA.ui.seam.adoption('button'); // shadow-only until visual parity is proven.
```

Do not use these helpers to mutate state. They are for inspection, preview tooling, tests, and theme-review diagnostics.

## Designer handoff

Give a designer or AI assistant only this `ui-system/` folder when the goal is a new Kiwe visual theme. They should produce:

1. A design direction.
2. A `theme.json` proposal.
3. Scoped CSS using `token-map.css`.
4. Screen arrangements that preserve `screen-payloads.json` selectors.
5. Notes for any desired core capability that does not already exist.

If the design requires new data, new actions, or new authority, that is not a theme change. It becomes a Kiwe core feature proposal.
