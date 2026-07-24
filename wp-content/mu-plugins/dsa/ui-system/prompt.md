# Prompt for designing a Kiwe DSA AppShell theme

You are designing a new visual theme for Kiwe DSA, a WordPress MU plugin that turns a normal WordPress/WooCommerce site into an AppShell-style surface. The theme is for the Kiwe Surface/AppShell that sits on top of the website: dock, sheets/screens, module panels, cart surface, search surface, profile/account surface, Links hub, saved items, notifications, iOS install guide, games shell, and AI shell.

First read this entire `ui-system/` folder before designing. Treat this folder as the UI brain and contract source. Do not assume access to the full plugin codebase.

## How to navigate this folder

Read files in this order:

1. `README.md` to understand the theme system and current built-in profiles.
2. This `prompt.md` to understand the assignment and output requirements.
3. `HANDOFF-MODES.md` if the assignment asks for both a website/page and a DSA AppShell theme.
4. `screen-payloads.json` and `slots.md` to learn what screens, payload fields, selectors, and slots exist.
5. `token-map.css`, `tokens-reference.md`, and `budgets.md` to understand the token system, the difference between stable theme tokens and internal runtime variables, geometry variables, size limits, motion limits, and low-tax performance expectations.
6. `preview-handoff.md` to learn exactly how to build the standalone preview.
7. `theme-manifest.schema.json` and `marketplace-package.md` to build the importable package correctly.
8. `profiles/legacy.css` and `profiles/kiwe-2027.css` to understand the current built-in baseline styles.
9. `bricks-capabilities.json` to understand Bricks/dynamic tag capabilities and boundaries.
10. `seam-vocabulary.md`, `seam-class-vocabulary.md`, `seam-vocabulary.json`, and `seam-class-vocabulary.json` to understand public Seam roles/classes, the protected Kiwe `data-seam-*` shadow contract, and the `appShellAdoption` map that says which Seam roles are safe as public classes inside live DSA screens.
11. `handoffs/legacy-ui-review/` as an example of a review/preview handoff.

## Goal

Create a new Kiwe DSA AppShell visual theme in the requested visual style, for example neumorphism, glassmorphism, editorial luxury, playful commerce, minimalist SaaS, etc.

Your theme may change the look, layout, hierarchy, spacing, material, typography, icon treatment, badge style, card style, and screen arrangement. It must not create new runtime authority.

If the assignment asks for a website/page and a DSA AppShell theme together, follow `HANDOFF-MODES.md`: keep website/page files, AppShell theme package, and standalone previews separate. Theme settings belong inside the AppShell theme package, not in a separate settings/profile folder.

## Originality requirement

Do not treat "modern" or "ultra-modern" as permission to default to generic glassmorphism, Aurora gradients, floating blurred cards, or Bento dashboards. Those directions are allowed only when the site owner explicitly asks for them.

Before writing files, choose a distinct design thesis and name. The thesis must explain how the theme differs from both built-in references:

- Legacy: the lowest-tax compact baseline.
- Kiwe 2027: the current modern/prototype-style profile.

If the requested style is broad, such as "ultra modern", avoid the overused names and concepts `Aurora`, `Glass`, `Flow`, `Bento`, `Neon`, and `Neo` unless the site owner requested that exact direction. Prefer a specific commerce/appshell concept, for example editorial catalog, tactical dashboard, soft utility, high-contrast retail, calm healthcare, luxury concierge, kinetic cards, quiet OS, etc.

Your final handoff must include a short "distinctness note" in `README.md` covering:

1. The visual thesis.
2. What it deliberately does differently from Legacy.
3. What it deliberately does differently from Kiwe 2027.
4. Whether it uses blur/glass; if yes, why it is not merely another frosted-card theme.

The root handoff `README.md` is machine-checked. It must include clearly named sections or unmistakable text for:

- distinctness note / visual thesis
- screen and shell mode coverage
- selector-fit checklist
- intentional limitations
- core/plugin changes, including "no core/plugin changes" when none are needed
- validation commands for both package and full handoff validation

