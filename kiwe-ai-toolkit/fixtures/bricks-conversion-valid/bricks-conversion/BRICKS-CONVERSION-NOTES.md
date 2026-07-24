# Bricks conversion notes

This fixture converts `website/bricks-paste.html` into a reviewable Bricks element JSON package.

- Converter path: AI-authored fixture, with Bricks native conversion preferred on live sites.
- Site Graph: `site-graph.json` supplies `product`, `product_cat::123`, and the dynamic tags used.
- Dynamic intent: the `featured-products` marker maps to a Bricks product query loop.
- Conditions/interactions: none in this fixture.
- Manual review: none.
- No mutation: this package does not save Bricks, WordPress, WooCommerce, cart, checkout, or auth data by itself.

Apply later only through human review or the trusted Kiwe staging adapter.
