# Start owns purchased projects before website installation

23 November 2026. Status accepted for the Version 1.x candidate.

A verified site entitlement from Kiwe Launch creates one durable Start project. The stable entitlement identifier is the idempotency key and the trusted purchaser account owns the record. A purchase can yield multiple site entitlements for the same agency. The checkout process is responsible for payment validation and purchaser authentication.

The hosted Start service holds pre-install state separately from the static versioned authoring guide. Both the web form and MCP call the same project actions. Browser access uses a short-lived, one-time handoff from an authenticated checkout session. MCP uses an audience-bound OAuth access token from the configured issuer. The two channels identify the same purchaser account and share a revision check for writes.

The selected domain is a draft until a managed provider or the owner of an existing WordPress site verifies it. Host provision, native site ownership, Key sender setup and WordPress business content retain their respective authorities. This slice defines the next step from the entitlement and the buyer's hosting choice.

Use the [service integration and test guide](../../services/start-projects/README.md) for the purchase inputs, deployment configuration and executable checks. See the [Version 1 recovery release](https://github.com/Museintel/kiwe-ecosystem/releases/tag/kiwe-version-1) for the frozen rollback checkpoint.

## Existing WordPress site verification

The Kiwe MCP App provides a dedicated read-only site proof operation under `mcp.site.connect`. Its native permission callback checks the designated Kiwe owner on each read. A single-site administrator can authorize where the native owner policy has no designated owner. Existing editorial and authoring permissions retain their established scopes.

Start binds a native OAuth code exchange to the project revision, account, exact site issuer and a one-time callback state. The website's native grant supplies the proof. Start stores only the verified site and its runtime versions. A server request follows the chosen HTTPS origin and pins the validated public DNS addresses. A changed domain retires pending proof and clears the previous link.

## Managed hosting

An eligible purchaser can request hosted WordPress through the Start form or MCP. Start records the reserved Hostinger temporary address before the setup write. Every follow-up uses that exact address. The selected custom domain remains on the project. Readiness requires both provider completion and a WordPress response for the assigned site. The hosted and existing paths continue toward the site's own authority and Key.
