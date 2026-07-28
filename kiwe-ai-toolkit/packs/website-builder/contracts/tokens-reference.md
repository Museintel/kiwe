# Kiwe token reference for theme authors

`token-map.css` is the canonical portable token map for outside theme work.

It is intentionally not a raw dump of every CSS variable used inside production `assets/css/surface.css`.

## What `token-map.css` contains

`token-map.css` exposes the tokens and aliases a designer or AI should normally use:

- brand and accent state
- surface, raised surface, text, muted text, inverse text, and border
- common radius aliases
- spacing aliases
- typography, heading scale, font, line-height, and tracking aliases
- generic border widths
- safe elevation/shadow aliases
- compact, readable, default, narrow, and wide content widths
- dock control, AI, icon, and badge sizes
- compatibility aliases that bridge `--kiwe-*` tokens to `--dsa-*` runtime values

This is the safe design surface for marketplace themes.

`Kiwe > Framework` is the editable site design-token profile. It can export the same profile to Bricks as additive `kiwe-*` variables, the Kiwe Universal color palette, the neutral Seam Class Vocabulary, and one safe global Bricks theme style covering only body/headings, colors, links, and site background.

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

In the current Kiwe Framework architecture:

- `ui-system/token-map.css` exposes the curated portable token map.
- `Design\Seam_Token_Service` is the complete canonical PHP source for Kiwe universal tokens and Bricks export.
- production `assets/css/surface.css` contains more variables because it owns runtime geometry, state, transitions, legacy compatibility, and built-in profile internals.

That difference is expected. It does not mean the UI brain is missing the design tokens required to create a theme.

## Token behavior roles

Kiwe tokens now carry behavior metadata in addition to their visual group. This is how the Framework keeps the Phantom Viewport / Geometry Engine goal without forcing every primitive value to become a clamp:

- `fluid-scale` — responsive values such as type and spacing scales. These normally use real `clamp(...)` values.
- `fixed-primitive` — stable named constants such as a hairline border or radius step. Plain values are valid here because page/theme code consumes the token, not the raw number.
- `geometry-input` — named control points consumed by Kiwe's Geometry Engine or runtime measurements, such as dock gaps, control sizes, and badge sizes.
- `content-limit` — named content bounds such as compact/readable/default/wide widths. Use them inside responsive-safe `min(...)`, `max(...)`, `clamp(...)`, or `minmax(...)` formulas.
- `responsive-guard` — minimum clearances and collapse guards such as viewport gutters and responsive grid minimums.
- `semantic-token` — meaning-driven tokens such as colors, font stacks, motion curves, density, and state identity.
- `alias` — compatibility or convenience variables that resolve to another Kiwe/Seam token.
- `layer-token` — named layer/index values. These are for core/AppShell layer contracts, not ad-hoc theme z-index fights.
- `project-token` — site-specific art direction declared by a Framework profile or conversion package.

This means `--kiwe-grid-min-col: 240px` is valid as a named responsive guard, while `_gridAutoColumns: minmax(250px, 1fr)` in a Bricks template is not valid unless it uses an official token, a declared project token, or a proven calculated clamp.

## If a theme needs a missing token

Do not invent a private replacement and do not reach into production internals blindly.

Use this fallback ladder:

1. Use an exact official Kiwe/Seam universal token when the token meaning and CSS property domain match.
   - `border-radius: var(--kiwe-radius-xl)` is valid for a 20px panel radius.
   - `padding: var(--kiwe-space-md)` is valid for normal layout spacing.
   - Do not map by number alone. A `12px` radius is not a spacing token just because `space-xs` can resolve to 12px.
2. Use a declared project token for real art direction that is not universal.
   - Good: `--nc-promo-card-height`, `--nc-campaign-art-offset`, `--nc-hero-scrim`.
   - Put the project token in the Framework profile/global variables before using it in Bricks JSON or page CSS.
3. Use a real fluid `clamp()` only when the source design has different values across responsive states.
   - Good: `clamp(220px, calc(135.53px + 17.64vw), 390px)` when the source proves a card should interpolate from 220px at a phone width to 390px at a desktop width.
   - Bad: `clamp(680px, 680px, 680px)`. That is a hardcoded value disguised as a clamp and must fail audit.

The Kiwe interpolation formula is:

```text
slope = (maxValue - minValue) / (maxViewport - minViewport) * 100
intercept = minValue - (slope / 100 * minViewport)
clamp(minValue, calc(intercept + slope * 1vw), maxValue)
```

Use compatible units only. If the min/max values use incompatible units, create a declared project token instead of guessing.

Geometry Engine owns DSA/AppShell placement and measurement. This fluid-clamp ladder is for page/Bricks/content design values and for preview-only proof, not for overriding dock, sheet, screen, backdrop, safe-area, keyboard, or Surface lifecycle geometry.

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
- support the Kiwe Framework design-token profile and preserve Active/Hover compatibility through the provided aliases
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
- create hidden color, font, heading, background, shadow, or spacing systems that cannot map back to documented Kiwe tokens
- depend on private variables such as `--dsa-flat-*` unless the theme is explicitly targeting a built-in profile reference
