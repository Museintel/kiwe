# Kiwe 0.6.12 Studio AI proof

Release date: 2026-07-22

This release adds Kiwe Studio AI as the workflow layer above Companion. Studio supports three operating modes:

- `native` - Kiwe may call the configured native provider for bounded drafting only when native generation is enabled and the API key has `native_ai` scope.
- `browser_companion` - browser/IDE/GitHub AI uses Kiwe Studio packets plus Companion deterministic review; Kiwe does not call a model.
- `browser_only` - Kiwe provides public toolkit guidance only; no internal AI support is expected.

Studio is not a production behavior owner. It does not save Bricks, publish WordPress content, mutate WooCommerce, run cart/checkout/auth, change SecureTrack enforcement, or expose secrets.

## Implemented surfaces

- `Kiwe > AI` now owns Studio enablement, operating mode, native provider/model/base URL/API key, native token/context budgets, token-saver preference, Companion enablement, Companion memory/budgets, and SecureTrack AI bridge settings.
- Native provider API keys are encrypted by Kiwe Secret Store.
- API key scopes now include:
  - `studio_ai`
  - `native_ai`
- REST routes:
  - `GET /wp-json/dsa/v1/ai/studio/status`
  - `POST /wp-json/dsa/v1/ai/studio/start`
  - `POST /wp-json/dsa/v1/ai/studio/draft`
  - `POST /wp-json/dsa/v1/ai/studio/review`
- WordPress 7 Abilities, when available:
  - `dsa/start-studio-project`
  - `dsa/review-studio-output`
- Site Graph connector summaries now advertise Companion and Studio routes so external tools can discover the intended flow without scanning the plugin.

## Security and token boundary

- A normal `studio_ai` key can request Studio packets and deterministic review.
- Add `native_ai` only when that key may spend provider tokens.
- Native drafting still requires `Kiwe > AI` native generation to be enabled.
- Studio packets are compact, hashable, bounded, and built from Site Graph + toolkit + Companion cards.
- The native provider adapter supports WordPress AI Client detection plus OpenAI-compatible, Gemini, Groq, and xAI HTTP envelopes, but it never grants mutation authority.

## Local verification commands

```bash
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Provider_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Studio_AI_Service.php
php -l wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php
php -l wp-content/mu-plugins/dsa/includes/AI/Access_Key_Service.php
php -l wp-content/mu-plugins/dsa/includes/WP7/Abilities_Service.php
php -l wp-content/mu-plugins/dsa/includes/Settings.php
php -l wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php
node tools/connector/ai-api-contracts.cjs
node tools/release/verify-package.cjs
node tools/release/rc12-contracts.cjs
node tools/runtime/rc8-contracts.cjs
```

## Verified locally

- PHP lint passes on touched PHP files.
- `tools/connector/ai-api-contracts.cjs` passes `49/49`.
- `tools/release/verify-package.cjs` verifies package `0.6.12`.
- `tools/release/rc12-contracts.cjs` passes `16/16`.
- `tools/runtime/rc8-contracts.cjs` passes `11/11`.
- `tools/runtime/htmx-alpine-contracts.cjs` passes `11/11`.

Live Hostinger/API/browser verification still requires uploading the `0.6.12` MU-plugin folder to the staging site, enabling Studio in `Kiwe > AI`, and creating a scoped key with `studio_ai` and, only when testing provider spend, `native_ai`.
