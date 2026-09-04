# Product and account model

## Registration

The owner enters an international WhatsApp number and scans the displayed QR from **WhatsApp → Linked devices → Link a device**. Key.kiwe completes registration only when the scanned number matches the entered number. No email address, password, or payment card is required.

The first successful connection becomes the account’s primary WhatsApp connection. New websites inherit it automatically.

## Login

The owner enters the registered number. Key.kiwe creates a six-digit, five-minute challenge and sends it from that account’s own WhatsApp connection to the registered number. Codes are one-time, attempt-limited, rate-limited, and stored only as hashes.

If the primary connection is offline, sign-in changes to the reconnection journey instead of ending at an error. The owner scans a fresh QR from the same registered number. A matching scan replaces the dead transport, signs the owner in, and preserves all websites and credentials.

## Account roles

- **Owner:** manages their primary number and websites.
- **Master:** has the same client-management dashboard plus Network Oversight across the service.

The master can see account numbers, each website, the number actually serving that website, connection mode, activity, and status. This visibility is deliberately restricted to abuse response and operational support.

## Sessions

Browser sessions use Secure, HttpOnly, SameSite=Lax cookies. State-changing dashboard operations require an independent CSRF token. Suspending an account invalidates its sessions and prevents its websites from sending.
