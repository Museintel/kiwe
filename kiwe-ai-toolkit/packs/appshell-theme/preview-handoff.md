# Standalone Preview Handoff Contract

Theme handoffs may include a standalone HTML preview, but the preview is not the theme.

The importable theme remains only:

- `theme.json`
- listed CSS files
- listed static image assets
- optional human README

The preview exists so a site owner, designer, or AI assistant can look at the theme before installing it. Preview markup, preview JavaScript, mock payloads, placeholder images, and preview controls must never be copied into the importable theme package.

## Recommended handoff shape

```text
theme-handoff/
  README.md
  import/
    theme-id/
      theme.json
      css/theme.css
      assets/optional.svg
      README.md
  preview/
    index.html
    PLACEHOLDERS.md
```

Validate the importable package first:

```bash
node tools/ui-theme/validate-package.cjs theme-handoff/import/theme-id
```

When reviewing a complete AI/designer handoff, validate the whole handoff too:

```bash
node tools/ui-theme/validate-handoff.cjs theme-handoff
```

The handoff validator runs the import-package validator and then checks the standalone preview for required Kiwe geometry attributes, dock modes, dock shape states, screen selectors, FBT rail proof, placeholder documentation, and forbidden remote/network/service-worker/payment behavior.

It also checks the root handoff `README.md` for review-quality sections: distinctness note / visual thesis, screen and shell mode coverage, selector-fit checklist, validation instructions, intentional limitations, clear core/plugin change separation, and acknowledgement of the Seam `appShellAdoption` map.

## Preview layer rules

Keep four layers separate:

| Layer | Allowed source | Importable? |
| --- | --- | --- |
| Theme CSS | `import/theme-id/css/theme.css` | Yes |
| Theme manifest | `import/theme-id/theme.json` | Yes |
| Mock screen content | Preview fixtures/placeholders | No |
| Preview shell/controller | `preview/index.html` only | No |

The preview may simulate data, screen switching, light/dark mode, viewport profiles, sheet/classic presentation, full compact dock, split compact dock, Navigation bar presentation, and placeholder commerce/account/search states. It must not simulate real cart mutation, checkout/payment, PhoneKey, Push subscription, service-worker behavior, Bricks queries, reward verification, or browser history ownership. Marketplace manifests may describe support as `navigation-bar`, but production preview markup must use the runtime attribute value `data-dsa-dock-presentation="navbar"`.

For combined website/page + AppShell handoffs, `combined-preview/index.html` is the single primary visual proof. It must show the website/page behind the AppShell and include the variation controls there. A separate AppShell-only preview is optional technical proof only; do not make it the only place where dock modes, dock shapes, Classic, or responsive profiles are reviewed.

If the combined preview loads `website/bricks-paste.html` in an iframe, add preview-only bridge JavaScript for canonical page/header launchers such as `data-dsa-open-module="cart"`, `data-dsa-open-module="profile"`, and `data-dsa-open-module="search"`. The bridge is only for the preview; live WordPress behavior remains Kiwe-owned.

## Geometry rules for preview HTML

The preview shell should mimic Kiwe geometry by setting production-style attributes and CSS variables on the Surface root:

```html
<div
  class="dsa-surface dsa-theme-sheet dsa-visual-marketplace"
  data-dsa-surface
  data-dsa-ui-contract="2"
  data-dsa-layout="compact"
  data-dsa-density="comfortable"
  data-dsa-dock-presentation="dock"
  data-dsa-dock-orientation="horizontal"
  data-dsa-sheet-position="bottom"
  data-dsa-sheet-space="inset"
  style="
    --dsa-dock-control-size:48px;
    --dsa-dock-ai-size:54px;
    --dsa-dock-gap:2px;
    --dsa-dock-padding:8px;
    --dsa-dock-only-reserve:82px;
    --dsa-screen-block-reserve:104px;
    --dsa-screen-inline-reserve:24px;
    --dsa-screen-available-inline:calc(100vw - 24px);
    --dsa-screen-available-block:calc(100vh - 104px);
  "
>
```

When previewing split dock, add the same runtime classes the production renderer emits:

```html
<div class="dsa-surface dsa-dock-split" data-dsa-dock-presentation="dock" ...>
  <nav class="dsa-dock dsa-phonekey-dock" data-dsa-dock-cluster>
    <button class="dsa-dock__button is-split-segment-start">...</button>
    <button class="dsa-dock__button is-split-before-ai">...</button>
    <button class="dsa-dock__button dsa-ai-launcher">...</button>
    <button class="dsa-dock__button is-split-after-ai">...</button>
    <button class="dsa-dock__button is-split-segment-end">...</button>
  </nav>
</div>
```

Split dock applies only when `data-dsa-dock-presentation="dock"`. A navigation bar must ignore split styling even if a preview toggle accidentally leaves `dsa-dock-split` on the root. Navigation bar is not a horizontal dock: `navbar` is a separate presentation mode, while `horizontal` and `vertical` are orientation states for compact dock. Full compact dock, split compact dock, and Navigation bar are core dock modes; a theme may style their material, radius, badge treatment, and spacing, but may not convert one mode into another. If a handoff claims dock styling, preview pill (`dsa-dock-shape-pill`), rounded box (`dsa-dock-shape-box`), and square/no-radius (`dsa-dock-shape-square`) so reviewers can see the shape controls actually work.

