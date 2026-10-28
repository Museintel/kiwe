# Bricks Integration Notes

## Contextual Comments/Reviews — from Core451

`{kiwe_discussion_id}` and `{kiwe_discussion_count}` resolve only an eligible native WordPress post context; a taxonomy/user loop cannot supply its ID. Use the ID tag with `data-dsa-open-module="comments"` and `data-dsa-discussion-id` on a real button/link. `{kiwe_icon_comments}` reuses packaged Dock artwork. The shared launcher supplies its own badge; replies are separate from approved root counts. Product reviews require Store1.1.7; ordinary post comments do not require Woo. [Authoring and strict compiler intent](../kiwe-ai-toolkit/contracts/discussion.md).

## Native Kiwe icons (16 October 2026, source candidate)

The native Icon control's Dynamic Data chooser gains a **Kiwe icons** group using the same packaged SVG artwork as the Dock. Stable cart, notifications, wishlist and bookmark tags require no uploaded media, icon font, provider or enable switch. Normal Bricks styling and existing launcher/save attributes remain authoritative. [Icon vocabulary, cost and verification boundary](BRICKS-ICON-LIBRARY.md). Lab/browser acceptance and deployment are separate from this source addition.

## Public product and configured-pair bindings (15 October 2026)

Native product loops retain WordPress/Woo tags for title, permalink, image, price and descriptions. Kiwe adds `kiwe_product_image_alt` and `kiwe_product_price_text` for matching accessible output. Generic `kiwe_pair_<field>` tags read the current source product; configured pairs also appear automatically as `kiwe_pair_<source-product-id>_<field>`. The stable ID survives renames. Public/private/deleted endpoint guards apply on every resolution.

Layout query loops expose **Product collection** and optional **Source product ID**. Choices use existing up-sells, cross-sells, grouped children, companion or configured Kiwe pairs. Leave source empty for the current product; native product editing owns the relationship. Empty collections render no results. Enumeration is capped at 100 configured source products; exact selectors/current-record bindings do not scan the catalogue. Pair tags expose names, images, links, prices and normalized offer labels/scope; Woo's cart remains the final totals/claim authority. [Exact ownership and limits](decisions/2026-10-15-native-commerce-bindings.md).

AI preview markers use one `data-seam-field` or a `data-seam-fields` JSON slot map on a node. A closed exact map and public catalogue are required. Bind each card's visible and accessible fields to the same record. Source previews keep real values; native Bricks receives altText/link ariaLabel/root attributes through Seam 2.17.0. Public compiler/Start rollout remains D03.

## Automatic editor controls (2026-08-28)

Kiwe editor controls register automatically after active Bricks theme setup,
including child themes. Saved runtime switches do not hide style controls.
Native dynamic data, cart/linked-product/icon adapters and the search bridge are
automatic in RC319; no global integration switch remains. Per-element design
choices and optional DSA/AI/provider services retain their own authority.
See [current decision](decisions/2026-10-11-automatic-bricks-capabilities.md) and
the complete [frontend name/value reference](FRONTEND-ATTRIBUTES.md).

Authored Search launchers are cosmetic controls; Cart launchers receive the
shared live count automatically. Raw HTML elements forward the recognized
native launcher attribute to their real root at render time. The native
layout/settings remain unchanged. See the [launcher contract](decisions/2026-10-12-authored-dsa-launchers.md).

For a native **Add to cart** element, behaviour is in **Content → Kiwe Add To
Cart**. Its quantity-button/input typography, background, border/radius,
padding and width controls are in **Style → Kiwe Add To Cart styles**. The
native submit button retains Bricks' **Content → Button** controls. These
groups require no Kiwe admin enable switch. [12 October Chrome verification](BRICKS-STYLE-CONTROLS-2026-10-12.md)
confirmed both a fresh editor and the founder's existing session without
reloading or saving that session.

Primary source currently verified at:

```text
C:\Users\shariq\Downloads\bricks.2.3.10\bricks
```

