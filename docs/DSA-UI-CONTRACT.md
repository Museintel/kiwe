# DSA Surface UI Contract

**Contract:** `kiwe.surface-ui.v2`  
**Runtime marker:** `[data-dsa-ui-contract="2"]`  
**Canonical implementation:** `wp-content/mu-plugins/dsa/includes/Public_Endpoint/Surface_Renderer.php`, `assets/js/surface.js`, and `assets/css/surface.css`

## Icon And Dock Contract (`0.5.2`)

- Visitor-facing utility icons use the bundled official Lucide static SVG set. The package retains its ISC license and serves a compact local sprite, so the dock has no CDN dependency.
- Every enabled module, including AI, belongs to one toolbar and one Responsive Geometry Engine calculation. AI is the single emphasized action: it may protrude, wobble, and carry a badge, but it is not a separate geometry container.
- Menu, Search, Profile, Links, Cart, Saved, Theme, and AI retain their module contracts, lifecycle, badges, and active states. Links may replace its share glyph with configured social-brand frames; Saved switches between Bookmark and Heart according to saved content.
- Context controls use icon plus text when text is the stronger recognition aid. Downloads, Addresses, and Password therefore keep labels; Logout is icon-only with an accessible name.
- Address setup and unseen downloads are state badges on their Profile context actions. Recent Orders remains a summary rail and provides a View all action to the complete Orders view.

## Conformance Evidence

`tools/ui-contract/run.cjs` serves fixtures outside the deploy package and captures Menu, Profile, Cart, Links, and Search across phone, resized-desktop, tablet, and desktop widths; light/dark themes; and horizontal/vertical orientations where applicable. Version `0.5.4` retains the baseline of all 70 generated variants while extending Search with card-only results, optional product quick-add, and a bounded progressive alphabet rail.

The harness validates layout-state selection, viewport containment, dock/context alignment, horizontal/vertical context placement, accessible control names, named form/media content, unique IDs, one-dialog ownership, and minimum dock targets. Screenshots and `report.json` are written to `tools/ui-contract/artifacts`. These automated checks complement rather than replace real safe-area, zoom, keyboard, screen-reader, and device QA.

Context is a sibling of the dock cluster, not its child. This is mandatory: transformed dock positioning must never become the containing block for viewport-measured context coordinates.

## Purpose

DSA is one persistent Appsite shell. Menu, Search, Profile, Links, Saved, Cart, Checkout, AI, Notifications, and future marketplace modules are not independent pages or popups. They are module states rendered into one Surface viewport while the persistent dock and its contextual controls remain available.

The contract protects four properties:

1. One predictable app-like geometry across every module.
2. Deterministic server-owned commerce, identity, trust, and security behavior.
3. A visual system that can be restyled without changing lifecycle or data contracts.
4. A future marketplace boundary where third-party designs can be accepted or rejected mechanically.

## Visual Profiles

`Kiwe > Theme` separates presentation into named visual profiles. `Legacy UI` is the preserved ultra-light baseline and remains the default. `Kiwe 2027` is the built-in modern app UI profile. The runtime exposes it as `data-dsa-visual-profile="kiwe2027"` and keeps `.dsa-visual-prototype` / saved `prototype` support only as a backwards-compatible alias for older installs.

The portable authoring map for future marketplace themes lives in `wp-content/mu-plugins/dsa/ui-system/`. That folder is the UI brain for designers and AI assistants: it lists the stable screen payloads, slots, token aliases, Bricks/dynamic-tag capabilities, budgets, and adapter rules without requiring the entire plugin codebase. Runtime adoption has begun with visual-profile adapter registries for Profile, Menu, Saved, Cart, the Search shell, Links, notification preferences, the iOS install guide, and AI panel/report presentation. The browser runtime now also exposes `window.DSA.ui` as the small canonical theme bridge: current contract id, adapter version, active visual profile, adapter-ready screens, current Active/Hover color model, and profile-aware payload helpers. The full marketplace import/export registry remains future work.

