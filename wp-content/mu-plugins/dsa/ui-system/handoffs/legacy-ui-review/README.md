# Kiwe Legacy UI Review Handoff

This handoff is for reviewing Kiwe's built-in Legacy UI visual profile.

Legacy is the lightest baseline profile shipped with the MU plugin. It should remain low-tax, stable, accessible, and compatible with existing sites. The goal of this handoff is not to replace Legacy with a new visual system; it is to let a UI designer audit the current baseline and propose small, contract-safe improvements.

## What to give a UI reviewer

Give them this folder:

```text
ui-system/handoffs/legacy-ui-review/
```

They should inspect:

- `preview/index.html`
- `preview/PLACEHOLDERS.md`
- `REVIEW-BRIEF.md`
- `import/kiwe-legacy-baseline/theme.json`

The preview includes current shared shell controls for full compact dock, split compact dock, Navigation bar, and the Pill / Rounded box / Square dock shape states.

## What they should return

Ask for:

1. A visual QA report with screenshots or annotated notes.
2. A prioritized list of issues: blocker, high, medium, low.
3. Contract-safe CSS suggestions when possible.
4. Notes for any improvement that needs core markup or runtime changes.

They should not rewrite cart, checkout, PhoneKey, Search, Bricks, service worker, history, or Geometry Engine behavior.

## Important distinction

The `import/kiwe-legacy-baseline/` folder is a baseline manifest, not a new theme to install over Legacy. Legacy is already built into Kiwe. The manifest exists so validators and reviewers can compare Legacy against the same marketplace/theme contract used by Kiwe 2027 and future themes.

## Distinctness note / visual thesis

Legacy is Kiwe's low-tax baseline. Its visual thesis is compact utility: minimal chrome, conservative spacing, simple cards, and no dependency on expressive materials such as glass, heavy blur, or animated dashboards.

- Different from Legacy: this is the Legacy baseline, so reviewers should preserve the low-tax direction rather than replace it.
- Different from Kiwe 2027: Legacy should avoid the larger editorial hierarchy, split-card account treatment, richer badge styling, and stronger visual personality used by Kiwe 2027.
- Blur/glass: Legacy should not require glassmorphism or high-cost blur to feel complete.

## Screen and shell mode coverage

The preview covers profile, cart, checkout, search, menu, saved, links, notifications, iOS install, games, and AI. It demonstrates Sheet and Classic presentation, full compact dock, split compact dock, Navigation bar, horizontal and vertical orientation, light and dark mode, desktop/tablet/mobile viewports, and Pill / Rounded box / Square dock shape states.

## Selector-fit checklist

- Profile preserves `data-dsa-profile-panel`, `data-dsa-profile-form`, profile actions, downloads, addresses, password, and logout selectors.
- Cart preserves `data-dsa-cart-panel`, checkout-open action, cart item classes, and FBT rail proof through `data-dsa-cart-fbt-rail`.
- Checkout preserves `data-dsa-checkout-panel`, `data-dsa-checkout-form`, and field markers.
- Search preserves `data-dsa-search-panel`, `data-dsa-search-form`, `data-dsa-search-input`, filters, alphabet, and results.
- Menu preserves the menu panel and page table-of-contents anchor controls.
- Saved, Links, Notifications, iOS install, Games, and AI preserve their stable `data-dsa-*` screen selectors.
- Links documents both site score shown and score absent states. When no score is configured, the score badge should not render at all.
- FBT / Frequently Bought Together remains a horizontal rail, not a stacked list.

## Seam adoption map

Legacy review must respect the Seam `appShellAdoption` map. Public-adopted AppShell classes such as `seam-eyebrow`, `seam-caption`, and `seam-price` may appear where Kiwe core applies them. Shadow-only roles such as card, button, input, media, badge, nav, actions, form, field, and modal must remain reviewed through existing DSA selectors and protected `data-seam-*` metadata rather than assuming public Seam classes are safe inside live sheets/screens.

## Intentional limitations

This handoff is a visual review aid, not an installable replacement for Legacy. It uses mock preview content and does not attempt pixel-perfect WooCommerce, PhoneKey, Bricks query, notification permission, AI, or PWA behavior. The live plugin remains the final truth for runtime geometry and state.

## Core/plugin changes

No core/plugin change is requested by this handoff. If a reviewer proposes new markup, new data, new account/cart/checkout behavior, or a different Geometry Engine reserve model, it must be reported separately as a core/plugin change, not hidden inside CSS.

## Validation

Validate the import manifest:

```bash
node tools/ui-theme/validate-package.cjs wp-content/mu-plugins/dsa/ui-system/handoffs/legacy-ui-review/import/kiwe-legacy-baseline
```

Validate the complete handoff:

```bash
node tools/ui-theme/validate-handoff.cjs wp-content/mu-plugins/dsa/ui-system/handoffs/legacy-ui-review
```

Audit the current Seam AppShell adoption boundary:

```bash
node tools/ui-theme/audit-seam-adoption.cjs
```
