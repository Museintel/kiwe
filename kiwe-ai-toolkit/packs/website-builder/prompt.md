# Prompt for using Kiwe Framework

You are working with Kiwe Framework, the Seam-powered design/development framework bundled with Kiwe DSA.

Read this entire `framework-system/` folder before proposing code or design.

## Goal

Use the Kiwe Framework to design or implement WordPress/Bricks page sections, reusable classes, or builder-safe design patterns that align with Kiwe’s AppShell and token system.

Kiwe is an AppShell framework for WordPress. The page you design may be imported into Bricks, and the site may also run Kiwe DSA sheets/screens/dock on top of it.

## Layer split

Do not confuse these layers:

- **Website/page:** normal WordPress or Bricks pages. You may use Seam Framework, custom CSS, or neither. The web developer has liberty here.
- **Kiwe AppShell theme:** how Kiwe DSA sheets, screens, dock, and AppShell surfaces look and arrange existing capabilities. That is handled by `ui-system/`, not this framework prompt.
- **Kiwe capabilities:** save/bookmark/wishlist, Links, Menu, menu context, Search, WooCommerce cart/checkout, AI, PhoneKey/auth, notifications, privacy, service worker, and history/focus behavior. These are core/plugin authority, not website preview JavaScript.

## Read order

1. `README.md` for the framework boundary.
2. `source-map.md` for canonical source locations.
3. `HANDOFF-MODES.md` if the assignment might include both website/page work and a DSA AppShell theme.
4. `contracts/seam-vocabulary.md` and `contracts/seam-vocabulary.json` for roles, flows, tones, states, and AppShell adoption rules.
5. `contracts/seam-class-vocabulary.md` and `contracts/seam-class-vocabulary.json` for the searchable neutral class library pushed to Bricks.
6. `contracts/token-map.css` and `contracts/tokens-reference.md` for public tokens.
7. `runtime/seam.css` and `runtime/seam.js` to understand the available framework CSS/runtime helpers.
8. `bricks/bricks-capabilities.json` and `bricks/BRICKS-INTEGRATION.md` for Bricks support and boundaries.

## What you may create

- Bricks sections using `kiwe-*` variables and `seam-*` classes.
- WordPress HTML/CSS sections using public Seam vocabulary.
- Standalone previewable HTML pages that can be visually reviewed before being brought into Bricks.
- Documentation for how a designer should use the framework.
- Proposals for new framework roles/classes/tokens.
- A combined website/page + DSA AppShell theme handoff only when the assignment explicitly asks for both; follow `HANDOFF-MODES.md`, keep website/page output separate from the AppShell theme package, and place DSA theme settings inside `theme-package.json`.

Custom CSS is allowed. Seam-native does not mean zero custom CSS; it means Kiwe/Seam tokens, vocabulary, and behavior boundaries stay authoritative.

Seam roles are semantic/headless by default. `data-role="card"` or `.seam-card` should identify an element as a card-like thing, but must not be relied on to create the visual design. Seam core does not ship generic card/button/modal padding, radius, border, shadow, or background. Build the visual layer with neutral framework primitives, universal Kiwe/Seam tokens, and the searchable Seam Class Vocabulary. Prefer existing generic classes such as `seam-card`, `seam-accordion`, `seam-table`, `seam-horizontal-rail`, `seam-size-xl`, `seam-density-spacious`, and `seam-emphasis-featured` before inventing new names.

Prefer the Kiwe/Seam token library first. If a design genuinely needs a missing art-direction variable, propose a generic addition to the universal token library instead of inventing project-locked token names. Temporary preview-only variables are allowed only when clearly documented.

## What you must not create

Do not create a second authority for:

- cart, checkout, payment, discounts, or orders;
- auth, PhoneKey, profile/account mutation, or logout;
- Search or Bricks query ownership;
- service workers, cache policy, browser history, focus trap, or scroll lock;
- notification permission, Push subscription, AI actions, or privacy/master switches.

## DSA AppShell caution

Public WordPress/Bricks page sections may use the public Seam vocabulary directly.

Live DSA sheets/screens are stricter. Before assuming a public Seam class is safe inside DSA, check `contracts/seam-vocabulary.json` → `appShellAdoption`.

- `public-adopted` means Kiwe core may apply the public class inside DSA.
- `shadow-only` means DSA only gets protected `data-seam-*` metadata for now.
- `authority-only` means do not touch it from framework/page code. If you are designing a Kiwe AppShell theme, follow `ui-system/` instead.

Do not attach high-impact classes such as `seam-card`, `seam-button`, `seam-input`, `seam-media`, `seam-badge`, `seam-nav`, `seam-actions`, `seam-form`, `seam-field`, or `seam-modal` to live DSA internals unless the adoption map is updated by Kiwe core.

## Bricks usage

`Kiwe > Framework` pushes the active framework to Bricks as:

- variables;
- color palette;
- Seam global classes/categories, including the neutral searchable class vocabulary.

Use Bricks as the page-design authority. Do not hardcode site-specific Bricks element IDs or copy Bricks post meta between sites.

Bricks 2.4 beta includes an HTML-to-Bricks converter pipeline. Make standalone previews conversion-friendly: semantic HTML, class-based CSS, reusable variables, limited inline styles, and clear separation between preview-only JavaScript and production behavior.

## Preview requirements

When creating a standalone preview:

1. Include `index.html`.
2. Include CSS that references Kiwe/Seam variables where possible.
3. Include mock content only where the live plugin/WordPress/Bricks would normally supply data.
4. Mark placeholder behavior in comments or a short `README.md`.
5. Do not implement production save, cart, checkout, auth, search-query, AI, or Bricks-query behavior in preview JavaScript.
6. For bookmark/wishlist/save UI, use Kiwe/DSA save attributes or clearly mark the interaction as preview-only.
7. Do not treat high Seam usage as visual quality. A good page uses Seam for structure/meaning/tokens and lets the site CSS create the creative layer without duplicating Kiwe-owned behavior.

## Expected output

When returning work, include:

1. What framework pieces you used.
2. Whether the work targets normal WordPress/Bricks pages or DSA AppShell internals.
3. Any proposed new tokens/classes/roles.
4. Any core/plugin changes required.
5. Validation notes.
6. Whether the preview is ready for Bricks HTML-to-Bricks conversion or only visual review.
