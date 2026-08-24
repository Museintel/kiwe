# Kiwe PhoneKey WhatsApp Gateway

This service is the bounded WhatsApp transport for PhoneKey verification and consented Kiwe notifications. It is not an open or arbitrary messaging API.

The shared-hosting transport uses the unofficial WhatsApp Web protocol. It accepts only requested authentication codes and an allowlist of Kiwe purposes: opted-in campaigns, saved-cart reminders, WooCommerce order status, and owner events. It does not expose scraping, contact harvesting, arbitrary API sends, or unbounded bulk delivery. WhatsApp may disconnect or restrict an unofficial session, so consent-aware email fallback and operator monitoring remain mandatory until the future official-provider lane is available.

## Deployment profiles

- **Shared-hosting RC:** one exactly pinned Baileys 7 RC session, bounded memory, synchronous delivery acknowledgement, and immediate WordPress email fallback on eligible non-2xx responses. PhoneKey negotiates the current WhatsApp Web protocol version at startup, exposes only bounded connection diagnostics, and uses guarded reconnect backoff. Optional RC observability stores a capped encrypted timeline in one state file. OTP bodies are never retained; consented notification content is encrypted at rest only when the RC capture switch is enabled.
- **VPS:** the same public gateway contract in front of pinned Evolution API 2.3.7, PostgreSQL 15, and Redis 7. Evolution's global API is kept off the public network.

Neither unofficial WhatsApp Web nor email can promise delivery. The implementation therefore promises deterministic channel handling: it reports WhatsApp success only after the transport accepts the send; every unavailable, rejected, timed-out, malformed, or non-2xx attempt tells PhoneKey to send the same OTP by email immediately.

## Security boundary

`POST /v1/otp` accepts only an allowlisted tenant, site origin, six-digit OTP, international phone number, unique request id, fresh timestamp, nonce, and SHA-256 HMAC over the exact request body. OTPs and full phone numbers are never returned or logged.

`POST /v1/message` uses the same signed tenant and origin boundary. It accepts only bounded text, a unique request id, and one of the compiled Kiwe notification purposes. Kiwe performs user/topic/channel consent checks before calling it; the gateway adds independent tenant and target rate limits.

The pairing page is `GET /setup?token=...` and is protected by a separate high-entropy setup token. It reports the audited library version, negotiated protocol version, bounded disconnect reason, and email-fallback state so operators are not encouraged to loop a rejected QR. The public health endpoint contains no account, phone, QR, token, or message information.

When WhatsApp explicitly replaces or logs out the linked device, the protected setup page exposes a deliberate **Reset linked device** action. It moves only the Baileys session state into a timestamped recovery backup, preserves RC delivery history, restarts the supervised process, and then presents one fresh QR. Tenant secrets and Kiwe configuration are never reset.

Run `npm run check:compat` before deployment. CI fails if the manifest, lockfile, displayed runtime version, or npm `latest` tag diverges; upstream changes therefore require an explicit compatibility review rather than silently entering production.

## RC observability

`rcObservability.enabled` activates a protected delivery timeline at `GET /rc?token=...`. It records signed request, WhatsApp acceptance/delivery, failure, inbound replies, and WordPress email-fallback outcomes. Recipients are hashed and reduced to their last four digits. OTP codes are never stored. Inbound and consented outbound text are encrypted at rest only when their separate RC capture flags are enabled.

`captureInboundText` is an RC-only consent switch. When enabled, inbound WhatsApp text is encrypted with AES-256-GCM in the bounded history file and is visible only through the protected RC view. Disable it for public operation. Retention is capped to 30 days and 10,000 events by configuration; the supplied RC defaults are 14 days and 3,000 events.

## Local checks

```sh
npm ci
npm test
```

Copy `runtime-config.example.json` to the ignored `runtime-config.json`, replace every placeholder with generated secrets, and keep the state directory outside the deployment root so a source deployment does not unlink WhatsApp.

For the first tenant, the bundled generator creates both secrets without printing them:

```sh
node tools/create-runtime-config.mjs --key-id client-id --site https://client.example --label "Client name"
```
