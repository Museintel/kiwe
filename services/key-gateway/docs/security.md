# Security and privacy

## Stored data

- Account and client phone numbers are encrypted with AES-256-GCM.
- Phone lookup uses a one-way SHA-256 digest.
- Website signing secrets are encrypted with AES-256-GCM.
- Session, pairing, reconnection, and login tokens are stored as hashes.
- OTP codes are stored as salted challenge hashes and expire after five minutes.
- WhatsApp linked-device credentials live in isolated, permission-restricted directories outside the deployment root.

## Isolation

Every website has a separate HMAC identity. Dedicated client connections use separate persistent transport directories. Account-primary websites share only the chosen primary transport; they never share signing secrets.

## Abuse controls

Registration, login, reconnect, tenant, recipient, and endpoint limits are independent. A master can inspect account/site/serving-number relationships and suspend a non-master account. Suspension revokes dashboard sessions and rejects signed website delivery.

## Minimized telemetry

The bounded operational timeline records status and hashed recipients. OTP bodies are never retained. Optional inbound or consented notification content is encrypted and disabled unless explicitly configured. Public health output contains no number, QR, tenant secret, message, or account identity.

## Transport caveat

The shared-hosting profile uses an exactly pinned, compatibility-tested Baileys release. WhatsApp may disconnect an unofficial linked-device session. Key.kiwe therefore exposes connection health, guarded reconnection, and deterministic email-fallback signalling rather than claiming guaranteed delivery.
