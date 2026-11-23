# Start owns purchased projects before website installation

23 November 2026. Status accepted for the Version 1.x candidate.

A verified site entitlement from Kiwe Launch creates one durable Start project. The stable entitlement identifier is the idempotency key and the trusted purchaser account owns the record. A purchase can yield multiple site entitlements for the same agency. The checkout process is responsible for payment validation and purchaser authentication.

The hosted Start service holds pre-install state separately from the static versioned authoring guide. Both the web form and MCP call the same project actions. Browser access uses a short-lived, one-time handoff from an authenticated checkout session. MCP uses an audience-bound OAuth access token from the configured issuer. The two channels identify the same purchaser account and share a revision check for writes.

The selected domain is a draft until a managed provider or the owner of an existing WordPress site verifies it. Host provision, native site ownership, Key sender setup and WordPress business content retain their respective authorities. This slice defines the next step from the entitlement and the buyer's hosting choice.

Use the [service integration and test guide](../../services/start-projects/README.md) for the purchase inputs, deployment configuration and executable checks. See the [Version 1 recovery release](https://github.com/Museintel/kiwe-ecosystem/releases/tag/kiwe-version-1) for the frozen rollback checkpoint.
