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

Batch 3 uses a Razorpay Standard Payment Link for the first year. Launch records a successful capture only when it matches the saved amount, currency, link and order. It sends an idempotent entitlement to Start and brokers a short-lived browser ticket after verifying the signed-in buyer. The buyer can stop automatic billing by cancelling the separate Razorpay subscription. Its first monthly charge is scheduled at the end of the prepaid term. Failed renewals and processed refunds are recorded for the subsequent partner ledger and service rules. The candidate passes isolated tests with simulated Razorpay receipts. Public checkout activation needs the sender, registrar and Razorpay merchant configuration and a provider rehearsal.

Batch 4 derives partner earnings from the immutable referral on the paid order, Start’s verified managed-site delivery, and the billing ledger. The first month eligible for commission is the paid billing month in which Start verifies setup. Earnings accrue when it ends. Refunds and lost chargebacks reduce the commission in proportion to the payment adjustment. An open dispute holds the affected share. Launch reserves requested withdrawals and rechecks the balance on approval. An operator records the exact confirmed external transfer and its unique reference. Withdrawal or partner status gives no website authority. [Partner operation](../../services/launch-checkout/README.md#partner-earnings-and-withdrawals)

Use the current environment for isolated checkout and provider rehearsals before enabling real registration, charges or payouts. Exercise the published-page workflow with Dharwad Pedha and National Chikki before onboarding Evergreen Basin with client-supplied Design Context. Review each exact draft and native publication with the appropriate owner.
