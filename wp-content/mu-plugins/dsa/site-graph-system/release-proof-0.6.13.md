# Kiwe 0.6.13 Bricks AI Intelligence proof

Release date: 2026-07-22

This release adds the Bricks-native intelligence layer for Kiwe Studio AI and external browser/tool AIs. The goal is to let AI plan real Bricks pages using Bricks elements, query loops, dynamic tags, display conditions, interactions, and Seam vocabulary without reading the whole plugin or guessing Bricks internals.

## Implemented surfaces

- New read-only `DSA\AI\Bricks_AI_Intelligence_Service`.
- New API-key scope:
  - `bricks_ai`
- `studio_ai` keys are allowed to read Bricks AI Intelligence because Studio packets depend on it.
- API-key REST routes:
  - `GET|POST /wp-json/dsa/v1/ai/bricks/context`
  - `POST /wp-json/dsa/v1/ai/bricks/plan`
- Logged-in Bricks front-end editor REST routes:
  - `GET|POST /wp-json/dsa/v1/bricks/studio/context`
  - `POST /wp-json/dsa/v1/bricks/studio/start`
  - `POST /wp-json/dsa/v1/bricks/studio/draft`
- Optional Bricks front-end editor companion toggle under `Kiwe > AI`.
- Bricks editor companion assets:
  - `assets/js/bricks-studio-ai.js`
  - `assets/css/bricks-studio-ai.css`
- Kiwe Studio packets now include a `bricksBuilder` lane containing the Bricks AI planning packet.

## What the Bricks packet can describe

- Bricks active/version signals.
- Available Bricks elements from Bricks abilities/reference APIs when available, with safe static fallbacks.
- Compact element schemas/controls for requested elements.
- Query loop object types.
- Dynamic data tags.
- Bricks interaction controls.
- Bricks condition controls.
- Seam headless class/attribute rules.
- Kiwe launcher/runtime boundaries such as `data-dsa-open-module`.

## Safety boundary

This release is read-only/planning-only. It does not save Bricks content, publish WordPress content, mutate WooCommerce, run checkout/cart/auth, change SecureTrack enforcement, or write raw `_bricks*` meta. Real staging writes still require the existing controlled executor confirmations and rollback chain.

The Bricks front-end editor companion is also read-only in this release. It can fetch context, create a Studio plan, or ask native AI when explicitly enabled, but it returns copyable output rather than mutating the current editor tree.

## Local verification commands

```bash
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_AI_Intelligence_Service.php
php -l wp-content/mu-plugins/dsa/includes/Rest/Bricks_Studio_Controller.php
php -l wp-content/mu-plugins/dsa/includes/AI/Studio_AI_Service.php
php -l wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php
php -l wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php
php -l wp-content/mu-plugins/dsa/includes/Settings.php
php -l wp-content/mu-plugins/dsa/includes/Plugin.php
php -l wp-content/mu-plugins/dsa/includes/AI/Access_Key_Service.php
node --check wp-content/mu-plugins/dsa/assets/js/bricks-studio-ai.js
node tools/connector/ai-api-contracts.cjs
node tools/release/verify-package.cjs
node tools/release/rc12-contracts.cjs
node tools/runtime/rc8-contracts.cjs
node tools/runtime/htmx-alpine-contracts.cjs
```

## Verified locally

- PHP lint passes on the touched PHP files.
- `node --check wp-content/mu-plugins/dsa/assets/js/bricks-studio-ai.js` passes.
- `tools/connector/ai-api-contracts.cjs` passes `55/55`.
- `tools/release/verify-package.cjs` verifies package `0.6.13` with `251` files.
- `tools/release/rc12-contracts.cjs` passes `16/16`.
- `tools/runtime/rc8-contracts.cjs` passes `11/11`.
- `tools/runtime/htmx-alpine-contracts.cjs` passes `11/11`.

Live Hostinger/API/browser verification still requires uploading the `0.6.13` MU-plugin folder to the staging site, enabling Studio and the Bricks editor companion in `Kiwe > AI`, and creating a scoped key with `bricks_ai` or `studio_ai`.
