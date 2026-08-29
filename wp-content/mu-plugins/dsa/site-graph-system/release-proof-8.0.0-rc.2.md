# Kiwe 8.0.0-rc.2 — SiteGraph Staging Seed preflight

## Delivered

- Administrator-only staging-seed manifest and paged resource endpoints.
- Published business content, public CPT/taxonomy, menu, media, Design Context,
  and complete core WooCommerce product/variation migration facts.
- Explicit exclusion of users, customers, orders, payment/authentication data,
  sessions, webhooks, provider settings, logs and downloadable-file URLs.
- Server-to-server target inspection using a temporary WordPress Application
  Password that is never stored.
- Fail-closed target preflight plus a credential-free audit/rollback contract.
- No import or WordPress/WooCommerce content mutation in this release rung.

## Local proof

```text
php tools/release/verify-staging-seed.php
php -l wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Export_Service.php
php -l wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Preflight_Service.php
php -l wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Connection_Service.php
php -l wp-content/mu-plugins/dsa/includes/Site_Graph/Staging_Seed_Ledger_Service.php
php -l wp-content/mu-plugins/dsa/includes/Rest/Site_Graph_Controller.php
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

## Live proof after uploading the same package to source and staging

1. On the source administrator profile, create a temporary Application Password
   named `Kiwe staging seed`.
2. On staging, open `Kiwe > SiteGraph > Staging Seed`.
3. Enter the source HTTPS home URL, administrator username and Application
   Password, then select **Inspect source manifest**.
4. Confirm the recent preflight lists source revision and product/content/media
   counts, reports that credentials were not stored, and imports nothing.
5. Revoke the temporary Application Password on the source immediately after
   the preflight.

The next release rung adds verified chunk pulling, dry-run entity mapping,
baseline capture, staging safe mode and explicit controlled import/rollback.
