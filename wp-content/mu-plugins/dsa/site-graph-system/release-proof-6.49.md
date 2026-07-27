# Kiwe 6.49 Framework clear + lean Seam workflow proof

Purpose: close the Kiwe Framework admin loop and reduce browser-AI token waste in the phased slash-command workflow.

## Changes in 6.49

- Added `Kiwe > Framework > Clear Framework + Bricks Push`.
- The clear action resets the active Framework token profile to the built-in Kiwe defaults.
- The clear action removes only Kiwe-owned Bricks Framework data:
  - `kiwe-*` global variables;
  - Kiwe Universal variable categories;
  - Kiwe Universal color palette;
  - the active/default Kiwe global Bricks theme style;
  - Seam Class Vocabulary classes and categories.
- Existing non-Kiwe Bricks variables, classes, palettes, and theme styles are preserved.
- A Bricks Framework backup is stored before the clear mutation, matching the existing push backup safety pattern.
- Added `/document` as the explicit notes/documentation command.
- Updated `/rebuild /seamframework` so its default output is only `website/bricks-paste.html`; `website/bricks-notes.md`, README files, and reports are generated only when `/document` or an explicit documentation request is present.

## Local proof

Commands run:

```bash
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php
cmd /c npm.cmd test
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Results:

- PHP syntax check passed for `Admin.php`.
- Kiwe AI Toolkit test suite passed, including command diagnostics, workflow route smoke outputs, validators, Bricks conversion fixture validation, accessibility fixture validation, apply-plan preparation, and Framework profile validation.
- Package manifest rebuilt with 272 files.
- Package manifest verified as `6.49` with 272 files.

## Staging proof still required

After uploading the complete MU package, confirm:

1. `/wp-json/dsa/v1/manifest` reports `6.49`.
2. `Kiwe > Framework` shows the `Clear Framework + Bricks Push` action.
3. Pushing Framework to Bricks creates Kiwe-owned variables/classes/palette/style.
4. Clearing Framework removes those Kiwe-owned Bricks entries while preserving non-Kiwe Bricks design data.
5. Browser AI using `explore: https://github.com/Museintel/kiwe` then `/list` includes `/document`.
6. Browser AI using `/rebuild /seamframework` returns only `website/bricks-paste.html` unless `/document` is explicitly requested.
