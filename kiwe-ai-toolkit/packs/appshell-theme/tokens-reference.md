# Kiwe token reference for theme authors

`token-map.css` is the canonical portable token map for outside theme work.

It is intentionally not a raw dump of every CSS variable used inside production `assets/css/surface.css`.

## What `token-map.css` contains

`token-map.css` exposes the tokens and aliases a designer or AI should normally use:

- brand and accent state
- surface, raised surface, text, muted text, inverse text, and border
- common radius aliases
- spacing aliases
- typography aliases
- dock control, AI, icon, and badge sizes
- compatibility aliases that bridge `--kiwe-*` tokens to `--dsa-*` runtime values

This is the safe design surface for marketplace themes.

## What it does not contain

Production CSS contains additional runtime variables that are not all exported to the portable UI brain. These include:

- measured Geometry Engine offsets
- scroll-lock and browser chrome offsets
- dock/context measured positions
- internal animation start/end values
- temporary state variables
- private visual-profile variables such as Kiwe 2027 flat-layer internals
- implementation details that may change without becoming marketplace API

Theme authors should not depend on those internal variables unless they are documented in this folder.

## Current relationship

As of Kiwe `0.5.73`:

- `ui-system/token-map.css` exposes the curated portable token map.
- production `assets/css/surface.css` contains more variables because it owns runtime geometry, state, transitions, legacy compatibility, and built-in profile internals.

That difference is expected. It does not mean the UI brain is missing the design tokens required to create a theme.

## If a theme needs a missing token

Do not invent a private replacement and do not reach into production internals blindly.

Instead, document the need in the handoff:

```text
Requested core token:
- Name:
- Purpose:
- Screen/state:
- Why existing tokens are insufficient:
- Suggested fallback:
```

Kiwe core can then decide whether to promote that variable into `token-map.css` as a stable marketplace token.

## Required token behavior

Themes must:

- consume `--kiwe-theme-*` aliases first where possible
- support Active and Hover color authority through the provided aliases
- preserve dock shape variables:
  - `--dsa-dock-shell-radius`
  - `--dsa-dock-control-radius`
  - `--dsa-dock-segment-radius`
- use Geometry Engine variables for reserves and layout instead of magic offsets
- keep light/dark mode readable
- keep reduced-motion behavior safe

Themes must not:

- hardcode one dock radius while ignoring `dsa-dock-shape-*`
- hardcode viewport offsets instead of using geometry variables
- create hidden color systems that cannot map back to Active/Hover and documented tokens
- depend on private variables such as `--dsa-flat-*` unless the theme is explicitly targeting a built-in profile reference
