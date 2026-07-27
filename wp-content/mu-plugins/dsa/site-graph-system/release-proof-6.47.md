# Kiwe 6.47 release proof

## Scope

6.47 adds the first dedicated accessibility command lane for browser/IDE AI, native WordPress AI, and Kiwe Companion:

- `/create /accessibility`
- `/audit /accessibility`

The lane is intentionally narrow for this release. It covers color contrast, native light/dark-mode proof, Kiwe/Seam token pairing, and Bricks global theme-style color alignment. Font-size/readability scaling is explicitly out of scope and remains a later lane.

## Contract changes

- Added `kiwe-ai-toolkit/contexts/accessibility-lite.md` as the small context file for accessibility work.
- Added `accessibility/kiwe-accessibility-plan.json` with schema `kiwe.accessibility-plan.v1`.
- Added `ACCESSIBILITY-NOTES.md` as the required human-readable proof lane.
- Added deterministic validation for:
  - missing accessibility plan when the accessibility lane is strict;
  - missing native dark-mode proof;
  - literal white-on-white, light-on-light, black-on-black, and dark-on-dark foreground/background pairs;
  - unmapped private color variables;
  - missing Kiwe/Seam token evidence;
  - missing Bricks theme-style color alignment.
- Wired the same lane through:
  - toolkit CLI;
  - MCP tools;
  - command router and command diagnostics;
  - REST API key route `/wp-json/dsa/v1/ai/validate-accessibility`;
  - Companion review and Audit Companion;
  - WordPress Abilities `dsa/validate-accessibility`;
  - Site Graph capability discovery;
  - Internal AI context discovery;
  - AI/API connector contracts.

## Bricks source evidence

The accessibility lane aligns with Bricks 2.4 beta theme-style root slots:

- `C:/Users/shariq/Downloads/bricks.2.4-beta/bricks/includes/theme-styles/controls/colors.php` maps global colors such as `colorPrimary`, `colorSecondary`, `colorLight`, `colorDark`, `colorMuted`, and state colors at `:where(:root)`.
- `C:/Users/shariq/Downloads/bricks.2.4-beta/bricks/includes/theme-styles/controls/general.php` maps `siteBackground` to the global `html` background lane.

Kiwe therefore does not invent a separate Bricks dark-mode system. Browser AI should map Kiwe/Seam tokens into the safe Bricks global style lanes, then keep component-specific contrast fixes in the page/theme artifact.

## Evidence

- `kiwe-ai-toolkit/tools/validate-accessibility.cjs` SHA-256: `26ac94afcd5c4a70581d5089e2e61109d3d5edb6721dc11a16a67327e4a13ad4`
- `kiwe-ai-toolkit/lib/accessibility-validator.js` SHA-256: `0b14a21503a4b2ce91e5975c35a93a7f1e7f8e59aa06812fcc3c0f3b8c3d7414`
- `kiwe-ai-toolkit/contexts/accessibility-lite.md` SHA-256: `b5a4f8df218306451817086313f549b6d1bd483b7c186db07e388bf4fa0188ec`
- `wp-content/mu-plugins/dsa/includes/AI/Accessibility_Validator.php` SHA-256: `0a3f1a6677eed4ac2edc7110da584eb5ca0aebecbfea86a1bcd79a977deee45b`
- `wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php` SHA-256: `5ff7b9433c70ab1a98b8e7275e91f78f83e87ffd1a2c2d4974d73435f78af13b`
- `wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php` SHA-256: `0147d80fe68e5831637711d1ae6828efc259f47da27b7bb99bb53384bb35b1c9`
- Added positive fixture: `kiwe-ai-toolkit/fixtures/accessibility-valid`.
- Added negative fixture: `kiwe-ai-toolkit/fixtures/accessibility-invalid-contrast`.
- The positive fixture passes `validate-accessibility`.
- The negative fixture fails through `accessibility_low_contrast_literal_pair`.

## Commands run

```bash
node --check kiwe-ai-toolkit/lib/accessibility-validator.js
node --check kiwe-ai-toolkit/bin/kiwe.js
node --check kiwe-ai-toolkit/mcp/index.js
php -l wp-content/mu-plugins/dsa/includes/AI/Accessibility_Validator.php
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Access_Key_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Internal_AI_Context_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php
php -l wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php
php -l wp-content/mu-plugins/dsa/includes/WP7/Abilities_Service.php
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
npm --prefix kiwe-ai-toolkit test
node tools/connector/ai-api-contracts.cjs
node tools/release/rc12-contracts.cjs
node tools/runtime/htmx-alpine-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

## Staging checklist

After uploading both `wp-content/mu-plugins/dsa.php` and the complete `wp-content/mu-plugins/dsa/` folder from this release:

1. Confirm `/wp-json/dsa/v1/manifest` reports `6.47`.
2. Confirm Kiwe > AI generated keys can include `validate_accessibility`.
3. Confirm `GET /wp-json/dsa/v1/ai/status` advertises `validateAccessibility`.
4. Submit a small file map with `color:#fff;background:#fff` to `/wp-json/dsa/v1/ai/validate-accessibility` and confirm it fails.
5. Submit a file map with `accessibility/kiwe-accessibility-plan.json`, light/dark proof, and readable token pairs and confirm it passes.
6. In a browser-AI flow, run `/create /accessibility /usecompanion` after a generated artifact exists; verify Companion does not redesign the site and only adds the accessibility lane/revisions.
7. Run `/audit /accessibility /usecompanion` on the revised package and confirm no literal low-contrast pairs remain.
