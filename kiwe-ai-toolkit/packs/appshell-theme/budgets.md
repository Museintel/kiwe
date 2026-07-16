# Kiwe UI Budgets

The UI contract is a floor, not a ceiling, but themes must stay light enough to run on shared WordPress hosting and existing Woo/Bricks pages.

## Authority budgets

- 0 alternate auth systems.
- 0 alternate payment/checkout systems.
- 0 alternate cart mutation queues.
- 0 alternate service-worker policies.
- 0 theme-owned browser history managers.
- 0 cloned transactional controls without original DSA/Woo authority.

## Asset budgets

Recommended for a marketplace theme:

- CSS: up to 40 KB uncompressed for one visual profile; hard ceiling 80 KB.
- JS: 0 KB preferred; up to 20 KB for local presentation state; hard ceiling 40 KB.
- Blocking remote assets: 0.
- Fonts: use existing site/font tokens unless the site owner explicitly opts in.

## Runtime budgets

- No layout polling loops.
- No global mutation observer unless DSA core provides one.
- No scroll hijacking.
- No pointer-blocking overlays outside `[data-dsa-overlay-root]`.
- Use `prefers-reduced-motion`.
- Consume Geometry Engine attributes: `data-dsa-layout`, `data-dsa-density`, `data-dsa-dock-orientation`, `data-dsa-dock-presentation`, `data-dsa-sheet-*`.
- Use geometry-aware wrapping (`minmax(0, 1fr)`, bounded `clamp()`, container/media breakpoints, and tokenized gaps) for mixed-content rows. A theme must not allow badges, logos, commerce actions, or metrics to shrink labels into unreadable fragments as the visual viewport narrows.
- Use semantic text/background tokens in both `html[data-kiwe-theme="light"]` and `html[data-kiwe-theme="dark"]`; active/hover colors are accents, not a guarantee of contrast on every surface.

## Screen budgets

- Profile, Cart, Checkout, Search, Saved, Menu, Links: one panel root.
- Search may have a bounded internal results scroller.
- Games may own canvas interaction.
- Other nested scrollers need explicit contract approval.
- Top-level DSA screen titles consume the Theme-owned `style[screen_heading_tag]` setting exposed in Kiwe > Theme as “Screen title tag”. Theme CSS may preserve visual scale, but handoffs must not hard-code semantic title tags as an undocumented design decision.

## htmx/Alpine budgets

- htmx may request server-owned fragments only.
- Alpine may own local display state only.
- Neither may own PhoneKey/auth, checkout/payment, cart reconciliation, service-worker, focus trap, Surface lifecycle, or browser history.
