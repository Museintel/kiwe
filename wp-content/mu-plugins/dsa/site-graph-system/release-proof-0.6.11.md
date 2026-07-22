# Kiwe 0.6.11 Companion AI proof

Release date: 2026-07-22

This release adds the first Kiwe Companion AI control surface. The Companion is a deterministic context broker and reviewer for external AI tooling. It does not call a model, does not own production behavior, and does not mutate WordPress, Bricks, WooCommerce, cart, checkout, auth, or SecureTrack enforcement.

## Implemented surfaces

- `Kiwe > AI` now owns Companion enablement, allowed Companion modes, privacy-safe memory/budget controls, redacted SecureTrack brief sharing consent, and SecureTrack cloud provider/model/API-key controls.
- `Kiwe > Secure` keeps local security and Site Brain controls but points provider/model/API-key work back to `Kiwe > AI`.
- API key scopes now include `companion` and `companion_securetrack`.
- REST routes:
  - `GET /wp-json/dsa/v1/ai/companion/status`
  - `GET|POST /wp-json/dsa/v1/ai/companion/context`
  - `POST /wp-json/dsa/v1/ai/companion/ask`
  - `POST /wp-json/dsa/v1/ai/companion/review-output`
  - `GET /wp-json/dsa/v1/ai/companion/memory`
  - `POST /wp-json/dsa/v1/ai/companion/memory/clear`
- WordPress 7 Abilities, when available:
  - `dsa/get-companion-context`
  - `dsa/ask-companion`
  - `dsa/review-ai-output`

## Security boundary

- SecureTrack brief details are off unless `Kiwe > AI` enables redacted sharing and the API key has `all`, `security_brief`, or `companion_securetrack`.
- Companion local memory stores only finding fingerprints, severity/code/message summaries, mode/lane, counts, and timestamps.
- Companion memory does not store prompts, handoff files, API keys, credentials, raw SecureTrack events, raw visitor trails, customer data, orders, payment data, or unredacted transcripts.

## Local verification commands

```bash
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Memory_Service.php
php -l wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php
php -l wp-content/mu-plugins/dsa/includes/WP7/Abilities_Service.php
node tools/connector/ai-api-contracts.cjs
node tools/release/verify-package.cjs
node tools/release/rc12-contracts.cjs
node tools/runtime/rc8-contracts.cjs
```

## Verified locally

- PHP lint passed on the touched PHP files.
- `tools/connector/ai-api-contracts.cjs` passed `44/44`.
- `tools/release/verify-package.cjs` verified package `0.6.11`.
- `tools/release/rc12-contracts.cjs` passed `16/16`.
- `tools/runtime/rc8-contracts.cjs` passed `11/11`.

Live Hostinger/API/browser verification still requires uploading the `0.6.11` MU-plugin folder to the staging site and creating a scoped Companion key from `Kiwe > AI`.
