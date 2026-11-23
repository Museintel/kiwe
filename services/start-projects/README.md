# Kiwe Start projects

This service opens the authenticated Start project after Kiwe Launch confirms a purchase. One purchased site entitlement opens one project. An agency can own multiple projects, including multiple site entitlements from one purchase. The domain and hosting choice are shared by the web form and MCP. The existing public Start authoring guide continues to serve its versioned instructions.

## Ownership

Kiwe Launch validates the payment and supplies the account and plan facts. Start owns the project until the website is installed or connected. Key owns WhatsApp identities and sender authority. The connected WordPress site owns Design Context, products, content and publication.

The current slice saves a requested domain and selects included Kiwe hosting or an existing WordPress site. Site provisioning and verification of existing site ownership require their provider integrations. A saved domain is a setup choice. The domain and hosting step response identifies the applicable integration.

## Checkout handoff

1. From the trusted checkout backend, send the confirmed site entitlement to `POST /projects/api/purchases` as JSON. Authenticate with the separate purchase service credential. Supply `entitlementId`, `purchaseId`, `accountId`, `accountType` (client or agency), `planId` and `hostingIncluded` (boolean). Reusing the entitlement with identical facts returns the original project. Conflicting facts return HTTP 409.
2. After authenticating the purchasing account on Kiwe Launch, send its `accountId` and `entitlementId` to `POST /projects/api/handoffs` from that backend. The response contains a one-time ticket, its expiration and the Start form action.
3. Render a top-level browser form on the configured Kiwe Launch origin. POST the ticket in the form body to the returned action as `application/x-www-form-urlencoded`. The Start service verifies origin, consumes the ticket and redirects the buyer into the saved project with a secure session cookie. A new ticket for the same account reopens the same project after sign-out.

Keep the purchase credential inside the checkout server and keep handoff tickets out of URLs and logs. An entitlement reference or redirect alone grants no access. The checkout backend must authenticate the buyer before issuing each handoff; payment webhook deliveries call only the entitlement endpoint.

## MCP

The protected resource is `/projects/mcp`, with resource metadata at `/.well-known/oauth-protected-resource/projects/mcp`. Use JWT access tokens from the configured OAuth issuer with that resource audience and the `start:projects` scope. The OAuth subject must be the same stable account identifier that the trusted checkout sends. This service relies on the configured issuer for interactive authorization.

- `kiwe_start_projects` lists the buyer's projects.
- `kiwe_start_project` reads the specified project.
- `kiwe_start_set_domain` saves a human-confirmed domain and hosting choice at the current revision.

The service preserves the latest saved revision when a browser and an MCP client act at the same time. Cross-account project IDs confer no access.

## Runtime and verification

Run Node 22.13 or newer and install the locked dependencies with `npm ci`. Run `npm test` to verify the HTTP purchase callback, browser request flow, durable restart, managed and existing hosting choices, account isolation and real MCP tool calls. Set `KIWE_START_TEST_SOCKET=1` for isolated Unix-socket qualification on hosts that restrict TCP loopback.

The optional real-browser check runs the production service entry point with an isolated checkout and OAuth issuer. It opens a client project at 390 CSS pixels and an agency project at 1440 CSS pixels, saves each domain through the form and reads both back through MCP. Install the existing UI harness dependencies under `tools/ui-contract`, then run `npm run test:browser` in this service. Set `KIWE_START_TEST_CHROME` to use an installed Chrome executable. The harness resolves Playwright from the repository's UI test dependency by default.

The 23 November 2026 candidate passed four end-to-end tests on local Node 24 and on Hostinger Node 22.23.5 over a private Unix socket, plus both real-browser traces and remote-JWKS readback. The public Start command registry also passed its drift check. Production-facing checkout, buyer authentication and hosting activation require their real service integrations.

The runnable service uses `npm start` and the following environment variables.

| Variable | Purpose |
| --- | --- |
| `KIWE_START_ORIGIN` | HTTPS origin that serves the Start project routes |
| `KIWE_START_CHECKOUT_ORIGIN` | Approved browser origin for the checkout return form |
| `KIWE_START_PURCHASE_TOKEN` | Random server-only secret of 32 bytes or more, encoded as base64url |
| `KIWE_START_OAUTH_ISSUER` | Exact OAuth issuer identifier |
| `KIWE_START_OAUTH_JWKS` | Configured issuer's verification key URL |
| `KIWE_START_DATABASE` | Absolute private SQLite path in a persistent directory |
| `KIWE_START_BIND` and `PORT` | Process binding, defaulting to `127.0.0.1:3000` |

Route `/projects` and the MCP resource metadata path to the service. Serve the versioned Start guide through its existing static routes. Use a protected persistent directory for the database and a SQLite-consistent backup, including pending writes. The standalone candidate and test checkout are suitable for isolated qualification. The production checkout and OAuth provider wiring require verified issuer configuration.

[Start ownership decision](../../docs/decisions/2026-11-23-start-purchased-projects.md)
