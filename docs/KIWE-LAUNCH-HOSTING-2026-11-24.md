# Kiwe Launch hosting and monthly plan model

24 November 2026

Hostinger India Agency Startup supports isolated WordPress sites, per-site access, and unbranded client dashboards. Its public quote showed ₹59,976 upfront for the 24-month introductory term and an equivalent renewal rate of ₹3,499 per month before applicable taxes. Hostinger also documents one-month Agency renewals. Obtain the exact short-term checkout rate and terms for Kiwe before using it for customer pricing. Kiwe's connected account returned zero Agency Hosting orders. [India Agency Hosting](https://www.hostinger.com/in/pro) · [Renewal terms](https://www.hostinger.com/support/1583589-how-to-pay-for-hostinger-services-in-advance/)

The Partner Program requires at least ten sites on Hostinger and a business email, and reviews applications. Partner approval and Agency Hosting capacity have their own processes. Kiwe supplies client billing and support when reselling access under the Agency agreement. Budget with partner commission at zero until approved terms establish it. [Partner Program](https://www.hostinger.com/support/hostinger-partner-program/) · [India Hosting Agreement](https://www.hostinger.com/in/legal/hosting-agreement)

A 10% gross margin means dividing the monthly cost per active billed site by 0.90. Include reserved capacity, operations, Kiwe software, AI, billing and support in the final cost. Register domains at the confirmed term and renewal price as a separate charge. These illustrations use the ₹3,499 monthly equivalent for hosting alone and round up to a whole rupee.

| Active billed sites | Hosting cost per site | Monthly hosting revenue per site at 10% margin |
| ---: | ---: | ---: |
| 10 | ₹349.90 | ₹389 |
| 25 | ₹139.96 | ₹156 |
| 50 | ₹69.98 | ₹78 |

The published Client rate is ₹499 per site per month with managed hosting included and an existing WordPress option. The published Agency rate is ₹999 per month for up to three site projects, with managed hosting priced separately for each site. The rates exclude applicable taxes and domain registrations. The 10% margin limits total monthly cost to ₹449.10 per Client site and ₹899.10 per Agency subscription before tax. For an Agency hosting add-on, check the actual plan contribution, provisioning cost and support before setting its price.

Use the trusted purchase contract to issue an internal Agency pilot entitlement to the verified purchaser subject. The client's separate identity and project membership give them an independent dashboard. WordPress owns the site's accounts and permissions. WordPress single sign-on must bind the verified account to an approved native user and role.


## Publication and qualification

The [plans page](https://kiwelaunch.com/plans/), Launch root and [Start login entry](https://start.kiwelaunch.com/login/) returned HTTP 200 with the exact published hashes. The login entry shows pilot status while the project service is pending. The original public Start guide returned the same file hash. Kiwe Launch's Git deployment is configured for the Seam repository. The new files were installed in a scoped deployment with the previous Launch files retained for recovery.

The deployed plan and login files passed Chrome layout checks at 320, 390 and 1280 CSS pixels. The check loaded the matching source files locally after the live headless browser received Hostinger's browser-check page. Production Start project and MCP metadata routes still returned HTTP 404.

All 28 Start service tests passed locally and on Plum Giraffe under Node 22.23.5. The test suite covered stable browser and MCP identity, verified client invitations, client Design Context isolation, the Agency allowance and managed test-site connection. It used synthetic identities, entitlements and simulated provider and WordPress responses. The hosted test used one worker and an isolated Unix socket. The lab inputs were retired after qualification.

## Existing-site pilot

1. **Shared pilot.** The live Start dashboard runs on Hostinger Node 22 with Key RC7. The invited owner signed in to the verified Kiwe Launch agency, and Start saved both stores' exact WordPress origins after native MCP verification. Start's original public guide, protected login, Key handoff and guest denial passed live readback. One-click browser generation needs its own authorized provider.
2. **Dharwad Pedha.** Start verified WordPress, linked the existing agency Key connection, and read 14 published pages and four Bricks templates through Kiwe MCP. The founder deferred the design review. The remaining design test uses one approved preview, a private Bricks draft and an owner-reviewed publication.
3. **National Chikki.** Start verified WordPress, linked the existing agency Key connection, and read 10 published pages and four Bricks templates through Kiwe MCP. A fresh WordPress review completed the read after the first callback failed. Complete its single-draft and publication test after the Dharwad review.
4. **Evergreen Basin.** Use the separate client invitation to collect the client's own Design Context. Start design when the client has supplied the required facts.

### Hosted sign-in correction

The original static guide's `.htaccess` applied `form-action 'none'` to the live Start sign-in page. A direct server request followed the expected Key redirect, while the browser blocked the form. The Start document root now leaves route-specific headers to the application. The prior hosting profile is retained privately for rollback.

The live **Continue with Key** button opened Key's WhatsApp sign-in. The original guide returned its published checksum, the guide and registry kept their restrictive policies, and guests still received HTTP 401 on `/projects`. The invited owner reached the live dashboard and both projects passed native verification. On National Chikki, the WordPress sign-in landed on its admin dashboard and reopening the review completed the connection. Run the [hosted login check](../services/start-projects/README.md#browser-login) on subsequent pilot deployments.

Key RC7 corrected a second approval error. The old referrer policy caused Chrome to submit a null origin. The Key approval forms now preserve their own origin and require the same account, session and CSRF checks. In the paired Chrome rehearsal, the real form and callback routes opened a dashboard with two test projects. The Key deployment preserved the owner and both active store connections. [Key RC7 source](https://github.com/Museintel/key.kiwe/commit/335d2fe7c46de85c6c7605a5e2fd35e1f17060a3)

The live inventory fix keeps the Start form submit within Start and presents the native WordPress review as a link. The same-origin form policy and the existing MCP read scope still apply. Both store inventories are available in Start and both temporary read grants were revoked after use. Hostinger build `01a20e7b-a0ea-7074-8db1-b8e8f632487c` ran the qualified source. The previous pilot source is available at `bf6f7679bf5660eeb7002d413e9ac0cb3e3f421b`. [Inventory fix source](https://github.com/Museintel/kiwe-ecosystem/commit/99c5b7d61a9a066a5e83075093195485e65087e4)

The store previews use existing WordPress hosting. Managed onboarding is a separate release. The connected Hostinger account has no active Agency Hosting order. Confirm the eligible order and actual renewal cost, qualify the Agency setup API, verify temporary domain and installation receipts, install the signed Kiwe and MCP packages, then qualify customer domain ownership, TLS and native role assignment. Hostinger's current setup adapter in Start uses the Shared and Cloud API. A portable enrollment file must be signed, short-lived and bound to its site, with the same ownership proof on import. Payment and domain registration checkout are deferred from the pilot. [Hostinger Agency API](https://github.com/hostinger/api-mcp-server/blob/main/types.d.ts)

### Design Context in WordPress

Start build `01a20ecc-7e54-7240-add5-17c20539a75b` serves the qualified archive for `a665fc3ac0673f2ed9715f571a9095b7ebeae6b4`, SHA-256 `1cea1f55ee0ceeb237216066873d3d8286553fc0d0dbcdd96199e1cf35b6f451`. All 27 Start tests passed on Node 24.22.3 locally and Node 22.23.5 on Hostinger. The fixtures verify owner and client links, membership revocation, retired editing routes and the read-only design request. The live Dharwad Pedha dashboard displayed the verified WordPress link and its former Start bookmark loaded the native form. Native WordPress checks confirmed both stores' form URLs. Live guest access to the old route returned HTTP 401. The hosted login, public guide and registry checks passed.

For immediate recovery, the prior archive with SHA-256 `aac61a23424051d71875b535412c7dc2c92e0127faf03c3b97b44be0e5bfde35` was verified in private Hostinger storage before deployment. [Live source](https://github.com/Museintel/kiwe-ecosystem/commit/a665fc3ac0673f2ed9715f571a9095b7ebeae6b4)
