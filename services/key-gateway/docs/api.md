# Signed delivery API

Key.kiwe is a bounded Kiwe transport, not a public arbitrary-messaging API.

## Authentication headers

- `X-Kiwe-Key-Id`: website tenant key ID
- `X-Kiwe-Key-Timestamp`: current Unix timestamp
- `X-Kiwe-Key-Nonce`: unique request nonce
- `X-Kiwe-Key-Signature`: SHA-256 HMAC of the canonical signed payload
- `Origin`: the configured HTTPS website origin

The signature covers the exact request body. Nonces and timestamps prevent replay. A tenant is accepted only for its configured origin and active owner/site state.

## Endpoints

- `POST /v1/otp`: requested six-digit authentication codes only.
- `POST /v1/message`: bounded, consented Kiwe notifications from an allowlisted purpose.
- `POST /v1/event`: bounded delivery/fallback outcomes without OTP content.
- `GET /health`: public, non-sensitive service and transport state.

Payload length, phone format, purpose, request ID, timestamp, nonce, tenant, origin, and rate limits are validated before delivery. Responses never echo an OTP or full recipient number.

## Compatibility

Legacy header aliases are accepted temporarily on the server during rollout. New integrations must use `X-Kiwe-Key-*`.