The output must let the site owner do two things:

1. Open a standalone `preview/index.html` and visually review the theme with realistic placeholder content before installing anything.
2. Install or validate only the safe importable package inside `import/your-theme-id/`.

## Files you must use

- `README.md` for the current state of the theme system.
- `theme-manifest.schema.json` for the importable theme package shape.
- `marketplace-package.md` for import/export and acceptance rules.
- `preview-handoff.md` for how to build a viewable HTML preview.
- `screen-payloads.json` for the screens, payload fields, and selectors themes may consume.
- `slots.md` for stable `data-dsa-*` slots and runtime ownership.
- `token-map.css` for universal tokens and compatibility aliases.
- `tokens-reference.md` for what is intentionally included or excluded from the portable token map.
- `budgets.md` for size, paint, motion, and authority constraints.
- `bricks-capabilities.json` for Bricks hooks, dynamic tags, builder controls, and boundaries.
- `profiles/legacy.css` to understand the low-tax Legacy baseline.
- `profiles/kiwe-2027.css` to understand the built-in modern profile direction.
- `adapters/adapter-contract.js` and `adapters/profile.kiwe-2027.example.js` for adapter expectations.
- `handoffs/legacy-ui-review/` for an example review handoff.

## Non-negotiable rules

Do not create or simulate a second authority for:

- cart, checkout, payment, WooCommerce mutation, discounts, or order ownership
- auth, PhoneKey, profile update, logout, addresses, downloads, or account ownership
- Search query authority, Bricks query authority, Bricks dynamic tag resolution, or filters
- service worker, PWA install, cache policy, browser history, focus trap, or scroll lock
- AI actions, notification permission, Push subscription, or privacy/master switches

Themes are presentation only. Kiwe owns capability, state, lifecycle, security, and commerce.

## Required shell modes

Your theme must account for all core shell states it supports in `theme.json`:

- Sheet and Classic presentation.
- Full compact dock.
- Split compact dock.
- Navigation bar.
- Horizontal and vertical dock orientation.
- Light and dark mode.
- Narrow, compact, and wide layout states.
- Reduced motion.

Dock shape is a real core setting. If your theme supports dock styling, preview and support:

- `dsa-dock-shape-pill`
- `dsa-dock-shape-box`
- `dsa-dock-shape-square`

Use the runtime variables:

- `--dsa-dock-shell-radius`
- `--dsa-dock-control-radius`
- `--dsa-dock-segment-radius`

Do not hardcode one dock radius and ignore admin shape controls.

## Screen rules

Preserve all required selectors from `screen-payloads.json` and `slots.md`.

A Kiwe AppShell theme is a reusable visual skin, not a screenshot of the currently enabled dock icons. Treat `theme-package.json` root `settings` as the recommended site preset:

- `theme-package.json` root `settings` may hide dock icons or choose a site-specific order/focus item.
- Hiding a dock icon does not remove that registered DSA module from the plugin.
- `theme-package.json` root `settings.screens` may carry sanitized presentation/copy labels for live registered DSA screens/sheets. Allowed screen keys are `profile`, `cart`, `checkout`, `search`, `menu`, `saved`, `links`, `notifications`, `ios-install`, `games`, and `ai`. This lane may rename labels, titles, helper text, empty states, safe CTA labels, Cart FBT rail title, Profile row labels, Links shop/cart labels, notification form labels, iOS install steps, game labels, and AI empty/chat copy. It must not contain products, orders, saved items, profile identity, menu items, search results, social URLs, score values, notification state, AI messages/actions, cart line items, totals, checkout/payment URLs, JavaScript, endpoints, or state authority.
- Importable theme CSS must provide a resilient baseline for every registered core screen in `screen-payloads.json`: profile, cart, checkout, search, menu, saved, links, notifications, iOS install, games, and AI.
- If the combined preview is for a non-commerce/news site, it may hide cart/checkout/order-heavy UI, but enabling cart later in Kiwe admin must still inherit the theme’s panel, card, button, form, badge, rail, and CTA language without looking broken.
- `theme.json.screens` should list all core screens the theme can safely skin. A partial theme that only skins the screens shown in the preview must clearly label itself as partial and is not marketplace-ready.
- Use broad stable selectors and tokens for shared shell language, then add light module-specific refinements only where needed. Do not make the design depend on a fixed dock order or fixed set of enabled icons.

