# Key.kiwe documentation

Key.kiwe is Kiwe’s WhatsApp-first identity and isolated website connection service.

## Start here

1. Create an account at `https://key.kiwelaunch.com/register` with an international WhatsApp number.
2. Scan the QR from that number through WhatsApp Linked devices.
3. Add a website. It inherits the account primary number by default.
4. Copy its one-time tenant key ID and signing secret into Kiwe → Key → WhatsApp provider.
5. Optionally pair a separate client number for only that website.

## Account and login

Registration uses a matching-number QR scan. Login uses a six-digit, five-minute code delivered from the account’s own WhatsApp connection to the registered number. If that connection is offline, `/reconnect` requires a fresh matching-number QR scan and then restores the same account, sites, and credentials.

## Website connection modes

- `account_primary`: the website uses its owner’s primary number.
- `dedicated`: the website uses its own isolated client number.

Replacing a dedicated number affects only that website. Returning it to primary closes the dedicated runtime and clears that association. Primary replacement is atomic: inherited websites continue on the old number until the new matching scan succeeds.

## Network Oversight

The master view shows every account, website, actual serving number, connection mode, status, last transaction, and account action. Full numbers are restricted to the master for operational support and abuse response.

## WordPress

Use gateway `https://key.kiwelaunch.com/v1/otp`, the generated tenant key ID, and the one-time signing secret. Kiwe 8.0.0-rc.45+ automatically migrates the exact former hosted gateway URL without changing WordPress accounts or tenant credentials.

## Security

Phone numbers and tenant secrets are AES-256-GCM encrypted. Lookup, session, pairing, reconnection, and challenge material are stored as hashes. OTP bodies are never retained. Each website has isolated HMAC credentials and an approved HTTPS origin. Requests require a fresh timestamp, unique nonce, exact-body signature, bounded purpose, and rate-limit allowance.

## Endpoints

- `POST /v1/otp` — requested authentication codes.
- `POST /v1/message` — bounded consented notifications.
- `POST /v1/event` — bounded outcome/fallback reports.
- `GET /health` — non-sensitive health state.
- `/register`, `/login`, `/reconnect`, `/app`, `/network` — account surfaces.

## Operations

HTTP 200 from `/health` means the WhatsApp transport is open. HTTP 503 means WordPress should use its deterministic email fallback or the owner should reconnect. Production state lives outside deployment roots. Releases must pass `npm test`, `npm run check:compat`, and `npm run build` before deployment.

Source and complete Markdown documentation: `https://github.com/Museintel/key.kiwe`.
