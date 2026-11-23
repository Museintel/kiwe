# Kiwe Start projects

This service opens the authenticated Start project after Kiwe Launch confirms a purchase. One purchased site entitlement opens one project. An agency can own multiple projects, including multiple site entitlements from one purchase. The domain and hosting choice are shared by the web form and MCP. The existing public Start authoring guide continues to serve its versioned instructions.

## Ownership

Kiwe Launch validates the payment and supplies the account and plan facts. Start owns the project until the website is installed or connected. Key owns WhatsApp identities and sender authority. The connected WordPress site owns Design Context, products, content and publication.

Start saves a requested domain and selects included Kiwe hosting or an existing WordPress site. An existing site uses native Kiwe OAuth consent. Included hosting uses the configured Hostinger order and a verified temporary WordPress address.

## Agency workspaces

Every standalone purchaser opens their own projects under Kiwe Launch. An agency purchaser opens a private agency workspace with its own site projects. The same purchaser owns the workspace in the Start form and MCP. An agency name appears over its site list after the agency home is verified.

An agency owner enters their business name and agency website domain in **Agency workspace settings** or through MCP. An eligible WordPress site already verified in that agency workspace can serve as its home. For any other home, Start returns a 24-hour text proof at the exact site's `/.well-known/kiwe-agency.txt`. The owner publishes it and selects **Verify website**. Start checks the specific HTTPS origin. Changing an agency home keeps the previous verified home active until the new proof succeeds. The proof does not purchase a domain, install hosting, or move client projects.

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

## Design Context

From the site's project, open **Design Context** and authorize the introductory fields in WordPress. The dedicated `mcp.start.context` scope covers business name, tagline, industry, website type, description, audience, site goal, differentiator, and public phone and email. Existing website proof grants retain their limited read permission. The introductory forms use native field definitions and an exact current-value check, then save through WordPress. The full native journey remains available for brand assets, products, services, and the other site-specific settings.

In MCP, read the selected section and ask the authorized human for the missing facts. Prepare that exact answer, then open its Start review. The human can compare the current and proposed values and confirm for the named website. The same value is visible in Start, MCP and WordPress after it saves. The native writer refuses stale changes. Individual site scopes, temporary grants and per-site context stay separate even inside one agency. Native edits feed the existing Bricks dynamic bindings where templates use them.

The edit session lasts up to 15 minutes and can be closed from Start. Start keeps the native token and any pending review in process memory. Saved values belong to WordPress. Restarting Start retains the agency and project records and requires fresh WordPress consent for editing.

## Homepage draft

Open **Homepage draft** from the verified project. **Generate homepage** requests one homepage, with optional design direction. The WordPress owner authorizes `mcp.design.read` for the scoped `mcp.design.homepage.read` operation. Earlier frozen grants retain their existing pins. Start captures current public Design Context, verified Bricks bindings, and a bounded set of live catalogue records and selected media. The capture needs a saved business name and description. The temporary WordPress grant is revoked after the brief is read.

The design selects a layout, type treatment, accessible colors, a proven hero image, and Dock behavior. The renderer builds a single HTML, CSS and JavaScript homepage with the captured business facts. It emits native loops for products, categories and posts when the site proves those queries and fields. It uses safe static links when native support has yet to be proven. Empty catalogues leave those sections out. The preview uses an isolated iframe and a local script to prevent live link and launcher actions.

The owner can request another revision with instructions or refresh the native business facts. Start retains the previous design to guide a revision. Approval is a browser action tied to the current source fingerprint. Each approved revision has its own download. **Download the raw preview files** keeps the complete approved HTML, CSS and JavaScript. **Download for Seam** prepares a separate copy, omits the preview-only script and fingerprints its exact HTML for Seam. The approved-source receipt links the package to its preview.

The connected MCP client can read the request, choose a design and submit it for human review. For one-click generation in the browser, configure `KIWE_START_HOMEPAGE_GENERATOR_URL` and `KIWE_START_HOMEPAGE_GENERATOR_TOKEN` for an authorized HTTPS design generator. The service receives the public brief, human direction, previous design when one exists and the design output schema. It returns the small design object. The Start service holds the provider's credential. When no generator is configured, the saved request can be completed through MCP.

A runner can download an approved source byte stream over HTTP with an authorized Start access token for that purchaser. Use the revision-specific raw or Seam URL from the homepage tool. The response includes the downloadable source fingerprint and the approved raw source fingerprint.

## Pages and Templates

