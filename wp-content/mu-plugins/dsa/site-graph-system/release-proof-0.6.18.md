# Kiwe DSA 0.6.18 release proof

Date: 2026-07-22

Purpose: close the post-upload staging visual audit where `0.6.17` was live and functional, but the Sheet close/drag handle still sat too far below the sheet edge when a roomy marketplace theme supplied large panel padding.

## Live staging findings before this patch

- Staging served package manifest `0.6.17` with 255 files.
- Active installed theme was `national-heritage-commerce` v1.8.0.
- Site Graph Data product/page envelopes returned `products` and `pages`.
- DSA Cart opened after the entry layer was dismissed.
- Cart created one overlay and one cart panel, with no duplicate sheet instances.
- National theme styling was active on the live Cart panel.
- The visible grabber was still content-flow based and measured too low relative to the sheet top.

## Local fix

- `.dsa-sheet-grabber` is now absolutely anchored inside the sheet panel at the shell/chrome layer.
- The handle remains theme-agnostic and independent of module top padding.

## Verification required after upload

- Upload the complete `dsa` MU plugin folder paired with loader `dsa.php`.
- Confirm the package manifest reports `0.6.18`.
- Open a sheet after dismissing the DSA entry layer and verify the handle appears near the sheet edge.
