# Live Bricks automatic-control verification

After the canonical MU-plugin upload, authenticated read-only verification on
the test site confirmed the latest integration is active:

- Kiwe > Bricks reports automatic editor controls for the active Bricks theme;
- all 53 advertised controls are embedded in the live Bricks frontend editor;
- the new content-free calibration advertises 15 Add To Cart controls and 38
  surface controls, with zero missing keys;
- no settings save was needed and optional frontend behaviour switches remain
  independent of editor styling availability.

The calibration is read-only and excludes secrets, site content and visitor
data. It reports Bricks 2.3.10, WooCommerce 11.0.1, four breakpoints and the
current target collision namespace. No live templates, products, orders,
settings or framework data were changed during verification.

The preserved checkout template opened in the editor and rendered without
content horizontal overflow at desktop, 991px and 478px. This is one baseline
page check, not full WooCommerce visual certification. The canonical upload
directory remains `wp-content/mu-plugins`; generated `dist/` is not canonical.
