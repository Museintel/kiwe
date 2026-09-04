# WordPress and Kiwe integration

## Configuration

In WordPress, open **Kiwe → Key → WhatsApp provider** and enter:

- Gateway URL: `https://key.kiwelaunch.com/v1/otp`
- Tenant key ID: generated when the website is added
- Signing secret: displayed once when the website is added

The WordPress secret belongs in Kiwe Secret Store. It must not be written to theme code, JavaScript, source control, logs, or screenshots.

## Existing Kiwe installations

Kiwe 8.0.0-rc.45 and later migrate the exact former hosted gateway URL to Key.kiwe automatically. Tenant IDs, signing secrets, WordPress users, verification factors, and trusted-device state remain intact. Invisible legacy setting and namespace aliases remain temporarily so existing sites do not lose identity continuity during rollout.

## Delivery behavior

Kiwe signs the exact JSON request body with the website secret and sends the key ID, timestamp, nonce, and signature in `X-Kiwe-Key-*` headers. Key.kiwe validates the approved HTTPS origin and rejects stale, replayed, malformed, inactive, suspended, or incorrectly signed requests.

When WhatsApp cannot accept an OTP, Key.kiwe returns an explicit bounded fallback result. WordPress can then deliver the same challenge through its configured email lane without exposing the code to the gateway response.
