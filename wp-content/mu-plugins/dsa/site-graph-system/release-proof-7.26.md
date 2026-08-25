# Connected Design Context proof — Kiwe 7.26

Kiwe 7.26 connects owner onboarding, WooCommerce product evidence,
SiteGraph, Bricks dynamic data and WP7 binding sources without transferring
data ownership into page templates.

- Owner context now includes industry, business story, mission, vision, values,
  USP and optional founder evidence.
- Store context records FSSAI, GST and manufacturing-address disclosure intent.
- WooCommerce remains authoritative for its complete native product model.
  Kiwe adds only a product-scoped nutrition-information media attachment.
- SiteGraph exposes the owner context, product nutrition image and verified
  Bricks 2.3.10 native product-tag vocabulary.
- Bricks and WP7 expose live bindings so later owner/product edits update the
  frontend without regenerating templates.
- A one-time Bricks/Woo compatibility profile enables Kiwe's builder adapters
  while leaving unrelated fresh-install capabilities disabled.
- SeamFlow 7.28 requires portable `/ideate` assets plus provenance and asks for
  SiteGraph only when the requested draft needs real site evidence.

Validation commands:

```text
node tools/release/onboarding-contracts.cjs
node tools/release/sitegraph-design-context-contracts.cjs
node tools/connector/ai-api-contracts.cjs
node public/start.kiwelaunch.com/validators/smoke-test.cjs
```
