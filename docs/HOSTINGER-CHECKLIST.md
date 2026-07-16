# Hostinger Checklist

## PHP

Use PHP 8.4 on staging/development if Bricks runs cleanly. Use PHP 8.3 as the conservative production fallback.

Enable or verify:

- `curl`
- `dom`
- `fileinfo`
- `gd`
- `imagick`
- `intl`
- `mbstring`
- `mysqli`
- `pdo`
- `pdo_mysql`
- `opcache`
- `xmlreader`
- `xmlwriter`
- `zip`

Nice to have:

- `apcu`
- `brotli`
- `sodium`

## PHP Options

- `displayErrors`: off
- `logErrors`: on for staging
- `opcache.enable`: on
- `allowUrlFopen`: on
- `exposePhp`: off
- `fileUploads`: on

## Required Hosting Tests

- Confirm REST API works.
- Confirm pretty permalinks work.
- Confirm MU plugin loads.
- Confirm Bricks builder still opens.
- Confirm the Kiwe service-worker response is same-origin, JavaScript, and carries the intended scope header.
- Confirm WordPress loopback/cron can execute the S18 build and Push queues when those gated features are enabled.
- Confirm OpenSSL is available before enabling Web Push delivery.
- Confirm WordPress mail handoff and actual inbox delivery separately; `wp_mail()` success does not prove delivery.
- Confirm uploads are writable before enabling generated asset delivery.

## Cache

Keep full-page caching disabled while diagnosing identity, checkout, cart, Push, or SecureTrack behavior. For release proof, capture one no-persistent-cache profile and one Redis/Memcached profile when available. Any LiteSpeed/CDN page cache must exclude checkout, cart, account, PhoneKey, DSA personalized responses, admin, and other protected routes. Controlled editorial and generated-asset pilots remain off until their separate proof matrices pass.

## Release Gate

- Run `Kiwe > Developer > Production readiness` and record warnings rather than treating it as automatic certification.
- Run `node tools/certification/rc13-public-live.cjs https://your-site.example` from the canonical project. This is a read-only/public-boundary preflight except for two deliberately rejected cart mutations using product ID `0`; it must not add an item or create an order.
- On a staging/test store with at least one purchasable simple product, run `node tools/certification/rc13-commerce-live.cjs https://your-site.example`. It creates an isolated anonymous Woo session, adds one unit, verifies a separate read, exercises quantity when allowed, and removes the item in cleanup. It creates ordinary cart analytics events but no order, stock reduction, or payment.
- Run `node tools/certification/rc14-public-live.cjs https://your-site.example` after disabling frontend diagnostics. It is read-only and checks document/feed/robots isolation, no-snippet protection, canonical count, service-worker scope, private hydration, deployed assets, and production quietness.
- Verify dock safe areas, browser chrome resize, zoom, horizontal/vertical placement, Surface scrolling, and back gesture on real Android and iOS devices.
- Test one staging-only loader failure and confirm WordPress fails open with a useful debug-log record.
- Disable frontend diagnostics and runtime profiling after evidence is collected.
