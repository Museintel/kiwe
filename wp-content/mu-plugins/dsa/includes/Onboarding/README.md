# Kiwe owner onboarding

Kiwe Onboarding is an owner-facing orchestration layer. It does not replace the
native systems that already own site data:

- WordPress owns the site title, tagline, timezone, site icon, indexing switch,
  robots output and XML sitemap.
- WooCommerce owns products, prices, tax calculation, store/base/selling/
  shipping locations, measurement units, shipping zones and checkout behavior.
- Kiwe owns explicit public phone/email/WhatsApp/social identity, page
  search-role intent and the additional SEAM Design Context brief.
- SiteGraph publishes a bounded, read-only view of this context together with
  configured public content, media, products, taxonomies and safe custom fields.

## Owner journey

The journey covers identity, industry, business story, mission, vision, values,
USP, optional founder evidence, public contact, brand feeling, page intent,
store planning and a final authority review. Existing native data is prefilled.
Site name, logo, site icon, public phone and public email are required. Inverse
logo, founder details, social links, colors and visual preferences are optional.
WhatsApp may explicitly reuse the public phone.

For stores, WooCommerce remains the authority for title, long/short
description, images/gallery, SKU, price, stock, weight, dimensions, attributes
and product relationships. Kiwe adds one missing public product field: a
nutrition-information image attachment. Food businesses can record an FSSAI
licence and its product-page display decision; stores can also record GST and a
public manufacturing address decision. These values are reusable Design
Context facts and Bricks/WP7 dynamic data, not copied page content.

Owner color choices map only to real human-selectable SEAM anchors:
`color-brand`, `color-accent`, `color-hero`, `color-neutral`, and
`color-surface`. Readable text, borders, states, raised/sunken surfaces and
dark-mode pairs are derived later by SEAM/accessibility logic instead of being
assigned by a nontechnical owner. The mood can be cleared after selection.

On a truly fresh Kiwe install, the first administrator is redirected once to
the journey. Existing sites can open `Kiwe > Onboarding` at any time.

## Invitations

An administrator can create a seven-day onboarding link for another existing
WordPress administrator. The random secret is stored only as a password hash,
the link requires WordPress login, and the logged-in account must match the
selected user. Delivery can use `wp_mail`, already-configured Kiwe SMS or
WhatsApp webhooks, or manual copy. A completed invitation cannot be reused.

## SEO boundary

Primary pages remain indexable and appear in the WordPress XML sitemap.
Secondary utility pages receive `noindex` and are excluded from that sitemap.
Kiwe emits the separate homepage search description and basic
Organization/OnlineStore JSON-LD
only when Yoast, Rank Math, AIOSEO or SEOPress is not detected. This is a safe
first-party SEO foundation, not yet a feature-for-feature replacement for a
dedicated SEO suite.

Business story and audience fields remain design/copy context. They do not
become the homepage meta description or schema description.

Kiwe never invents tax rates, creates shipping zones, creates planned pages, or
creates products during onboarding.

## Dynamic ownership

Onboarding is resumable and may be revisited. Public owner facts are exposed as
Kiwe Bricks dynamic tags and WP7 binding sources, while products use native
Bricks/WooCommerce tags plus `{kiwe_product_nutrition_image}`. Therefore a later
owner edit changes bound front-end output without regenerating a page. The
homepage blog-rail and bestseller choices are intent, not layout instructions;
the latter enables Kiwe's maintained bestseller taxonomy when selected.

On Bricks installations, Kiwe applies a one-time compatibility profile for its
dynamic tags and launchers. If WooCommerce is also present, the add-to-cart,
quantity, stock, linked-products and mini-cart adapters are enabled once. Every
other optional fresh-install capability remains off except read-only SiteGraph,
and an administrator may disable an adapter afterwards without it being forced
back on.