Visual profiles may change material, density, card treatment, badge styling, and dock/button presentation. They must not replace Surface lifecycle ownership, PhoneKey/auth authority, checkout/payment authority, cart reconciliation, service-worker policy, focus trapping, history ownership, or REST/security contracts. Kiwe 2027 styling must remain scoped to its visual-profile class/alias so Legacy can stay stable.

Kiwe 2027 and Legacy may use bottom-sheet spacing/origin options to create a floating app-card surface: `Edge-to-edge` vs `Inset / space around`, and `Screen bottom` vs `Above dock`. Inset sheets may expose a bounded width percentage control from 50-90% of the visual viewport, defaulting to 78%. These options change geometry only; they must not create a second checkout/cart authority or hide required focus/close controls. Dock split style is presentation-only and only applies when Dock presentation is active, never Navigation bar: the existing admin drag order remains the source of truth, while the renderer adds segment classes around the emphasized AI/action button for visual grouping wherever the site owner places it. Full compact dock, split compact dock, and Navigation bar are core shell modes; themes may style them within token/geometry constraints but must not let page content bleed through the dock material or silently reinterpret one mode as another. Dock shape is also a core shell state: `pill`, `box` / rounded rectangle, and `square` / no radius must visibly affect full and split compact docks.

Links site score is optional core data. When `Kiwe > Links > Site score` is blank, runtime payloads expose no score and themes must omit the score badge entirely rather than rendering an empty card, `0`, or a placeholder. When a score exists, mixed logo/score rows must rebalance or wrap before text becomes unreadable.

## Required Shell Slots

Every design profile must preserve these slots and their ownership:

| Slot | Runtime selector | Ownership |
| --- | --- | --- |
| Surface shell | `[data-dsa-surface]` | DSA runtime, theme, geometry, mode arbitration |
| Module viewport | `[data-dsa-overlay-root]` | One visual backdrop; never the module's scrolling content |
| Module body | `.dsa-panel[role="dialog"]` | The only vertical content scroller |
| Dock cluster | `[data-dsa-dock-cluster]` | Persistent module navigation and AI launcher |
| Primary dock | `.dsa-phonekey-dock` | Registered module actions only |
| Context rail | `[data-dsa-dock-context]` | Optional experimental current-module action/status rail |
| Context content | `[data-dsa-dock-context-content]` | Optional horizontal, non-wrapping action row |
| AI notification | `[data-dsa-ai-popout]` | Temporary notification, not a second module viewport |
| Loading/interstice | `[data-dsa-loader]` | Navigation response, message, game, and trust moment |

Designs may change composition, typography, radii, material, icon treatment, and motion. They must not remove, duplicate, rename, or nest the required runtime slots. Context rail slots remain in the canonical DOM for backwards compatibility but are hidden unless `Kiwe > Dock` explicitly enables the experimental rail.

## Viewport Rule

- `.dsa-overlay-root` paints the full Surface material and never scrolls.
- Its direct `.dsa-panel` child owns vertical scrolling.
- Panel content ends before `--dsa-screen-block-reserve` and `--dsa-screen-inline-reserve`.
- No content may render beneath the dock, safe area, browser chrome reserve, WordPress admin-bar reserve, or the optional context rail when it is enabled.
- Search may own a nested result scroller because result counts are unbounded. Games may own a canvas interaction region. These are explicit exceptions, not alternate shell implementations.
- Appsite Home is a session-entry state and does not expose the dock, or the optional context rail, until dismissed.

## Responsive Geometry

Marketplace designs consume the resolved variables; they do not calculate their own mobile dock sizes.

The engine publishes layout from **usable Surface space**, not device names or user-agent guesses:

