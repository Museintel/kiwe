# Kiwe combined handoff lite context

Use this file when a browser AI needs to create a Kiwe website/page plus Kiwe DSA/AppShell direction without reading the full repository.

Do not read the whole repo. Do not output a React/Vite/Tailwind/shadcn app. The output must be plain HTML/CSS with optional preview-only JS.

## Goal

Create a combined Kiwe handoff:

- a normal WordPress/Bricks website/page using Kiwe/Seam ideas; and
- a matching Kiwe DSA/AppShell theme direction for dock, sheets/screens, and AppShell chrome.

Keep these lanes separate:

- `website/` is for the page/website preview and Bricks paste artifact.
- `appshell-theme/` is for the importable Kiwe DSA theme package and its preview.
- `kiwe-settings/` is optional and only for Kiwe admin/profile settings.

## Required output shape

```text
combined-kiwe-handoff/
  README.md
  combined-preview/
    index.html
    assets/
      combined-preview.css  # optional, preview-only
      combined-preview.js   # optional, preview-only
  website/
    preview/
      index.html
      assets/
        site.css
        site.js       # optional, preview-only
    bricks-paste.html
    bricks-notes.md
  appshell-theme/
    README.md
    import/
      [theme-id]/
        theme.json
        css/
          theme.css
    preview/
      index.html
      PLACEHOLDERS.md
  kiwe-settings/
    kiwe-appsite-profile.json   # optional, only if settings change
    SETTINGS-NOTES.md           # optional, only if settings change
```

## Combined preview rule

`combined-preview/index.html` is the primary human review artifact for combined mode.

It should show the website/page behind the Kiwe DSA dock/sheet/screen so the site owner can judge the full AppShell experience in one place. This is where the DSA theme should be experienced over the actual page design.

The separate previews still exist, but they are technical fixtures:

- `website/preview/index.html` proves the page/Bricks lane.
- `appshell-theme/preview/index.html` proves the AppShell theme against validator selectors and shell states.
- `combined-preview/index.html` proves the paired experience.

The combined preview may simulate save/cart/search/screen switching only as preview-only behavior. Production behavior remains Kiwe/WordPress/WooCommerce/Bricks-owned.

## Website/page rules

- `website/preview/index.html` must be standalone and viewable in a browser.
- `website/preview/assets/site.css` must contain the page visual CSS.
- `website/preview/assets/site.js` is optional and must be preview-only.
- `website/bricks-paste.html` is required. It is the copy/paste artifact for Bricks HTML-to-Bricks import.
- Do not require React, Vite, Tailwind, shadcn, Next, a build pipeline, generated Bricks IDs, or hidden local files.
- Use semantic HTML, class-based CSS, reusable variables, minimal inline styles, and Bricks-friendly structure.

Seam is semantic/headless. Use Seam classes/attributes for meaning and structure where helpful, but use custom page CSS for the actual visual art direction.

Useful website Seam vocabulary:

- `seam-horizontal-rail`
- `seam-vertical-rail`
- `seam-story`
- `seam-article`
- `seam-card`
- `seam-grid`
- `seam-stack`
- `seam-cluster`
- `seam-tabs`
- `seam-toc`
- `data-role`
- `data-flow`
- `data-tone`
- `data-state`

## Authority boundaries

Do not create production authority for:

- cart, checkout, payment, discounts, orders, WooCommerce mutation;
- auth, PhoneKey, profile updates, logout, addresses, downloads;
- search query authority or Bricks query authority;
- save/bookmark/wishlist state authority;
- service workers, cache policy, browser history, focus trap, scroll lock;
- AI actions, notification permission, Push subscription, privacy/master switches.

Preview UI can show these affordances, but production behavior must be documented as Kiwe/WordPress/WooCommerce/Bricks-owned.

## AppShell theme.json quick contract

If your output includes `appshell-theme/import/[theme-id]/theme.json`, copy this shape and only change theme-specific values.

Do not invent alternate manifest keys.

Important:

- Use `schema`, not `type`.
- Do not use `schemaVersion` in AppShell theme manifests. `schemaVersion` is only used by optional Kiwe settings profiles.
- Do not use nested `contracts`, `colorAuthority`, `authority`, `supportedPresentationModes`, `supportedDockShapes`, `cssFiles`, or object-form `supports`.
- `supports` must be an array of allowed strings.
- `screens` must use Kiwe screen names only.

