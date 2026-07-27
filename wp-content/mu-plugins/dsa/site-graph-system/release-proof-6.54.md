# Kiwe 6.54 release proof — Framework profile command intelligence

Date: 2026-07-28

## Lead decision

`/audit /frameworkprofile` and `/fix /frameworkprofile` must carry the Framework-profile intelligence themselves. The human should not need to spoon-feed live-test symptoms such as missing CSS variables.

This release tightens the deterministic Framework profile contract so browser/IDE AIs can use the short command flow:

```text
explore: https://github.com/Museintel/kiwe
/audit /frameworkprofile
/fix /frameworkprofile
```

## Changes

- Added core live token coverage checks to the local Framework profile validator.
- Added the same checks to the generic output audit tool.
- Added the same checks to Kiwe Companion review.
- Updated workflow and audit contexts so browser AIs know that a Framework profile must cover the live Seam/Bricks foundation without being told individual missing variables.
- Corrected the vocabulary away from invented variables like `--kiwe-color-primary`; the official universal token is `color-brand`, exported as `--kiwe-color-brand`.

## Required Framework profile coverage

A complete `framework/kiwe-framework-profile.json` must cover these official universal tokens either in `settings.tokens.overrides` or through mapped `settings.tokens.bricks_theme_style` global slots:

- `color-brand`
- `color-accent`
- `color-surface`
- `color-surface-raised`
- `color-text`
- `color-text-muted`
- `color-border`
- `font-display`
- `font-body`
- `type-h1`
- `type-body`
- `space-md`
- `radius-lg`
- `shadow-md`

The Bricks theme-style lane must still include:

- `enabled: true`
- safe `id`
- human `label`

## Evidence

- `npm.cmd test` passed in `kiwe-ai-toolkit`.
- `php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php` passed.
- Existing broken National Chikki Framework profile now fails without a spoon-fed symptom list:
  - missing `bricks_theme_style.enabled`
  - missing `bricks_theme_style.id`
  - missing `bricks_theme_style.label`
  - missing core token coverage for `type-body`

## Upload note

After upload, browser AI should be able to fix this class of issue with:

```text
https://github.com/Museintel/kiwe
/audit /frameworkprofile
/fix /frameworkprofile
```

No long prompt or missing-variable symptom list should be required.