- `[data-dsa-layout="wide"]`: at least 820 CSS pixels remain after inline reserves.
- `[data-dsa-layout="compact"]`: 540-819 CSS pixels remain.
- `[data-dsa-layout="narrow"]`: fewer than 540 CSS pixels remain.
- `[data-dsa-density="dense"]`: fewer than 560 CSS pixels remain vertically after block reserves.
- `[data-dsa-density="comfortable"]`: normal vertical composition.

Browser zoom, resized desktop windows, mobile browser chrome, safe areas, dock orientation, context height, and admin-bar height therefore converge on the same state. Marketplace CSS must consume these attributes or container queries. Shell or module layout must not infer a separate mobile state from `@media` rules.

```css
--dsa-dock-control-size
--dsa-dock-ai-size
--dsa-dock-icon-size
--dsa-dock-badge-size
--dsa-dock-gap
--dsa-dock-padding
--dsa-dock-cluster-gap
--dsa-dock-only-reserve
--dsa-dock-context-size
--dsa-screen-block-reserve
--dsa-screen-inline-reserve
--dsa-screen-available-inline
--dsa-screen-available-block
--dsa-context-bottom-offset
--dsa-admin-bar-height
```

The Responsive Geometry Engine derives these from the visual viewport, enabled module count, dock orientation, safe areas, browser zoom, and admin-bar height. A design that replaces them with viewport-specific magic numbers is incompatible.

Mixed-content rows must rebalance before they crush their own labels. Logos plus site-score badges, commerce actions, metrics, badges, and social rows must use `minmax(0, 1fr)`, bounded `clamp()`, tokenized gaps, and container/geometry breakpoints so text never collapses into unreadable fragments at smaller sheet or screen widths. Shrink-to-illegibility is a contract failure, not an acceptable theme interpretation.

## Optional Context Rail Rule

The context rail is an experimental opt-in second area of the dock. It is not a required marketplace surface, not a card, and never part of module content. The default contract keeps immediate controls inside their owning panel so new visual themes do not depend on relocated controls.

- It uses the same `glass` or `solid` material and the same `pill`, `box`, or `square` shape selected for the dock.
- Its contents form one horizontal, non-wrapping row. The row does not become another scroll area: labels, icons, gaps, and `kiwe-type-micro` must compress within the measured rail. Vertical stacking is not allowed.
- With a horizontal dock, the dock width is the context rail ceiling. Transactional and multi-action contexts use the full measured dock width; a single compact action may use intrinsic width. With a desktop left/right dock, the rail uses the active module body's measured width and sits immediately below that body.
- It appears above a horizontal dock. With a left/right vertical dock, it stays below the module content area rather than beside the dock.
- It carries only immediate module actions or compact status.

When the rail is explicitly enabled, current mappings are:

| Module/page | Context rail content |
| --- | --- |
| Cart | Checkout and current total |
| Checkout | Continue/return to Place order and validation status |
| AI | Chat composer |
| Profile | Downloads, Addresses, Password, icon-only Logout; Recent Orders remain visible as status cards in the module body |
| Links | Shared SSL, secure-login, and payment trust badges |
| Menu (administrator) | Dashboard entry |
| Simple product | Authoritative Woo quantity and Add to cart form |

Marketplace modules may declare immediate controls with `data-dsa-context-slot` only when they are safe to relocate. Optional `data-dsa-context-name` identifies the module and `data-dsa-context-width="dock|content"` selects full-width or intrinsic composition. When enabled, the runtime relocates the original control through a reversible marker; designs must never clone it.

Variable-product forms remain in module/page content until a variation-safe compact controller is implemented. A design must never clone transactional controls or create a second cart state.

## Information Density

DSA optimizes for one useful viewport, not decorative emptiness.

