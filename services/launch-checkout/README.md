# Kiwe Launch checkout

The checkout candidate handles private enrolment and billing for the public Kiwe Client offer. The web entry is `public/kiwelaunch.com/checkout/`. Launch owns the verified buyer, quote and payment record. Start receives the same durable `launch:` account ID after payment confirmation.

## Buyer and partner flow

Buyers verify a link sent to their email, then set a password. The link expires after 30 minutes and is consumed when the password is saved. Buyers can sign in or reset that password with a fresh link. Session identifiers rotate after sign-in and a password reset invalidates earlier sessions.

A verified buyer can opt into the Partner Program. They enter the client's email, WhatsApp and preferred domain with the client's permission. The private referral link expires after 30 days if unused. A partner can revoke an unused link or see when the corresponding email was verified. The link contains an opaque token in the fragment. Checkout binds it to the matching verified email before pre-filling contact details or showing the partner price. Partners share links themselves.

Hostinger checks new standard `.in` names. The form preserves the entered details when a domain is taken, premium or restricted. A buyer can explicitly confirm ownership of a domain they already have. That selection records the buyer's claim for later DNS verification. Where the registry requires identity verification, the quote carries that requirement.

Checkout saves contact and billing details privately with the domain and the server-calculated price. Both direct and referred prices come from the canonical Client plan. The quote expires after 15 minutes. Its stable ID, revision and pricing can be read through the private Kiwe MCP tool. The payment step rechecks domain eligibility, locks the quote and sends a confirmed entitlement to Start once.

## First year and monthly renewals

Launch rechecks the saved quote when the buyer confirms the first-year amount. It reserves a unique reference before creating a 30-minute Razorpay Standard Payment Link. A retry looks up that reference before requesting a link. A confirmed expired or cancelled unpaid link lets the buyer review a fresh quote. The browser return only opens the checkout status page. A captured payment must match the link, order, currency and complete amount.

When Launch confirms the payment, the first term runs for 12 calendar months in India time. Checks first look for any processed full refund. Launch submits the same entitlement and payment ID to Start on each delivery attempt. Start returns a one-time browser handoff for the same verified Launch account. Subsequent logins reopen that project from Launch. The closed Key pilot can serve the new path when its separate Launch purchase credential is configured.

Renewal requires a separate buyer approval for the exact amount, start date and number of payments. Razorpay begins monthly billing at the end of the prepaid term. The default mandate is up to 120 monthly payments. A new approval after cancellation or expiry begins at the end of the latest paid term or on a new disclosed date if service has lapsed. Each payment and processed refund is recorded by provider ID. Failed or halted renewals surface in checkout. Existing paid periods remain recorded when the buyer cancels automatic renewal.