Do not replace Geometry Engine behavior with one-off hardcoded offsets like `right: 94px`, `width: min(780px, 100%)`, or a fixed toolbar overlay. Classic mode must prove full app-viewport coverage unless a live Kiwe setting explicitly narrows it; a 390px side drawer is not sufficient Classic proof. If a preview needs controls, place them outside the app viewport or reserve space for them above the preview. Mixed-content rows such as logo plus optional score, social links, Shop/Cart actions, and metrics must wrap or rebalance before text becomes unreadable; shrinking labels into fragments is a preview and theme failure. If the Links payload has no site score, omit the score badge entirely. Do not render `0`, a blank white card, or placeholder score copy.

Responsive proof must include desktop, tablet, and mobile Geometry Engine profiles, then add narrow stress cases such as 320px, 360px, and 390px. A preview that only tests phone widths is incomplete.

## Preview controls

Preview controls must be outside the app viewport:

```html
<header class="kiwe-preview-toolbar">...</header>
<main class="kiwe-preview-stage">
  <div class="kiwe-preview-viewport">...DSA surface...</div>
</main>
```

Avoid `position: fixed` controls over the app unless the app viewport reserves space for them. A toolbar that overlaps the dock, checkout CTA, FBT rail, or sheet bottom is a preview bug.

## Required preview selectors

Screen mockups must preserve the runtime selectors from `screen-payloads.json` and `slots.md`.

Examples:

```html
<section class="dsa-panel dsa-profile-panel" role="dialog" aria-modal="false" data-dsa-profile-panel>
```

```html
<section class="dsa-panel dsa-cart-panel" role="dialog" aria-modal="false" data-dsa-cart-panel>
```

```html
<section class="dsa-panel dsa-search-panel" role="dialog" aria-modal="false" data-dsa-search-panel>
  <form data-dsa-search-form data-dsa-keep-open>
    <input data-dsa-search-input>
  </form>
  <div data-dsa-search-filters></div>
  <div data-dsa-search-alphabet></div>
  <div data-dsa-search-results></div>
</section>
```

Do not invent final production wrappers when the UI brain already names a required selector. If a designer must add wrapper elements for visual composition, keep them inside the screen root and do not remove required attributes.

## Placeholder rules

Good placeholders look like real neutral content:

- soft product thumbnails
- initials avatars
- logo monograms
- realistic short names and prices
- natural trust badges
- short sample copy

Bad placeholders dominate the design:

- giant debug labels
- red warning boxes
- `PREVIEW IMAGE HERE` banners
- layout-affecting placeholder text

Debug labels are allowed only behind a preview-only toggle such as `data-preview-debug="true"`.

## CSS rules

The preview may have preview-only CSS, but it must be visibly separated from the importable theme CSS.

Recommended:

```html
<link rel="stylesheet" href="../import/theme-id/css/theme.css">
<style>
  .kiwe-preview-toolbar { ... }
  .kiwe-preview-stage { ... }
</style>
```

Preview CSS should be namespaced with `.kiwe-preview-*`. Importable theme CSS should remain scoped to `.dsa-surface`, visual-profile classes, and stable `data-dsa-*` selectors.

## Seam adoption proof

If the preview or README discusses Seam classes inside DSA sheets/screens, it must respect `seam-vocabulary.json`:

- `public-adopted` roles may appear as public `seam-*` classes where Kiwe core applies them.
- `shadow-only` roles should be reviewed through DSA selectors and protected runtime metadata; do not make the theme depend on attaching public `seam-card`, `seam-button`, `seam-input`, `seam-media`, `seam-badge`, `seam-nav`, `seam-actions`, `seam-form`, `seam-field`, or `seam-modal` to live DSA internals.
- `authority-only` concerns such as cart, checkout, PhoneKey, Search, Bricks query, service worker, history, and focus trap must remain Kiwe/core owned.

The current adoption boundary can be checked from the repo root:

```bash
node tools/ui-theme/audit-seam-adoption.cjs
```

## What a broken preview proves

A broken preview proves one of these:

1. The preview shell is not faithfully simulating Kiwe geometry.
2. The preview markup does not match required screen selectors.
3. Preview controls or placeholders are interfering with app layout.
4. The importable theme CSS has a real issue.

Use `tools/ui-theme/validate-package.cjs` first. If the import package passes but the standalone HTML looks broken, inspect the preview shell before blaming the theme engine.

Use `tools/ui-theme/validate-handoff.cjs` when evaluating a complete folder returned by an AI/designer. Passing handoff validation still is not visual approval, but failing it means the preview does not prove the Kiwe contract yet.

## Current limitation

The UI brain provides contract fixtures, not a live WordPress renderer. Pixel-perfect preview snapshots still need to be checked inside the real plugin because production markup, WooCommerce state, Bricks bridges, and responsive Geometry Engine measurements are runtime-owned.

The standalone preview is a design review aid. The live plugin remains the final truth.
