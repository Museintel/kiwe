# Kiwe DSA 0.6.19 release proof

Date: 2026-07-22

Purpose: tighten the Companion AI review loop before the fresh BioVantage/Site Graph combined-output test.

## Finding

Live `0.6.18` Companion status/context/ask routes worked and returned compact context cards, but a deliberately flawed `theme.css` containing direct protected `[data-dsa-surface]` geometry was not flagged as an error by Companion review.

## Local fix

- Companion review now flags direct protected AppShell surface geometry in importable theme CSS.
- Companion review now flags private AppShell fixture structures in the primary combined preview.
- Connector contracts pin these checks so Companion stays aligned with the official Kiwe validators.

## Verification required after upload

- Upload the complete `dsa` MU plugin folder paired with loader `dsa.php`.
- Confirm the package manifest reports `0.6.19`.
- Re-run Companion review against a deliberately bad package and confirm geometry/fixture errors are reported.
