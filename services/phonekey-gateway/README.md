# Kiwe PhoneKey WhatsApp Gateway

This service is the bounded WhatsApp transport for PhoneKey verification. It is not a general messaging API.

The shared-hosting transport uses the unofficial WhatsApp Web protocol. It is deliberately restricted to requested authentication codes: no campaigns, scraping, unsolicited messaging, chat history, contact harvesting, or bulk sends. WhatsApp may disconnect or restrict an unofficial session, so email fallback and operator monitoring remain mandatory until the future official-provider lane is available.

## Deployment profiles

- **Shared-hosting RC:** one Baileys session, bounded memory, synchronous OTP acknowledgement, and immediate WordPress email fallback on any non-2xx response. Optional RC observability stores a capped encrypted timeline in one state file. OTP bodies are never retained.
- **VPS:** the same public gateway contract in front of pinned Evolution API 2.3.7, PostgreSQL 15, and Redis 7. Evolution's global API is kept off the public network.

Neither unofficial WhatsApp Web nor email can promise delivery. The implementation therefore promises deterministic channel handling: it reports WhatsApp success only after the transport accepts the send; every unavailable, rejected, timed-out, malformed, or non-2xx attempt tells PhoneKey to send the same OTP by email immediately.

## Security boundary

`POST /v1/otp` accepts only an allowlisted tenant, site origin, six-digit OTP, international phone number, unique request id, fresh timestamp, nonce, and SHA-256 HMAC over the exact request body. OTPs and full phone numbers are never returned or logged.

The pairing page is `GET /setup?token=...` and is protected by a separate high-entropy setup token. The public health endpoint contains no account or phone information.

## RC observability

`rcObservability.enabled` activates a protected delivery timeline at `GET /rc?token=...`. It records signed request, WhatsApp acceptance/delivery, failure, and WordPress email-fallback outcomes. Recipients are hashed and reduced to their last four digits. Outbound message content and OTP codes are never stored.

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
