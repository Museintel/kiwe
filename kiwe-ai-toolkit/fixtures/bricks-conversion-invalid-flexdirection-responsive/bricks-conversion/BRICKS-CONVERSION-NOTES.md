# Invalid Bricks conversion fixture

Expected result: `validate-bricks-conversion` must fail because the conversion changes a `seam-spread` section head to `_flexDirection:custom_phone = column` without `fidelity.responsiveIntent` source evidence.

No mutation: this conversion package is deterministic review material only and does not mutate WordPress/Bricks, WooCommerce, cart, checkout, auth, or the live site by itself.

Site Graph/Bricks context is unavailable for this fixture. No dynamic tags or query loops are required. Manual review remains blocked until the responsive fidelity failure is corrected with source-backed breakpoint behavior.
