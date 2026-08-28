# Bricks control follow-up and baseline alignment

## Live state

Authenticated read-only verification at 2026-08-28T08:45:47Z still found the
previous upload on the test site: Bricks 2.3.10, WooCommerce 11.0.1, 38 advertised
surface style keys, zero Add To Cart style keys, and no automatic-controls
notice. The saved Add To Cart, mini-cart and linked-product switches were false.
The new theme-based automatic registration is therefore not yet verified live.
Upload the canonical `wp-content/mu-plugins` directory, then confirm the notice
and all 53 catalog keys before refreshing the compiler's calibration.

Chrome was logged out of the editor. Public homepage content loaded, but visual
inspection stalled repeatedly. No screenshot/geometry or editor acceptance is
claimed. No live settings, templates, commerce records or plugin files changed.

## Three earlier baseline failures resolved

- Regenerated `packages/seam-contracts/generated/contracts.ts` from its existing
  schema. The missing optional binding `sources` manifest was real generated
  type drift, not merely Windows line endings.
- Updated the stale Ideate discovery v4 assertion to the actual strict v5
  contract. Also verify production-ready output has no placeholders/unresolved
  claims and has passed its runtime smoke test with an approved evidence basis.
- Replaced stale conversion-authority wording assertions with execution of all
  three CLI binding routes. Verify immutable source, no rewritten HTML, no
  Bricks JSON, expected graph/report artifacts and the exact modifier modes.

`node tools/release/verify-green-baseline.cjs` passes all gates and JavaScript
syntax checks. The automatic-control and surface-style PHP suites pass 874
assertions. These are local source tests, not live visual acceptance.

No MU-plugin runtime, command instructions or generated package manifest changed
in this follow-up. The canonical upload remains the preceding automatic-controls
package. The separate compiler batch records purchase-versus-navigation guards;
no approved HTML or exported baseline JSON was edited.