Use the Seam adoption map correctly:

- Public WordPress/Bricks page sections may use the normal public Seam vocabulary.
- Importable AppShell theme CSS may style existing DSA selectors and public Seam classes.
- Kiwe runtime-scopes installed theme CSS to the active `#dsa-surface[data-dsa-surface].dsa-installed-theme-[theme-id]` root, but that root is transparent runtime scaffolding. Root selectors may set custom properties, inherited text color, and inherited typography; they must not paint the AppShell root with backgrounds, borders, shadows, opacity, or filters. Put visual surfaces on dock/sheet/screen/panel and documented part hooks.
- Do not add protected `data-seam-*` attributes to theme markup or CSS.
- Do not assume high-impact classes such as `seam-card`, `seam-button`, `seam-input`, `seam-media`, `seam-badge`, `seam-nav`, `seam-actions`, `seam-form`, `seam-field`, or `seam-modal` are safe to attach to live DSA internals. Check `appShellAdoption.shadowOnly` first.
- If you believe a shadow-only role should become public-adopted, document that as a proposed core change. Do not silently depend on it in the importable theme.

Important examples:

- FBT / frequently bought together must remain a horizontal side-scrolling rail. You may style cards, but do not turn the rail into a stacked list or static grid.
- Links site score is optional. If no score exists in the payload, omit the score badge entirely. Do not render `0`, a blank score card, or placeholder score text.
- Menu page table of contents must preserve anchor selectors and be clickable.
- Top-level screen title tag is Theme-owned through Kiwe > Theme, but nested product/content/report headings keep their own semantics.
- Checkout CTA and AI chat placeholder must flow with panel content and must not float over products/messages.
- Sheet scrollbars must not visually cut into rounded sheet corners.
- No content may render under the dock, navigation bar, safe area, or browser chrome reserve.

Do not invent new structural wrapper classes and then claim the theme needs no core change. If your CSS depends on a class, attribute, or wrapper that is not listed in `screen-payloads.json`, `slots.md`, `preview-handoff.md`, or the built-in profile references, call it out as a core/plugin change instead of silently using it in the importable CSS.

If you use Seam framework selectors, use only the published public vocabulary:

- public classes such as `.seam-card`, `.seam-grid`, `.seam-tone-brand`, `.seam-scene-elevated`, `.seam-gap-md`
- public attributes such as `[data-role="card"]`, `[data-flow="grid"]`, `[data-tone="brand"]`

Do not style or depend on protected Kiwe shadow attributes such as `[data-seam-role]`, `[data-seam-slot]`, or `[data-seam-surface-panel]` in importable theme CSS. Those attributes are runtime metadata for tooling and diagnostics, not theme styling hooks.

Every preview must include a selector-fit checklist in its `README.md`:

- Which stable `.dsa-*` classes and `data-dsa-*` selectors are styled.
- Which optional selectors are demonstrated.
- Whether any desired layout would require a future adapter-profile or core wrapper.
- Confirmation that FBT remains horizontal, score can be hidden when absent, dock shape controls visibly change the dock, and sheet content does not pass beneath the dock.

## Color, typography, and design tokens

Kiwe now has a shared design-token profile under `Kiwe > Framework`. Active and Hover colors remain legacy-compatible controls that map to `--kiwe-color-brand` and `--kiwe-color-accent`, but they are no longer the ceiling of the design system.

Use canonical `--kiwe-*` tokens and aliases from `token-map.css` for palette, typography, site background, spacing, radius, motion, and elevation. If your theme needs a live visual personality beyond CSS selectors, include `settings.tokens` inside `theme-package.json`:

