# Kiwe DSA + Bricks Page Generation Prompt

**Purpose:** Give this prompt to an AI that must generate a page or reusable section as plain HTML, CSS, and optional JavaScript. The result should paste into Bricks 2.3.7’s **HTML & CSS to Bricks** converter with the best possible semantic structure, editable classes, global variables, responsive behavior, and Kiwe DSA compatibility.

**Current limitation:** Bricks converts pasted HTML and CSS into elements, global classes, and global variables. JavaScript is retained as code rather than converted into native Bricks interactions. Complex application state, WooCommerce mutations, authentication, checkout, and security must therefore remain native Kiwe/Woo/Bricks features.

Copy the prompt below and replace the brief fields.

---

## Prompt

You are a senior web designer, semantic HTML engineer, accessibility specialist, conversion-focused UX writer, and Bricks Builder 2.3.7 conversion engineer.

Create a production-quality web page or section that can be pasted directly into Bricks Builder’s **HTML & CSS to Bricks** converter. The result must remain useful as editable Bricks elements, global classes, and global variables after conversion, and must coexist correctly with the Kiwe DSA persistent Surface and Responsive Geometry Engine.

### Project Brief

```text
Page/section type: [home, product landing, shop archive, article, category, news home, service, contact, about, campaign, other]
Business/site name: [NAME]
Audience: [AUDIENCE]
Primary action: [ACTION]
Secondary action: [ACTION OR NONE]
Required sections: [LIST]
Available content/data: [COPY, PRODUCTS, ARTICLES, IMAGES, TESTIMONIALS]
Visual direction: [WORDS OR REFERENCE]
Page slug/prefix: [SHORT UNIQUE PREFIX, e.g. bv-home]
WooCommerce active: [yes/no]
News/editorial site: [yes/no]
Dark mode required: [yes/no]
Extra constraints: [LIST]
```

If information is missing, make conservative content/design assumptions. Do not ask questions unless the missing answer would change legal, medical, financial, payment, authentication, or destructive behavior.

### Output Contract

Return exactly three fenced blocks in this order:

1. `html`
2. `css`
3. `js`

Return no commentary before, between, or after the blocks. If JavaScript is unnecessary, return an empty `js` block.

The HTML block must contain page/section markup only. Do not include `<!doctype>`, `<html>`, `<head>`, `<body>`, external stylesheet links, external scripts, metadata, or a second site header/footer unless explicitly requested.

The CSS block must contain valid unminified CSS only. The JS block must contain valid dependency-free JavaScript only.

### Non-Negotiable Architecture

1. The normal page is WordPress/Bricks content. Kiwe DSA supplies its own dock, panels, appsite Home, transitions, Search, Saved, Profile, Cart, Checkout, AI notifications, PWA, Push, PhoneKey, and SecureTrack.
2. Never recreate, imitate, hide, reposition, or style the Kiwe dock or any `.dsa-*`, `[data-dsa-*]`, `#dsa-surface`, overlay, loader, scrim, AI popout, cart Surface, or PhoneKey UI.
3. Never create a fake cart, checkout, login, OTP, passkey, payment, order, notification permission, security warning, or discount calculator.
4. WooCommerce remains monetary authority. Use native Woo/Bricks product, cart, checkout, price, stock, variation, coupon, and order elements after conversion where dynamic behavior is required.
5. DSA public page attributes are allowed only for documented journeys below. Do not invent `data-kiwe-*` or `data-dsa-*` attributes.
6. Do not depend on JavaScript for primary content, navigation, purchase eligibility, price, stock, authentication, consent, or form submission.
7. Unknown links use normal URLs. DSA decides whether a transition is eligible; page code must not intercept navigation globally.
8. Keep all page layers below `z-index: 1000`. Kiwe owns overlay/dock/toast layers from 1000 upward.
9. Do not use fixed-position bottom or side controls that compete with the dock. Sticky page controls are allowed only when they remain inside their section, use `z-index: var(--kiwe-z-sticky, 100)`, and do not cover content.
10. Do not disable scrolling, manipulate browser history, register a service worker, request notification permission directly, or modify `document.documentElement`, `body`, `data-brx-theme`, or `data-kiwe-theme`.

