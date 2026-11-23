# Kiwe icons in Bricks

The native Bricks **Icon** control’s **Dynamic Data** chooser includes **Kiwe icons**. Select the icon there, then use Bricks’ normal size, colour and layout controls. No Kiwe enable switch, uploaded SVG, icon font or external request is needed. Existing Bricks editor access remains authoritative.

| Purpose | Dynamic tag |
| --- | --- |
| Cart, matching the Dock | `{kiwe_icon_cart}` |
| Notifications bell | `{kiwe_icon_notifications}` |
| iPhone / App Store artwork (RC393+) | `{kiwe_icon_app_ios}` |
| Android / Google Play artwork (RC393+) | `{kiwe_icon_app_android}` |
| Product wishlist | `{kiwe_icon_wishlist}` |
| Post bookmark | `{kiwe_icon_bookmark}` |
| Dock menu / profile / links / saved | `{kiwe_icon_menu}`, `{kiwe_icon_profile}`, `{kiwe_icon_links}`, `{kiwe_icon_saved}` |
| Theme / security | `{kiwe_icon_theme}`, `{kiwe_icon_secure}` |

Every packaged Lucide symbol is also exposed under its original name, using underscores: `arrow_right`, `bell`, `bookmark`, `circle_alert`, `download`, `grip`, `heart`, `key_round`, `log_out`, `map_pin`, `package`, `search`, `share_2`, `shield_check`, `shopping_bag`, `sparkles`, `sun_moon`, and `user_round`. Prefix each with `kiwe_icon_` and wrap it in braces.

An icon supplies artwork only. To open Notifications, add **name** `data-dsa-open-module`, **value** `notifications` to its real button or link. Cart uses value `cart`. The shared launcher owns badges and interactions. Wishlist/bookmark buttons use the existing [save attributes](FRONTEND-ATTRIBUTES.md). Give every icon-only interactive control a meaningful accessible label; its decorative SVG is hidden from assistive technology.

The registry is fixed and has no database or provider reads. SVG paths resolve lazily from the same small packaged sprite used by the Dock and are memoized for the request. They inherit `currentColor` and contain no external sprite reference. Unknown names and image, link, media or attribute contexts return empty; no caller can choose a file path or submit SVG. This vocabulary is separate from Design Context business fields.

The affected local proof is `tools/release/test-bricks-icon-library.php`. It exercises all symbols, aliases, registration, context/selector denials and malformed-artwork rejection. Optional `BRICKS_SOURCE` additionally runs the actual supplied native `Bricks\Element::render_icon()` and `render_svg()` methods with narrow WordPress adapters stubbed. Actual builder chooser, sanitation/save, role access and imported responsive rendering require separate native lab/browser acceptance; local compatibility does not establish deployment.

The two App artwork tags retain their supplied brand colours and 48 × 48 view box. Reuse them on real footer buttons with `data-dsa-install-pwa="ios"` or `data-dsa-install-pwa="android"`. The existing Home handler resolves configured destinations or browser installation/help; the icons alone never prove an app-store listing or grant notification consent. These additions require RC393 or verified target support.
