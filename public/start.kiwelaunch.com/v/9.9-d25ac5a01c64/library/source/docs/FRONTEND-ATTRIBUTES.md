# Kiwe frontend attributes

Current authoring contract: RC356, extending the [RC319 acceptance](BRICKS-AUTOMATIC-RC319-2026-10-11.md). In Bricks, open **Style → Attributes** on a real button/link and enter the name and value in their separate fields. Dynamic tags go in the value. Use `type="button"` for buttons inside forms; give icon-only controls an `aria-label`. Kiwe frontend actions require the Kiwe frontend runtime; native Bricks dynamic tags and element integrations are available independently of dock visibility.

## Actions: name and value

Contextual **Comments/Reviews** below require Core451 and, for product reviews, Store1.1.7. Confirm current SiteGraph capabilities before authoring these controls for an older installation.

| Name | Value | Result |
| --- | --- | --- |
| `data-kiwe-add-to-cart` | `{post_id}`, a positive product/variation ID, or `current` | Adds a simple product or concrete variation through native WooCommerce. Use `{post_id}` in product loops and `current` on a single product. |
| `data-kiwe-cart-quantity` | Integer `1`–`99`; omitted = `1` | Optional quantity on the same add button. Native stock and purchase rules still apply. |
| `data-kiwe-cart-status-target` | CSS selector, e.g. `#cart-status` | Optional status element for progress/success/error messages. Give that element `role="status" aria-live="polite"`. |
| `data-dsa-open-module` | `menu`, `search`, `profile`, `links`, `saved`, `cart`, `theme`, `ai`, `notifications`, `ios-install`, `games` | Opens an available Kiwe destination. `notifications` opens the Notifications inbox and shares its badge and silent checkout reminder; `ai` remains a compatible inbox alias. `profile` shows sign-in/account as appropriate; `theme` toggles light/dark. Hidden dock icons do not prevent available destinations opening. |
| `data-dsa-install-pwa` | `ios`, `android`, `desktop`, `auto` | From RC393, runs the existing Home install action anywhere, including the footer. Configured store/download URLs are used when present; otherwise Kiwe offers supported installation or platform instructions. Use a real button/link with an accessible label. No extra per-button script, fake store listing or consent grant. |
| `data-dsa-open-module` | `comments` | Core451: opens Comments, Store product Reviews or configured Site feedback. Existing PhoneKey, native moderation and Store rules apply. The shared runtime supplies the approved root-count badge. |
| `data-dsa-discussion-id` | `current`, `site`, or `{kiwe_discussion_id}` | Target on that Comments launcher. Use the native tag in WordPress post/product loops; never a user/term ID or one static ID for every card. `site` requires the configured public website-feedback page. |
| `data-dsa-install-group` | `true` | Optional wrapper for install-only copy and attributed buttons. The App visibility choice hides the whole group. From RC413 the shared runtime adds one concise Home Screen/optional-notifications disclosure. Keep unrelated footer content outside it; no per-footer installer or duplicate disclosure script is needed. |
| `data-kiwe-save` | `wishlist`, `bookmark`, `auto` | Toggles a product wishlist or content bookmark. Prefer explicit values in loops; sign-in follows the existing identity journey. |
| `data-kiwe-save-id` | `{post_id}` or a positive content/product ID | Identifies the item being saved. |
| `data-kiwe-save-title` | `{post_title}` or plain text | Optional saved-item title. |
| `data-kiwe-save-url` | `{post_url}` or a public URL | Optional saved-item destination. |
| `data-kiwe-save-image` | Public image URL or a dynamic image URL | Optional saved-item thumbnail URL. |
| `data-kiwe-contact` | `phone`, `email`, `whatsapp`, `directions` | Uses the public contact details configured in Kiwe. Prefer a native link with the corresponding dynamic URL as its fallback. |
| `data-kiwe-contact-message` | Plain text, up to 500 characters | Optional prefilled WhatsApp message. |
| `data-kiwe-social` | `facebook`, `instagram`, `x`, `youtube`, `pinterest`, `linkedin` | Opens the configured public social profile. |
| `data-kiwe-notifications` | Empty value / attribute present | Opens notification preferences after a visitor click. It does not grant permission or add someone to push automatically. |
| `data-kiwe-notification-topic` | A real topic exposed by this site's notification configuration | Optional topic hint on the notification trigger. |
| `data-kiwe-notification-status-target` | CSS selector, e.g. `#notification-status` | Optional status element for notification-permission messages. |
| `data-dsa-native-notification-request` | Empty value / attribute present | Advanced explicit browser-permission request. Prefer the notification-preferences journey above. |
| `data-kiwe-theme-toggle` | Empty value / attribute present | Toggles Kiwe/Bricks light/dark mode. |
| `data-kiwe-theme-status-target` | CSS selector, e.g. `#theme-status` | Optional element showing the current theme label. |

