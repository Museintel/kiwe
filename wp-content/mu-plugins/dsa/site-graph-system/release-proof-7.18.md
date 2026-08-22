# Owner onboarding and SEAM Design Context proof — Kiwe 7.18

Kiwe 7.18 adds a site-owner onboarding authority which coordinates existing
WordPress, WooCommerce and Kiwe settings and publishes the additional semantic
brief through SiteGraph.

## Native authority map

| Context | Write owner | SiteGraph result |
| --- | --- | --- |
| Site name, tagline, timezone, site icon, indexing | WordPress options | Public identity and SEO intent |
| Main logo | WordPress custom logo plus Kiwe identity option | Public media URL and dynamic tag |
| Public phone/email | Kiwe explicit public identity options | Public contact and Bricks bindings |
| Store address, currency and tax switches | WooCommerce options | Full address only in authenticated administrator export |
| Products, prices, tax rates and shipping zones | WooCommerce | Read-only catalog/capability evidence; onboarding never creates or overwrites these records |
| Business story, audience, brand preferences, page intent and store plan | `kiwe.seam-design-context.v1` | Bounded owner-authored design evidence |

## Invitation proof

- Existing WordPress administrator accounts only.
- 256-bit random bearer value; only a password hash is persisted.
- Seven-day expiration, account binding, WordPress login, nonce-protected writes.
- Completed links cannot be reused.
- WordPress email, preconfigured Kiwe SMS/WhatsApp channel, or manual copy.

## SEO proof

- Separate SEO-strength and Design Context readiness scores.
- Secondary utility pages use the WordPress `wp_robots` contract for `noindex`.
- Secondary pages are excluded through `wp_sitemaps_posts_query_args`.
- Homepage description and Organization/OnlineStore JSON-LD are emitted only
  when a recognized dedicated SEO plugin is not active.
- Planned pages are context only; onboarding never creates them.

## Framework boundary

The design context may contain an optional brand tone and semantic brand,
accent and supporting colors. This does not install variables, classes, theme
styles or a Framework Profile. SEAM Framework remains a separate explicit
conversion/install choice.

## Deterministic evidence

Run:

```text
node tools/release/onboarding-contracts.cjs
node tools/release/sitegraph-design-context-contracts.cjs
node tools/release/verify-green-baseline.cjs
```

The onboarding contract covers fresh-install behavior, native authority,
server-side required fields, media selection, no-page/no-product/no-shipping-
zone mutation, SEO behavior, invitation security, delivery routes, SiteGraph
redaction and framework neutrality.
