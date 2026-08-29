# Kiwe 8.0.0-rc.4 — controlled Staging Seed import and rollback

## Delivered

- Imports only a destination-bound, hash-verified package whose current dry
  run has no blockers and whose exact revision is explicitly confirmed.
- Captures a private target baseline before any content mutation.
- Records each created or updated post, product, variation, menu, term, media
  item and WooCommerce attribute immediately in a credential-free ledger.
- Copies published public content, referenced media, menus, public Design
  Context, site identity, and simple, variable, grouped and external products.
- Preserves downloadable-product semantics while excluding protected download
  file URLs from SiteGraph and the staging package.
- Suppresses WooCommerce webhook delivery throughout the import.
- Never imports users, customers, orders, coupons, credentials, conversations,
  sessions, payment data or webhook configuration.
- Supports exact baseline rollback, removal of ledger-created media/menu/term/
  attribute records, or explicit acceptance after testing.

## Local proof

```text
php tools/release/verify-staging-seed.php
php tools/release/test-builder-snapshot-roundtrip.php
php -l wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Import_Service.php
php -l wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Import_Ledger_Service.php
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

## Live acceptance

1. Upload the same RC package to source and staging.
2. On staging, open `Kiwe > SiteGraph > Staging Seed`.
3. Use the already verified package, confirm the staging destination, and click
   **Capture baseline and import**.
4. Verify public pages, menus, media, products, variations and Design Context.
5. Verify customers, orders and credentials are unchanged.
6. Use **Roll back import** if any acceptance check fails. Use **Accept and
   discard baseline** only after visual and commerce acceptance succeeds.