## Relevant Bricks Anchors

- `page.php` calls `Bricks\Frontend::render_content( $bricks_data )`.
- `includes/frontend.php` exposes `Bricks\Frontend::render_content()`.
- `includes/frontend.php` applies `bricks/frontend/render_element` after each element renders.
- `includes/assets.php` uses `bricks/element/render_attributes`, suitable for adding DSA markers.
- Bricks content normally renders inside `#brx-content`.
- Bricks provides builder detection helpers such as `bricks_is_builder()`.

## Current DSA Strategy

Bricks remains the page-design authority and DSA remains the appsite Surface authority. Current production navigation uses full documents plus native cross-document View Transitions where eligible. The S13-S16 editorial envelope/morph pipeline is gated, off by default, and deliberately blocks Bricks content until live lifecycle proof exists.

The retained future Bricks renderer strategy is:

1. Resolve requested URL.
2. Set WordPress query context.
3. Detect Bricks content data.
4. Render only the content area with Bricks APIs.
5. Return a metadata envelope and registry data.

Avoid globally suppressing `get_header()` and `get_footer()` as the primary implementation path.

## First Integration Hooks

Current scaffold registers:

```php
add_filter( 'bricks/element/render_attributes', ... );
add_filter( 'bricks/frontend/render_element', ... );
```

The first hook adds stable element attributes. The second will become the registry collector.

## Registry Verification

After uploading the current build, open a public Bricks-rendered page and run this in the browser console:

```js
window.DSA.registry
```

Expected shape:

```js
{
  route: "https://example.com/",
  postId: 123,
  count: 12,
  elements: [
    {
      id: "abc123",
      source: "bricks",
      bricksType: "heading",
      type: "heading",
      label: "Hero headline",
      selector: "[data-dsa-bricks-id=\"abc123\"]",
      confidence: 0.9
    }
  ]
}
```

Each Bricks root element should also receive:

```html
data-dsa-bricks-id="..."
data-dsa-bricks-type="..."
```

The registry is still read-only. It does not write to Bricks post meta.

Useful console helpers:

```js
window.DSA.registry.summary
window.DSA.getAiVisibleElements()
window.DSA.getEditableElements()
window.DSA.getElementsByType('heading')
window.DSA.getElementsByType('navigation')
window.DSA.getElementsByType('image')
```

Surface visual helpers:

```js
window.DSA.previewLoader()
window.DSA.previewLoader(3000)
window.DSA.showLoader()
window.DSA.hideLoader()
```

Open any Surface dock module to verify the document blur, scrim, frosted glass panel, and overlay events:

```js
window.addEventListener('surface:overlay:open', console.log)
window.addEventListener('surface:overlay:close', console.log)
window.addEventListener('surface:loading:start', console.log)
window.addEventListener('surface:loading:complete', console.log)
```

Fragment navigation helpers:

```js
window.addEventListener('surface:navigation:start', console.log)
window.addEventListener('surface:navigation:complete', console.log)
window.addEventListener('surface:navigation:fallback', console.log)
```

Legacy fragment navigation is hard-disabled. Do not instruct site owners to enable it. Controlled editorial morphing is a separate diagnostics pilot and must remain off until S16 proof is complete; Bricks routes are currently ineligible.

Navigation game behavior:

- Admin trigger rules control whether eligible navigation opens a transition message or game layer.
- Ordinary and Bricks links continue through full browser navigation unless they qualify for the separately gated static-editorial pilot.
- Protected, Woo, builder, interactive, and uncertain routes always retain full-document authority.
- A game close action may release an artificial delay, but it must never bypass Protected Flow or route safety.

Current dock baseline:

```js
window.DSA.boot.version
window.DSA.registry.summary
```

Use `Kiwe > Dock` to configure registered modules, compact Dock or Navigation bar presentation, desktop/mobile orientation, edge placement, responsive geometry, light/dark action, and AI visibility. Use `Kiwe > Theme` for Classic or Sheet presentation. Search and Saved are registered modules; Woo-only modules remain capability-gated. Profile and Cart consume WordPress, WooCommerce, and Kiwe Auth/PhoneKey when available.

