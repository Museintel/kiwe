# Kiwe Framework canonical source map

This folder is a portable handoff. The real plugin sources live elsewhere.

## Runtime framework files

| Concern | Canonical file |
| --- | --- |
| Production Seam CSS | `wp-content/mu-plugins/dsa/assets/css/seam.css` |
| Safe Seam runtime helper | `wp-content/mu-plugins/dsa/assets/js/seam.js` |
| Debug-only Seam linter | `wp-content/mu-plugins/dsa/assets/js/seam-dev.js` |
| Frontend enqueue / `DSA_DATA.seam` payload | `wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php` |

## Framework contracts

| Concern | Canonical file |
| --- | --- |
| PHP vocabulary source | `wp-content/mu-plugins/dsa/includes/Design/Seam_Vocabulary_Schema.php` |
| PHP Bricks class vocabulary/export source | `wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php` |
| Portable vocabulary JSON snapshot | `wp-content/mu-plugins/dsa/ui-system/seam-vocabulary.json` |
| Portable vocabulary docs | `wp-content/mu-plugins/dsa/ui-system/seam-vocabulary.md` |
| Portable class vocabulary JSON snapshot | `wp-content/mu-plugins/dsa/ui-system/seam-class-vocabulary.json` |
| Portable class vocabulary docs | `wp-content/mu-plugins/dsa/ui-system/seam-class-vocabulary.md` |
| Universal token schema | `wp-content/mu-plugins/dsa/includes/Design/Token_Schema.php` |
| Bricks/framework export service | `wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php` |
| Public token handoff map | `wp-content/mu-plugins/dsa/ui-system/token-map.css` |
| Token handoff explanation | `wp-content/mu-plugins/dsa/ui-system/tokens-reference.md` |

## Bricks integration

| Concern | Canonical file |
| --- | --- |
| Bricks runtime integration | `wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php` |
| Admin push to Bricks | `wp-content/mu-plugins/dsa/includes/Admin/Admin.php` |
| Bricks capability handoff | `wp-content/mu-plugins/dsa/ui-system/bricks-capabilities.json` |
| Bricks docs | `docs/BRICKS-INTEGRATION.md` |

## DSA/AppShell adoption

| Concern | Canonical file |
| --- | --- |
| DSA panel landmark annotation | `wp-content/mu-plugins/dsa/assets/js/surface.js` |
| `window.DSA.ui.seam` bridge | `wp-content/mu-plugins/dsa/assets/js/surface.js` |
| Adoption audit tool | `tools/ui-theme/audit-seam-adoption.cjs` |
| Integration proof checklist | `wp-content/mu-plugins/dsa/ui-system/integration-proof-2026-07-16.md` |

## Admin location

The WordPress admin page is:

```text
Kiwe > Framework
```

It pushes the active Kiwe Framework into Bricks as:

- `kiwe-*` global variables;
- Kiwe Universal color palette;
- Kiwe Seam global classes/categories, including the expanded neutral Seam Class Vocabulary.

The old `Kiwe > Tokens` slug (`kiwe-tokens`) redirects to `kiwe-framework` for compatibility.

## Validation commands

Run from the repo root:

```bash
node tools/ui-theme/audit-seam-adoption.cjs
node tools/ui-theme/validate-package.cjs tools/ui-theme/fixtures/valid
node tools/ui-theme/validate-handoff.cjs wp-content/mu-plugins/dsa/ui-system/handoffs/legacy-ui-review
node tools/release/verify-package.cjs
```

The reference copy at `framework-system/tools/audit-seam-adoption.cjs` is included for review. The canonical runnable tool is `tools/ui-theme/audit-seam-adoption.cjs`.
