# Kiwe 6.51 Framework profile Bricks-theme push proof

Date: 2026-07-27

## Changes in 6.51

- Framework profile import is now self-contained for Bricks theme-style setup.
  - If `settings.tokens.bricks_theme_style` carries global style metadata, Kiwe treats that as an intentional Bricks theme-style push lane.
  - Missing Bricks theme-style `id` and `label` are derived safely from `settings.tokens.profile_label`.
  - Safe global slots such as `siteBackground`, `colorPrimary`, `colorSecondary`, `fontDisplay`, `fontBody`, `typeH1`, `radiusLg`, `shadowMd`, and `spaceMd` normalize into official Kiwe universal token overrides.
- Kiwe > Framework push now uses Bricks global-variable helper methods where available.
- Kiwe > Framework push and AI staging `kiwe.framework.push-bricks` explicitly regenerate Bricks theme-style CSS after writing `bricks_theme_styles`, when Bricks exposes `\Bricks\Assets_Theme_Styles`.
- The public toolkit now requires `/create /frameworkprofile` output to include a complete `settings.tokens.bricks_theme_style` lane:
  - `enabled: true`
  - safe `id`
  - human `label`
  - global-only style slots
- `/audit /frameworkprofile` and generic `audit-output.cjs` now reject partial Framework profile theme-style blobs.

## Why this matters

The Framework profile is the preferred one-file foundation for Seam/Kiwe pages:

- import under `Kiwe > Framework`;
- push to Bricks;
- Bricks receives Kiwe universal variables, Kiwe Universal color palette, Seam global classes, and the matching native Bricks Theme Style.

This prevents Bricks pages/templates from rendering with missing global colors, typography, and site background after an AI-generated page uses Seam/Kiwe tokens.

## Browser-AI regression this closes

The failing profile shape looked like this:

```json
{
  "schema": "kiwe.framework-profile.v1",
  "settings": {
    "tokens": {
      "enabled": true,
      "profile_label": "National Chikki",
      "overrides": {
        "color-brand": "#d71920"
      },
      "bricks_theme_style": {
        "siteBackground": "#f5f0e7",
        "colorPrimary": "#d71920"
      }
    }
  }
}
```

The importer now rescues this by enabling the lane and deriving id/label, while the toolkit validator rejects it so future AI output uses the complete shape first time.

## Required local proof

Run from the repo root:

```bash
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
php -l wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php
php -l wp-content/mu-plugins/dsa/includes/AI/Staging_Execution_Service.php
cd kiwe-ai-toolkit && npm test
node ../tools/release/build-package-manifest.cjs
node ../tools/release/verify-package.cjs
```

## Staging proof checklist

1. Upload MU plugin version `6.51`.
2. Confirm `/wp-json/dsa/v1/manifest` reports `6.51`.
3. Import an AI-created `framework/kiwe-framework-profile.json` in `Kiwe > Framework`.
4. Click `Push Kiwe Framework to Bricks`.
5. In Bricks, verify:
   - `kiwe-*` global variables exist;
   - Kiwe Universal palette exists;
   - Seam global classes exist;
   - a Bricks Theme Style named from the Framework profile exists;
   - frontend CSS reflects the pushed site background, colors, and typography after Bricks CSS regeneration.
6. Run `/audit /frameworkprofile` on the same profile and confirm partial `bricks_theme_style` blobs fail before upload.

