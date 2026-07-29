# Kiwe 6.61 DSA runtime dark bridge proof

## Reason

Kiwe 6.60 taught the AI/tooling accessibility lane that Bricks 2.4 frontend dark mode is proven through `data-brx-theme="dark"` / `:root[data-brx-theme="dark"]`, while Kiwe/AppShell dark mode uses `data-kiwe-theme="dark"`.

This release closes the runtime symmetry so DSA dock, sheets, screens, AppShell materials, and theme toggles directly support both attributes as first-class theme-state proof.

## Runtime behavior

- `surface.js` reads Bricks' `document.documentElement.dataset.brxTheme` before local storage and Kiwe fallback.
- `applyColorMode()` writes both:
  - `document.documentElement.dataset.kiweTheme`
  - `document.documentElement.dataset.brxTheme`
- The DSA surface element now receives both:
  - `data-kiwe-theme`
  - `data-brx-theme`
- Storage remains bridged:
  - `brx_mode`
  - `kiwe_color_mode`
- A `MutationObserver` watches Bricks' `data-brx-theme` changes and applies them back into Kiwe state.

## CSS behavior

- DSA dark-mode selectors now accept:
  - `html[data-kiwe-theme="dark"]`
  - `html[data-brx-theme="dark"]`
- Core dark surfaces affected:
  - dock;
  - dock material controls;
  - sheet/classic overlay root;
  - cart/search/profile/menu/links/saved/AI/notification panel internals;
  - solid/glass screen and dock material paths;
  - sheet panels.

## Seam / framework behavior

- Seam vocabulary now describes the bridge as:
  - Kiwe AppShell: `html[data-kiwe-theme="dark"]`
  - Bricks frontend dark mode: `html[data-brx-theme="dark"]`
- Page builders remain adapters. Bricks is the first supported adapter, not the architectural center.
- Kiwe/Seam tokens and Geometry Engine variables remain the design authority; Bricks palette CSS is a storage/runtime bridge for Bricks-built pages.

## Local validation

```text
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
php -l wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php
php -l wp-content/mu-plugins/dsa/includes/Design/Seam_Vocabulary_Schema.php
npm.cmd test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Expected:

- PHP syntax passes.
- Toolkit tests pass.
- Connector contracts pass.
- Package manifest reports version `6.61`.
- Package verifier reports `Package 6.61` with all files verified.