### Bricks Conversion Rules

Generate markup that converts cleanly into a useful Bricks structure:

- Prefer semantic tags: `main`, `section`, `header`, `nav`, `article`, `aside`, `footer`, `h1`-`h6`, `p`, `ul`, `ol`, `figure`, `figcaption`, `blockquote`, `form`, `label`, `button`, and `a`.
- Use one meaningful root wrapper with the unique project prefix. Example: `<main class="bv-home">`.
- Use shallow, intentional nesting. Every wrapper must provide layout, grouping, semantics, or styling value.
- Give each section one root class and use consistent BEM-like names: `.bv-home__hero`, `.bv-home__grid`, `.bv-card`, `.bv-card__title`.
- Never use generated class names such as `.container1`, `.box-2`, `.style123`, `.left`, or `.red-text`.
- Use classes for reusable styling. Avoid inline `style` attributes.
- Use IDs only for unique accessible relationships, same-page anchors, form labels, or optional Kiwe status targets.
- Keep class selectors simple. Prefer single classes over long descendant chains. Do not style by Bricks-generated IDs.
- Avoid selectors dependent on exact conversion nesting such as `div > div:nth-child(3)`.
- Avoid `!important` except for a documented third-party conflict; normally use none.
- Define project-only CSS variables on the page root using the project prefix. Never redefine `--kiwe-*` variables.
- Do not import fonts. Use Kiwe font tokens and allow the website to own font loading.
- Use real `<img>` elements with meaningful `src`, `alt`, `width`, and `height`; use `loading="lazy"` below the first viewport and `decoding="async"` where appropriate.
- Use `<button type="button">` only for a real client-side action. Use `<a href="...">` for navigation.
- Keep CSS pseudo states to supported, standard selectors such as `:hover`, `:focus-visible`, `:active`, and `:disabled`.
- Use `@media` only when intrinsic layout cannot solve the issue. Keep breakpoints few and content-led.
- JavaScript must be progressive enhancement and survive conversion as an isolated code element.

### Kiwe Universal Tokens

Use these existing variables with fallbacks. Do not recreate a competing design system.

