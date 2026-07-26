# Seam capability attributes lite context

Use this context inside `/rebuild /seamframework`, `/audit /seamframework`, `/usesitegraph`, `/convert /bricks`, and `/audit /bricksconversion`.

Seam is a neutral Appsite framework, not only a CSS class library. It has two public lanes:

1. semantic lane: `data-role`, `data-flow`, `data-tone`, `data-state`, Seam classes, IDs, ARIA, and normal HTML;
2. capability lane: small Kiwe attributes that ask Kiwe-owned runtime systems to act.

Do not recreate Kiwe runtime behavior in page JavaScript when a live capability attribute exists.

## Live public capability attributes

### Open Kiwe AppShell screens from page/header/Bricks controls

Use the canonical launcher:

```html
<button data-dsa-open-module="cart" type="button">Open bag</button>
```

Allowed values:

```text
menu, search, profile, links, saved, cart, theme, ai, notifications, ios-install, games
```

`theme` toggles light/dark mode. Other values open the matching Kiwe DSA screen/sheet when that module is enabled.

Boundary: do not create AppShell, dock, sheet, screen, focus trap, route history, Search, AI, cart, checkout, auth, or PhoneKey markup inside `website/bricks-paste.html`.

### Saved / wishlist / bookmark

When an existing page UI clearly means save, wishlist, favorite, bookmark, or read later, preserve the UI and add:

```html
<button
  data-kiwe-save="wishlist"
  data-kiwe-save-id="{post_id}"
  data-kiwe-save-title="{post_title}"
  data-kiwe-save-url="{post_url}"
  type="button">Wishlist</button>
```

Use:

- `data-kiwe-save="wishlist"` for WooCommerce product controls;
- `data-kiwe-save="bookmark"` for posts/pages/articles/guides;
- `data-kiwe-save="auto"` only when the context is unambiguous.

Optional attributes:

- `data-kiwe-save-id="{post_id}"`;
- `data-kiwe-save-title="{post_title}"`;
- `data-kiwe-save-url="{post_url}"`;
- `data-kiwe-save-image="..."`.

In Bricks query loops, prefer dynamic tags supplied by Site Graph / Bricks context. Do not hardcode sample product IDs in production artifacts when dynamic binding is available.

### Browser notifications

Use only on a real button/link/clickable Bricks element:

```html
<button data-kiwe-notifications data-kiwe-notification-status-target="#notification-status" type="button">
  Turn on notifications
</button>
<p id="notification-status" aria-live="polite"></p>
```

Optional:

- `data-kiwe-notification-status-target="#selector"` updates a visible status element;
- `data-kiwe-notification-topic="topic-id"` hints a real site topic exposed by Kiwe;
- `data-dsa-native-notification-request` is advanced direct permission mode; prefer `data-kiwe-notifications` unless the visitor is explicitly clicking a native-permission CTA.

Kiwe requests permission only after the explicit visitor click and never during a protected checkout/auth/payment flow.

### Light / dark control outside the dock

If the draft has a light/dark switch outside the Kiwe dock, preserve it and add:

```html
<button data-kiwe-theme-toggle data-kiwe-theme-status-target="#theme-status" type="button">
  Toggle theme
</button>
<p id="theme-status" aria-live="polite"></p>
```

Do not write custom theme-switch JavaScript when this attribute can call Kiwe's shared theme runtime.

### Menu context / table of contents

Do not add hidden Kiwe-only anchors just to feed the menu.

Use real semantic sections:

```html
<section id="heritage" class="seam-section" data-role="section" aria-labelledby="heritage-title">
  <h2 id="heritage-title">Heritage</h2>
</section>
```

Kiwe Menu context prefers semantic page sections with stable `id` and labels from `aria-label`, `aria-labelledby`, or visible headings. If no semantic sections exist, Kiwe falls back to the site's configured heading levels such as H1/H2/H3.

### Dynamic / Bricks binding intent

Use when preview/sample regions should later become dynamic Bricks query loops or bindings:

```html
<section data-kiwe-query-template="featured-products" data-role="section" class="seam-section seam-horizontal-rail">
  <article data-kiwe-binding="featured-products" class="seam-card product-card">...</article>
</section>
```

`data-kiwe-query-template` marks the query-loop/source region.
`data-kiwe-binding` marks repeated samples or fields that belong to that binding.

Do not convert sample product/category/media content into hardcoded production content when the Site Graph/Bricks context exposes a dynamic tag, query loop, or term/product ID.

## `/rebuild /seamframework` upgrade rule

When rebuilding a pure HTML/CSS/JS draft with Seam:

- preserve the approved visual direction;
- add official Seam roles/classes/tokens for meaning and responsive structure;
- add live Kiwe capability attributes only where the draft already has matching UI intent;
- never create duplicate app runtime code for a Kiwe-owned capability;
- do not add DSA AppShell shell markup;
- keep `website/bricks-paste.html` page-only and Bricks-friendly.

## Candidate attributes

These are roadmap candidates, not live production attributes. Do not use them in output unless the current contract marks them live:

- `data-kiwe-share`;
- `data-kiwe-compare`;
- `data-kiwe-recently-viewed`;
- `data-kiwe-follow`;
- `data-kiwe-ai-context`;
- `data-kiwe-feedback`;
- `data-kiwe-offer`.
