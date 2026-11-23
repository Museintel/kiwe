# Kiwe Start projects

This service opens the authenticated Start project after Kiwe Launch confirms a purchase. One purchased site entitlement opens one project. An agency can own multiple projects, including multiple site entitlements from one purchase. The domain and hosting choice are shared by the web form and MCP. The existing public Start authoring guide continues to serve its versioned instructions.

## Ownership

Kiwe Launch validates the payment and supplies the account and plan facts. Start owns the project until the website is installed or connected. Key owns WhatsApp identities and sender authority. The connected WordPress site owns Design Context, products, content and publication.

Start saves a requested domain and selects included Kiwe hosting or an existing WordPress site. An existing site uses native Kiwe OAuth consent. Included hosting uses the configured Hostinger order and a verified temporary WordPress address.

## Existing WordPress sites

Choose an existing site and save its exact HTTPS domain. Start opens native WordPress consent for the Kiwe MCP App's `mcp.site.connect` scope. For sites with a designated Kiwe owner, that owner approves. An administrator with `manage_options` can approve on a single-site installation before a Kiwe owner has been designated. An ordinary editor can still use permitted MCP reads, and the connection proof remains owner-restricted.

After consent, Start reads the site's identity and runtime versions, saves the connected site at the confirmed project revision, and revokes the temporary access token. Changing the domain or hosting choice clears the previous site proof. WordPress application passwords and long-lived site credentials are unnecessary for this step.

The first release targets single-site WordPress at the root of the saved HTTPS domain. The site needs signed Kiwe MCP App 1.2.0-rc.3 or a later release that advertises the connection scope. Start follows the native authorization metadata on the selected domain and pins outbound requests to verified public addresses.

## Connect Key

Set `KIWE_START_KEY_ORIGIN` to the Key HTTPS origin and `KIWE_START_KEY_TOKEN` to a dedicated random service credential. Configure the matching credential and Start origin on Key. Start only requests Key setup for a verified WordPress project.

1. Open **Connect Key** in the project or call `kiwe_start_connect_key`. Open the returned Key link.
2. In Key, sign in or pair a new account, then approve the website. An agency uses its Key account across its sites and can invite one client to pair a separate number.
3. Open **Set up WordPress** in Key. Enter the one-time code as the site owner in **Kiwe Auth** on that WordPress site. WordPress stores the encrypted credential and verifies the connection.
4. Select **Check Key connection** or call the MCP tool again. The project reports the next Design Context step after native website proof and sender readiness.

A standalone buyer has their own Start project and Design Context. Each agency has its own home website with separate child projects for its clients. Each project keeps its own Design Context. This candidate links a workspace’s Key account and the verified site. Agency home website designation and the Design Context form are later steps.

## Included Kiwe hosting

An eligible buyer can set up hosting from the project form or `kiwe_start_provision_site`. Both surface the same durable request. The Hostinger adapter reserves a free temporary address before it requests WordPress. Subsequent calls look up the same address and check the provider status. The project shows the temporary address after the provider reports completion and the WordPress REST API answers for that site. An interrupted request reconciles the original address. The project keeps its requested custom domain for a later domain connection step.

This adapter targets an existing Hostinger Web or Cloud order. Configure the four variables below together to enable the managed form and tool. Store the API credential in the deployment's native secret store. WordPress receives a generated initial administrator at the configured operator email. The hosting operator can access the installation through Hostinger and complete the site's separate ownership handover. The project stores the assigned address and setup state.

| Variable | Purpose |
| --- | --- |
| `KIWE_START_HOSTINGER_TOKEN` | Server-side Hostinger credential for the managed order |
| `KIWE_START_HOSTINGER_ORDER` | Order that includes capacity for new WordPress sites |
| `KIWE_START_HOSTINGER_ACCOUNT` | Hosting username that must match setup records |
| `KIWE_START_HOSTINGER_ADMIN_EMAIL` | Authorized initial WordPress operator mailbox |

On a request with a lost response, Start checks the stored address before resubmitting to the same destination. An assigned address stays bound to the project. A failed setup goes to operator review. The first release qualifies WordPress at a temporary address. Kiwe activation, custom DNS and the purchaser's ongoing native authorization follow their own workflows. [Hostinger website setup API](https://github.com/hostinger/api-php-sdk/blob/main/docs/Api/HostingWebsitesApi.md)

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

- `kiwe_start_connect_site` prepares the same native WordPress approval link for the saved existing site. After approval, the project reports its verified site and the next Key step.

- `kiwe_start_provision_site` sets up or checks included hosting when a Hostinger order is configured.
- `kiwe_start_connect_key` opens native Key approval or refreshes the verified site's Key status when Key is configured.

The service preserves the latest saved revision when a browser and an MCP client act at the same time. Cross-account project IDs confer no access.

## Runtime and verification

Run Node 22.13 or newer and install the locked dependencies with `npm ci`. Run `npm test` to verify the HTTP purchase callback, browser request flow, durable restart, managed and existing hosting choices, account isolation and real MCP tool calls. Set `KIWE_START_TEST_SOCKET=1` for isolated Unix-socket qualification on hosts that restrict TCP loopback.

The optional real-browser check runs the production service entry point with an isolated checkout and OAuth issuer. It opens a client project at 390 CSS pixels and an agency project at 1440 CSS pixels, saves each domain through the form and reads both back through MCP. Install the existing UI harness dependencies under `tools/ui-contract`, then run `npm run test:browser` in this service. Set `KIWE_START_TEST_CHROME` to use an installed Chrome executable. The harness resolves Playwright from the repository's UI test dependency by default.

The Batch 3 candidate passed all 11 Start tests and three Key integration tests in the isolated Plum Giraffe run, plus one connected rehearsal on Hostinger Node 22.23.5 and WordPress 7.1.5 with signed Core RC495. The rehearsal used candidate Kiwe Auth source, a test purchase and a simulated sender. A native site owner verified the connection over the Key HTTP routes, using a temporary WordPress options table. The encrypted credential and native settings readback passed. Start web and MCP both reached Design Context. The lab's existing option hash matched, and no recipient message was sent. The relay and temporary data were retired. The connected test uses the previously qualified website ownership contract. Live buyer, agency-home and Key enrolment remain pending.

The Batch 2 candidate passed ten tests on local Node 24 and Hostinger Node 22.23.5 and the phone and desktop Chrome checks. Plum Giraffe ran the signed MCP App 1.2.0-rc.3 on Core RC495. The owner and editor exercised real registration, code exchange, MCP proof and revocation over HTTPS. Isolated WP-CLI supplied the two native consent decisions. Start showed the verified owner's project and denied the editor. The owner-authorized hosting test created a single free temporary WordPress site through Hostinger MCP. A completed provider receipt and real WordPress HTTPS readback made the synthetic Start purchase ready in the web form and MCP. Automated retries and interrupted responses were tested with a simulated provider.

Kiwe Launch buyer identity, verified purchase delivery, the production managed hosting credential and public Start service routing require live integration. [Signed MCP candidate](https://github.com/Museintel/kiwe/actions/runs/45228013550)

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
