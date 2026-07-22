# Kiwe release proof 0.6.15

Date: 2026-07-22

## Scope

- Hardened Kiwe AI API key creation for hosts where transients do not survive the post/redirect/get flow.
- New API keys are still shown only once, but the one-time secret is now stored in both a short-lived transient and a short-lived option fallback.
- Both one-time stores are deleted immediately after the key is rendered.
- This preserves the security model: Kiwe stores only the long-lived key hash, and the plain key is not kept after the one-time view.
- `/wp-json/dsa/v1/ai/themes` now includes an explicit `active` boolean on each listed theme record so external AI/tooling does not need to infer active state from the separate top-level active object.
- Studio native draft context now progressively compacts Bricks intelligence before calling a provider, keeping the prompt closer to the configured native context budget.
- Native provider failures now include a sanitized error code/message for non-2xx or empty-output responses.
- Companion output review now catches protected AppShell geometry in importable theme CSS for both `#dsa-surface` and `[data-dsa-surface]` selector styles.

## Why

Live staging showed the API key table prefix after creation, but the full one-time secret did not render. A prefix-only value cannot authenticate, so external AI/browser-AI users could create a key and still fail every `/wp-json/dsa/v1/ai/*` test with `invalid_key`.

## Verification

- PHP syntax: `includes/Admin/Admin.php`
- AI API/admin contracts
- Package manifest verification