- Use compact headers, two-column layouts when controls remain at least 44 CSS pixels, horizontal rails for finite related items, and internal scrolling only for genuinely unbounded data.
- Keep hero typography for Menu, interstice messages, and intentional first-impression states. Operational Profile, Cart, Checkout, and admin tools use tighter typography.
- Links should prioritize identity, social actions, commerce, a short editorial rail, one testimonial, and trust. If the optional context rail is disabled, trust remains inside the Links panel.
- Profile editing remains visible while destinations stay inside the panel by default; the experimental context rail may relocate eligible destinations when explicitly enabled.
- Search exposes Products, Posts, and Authors filter pills when those families are enabled in `Kiwe > Search`. Shop/product archives initially select Products, the Posts page/post archives select Posts, and author archives select Authors. The selected family is module state and must not reset when the input receives focus. Selecting a pill limits both DSA REST work and compatible Bricks Filter - Search output; selecting it again clears the filter. Results are cards at every viewport. Purchasable simple products may expose a `+` action using the canonical cart mutation queue. The optional alphabet rail shows only title prefixes that exist; choosing `A` drills into available `Aa`, `Ab`, `Ac` branches and provides a one-level back action. Results stay in one bounded scroller.
- Interface command icons use accessible inline SVG. Text remains where it improves recognition; destructive or universally familiar compact actions may be icon-only with an accessible name and tooltip.

## Material And Theme

- Dock and the optional context rail always share material and shape when the rail is enabled.
- The DSA screen material is independently configurable.
- A bottom Sheet is one contiguous material from its rounded top edge through the safe-area edge. The module body reserves dock/context space inside that same sheet; themes must not add a second tray, footer slab, shadow band, or detached dock background below it.
- `solid` means white in light mode and the canonical dark surface in dark mode.
- `glass` uses the configured DSA blur/intensity contract.
- Designs must support `[data-kiwe-theme="light"]` and `[data-kiwe-theme="dark"]` without hiding inactive navigation.
- Active and hover colors remain semantic state tokens, not a whole-page palette.

## Motion

Module entry and exit use the configured top, right, bottom, or left direction. Entry and exit use the same edge. `prefers-reduced-motion` disables decorative movement. Designs may alter timing curves within a restrained range but must not delay close, navigation, checkout, or protected-flow recovery.

## Interaction And Accessibility

- Dock actions expose `aria-pressed` and clicking the active action closes its module.
- Every module body remains a named `role="dialog"` region.
- Interactive controls do not trigger outside-click dismissal.
- Back gestures close a closeable Surface before browser navigation.
- PhoneKey and required Appsite permission gates may be non-dismissable.
- Minimum touch target is 44 CSS pixels where space permits; never below the geometry engine minimum.
- Focus, keyboard activation, reduced motion, readable contrast, and live status announcements are mandatory.
- Top-level DSA screen titles consume the Theme-owned `style[screen_heading_tag]` setting exposed in Kiwe > Theme as “Screen title tag.” Theme CSS may keep visual scale independent from semantic tag choice, but handoffs must document this setting instead of hard-coding H1/H2 choices silently.
- Games are the only general module class exempt from outside-click closure.

## Behavioral Ownership

A design package is presentation-only. It cannot implement or replace:

- Cart mutation, totals, discounts, coupons, checkout validation, or payment.
- PhoneKey, passkeys, OTP, role escalation, or account authorization.
- SecureTrack enforcement, rate limits, proxy resolution, or audit logging.
- Push subscription, notification authorization, rewards, or analytics identity.
- Trigger arbitration, protected-flow classification, navigation safety, or Surface history.

These remain deterministic DSA services. UI actions call existing runtime contracts.

## Marketplace Package Boundary

A future dock/Surface design package should contain:

```json
{
  "schema": "kiwe.surface-theme.v1",
  "id": "vendor.design-name",
  "name": "Design name",
  "version": "1.0.0",
  "profile": "marketplace",
  "mode": "css-only",
  "screens": ["profile", "cart", "search", "menu", "saved", "links", "notifications", "ios-install", "ai"],
  "requires": {
    "uiContract": "kiwe.surface-ui.v2",
    "tokenContract": "kiwe.universal"
  },
  "supports": ["light", "dark", "sheet", "classic", "dock", "split-dock", "full-dock", "horizontal", "vertical", "reduced-motion"],
  "css": ["css/design.css"],
  "assets": [],
  "tokens": {},
  "budgets": {
    "cssKb": 40,
    "jsKb": 0,
    "blockingAssets": 0
  }
}
```