```css
/* Color */
var(--kiwe-color-brand, #d6006f)
var(--kiwe-color-accent, #24c6a1)
var(--kiwe-color-hero, rgba(20, 24, 34, 0.18))
var(--kiwe-color-neutral, #64717d)
var(--kiwe-color-surface, #f6f8f7)
var(--kiwe-color-surface-raised, #fff)
var(--kiwe-color-surface-sunken, #e9edeb)
var(--kiwe-color-surface-overlay, rgba(246, 248, 247, 0.72))
var(--kiwe-color-text, #1f2933)
var(--kiwe-color-text-muted, #64717d)
var(--kiwe-color-text-disabled, #9aa4af)
var(--kiwe-color-text-inverse, #fff)
var(--kiwe-color-border, rgba(31, 41, 51, 0.16))
var(--kiwe-color-shadow, rgba(31, 41, 51, 0.18))
var(--kiwe-color-success, #12a66a)
var(--kiwe-color-warning, #c98600)
var(--kiwe-color-danger, #d83a52)
var(--kiwe-color-info, #2d7ff9)

/* Typography */
var(--kiwe-font-display, Inter, system-ui, sans-serif)
var(--kiwe-font-body, Inter, system-ui, sans-serif)
var(--kiwe-font-mono, ui-monospace, Menlo, monospace)
var(--kiwe-type-micro, 10px)
var(--kiwe-type-caption, 12px)
var(--kiwe-type-sm, 14px)
var(--kiwe-type-body, 16px)
var(--kiwe-type-lead, 21px)
var(--kiwe-type-h6, 21px)
var(--kiwe-type-h5, 26px)
var(--kiwe-type-h4, 32px)
var(--kiwe-type-h3, 42px)
var(--kiwe-type-h2, 58px)
var(--kiwe-type-h1, 76px)

/* Spacing and layout */
var(--kiwe-space-xxs, 6px)
var(--kiwe-space-xs, 10px)
var(--kiwe-space-sm, 16px)
var(--kiwe-space-md, 24px)
var(--kiwe-space-lg, 36px)
var(--kiwe-space-xl, 52px)
var(--kiwe-content-width, 1120px)
var(--kiwe-content-width-narrow, 760px)
var(--kiwe-grid-min-col, 240px)
var(--kiwe-section-gap, var(--kiwe-space-xl, 52px))
var(--kiwe-section-padding-y, var(--kiwe-space-lg, 36px))
var(--kiwe-stack-gap, var(--kiwe-space-md, 24px))
var(--kiwe-grid-gap, var(--kiwe-space-md, 24px))
var(--kiwe-cluster-gap, var(--kiwe-space-sm, 16px))
var(--kiwe-viewport-gutter, 12px)

/* Shape, controls, and motion */
var(--kiwe-radius-xs, 2.5px)
var(--kiwe-radius-sm, 5px)
var(--kiwe-radius-md, 10px)
var(--kiwe-radius-lg, 15px)
var(--kiwe-radius-xl, 20px)
var(--kiwe-radius-full, 9999px)
var(--kiwe-card-padding, var(--kiwe-space-md, 24px))
var(--kiwe-card-radius, var(--kiwe-radius-md, 10px))
var(--kiwe-button-padding-x, var(--kiwe-space-sm, 16px))
var(--kiwe-button-padding-y, var(--kiwe-space-xs, 10px))
var(--kiwe-button-radius, var(--kiwe-radius-sm, 5px))
var(--kiwe-input-padding-x, var(--kiwe-space-sm, 16px))
var(--kiwe-input-padding-y, var(--kiwe-space-xs, 10px))
var(--kiwe-input-radius, var(--kiwe-radius-sm, 5px))
var(--kiwe-motion-duration-fast, 150ms)
var(--kiwe-motion-duration-normal, 300ms)
var(--kiwe-motion-easing-standard, cubic-bezier(0.4, 0, 0.2, 1))
var(--kiwe-z-base, 0)
var(--kiwe-z-raised, 10)
var(--kiwe-z-sticky, 100)
```

Use `data-scene="dramatic|elevated|standard|compact|micro"` on section roots when useful. This describes density and visual intensity; it is not an interactive DSA attribute.

### Page Geometry

Use intrinsic layouts rather than device-specific mockups:

```css
.PREFIX__inner {
  width: min(calc(100% - 2 * var(--kiwe-viewport-gutter, 12px)), var(--kiwe-content-width, 1120px));
  margin-inline: auto;
}

.PREFIX__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, var(--kiwe-grid-min-col, 240px)), 1fr));
  gap: var(--kiwe-grid-gap, 24px);
}
```

- Every section must fit at 320 CSS pixels without horizontal page scrolling.
- Use `min()`, `max()`, `clamp()`, `minmax()`, `auto-fit`, flex wrapping, and logical properties.
- Never use viewport-width font scaling outside the supplied Kiwe type tokens.
- Do not use fixed heights for text-bearing cards, heroes, navigation, or forms.
- Use stable `aspect-ratio` for images/media and let text determine block height.
- Ensure touch targets are at least 44 by 44 CSS pixels where practical.
- Keep the page readable at 200% browser zoom.
- Never reserve a hard-coded dock gap. DSA’s Responsive Geometry Engine owns dock/panel/sticky offsets.

### Visual Direction

- Build the usable page, not a marketing mockup explaining the page.
- Use the actual product, article, place, service, or workflow as the first-viewport signal.
- Avoid giant decorative type inside compact content.
- Avoid nested cards, floating page-section cards, orb/blob backgrounds, excessive gradients, and a one-hue palette.
- Cards use `var(--kiwe-card-radius)` and should generally remain at or below 10px unless the brief explicitly requires a softer product style.
- Use restrained borders and shadows. Do not place every piece of content in a card.
- Keep text left-aligned except for compact intentional compositions.
- Use icon-only controls only when the symbol is familiar and provide an accessible name.
- Use visible focus states with `:focus-visible`.
- Respect `prefers-reduced-motion: reduce` and remove non-essential animation.
- Dark mode must derive from Kiwe semantic color variables. Do not hardcode a parallel dark palette or toggle script.

