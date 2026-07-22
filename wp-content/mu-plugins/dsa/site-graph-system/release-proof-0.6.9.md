# Kiwe 0.6.9 PhoneKey privileged reauth release proof

## Scope

This release closes the session-timeout ambiguity discovered after `0.6.8`:

- `Kiwe > Secure` Role-Based Auto Logout was already gated by `secure[auto_logout_enabled]`;
- the remaining unexpected admin sign-out path was PhoneKey's separate privileged reauth timer;
- PhoneKey now treats `0` as a real disabled value across defaults, saved settings, runtime enforcement, and session-status polling.

## Expected Hostinger verification

After uploading this MU-plugin folder:

1. `GET /wp-json/dsa/v1/ai/site-graph` reports `version: 0.6.9`.
2. `Kiwe > Secure` can keep Role-Based Auto Logout unchecked without Kiwe Secure initiating logout.
3. `Kiwe Auth` / PhoneKey privileged reauth minutes can be set to `0`, which disables Kiwe-initiated privileged-session logout.
4. The `/phonekey/v3/session/status` payload should report `timeout: 0` for a privileged admin when both role timeout and privileged reauth are disabled.
5. Normal WordPress authentication cookie expiry, host security policy, or manual logout remain outside this Kiwe-initiated timeout path.

## Regression guard

The runtime must not call `wp_logout()` from PhoneKey session timeout when the effective timeout is `0`.