Initial marketplace acceptance must be CSS/token/profile-led. Arbitrary visitor-facing JavaScript, PHP, remote fonts, trackers, network calls, duplicated transactional forms, service-worker files, Bricks template imports, and DOM replacement are rejected. A package import may include only its `theme.json`, listed CSS files, listed static assets, and human documentation. A package export must not include user data, orders, carts, addresses, notification preferences, PhoneKey state, Bricks post meta, SecureTrack data, PWA files, or release package-manifest hashes. `tools/ui-theme/validate-package.cjs` is the first source-level acceptance gate for this boundary; visual QA and responsive contract screenshots remain separate. Games use a separate reviewed module contract because they require executable logic.

Designer handoffs may include a standalone `preview/index.html`, but preview files are outside the import package. Preview controls must reserve their own space outside the app viewport, mock content must be natural rather than debug-dominant, and the preview shell must use production-style `data-dsa-*` attributes plus Geometry Engine variables instead of one-off fixed offsets. Dock previews must cover full compact dock, split compact dock, and navigation bar when the package claims support; split dock uses the production `dsa-dock-split` root class and `is-split-*` button classes and applies only to compact Dock presentation. `wp-content/mu-plugins/dsa/ui-system/preview-handoff.md` is the canonical rulebook for viewable HTML previews.

The built-in Legacy UI profile follows the same review loop. `wp-content/mu-plugins/dsa/ui-system/handoffs/legacy-ui-review/` is a portable handoff for auditing Legacy without giving a reviewer the full plugin. It contains a baseline manifest, preview, placeholder notes, and review brief; it is not a replacement theme and should not be installed over Legacy.

## Review Checklist

A candidate design passes only when it proves:

- 320px mobile through desktop, portrait and landscape.
- Horizontal and vertical docks in every supported position.
- Light/dark, glass/solid, rounded/square combinations.
- Safe areas, mobile browser chrome, browser zoom, and admin bar.
- Zero module content behind the dock/context divider.
- Cart, checkout, PhoneKey, back gesture, and active dock state remain authoritative.
- Keyboard, focus, reduced motion, readable contrast, and screen-reader labels.
- No new full-page scroll owner and no component-specific geometry formula.

## Dock Presentations

- `Dock` is compact and follows the configured alignment controls.
- `Navigation bar` fills the viewport axis for its orientation and attaches to the configured top, right, bottom, or left edge with zero external gap.
- Safe-area insets are padding inside a Navigation bar, never an offset outside it.
- Full-axis size never changes module order, badge state, context ownership, action behavior, or the cross-axis content reserve.
- Compact Dock and Navigation bar are renderer presentations over the same registered module contract; marketplace themes may style them but may not duplicate their DOM or logic.
## Responsive Profiles And Sheet Semantics (0.5.43)

The Geometry Engine resolves one active viewport profile: mobile, tablet, or desktop. Settings are normalized into runtime orientation, position, alignment, and edge state. Theme CSS consumes that state and must not reproduce profile-specific business logic.

Classic Surface preserves click-away dismissal where the target is non-interactive. Sheets use a different interaction contract: backdrop clicks close the panel, but empty clicks inside the sheet panel do not. The accessible handle supports click-to-close and directional pointer drag; Escape, browser back, the active Dock control, and explicit commands remain valid close paths. Every close path must use the shared Surface lifecycle so history, focus, scroll lock, games, and module cleanup remain coherent.

Navigation bar fills its selected viewport axis and touches the selected edge. Compact Dock remains content-sized. Dock context is measured from the active anchor and inherits material and geometry; modules cannot position it independently.
