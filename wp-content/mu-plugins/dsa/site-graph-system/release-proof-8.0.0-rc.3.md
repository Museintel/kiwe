# Kiwe 8.0.0-rc.3 — verified Staging Seed package and dry run

## Delivered

- Complete server-to-server pull for the approved SiteGraph staging resources.
- Bounded 50,000-row, 600-request, 12 MB per response and 128 MB package gates.
- Before/after revision comparison so a changing source fails closed.
- HMAC-protected package stored outside the public web root and bound to the
  exact destination site.
- Deterministic target dry run for terms, referenced media, content, products,
  menus and public site context.
- Source-key, SKU and slug matching with duplicate-SKU, missing post type,
  missing taxonomy and product-type conflict blockers.
- No content import, media download, WooCommerce save or remote credential
  persistence in this release rung.

## Local proof

```text
php tools/release/verify-staging-seed.php
php -l wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Package_Service.php
php -l wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Dry_Run_Service.php
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

## Live proof after uploading the same package to source and staging

1. Create or retain a temporary source Application Password.
2. On staging, open `Kiwe > SiteGraph > Staging Seed`.
3. Under **Pull verified package and build dry run**, enter the source URL,
   administrator username and Application Password.
4. Confirm the success notice says the source revision stayed unchanged.
5. Confirm the package table reports product/content/media counts, integrity
   hash prefix and a dry-run state without modifying WordPress content.
6. Revoke the source Application Password after the package is complete.

The next rung consumes only a verified package whose dry run has no blockers,
requires a target baseline plus explicit confirmation, and records every
created entity for rollback.