- `tokens.enabled`
- `tokens.profile_label`
- `tokens.overrides` using official Kiwe universal token names only
- `tokens.bricks_theme_style` when the same personality should be pushed to Bricks as safe global typography, colors, links, and site background

Do not create a hidden large palette, font system, or heading scale that cannot map back to Kiwe tokens. Importable `theme.css` should consume the token profile; it should not be the only place where the design personality exists.

Never use generated `--dsa-runtime-token-####` variables in handoff CSS, preview CSS, or documentation. Those are private Kiwe core bridge tokens for runtime token-purity validation, not public design tokens. Use official `--kiwe-*` variables, documented `--kiwe-theme-*` aliases, or propose a missing generic token for promotion into the universal library.

Never place anonymous raw CSS literals directly in importable AppShell `theme.css`. If a design needs a concrete size, radius, border width, color, shadow, or type scale, define it through `theme-package.json settings.tokens` or use an existing Kiwe/DSA Geometry Engine variable, then consume that variable in CSS. Examples: use `var(--kiwe-space-md)`, `var(--kiwe-radius-xl)`, `var(--kiwe-color-text)`, `var(--kiwe-shadow-md)`, or `var(--dsa-geometry-dock-border, thin)`, not `24px`, `18px`, `#10231d`, `rgb(...)`, `0 18px 48px`, or `1px`.

## Output format

Return a complete handoff folder shaped like this:

```text
theme-handoff/
  README.md
  import/
    your-theme-id/
      theme.json
      css/theme.css
      assets/optional-static-assets-only.svg
      README.md
  preview/
    index.html
    PLACEHOLDERS.md
```

Only the `import/your-theme-id/` folder is importable. The preview is for humans only.

Do not return only screenshots, a Figma-style description, or a single CSS file. The required output is a complete handoff folder with both an importable package and a standalone preview.

## Importable package rules

The importable theme package may contain:

- `theme-package.json` as the single Kiwe admin/API import file containing the theme manifest, safe theme settings preset, and inline CSS
- `theme.json`
- listed CSS files
- listed static image assets
- optional human README

It must not contain:

- JavaScript, TypeScript, PHP, HTML, WASM, remote fonts, remote scripts, trackers, service workers, or executable files
- remote `@import`, remote `url()`, data URLs, or JavaScript URLs in CSS
- preview-only mocks, simulator code, or placeholder product/account data

Theme settings belong inside the theme package, not in a separate loose settings export/import. Keep `theme.json` as the manifest-only validator file. Put dock composition, focus item, shape, sheet behavior, active theme id, design-token profile, color settings, visual-effect presets, and safe screen copy settings in `theme-package.json` under root `settings`. Put the same CSS as `css/theme.css` in `theme-package.json` under root `css` so Kiwe can import one file and show it under `Kiwe > Theme > Installed themes`.

If your preview uses custom live-intended screen/sheet copy, declare the same copy in `theme-package.json` under `settings.screens`. Examples: "Your tea-time bag" belongs in `settings.screens.cart.title`, "Pairs well with" belongs in `settings.screens.cart.fbtTitle`, "Your account" variants belong in `settings.screens.profile.title`, Links action labels belong in `settings.screens.links`, Search placeholder belongs in `settings.screens.search.placeholder`, and AI chat copy belongs in `settings.screens.ai.chatPlaceholder`. This is copy only: do not place products, prices, totals, profile/user data, social URLs, search results, checkout URLs, cart state, JavaScript, or behavior there.

Importable theme CSS must not own AppShell geometry. Kiwe's Geometry Engine owns dock, sheet, screen, and backdrop placement and measurement. Do not set `position: fixed`, `position: absolute`, `inset`, `top`, `right`, `bottom`, `left`, hardcoded `z-index`, `width: 100vw`, `height: 100vh`, or hardcoded viewport offsets on `[data-dsa-dock]`, `.dsa-dock`, `[data-dsa-screen]`, `.dsa-panel`, `.dsa-sheet`, `[data-dsa-screen-backdrop]`, or sheet/screen backdrop selectors. Those properties belong in core or preview-only CSS. Theme CSS may style color, typography, border, radius, shadow, inner spacing, icons, badges, cards, buttons, and state appearance while consuming Geometry Engine variables.

