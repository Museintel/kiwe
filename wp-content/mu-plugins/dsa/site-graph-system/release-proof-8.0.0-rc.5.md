# Kiwe 8.0.0-rc.5 — clean public-data reconciliation

## Finding from the first live import

The five Dharwad Pedha source products matched staging in type, SKU, price,
stock, attributes and gallery count, and every imported gallery URL resolved to
the staging host. The additive import also correctly revealed one historical
National Chikki product and 30 historical compiler-test pages. An additive
result is not an acceptable clean source mirror.

## Delivered

- Adds an explicit clean-reconciliation choice to the reviewed import gate.
- Reports unmatched destination pages/posts and products in the dry run.
- Removes only unmatched public content types represented by the verified
  package and unmatched WooCommerce products.
- Performs removals only after the rollback baseline is captured and records
  every removed post/product ID in the import ledger.
- Exports and remaps authoritative WordPress front/posts page IDs and
  WooCommerce Shop, Cart, Checkout and My Account page IDs.
- Continues to preserve media binaries, customers, users, orders, coupons,
  credentials, conversations, payment state and webhook configuration.

## Acceptance target

- Source and staging public product slugs: exactly 5/5.
- Source and staging public page slugs: exactly 17/17 for the current fixture.
- All imported product image URLs use the staging host.
- WordPress and WooCommerce page options point to remapped destination IDs.
- The rollback baseline remains open until render and transaction acceptance.
