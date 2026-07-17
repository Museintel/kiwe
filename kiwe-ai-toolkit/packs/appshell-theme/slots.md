# Kiwe UI Slots

These selectors are the stable shell and screen slots theme authors may target. Do not rename or remove them in adapters.

## Shell

| Slot | Selector | Ownership |
|---|---|---|
| Surface root | `[data-dsa-surface]` | DSA runtime, profile/theme classes, geometry |
| Overlay root | `[data-dsa-overlay-root]` | DSA lifecycle and focus |
| Panel root | `.dsa-panel[role="dialog"]` | Screen adapter output |
| Dock cluster | `[data-dsa-dock-cluster]` | DSA dock geometry |
| Dock buttons | `[data-dsa-module]` | Registered modules from `Module_Registry` |
| AI popout | `[data-dsa-ai-popout]` | AI notification surface |
| Loader | `[data-dsa-loader]` | Transition/interstice system |

## Profile

| Capability | Selector / Required attribute |
|---|---|
| Profile panel | `[data-dsa-profile-panel]` |
| Profile form | `[data-dsa-profile-form]` |
| Avatar input | `[data-dsa-avatar-input]` |
| Email confirmation | `[data-dsa-profile-email-confirm]` |
| Account subview buttons | `[data-dsa-account-view]` |
| Downloads | `[data-dsa-account-view="downloads"]` |
| Addresses | `[data-dsa-account-view="addresses"]` |
| Logout | `[data-dsa-account-logout]` |
| Recent orders container | `[data-dsa-recent-orders]` |

## Commerce

| Capability | Selector / Required attribute |
|---|---|
| Cart panel | `[data-dsa-cart-panel]` |
| Checkout panel | `[data-dsa-checkout-panel]` |
| FBT/upsell rail | `.dsa-cart-fbt[data-dsa-cart-fbt]` with `.dsa-cart-fbt__rail[data-dsa-cart-fbt-rail]` |
| FBT add | `[data-dsa-cart-add]` |
| FBT claim | `[data-dsa-cart-claim]` |
| Checkout action | `.dsa-cart-panel__checkout`, `.dsa-checkout-continue` |

FBT is a fixed interaction pattern, not a loose list. Themes may restyle the cards, title, spacing, and buttons, but the FBT container must remain a horizontally scrolling rail with inline overflow, touch momentum, and snap behavior. Do not convert `.dsa-cart-fbt__rail` into an auto-fit grid or stacked vertical list.

## Search

| Capability | Selector / Required attribute |
|---|---|
| Search panel | `[data-dsa-search-panel]` |
| Search form | `[data-dsa-search-form]` |
| Search input | `[data-dsa-search-input]` |
| Filters | `[data-dsa-search-filters]` |
| Alphabet rail | `[data-dsa-search-alphabet]` |
| Results | `[data-dsa-search-results]` |
| Product quick add | `[data-dsa-search-add]` |

## Bricks/Page interop

| Capability | Selector / Required attribute |
|---|---|
| Bricks observed element | `[data-dsa-bricks-id]` |
| Bricks type | `[data-dsa-bricks-type]` |
| External module launcher | `[data-dsa-open-module]` |
| Menu context section | Public Seam section (`[data-role~="section"]` or `.seam-section`) with `id` and `aria-label`, `aria-labelledby`, or visible heading text |
| Bricks Search bridge | `[data-dsa-search-bridge]` |
| Full navigation link | `[data-dsa-full-navigation]` |
| Keep overlay open | `[data-dsa-keep-open]` |