Importable theme CSS is also token-pure: it must consume named tokens/variables instead of anonymous raw `px` literals. Values such as `35px`, `22px`, `1px`, and `999px` are allowed only in token settings/core token definitions, not in installed theme CSS declarations or CSS custom-property declarations.

Importable theme CSS must also not paint the protected AppShell root itself. Do not set `background`, `background-color`, `background-image`, `border`, `box-shadow`, `filter`, `backdrop-filter`, or `opacity` directly on `[data-dsa-surface]`, `#dsa-surface`, or `.dsa-installed-theme-[theme-id]` root selectors. The DSA root is the transparent layer Kiwe uses to coordinate geometry, state, dock reserve, and external modal yield.

Use the live runtime hooks in `screen-payloads.json` for screen internals. Kiwe annotates rendered panels with `data-dsa-screen-name` and `data-dsa-part`; protected `data-seam-*` shadow metadata may exist for tooling and AI understanding, but importable theme CSS should not style or depend on those protected attributes. Importable `theme.css` must use documented live part hooks for real theme composition, for example `[data-dsa-screen-name="cart"] [data-dsa-part="summary"]`, `[data-dsa-screen-name="menu"] [data-dsa-part="context"]`, `[data-dsa-screen-name="profile"] [data-dsa-part="identity"]`, `[data-dsa-part="card"]`, `[data-dsa-part="row"]`, and `[data-dsa-part="action"]`. A package that only styles `[data-dsa-screen]`, `.dsa-panel`, buttons, and colors is a skin, not a complete AppShell theme. For cart, stable theme hooks also include `[data-dsa-cart-line]`, `.dsa-cart-line`, `.dsa-line-thumb`, `.dsa-quantity`, `[data-dsa-cart-fbt-card]`, `.dsa-fbt-card`, and `.dsa-fbt-img`; do not style only preview-only item/card names.

## Preview rules

The preview must be viewable as standalone HTML, but preview code is not the theme.

Preview controls must live outside the app viewport. Do not overlay preview controls on top of the dock, sheet, checkout CTA, FBT rail, or AI chat.

The preview must include placeholder content, but placeholders must be preview-only and documented in `preview/PLACEHOLDERS.md`. Placeholder product images, account names, order counts, score values, social links, search results, cart totals, trust badges, and AI signals must not appear in the importable package.

Preview placeholders must be local or pure CSS/HTML. Do not use remote image URLs, remote fonts, analytics, third-party scripts, or live network resources in the preview. If you need photography, use neutral local SVG/CSS placeholders and document them.

Build the preview like this:

- `preview/index.html` contains the preview shell, mock data, optional preview-only controls, and realistic placeholder markup.
- Link the importable CSS from `../import/your-theme-id/css/theme.css` so the preview demonstrates the actual theme CSS.
- Use production-style attributes such as `data-dsa-surface`, `data-dsa-ui-contract="2"`, `data-dsa-dock-presentation`, `data-dsa-dock-orientation`, `data-dsa-layout`, `data-dsa-density`, and the required screen selectors.
- Use Geometry Engine variables such as `--dsa-dock-control-size`, `--dsa-dock-ai-size`, `--dsa-dock-only-reserve`, `--dsa-screen-block-reserve`, and the dock shape radius variables.
- Include preview controls for switching at least light/dark, sheet/classic, viewport size, full compact dock, split compact dock, Navigation bar, and dock shape if the theme claims support for those modes.
- Keep all preview controls outside `.dsa-surface`.
- For combined website/page + AppShell handoffs, put these controls in `combined-preview/index.html`. The combined preview is the primary visual proof. A separate `appshell-theme/preview/index.html` is optional technical proof only and must not be the only place where dock modes, shapes, Classic, or device profiles are tested.
- If the combined preview loads `website/bricks-paste.html` in an iframe, add preview-only bridge JavaScript so canonical page/header launchers such as `data-dsa-open-module="cart"`, `data-dsa-open-module="profile"`, and `data-dsa-open-module="search"` open the corresponding preview DSA screen.

