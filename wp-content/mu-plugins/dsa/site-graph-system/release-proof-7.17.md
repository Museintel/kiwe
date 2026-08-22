# Full public data-model design-context proof — Kiwe 7.17

Kiwe 7.17 expands `kiwe.sitegraph-design-context.v1` beyond standard WordPress and WooCommerce records while preserving its read-only security boundary.

## Included evidence

- explicit Kiwe public business identity, phone, email, logos and dynamic-tag mapping;
- every registered public custom post type, bounded published records and safe custom-field contracts/values;
- every public taxonomy, bounded terms and safe term metadata including category thumbnails;
- WooCommerce product types, prices, galleries, attributes, children, cross-sells, upsells and plugin bundle items when a public product API exists;
- Kiwe linked offer products, discount value/type/scope and configured bestseller category periods;
- independent product, media, standard content, custom-content and taxonomy budgets.

Anonymous clients receive only REST-public, non-protected field values. An administrator-created file may include bounded non-protected values from published records. Both lanes exclude secret-like keys, Bricks internals, drafts, orders, visitors, payment/authentication data, WordPress administrator email and filesystem paths.

## Verification

```text
node tools/release/sitegraph-design-context-contracts.cjs
node kiwe-ai-toolkit/tools/smoke-test.cjs
node tools/release/verify-green-baseline.cjs
```