### Page-aware DSA Search and Bricks queries

DSA Search uses the native Bricks 2.3.7 Filter Search contract instead of introducing a second page-query engine. Connect a Bricks **Filter - Search** element to the intended query, then enable **Use as DSA Search bridge** in that Filter Search element's Content controls. The query may use ordinary AJAX filtering or Bricks **Live search**. The filter element may be visually hidden when DSA Search is the only intended input, but it must remain in the document so Bricks owns its query state and rendering.

There are two distinct setup levels:

1. DSA's own Search screen needs no Bricks element, generated ID, attribute, or per-site configuration.
2. To make the query cards on a Bricks page update alongside DSA Search, add a native **Filter - Search** element, point it at the intended Bricks query, and enable **Use as DSA Search bridge**. Kiwe emits the semantic marker and reads Bricks' generated query reference at render time. Never copy an element or query ID from one site to another.

On Shop, product archive, and product taxonomy routes, DSA opens with **Products** selected. On the Posts page and post archives it opens with **Posts** selected; author archives select **Authors**. Typing in DSA Search updates both bounded DSA results and the marked Bricks query area underneath. Kiwe emits `data-dsa-search-bridge="1"` and the element's current Bricks-owned query reference, so no generated element/query ID is embedded in plugin code. Explicitly marked Filter Search elements win. If none are marked, backward-compatible automatic discovery follows compatible native search instances and matches post type where Bricks exposes it. DSA uses Bricks' own selected-filter and query-result utilities and suppresses only redundant URL history. Search behavior and cache generation are governed in `Kiwe > Search`.

### Context-aware Menu

`Kiwe > Menu` can combine any number of WordPress menus with DSA-only targets. Its optional Context Engine client reads the rendered headings in the current page's authoritative main region and presents them as an in-page table of contents. It does not store Bricks element IDs or parse Bricks post meta, so ordinary Bricks Heading elements work without attributes. Admins govern the allowed routes, pages, and H1-H6 levels in `Kiwe > Menu`.

## Kiwe Tokens And SEAM

- `Design\Seam_Token_Service` is the canonical `kiwe-*` token source for the Surface and additive builder export.
- Kiwe exports variables and the `Kiwe Universal` color palette without replacing existing Bricks variables, palettes, classes, or theme styles.
- The retained `references/seam/` files are non-executable inputs for the next SEAM framework batches.
- Future SEAM layout/component classes must be namespaced and additive. Page-authoring controls must not become a second DSA Surface theme system.

Registry quality rules in the current build:

- Bricks `section` elements are classified as `region`.
- Bricks `container`, `block`, `div`, `divider`, and nested slider wrappers are classified as `layout`.
- Headings, text, and images are marked `editable: true`.
- Layout and unknown elements are marked `aiVisible: false`.
- The registry remains read-only and does not alter Bricks post meta.

## Live native testimonials and enquiry identity

The existing `kiwe_testimonials` query now projects approved public comments selected by Website plan: pinned, team-hearted, or optionally liked. It reuses `{kiwe_testimonial_name}`, `{kiwe_testimonial_quote}` and the existing URL/source tags. Additional `source_type`, `source_title`, `likes`, `pinned`, `hearted` and `target_id` suffixes describe the genuine record; only real product ratings are available. Private/held/deleted records, staff replies and restricted ancestry are excluded. Source settings remain in Design Context even when SEO is off. The compiler retains one native array prototype and requires exact SiteGraph authority for a source-discussion launcher.

The native Form Attributes carry `data-kiwe-form` and `data-kiwe-form-label`. The compiler maps the human name to `submissionFormName` and adds the existing Save submission action only for a clearly identified ordinary enquiry form. It never invents recipients or turns on mail; password/upload/credential-like forms require explicit review. Actual saved submissions drive private Daily Forms filters, badges and role-derived notifications.