```json
{
  "schema": "kiwe.surface-theme.v1",
  "id": "your-theme-id",
  "name": "Your Theme Name",
  "version": "1.0.0",
  "profile": "marketplace",
  "mode": "css-only",
  "description": "Short presentation-only Kiwe DSA AppShell theme description under 240 characters.",
  "author": "Your name or team",
  "css": ["css/theme.css"],
  "assets": [],
  "screens": ["profile", "cart", "checkout", "search", "menu", "saved", "links", "notifications", "ios-install", "games", "ai"],
  "requires": {
    "uiContract": "kiwe.surface-ui.v2",
    "tokenContract": "kiwe.universal",
    "minKiwe": "0.5.75"
  },
  "supports": ["light", "dark", "sheet", "classic", "dock", "split-dock", "full-dock", "navigation-bar", "dock-shape-pill", "dock-shape-box", "dock-shape-square", "horizontal", "vertical", "reduced-motion"],
  "budgets": {
    "cssKb": 40,
    "jsKb": 0,
    "blockingAssets": 0
  },
  "forbidden": ["remote-code", "trackers", "php", "service-worker", "history-owner", "cart-owner", "checkout-owner", "phonekey-owner", "bricks-owner"]
}
```

If a theme does not cover a screen, remove that screen from `screens`. Do not add unsupported screen names such as `orders`, `downloads`, `addresses`, or `install`; those are payload sections or concepts inside supported screens, not theme-manifest screen IDs.

## AppShell importable CSS rules

The importable package is only:

- `theme.json`
- CSS files listed in `theme.json`
- listed static image assets, if any
- optional human README

The import package must not contain JavaScript, TypeScript, PHP, HTML, WASM, remote fonts, remote scripts, trackers, service workers, executable files, remote `@import`, remote `url()`, data URLs, or JavaScript URLs.

The theme CSS is presentation-only. It may style existing DSA selectors and allowed public Seam classes. It must not create runtime authority.

## AppShell preview quick contract

If your output includes `appshell-theme/preview/index.html`, it must prove the theme against Kiwe's actual preview selectors and Geometry Engine states. A pretty mock phone is not enough.

Minimum preview shell requirements:

- Include a root with `data-dsa-surface`.
- Include `data-dsa-ui-contract="2"`.
- Include `data-dsa-dock-presentation` and demonstrate dock plus navigation bar values; use `navbar` for the navigation-bar runtime value.
- Include `data-dsa-dock-orientation`.
- Include Geometry Engine variables in the preview markup/style:
  - `--dsa-dock-control-size`
  - `--dsa-dock-only-reserve`
  - `--dsa-screen-block-reserve`
- If `supports` includes `split-dock`, include `dsa-dock-split`.
- If `supports` includes dock shapes, demonstrate:
  - `dsa-dock-shape-pill`
  - `dsa-dock-shape-box`
  - `dsa-dock-shape-square`
- If `supports` includes dark mode, include `data-kiwe-theme="dark"`.
- Link the importable CSS from `../import/[theme-id]/css/theme.css`; the preview must demonstrate the real import CSS.
- Keep preview controls outside the app viewport, preferably using `kiwe-preview-toolbar` and `kiwe-preview-stage`.

Required screen selectors when the theme manifest lists these screens:

- `profile`: `data-dsa-profile-panel`
- `cart`: `data-dsa-cart-panel` and `data-dsa-cart-fbt-rail`
- `checkout`: `data-dsa-checkout-panel` and `data-dsa-checkout-form`
- `search`: `data-dsa-search-panel`, `data-dsa-search-form`, `data-dsa-search-input`, and `data-dsa-search-results`
- `menu`: `dsa-menu-panel`
- `saved`: `data-dsa-saved-panel`
- `links`: `dsa-links-panel`
- `notifications`: `data-dsa-notification-panel`
- `ios-install`: `data-dsa-ios-install-panel`
- `games`: `data-dsa-game-panel`
- `ai`: `data-dsa-ai-panel`

Cart FBT must be a horizontal rail. Include `data-dsa-cart-fbt-rail` on that rail. Do not render it as a stacked list.

Links site score is optional. The preview and README must show/document both:

- score present; and
- score absent/no score/without score, where no badge is rendered at all.

## AppShell README requirements

The AppShell handoff README must include:

- distinctness note / visual thesis;
- screen coverage summary;
- shell mode coverage summary;
- selector-fit checklist;
- intentional limitations;
- core/plugin changes section, including "no core/plugin changes" when true;
- Seam AppShell adoption map acknowledgement;
- validation commands.

## Visual originality

Avoid generic Aurora/glass/bento/neon sameness unless explicitly requested. If using blur/glass, explain why it is not just another frosted-card theme. Prefer a distinct thesis such as editorial newsroom, cinematic markets desk, luxury business briefing, tactical founder dashboard, or quiet OS.

## Validation commands

The final handoff should be able to pass:

```bash
node tools/ui-theme/validate-package.cjs appshell-theme/import/[theme-id]
node tools/ui-theme/validate-handoff.cjs appshell-theme
```

For generated AI output review, also use:

```bash
node kiwe-ai-toolkit/tools/audit-output.cjs combined-kiwe-handoff
```
