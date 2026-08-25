# Kiwe 8.0 RC rollout

Kiwe 8.0.0-rc.1 is a clean product boundary, not a compatibility release. New
installations start with only read-only SiteGraph enabled. Existing explicit
administrator choices are preserved; capabilities introduced after an
installation remain disabled until deliberately enabled.

## Before each client site

1. Take a database backup and copy the existing `wp-content/mu-plugins` folder.
2. Record the current Kiwe, Bricks, WordPress, WooCommerce and PHP versions.
3. Upload only the canonical `wp-content/mu-plugins` contents. Do not deploy
   `dist`, `.tmp`, development tools or test-corpus files.
4. Open **Kiwe > Overview** and confirm that no optional service became enabled.
5. Export SiteGraph and confirm that it is read-only and contains no secrets.

## Acceptance pass

- Public home, archive, search, single post, product, cart, checkout and account
  surfaces render without PHP or browser-console errors.
- Bricks editor opens, saves and renders a harmless test change.
- Responsive navigation, AppShell and the enabled DSA surfaces work at desktop,
  tablet and mobile widths.
- PhoneKey sends one test notification and records the result; force one safe
  WhatsApp failure and verify the email fallback.
- SiteGraph status, export and validation work while remote mutation remains
  unavailable.
- Caches can be inspected and purged without deleting content or active-plugin
  data.
- Security and error logs remain clean for at least one normal checkout journey.

## Rollout order

Promote one low-risk client site first. Observe it for a full business cycle,
then promote two sites, then the remaining two. Stop promotion on any regression;
restore the saved MU-plugin folder and database backup before diagnosing.

RC is accepted only after all five sites pass the same checklist. Site-specific
workarounds do not become compiler or framework rules; reusable fixes require a
general regression test.
