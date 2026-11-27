# Kiwe Start projects

This service opens authenticated site projects after Kiwe Launch confirms an entitlement. The browser and MCP share the same project records, domain and hosting choices. The public Start authoring guide serves its versioned instructions alongside the project service.

## Ownership

Kiwe Launch validates the payment and supplies the account and plan facts. Start owns the project until the website is installed or connected. Key owns WhatsApp identities and sender authority. The connected WordPress site owns Design Context, products, content and publication.

New Client orders carry the confirmed domain and included hosting into Start. Existing site projects use native Kiwe OAuth consent. Included hosting uses the configured Hostinger order and a verified temporary WordPress address.

## Kiwe Client plan

The Client plan is `kiwe-client-managed-v1`. The direct price is ₹8,400 for the first 12 months, then ₹700 monthly. A verified referral sets the price to ₹7,200 for the first 12 months, then ₹600 monthly. The catalogue treats prices as tax-inclusive. Included hosting supports registering one eligible standard `.in` domain or bringing a domain the buyer owns. Launch verifies the referral, price and domain status at checkout.

`GET /projects/api/client-plan` returns the canonical catalogue. Authenticated clients can read the same catalogue through `kiwe_start_client_plan`. Amounts are in paise. The partner ledger and provider integrations are subsequent launch batches. [Launch decision](../../docs/decisions/2026-11-25-client-and-partner-launch.md)

`GET /projects/api/launch-catalogue` returns the five versioned offers. Through MCP, call `kiwe_start_client_plan` with `catalogue: true` for the same projection. Start accepts verified website purchases by plan ID. Portfolio preselects the services configuration. The Freelancer Pass belongs to the buyer account and does not create a Start project. [Catalogue decision](../../docs/decisions/2026-11-27-launch-tier-catalogue.md)

## Agency workspaces

Every Client buyer opens their own project under Kiwe Launch. The closed pilot and previously linked workspaces retain project access through the Start form and MCP. An agency name appears over the existing workspace's site list after the agency home is verified.

An agency owner enters their business name and agency website domain in **Agency workspace settings** or through MCP. An eligible WordPress site already verified in that agency workspace can serve as its home. For any other home, Start returns a 24-hour text proof at the exact site's `/.well-known/kiwe-agency.txt`. The owner publishes it and selects **Verify website**. Start checks the specific HTTPS origin. Changing an agency home keeps the previous verified home active until the new proof succeeds. The proof does not purchase a domain, install hosting, or move client projects.

## Independent client access in existing workspaces

The browser **Add site** form and `kiwe_start_add_agency_site` set up an existing WordPress domain in the current agency workspace. Adding a domain reuses the initial empty unhosted slot if one exists. A repeated request for the same domain returns the existing project. Start checks the three-site limit in the same transaction for new slots and trusted entitlements. Slots count the agency's own projects, including reserved domains and any legacy agency projects. A verified agency home by itself uses no slot.

An agency owner opens **Client access** for a verified site or calls `kiwe_start_invite_client`. Start issues a code valid for seven days, bound to the selected email and website revision. `kiwe_start_revoke_client` and the web action revoke Start access and unused tickets for that site. A changed website requires fresh approval.

On the configured Start login page, a first-time invitee enters the private code and signs in with the verified email chosen by the agency. Start accepts the grant once. Returning clients sign in with the same account. An existing trusted Launch broker can instead send `accountId`, `verifiedEmail` and `inviteCode` to `POST /projects/api/client-handoffs` or reopen an active grant with `accountId` and optionally `projectId`.

The client dashboard and MCP use the assigned site membership. A verified project links directly to its WordPress Design Context. Clients sign in to WordPress with their own site account and edit using the permissions of their native role. Billing, hosting and Key stay with the agency. WordPress single sign-on requires separate integration.