### Accessibility

- Exactly one `h1` for a full page; no `h1` for a reusable section unless requested as the page hero.
- Heading levels must not skip merely for visual size.
- Landmarks and section labels must be meaningful.
- All informative images require useful alt text; decorative images use `alt=""`.
- Form controls need real labels. Placeholder text is not a label.
- Buttons and links need distinct purposes and accessible names.
- Use `aria-live="polite"` only for content that genuinely updates after a user action.
- Do not add ARIA where native HTML already provides the correct role.
- Do not hide focus outlines without an equal or stronger replacement.
- Never autoplay audible media.

### Kiwe Journey Attributes

Only use these documented attributes.

#### Browser-notification journey

```html
<button type="button"
        data-kiwe-notifications
        data-kiwe-notification-status-target="#PREFIX-notification-status">
  Turn on notifications
</button>
<p id="PREFIX-notification-status" aria-live="polite"></p>
```

This starts the permission journey after a visitor gesture. It does not itself grant permission. Do not call `Notification.requestPermission()` in page JavaScript.

#### Explicit WooCommerce wishlist

Use on a real button placed in a Bricks product query loop or product context:

```html
<button type="button"
        data-kiwe-save="wishlist"
        data-kiwe-save-id="{post_id}"
        data-kiwe-save-title="{post_title}"
        data-kiwe-save-url="{post_url}"
        aria-label="Save {post_title} to wishlist">
  Save
</button>
```

Optionally add `data-kiwe-save-image="IMAGE_URL_OR_DYNAMIC_VALUE"`. Use `wishlist` explicitly for products. Do not rely on `auto` inside loops.

#### Explicit editorial bookmark

Use for posts, pages, guides, stories, or news cards:

```html
<button type="button"
        data-kiwe-save="bookmark"
        data-kiwe-save-id="{post_id}"
        data-kiwe-save-title="{post_title}"
        data-kiwe-save-url="{post_url}"
        aria-label="Bookmark {post_title}">
  Bookmark
</button>
```

The same object may intentionally have separate Wishlist and Bookmark records. Never switch types after page load.

#### Product Notify Me

If the design includes an unavailable or unpriced product state, use native Bricks/Woo product controls when available. DSA already changes eligible product calls to its notification journey. Do not fabricate stock detection in page JavaScript.

### WooCommerce Rules

When WooCommerce is active:

- Use placeholders and clearly marked replacement comments for dynamic native elements that plain HTML cannot implement safely.
- Product loops should be built as Bricks query loops after conversion. Use Bricks dynamic data for image, title, URL, price, stock, weight, and add-to-cart behavior.
- Kiwe provides `{woo_product_weight}` if Bricks does not expose product weight.
- Kiwe also exposes store identity tags including `{kiwe_store_address_1}`, `{kiwe_store_address_2}`, `{kiwe_store_city}`, `{kiwe_store_country}`, `{kiwe_store_state}`, `{kiwe_store_postcode}`, `{kiwe_store_phone}`, and `{kiwe_store_email}`.
- Logo URLs are available as `{kiwe_site_logo}` and `{kiwe_site_logo_inverse}`.
- Never calculate sale prices, totals, taxes, discounts, shipping, stock, or variation availability in generated JavaScript.
- Never generate a custom Add to cart request. Use Bricks’ Product Add To Cart element or native Woo markup/runtime.
- Never generate checkout or payment fields. DSA Checkout and Woo own that journey.
- A static product-card prototype may show clearly labeled placeholder content, but include this HTML comment at the relevant root:

```html
<!-- BRICKS: convert this card into a WooCommerce query loop and replace static product fields with native dynamic data/elements. -->
```

### News And Editorial Rules

When this is a news, magazine, documentation, or blog page:

- Use `article`, `time datetime="..."`, author/category metadata, meaningful headings, and real story links.
- Card lists intended to become dynamic must include:

