# Kiwe 0.6.8 notification / dock polish release proof

Date: 2026-07-22

This file closes the post-Hostinger polish pass after the `0.6.7` Site Graph + internal AI release proof. It keeps the same API/staging boundaries while adding deterministic notification-stack proof and a 320px split-dock stress check.

## Local package proof

Run before uploading the folder-based MU plugin package:

```bash
php -l wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php

node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
npm.cmd test --prefix kiwe-ai-toolkit
```

Expected:

- loader, package entry, and manifest versions all read `0.6.8`;
- `package-manifest.json` includes every file under `wp-content/mu-plugins/dsa/`;
- runtime hashes match the uploaded folder;
- Kiwe AI Toolkit contexts still validate.

## SecureTrack logout proof

On staging, open `Kiwe > Secure` and confirm:

- `secure[enabled]` may be on;
- `secure[auto_logout_enabled]` is unchecked unless the site owner intentionally enables idle logout;
- `secure[auto_logout_minutes]` can show a value such as `30`, but it is inert while auto logout is unchecked;
- if auto logout is unchecked, unexpected admin sign-out is not attributed to SecureTrack.

## Notification stack proof

Use the real runtime hook after the AppShell has booted:

```js
window.DSA.previewNotification({ title: 'One', body: 'First proof notice', actionLabel: 'Dismiss' });
window.DSA.previewNotification({ title: 'Two', body: 'Second proof notice', actionLabel: 'View' });
window.DSA.previewNotification({ title: 'Three', body: 'Third proof notice', actionLabel: 'Apply' });
```

Expected:

- notices mount in `[data-dsa-notification-stack]`, outside dock geometry;
- desktop positions the stack at the top-right safe area;
- mobile positions the stack at the top safe area;
- collapsed notices cascade compactly;
- hover/focus expands visible cards so actions remain reachable;
- the stack works even if the `ai` or `notifications` dock items are hidden.

## Browser smoke proof

Repeat the `0.6.7` browser checks and add:

- 320px split compact dock: the last control must not hang past the visual viewport;
- Search opened from dock must not auto-focus the input on mobile;
- Cart FBT rail must remain horizontal and readable with a real cart line;
- external Bricks/site popups must sit above Kiwe dock, while Kiwe dock yields pointer events;
- no horizontal page overflow at desktop, tablet, mobile, 390px, 360px, and 320px.

## API proof

The `0.6.7` Site Graph/Internal AI API proof still applies:

```text
GET  /wp-json/dsa/v1/ai/status
GET  /wp-json/dsa/v1/ai/site-graph?sampleLimit=8
GET  /wp-json/dsa/v1/ai/site-graph-data/schema
GET  /wp-json/dsa/v1/ai/site-graph-data?resource=site
POST /wp-json/dsa/v1/ai/site-graph-data
GET  /wp-json/dsa/v1/ai/security-brief
GET  /wp-json/dsa/v1/ai/internal-context
GET  /wp-json/dsa/v1/ai/advisor
POST /wp-json/dsa/v1/ai/advisor
GET  /wp-json/dsa/v1/ai/advisor/enrich
POST /wp-json/dsa/v1/ai/advisor/enrich
GET  /wp-json/dsa/v1/ai/site-inspection?sampleLimit=8
GET  /wp-json/dsa/v1/ai/themes
```

Mutation/runtime safety checks:

- `/ai/mutations/bricks-page-save` refuses without explicit staging confirmation;
- `/ai/mutations/woocommerce` refuses without explicit staging confirmation;
- `/ai/runtime/cart`, `/ai/runtime/checkout`, and `/ai/runtime/auth` refuse without explicit runtime confirmation;
- `/ai/staging/execute` refuses an empty body with `missing_execution`.

## Phase boundary

This release adds deterministic UI proof hooks and geometry polish only. It does not make notification permission, push delivery, AI actions, Bricks saves, WooCommerce mutations, checkout, cart, auth, or security enforcement theme-owned or silently executable.
