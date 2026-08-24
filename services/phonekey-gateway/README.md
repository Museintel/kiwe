# Kiwe PhoneKey WhatsApp Gateway

This service is the bounded WhatsApp transport for PhoneKey verification. It is not a general messaging API.

The shared-hosting transport uses the unofficial WhatsApp Web protocol. It is deliberately restricted to requested authentication codes: no campaigns, scraping, unsolicited messaging, chat history, contact harvesting, or bulk sends. WhatsApp may disconnect or restrict an unofficial session, so email fallback and operator monitoring remain mandatory until the future official-provider lane is available.

## Deployment profiles

- **Shared-hosting RC1:** one Baileys session, no chat/history persistence, bounded memory, synchronous OTP acknowledgement, and immediate WordPress email fallback on any non-2xx response. Runtime credentials and WhatsApp state are excluded from Git.
- **VPS:** the same public gateway contract in front of pinned Evolution API 2.3.7, PostgreSQL 15, and Redis 7. Evolution's global API is kept off the public network.

Neither unofficial WhatsApp Web nor email can promise delivery. The implementation therefore promises deterministic channel handling: it reports WhatsApp success only after the transport accepts the send; every unavailable, rejected, timed-out, malformed, or non-2xx attempt tells PhoneKey to send the same OTP by email immediately.

## Security boundary

`POST /v1/otp` accepts only an allowlisted tenant, site origin, six-digit OTP, international phone number, unique request id, fresh timestamp, nonce, and SHA-256 HMAC over the exact request body. OTPs and full phone numbers are never returned or logged.

The pairing page is `GET /setup?token=...` and is protected by a separate high-entropy setup token. The public health endpoint contains no account or phone information.

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