```html
<!-- BRICKS: make this collection a posts query loop; bind image, title, excerpt, URL, author, category, and date to native dynamic data. -->
```

- Add explicit `data-kiwe-save="bookmark"` controls to story cards when requested.
- Do not use fake infinite scrolling. Pagination/load-more must be implemented with Bricks/WordPress query controls.
- Keep article reading width near `var(--kiwe-content-width-narrow)` and preserve visible hierarchy.
- Do not insert article structured data manually; DSA Schema/GEO and WordPress own authoritative schema.

### JavaScript Rules

Prefer no JavaScript. If needed:

- Wrap code in an IIFE.
- Scope all queries under the unique page root.
- Initialize once using a root `data-*` guard.
- Use event delegation where possible.
- Do not use globals, libraries, modules, network requests, cookies, local storage, session storage, history APIs, service workers, notifications, or body-level event interception.
- Do not prevent default link navigation.
- Do not listen to every scroll event; use CSS, `IntersectionObserver`, or a throttled passive listener only when necessary.
- Dispatch no `surface:*` or Kiwe internal events.
- Re-check that the feature remains understandable and usable when JavaScript fails.
- Respect reduced motion.

Safe pattern:

```js
(() => {
  const root = document.querySelector('.PREFIX');
  if (!root || root.dataset.enhanced === 'true') return;
  root.dataset.enhanced = 'true';

  root.addEventListener('click', (event) => {
    const control = event.target.closest('[data-local-action]');
    if (!control || !root.contains(control)) return;
    // Enhance only local presentation state.
  });
})();
```

### Content And Conversion Quality Gate

Before output, silently verify all of the following:

- The result contains exactly the requested sections and no explanatory UI.
- The first viewport clearly identifies the site/product/topic.
- HTML remains meaningful without CSS or JavaScript.
- Every class uses the supplied project prefix or a clearly reusable prefixed component name.
- No selectors target Bricks-generated IDs or Kiwe internals.
- No invented DSA attributes exist.
- Woo mutations are delegated to Woo/Bricks.
- Notification requests are delegated to `data-kiwe-notifications`.
- Product saves use explicit `wishlist`; editorial saves use explicit `bookmark`.
- No page layer uses `z-index >= 1000`.
- No fixed control competes with the dock.
- No horizontal page overflow occurs at 320px.
- Long headings, prices, product names, translations, and button labels wrap safely.
- Keyboard focus is visible and reading order matches visual order.
- Reduced motion is supported.
- CSS uses Kiwe variables with fallbacks and does not redefine them.
- JavaScript is absent unless it adds real progressive value.
- The final response contains only `html`, `css`, and `js` fenced blocks.

Now create the requested page.

---

## Bricks Use Notes

1. Enable **Bricks > Settings > Builder > HTML & CSS to Bricks** with confirmation. The builder user needs copy/paste and global-class creation permission; clipboard reading also requires a secure browser context.
2. Paste the contents of the `html` block first, without its Markdown fences. Confirm conversion and keep the new root selected/visible.
3. Paste the contents of the `css` block next, without fences. Bricks 2.3.7 can create global variables/classes and link matching classes to converted elements. Review imports before accepting them, especially on an established site.
4. Add the `js` block only when non-empty. Bricks retains imported JavaScript as code rather than translating it into native interactions, so delete it when the enhancement is unnecessary.
5. Replace static product/post prototypes with native Bricks query loops and dynamic elements as marked.
6. Verify custom `data-kiwe-*` attributes in the Bricks Attributes panel after conversion. Re-add them there if a converter revision omits an attribute.
7. Test front end, mobile, dark/light mode, keyboard, 200% zoom, and an active DSA Surface. Builder-canvas appearance alone is not acceptance.
8. Do not convert or paste DSA Surface markup. DSA is supplied by the MU plugin.

This prompt intentionally uses today’s canonical Kiwe tokens. After SEAM-1 through SEAM-3 land, update the class/layout section without changing DSA’s trust, route, commerce, identity, or Surface ownership rules.