A signed renewal charge identifies its paid Razorpay invoice. Launch reads that invoice before opening its database write transaction and checks the subscription, order, payment, currency and full amount. The invoice sets the billing period, including when a late charge arrives after the subscription advances to the next month. A failed invoice read leaves the webhook eligible for retry. Repeated notifications preserve the original ledger entry. [Razorpay subscription invoices](https://razorpay.com/docs/api/payments/subscriptions/fetch-invoices)

A Razorpay subscription request with an uncertain outcome keeps its reservation. The private recovery operation verifies the matching subscription ID with Razorpay before accepting it. Manage refunds through Razorpay. Launch records processed refunds and blocks an undelivered first-year entitlement after a full refund. Project data stays under its existing owner.

## Partner earnings and withdrawals

The signed-in Partner area lists the partner's paid referrals, their setup status and earned commission. The plan sets ₹100 per completed paid month. Launch captures the commission rate on the payment record. Earlier records for the same plan use its canonical rate. The first eligible month is the paid billing month that includes verified managed-site setup in Start. Launch recognizes commission after that month ends. The prepaid year uses calendar anniversaries in India time and pays at most 12 monthly commissions. Later months use the billing period of each captured renewal.

Commission uses the net collected amount in paise. A partial refund or lost chargeback reduces the commission proportionally. An open dispute holds the affected share from withdrawal. A reversal after payout can offset future earnings. The dashboard shows net earnings, available balance, pending and paid withdrawals, and any active holds or adjustments.

Selecting **Withdraw** requests the displayed available amount. The request reserves it immediately and retries reuse the request ID. The partner can cancel before approval. The operator reviews the exact amount against the current balance, then records a cleared external transfer with its unique reference. For a manual transfer, use the withdrawal ID for reconciliation and confirm the destination and transfer through the approved payment channel. Confirm a transfer has not cleared before declining an approved request. The candidate stores the confirmed payout record in Launch and uses a separate payment channel for the transfer itself.

Launch records the setup date from Start's private `purchase-delivery` lookup for the exact entitlement and buyer. Start uses the verified managed WordPress site and its existing connection proof. Run `php operator.php reconcile-partner-deliveries` regularly after enabling checkout and provisioning. Each pass checks up to four due referrals. The service claims each pending check before calling Start. The Partner area reads local records on page load.

## Provider configuration

Deploy service files outside the public document root, default `~/.kiwe-launch-checkout/`. The route enables checkout when its private `settings.php` sets `enabled` to true and supplies an authorised Hostinger Mail API `mailbox` and `mailToken`, plus a Hostinger API `registrarToken`. Live origin defaults to `https://kiwelaunch.com`. Use PHP 8.2 or later with PDO SQLite, cURL and Argon2id. [Mail send API](https://github.com/hostinger/mail-api-php-sdk/blob/main/docs/Api/SendApi.md) · [Domain availability API](https://github.com/hostinger/api-php-sdk/blob/main/docs/Api/DomainsAvailabilityApi.md)

Prepare private mode 0700 directories `data/` and `data/sessions/`, and generate a 32-byte random `data/rate-key` at mode 0600. Derive `client-plan.json` from the Start source in the release checkout.

```sh
node --input-type=module -e "import { clientPlan } from './services/start-projects/src/client-plan.mjs'; process.stdout.write(JSON.stringify(clientPlan))"
```

Place that output in the private runtime. Keep provider credentials and the database there. Public enrolment should open once the real sender and registrar credentials have passed an isolated rehearsal.

To enable production billing, configure a private `billing` array with `mode: live`, `merchant`, `keyId`, `keySecret`, `webhookSecret`, `startOrigin`, `startToken`, and `plans.direct` and `plans.referred`. The two Razorpay plans must match the current monthly amounts in INR. The optional `cycles` sets the disclosed mandate length.

Razorpay `mode: test` is available in the isolated CLI runtime with separate test keys, a signing secret, a separate store and an isolated loopback Start service. A provider webhook rehearsal needs its own HTTPS receiver. The production checkout accepts live Razorpay keys and uses its dedicated Start purchase credential to deliver orders.

Configure Razorpay to send Payment Link paid, expired and cancelled events, Subscription status and charged events, processed refunds and dispute lifecycle events to `/launch-api/razorpay.php`. The endpoint validates the raw body and merchant before writing a minimal local record. An enabled deployment runs `php operator.php reconcile-billing` to check pending provider state and retry confirmed Start delivery outside the webhook response. Checkout can also check the payment with the buyer present. [Razorpay Payment Links](https://razorpay.com/docs/api/payments/payment-links/) · [Razorpay Subscriptions](https://razorpay.com/docs/api/payments/subscriptions/create-subscription/) · [Razorpay dispute events](https://razorpay.com/docs/webhooks/disputes/) · [Razorpay webhook verification](https://razorpay.com/docs/webhooks/validate-test/)

## Programmatic operations

Set `KIWE_LAUNCH_CHECKOUT_ROOT` on the private Launch office MCP process. Its `kiwe_launch_check_domain`, `kiwe_launch_enrol_partner`, `kiwe_launch_checkout_quote`, `kiwe_launch_billing_status`, `kiwe_launch_reconcile_billing` and `kiwe_launch_recover_renewal` tools use the same checkout service. The reads exclude contact fields, payment links and handoff credentials. Operators can use `php operator.php` from the service directory through the existing approved SSH access.

### Native WooCommerce purchases

Generate `launch-catalogue.json` from `launchCatalogue` in `services/start-projects/src/client-plan.mjs` using the same private configuration export as the original plan. Keep the versioned offers used by existing purchases available to the reconciler. With the catalogue present, the private `kiwe_launch_import_woo_purchase` tool and `php operator.php import-woo-purchase JSON` accept the native order ID, verified Launch account ID, exact plan and SKU, Razorpay order and payment IDs, WooCommerce paid timestamp, and selected domain. The operator must obtain these fields from the trusted Kiwe Launch WooCommerce order and verified buyer binding. The tool is for direct first-year payments and does not create charges. Razorpay must independently confirm the exact order receipt, captured payment and amount. [Razorpay order readback](https://razorpay.com/docs/api/orders/fetch-with-id/)

The billing reconciliation job delivers by exact purchase ID, so one buyer can have separate native orders and Start projects. Read a purchase with `kiwe_launch_billing_status` and `purchaseId`. Omitting it selects the original Payment Link flow. Before migrating an existing billing database, keep a consistent SQLite backup including pending writes. The new schema retains the legacy single-open-payment constraint for original orders and permits multiple native purchases. If a migrated database already contains multiple purchases, preserve it for reconciliation before restoring any older code and index.

The signed-in billing view lists the buyer's imported website orders by domain. Open one to see its Start link, paid-through date, refunds and renewal approval. The purchase ID travels with each billing form. A buyer can cancel one renewal and retain that site's paid term and any other site's approval.

Map each supported direct website plan version to its Razorpay plan ID in the private `billing.plans.websitePlans` settings. For example, use the catalogue key `kiwe-portfolio-v1` and the separately created monthly provider plan for that price. Configure IDs for the same provider mode as the merchant. The service checks the interval, amount and currency before requesting approval. Purchases without a mapping can still be read and opened in Start. Approval requires fresh consent and begins after the recorded paid term. A full first-year refund or unsettled dispute blocks approval. Refunds and paid renewal periods follow the payment and invoice IDs for that exact website.

If Razorpay created an approval but Launch lost the response, use `kiwe_launch_recover_renewal` with the account, purchase and subscription IDs. The CLI equivalent is `php operator.php recover-renewal ACCOUNT SUBSCRIPTION PURCHASE`. Verify the existing subscription before issuing another approval link.

The Launch WordPress adapter is `services/launch-checkout/wordpress.php`. It may be installed as a single MU plugin on the Kiwe Launch store. Define `KIWE_LAUNCH_NATIVE_CHECKOUT_ROOT` in that WordPress configuration to point at the private service. The feature also requires the private `nativeWoo` switch, the versioned catalogue, verified-account email delivery, and a billing configuration for the same Razorpay merchant and mode as the native gateway. Serve `/launch-account/` and `/assets/launch/checkout.css` and `/assets/launch/checkout.js` on the same HTTPS host as WooCommerce. Route `/checkout/` to the WordPress checkout page on that host. Keep the account route, private replies and WooCommerce personal and checkout routes out of public caches. The legacy static deployment continues to use its own `/checkout/` entry.

Use the classic WooCommerce checkout form inside the Bricks checkout template. It supplies the checkout nonce, buyer fields and normal payment process. The adapter adds the domain fields at the native billing hook for Launch website plans. A Launch account verifies the buyer's email before payment and has its own cookie. WooCommerce can use guest checkout. The order is bound before the gateway opens, and only the standard Razorpay gateway processes these website plans. A standard included .in must pass the domain availability check before payment. This same check is refreshed before registration. [WooCommerce classic checkout hooks](https://woocommerce.com/document/introduction-to-hooks-actions-and-filters/) · [Official Razorpay WooCommerce gateway](https://razorpay.com/app-store/woocommerce/)

Use Action Scheduler to process paid WooCommerce orders and the existing private billing MCP to inspect the resulting purchase. The worker checks the saved WooCommerce order and the captured Razorpay payment before opening Start. It retries delayed provider responses. If a job needs review, run `wp kiwe-launch reconcile-order WOO_ORDER_ID` against the same store. A repeat uses the same receipt and Start project. Keep the private service and its rate key available with the database and of the same generation as the WooCommerce recovery snapshot so signed order intents can still be read.

This native intake handles direct first-year website checkout and paid Freelancer Pass orders. Qualify the hosted Bricks form, signed-in browser return from Razorpay, outgoing account mail, hosted jobs and new buyer prices before enabling public purchases. Automatic private discounts and referral prices are still pending.

### Annual Freelancer Pass

The virtual Pass uses its own catalogue SKU with no inventory tracking. The buyer's verified Launch account reserves one WooCommerce order before Razorpay opens. The buyer retries an unpaid order or renews the existing pass in their Launch account. The verified capture activates annual account coverage in the existing billing ledger. The Pass has no domain, website, hosting setup or Start handoff. Keep the existing private SQLite ledger and WordPress orders together for recovery.

Map the separate yearly Razorpay plan to the private `billing.plans.freelancerPass` setting, using a plan with a yearly period, interval one and the catalogue amount in paise. First-year payment does not authorize renewals. The buyer can approve up to nine annual charges starting at the end of the prepaid term, and can cancel future charges. A signed Razorpay event backed by the matching paid annual invoice extends coverage. Eligibility follows the current paid period, with refunds and payment disputes reflected separately from website purchases. [Razorpay yearly plan terms](https://razorpay.com/docs/api/payments/subscriptions/create-plan/) · [Razorpay subscription creation](https://razorpay.com/docs/api/payments/subscriptions/create-subscription/)

The private `kiwe_launch_freelancer_pass` tool and `php operator.php freelancer-pass ACCOUNT_ID` read the same coverage used in the signed-in account. They return only the plan, purchase ID, active flag and paid-through date. A reserved virtual order stays available for retry through WooCommerce's stock-hold timeout. Review an administrator-cancelled order and its provider record before making payment available again. The private discount and Guest approval flows will consume verified coverage independently.

Partner tools in that private MCP process are `kiwe_launch_partner_summary`, `kiwe_launch_reconcile_partner_deliveries`, `kiwe_launch_withdrawals`, `kiwe_launch_review_withdrawal` and `kiwe_launch_record_payout`. They use the same ledger as the dashboard. The withdrawal read can page through pending, paid or all records. The tool results use Launch account and withdrawal IDs and omit client contact and bank details.

## Included domain registration

Paid registration is disabled by default. The private `registration` settings require `enabled`, an explicit Hostinger `paymentMethodId`, and limits `firstYearMaxMinor` and `renewalMaxMinor` in INR paise. The limits apply to catalogue prices. Review the account's full charges before enabling the command. On 25 November 2026 the account catalogue listed a standard one-year `.in` at ₹549 initially and ₹899 for annual renewal.

Use `php operator.php register-domain PURCHASE_ID EXACT_DOMAIN WHOIS_PROFILE_ID` once the buyer's Launch payment is captured and delivered to Start and the correct buyer WHOIS profile is ready. Launch checks the verified buyer email on that profile, current eligibility, existing Hostinger orders, price limits, and payment and dispute status. After claiming the exact purchase and domain, the command can submit one paid request. The registry may ask for phone, email or identity verification. Complete those steps with Hostinger and keep sensitive documents in the provider's official process. [Hostinger purchase API](https://github.com/hostinger/api-php-sdk/blob/main/docs/Api/DomainsPortfolioApi.md#purchasenewdomainv1) · [Hostinger .in requirements](https://www.hostinger.com/support/requirements-to-register-in-domains-at-hostinger/)

Use `php operator.php reconcile-domain PURCHASE_ID EXACT_DOMAIN` or the private `kiwe_launch_reconcile_domain` tool to check a saved registration. Preserve the attempt after an interrupted request or a pending payment. Reconcile it with hPanel before any recovery action. Launch confirms the exact active registration and owner contact before notifying Start. Reconciliation handles a lost Start response through the same receipt. Customer-facing tools expose the domain's progress only.

### Owned-domain moves

Use the private `kiwe_launch_review_domain_move` or `php operator.php review-domain-move PURCHASE DOMAIN SOURCE_URL REVIEW_SHA256` to record the verified restoration on the original paid purchase. The buyer verifies WordPress and Key at the final address through Start.

After pausing writes at the temporary address and verifying the final synchronized recovery copy, use `kiwe_launch_activate_domain_move` with the purchase, exact final domain, temporary HTTPS URL, reviewed recovery digest, final recovery digest and confirmation of the write pause. The equivalent command is `php operator.php activate-domain-move PURCHASE DOMAIN SOURCE_URL REVIEW_SHA256 FINAL_SHA256 --source-frozen`. Start checks the public final address and returns the active domain on the same project. Retry with the same details after an interrupted response and preserve the original recovery copy through the final checks.

See the [Start move and recovery workflow](../start-projects/README.md#reviewed-domain-move).

## Qualification and recovery

Run `node services/launch-checkout/test/checkout.mjs` using the same PHP and Playwright environment as the [Launch site checks](../launch-office/README.md#qualification-and-release). The browser suite uses an isolated SQLite database, captured fixture email, simulated Razorpay responses and an isolated Start server. It covers partner isolation, exact-month accrual, request replays, dispute holds, payout recording and a refund after payout. The Hostinger PHP proof in `test/hosted-proof.php` uses the same PHP store and synthetic Start and transfer receipts. It covers a full first year and a monthly renewal, a chargeback and duplicate transfer reference rejection. Live mail, registrar access, Razorpay Test Mode and the external payout process require their respective rehearsals before use.

Run `node services/launch-checkout/test/woo-purchases.mjs` with the same PHP settings to check the old index migration, ownership and duplicate rejection, and real Start service delivery from two simulated captured provider orders.

The PHP proof also checks the registration payment gate, matching registrar contact, catalogue cap, lost request recovery, verification hold and durable receipt. Keep `launch_domain_orders` and the Start domain setup records through code rollback. Reconcile any intervening registrar and billing state before re-enabling purchase. The domain purchase and renewal lifecycle still need real provider qualification.

The partner and dispute tables are additive in the existing private checkout database. Keep a SQLite-consistent backup before replacing checkout source. Preserve the database and confirmed external payout records during code rollback. Before re-enabling withdrawals after an outage or rollback, reconcile intervening provider refunds, disputes and external payouts with the retained records.
