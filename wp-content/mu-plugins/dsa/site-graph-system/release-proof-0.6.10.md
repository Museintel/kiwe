# Kiwe 0.6.10 split dock centering release proof

## Scope

This release hardens the AppShell Geometry Engine boundary for split compact docks:

- installed theme CSS may style colors, typography, surface material, borders, and visual states;
- installed theme CSS must not own dock arrangement, gap, shell measurement, or split focus spacing;
- the runtime guard is appended after installed theme CSS and now reasserts split dock gap/spacing.

## Expected Hostinger verification

After uploading this MU-plugin folder:

1. `GET /wp-json/dsa/v1/ai/site-graph` reports `version: 0.6.10`.
2. On a mobile viewport around 390px, the split dock button span is visually centered and does not leave the right segment pressed against the viewport edge.
3. The computed split dock gap for `.dsa-phonekey-dock` is `0px` even when an installed theme package is active.
4. The focus/action button keeps a controlled gap through `--dsa-dock-split-focus-gap` rather than theme-owned arbitrary margins.
5. 320px stress checks still produce no horizontal overflow and no clipped final dock control.

