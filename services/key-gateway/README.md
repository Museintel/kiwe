# Key.kiwe

Key.kiwe is Kiwe’s WhatsApp-first identity and website connection service. It combines a public product site, number-only account enrollment, passwordless account access, isolated website credentials, and bounded WhatsApp delivery.

Public documentation: [start.kiwelaunch.com/key/](https://start.kiwelaunch.com/key/). The complete repository documentation begins at [docs/README.md](docs/README.md).

## Account model

- Registration asks for one WhatsApp number and one linked-device scan. A successful scan proves control of the entered number and creates the account.
- Login asks for the registered number. Key.kiwe sends a six-digit, five-minute code from that account’s own WhatsApp connection to the same number.
- Every account owns one primary WhatsApp connection.
- New websites inherit the primary connection by default.
- Any website can switch to a client-specific number, replace that number, deactivate independently, or return to the account primary without disturbing other websites.
- The bootstrap master has an ordinary client dashboard plus a separate network-oversight view for account/site monitoring and suspension.
- Network Oversight identifies the actual number serving every website and whether it is inherited or client-specific, giving the master enough evidence to investigate misuse.
- An offline primary connection can be repaired from `/reconnect`; only a fresh scan from the already-registered number can restore the account.

## Delivery security

`POST /v1/otp`, `/v1/message`, and `/v1/event` accept only an allowlisted tenant, approved HTTPS origin, fresh timestamp, unique nonce, bounded payload, and SHA-256 HMAC over the exact request body. Each website receives an isolated key ID and one-time signing secret. Secrets and phone numbers are encrypted at rest; OTP bodies are never retained.

The shared-hosting transport uses an exactly pinned Baileys release with guarded reconnects, bounded memory, no history sync, and immediate email-fallback signalling whenever WhatsApp is unavailable. It is not an arbitrary or bulk messaging API.

Use these values in Kiwe → Key → WhatsApp provider:

- Gateway URL: `https://key.kiwelaunch.com/v1/otp`
- Tenant key ID: generated per website
- Signing secret: generated once per website

## Persistent state

Production uses an absolute state directory outside every deployment root. The state contains encrypted account/site metadata, sessions, primary and client-specific linked-device credentials, and bounded delivery history. `legacyStateDirectory` is a one-time migration source: the server copies it only when the new state directory does not yet exist.

The compatibility cipher derivation remains stable during the product-name migration so existing encrypted tenant secrets stay readable.

## Checks

```sh
npm ci
npm test
npm run check:compat
npm run build
```

Copy `runtime-config.example.json` to ignored `runtime-config.json` for local work. Never commit or deploy runtime secrets; production values belong in Hostinger’s Node environment.