For example, a custom product button needs **name** `data-kiwe-add-to-cart`, **value** `{post_id}`. An optional second row is **name** `data-kiwe-cart-quantity`, **value** `1`.

A cosmetic header search needs **name** `data-dsa-open-module`, **value** `search`, on the form, its wrapper or the input. Kiwe makes it a keyboard-accessible launcher, keeps its decorative fields read-only and opens the real Search screen. The same attribute in a Bricks raw HTML element's native Attributes panel reaches its first visual root automatically. No HTML editing or frontend script is needed. An ordinary, unmarked search input remains editable.

A custom notification icon needs **name** `data-dsa-open-module`, **value** `notifications`. The badge includes unseen updates and pending benefits; opening the inbox clears passive updates while eligible actions remain counted until resolved. On the native Woo checkout page, an unresolved action gives the bell a silent ring animation (disabled for reduced motion). Kiwe supplies the badge and behavior on HTML, SVG and image icons, including late-loaded elements. Use the separate `data-kiwe-notifications` trigger for alert-channel preferences.

A custom cart icon needs **name** `data-dsa-open-module`, **value** `cart`. Kiwe supplies its live count badge automatically, including late-loaded icons. It shares the Dock's native quantity and hides at zero; no second badge attribute or developer markup is required. Both integrations respect destination availability. [Shared behavior decision](decisions/2026-10-12-authored-dsa-launchers.md).

Wishlist and bookmark attributes also supply their saved appearance. Kiwe applies the site's brand colour to a saved control and its native SVG/font icon, alongside `aria-pressed="true"`. Removing the saved item restores the authored appearance. Use the same existing `data-kiwe-save` and item-identity attributes; no extra state class, CSS or Bricks style setting is required. The state follows the current product when a preview changes.

```html
<button type="button" data-kiwe-add-to-cart="{post_id}"
  data-kiwe-cart-quantity="1" data-kiwe-cart-status-target="#cart-status">
  Add to cart
</button>
<span id="cart-status" role="status" aria-live="polite"></span>
```

Use the native **Bricks Add To Cart** element for products that need option/add-on selection. A concrete variation ID can be added directly; a variable parent without its required selections is rejected by WooCommerce. On a non-Woo site the new handler does not intercept the control's native fallback. Adding an attribute never changes roles, consent, stock, pricing, eligibility or provider configuration.

## Semantic layout: name and value

These are the canonical Seam vocabulary shipped with Kiwe. Their availability does not require an AI account. Meaning-only roles do not impose a card/button design; appearance remains in Bricks/styles.

| Name | Value |
| --- | --- |
| `data-role` | `section`, `container`, `hero`, `lead`, `eyebrow`, `label`, `caption`, `hint`, `micro`, `card`, `media`, `avatar`, `button`, `badge`, `chip`, `nav`, `actions`, `form`, `field`, `input`, `textarea`, `select`, `modal`, `toast`, `testimonial`, `price`, `progress`, `skeleton`, `footer`, `aside` |
| `data-flow` | `stack`, `row`, `cluster`, `inline`, `grid`, `dense`, `sidebar`, `center`, `spread`, `cover`, `frame`, `reel`, `horizontal-rail`, `vertical-rail` |
| `data-tone` | `brand`, `accent`, `neutral`, `muted`, `success`, `warning`, `danger`, `info`, `surface`, `inverse` |
| `data-scene` | `dramatic`, `elevated`, `standard`, `compact`, `micro` |
| `data-state` | `loading`, `disabled`, `selected`, `current`, `error`, `success`, `warning`, `collapsed`, `featured`, `hidden`, `print-hidden`; multiple values separated by spaces |
| `data-motion` | `fade-up`, `scale-in`, `view-fade-up` |
| `data-shape` | `square`, `sharp`, `soft`, `rounded`, `pill`, `circle` |
| `data-flow-density` | `compact`, `comfortable`, `spacious` |
| `data-gap` | `none`, `xxs`, `xs`, `sm`, `md`, `lg`, `xl` |
| `data-align` | `start`, `center`, `end`, `stretch` |
| `data-justify` | `start`, `center`, `end`, `between`, `around` |
| `data-theme` | `dark`; light is implicit |

