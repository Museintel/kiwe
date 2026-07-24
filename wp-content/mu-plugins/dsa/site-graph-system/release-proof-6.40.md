# Kiwe 6.40 release proof

Date: 2026-07-24

## Scope

This release is a launch-readiness hardening pass for the 6.39 → 1.0 journey on the staging/live test site. It focuses on the areas that must be trusted before traffic-facing rollout:

- PhoneKey privileged-admin access;
- cart and checkout REST contracts;
- abandoned-cart tracking and reminder email foundations;
- admin settings that must save and clear reliably;
- DSA sheet-mode open/rerender behavior.

## Live 6.39 evidence gathered before fixes

- Public manifest endpoint responded with Kiwe version `6.39`.
- Public cart contract endpoint responded successfully with cart availability, item count, totals, checkout/cart URLs, recommendations, and upsell structures.
- Public cart nonce endpoint responded successfully.
- Public checkout contract endpoint responded successfully with checkout availability, groups, values, errors/notices, totals, discount summary, shipping state, and checkout URL.
- PhoneKey public config endpoint responded successfully for the staging host RP ID.
- Cart mutation without `X-Kiwe-Mutation: 1` was rejected with `dsa_mutation_proof_missing`.
- Cart mutation with cross-site origin plus mutation proof was rejected with `dsa_cross_site_mutation`.
- Secure settings save testing exposed a real list-merge regression: role checkboxes could remain saved even after the UI submitted them cleared.
- SecureTrack auto logout was off during testing, yet the admin page showed the account still needed verified phone setup for privileged assurance.
- DSA sheet-mode testing found that async-opened Cart/Profile/Search panels could remain in an entering animation state, leaving panels partly offscreen or semi-transparent.

## Fixed in 6.40

- Numeric/list settings now replace the previously saved list when a submitted list is present. This fixes sticky role-checkbox behavior and protects similar list settings elsewhere.
- PhoneKey redirects privileged users to WordPress admin after the configured high-assurance login path completes. It does not weaken privileged assurance requirements.
- Abandoned Cart gained opt-in automatic email reminders during hourly maintenance.
- Guest checkout email reminders are an explicit opt-in and store the checkout email encrypted with Kiwe Secret Store; registered/PhoneKey user reminders continue to use account contacts.
- Abandoned-cart email copy now supports `{cart_items}` and includes a stronger default revisit/checkout-completion message.
- Abandoned-cart email reminders are sent as branded HTML emails with site logo fallback, cart details, cart total, and restore-cart CTA.
- Channel delivery treats abandoned-cart automation as a campaign-safe email purpose without changing manual reminder checks.
- Sheet-mode DSA panels now settle to their final Geometry Engine position after CSS animation or token-duration fallback, including after async content replacement.

## Validation

- PHP syntax checks passed for the modified PHP runtime files:
  - `includes/Settings.php`
  - `includes/Admin/Admin.php`
  - `includes/Communications/Channel_Service.php`
  - `includes/Commerce/Abandoned_Cart_Service.php`
  - `includes/PhoneKey/phonekey-core.php`
- JavaScript syntax could not be executed through the local Node CLI because the shell Node process hung, and the Node REPL sandbox disables string-code parsing. The JavaScript change is localized and manually reviewed.
- Package manifest was rebuilt after the version bump and runtime changes.

## Required post-upload checks

After uploading both `wp-content/mu-plugins/dsa.php` and the complete `wp-content/mu-plugins/dsa/` folder from the same 6.40 release:

1. Confirm the public manifest reports `6.40`.
2. Re-run Secure settings save/clear for role checkboxes and auto logout.
3. Complete PhoneKey admin flow with email OTP, passkey, and verified phone setup; confirm WordPress admin opens after the assurance policy is satisfied.
4. Open Search, Cart, Profile, and Checkout sheets from the dock repeatedly; confirm one panel is visible, centered/settled, and not stuck mid-animation.
5. Add/remove cart items and confirm cart badge, first-add behavior, FBT rail, checkout contract, and mutation-proof protections still work.
6. Enable abandoned-cart automatic email on staging only after email transport is confirmed; create an identified-user test cart, let it age past the configured threshold, run hourly maintenance, and confirm the reminder email contains site logo, cart line items, cart total, custom/default message, and restore link.
7. If guest recovery is enabled, create a guest checkout cart after entering a billing email, age it past the configured threshold, run hourly maintenance, and confirm the guest email reminder sends from the encrypted contact lane.
