# Automatic Kiwe Bricks editor controls

## Policy

When the active theme is Bricks (including a child theme), Kiwe registers its
editor controls automatically. Saved optional runtime flags cannot hide them.
No settings migration, option write, template rewrite or source-specific rule
is used. The canonical upload directory remains `wp-content/mu-plugins`.

Registration runs at `after_setup_theme` priority 20, not `muplugins_loaded`:
MU plugins run before theme classes are loaded. Bricks 2.3.10 initializes
elements on `init`, leaving the control filters in place before that happens.
Active parent detection uses `get_template()` and parent theme metadata for a
renamed Bricks folder; merely installing Bricks is insufficient. Registration
is idempotent per integration instance.

## Controls versus behaviour

- Surface styles, Add To Cart style/control groups, mini-cart controls,
  linked-product controls, contact actions and launcher controls are available.
- The 38 catalog additions and 15 existing Add To Cart styles are advertised
  to compiler calibration regardless of the Add To Cart behaviour switch.
- Optional AJAX/quantity/cart/recommendation behaviour still respects its
  saved global switches and existing per-element requirements.
- Styles do not create missing markup such as quantity buttons. Enabling
  an editor field is not a promise that every WooCommerce state is styled.
- Dynamic-tag availability keeps its existing separate setting.
- Dock, PhoneKey, SecureTrack, notifications, framework and other services
  are not activated. Fresh-install SiteGraph-only runtime defaults remain.
- Kiwe > Bricks and editor info boxes explain this distinction.

## Verification

- Isolated PHP tests: active Bricks parent, child theme, renamed parent,
  inactive theme, missing theme, all runtime flags false, existing flags true,
  duplicate registration, exact advertised controls, preservation of native
  controls, style groups, no default styles, no settings writes or cart assets.
- Fresh-install and existing-install settings tests remain passing.
- Compiler surface-style tests: 9 passing, including exact-capability checks
  and fallback when the required target control is absent.
- PHP syntax and whitespace checks run for changed source files.
- CI now runs the control tests on its PHP 8.2 / 8.3 / 8.4 matrix. Local runtime
  is PHP 8.4; CI matrix results are separate from local verification.
- The two PHP control suites pass 874 assertions in total. The persistence
  contract now checks theme-based registration instead of the removed flag
  gate (11/11 checks pass). All 332 canonical package files are verified;
  the manifest also now includes the preceding batch's two surface-control files.

### Broader baseline is not fully green

The broader source baseline exposed three existing failing gates, reproduced
against pre-change HEAD with changed files read from Git in memory (no worktree
replacement):

- Compiler foundation: generated `contracts.ts` drift in the Windows checkout.
- SiteGraph design context: an assertion still expects discovery schema v4.
- AI API source contracts: two command-authority text assertions fail.

These were not introduced by automatic editor activation. They are recorded
for a separate contract/test-alignment pass; this batch does not claim full
platform release acceptance. The package-integrity and persistence checks that
needed updating for this batch now pass, without weakening behaviour tests.

## Live upload check (before this new change)

Authenticated read-only verification of
`https://plum-giraffe-539524.hostingersite.com` confirmed WordPress 7.0.4,
Bricks 2.3.10, WooCommerce 11.0.1 and Kiwe 8.0.0-rc.1. The native Kiwe
calibration export reported all 38 new surface controls. Existing Add To Cart
styles were absent because its saved enhancer switch was false. This is the
previous batch's uploaded version; the automatic-controls correction in this
batch is not live until the user uploads the canonical folder again.

No live settings, templates, posts or commerce records were changed. The
offline `mishtanna best candidate templates` baseline remains untouched.
After upload, verify the automatic-controls notice and all 53 catalogued
style keys, refresh compiler calibration/pairing, then test editor rendering.
This batch is not a new visual acceptance claim for the templates.
