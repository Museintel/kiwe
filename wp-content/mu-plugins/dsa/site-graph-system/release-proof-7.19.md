# Owner onboarding refinement proof — Kiwe 7.19

Kiwe 7.19 closes the gaps found in the first Mishtanna owner-context run while
preserving the authority boundaries introduced in 7.18.

## Human decisions versus derived framework values

The owner may optionally choose only five real SEAM anchors:

- `color-brand`
- `color-accent`
- `color-hero`
- `color-neutral`
- `color-surface`

SEAM/accessibility remains responsible for readable text, muted/disabled text,
borders, shadows, states, raised/sunken/overlay surfaces, dark-mode pairs and
contrast. Onboarding does not install or generate a Framework Profile.

## Native authority additions

- WordPress/Kiwe: optional inverse logo.
- Kiwe Links: Facebook, Instagram, X, YouTube, Pinterest and LinkedIn URLs.
- WhatsApp: explicit same-as-public-phone choice or independent number.
- WooCommerce: allowed selling countries, excluded countries, shipping
  destinations, currency position/decimals, weight unit and dimension unit.
- SiteGraph/Bricks/WordPress bindings: all public owner contact, social and
  selectable token anchors.

WooCommerce still owns products, prices, tax rates, shipping zones, inventory,
checkout and orders. The owner plan never invents jurisdictional rates or zones.

## SEO separation

Business story and audience are design/copy evidence only. They do not populate
SEO metadata. The explicit homepage search description is the sole onboarding
description used for homepage meta/schema output, and Kiwe still yields to a
recognized dedicated SEO plugin.

## Save and validation behavior

- The final action enters a visible saving state.
- Successful saves return to the review step with a success notice.
- Server validation returns to the relevant step.
- Hidden-step native validation opens the panel containing the invalid control.
- Mood preference has an explicit clear action.

## Deterministic verification

```text
node tools/release/onboarding-contracts.cjs
node tools/release/sitegraph-design-context-contracts.cjs
node tools/release/verify-green-baseline.cjs
```

