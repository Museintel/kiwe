# Kiwe Client and the Partner Program

25 November 2026. Accepted for the Version 1.x launch.

## Plan and ownership

Kiwe Launch sells one managed website per Client purchase. The buyer pays ₹8,400 for the first 12 months and ₹700 monthly from month 13. Through a verified referral, the buyer pays ₹7,200 for the first 12 months and ₹600 monthly from month 13. The current checkout contract treats these prices as tax-inclusive.

The plan includes managed hosting, provisioned WordPress with licensed Bricks and signed Kiwe, and one eligible standard `.in` domain with renewals while the plan stays active. Check availability and eligibility before accepting a registration order. A client may use a domain they own. For an owned domain, provision a temporary HTTPS address first and guide the client through verification and DNS connection. Preserve existing mail records.

The managed WordPress package includes the latest qualified stable Bricks version, Razorpay for WooCommerce and Shiprocket. Stores activate and configure their own merchant and shipping integrations through native WooCommerce. Key’s configured primary Kiwe account provides the default WhatsApp sender using a separate credential for each verified managed site. Clients can select and verify their own sender from Start or MCP. The site uses the selected sender for WhatsApp login, campaigns and automations. [Managed package and Key setup](../../services/start-projects/README.md#managed-wordpress-package)

A partner enters a prospective client's email, consenting WhatsApp number and preferred domain. Kiwe Launch issues a private link and applies the discount only to a verified referral at checkout. The buyer sets their own account credentials and confirms their contact and billing details. The partner earns ₹100 per paid month of service delivered, with refunds and reversals reflected before withdrawal. The partner dashboard shows that partner's enrolled clients, earnings and withdrawal requests. Each buyer owns their Start project and retains sole authority over their site and its content.

Kiwe Launch owns accounts, checkout, subscription and partner ledger. Start uses that buyer identity and records the confirmed order before provisioning. Hostinger owns hosting and registrar operations. Key owns WhatsApp verification and sender permissions. WordPress owns the existing Design Context editor, business data and native approval. Start reads saved Design Context and opens its editor at the verified site. It guides the client through approving a homepage direction and reviewing each page or template individually. The same workflow is available through scoped Kiwe MCP calls. Seam compiles the exact approved draft into Bricks for native review and publication.

The earlier [purchased projects decision](2026-11-23-start-purchased-projects.md) remains the record of the pilot. Existing project access, native site permissions and the [Version 1 recovery release](https://github.com/Museintel/kiwe-ecosystem/releases/tag/kiwe-version-1) are retained.

## Delivery sequence

| Batch | Delivery |
| --- | --- |
| 1 | One Client plan and validated confirmed orders in Start and MCP |
| 2 | Kiwe Launch pricing, buyer signup, Become a partner, private referrals and verified domain selection |
| 3 | First-year payment, month-13 recurring consent, renewals, refunds and same-account Start sign-in |
| 4 | Partner earnings, balances, withdrawal requests and payout records |
| 5 | Isolated Hostinger provisioning, domain registration or connection, licensed Bricks and signed Kiwe |
| 6 | Client setup in Start, Key setup where applicable and progress from the existing WordPress Design Context |
| 7 | One page or template at a time through web and MCP, with preview, revision, Seam conversion and native Bricks publication |
| 8 | Full lab rehearsal, existing-store pilots, client onboarding and coordinated launch with recovery |

Batch 1 stores the payment and referral references, immutable first-term price and chosen domain with the project in a single transaction. The trusted Launch service supplies payment confirmation and verified attribution. Replayed deliveries return the original project. A payment or referral may only create one new project. The [integration guide](../../services/start-projects/README.md#checkout-handoff) specifies the current receipt.

The Batch 2 [public site and enquiry flow](../../services/launch-office/README.md) presents the single offer and saves demo requests and Partner Program interest in the private Launch inbox. It uses actual public site examples and a labelled sample dashboard. Checkout enrolment adds verified buyer accounts, private referrals and the registrar quote before payment is offered.

The [checkout candidate](../../services/launch-checkout/README.md) uses email verification and a durable Launch account ID. Verified buyers can join the Partner Program. The test runtime uses a captured mail transport and registrar fixtures.

Batch 3 uses a Razorpay Standard Payment Link for the first year. Launch records a successful capture only when it matches the saved amount, currency, link and order. It sends an idempotent entitlement to Start and brokers a short-lived browser ticket after verifying the signed-in buyer. The buyer can stop automatic billing by cancelling the separate Razorpay subscription. Its first monthly charge is scheduled at the end of the prepaid term. Failed renewals and processed refunds are recorded for the subsequent partner ledger and service rules. Browser and PHP fixtures verify the billing and handoff flow. The direct Razorpay Test Mode payment and signed monthly renewals also passed. The referral capture and production configuration are tracked below.

Batch 4 derives partner earnings from the immutable referral on the paid order, Start’s verified managed-site delivery, and the billing ledger. The first month eligible for commission is the paid billing month in which Start verifies setup. Earnings accrue when it ends. Refunds and lost chargebacks reduce the commission in proportion to the payment adjustment. An open dispute holds the affected share. Launch reserves requested withdrawals and rechecks the balance on approval. An operator records the exact confirmed external transfer and its unique reference. Withdrawal or partner status gives no website authority. [Partner operation](../../services/launch-checkout/README.md#partner-earnings-and-withdrawals)

Batch 5 sends a new included domain to WordPress provisioning after Launch confirms exact registration in Hostinger. The existing Start purchase connection carries the confirmation. Contact verification and paid registrar operations stay with Launch and Hostinger. Clients who bring a domain can verify it from Start or MCP and continue on their temporary site. On Shared and Cloud plans, moving the existing WordPress installation needs a reviewed provider operation and fresh native authorization at the new origin. Registrar renewal and that move are still open qualification work. [Domain setup](../../services/start-projects/README.md#domain-setup)

Launch records a reviewed WordPress restoration on the original client purchase. The client uses Start or MCP to approve WordPress and verify Key at the final hostname while the temporary site serves. Launch activates after the operator pauses writes and verifies the final copy. Start checks public HTTPS, records the pending activation and commits the new site after Key confirms the existing tenant at the final origin. Retrying the same activation reconciles a lost response. The original payment and delivery date stay with the project. [Reviewed domain move](../../services/start-projects/README.md#reviewed-domain-move)

Use the current environment for isolated checkout and provider rehearsals before enabling real registration, charges or payouts. Exercise the published-page workflow with Dharwad Pedha and National Chikki before onboarding Evergreen Basin with client-supplied Design Context. Review each exact draft and native publication with the appropriate owner.

## Pending work

Reviewed on 25 November 2026. The Kiwe Launch homepage and Partner Program interest page are live. Start serves its guide and the closed existing-site pilot. Public checkout returned HTTP 404. The shared launch candidate adds the buyer, billing, partner and managed-site workflow. Keep using the single Client offer, with private referrals and a client-owned project per purchase. [Hosted pilot record](../KIWE-LAUNCH-HOSTING-2026-11-24.md#existing-site-pilot) · [Checkout candidate](../../services/launch-checkout/README.md)

### 1. Complete the Razorpay referral test

- [ ] **Do this next.** Create a fresh Test Mode checkout through a verified referral. The earlier ₹7,200 link expired unpaid. Capture the correct first-year amount and verify the ₹600 monthly approval starts in month 13.
- [ ] Verify that the buyer can reopen the same Start project in the browser and through scoped MCP. Confirm the referral stays attached to the order, the buyer retains sole site authority, and retries create one project. The direct ₹8,400 capture, ₹700 approval and replay of two signed monthly charges have passed in isolation. Kiwe Launch records each renewal against its paid invoice's dates. [Direct payment and renewal evidence](https://github.com/Museintel/kiwe-ecosystem/commit/b9d62fbd97af224143b33afa7e50b66c1bf1c677)

### 2. Connect and qualify the production checkout

- [ ] Set up the authorized Launch verification-email sender and private registrar access. Check signup, password setup, reset and returning sign-in with a controlled inbox. Verify a standard included domain, a premium or taken domain, and the buyer-owned-domain choice.
- [ ] Connect the dedicated Launch-to-Start purchase credential on both services. Verify the real hosted buyer handoff and re-entry through the Start login, including project membership and isolation from the existing pilot.
- [ ] Verify Kiwe Launch's live Razorpay merchant and website setup. Configure the private credentials, exact ₹700 and ₹600 monthly plans, and signed webhook delivery. Activate the billing and partner delivery reconciliation jobs. Run the controlled provider check for failed payments, retries, cancellation and refunds before accepting public orders. Retain the existing dispute and duplicate-event regression checks. [Production configuration and recovery](../../services/launch-checkout/README.md#provider-configuration)
- [ ] Open the verified Partner Program enrolment in place of interest-only intake. Rehearse the path from a private referral through a paid order and a verified managed-site setup to the partner dashboard. Confirm the ₹100 commission rule, withdrawal review and recording of a cleared external transfer exactly once. The operator-led transfer and payout proof are pending. [Partner workflow](../../services/launch-checkout/README.md#partner-earnings-and-withdrawals)

### 3. Finish managed hosting and the domain lifecycle

- [ ] Confirm the eligible Hostinger order, reserved capacity and actual per-client cost. The current setup adapter uses Shared or Cloud hosting. Confirm Hostinger India's partnership or reseller terms and qualify the production hosting tier before using it for client sites. Recheck first-year and renewal costs for the included standard .in domain, hosting, taxes, payment fees, Bricks, support, and referral commission against the agreed prices. Verify sustainability at expected occupancy before opening sales. [Cost and hosting research](../KIWE-LAUNCH-HOSTING-2026-11-24.md)
- [ ] Select an eligible .in for the real provider check and confirm the exact cost before the approved registration. Verify the matching registrant and required registry checks, the registration receipt, Start's exact-domain installation and Key's final-origin admission. Complete the annual registrar renewal workflow and prove retry and billing reconciliation. Define how active subscriptions, cancellations and unpaid renewals affect the included domain and continued hosting. [Registration workflow](../../services/launch-checkout/README.md#included-domain-registration)
- [ ] Rehearse one named buyer-owned-domain move from its temporary HTTPS address to the verified domain. Use the reviewed recovery copy, fresh WordPress and Key approval, preserved mail records, public DNS and TLS checks, and final cutover. Verify that the original project, tenant and delivery date survive. Lab recovery and the joined Start and Key activation already passed using fixtures. [Owned-domain workflow](../../services/start-projects/README.md#reviewed-domain-move)
- [ ] Wire the production managed-hosting and Key credentials and verify the install on a paid client project. Use the qualified signed Kiwe Core and MCP, licensed stable Bricks, Razorpay for WooCommerce, and Shiprocket. Verify retries reuse the installation and that the site has its own credential for the default WhatsApp sender. Test a verified client sender change and a consented delivery. Keep the store's payment and shipping setup under that site's own provider configuration. [Managed WordPress package](../../services/start-projects/README.md#managed-wordpress-package)
- [ ] Finish and test the buyer's WordPress administrator handover. The current provisioner starts with an operator administrator. A verified buyer needs a self-service way to take ownership, return to Start under their purchasing account, and enter their own WordPress admin and existing Design Context form. Check that progress and new public business facts are reflected in Start and MCP. [Provisioning and ownership](../../services/start-projects/README.md#included-kiwe-hosting)

### 4. Complete the authoring pilots

The existing-site pilots can run alongside provider setup because they use their current WordPress sites.

- [ ] Connect and qualify the authorized browser design generator for one-click generation and revisions. The live pilot already supports preparing design requests through Kiwe MCP. Verify both routes use the same saved WordPress brief, one draft per page or template, and the same native approval and publication path. [Start design workflow](../../services/start-projects/README.md#homepage-draft)
- [ ] **Dharwad Pedha first.** Use the existing client-filled WordPress Design Context. Present one exact preview for owner review. Convert the approved version with Seam, verify the private Bricks result against the preview on mobile and desktop, then publish and verify native recovery through the owner-approved workflow.
- [ ] **National Chikki second.** Repeat the one-document pilot. Verify the applicable live catalogue queries, category rail, dynamic tags, images and Dock actions in the rendered Bricks page. Test native store and checkout behavior when the approved document affects it. Resolve preview-to-Bricks differences before approval. [Both existing-site pilots](../KIWE-LAUNCH-HOSTING-2026-11-24.md#existing-site-pilot)
- [ ] **Evergreen Basin next.** Give the client access to the existing WordPress Design Context and wait for their business details, catalogue and images. Use the supplied raw homepage as the visual reference and approve the homepage first. Review the sample products, categories, media and five existing test posts against client-supplied facts before replacing or approving them. Verify the approved homepage uses real product queries and its dynamic category rail.
- [ ] Generate and review the remaining Evergreen pages and templates individually. Use Start's inventory for about, contact, header, footer, product, archive, blog and post views, and the applicable native WooCommerce cart, checkout, account and thank-you views. Exercise a Design Context edit through an existing dynamic binding. The site's Key connection has passed signed verification. Recipient OTP, campaign and automation delivery still need a consented test. [Pages and templates checklist](../../services/start-projects/README.md#pages-and-templates)
- [ ] Return to the deferred Kiwe Launch homepage design review. Replace the illustrative proof rail with approved testimonials as they become available. The live provider logo rail, including Shiprocket, already passed its first-viewport checks. [Launch homepage and proof assets](../../services/launch-office/README.md)

### 5. Release and follow up

- [ ] Rehearse the joined purchase-to-published-site path on the Plum Giraffe lab with one supported release set for Launch, Start, Key, Seam and the signed WordPress packages. Verify web and MCP access, duplicate delivery, provisioning recovery, and rollback that preserves paid orders, domains, project ownership and the partner ledger.
- [ ] After the provider checks and pilot reviews, release the Client checkout and Partner Program together. Verify the live health and customer path, then monitor billing reconciliation, hosting delivery, domain deadlines and withdrawal records. Keep the Version 1 recovery release available during the Version 1.x rollout. [Version 1 recovery release](https://github.com/Museintel/kiwe-ecosystem/releases/tag/kiwe-version-1)
- [ ] Check that Ascendants' reporting backlog drains and database allocation stays within its Hostinger limit after the completed compact-copy move and RC499 retention rollout. Preserve the database recovery copy. Future shared Core fixes use the existing signed fleet workflow for the four Kiwe sites. [Fleet release and recovery](../CORE-FLEET-RELEASE.md)

Evanya Organics is a later onboarding candidate and requires its own named approval.