The existing [Start login](https://start.kiwelaunch.com/login/) accepts the enrolled Key owner in the closed pilot. The Client checkout candidate can also open a purchased project from the buyer's verified Launch account when the Start purchase connection is configured. [Pilot hosting and rollout record](../../docs/KIWE-LAUNCH-HOSTING-2026-11-24.md)

## Existing WordPress sites

Choose an existing site and save its exact HTTPS domain. Start opens native WordPress consent for the Kiwe MCP App's `mcp.site.connect` scope. For sites with a designated Kiwe owner, that owner approves. An administrator with `manage_options` can approve on a single-site installation before a Kiwe owner has been designated. An ordinary editor can still use permitted MCP reads, and the connection proof remains owner-restricted.

After consent, Start reads the site's identity and runtime versions, saves the connected site at the confirmed project revision, and revokes the temporary access token. Changing the domain or hosting choice clears the previous site proof. WordPress application passwords and long-lived site credentials are unnecessary for this step.

The first release targets single-site WordPress at the root of the saved HTTPS domain. The site needs signed Kiwe MCP App 1.2.0-rc.3 or a later release that advertises the connection scope. Start follows the native authorization metadata on the selected domain and pins outbound requests to verified public addresses.

When the Shared or Cloud adapter marks the managed stack ready, Start can run the same owner-consent flow on the provider-assigned temporary address. The custom domain stays a requested domain until its own verified hostname, TLS and migration. The registrar purchase and domain attachment integrations remain pending.

## Managed WordPress package

The buyer chooses **Services or portfolio**, **Online store**, **Paid memberships**, or **Online store and memberships** in the project before setting up hosting. The dashboard and `kiwe_start_set_site_setup` use the same owner and project revision checks. Provisioning saves a durable request and locks the selected setup. Existing connected sites use their native installation.

The managed Start route and `kiwe_start_provision_site` use the same preparation step after the exact Hostinger WordPress site responds. The worker checks the on-disk site root and native WordPress origin. A services or portfolio install omits WooCommerce and its add-ons. A store or membership install verifies and activates WooCommerce, installs the signed Kiwe Store App, and prepares verified Razorpay for WooCommerce and Shiprocket as inactive plug-ins. The store owner connects their merchant account and shipping channel. The membership choices enable recurring product authoring. [Razorpay setup](https://razorpay.com/app-store/woocommerce/) · [Shiprocket setup](https://www.shiprocket.in/woocommerce-shipping/) · [WP-CLI checksum verification](https://developer.wordpress.org/cli/commands/plugin/verify-checksums/)

For managed Client purchases, set `KIWE_START_KIWE_RELEASE_DIRECTORY` to the private directory holding the approved Core `manifest.json` and `kiwe-mu-plugin.zip`, and MCP App `release.json` and `kiwe-mcp.zip`. Include the matching signed Store App at `store/release.json` and `store/kiwe-store.zip` for commerce setups. Use the linked Core candidate or a later qualified release with managed setup support. Start uses the fleet verifier for the Core signature and archive hash, checks the Core inventory in private staging, and places the matched MU loader last. The Apps use Kiwe’s native signed installer verification and initial owner setup. A matching installation can resume after interruption. A different signed package or an existing Store stack on a proposed portfolio site needs a reviewed deployment.

Set `KIWE_START_BRICKS_ARCHIVE`, `KIWE_START_BRICKS_SHA256` and `KIWE_START_BRICKS_VERSION` for the latest qualified stable Bricks ZIP. The worker checks the archive hash, installs the exact version and verifies the site's native license. The isolated Hostinger fixture used the official Bricks 2.4.6 ZIP and its existing authorised staging license. Its signed Core RC498 and MCP 1.2.0-rc.4 booted and passed a second install. Production requires the license in the site's protected WordPress configuration. [Bricks stable release](https://bricksbuilder.io/release/bricks-2-4-6/) · [Native license configuration](https://academy.bricksbuilder.io/builder/license/license-and-updates/)

Set `KIWE_START_KEY_MANAGED_TOKEN` to the dedicated managed setup credential configured on Key. The first setup binds the paid project’s reserved origin to Key’s configured primary account. WordPress redeems the short-lived setup code through its native Kiwe Auth handler and proves its own encrypted site credential. The existing Key sender serves the site until the selected client number completes verification. The initial live Key configuration admits Evergreen Basin’s exact origin. Temporary addresses require the qualified hosting worker and Key’s temporary-origin setting.

The client can choose **Use your own WhatsApp number** in the connected project or call `kiwe_start_pair_whatsapp` with the project revision, phone and explicit selection. Key pairs that verified number for this site’s WhatsApp login, campaigns and automated messages. The link only starts the pairing step. The same site credential remains bound to the original site.

## Connect Key

Set `KIWE_START_KEY_ORIGIN` to the Key HTTPS origin and `KIWE_START_KEY_TOKEN` to a dedicated random service credential. Configure the matching credential and Start origin on Key. For existing sites using native account approval, Start requests setup after verification.

1. Open **Connect Key** in the project or call `kiwe_start_connect_key`. Open the returned Key link.
2. In Key, sign in or pair a new account, then approve the website. An agency uses its Key account across its sites and can invite one client to pair a separate number.
3. Open **Set up WordPress** in Key. Enter the one-time code as the site owner in **Kiwe Auth** on that WordPress site. WordPress stores the encrypted credential and verifies the connection.
4. Select **Check Key connection** or call the MCP tool again. The project reports the next Design Context step after native website proof and sender readiness.

## Design Context

From a verified project, select **Open Design Context in WordPress**. Use the existing form to enter or update business facts, brand assets, products and services. An old Start Design Context bookmark takes the signed-in user to the same WordPress screen.

MCP's `kiwe_start_project` returns the same URL in `project.site.designContextUrl`. Start requests a fresh, read-only public brief from WordPress for each new design. Native edits feed existing Bricks dynamic bindings wherever a template uses them.

## Homepage draft

Open **Homepage draft** from the verified project. **Generate homepage** requests one homepage, with optional design direction, and presents the link to WordPress authorization. The WordPress owner authorizes `mcp.design.read` for the scoped `mcp.design.homepage.read` operation. Earlier frozen grants retain their existing pins. Start captures current public Design Context, verified Bricks bindings, and a bounded set of live catalogue records and selected media. The capture needs a saved business name and description. The temporary WordPress grant is revoked after the brief is read.

The design selects a layout, type treatment, accessible colors, a proven hero image, and Dock behavior. The renderer builds a single HTML, CSS and JavaScript homepage with the captured business facts. It emits native loops for products, categories and posts when the site proves those queries and fields. It uses safe static links when native support has yet to be proven. Empty catalogues leave those sections out. The preview uses an isolated iframe and a local script to prevent live link and launcher actions.

The owner can request another revision with instructions or refresh the native business facts. Start retains the previous design to guide a revision. Approval is a browser action tied to the current source fingerprint. Each approved revision has its own download. **Download the raw preview files** keeps the complete approved HTML, CSS and JavaScript. **Download for Seam** prepares a separate copy, omits the preview-only script and fingerprints its exact HTML for Seam. The approved-source receipt links the package to its preview.

The connected MCP client can read the request, choose a design and submit it for human review. For one-click generation in the browser, configure `KIWE_START_HOMEPAGE_GENERATOR_URL` and `KIWE_START_HOMEPAGE_GENERATOR_TOKEN` for an authorized HTTPS design generator. The service receives the public brief, human direction, previous design when one exists and the design output schema. It returns the small design object. The Start service holds the provider's credential. When no generator is configured, the saved request can be completed through MCP.

A runner can download an approved source byte stream over HTTP with an authorized Start access token for that purchaser. Use the revision-specific raw or Seam URL from the homepage tool. The response includes the downloadable source fingerprint and the approved raw source fingerprint.

## Pages and Templates

Open **Pages & Templates** from a verified project. Refresh the inventory through WordPress consent. The read uses `mcp.design.website.read` under `mcp.design.read` and revokes its temporary grant after the refresh. It reads published pages, header and footer templates and native page assignments for the connected site. A project with WooCommerce shows the Store checklist. Each row distinguishes its known native state from the design review.

Open **Home** to generate, preview or revise one homepage. After approving its exact source, choose **Convert to Bricks** and follow **Open WordPress authorization**. Start runs the supported Seam command on a private copy of that source and uploads one isolated ZIP. Choose **Approve draft import in WordPress** to confirm the import. After approval, **Check Bricks import** continues the same job until its private Bricks draft is ready. Start saves the archive fingerprint and native receipt. A repeat request checks the receipt before attempting another write.

To enable this conversion, set `KIWE_START_SEAM_ROOT` to the absolute path of a trusted, pinned Seam checkout with its locked dependencies installed. Set `KIWE_START_SEAM_WORK` to a private absolute working directory outside the served tree. The target needs Kiwe MCP with native Bricks import and package transfer. Keep a single Start process for active consent and imports. Import grants stay in process memory and last up to 15 minutes. If the grant ends after staging, open the saved Framework review on that site. Saved source and native draft receipts survive a Start restart.

### Design and publish one page or template

Approve a homepage direction, then refresh **Pages & Templates**. Choose a specific row and select **Generate design**. Start opens native consent for the row’s public Design Context and catalogue brief. The approved homepage supplies the palette and typography. Each row has its own source history, preview, approval and Bricks receipt.

**Request another revision** gives the generator the previous design and the owner’s instructions. **Regenerate design** starts a fresh layout from the approved homepage direction. The design uses native facts and supported elements with a typed layout, heading and optional sections. The browser uses the configured design generator. An MCP designer can fill the same request. Preview interactions stay inside the review, including when the native page uses a real WooCommerce form.

Approve the preview, choose **Convert to Bricks**, and confirm the private draft in WordPress. Start compiles each document in an isolated project namespace. Pin the [paired Seam candidate with native Search typography support](https://github.com/Museintel/seam-compiler/commit/d59ce2b2fa6d939661da10042b2b470ed0318927). Choose **Publish design** to review the exact route and assignment in WordPress, then **Check publication**. **Restore previous design** opens a separate review. Successful placement saves encrypted native recovery and frees its temporary import slot while keeping the private Bricks draft and required styles.

Header, footer, single post, archives, search, 404 and product layouts use native Bricks assignments. Blog uses the WordPress posts route. About and Contact can create their own pages. Existing pages retain their author-owned content. Privacy and Terms use the site’s existing published policy text. If a shared template condition or route has changed, WordPress requests a new review. Narrower existing conditions still take precedence according to Bricks.

On Store projects, Shop and category listings use the native results query. The Cart, Checkout, Order payment, Thank you and Account designs place a Bricks presentation section above the existing WooCommerce view. WooCommerce handles forms, checkout, payment, login and order data. Create and assign the native commerce pages in WooCommerce before placing those designs.

### Publish the homepage

Review the private Bricks draft and choose **Publish homepage**. Follow **Open WordPress authorization** to authorize the publication review, then approve the exact change in WordPress. Return to Start and choose **Check publication**. Use the Seam candidate with the responsive preview corrections when compiling the source. [Paired Seam commit](https://github.com/Museintel/seam-compiler/commit/ab3f1bd93ef5d8ddd90be15c6df806afba2c9a13)

When the site still shows its posts at the root, WordPress creates the Home page and assigns it in Reading settings. The review shows the Blog destination for the original posts index. It reuses an eligible assigned posts page or allocates an unused Blog address. If needed, the same review enables Bricks editing for Pages. The public root changes after both assigned pages are ready. The saved setup can resume without creating another set.

On a site with an assigned static homepage, WordPress updates its native page layout on the existing address. The page must use its own Bricks content and be separate from protected WordPress and WooCommerce routes. Both paths refuse stale approvals.

**Restore previous homepage** has a separate WordPress approval. A first-time restore returns the posts index to its original route and keeps generated pages as drafts. A layout restore uses WordPress's encrypted recovery. Start records the native result and its page and approval references. When a session lapses, reopen consent to read current native status before a new review.

## Included Kiwe hosting

An eligible buyer can set up hosting from the project form or `kiwe_start_provision_site`. Both use the same durable request. An owned domain starts at a free temporary address. For a new included `.in`, Start waits for the trusted Launch registration receipt, then asks Hostinger to create WordPress on the exact domain. A pre-existing site or setup requires hosting review. The assigned URL appears when Hostinger reports completion and the WordPress REST API answers from that HTTPS origin. An interrupted request reconciles the saved address.

This adapter targets an existing Hostinger Web or Cloud order. Configure the four variables below together to enable the managed form and tool. Store the API credential in the deployment's native secret store. WordPress receives a generated initial administrator at the configured operator email. The hosting operator can access the installation through Hostinger and complete the site's separate ownership handover. The project stores the assigned address and setup state.

| Variable | Purpose |
| --- | --- |
| `KIWE_START_HOSTINGER_TOKEN` | Server-side Hostinger credential for the managed order |
| `KIWE_START_HOSTINGER_ORDER` | Order that includes capacity for new WordPress sites |
| `KIWE_START_HOSTINGER_ACCOUNT` | Hosting username that must match setup records |
| `KIWE_START_HOSTINGER_ADMIN_EMAIL` | Authorized initial WordPress operator mailbox |

On a request with a lost response, Start checks the stored address before resubmitting to the same destination. An assigned address stays bound to the project. A failed setup goes to operator review. Projects with an assigned temporary address from an earlier build keep that address. The purchaser authorizes the native WordPress connection on the assigned origin. [Hostinger website setup API](https://github.com/hostinger/api-php-sdk/blob/main/docs/Api/HostingWebsitesApi.md)

### Domain setup

Launch uses its purchase credential at `POST /projects/api/domain-registration` after confirming the active registration in Hostinger. The request has `entitlementId`, `purchaseId`, `accountId` and `domain`. All four values must match the original confirmed order. Start locks each registered domain to one project. The buyer sees the registration status without the registrant or billing details. [Private registration operations](../launch-checkout/README.md#included-domain-registration)

For an owned domain, the buyer can create and verify a project-specific TXT record from Start or use `kiwe_start_domain_dns`. Repeated preparation reuses the record for 48 hours. On expiry, Start creates a fresh value. Verification records DNS control while the website keeps its temporary URL. Preserve existing email, verification and website records during this step.

Shared and Cloud hosting require a reviewed WordPress move to change the primary domain. The operator needs a recoverable copy, the exact provider web records and final HTTPS and WordPress verification. Verify the intended DNS records at cutover and preserve mail records. Start and Key must be authorized for the final origin before retiring the temporary address. The real provider cutover and registrar renewal remain to be qualified. [Hostinger's primary-domain change instructions](https://www.hostinger.com/support/6807580-connecting-a-different-domain-to-your-existing-website-at-hostinger/)

The private `test/hosted-domain-recovery.php` driver uses the existing Kiwe portable recovery factory. It reads the Plum Giraffe WordPress core files, the qualified Bricks archive and the signed Core package. It creates synthetic source and destination installations with separate tables and a private encrypted backup. The driver verifies activation, reversal of the prepared destination, and native content and credential checks on the recovered installation. Use a mode-0700 `.kiwe-owned-domain-qa.*` directory outside the docroot. Supply its private native database connection, the verified release receipt, the extracted Recovery classes and their Search Defaults dependency, and the Bricks theme under `theme/bricks`. The captured source root and the destination use reserved `.invalid` origins.

The hosted run passed 18 capture and 45 restore checks on PHP 8.2.36 with signed Core RC498, WordPress 7.1.5 and Bricks 2.4.6. The full file set had 5,245 members. The WordPress administrator authenticated at the destination and Bricks rendered the restored page. Media and serialized image URLs resolved to the destination origin in native WordPress. The first destination assembly exceeded the account's quota with an extra seed copy present. The successful run reused read-only WordPress core files and retired the isolated roots and tables.

Run `node --test test/hosted-domain-origins.mjs` with `KIWE_KEY_FIXTURE_SOURCE` set to the Key checkout with managed domain activation support. For Hostinger's private lab, set `KIWE_START_TEST_SOCKET=1` and `KIWE_KEY_TEST_SOCKET=1`. The test uses simulated DNS, WordPress authorization and sender transport. It covers the exact paid-project binding, WordPress owner proof, renewal of the same operation after a lost Key reply, and preservation of the original delivery date. Direct `.in` provisioning needs admission of its exact final origin under Key's managed policy.

### Reviewed domain move

The Launch operator checks the restored copy and independently verified recovery archive, then records the review with private MCP or CLI. Launch calls `POST /projects/api/domain-moves` using its purchase service credential. The exact captured payment, entitlement and buyer identify the existing project. Start matches the approved owned domain and connected temporary WordPress address and stores the recovery digest privately. Repeating the same review returns the saved state.

The buyer selects **Verify WordPress at your domain** or calls `kiwe_start_verify_domain_wordpress` to approve the exact origin in WordPress. **Verify WhatsApp connection** or `kiwe_start_verify_domain_key` then prepares the same project's Key proof. Start sends the private setup code to the restored installation through the existing managed command. WordPress seals the destination credential and signs the proof. The temporary address continues serving until the operator starts activation.

The operator pauses writes at the temporary site, verifies the final synced WordPress copy and checks that public DNS and TLS point to the intended hosting. Use the private Launch activation with the reviewed archive digest and the verified final-copy digest. Start checks the public HTTPS WordPress home before recording activation. Key switches the same tenant to the verified credential. Start then commits the final site and hosting address together and advances the project revision. The original delivery date remains the basis for partner accounting.

The project reports `activating` while Start waits for the Key receipt. Keep the temporary site read-only and replay the exact request if the response is lost. Start can finish with the same Key receipt after a restart. Renew the destination Key proof if Key has yet to accept activation and the proof expires. Confirm the final site and sender before retiring the temporary resource. See the [private Launch activation command](../launch-checkout/README.md#owned-domain-moves).

## Checkout handoff

1. After verifying the payment, account, any referral and the domain selection, the trusted Launch backend sends JSON to `POST /projects/api/purchases` with the separate purchase service credential. Supply `entitlementId`, `purchaseId`, `accountId`, `accountType: "client"`, `planId: "kiwe-client-managed-v1"`, `hostingIncluded: true` and a `clientOrder`. Use the payment provider's stable payment identifier as `purchaseId`.
2. After authenticating the purchasing account on Kiwe Launch, send its `accountId` and `entitlementId` to `POST /projects/api/handoffs` from that backend. The response contains a one-time ticket, its expiration and the Start form action.
3. Render a top-level browser form on the configured Kiwe Launch origin. POST the ticket in the form body to the returned action as `application/x-www-form-urlencoded`. The Start service verifies origin, consumes the ticket and redirects the buyer into the saved project with a secure session cookie. A new ticket for the same account reopens the same project after sign-out.

For a direct order selecting a new domain, the `clientOrder` object has the following shape.

```json
{
  "currency": "INR",
  "paidMinor": 840000,
  "renewalMinor": 70000,
  "referralId": null,
  "domain": { "mode": "register", "name": "example.in", "category": "standard" }
}
```

For an owned domain, use `{ "mode": "owned", "name": "example.com" }`. For a referred order, send a verified referral ID with amounts `720000` and `60000`. A repeat delivery with the same entitlement and identical details returns the original project. Changed facts or reuse of the payment or referral return HTTP 409. Pre-existing entitlement replays have the same ownership checks as before.

Keep the purchase credential inside the checkout server and keep handoff tickets out of URLs and logs. An entitlement reference or redirect alone grants no access. The checkout backend must authenticate the buyer before issuing each handoff; payment webhook deliveries call only the entitlement endpoint.

Launch uses the same purchase credential for `POST /projects/api/purchase-delivery` with the exact `entitlementId` and verified `accountId` of a referred Client purchase. The endpoint returns `projectId` and `readyAt`. The latter is the Unix timestamp of the verified Kiwe site connection after the included managed WordPress site is ready. An unfinished project returns a null date. Launch uses this limited record to qualify commission periods. Partners use the separate Launch dashboard.

## Browser login

For the closed pilot, configure `KIWE_START_KEY_PILOT_ACCOUNT` with the `key:` subject of the verified Key owner and use the dedicated Start service credential already configured for Key. Key requires its matching `KIWE_KEY_START_PILOT_OWNER_ID` and native owner approval. The Key sign-in accepts only that subject and opens the existing pilot workspace.

To test Client purchases alongside that workspace, set a separate `KIWE_START_PURCHASE_TOKEN` on Start and the matching `startToken` on the Launch server. The purchase connection accepts verified `launch:` account IDs for the Client plan. Buyers use their Launch credentials and receive the same project when they select **Open Kiwe Start** again. Signed-in users in this mode can issue a 20-minute bearer from **MCP access**. The bearer obeys the same project permissions and expires on replacement or sign-out. Standard OAuth discovery and existing client invitations use the OIDC configuration.

Hostinger can serve the existing versioned guide beside the project service by setting `KIWE_START_GUIDE_ROOT` to the absolute directory containing the verified public guide snapshot. The Start service sets the security headers for each route. When replacing the static guide host, remove the legacy guide-only CSP, referrer and CORS directives from the Start document root's `.htaccess`. Keep the existing Passenger configuration and private environment settings. The guide handler applies its original security headers to the guide and registry.

Run the hosted header check from the Start service directory after each Key pilot deployment.

```sh
npm run test:hosted-login -- https://start.kiwelaunch.com https://key.kiwelaunch.com
```

In a signed-out browser, open `/login/`, select **Continue with Key** and confirm that Key opens. The HTTP check covers the hosting layer, and the browser check covers form submission and navigation.

For the shared Hostinger runtime, set `GOMAXPROCS=2` in Start's environment. The TypeScript compiler subprocess uses the same environment. Hostinger rejected the unrestricted Go worker count during the pilot, and the bounded run produced one Bricks review ZIP from the synthetic Start homepage.

The header on Kiwe Launch opens `GET /login/` on Start. Configure an OpenID Connect confidential client with the exact callback `https://start.kiwelaunch.com/projects/login/return`. Start uses authorization code, S256 PKCE, an expiring browser-bound transaction and a verified signed ID token. The issuer must provide the same immutable subject to Start's browser client, Launch's trusted purchase integration and the MCP resource. Configure those registrations to share stable subjects. Email is used to accept a matching client invitation after the issuer verifies it.

The login transaction rotates the Start session and consumes the prior browser transaction. Preserve the database across restarts and keep authorization codes, invitation codes, bearer credentials and cookies out of access and application logs.

## MCP

The protected resource is `/projects/mcp`, with resource metadata at `/.well-known/oauth-protected-resource/projects/mcp`. Use JWT access tokens from the configured OAuth issuer with that resource audience and the `start:projects` scope. The OAuth subject must be the same stable account identifier that the trusted checkout sends. This service relies on the configured issuer for interactive authorization.

- `kiwe_start_client_plan` reads the Client price, billing schedule, referral terms and included domain eligibility.
- `kiwe_start_projects` lists the buyer's projects.
- `kiwe_start_project` reads the specified project and reports its verified WordPress Design Context link in `project.site.designContextUrl`.
- `kiwe_start_set_domain` saves a human-confirmed domain and hosting choice at the current revision.
- `kiwe_start_domain_dns` prepares or confirms the TXT verification record for an owned domain on the signed-in buyer's paid project.
- `kiwe_start_verify_domain_key` verifies the WhatsApp connection on the already approved WordPress restoration and leaves the current project active.

- `kiwe_start_connect_site` prepares the same native WordPress approval link for the saved existing site. After approval, the project reports its verified site and the next Key step.

- `kiwe_start_provision_site` sets up or checks included hosting when a Hostinger order is configured.
- `kiwe_start_connect_key` opens native Key approval or refreshes the verified site's Key status when Key is configured.
- `kiwe_start_agency_workspace`, `kiwe_start_set_agency_home` and `kiwe_start_verify_agency_home` manage the agency's own website.
- `kiwe_start_add_agency_site` adds an existing WordPress project to a monthly Agency workspace. `kiwe_start_agency_workspace` also reports its used and allowed site slots.
- `kiwe_start_open_homepage` starts the one-page brief through WordPress consent.
- `kiwe_start_homepage`, `kiwe_start_submit_homepage_design` and `kiwe_start_revise_homepage` read, prepare and revise that project's homepage. The owner approves its exact source in Start.

- `kiwe_start_website` reads the Pages and Templates workspace and each row’s design and publication status.
- `kiwe_start_refresh_website` opens native consent to refresh the inventory.
- `kiwe_start_open_document`, `kiwe_start_document`, `kiwe_start_submit_document_design` and `kiwe_start_revise_document` work on one checklist `target` per request. The owner approves its exact source in Start.
- `kiwe_start_convert_document` starts the approved row’s Seam import. `kiwe_start_resume_bricks` resumes its saved job with the same `target`.
- `kiwe_start_prepare_document_publication` and `kiwe_start_complete_document_publication` use the separate `mcp.bricks.document` owner review to publish or restore one row.
- `kiwe_start_convert_homepage` opens the approved homepage import when Seam is configured. `kiwe_start_resume_bricks` checks the same native job after authorization and separate import approval.
- `kiwe_start_prepare_homepage_publication` opens the native publication or recovery approval. After the owner reviews it, `kiwe_start_complete_homepage_publication` checks the exact intent and applies the approved change.

The service preserves the latest saved revision when a browser and an MCP client act at the same time. Cross-account project IDs confer no access.

## Runtime and verification

Run Node 22.13 or newer and install the locked dependencies with `npm ci`. Run `npm test` to verify the HTTP purchase callback, browser request flow, durable restart, managed and existing hosting choices, account isolation and real MCP tool calls. Set `KIWE_START_TEST_SOCKET=1` for isolated Unix-socket qualification on hosts that restrict TCP loopback.

The optional real-browser check runs the production service entry point with an isolated checkout and OAuth issuer. It opens a direct Client purchase at 390 CSS pixels and a referred Client purchase at 1440 CSS pixels. It checks that each Start project uses the confirmed domain and reads it back through MCP. Install the existing UI harness dependencies under `tools/ui-contract`, then run `npm run test:browser` in this service. Set `KIWE_START_TEST_CHROME` to use an installed Chrome executable. The harness resolves Playwright from the repository's UI test dependency by default.

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
| `KIWE_START_LOGIN_CLIENT_ID` and `KIWE_START_LOGIN_CLIENT_SECRET` | Dedicated browser OIDC client and server-only credential using `client_secret_basic` |
| `KIWE_START_LOGIN_AUTHORIZATION_URL` and `KIWE_START_LOGIN_TOKEN_URL` | Trusted endpoints from the same issuer's OIDC registration |
| `KIWE_START_DATABASE` | Absolute private SQLite path in a persistent directory |
| `KIWE_START_BIND` and `PORT` | Process binding, defaulting to `127.0.0.1:3000` |

Route `/login/`, `/projects` and the MCP resource metadata path to the service. Serve the versioned Start guide through its existing static routes. Use a protected persistent directory for the database and a SQLite-consistent backup, including pending writes. The static public login status page is retained in `public/start-login` for deployment before the OIDC route is active.

[Start ownership decision](../../docs/decisions/2026-11-23-start-purchased-projects.md)
