# Kiwe 0.6.14 SecureTrack AI settings consolidation proof

Release date: 2026-07-22

This release removes the confusing separate SecureTrack provider/model/API-key lane from `Kiwe > AI`.

## Implemented behavior

- `Kiwe > AI` still owns AI settings, but SecureTrack is now presented as:
  - a redacted SecureTrack brief toggle for Companion/API use;
  - SecureTrack Site Brain review cadence/local policy controls;
  - a read-only explanation that SecureTrack cloud review syncs from the shared Native AI provider/key when the provider is supported.
- No `securetrack_ai[v2_ai_key]` field is rendered by `Kiwe > AI`.
- No `securetrack_ai[v2_ai_provider]` or separate SecureTrack model selector is rendered by `Kiwe > AI`.
- SecureTrack cloud review syncs from Native AI settings only for providers SecureTrack currently supports directly: `gemini`, `groq`, and `xai`.
- If the Native AI provider is `none`, `wordpress_ai_client`, or `openai_compatible`, SecureTrack Site Brain remains local-only while redacted SecureTrack context can still flow to Companion when the consent toggle and API key scope allow it.

## Safety boundary

SecureTrack still owns local security enforcement and Site Brain. Companion/Studio AI do not mutate SecureTrack enforcement, resolve alerts, block users/IPs, or expose raw security logs. SecureTrack context remains redacted and gated by both `Kiwe > AI` consent and API key scope.

## Local verification commands

```bash
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php
php -l wp-content/mu-plugins/dsa/includes/Secure/securetrack-admin-core.php
node tools/connector/ai-api-contracts.cjs
node tools/release/verify-package.cjs
node tools/release/rc12-contracts.cjs
node tools/runtime/rc8-contracts.cjs
```

## Verified locally

- PHP lint passes on the touched PHP files.
- `tools/connector/ai-api-contracts.cjs` passes `55/55`.
- `tools/release/verify-package.cjs` verifies package `0.6.14` with `252` files.
- `tools/release/rc12-contracts.cjs` passes `16/16`.
- `tools/runtime/rc8-contracts.cjs` passes `11/11`.
- `tools/runtime/htmx-alpine-contracts.cjs` passes `11/11`.
