# Kiwe DSA 0.6.20 release proof

Date: 2026-07-22

Purpose: remove the Sheet grabber's component-level chrome magic number after audit feedback confirmed the AppShell itself must model Seam/Kiwe token discipline.

## Local fix

- The Sheet handle chrome offset now uses `--dsa-sheet-chrome-inset-block-start`.
- The Sheet handle touch target now uses `--dsa-sheet-grabber-hit-size`.
- The visible handle bar now uses `--dsa-sheet-grabber-bar-inline-size` and `--dsa-sheet-grabber-bar-block-size`.
- These tokens derive from existing Geometry Engine dock/control tokens, keeping the value adjustable without changing component CSS.

## Architectural note

Kiwe/Seam still needs numeric base tokens somewhere; that is the source-of-truth layer. The fix here is that AppShell components consume named geometry tokens rather than embedding one-off hard values.

## Verification required after upload

- Upload the complete `dsa` MU plugin folder paired with loader `dsa.php`.
- Confirm the package manifest reports `0.6.20`.
- Open a Sheet and confirm the visible handle remains near the sheet edge while reading from tokenized chrome metrics.
