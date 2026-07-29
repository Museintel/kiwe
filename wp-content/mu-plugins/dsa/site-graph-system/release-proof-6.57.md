# Kiwe 6.57 accessibility command proof

## Changes in 6.57

- Upgraded `/audit /accessibility` from a color-only lane into a launch-critical accessibility and Geometry/Seam containment lane.
- Added `/fix /accessibility` as a first-class command route. It repairs only the failed accessibility lane and must not restart creative work, reconvert to Bricks, create DSA themes, or add docs unless `/document` is present.
- Tightened the deterministic JavaScript validator to inspect:
  - literal foreground/background contrast;
  - token-resolved Bricks foreground/background pairs through `globalVariables`;
  - missing native dark-mode proof;
  - private project color variables that need an accessibility token-pair plan;
  - Bricks surfaces with backgrounds but no explicit readable foreground;
  - critical text clipping/overflow risks in titles, labels, pills, chips, buttons, tabs, prices, stats, and card text.
- Mirrored the same intent in the PHP/API accessibility validator so REST, WordPress Abilities, Companion, and browser-AI clients do not receive a weaker server-side rule set.
- Updated `KIWE-AI.md`, `workflow-lite.md`, `audit-lite.md`, and `accessibility-lite.md` so external AIs understand:
  - accessibility is post-design and artifact-scoped;
  - dark mode should use native Kiwe/Seam token state;
  - visible clipping is both an accessibility failure and a Geometry Engine / Seam contract failure when an element declares what it is;
  - browser/render proof is required for image, gradient, transparency, and responsive containment cases that static validation cannot prove.
- Added an invalid overflow fixture so future validator changes must continue rejecting clipped text.
- Fixed a Bricks AI context drift by documenting the optional review envelope path `bricks-conversion/kiwe-bricks-conversion.json` and `conversionIsReviewPackage` authority.

## Local validation

```text
php -l wp-content/mu-plugins/dsa/includes/AI/Accessibility_Validator.php
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
php -l wp-content/mu-plugins/dsa/includes/WP7/Abilities_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php
php -l wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_AI_Intelligence_Service.php
npm test
node tools/connector/ai-api-contracts.cjs
node kiwe-ai-toolkit/tools/validate-accessibility.cjs "C:/Users/shariq/Downloads/national-chikki-home-template-upload (1).json" --optional
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Expected:

- PHP syntax checks pass.
- Kiwe AI Toolkit test suite passes.
- AI API connector contracts pass.
- Valid accessibility fixture passes.
- Invalid contrast fixture fails with `accessibility_low_contrast_literal_pair`.
- Invalid overflow fixture fails with `accessibility_text_clipping_risk`.
- The National Chikki Bricks template still passes `/audit /bricksconversion`, but fails `/audit /accessibility` until a dark-mode/accessibility lane is created or fixed.
- `package-manifest.json` reports version `6.57`.
- Package verifier reports `Package 6.57` with all files verified.

## Staging smoke

1. Upload the canonical MU loader file and `dsa/` folder from `wp-content/mu-plugins`.
2. Confirm `/wp-json/dsa/v1/manifest` reports `6.57`.
3. Use a browser AI with:
   ```text
   explore: https://github.com/Museintel/kiwe
   /audit /accessibility
   ```
   against a Bricks template or website handoff.
4. Confirm the AI uses the accessibility lane, not `/convert /bricks`.
5. Confirm missing dark mode, low contrast pills/cards, and clipped critical text are reported before staging/import.