Open **Pages & Templates** from a verified project. Refresh the inventory through WordPress consent. The read uses `mcp.design.website.read` under `mcp.design.read` and revokes its temporary grant after the refresh. It reads published pages, header and footer templates and native page assignments for the connected site. A project with WooCommerce shows the Store checklist. Each row distinguishes its known native state from the design review.

Open **Home** to generate, preview or revise one homepage. When the owner has approved its exact source, **Convert to Bricks** is available on the dashboard and in the homepage review. Start runs the supported Seam command on a private copy of that source, uploads one isolated ZIP and opens the separate WordPress approval for the import. After the owner approves, **Check Bricks import** continues the same job until its private Bricks draft is ready. Start saves the archive fingerprint and native receipt. A repeat request checks the receipt before attempting another write.

To enable this conversion, set `KIWE_START_SEAM_ROOT` to the absolute path of a trusted, pinned Seam checkout with its locked dependencies installed. Set `KIWE_START_SEAM_WORK` to a private absolute working directory outside the served tree. The target needs Kiwe MCP with native Bricks import and package transfer. Keep a single Start process for active consent and imports. Import grants stay in process memory and last up to 15 minutes. If the grant ends after staging, open the saved Framework review on that site. Saved source and native draft receipts survive a Start restart.

The other checklist rows link to their WordPress owners. Independent design generation for those rows and publishing through Start are outside this candidate.

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
- `kiwe_start_agency_workspace`, `kiwe_start_set_agency_home` and `kiwe_start_verify_agency_home` manage the agency's own website.
- `kiwe_start_open_context` opens the site's narrow WordPress consent, and `kiwe_start_context` reads the authorized section.
- `kiwe_start_prepare_context_change` returns a link for the human to review and save the exact supplied value.
- `kiwe_start_open_homepage` starts the one-page brief through WordPress consent.
- `kiwe_start_homepage`, `kiwe_start_submit_homepage_design` and `kiwe_start_revise_homepage` read, prepare and revise that project's homepage. The owner approves its exact source in Start.

- `kiwe_start_website` reads the Pages and Templates workspace and current conversion.
- `kiwe_start_refresh_website` opens native consent to refresh the inventory.
- `kiwe_start_convert_homepage` opens the approved homepage import when Seam is configured. `kiwe_start_resume_bricks` checks the same native job after authorization and separate import approval.

The service preserves the latest saved revision when a browser and an MCP client act at the same time. Cross-account project IDs confer no access.

## Runtime and verification

Run Node 22.13 or newer and install the locked dependencies with `npm ci`. Run `npm test` to verify the HTTP purchase callback, browser request flow, durable restart, managed and existing hosting choices, account isolation and real MCP tool calls. Set `KIWE_START_TEST_SOCKET=1` for isolated Unix-socket qualification on hosts that restrict TCP loopback.

The optional real-browser check runs the production service entry point with an isolated checkout and OAuth issuer. It opens a client project at 390 CSS pixels and an agency project at 1440 CSS pixels, saves each domain through the form and reads both back through MCP. Install the existing UI harness dependencies under `tools/ui-contract`, then run `npm run test:browser` in this service. Set `KIWE_START_TEST_CHROME` to use an installed Chrome executable. The harness resolves Playwright from the repository's UI test dependency by default.

Batch 5 passed 17 Start tests locally and on Hostinger Node 22. The PHP design scope suite passed 54 checks. Plum Giraffe on WordPress 7.1.5 and signed Core RC495 returned the native homepage brief and kept older grants limited to their pinned operations. The full workflow used test purchases, public catalogue fixtures and a simulated configured generator. The owner-approved fixture compiled with Seam into one Bricks content draft with three native query loops and 11 dynamic bindings. The generated review package omitted the source preview runtime. Chrome exercised the sandbox and layout at 390, 768 and 1440 CSS pixels. Native staging, the imported Bricks visual comparison, and the production generator configuration are later work.

Batch 4 passed 15 Start tests locally and on Hostinger Node 22. Its 291 source checks cover native setup, authorization, guidance and change contracts. Plum Giraffe loaded the candidate context operations with the full MCP provider on WordPress 7.1.5 and signed Core RC495. With temporary native options, it admitted the scoped fields, saved a tagline through the native writer, read it through the Bricks binding and denied a stale repeat. The original options hash matched. Chrome checked that both agency verification and Design Context forms fit at 390 and 1440 CSS pixels. The hosted Start scenarios use test purchases and mediated WordPress responses. The new App scope needs a signed rollout before production use.

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