For section navigation, use a real section with a stable `id`, `data-role="section"` or class `seam-section`, and a visible heading or accessible label. Kiwe Menu reads those sections; do not invent hidden duplicate anchors.

## Planning and native Bricks controls

| Name | Value | Scope |
| --- | --- | --- |
| `data-kiwe-query-template` | A descriptive binding key, e.g. `featured-products` | Source/compiler planning marker. It does not execute a frontend query by itself. |
| `data-kiwe-binding` | The relevant binding-plan key | Source/compiler planning marker. Use actual Bricks query loops/dynamic data for runtime rendering. |
| `data-dsa-search-bridge` | `1` | Advanced marker on an existing native Bricks Filter - Search element. Prefer its **Use as DSA Search bridge** control. |
| `data-brx-t1` | `true` | Native product-add-to-cart root: AJAX quantity changes. Prefer Bricks' Kiwe content control. |
| `data-brx-t2` | `true` | Native product-add-to-cart root: hide the quantity control. |
| `data-brx-t3` | `true` | Native product-add-to-cart root: swap add button for quantity after adding. |
| `data-brx-t4` | `true` | Native product-add-to-cart root: plus-only initially; quantity/minus appear once in the cart. |
| `data-brx-t5` | `true` | Native product-add-to-cart root: plus adds the product. |

Bricks emits its own query/product/cart identity attributes and Kiwe emits registry IDs automatically. Do not copy generated IDs or cart keys between sites or manually add these to custom buttons. Use the public add attribute above instead. Older `data-dsa-notifications` and `data-dsa-permission="notifications"` aliases remain accepted; use `data-kiwe-notifications` for new designs.

`data-kiwe-share`, `data-kiwe-compare`, `data-kiwe-recently-viewed`, `data-kiwe-follow`, `data-kiwe-ai-context`, `data-kiwe-feedback` and `data-kiwe-offer` are candidate contracts, not working public controls. `data-seam-*` shadow/state-binding metadata and dashboard/cart/auth implementation markers are not supported page-author APIs.

Authoritative sources: `Design/Seam_Vocabulary_Schema.php`, frontend `surface.js`, native `Bricks/Bricks_Integration.php`, and the native Woo cart controller. Dynamic tags such as `{kiwe_site_logo}` are values selected through Bricks Dynamic Data, not HTML attribute names.

## Version 1 community and forms

`data-dsa-open-module="comments"` opens one native contextual conversation; `data-dsa-discussion-id="current"` follows the current public post or active Store product, and `site` selects the explicitly configured public feedback page. Kiwe owns the approved root count on the icon, replies, safe display identity, login/verification and moderation. Use `{kiwe_discussion_id}` only in native WP_Post loops; the `kiwe_testimonials` array loop exposes `{kiwe_testimonial_target_id}` for its source conversation. Never use a comment, user or taxonomy ID as the target.

Give each native Bricks Form element `data-kiwe-form="careers"` (a stable lowercase slug) and `data-kiwe-form-label="Careers enquiry"`. The saved page/form pair is the authoritative filter identity in private Daily Forms. Enable native submission storage and the Save submission action. Email requires an explicitly configured native delivery action; use the actual public business email tag where appropriate. Saved does not mean delivered, and an enquiry does not imply marketing consent. Private answers and internal receipts never belong in SiteGraph or public exports.