The preview should demonstrate:

- Every core screen listed in `screen-payloads.json` at least once in either the AppShell preview or a documented optional-state panel, unless the README explicitly labels the output as a partial/non-marketplace theme.
- Profile/account only when membership, login, account, or personalization is part of the brief/settings.
- Orders/downloads/addresses/password/profile actions only if represented by the theme and relevant to the appsite.
- Cart with quantity controls, checkout CTA, trust badges, and FBT rail only when commerce, WooCommerce, shop, products, paid reports, subscriptions, or checkout are part of the brief/settings.
- Search with filters/alphabet/results
- Menu with page table of contents
- Links/social hub with optional score hidden and shown states
- Saved
- Notifications when notification preferences, alerts, updates, briefs, or push/PWA journeys are part of the brief/settings
- iOS install
- AI/inbox/report shell
- Dock modes and dock shape modes
- Sheet/classic, light/dark, narrow/compact/wide

Use natural placeholder data. Do not fill the UI with debug labels.

For combined website/page + AppShell handoffs, match the visible AppShell preset to the website type. A news/editorial website should not automatically show cart, checkout, orders, downloads, or addresses just because the prompt says "Netflix-like"; only show commerce/account screens when the brief, Kiwe settings profile, or site business model requires them. However, the importable theme must still skin those registered screens through shared theme language so a site owner can enable them later without visual breakage. If an optional screen is hidden in the current preset, document that it is supported but hidden in the README/settings notes.

Navigation bar is not a horizontal dock. `data-dsa-dock-presentation="navbar"` is a distinct presentation mode. Horizontal and vertical are dock orientations under `data-dsa-dock-presentation="dock"`. Split dock applies only when presentation is `dock`.

Classic mode must prove full app-viewport coverage unless a live Kiwe setting explicitly narrows it. Do not use a 390px side drawer as the only Classic proof.

Responsive fit is a hard quality gate. The standalone/combined preview must prove desktop, tablet, and mobile Geometry Engine profiles, then also check narrow mobile stress widths around 320px, 360px, and 390px. No DSA sheet/screen may create horizontal page or panel scrolling unless the element is an intentional rail such as FBT, alphabet/search filters, or another documented horizontal rail. Decorative header stripes, badges, labels, and pseudo-elements must shrink, wrap, clip inside the panel, or stack; do not use non-shrinking flex decorations that can force the panel wider than the viewport.

Also demonstrate "absent optional data" states. At minimum, include a Links screen state where site score is missing and therefore not rendered at all.

## Validation expectation

The theme should be able to pass:

```bash
node tools/ui-theme/validate-package.cjs path/to/theme-handoff/import/your-theme-id
```

The complete handoff should also pass:

```bash
node tools/ui-theme/validate-handoff.cjs path/to/theme-handoff
```

The current AppShell Seam adoption boundary should also remain clean:

```bash
node tools/ui-theme/audit-seam-adoption.cjs
```

The validators now also check Seam usage. Unknown `.seam-*` classes, invalid public Seam attribute values, protected `data-seam-*` selectors in importable CSS, and missing handoff acknowledgement of the `appShellAdoption` map fail validation.

Passing validation is not visual approval; it only means the first safety and preview-contract boundaries were not crossed.

## Final answer to the site owner

When you return the theme, include:

1. What visual style you implemented.
2. What screens and shell modes are covered.
3. The distinctness note.
4. The selector-fit checklist.
5. Any intentional limitations.
6. How to validate the import folder.
7. Any core/plugin changes you believe would be needed, clearly separated from theme CSS.
