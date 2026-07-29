# Kiwe 6.60 Bricks dark-mode accessibility proof

## Reason

Local Bricks 2.4 beta source verification showed that Bricks frontend dark mode is not only an editor/admin preference. Bricks sets `document.documentElement.dataset.brxTheme` on the frontend and emits dark color-palette variables under `:root[data-brx-theme="dark"]`.

Kiwe 6.59 already required Kiwe/AppShell dark proof through `data-kiwe-theme`, but `/audit /accessibility` did not yet accept Bricks' native `data-brx-theme` proof. That could cause browser AI to over-search docs or repair a Bricks-native artifact with only Kiwe selectors.

## Bricks source evidence checked

- `includes/setup.php`
  - `set_project_default_mode()` writes `document.documentElement.dataset.brxTheme = "dark" | "light"` using saved `brx_mode`, Bricks default mode, or `prefers-color-scheme`.
- `includes/assets.php`
  - `generate_inline_css_color_vars()` emits light palette variables at `:root`.
  - The same function emits dark palette variables at `:root[data-brx-theme="dark"]`.
- `includes/theme-styles.php`
  - Theme Styles are database-backed Bricks settings with conditions.
- `includes/theme-styles/controls/colors.php`
  - Bricks root color slots such as `colorPrimary`, `colorSecondary`, `colorLight`, `colorDark`, `colorMuted`, and `colorBorder` map to CSS at `:where(:root)`.

## Changes in 6.60

- `kiwe-ai-toolkit/contexts/accessibility-lite.md`
  - Added Bricks-native dark state to the accessibility contract.
  - Dark-mode recipe now includes `html[data-brx-theme="light|dark"]` and `:root[data-brx-theme="light|dark"]`.
  - Bricks alignment section now explains that Bricks 2.4 emits dark palette variables at `:root[data-brx-theme="dark"]`, while Kiwe/AppShell still owns `data-kiwe-theme`.
- `kiwe-ai-toolkit/command-manifest.json`
  - `/fix /accessibility` now embeds the Bricks-compatible selector pattern and a `bricksCompatibility` note.
- `kiwe-ai-toolkit/lib/accessibility-validator.js`
  - Missing-dark-proof detection now accepts `data-brx-theme="dark"`, `[data-brx-theme="dark"]`, and `:root[data-brx-theme="dark"]`.
- `includes/AI/Accessibility_Validator.php`
  - Plugin/API validator now accepts the same Bricks-native dark proof.
- `KIWE-AI.md`
  - Top-level entrypoint now states that Bricks 2.4 dark proof is `data-brx-theme="dark"` / `:root[data-brx-theme="dark"]`, in addition to Kiwe's `data-kiwe-theme`.

## Expected behavior

For Bricks-native page/template accessibility fixes:

- Use Kiwe tokens as the design authority.
- Use Bricks global variables/color palette/theme-style lanes as the Bricks-native storage/runtime bridge.
- Prove dark mode with both Kiwe selectors and Bricks selectors when the artifact is Bricks-ready.
- Do not rely on Bricks editor dark UI alone.
- Do not search or clone the repository to discover dark-mode rules; the command manifest and accessibility-lite context are sufficient.

## Local validation

```text
php -l wp-content/mu-plugins/dsa/includes/AI/Accessibility_Validator.php
npm.cmd test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Expected:

- PHP syntax passes.
- Toolkit tests pass.
- Connector contracts pass.
- Package manifest reports version `6.60`.
- Package verifier reports `Package 6.60` with all files verified.
