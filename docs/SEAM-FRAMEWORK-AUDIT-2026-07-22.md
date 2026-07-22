# Seam / Kiwe Framework audit - 2026-07-22

Baseline: Kiwe DSA `0.6.22`

## Lead conclusion

The architecture now follows the intended Seam/Kiwe split at the important boundaries, and the runtime CSS declaration layer is now token-pure:

- Seam/Framework owns universal tokens, semantic vocabulary, class vocabulary, dynamic-binding guidance, Bricks handoff guidance, and framework profiles.
- Kiwe AppShell/DSA owns runtime capabilities, dock/sheet/screen geometry, live module roots, cart/search/profile/links data, and WordPress/WooCommerce/Bricks authority.
- Installed themes can style appearance and carry token/settings overlays, but validators reject protected AppShell geometry and private preview-only fixture structures.
- Browser AIs can use compact contexts, Companion review, Site Graph Data, and Bricks AI intelligence instead of reading the whole repository.
- `assets/css/surface.css` now keeps raw legacy visual values in a token-authority bridge while runtime selectors consume variables.
- `assets/css/bricks-studio-ai.css` now consumes Seam/Kiwe tokens for the front-end editor Companion UI.
- `tools/ui-theme/audit-runtime-token-purity.cjs` fails future runtime declarations that own raw colors, spacing, sizing, radii, shadows, blur, timing, or viewport values.

Important boundary: browser CSS still requires literal values in `@media` / `@container` query syntax. Those wrappers are not treated as component-owned design declarations. The declarations inside them are tokenized. A future Geometry Engine cleanup can migrate older query wrappers onto runtime layout attributes where safe, but that is separate from runtime token purity.

## Audits run

- `node tools/ui-theme/audit-seam-adoption.cjs`
  - Passed.
  - Notes: shadow-only `seam-*` selectors remain protected for AppShell isolation, not core Seam visual styling.
- `node tools/ui-theme/audit-runtime-token-purity.cjs`
  - Passed.
  - `DSA Surface runtime`: 4,187 runtime declarations checked, 793 authority tokens allowed.
  - `Kiwe Bricks Studio AI runtime`: 95 runtime declarations checked, 29 authority tokens allowed.
- `node tools/ui-theme/validate-package.cjs tools/ui-theme/fixtures/valid`
  - Passed.
- `node kiwe-ai-toolkit/tools/validate-framework-profile.cjs kiwe-ai-toolkit/fixtures/framework-profile-valid`
  - Passed with 8 overrides and 109 known official tokens.
- `node kiwe-ai-toolkit/tools/validate-framework-profile.cjs kiwe-ai-toolkit/fixtures/framework-profile-invalid`
  - Failed as expected.
  - Caught root CSS in framework profile, forbidden dock settings, CSS-variable token name, and unknown private token.
- `node tools/ui-theme/validate-package.cjs tools/ui-theme/fixtures/invalid-dock-geometry`
  - Failed as expected.
  - Caught dock arrangement/measurement in theme CSS.
- Release/runtime/AI connector contracts passed after the fixes.

## Fixes made from this audit

- Search alphabet chips:
  - Added `--dsa-search-chip-size`.
  - Added `--dsa-search-chip-gap`.
  - Removed the manual `margin-left` offset from `.dsa-search-prefix span`.
  - Kept alphabet letters centered through layout instead of a nudge.
- Sheet handle:
  - Already tokenized in `0.6.20` through `--dsa-sheet-chrome-inset-block-start`, `--dsa-sheet-grabber-hit-size`, `--dsa-sheet-grabber-bar-inline-size`, and `--dsa-sheet-grabber-bar-block-size`.
- Dock placement:
  - Added `--dsa-dock-edge-offset`.
  - Added `--dsa-dock-mobile-inline-edge-offset`.
  - Added `--dsa-dock-mobile-block-end-offset`.
  - Added `--dsa-dock-context-gap`.
  - Added `--dsa-dock-context-padding`.
  - Replaced repeated desktop/mobile dock edge values with geometry tokens.
- Runtime token purity:
  - Added the `DSA runtime primitive bridge` in `assets/css/surface.css`.
  - Moved 1,745 legacy runtime declarations from raw values to variable consumption.
  - Preserved the exact visual output by storing the old raw values in token authority, not in component selectors.
  - Added a permanent runtime purity validator.
- Bricks Studio AI:
  - Replaced the floating editor Companion UI's raw colors, spacing, sizing, radii, shadows, and font choices with Seam/Kiwe variables.

## Token-hygiene result

Strict scan target: runtime declarations in `assets/css/surface.css` and `assets/css/bricks-studio-ai.css`, excluding token-authority custom property definitions.

Current runtime declaration candidate count: `0`.

Allowed token authority:

- `:root` in `surface.css`, including the generated runtime primitive bridge.
- `html[data-kiwe-theme="dark"]` dark token overrides.
- `.kiwe-bricks-studio` component token definitions for the front-end editor AI companion.

Payload impact: `surface.css` increased by about 60 KB raw and about 5.5 KB gzip. This preserves current staging visuals while making every runtime declaration token-overridable.

## Acceptance boundary for testing

Proceed with the next testing phase with this understanding:

- Framework/theme/AI handoff boundaries are enforceable.
- Importable themes are protected from owning AppShell geometry.
- Combined previews now must match live-like DSA structure.
- Dock/sheet/search/cart/module runtime declarations now consume variables instead of raw design values.
- The strict runtime purity validator must be part of the release gate from this point onward.

## Next hardening batch recommendation

1. Replace the remaining old `@media` / `@container` wrappers with Geometry Engine data attributes where it is safe and visually verified.
2. Gradually rename high-traffic generated primitive tokens into human semantic tokens as specific modules are redesigned.
3. Keep the primitive bridge until every legacy module has a stable semantic token vocabulary; removing it too early would risk visual drift.
