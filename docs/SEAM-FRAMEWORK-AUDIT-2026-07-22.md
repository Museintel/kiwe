# Seam / Kiwe Framework audit - 2026-07-22

Baseline: Kiwe DSA `0.6.21`

## Lead conclusion

The architecture now follows the intended Seam/Kiwe split at the important boundaries:

- Seam/Framework owns universal tokens, semantic vocabulary, class vocabulary, dynamic-binding guidance, Bricks handoff guidance, and framework profiles.
- Kiwe AppShell/DSA owns runtime capabilities, dock/sheet/screen geometry, live module roots, cart/search/profile/links data, and WordPress/WooCommerce/Bricks authority.
- Installed themes can style appearance and carry token/settings overlays, but validators reject protected AppShell geometry and private preview-only fixture structures.
- Browser AIs can use compact contexts, Companion review, Site Graph Data, and Bricks AI intelligence instead of reading the whole repository.

This is not yet a claim that every historical declaration inside `assets/css/surface.css` is token-pure. The core now has the right enforcement boundaries, and the highest-risk recent misses were fixed, but some older module interiors still need token hardening.

## Audits run

- `node tools/ui-theme/audit-seam-adoption.cjs`
  - Passed.
  - Notes: shadow-only `seam-*` selectors remain protected for AppShell isolation, not core Seam visual styling.
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

## Token-hygiene scan result

Focused scan target: DSA screen/sheet/dock/cart/search selectors in `assets/css/surface.css`, excluding base `:root` tokens, already-tokenized declarations, color functions, `calc()`, `clamp()`, and blur/filter effects.

Current candidate count: `239`.

Grouped candidates:

- Dock: `18`
- Search: `83`
- Screen/sheet: `22`
- Cart: `116`

The dock group dropped significantly during this audit after tokenizing edge/context placement. The remaining dock candidates are mostly:

- shape radius sentinel values such as `999px`;
- compact dock-context mini UI sizes;
- icon dot sizes;
- border-width sentinel values.

The search/cart groups are mostly older module-interior sizing and spacing for forms, product cards, quantity controls, result cards, FBT cards, and typography. These are real token-hardening debt, but not safe to blanket-replace right before live testing because they affect dense WooCommerce/search layouts.

## Acceptance boundary for testing

Proceed with the next testing phase only with this understanding:

- Framework/theme/AI handoff boundaries are enforceable.
- Importable themes are protected from owning AppShell geometry.
- Combined previews now must match live-like DSA structure.
- Dock/sheet chrome has been corrected for the recent issues.
- Remaining token-hardening work is known and tracked; it should be done in targeted batches, especially Search and Cart interiors, not as a risky global find/replace.

## Next hardening batch recommendation

1. Search module token pass:
   - field height/padding/gaps;
   - glyph sizes;
   - filter chips;
   - result card spacing;
   - keyboard/viewport responsive behavior.
2. Cart module token pass:
   - quantity controls;
   - FBT rail card dimensions;
   - checkout/sticky action spacing;
   - trust badge chips.
3. Dock-context mini UI pass:
   - compact icon sizes;
   - health/action pill spacing;
   - context popout minimums.
